<?PHP namespace wiki;

/**
 * Wiki w osobnej bazie db/wiki.sqlite (treść publiczna, oddzielona od danych zgłoszeń).
 * Backup obejmuje ją automatycznie (src/tools/db-backup.php: glob db/*.sqlite).
 * Schemat: src/sql/wiki_schema.sql, wersja w PRAGMA user_version.
 */

if (!defined('WIKI_DB_FILENAME'))
    define('WIKI_DB_FILENAME', ROOT . 'db/wiki.sqlite');

const DELETED_AUTHOR = 'Użytkownik usunięty';

$wikiDb = null;

function db(): \PDO
{
    global $wikiDb;
    if ($wikiDb)
        return $wikiDb;
    $wikiDb = new \PDO('sqlite:' . WIKI_DB_FILENAME);
    $wikiDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $wikiDb->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $wikiDb->exec('PRAGMA foreign_keys = ON');
    if ((int)$wikiDb->query('PRAGMA user_version')->fetchColumn() === 0) {
        $schema = __DIR__ . '/../../sql/wiki_schema.sql';
        if (!is_readable($schema))
            throw new \RuntimeException("Brak schematu wiki: $schema");
        $wikiDb->exec(file_get_contents($schema));
    }
    return $wikiDb;
}

/** Opublikowana strona (lub szkic) po ścieżce; usunięte = null. */
function getPage(string $path): ?array
{
    $stmt = db()->prepare("SELECT * FROM wiki_pages WHERE path = :path AND status != 'deleted'");
    $stmt->execute([':path' => $path]);
    return $stmt->fetch() ?: null;
}

/** Strona podpięta pod trasę www (np. 'faq' dla /faq.html). */
function getPageByAlias(string $alias): ?array
{
    $stmt = db()->prepare("SELECT * FROM wiki_pages WHERE route_alias = :alias AND status != 'deleted'");
    $stmt->execute([':alias' => $alias]);
    return $stmt->fetch() ?: null;
}

/**
 * Zapisuje nową wersję strony (tworzy ją, jeśli nie istnieje) i rewizję z autorem. Zwraca id rewizji.
 * section/routeAlias ustawiane tylko przy tworzeniu — zmiana ścieżki i aliasu to osobne operacje.
 */
function savePage(string $path, string $title, string $bodyMd, string $summary,
    ?string $authorEmail, ?string $authorName, string $section = '', ?string $routeAlias = null): int
{
    $db = db();
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) $db->beginTransaction();
    try {
        $now = date('c');
        $stmt = $db->prepare("SELECT id FROM wiki_pages WHERE path = :path");
        $stmt->execute([':path' => $path]);
        $pageId = $stmt->fetchColumn();
        if ($pageId === false) {
            $stmt = $db->prepare("INSERT INTO wiki_pages (path, title, section, body_md, route_alias, updated_at, updated_by)
                VALUES (:path, :title, :section, :body, :alias, :now, :by)");
            $stmt->execute([':path' => $path, ':title' => $title, ':section' => $section, ':body' => $bodyMd,
                ':alias' => $routeAlias, ':now' => $now, ':by' => $authorEmail]);
            $pageId = (int)$db->lastInsertId();
        }

        $stmt = $db->prepare("INSERT INTO wiki_revisions (page_id, title, body_md, summary, author_email, author_name, created_at)
            VALUES (:page, :title, :body, :summary, :email, :name, :now)");
        $stmt->execute([':page' => $pageId, ':title' => $title, ':body' => $bodyMd, ':summary' => $summary,
            ':email' => $authorEmail, ':name' => $authorName, ':now' => $now]);
        $revId = (int)$db->lastInsertId();

        $stmt = $db->prepare("UPDATE wiki_pages SET title = :title, body_md = :body, status = 'published',
            current_rev = :rev, updated_at = :now, updated_by = :by WHERE id = :id");
        $stmt->execute([':title' => $title, ':body' => $bodyMd, ':rev' => $revId, ':now' => $now,
            ':by' => $authorEmail, ':id' => $pageId]);
        indexPage($path, $title, $bodyMd);

        if ($ownTransaction) $db->commit();
        return $revId;
    } catch (\Throwable $e) {
        if ($ownTransaction) $db->rollBack();
        throw $e;
    }
}

/** Oznacza stronę jako usuniętą (rewizje zostają). */
function deletePage(string $path): void
{
    $stmt = db()->prepare("UPDATE wiki_pages SET status = 'deleted', updated_at = :now WHERE path = :path");
    $stmt->execute([':path' => $path, ':now' => date('c')]);
    db()->prepare("DELETE FROM wiki_fts WHERE path = :path")->execute([':path' => $path]);
}

/** Indeks wyszukiwarki: tekst bez składni Markdown/dyrektyw (żeby „howto” czy „media:” nie były trafieniami). */
function indexPage(string $path, string $title, string $bodyMd): void
{
    $text = preg_replace([
        '/^\s*:{3,}[a-z-]*(\{.*\})?\s*$/mu', // płoty dyrektyw (wartości atrybutów, np. tytuł karty, zostają niżej)
        '/!\[([^\]]*)\]\([^)]*\)/u',          // obrazki → opis
        '/\[([^\]]*)\]\([^)]*\)/u',           // linki → tekst
        '/\[\[[^\]]*\]\]/u',                  // bloki osadzane
        '/\{\{sex:\w+\}\}|\{#[^}]*\}/u',    // formy rodzajowe, kotwice
        '/[#*_`>|~]+/u',
    ], ['', '$1', '$1', '', '', ' '], $bodyMd);
    preg_match_all('/(?:title|summary|method)="([^"]*)"/u', $bodyMd, $attrs);
    $text .= ' ' . implode(' ', $attrs[1]);
    $db = db();
    $db->prepare("DELETE FROM wiki_fts WHERE path = :path")->execute([':path' => $path]);
    $db->prepare("INSERT INTO wiki_fts (path, title, body) VALUES (:path, :title, :body)")
        ->execute([':path' => $path, ':title' => $title, ':body' => $text]);
}

/**
 * Wyszukiwanie w treści stron (FTS5, ranking bm25 z tytułem ważniejszym niż treść). Każde słowo zapytania musi wystąpić
 * (prefiksowo: „zażal” znajduje „zażalenie”). Fragment z trafieniem oznaczony \u{E020}…\u{E021} — escapuje i podmienia
 * na <mark> warstwa widoku.
 * @return list<array{path:string,title:string,route_alias:?string,snippet:string}>
 */
function searchPages(string $query, int $limit = 30): array
{
    preg_match_all('/[\p{L}\p{N}]+/u', $query, $words);
    $terms = array_slice(array_unique(array_map('mb_strtolower', $words[0])), 0, 8);
    if (!$terms) return [];
    $match = implode(' ', array_map(fn($word) => '"' . $word . '"*', $terms));
    $stmt = db()->prepare("SELECT f.path, p.title, p.route_alias,
            snippet(wiki_fts, 2, char(57376), char(57377), '…', 16) AS snippet
        FROM wiki_fts f JOIN wiki_pages p ON p.path = f.path AND p.status = 'published'
        WHERE wiki_fts MATCH :match ORDER BY bm25(wiki_fts, 0.0, 5.0, 1.0) LIMIT :limit");
    $stmt->bindValue(':match', $match);
    $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Przebudowa indeksu ze wszystkich opublikowanych stron (po imporcie albo po zmianie indexPage). */
function reindexAll(): int
{
    $db = db();
    $db->exec("DELETE FROM wiki_fts");
    $count = 0;
    foreach ($db->query("SELECT path, title, body_md FROM wiki_pages WHERE status = 'published'")->fetchAll() as $page) {
        indexPage($page['path'], $page['title'], $page['body_md']);
        $count++;
    }
    return $count;
}

function pathById(int $pageId): ?string
{
    $stmt = db()->prepare("SELECT path FROM wiki_pages WHERE id = :id");
    $stmt->execute([':id' => $pageId]);
    return $stmt->fetchColumn() ?: null;
}

/** Historia strony, od najnowszej. */
function revisions(int $pageId, int $limit = 100): array
{
    $stmt = db()->prepare("SELECT id, page_id, title, summary, author_name, created_at, length(body_md) AS bytes
        FROM wiki_revisions WHERE page_id = :page ORDER BY id DESC LIMIT :limit");
    $stmt->bindValue(':page', $pageId, \PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getRevision(int $revId): ?array
{
    $stmt = db()->prepare("SELECT * FROM wiki_revisions WHERE id = :id");
    $stmt->execute([':id' => $revId]);
    return $stmt->fetch() ?: null;
}

/** Rewizja tej samej strony bezpośrednio przed $revId (do diffu). */
function previousRevision(int $pageId, int $revId): ?array
{
    $stmt = db()->prepare("SELECT * FROM wiki_revisions WHERE page_id = :page AND id < :id ORDER BY id DESC LIMIT 1");
    $stmt->execute([':page' => $pageId, ':id' => $revId]);
    return $stmt->fetch() ?: null;
}

/** „Ostatnie zmiany”: rewizje wszystkich stron, od najnowszej. */
function recentChanges(int $limit = 50): array
{
    $stmt = db()->prepare("SELECT r.id, r.page_id, p.path, r.title, r.summary, r.author_name, r.created_at
        FROM wiki_revisions r JOIN wiki_pages p ON p.id = r.page_id
        WHERE p.status != 'deleted' ORDER BY r.id DESC LIMIT :limit");
    $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function redirectTarget(string $fromPath): ?string
{
    $stmt = db()->prepare("SELECT to_path FROM wiki_redirects WHERE from_path = :from");
    $stmt->execute([':from' => $fromPath]);
    return $stmt->fetchColumn() ?: null;
}

/** Dodaje przekierowanie i spłaszcza istniejące wskazujące na $fromPath (redirecty zawsze jednoskokowe). */
function addRedirect(string $fromPath, string $toPath): void
{
    if ($fromPath === $toPath)
        throw new \InvalidArgumentException("Przekierowanie na samą siebie: $fromPath");
    $db = db();
    $stmt = $db->prepare("INSERT OR REPLACE INTO wiki_redirects (from_path, to_path, created_at) VALUES (:from, :to, :now)");
    $stmt->execute([':from' => $fromPath, ':to' => $toPath, ':now' => date('c')]);
    $stmt = $db->prepare("UPDATE wiki_redirects SET to_path = :to WHERE to_path = :from");
    $stmt->execute([':from' => $fromPath, ':to' => $toPath]);
    $stmt = $db->prepare("DELETE FROM wiki_redirects WHERE from_path = to_path");
    $stmt->execute();
}

function unitSlug(string $unitType, string $unitKey): ?string
{
    $stmt = db()->prepare("SELECT slug FROM wiki_unit_slugs WHERE unit_type = :type AND unit_key = :key");
    $stmt->execute([':type' => $unitType, ':key' => $unitKey]);
    return $stmt->fetchColumn() ?: null;
}

/** Jednostki pod slugiem (komisariat może mieć kilka kluczy). @return list<array{unit_type:string,unit_key:string}> */
function unitsBySlug(string $slug): array
{
    $stmt = db()->prepare("SELECT unit_type, unit_key FROM wiki_unit_slugs WHERE slug = :slug ORDER BY unit_key");
    $stmt->execute([':slug' => $slug]);
    return $stmt->fetchAll();
}

/** Zamraża slug jednostki; istniejący nie jest nadpisywany (zmiana nazwy w konfiguracji nie zmienia URL-a). */
function freezeUnitSlug(string $unitType, string $unitKey, string $slug): string
{
    $stmt = db()->prepare("INSERT OR IGNORE INTO wiki_unit_slugs (unit_type, unit_key, slug) VALUES (:type, :key, :slug)");
    $stmt->execute([':type' => $unitType, ':key' => $unitKey, ':slug' => $slug]);
    return unitSlug($unitType, $unitKey);
}

/** Usuwanie konta (UserRemoval.php): treść zostaje, autor znika z rewizji i stron. Zwraca liczbę rewizji. */
function anonymizeAuthor(string $email): int
{
    $db = db();
    $stmt = $db->prepare("UPDATE wiki_revisions SET author_email = NULL, author_name = :name WHERE author_email = :email");
    $stmt->execute([':name' => DELETED_AUTHOR, ':email' => $email]);
    $count = $stmt->rowCount();
    $db->prepare("UPDATE wiki_pages SET updated_by = NULL WHERE updated_by = :email")->execute([':email' => $email]);
    return $count;
}

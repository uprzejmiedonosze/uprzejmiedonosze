<?PHP
/**
 * Synchronizacja wiki → baza wiedzy asystenta AI (src/inc/assistant/knowledge/wiki-*.md).
 *
 *   php tools/wiki-knowledge.php --ssh                 # prod: db/wiki.sqlite przez ssh (sqlite3 -readonly, tylko SELECT)
 *   php tools/wiki-knowledge.php --db=<wiki.sqlite>    # lokalna kopia, np. services/devroot/db/wiki.sqlite
 *   ... [--dry-run]                                    # tylko podsumowanie, bez zapisu
 *
 * Każda opublikowana strona (poza EXCLUDE) → jeden plik `wiki-<ścieżka>.md`. Każdy nagłówek z treścią (np. pytanie
 * w FAQ) to osobny fragment `## …` — Corpus dzieli wiedzę po `##` i wyszukuje fragmenty, a nie całe strony. Fragment
 * zaczyna się kontekstem („Strona › Dział”) i kończy linkiem źródłowym z kotwicą liczoną jak w \wiki\Renderer.
 * Pliki wiki-*.md stron, których już nie ma, są usuwane; pliki pisane ręcznie (podstawy.md itd.) — nietknięte.
 *
 * Treść wiki edytują użytkownicy (ADR 0006), więc to niezaufany tekst: przejrzyj `git diff` przed commitem.
 */

require_once __DIR__ . '/../src/inc/config.php'; // SEXSTRINGS

const KNOWLEDGE_DIR = __DIR__ . '/../src/inc/assistant/knowledge';
const SITE = 'https://uprzejmiedonosze.net';
const SSH_HOST = 'uprzejmiedonosze.net';
const REMOTE_DB = '/var/www/uprzejmiedonosze.net/db/wiki.sqlite';
/** Strony bez wartości dla asystenta (nawigacja, porady techniczne bez związku ze zgłoszeniami). */
const EXCLUDE = ['start', 'prawo', 'pytania/chrome-autofill', 'pytania/dekalog-patoparkowania'];
const RESERVED_IDS = ['main', 'header', 'footer', 'content', 'menu', 'nav', 'sidebar', 'toc']; // jak w Wiki.php
const QUERY = "SELECT path, title, route_alias, body_md, updated_at FROM wiki_pages WHERE status = 'published' ORDER BY path";

/** Slug kotwicy — kopia \wiki\slugify (Wiki.php wymaga autoloadu commonmark, a tu wystarczy ta funkcja). */
function slugify(string $text): string {
    static $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z'];
    $text = mb_strtolower(strtr($text, $map));
    return trim(preg_replace('/[^a-z0-9]+/', '-', $text), '-');
}

/** @return list<array{path:string,title:string,route_alias:?string,body_md:string,updated_at:string}> */
function loadPages(array $opts): array {
    if (isset($opts['db'])) {
        $pdo = new PDO('sqlite:' . $opts['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return $pdo->query(QUERY)->fetchAll(PDO::FETCH_ASSOC);
    }
    $remote = 'sqlite3 -readonly -json ' . escapeshellarg(REMOTE_DB) . ' ' . escapeshellarg(QUERY);
    $json = shell_exec('ssh ' . escapeshellarg(SSH_HOST) . ' ' . escapeshellarg($remote));
    $pages = json_decode((string)$json, true);
    if (!is_array($pages)) {
        fwrite(STDERR, "Nie udało się odczytać wiki przez ssh (odpowiedź: " . substr((string)$json, 0, 200) . ")\n");
        exit(1);
    }
    return $pages;
}

/** {{sex:x}} → „forma żeńska/forma męska” (asystent nie wie, kto pyta). */
function personalize(string $text): string {
    return preg_replace_callback('/\{\{sex:(\w+)\}\}/u', function ($m) {
        $f = SEXSTRINGS['f'][$m[1]] ?? null;
        $mm = SEXSTRINGS['m'][$m[1]] ?? null;
        if ($f === null || $mm === null) return SEXSTRINGS['?'][$m[1]] ?? $m[0];
        return $f === $mm ? $f : "$f/$mm";
    }, $text);
}

/**
 * Markdown strony → fragmenty [{title, crumbs, anchor, lines}]. Dyrektywy (:::howto, :::wzor…) zostają jako tytuł +
 * treść, osadzenia [[…]] i obrazki są pomijane, kotwice {#id} zdejmowane (kotwica liczona jak w Renderer::headingId).
 */
function chunks(string $body, string $url): array {
    $chunks = [['title' => null, 'crumbs' => [], 'anchor' => null, 'lines' => []]];
    $stack = []; // poziom => tekst nagłówka
    $used = [];
    $inFence = false;
    foreach (preg_split('/\R/u', personalize($body)) as $line) {
        if (preg_match('/^\s*(```|~~~)/', $line)) $inFence = !$inFence;
        if (!$inFence && preg_match('/^(#{1,6})\s+(.*?)\s*$/u', $line, $m)) {
            $level = strlen($m[1]);
            $text = $m[2];
            $id = null;
            if (preg_match('/\s*\{#([^}\s]+)\}\s*$/u', $text, $a)) {
                $text = trim(substr($text, 0, -strlen($a[0])));
                $id = $a[1];
            }
            if ($id === null) {
                $id = slugify(strip_tags($text)) ?: 'sekcja';
                if (in_array($id, RESERVED_IDS, true)) $id = "sekcja-$id";
            }
            for ($unique = $id, $i = 2; isset($used[$unique]); $i++) $unique = "$id-$i";
            $used[$unique] = true;
            $stack = array_filter($stack, fn($l) => $l < $level, ARRAY_FILTER_USE_KEY);
            $chunks[] = ['title' => $text, 'crumbs' => array_column($stack, 'text'), 'anchor' => $unique, 'lines' => []];
            $stack[$level] = ['text' => $text, 'anchor' => $unique];
            continue;
        }
        if (!$inFence) {
            if (preg_match('/^:{3,}\s*(\w[\w-]*)?\s*(\{.*\})?\s*$/u', $line, $d)) { // otwarcie/zamknięcie dyrektywy
                $title = !empty($d[2]) && preg_match('/\btitle="([^"]*)"/u', $d[2], $t) ? $t[1] : null;
                $id = !empty($d[2]) && preg_match('/\bid=([\p{Ll}0-9-]+)/u', $d[2], $i) ? $i[1] : null;
                if ($id !== null && $title !== null) { // karta z kotwicą (:::howto) = osobny fragment
                    $chunks[] = ['title' => $title, 'crumbs' => array_column($stack, 'text'), 'anchor' => $id, 'lines' => []];
                    continue;
                }
                if (empty($d[1]) && $stack) { // zamknięcie: dalszy tekst należy znów do bieżącego nagłówka
                    $top = end($stack);
                    $chunks[] = ['title' => $top['text'], 'crumbs' => array_column(array_slice($stack, 0, -1), 'text'),
                        'anchor' => $top['anchor'], 'lines' => []];
                    continue;
                }
                if ($title === null) continue;
                $line = "**$title**";
            }
            if (preg_match('/^\s*\[\[[a-z]+(:[^\]]*)?\]\]\s*$/', $line)) continue; // osadzenia: toc, metody, data, jednostka
            $line = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $line);               // obrazki
            $line = preg_replace('/\]\(#([^)\s]+)\)/u', "]($url#$1)", $line);          // kotwice w obrębie strony
        }
        $chunks[count($chunks) - 1]['lines'][] = $line;
    }
    return array_values(array_filter($chunks, fn($c) => trim(implode("\n", $c['lines'])) !== ''));
}

function render(array $page): string {
    $url = $page['route_alias'] ? "/{$page['route_alias']}.html" : "/wiki/{$page['path']}";
    $out = ["# {$page['title']}", ''];
    foreach (chunks($page['body_md'], $url) as $c) {
        $title = $c['title'] ?? $page['title'];
        $context = implode(' › ', array_merge([$page['title']], $c['crumbs']));
        $link = SITE . $url . ($c['anchor'] ? "#{$c['anchor']}" : '');
        $out[] = "## $title";
        if ($c['title'] !== null) $out[] = "_{$context}_";
        $out[] = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $c['lines'])));
        $out[] = "Źródło: $link";
        $out[] = '';
    }
    return implode("\n", $out);
}

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--ssh') $opts['ssh'] = true;
    elseif ($arg === '--dry-run') $opts['dry'] = true;
    elseif (str_starts_with($arg, '--db=')) $opts['db'] = substr($arg, 5);
    else { fwrite(STDERR, "Nieznany argument: $arg\n"); exit(2); }
}
if (!isset($opts['ssh']) && !isset($opts['db'])) {
    fwrite(STDERR, "Użycie: php tools/wiki-knowledge.php --ssh | --db=<wiki.sqlite> [--dry-run]\n");
    exit(2);
}

$written = [];
$stats = ['nowe' => 0, 'zmienione' => 0, 'bez zmian' => 0, 'usunięte' => 0];
foreach (loadPages($opts) as $page) {
    if (in_array($page['path'], EXCLUDE, true) || trim($page['body_md']) === '') continue;
    $file = KNOWLEDGE_DIR . '/wiki-' . str_replace('/', '--', $page['path']) . '.md';
    $content = render($page);
    $written[$file] = true;
    $old = is_file($file) ? file_get_contents($file) : null;
    $stats[$old === null ? 'nowe' : ($old === $content ? 'bez zmian' : 'zmienione')]++;
    if ($old !== $content && empty($opts['dry'])) file_put_contents($file, $content);
}
foreach (glob(KNOWLEDGE_DIR . '/wiki-*.md') ?: [] as $file) {
    if (isset($written[realpath($file)]) || isset($written[$file])) continue;
    $stats['usunięte']++;
    if (empty($opts['dry'])) unlink($file);
    echo 'usunięto ' . basename($file) . "\n";
}
echo (empty($opts['dry']) ? '' : '[dry-run] ') . count($written) . ' stron: '
    . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($stats), $stats)) . "\n";

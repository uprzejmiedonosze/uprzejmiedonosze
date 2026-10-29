<?PHP namespace admin;

/**
 * Jednorazowy import DokuWiki → db/wiki.sqlite (docs/plans/2026-10-28-wiki-w-ud.md, sekcja 4).
 *
 *   php wiki-import.php <katalog data/ z DokuWiki> [--dry-run] [--show-notes] [--db=<plik .sqlite>]
 *
 * --db: import do wskazanego pliku zamiast db/wiki.sqlite (np. przygotowanie bazy do wgrania na prod).
 *
 * Katalog z kopią data/ (pages/ i media/), np. z:
 *   ssh uprzejmiedonosze.net "cd /opt/wiki && docker compose exec -T wiki tar -C /var/www/html/data -czf - pages media"
 *
 * Importuje artykuły (mapa \wiki\import\PAGES), notatki jednostek (wolny tekst ze stron sluzby/*, mapa
 * wiki-import-units.json) i zapisuje przekierowania ze starych adresów. Media muszą już leżeć w public/img/wiki/
 * (src/img/wiki/ w repo) — skrypt tylko wypisuje brakujące. Ponowne uruchomienie nie dubluje rewizji
 * (savePage pomija niezmienioną treść), przekierowania są nadpisywane.
 */

foreach ($argv as $arg)
    if (str_starts_with($arg, '--db=')) define('WIKI_DB_FILENAME', substr($arg, 5)); // przed common.php (WikiStore)

require_once(__DIR__ . '/common.php');
require_once(__DIR__ . '/../inc/Twig.php');
require_once(__DIR__ . '/../inc/Wiki.php');
require_once(__DIR__ . '/../inc/WikiImport.php');

use function wiki\import\cleanId;

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$dryRun = in_array('--dry-run', $argv, true);
$dataDir = rtrim($args[0] ?? '', '/');
if (!is_dir("$dataDir/pages")) {
    fwrite(STDERR, "Użycie: php wiki-import.php <katalog data/ DokuWiki> [--dry-run]\n");
    exit(1);
}

/** Dopisywane do strony głównej: działy, których DokuWiki nie linkowała z treści (były w sidebarze). */
const SECTIONS_MD = <<<'MD'

## Działy

- [Prawo](/wiki/prawo) — przepisy i ich interpretacja
- [Wzory pism](/wiki/wzory)
- [Pytania od użytkowników](/wiki/pytania)
- [Straże miejskie i komisariaty Policji](/wiki/sluzby)
MD;

/** trasa www → [ścieżka w wiki, tytuł]; adres /wiki/<ścieżka> przekierowuje na /<trasa>.html */
const WWW_SEED = [
    'faq' => ['faq', 'Najczęściej zadawane pytania'],
    'przesluchanie' => ['przesluchanie', 'Czy straż miejska wezwie mnie na przesłuchanie?'],
    'jak-zglosic-nielegalne-parkowanie' => ['jak-zglosic', 'Jak zgłaszać nieprawidłowe parkowanie'],
];

$unitMap = json_decode(file_get_contents(__DIR__ . '/wiki-import-units.json'), true);
foreach (\wiki\import\UNIT_PAGES_EXTRA as $id => $unit) $unitMap[$id] = $unit;

/** stare id → nowa ścieżka wiki albo adres www ('/…'); null = nieznane */
$target = function (string $id) use ($unitMap): ?string {
    if (array_key_exists($id, \wiki\import\PAGES)) {
        [$path] = \wiki\import\PAGES[$id];
        return $path ?? \wiki\import\REDIRECT[explode(':', $id)[0]] ?? \wiki\import\REDIRECT[''];
    }
    if (array_key_exists($id, $unitMap)) {
        $unit = $unitMap[$id];
        $entry = $unit ? \wiki\unitByKey($unit[0], $unit[1]) : null;
        if ($unit && !$entry) fwrite(STDERR, "UWAGA: brak jednostki {$unit[0]}/{$unit[1]} dla $id\n");
        return $entry ? 'sluzby/' . $entry['slug'] : 'sluzby';
    }
    if (preg_match('/^sluzby(:[a-z_]+)?(:start)?$/', $id)) return 'sluzby'; // listy wojewódzkie
    return null;
};
$titleOf = fn(string $id) => \wiki\import\PAGES[$id][1] ?? null;
/** savePage bez nowej rewizji, gdy treść się nie zmieniła — ponowny import nie dubluje historii. */
$save = function (string $path, string $pageTitle, string $markdown, string $summary, string $section = '', ?string $alias = null) {
    $page = \wiki\getPage($path);
    if ($page && $page['title'] === $pageTitle && $page['body_md'] === $markdown) return;
    \wiki\savePage($path, $pageTitle, $markdown, $summary, null, \wiki\import\AUTHOR, $section, $alias);
};
$url = fn(?string $path) => $path === null ? null : (str_starts_with($path, '/') ? $path : ($path === 'start' ? '/wiki' : "/wiki/$path"));

$stats = ['pages' => 0, 'notes' => 0, 'redirects' => 0, 'skipped' => 0];
$media = [];
$notes = [];

$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$dataDir/pages", \FilesystemIterator::SKIP_DOTS));
$pages = [];
foreach ($files as $file) {
    if ($file->getExtension() !== 'txt') continue;
    $relative = substr($file->getPathname(), strlen("$dataDir/pages/"), -4);
    $pages[cleanId($relative)] = $file->getPathname();
}
ksort($pages);

foreach ($pages as $id => $file) {
    $doku = strtr(file_get_contents($file), \wiki\import\REDACT[$id] ?? []);
    $namespace = str_contains($id, ':') ? substr($id, 0, strrpos($id, ':')) : '';
    $newPath = $target($id);
    $legacyPath = str_replace(':', '/', $id);

    if ($newPath === null) {
        // wiki:*, playground:* — pomoc DokuWiki i brudnopisy: nie importujemy, adres prowadzi na stronę główną
        $newPath = \wiki\import\REDIRECT[''];
        $stats['skipped']++;
    }

    $isUnit = array_key_exists($id, $unitMap);
    $importAs = null;
    if ($isUnit && $unitMap[$id]) {
        $entry = \wiki\unitByKey($unitMap[$id][0], $unitMap[$id][1]);
        $note = $entry ? \wiki\import\unitNote($doku, $entry['unit'], $entry['type'], $entry['keys']) : '';
        if ($note !== '') {
            // kilka starych stron jednej jednostki (np. z kodem pocztowym w nazwie) → notatki sklejone
            $notes[$newPath][] = $note;
            $importAs = ['note', $entry];
        }
    } elseif (!$isUnit && array_key_exists($id, \wiki\import\PAGES) && \wiki\import\PAGES[$id][0] !== null
        && !str_starts_with(\wiki\import\PAGES[$id][0], '/') && $newPath !== 'sluzby') {
        $importAs = ['page', \wiki\import\PAGES[$id][1]];
    }

    if ($importAs && $importAs[0] === 'page') {
        $converter = new \wiki\import\Converter(fn(string $linkId) => $url($target($linkId)), $namespace, $titleOf);
        $markdown = $converter->convert($doku);
        if ($newPath === 'start') $markdown .= SECTIONS_MD;
        $title = $importAs[1] ?? $converter->title ?? $newPath;
        array_push($media, ...$converter->media);
        $result = \wiki\renderer()->render($markdown);
        foreach (array_merge($converter->warnings, $result->errors) as $warning)
            echo "  [$newPath] $warning\n";
        if (!$dryRun) $save($newPath, $title, $markdown, 'Import z DokuWiki', \wiki\sectionOf($newPath));
        $stats['pages']++;
        echo "strona   $id → $newPath\n";
    }

    if ($legacyPath !== $newPath && !$dryRun) {
        \wiki\addRedirect($legacyPath, $newPath);
        $stats['redirects']++;
    }
}

foreach (\wiki\import\TYPO_REDIRECTS as $from => $id) {
    $to = $target($id);
    if ($to === null) { fwrite(STDERR, "UWAGA: literówka $from → nieznane id $id\n"); continue; }
    if (!$dryRun) \wiki\addRedirect($from, $to);
    $stats['redirects']++;
}

// Strony www edytowane w wiki (\wiki\WWW_PAGES): treść startowa przepisana z szablonów, src/tools/wiki-seed/<trasa>.md
foreach (WWW_SEED as $route => [$path, $pageTitle]) {
    $markdown = file_get_contents(__DIR__ . "/wiki-seed/$route.md");
    foreach (\wiki\renderer()->render($markdown)->errors as $warning) echo "  [$path] $warning\n";
    if (!$dryRun) $save($path, $pageTitle, $markdown, 'Przeniesione z szablonu www', '', $route);
    echo "strona   (www) /$route.html → $path\n";
}

// Spis działu „Prawo” (w DokuWiki linkowany z nieistniejącej strony wiki:prawo)
$lawPages = array_filter(\wiki\import\PAGES, fn($page) => str_starts_with($page[0] ?? '', 'prawo/'));
$lawIndex = "Artykuły o przepisach, na które powołujemy się w zgłoszeniach i zażaleniach.\n\n"
    . implode("\n", array_map(fn($page) => "- [{$page[1]}](/wiki/{$page[0]})", $lawPages)) . "\n";
if (!$dryRun) $save('prawo', 'Prawo', $lawIndex, 'Spis działu (import z DokuWiki)');
echo "strona   (spis) → prawo\n";

foreach ($notes as $path => $parts) {
    $converter = new \wiki\import\Converter(fn(string $linkId) => $url($target($linkId)), 'sluzby', $titleOf);
    $markdown = $converter->convert(implode("\n\n", array_unique($parts)));
    $entry = \wiki\unitBySlug(substr($path, strlen('sluzby/')));
    array_push($media, ...$converter->media);
    foreach ($converter->warnings as $warning) echo "  [$path] $warning\n";
    if (in_array('--show-notes', $argv, true)) echo "---- $path\n$markdown\n";
    if (!$dryRun) $save($path, $entry['unit']->getName(), $markdown, 'Import z DokuWiki', 'sluzby');
    $stats['notes']++;
    echo "notatka  → $path\n";
}

if (!$dryRun) echo "\nIndeks wyszukiwarki: " . \wiki\reindexAll() . " stron\n";

$media = array_values(array_unique($media));
$mediaDir = __DIR__ . '/../public/img/wiki';
$missing = array_filter($media, fn($name) => !is_file("$mediaDir/$name"));
echo "\nMedia użyte w treści: " . count($media) . ($missing ? ', BRAK w public/img/wiki: ' . implode(', ', $missing) : '') . "\n";
printf("Strony: %d, notatki jednostek: %d, przekierowania: %d, pominięte (pomoc/brudnopisy): %d%s\n",
    $stats['pages'], $stats['notes'], $stats['redirects'], $stats['skipped'], $dryRun ? ' (dry-run, nic nie zapisano)' : '');

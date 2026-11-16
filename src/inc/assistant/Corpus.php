<?PHP namespace assistant\corpus;

/**
 * Korpus tekstów, z których asystent czerpie wiedzę: baza wiedzy (src/inc/assistant/knowledge/*.md) i akty prawne
 * (src/inc/assistant/legal/*.md + index.json). Nic nie trafia do kontekstu w całości — pliki są dzielone na fragmenty
 * (sekcje `##` / artykuły `## Art. N`), a model dostaje przez narzędzia tylko te najlepiej pasujące do pytania.
 *
 * Wyszukiwanie jest celowo proste (bez zewnętrznego indeksu): tokeny bez polskich znaków, dopasowanie po prefiksie
 * (łagodzi odmianę: „parkowanie”/„parkowania”/„parkować”), waga IDF, nagłówek liczy się potrójnie.
 */

const PREFIX_LEN = 5;
const STOPWORDS = ['jak', 'jaki', 'jaka', 'jakie', 'czy', 'czym', 'oraz', 'ktory', 'ktora', 'ktore', 'przez', 'dla', 'nie',
    'sie', 'jest', 'sa', 'ale', 'albo', 'lub', 'tez', 'moj', 'moja', 'moje', 'mam', 'moge', 'chce', 'czemu', 'gdzie', 'kiedy'];

/** @var array<string,list<array{source:string,title:string,key:string,text:string}>> */
$GLOBALS['assistantCorpusCache'] = [];
$GLOBALS['assistantCorpusRoot'] = null;

/** Katalog z danymi (testy podstawiają własny). null = domyślny, obok tego pliku. */
function setRoot(?string $dir): void {
    $GLOBALS['assistantCorpusRoot'] = $dir;
    $GLOBALS['assistantCorpusCache'] = [];
}

function root(): string {
    return rtrim($GLOBALS['assistantCorpusRoot'] ?? __DIR__, '/');
}

/** Małe litery, bez polskich znaków. */
function fold(string $text): string {
    return strtr(mb_strtolower($text), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
}

/** Znaczące słowa (z powtórzeniami) jako prefiksy, bez stopwords. @return list<string> */
function words(string $text): array {
    preg_match_all('/[a-z0-9]+/', fold($text), $m);
    $out = [];
    foreach ($m[0] as $word) {
        $isNumber = ctype_digit($word);
        if (in_array($word, STOPWORDS, true) || (strlen($word) < 3 && !$isNumber)) continue;
        $out[] = substr($word, 0, $isNumber ? 12 : PREFIX_LEN);
    }
    return $out;
}

/** @return list<string> unikalne słowa zapytania */
function tokens(string $text): array {
    return array_values(array_unique(words($text)));
}

/**
 * Dzieli Markdown na fragmenty po nagłówkach `##` (treść przed pierwszym `##` to fragment wprowadzający).
 * Nagłówki `###` zostają w treści fragmentu. @return list<array{title:string,text:string}>
 */
function splitSections(string $markdown): array {
    $sections = [];
    $title = '';
    $buf = [];
    $flush = function () use (&$sections, &$title, &$buf) {
        $text = trim(implode("\n", $buf));
        if ($text !== '' || $title !== '') $sections[] = ['title' => $title, 'text' => $text];
        $buf = [];
    };
    foreach (preg_split('/\R/', $markdown) as $line) {
        if (preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
            $flush();
            $title = $m[1];
        } elseif (preg_match('/^#\s+(.+?)\s*$/', $line, $m) && $title === '' && !$buf) {
            $title = $m[1]; // tytuł dokumentu jako tytuł fragmentu wprowadzającego
        } else {
            $buf[] = $line;
        }
    }
    $flush();
    return array_values(array_filter($sections, fn($s) => trim($s['text']) !== ''));
}

/**
 * Baza wiedzy: wszystkie pliki *.md z knowledge/. @return list<array{source:string,title:string,key:string,text:string}>
 */
function knowledge(): array {
    return $GLOBALS['assistantCorpusCache']['knowledge'] ??= (function () {
        $chunks = [];
        foreach (glob(root() . '/knowledge/*.md') ?: [] as $file) {
            $source = basename($file, '.md');
            foreach (splitSections((string)file_get_contents($file)) as $s)
                $chunks[] = ['source' => $source, 'title' => $s['title'], 'key' => $s['title'], 'text' => $s['text']];
        }
        return $chunks;
    })();
}

/** Plik `knowledge/podstawy.md` — zawsze w prompcie systemowym (krótki zestaw faktów, których asystent nie może pomylić). */
function basics(): string {
    $file = root() . '/knowledge/podstawy.md';
    return is_file($file) ? trim((string)file_get_contents($file)) : '';
}

/**
 * Spis aktów z legal/index.json: [{id,title,short,ref,url}]. Brak pliku = brak aktów.
 * @return list<array{id:string,title:string,short:string,ref:string,url:string}>
 */
function acts(): array {
    return $GLOBALS['assistantCorpusCache']['acts'] ??= (function () {
        $file = root() . '/legal/index.json';
        $json = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $acts = [];
        foreach ($json['acts'] ?? [] as $a) {
            if (!is_string($a['id'] ?? null) || !preg_match('/^[a-z0-9_-]+$/', $a['id'])) continue;
            $acts[] = ['id' => $a['id'], 'title' => (string)($a['title'] ?? $a['id']), 'short' => (string)($a['short'] ?? ''),
                'ref' => (string)($a['ref'] ?? ''), 'url' => (string)($a['url'] ?? '')];
        }
        return $acts;
    })();
}

/**
 * Artykuły aktów prawnych — fragment = `## Art. N` (np. „Art. 49”, „Art. 92a”); key to numer bez „Art.”, source to id aktu.
 * @return list<array{source:string,title:string,key:string,text:string}>
 */
function legal(?string $actId = null): array {
    $all = $GLOBALS['assistantCorpusCache']['legal'] ??= (function () {
        $chunks = [];
        foreach (acts() as $act) {
            $file = root() . "/legal/{$act['id']}.md";
            if (!is_file($file)) continue;
            foreach (splitSections((string)file_get_contents($file)) as $s) {
                if (!preg_match('/^Art\.?\s*(\d+[a-z]*)\b/iu', $s['title'], $m)) continue; // pomijamy nagłówki spoza artykułów
                $chunks[] = ['source' => $act['id'], 'title' => $s['title'], 'key' => mb_strtolower($m[1]), 'text' => $s['text']];
            }
        }
        return $chunks;
    })();
    return $actId === null ? $all : array_values(array_filter($all, fn($c) => $c['source'] === $actId));
}

/**
 * Najlepiej pasujące fragmenty (malejąco wg trafności). Fragmenty bez żadnego trafienia są pomijane.
 * @param list<array{source:string,title:string,key:string,text:string}> $chunks
 * @return list<array{source:string,title:string,key:string,text:string,score:float}>
 */
function search(array $chunks, string $query, int $limit = 4): array {
    $qTokens = tokens($query);
    if (!$qTokens || !$chunks) return [];

    $docs = [];
    $df = array_fill_keys($qTokens, 0);
    foreach ($chunks as $i => $c) {
        $body = array_count_values(words($c['text']));
        $head = array_count_values(words($c['title']));
        $docs[$i] = [$body, $head];
        foreach ($qTokens as $t)
            if (isset($body[$t]) || isset($head[$t])) $df[$t]++;
    }

    $n = count($chunks);
    $scored = [];
    foreach ($docs as $i => [$body, $head]) {
        $score = 0.0;
        foreach ($qTokens as $t) {
            if (!$df[$t]) continue;
            $idf = log(1 + $n / $df[$t]);
            $score += $idf * (($body[$t] ?? 0) ? 1 + log($body[$t]) : 0);
            $score += $idf * 3 * (isset($head[$t]) ? 1 : 0);
        }
        if ($score > 0) $scored[] = $chunks[$i] + ['score' => round($score, 3)];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, max(1, $limit));
}

/** Artykuł aktu po numerze („49”, „Art. 92a”, „92A”); null gdy brak. */
function article(string $actId, string $number): ?array {
    if (!preg_match('/(\d+[a-z]*)/i', $number, $m)) return null;
    $key = mb_strtolower($m[1]);
    foreach (legal($actId) as $c)
        if ($c['key'] === $key) return $c;
    return null;
}

/**
 * Skille instrukcyjne: skills/*.md z nagłówkiem YAML-owym (`name`, `description`) i treścią — instrukcją krok po kroku
 * (np. jak przygotować odwołanie od decyzji SM albo skargę). Prompt systemowy zawiera tylko nazwę i opis; pełną treść
 * model pobiera narzędziem `load_skill`, gdy pytanie jej dotyczy. Nowy skill = nowy plik, bez zmian w kodzie.
 * @return array<string,array{name:string,description:string,body:string}> klucz = nazwa
 */
function skills(): array {
    return $GLOBALS['assistantCorpusCache']['skills'] ??= (function () {
        $skills = [];
        foreach (glob(root() . '/skills/*.md') ?: [] as $file) {
            $raw = (string)file_get_contents($file);
            if (!preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $raw, $m)) continue; // bez nagłówka = nie skill (np. README)
            $meta = [];
            foreach (preg_split('/\R/', $m[1]) as $line)
                if (preg_match('/^(name|description):\s*(.+?)\s*$/', $line, $kv)) $meta[$kv[1]] = trim($kv[2], "\"' ");
            if (empty($meta['name']) || !preg_match('/^[a-z0-9_-]+$/', $meta['name'])) continue;
            $skills[$meta['name']] = ['name' => $meta['name'], 'description' => $meta['description'] ?? '', 'body' => trim($m[2])];
        }
        return $skills;
    })();
}

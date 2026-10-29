<?PHP namespace wiki\import;

/**
 * Jednorazowa migracja DokuWiki → wiki w UD (docs/plans/2026-10-28-wiki-w-ud.md, sekcja 4).
 * Używane przez src/tools/wiki-import.php i tests/wiki/WikiImportTest.php; aplikacja tego nie ładuje.
 *
 * Mapa PAGES: stare id DokuWiki → [nowa ścieżka, tytuł]. Ścieżka zaczynająca się od '/' = przekierowanie poza wiki
 * (np. '/faq.html' — kanoniczne FAQ jest w www), null = strona nie jest importowana, a jej adres przekierowuje na REDIRECT.
 */

const AUTHOR = 'Import z DokuWiki';

const PAGES = [
    'start' => ['start', 'Wiki Uprzejmie Donoszę'],
    'faq' => ['/faq.html', null],
    'sidebar' => [null, null],
    // poradniki
    'niezbednik:czesto_zadawane_pytania' => ['/faq.html', null],
    'niezbednik:gdzie_jeszcze_mozna_zglosic' => ['gdzie-jeszcze-zglosic', 'Gdzie jeszcze można zgłosić?'],
    'niezbednik:jak_napisac_zazalenie_na_decyzje_strazy_miejskiej_lub_policji' => ['zazalenie-na-decyzje-sm', 'Jak napisać zażalenie na decyzję straży miejskiej lub Policji'],
    'niezbednik:jak_sprawdzic_wlasnosc_dzialki' => ['wlasnosc-dzialki', 'Jak sprawdzić własność działki'],
    'niezbednik:jak_walczyc_z_patoparkowaniem' => ['jak-walczyc-z-patoparkowaniem', 'Jak walczyć z patoparkowaniem'],
    'niezbednik:problemy_z_aplikacja' => ['problemy-z-aplikacja', 'Problemy z aplikacją'],
    'niezbednik:wysylka_przez_e-doreczenia' => ['wysylka-e-doreczenia', 'Wysyłka przez e-Doręczenia'],
    'niezbednik:wysylka_przez_epuap' => ['wysylka-epuap', 'Wysyłka przez ePUAP'],
    'niezbednik:zwrot_srodkow_za_przesluchanie' => ['zwrot-za-przesluchanie', 'Zwrot środków za przesłuchanie'],
    'niezbednik:start' => [null, null],
    'niezbednik:oznakowanie_-_zmiana_poprawa_usiniecie' => [null, null],
    // prawo
    'niezbednik:przepisy_i_kategorie_zgloszen' => ['prawo/przepisy-i-kategorie', 'Przepisy i kategorie zgłoszeń'],
    'niezbednik:znaki' => ['prawo/znaki', 'Znaki'],
    'niezbednik:trawnik_kw_144_i_zazalenie' => ['prawo/trawnik-art-144', 'Parkowanie na trawniku (art. 144 KW) i zażalenie'],
    'niezbednik:o_1.5_metra_chodnika' => ['prawo/1-5-metra-chodnika', '1,5 metra chodnika'],
    'niezbednik:aspekty_holowania_i_odpowiedzialnosci_dot._parkowania_na_miejscach_dla_ozn' => ['prawo/holowanie-z-miejsc-dla-niepelnosprawnych', 'Holowanie i odpowiedzialność za parkowanie na miejscach dla osób z niepełnosprawnościami'],
    'niezbednik:odpowiedzialnosc_kierowcy_i_niekompletne_oznakowanie_dla_ozn' => ['prawo/niekompletne-oznakowanie-miejsc-dla-niepelnosprawnych', 'Odpowiedzialność kierowcy a niekompletne oznakowanie miejsc dla osób z niepełnosprawnościami'],
    // wzory pism
    'wzory_pism:start' => ['wzory', 'Wzory pism'],
    'wzory_pism:droga-gminna-uzupelnienie' => ['wzory/droga-gminna', 'Droga na terenie gminy — uzupełnienie zgłoszenia'],
    'wzory_pism:informacja_o_interwencji' => ['wzory/informacja-o-interwencji', 'Wniosek o informację o interwencji'],
    'wzory_pism:skarga_na_sposob_zalatwienia' => ['wzory/skarga-na-sposob-zalatwienia', 'Skarga na sposób załatwienia sprawy'],
    'wzory_pism:zazalenia-niewniesienie-wniosku-o-ukarania' => ['wzory/zazalenie-na-niewniesienie-wniosku-o-ukaranie', 'Zażalenie na niewniesienie wniosku o ukaranie'],
    'wzory_pism:zazalenie-wezwanie-na-przesluchanie' => ['wzory/odpowiedz-na-wezwanie-na-przesluchanie', 'Odpowiedź na wezwanie na przesłuchanie'],
    'wzory_pism:zwrot-srodkow-za-przesluchanie' => ['wzory/zwrot-za-przesluchanie', 'Wniosek o zwrot kosztów stawiennictwa'],
    'wzory_pism:zazalenie-test' => [null, null],
    // pytania
    'pytania' => ['pytania', 'Pytania od użytkowników'],
    'pytania:anulowanie-zgloszenia' => ['pytania/anulowanie-zgloszenia', 'Jak wycofać wysłane zgłoszenie?'],
    'pytania:chrome-autofill' => ['pytania/chrome-autofill', 'Jak wyłączyć zapamiętywanie numerów rejestracyjnych w Chrome?'],
    'pytania:czy-dostane-odpowiedz' => ['pytania/czy-dostane-odpowiedz', 'Czy dostanę odpowiedź, czy kierowca dostał mandat?'],
    'pytania:czy-musze-sie-stawic' => ['pytania/czy-musze-sie-stawic', 'Czy muszę stawiać się osobiście na komendzie?'],
    'pytania:dekalog-patoparkowania:dekalog_patoparkowania' => ['pytania/dekalog-patoparkowania', 'Dekalog patoparkowania'],
    'pytania:nie-widze-postepu-w-mandatach' => ['pytania/nie-widze-postepu-w-mandatach', 'Nie widzę postępu w mandatach'],
    'pytania:problem-z-wyslaniem-zgloszen' => ['pytania/problem-z-wyslaniem-zgloszen', 'Problem z wysłaniem zgłoszeń'],
    'pytania:zgloszenia-do-strazy-miejskiej' => ['pytania/zgloszenia-do-strazy-miejskiej', 'Zgłoszenia do straży miejskiej'],
    'pytania:zly-adres-na-mapie:zly_adres_na_mapie' => ['pytania/zly-adres-na-mapie', 'Zły adres na mapie'],
    // sluzby: indeksy (strony jednostek z UNIT_PAGES)
    'sluzby:start' => ['sluzby', null],
];

/** Dokąd prowadzą adresy stron nieimportowanych (null w PAGES) i niezmapowane przestrzenie nazw. */
const REDIRECT = [
    'sluzby' => 'sluzby',
    'wzory_pism' => 'wzory',
    'pytania' => 'pytania',
    '' => 'start',
];

/**
 * Błędne stare adresy z ruchem w Google Search Console (literówki w linkach z zewnątrz) → stare id, na które
 * mają prowadzić. Import zapisuje je jak zwykłe przekierowania.
 */
const TYPO_REDIRECTS = [
    'niezbednik/zwrot_srodkow_za_presluchanie' => 'niezbednik:zwrot_srodkow_za_przesluchanie',
    'pytania/czy-musze-se-stawic' => 'pytania:czy-musze-sie-stawic',
    'sluzby/komisariat_policji_wroclaw-grabiszynek' => 'sluzby:dolnoslaskie:komisariat_policji_wroclaw_grabiszynek',
    'sluzby/malopolskie/straz_miejskiej_w_olkuszu' => 'sluzby:malopolskie:straz_miejska_w_olkuszu',
];

/** Strony jednostek, których nie ma w mapie z audytu (sluzby_map), dopisane ręcznie; null = usunięta (decyzja 7). */
const UNIT_PAGES_EXTRA = [
    'sluzby:wielkopolskie:komenda_strazy_gminnej' => ['sm', 'Dopiewo'],
    'sluzby:dolnoslaskie:komisariat_policji_wroclaw_grabiszynek' => null,
];

/** cleanID z DokuWiki — implementacja w Wiki.php (używa jej też handler do starych adresów). */
function cleanId(string $id): string
{
    return \wiki\dokuCleanId($id);
}

/**
 * Konwerter jednej strony. Zbiera ostrzeżenia (rzeczy do ręcznej redakcji) i użyte media.
 * $resolveLink: stare id → URL w nowym serwisie (null = nieznana strona, zostaje sam tekst).
 */
final class Converter
{
    /** @var list<string> */
    public array $warnings = [];
    /** @var list<string> nazwy plików z przestrzeni media (basename) */
    public array $media = [];
    /** Pierwszy nagłówek h1 (DokuWiki ======), wycięty z treści. */
    public ?string $title = null;

    /**
     * @param callable(string):?string $resolveLink
     * @param ?callable(string):?string $resolveTitle stare id → tytuł (opis linku `[[id]]` / `[[id|]]`)
     */
    public function __construct(private $resolveLink, private string $namespace = '', private $resolveTitle = null) {}

    public function convert(string $doku): string
    {
        $doku = str_replace("\r\n", "\n", $doku);
        $out = [];
        $lines = explode("\n", $doku);
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];

            // <code>…</code> / <file>…</file> — w tej wiki to wzory pism
            if (preg_match('/^\s*<(code|file)\b[^>]*>(.*)$/', $line, $m)) {
                $tag = $m[1];
                $body = [];
                $rest = $m[2];
                $first = true;
                while (!str_contains($rest, "</$tag>") && $i + 1 < $n) {
                    if (!$first || trim($rest) !== '') $body[] = $rest; // puste linie = akapity pisma
                    $first = false;
                    $rest = $lines[++$i];
                }
                $before = strstr($rest, "</$tag>", true);
                if ($before !== '' && $before !== false) $body[] = $before;
                $out[] = '';
                $out[] = ':::wzor{title="Wzór pisma"}';
                foreach ($body as $bodyLine) $out[] = $this->escapeBlockStart(rtrim($bodyLine));
                $out[] = ':::';
                $out[] = '';
                continue;
            }

            // nagłówki: ====== h1 ====== … == h5 ==
            if (preg_match('/^\s*(={2,6})\s*(.+?)\s*={2,6}\s*$/', $line, $m)) {
                $level = 7 - strlen($m[1]);
                $text = trim($this->inline($m[2]));
                $plain = trim(preg_replace('/[*_`]/', '', $text));
                if ($level === 1 && $this->title === null) {
                    $this->title = $plain;
                    continue;
                }
                $out[] = '';
                $out[] = str_repeat('#', max(2, $level)) . ' ' . $plain;
                $out[] = '';
                continue;
            }

            // tabele
            if (preg_match('/^\s*[\^|]/', $line)) {
                $rows = [];
                while ($i < $n && preg_match('/^\s*[\^|]/', $lines[$i])) $rows[] = trim($lines[$i++]);
                $i--;
                $out[] = '';
                array_push($out, ...$this->table($rows));
                $out[] = '';
                continue;
            }

            // listy: dwie+ spacje, potem * albo -
            if (preg_match('/^( {2,}|\t+)([*-])\s?(.*)$/', $line, $m)) {
                $depth = max(0, intdiv(strlen(str_replace("\t", '  ', $m[1])), 2) - 1);
                $marker = $m[2] === '*' ? '-' : '1.';
                if (!$out || ($out[count($out) - 1] !== '' && !preg_match('/^\s*(-|1\.) /', $out[count($out) - 1])))
                    $out[] = '';
                $out[] = str_repeat('    ', $depth) . $marker . ' ' . $this->inline($m[3]);
                continue;
            }

            // tekst preformatowany (wcięcie 2+ spacje) → blok kodu
            if (preg_match('/^( {2,}|\t)\S/', $line)) {
                $block = [];
                while ($i < $n && preg_match('/^( {2,}|\t)\S/', $lines[$i])) $block[] = preg_replace('/^( {2}|\t)/', '', $lines[$i++]);
                $i--;
                array_push($out, '', '```', ...$block);
                array_push($out, '```', '');
                continue;
            }

            if (preg_match('/^\s*-{4,}\s*$/', $line)) {
                array_push($out, '', '---', '');
                continue;
            }

            if (preg_match('/^\s*>+\s?(.*)$/', $line, $m)) {
                $out[] = '> ' . $this->inline($m[1]);
                continue;
            }

            $out[] = $this->escapeBlockStart($this->inline($line));
        }

        $md = implode("\n", $out);
        $md = preg_replace("/\n{3,}/", "\n\n", $md);
        return trim($md) . "\n";
    }

    /** Linia zaczynająca się jak blok Markdown, którego w DokuWiki nie było (#, 1., :::) — escapowana. */
    private function escapeBlockStart(string $line): string
    {
        if (preg_match('/^(\s*)(#|:::|\+\s|=+\s*$)/', $line, $m))
            return $m[1] . '\\' . ltrim($line);
        return $line;
    }

    /** @param list<string> $rows */
    private function table(array $rows): array
    {
        $out = [];
        foreach ($rows as $index => $row) {
            $cells = preg_split('/(?<!\\\\)[\^|]/', trim($row, " \t"));
            array_shift($cells); // przed pierwszym separatorem
            if (end($cells) !== false && trim(end($cells)) === '') array_pop($cells);
            $cells = array_map(fn($cell) => trim(str_replace(':::', '', $this->inline(trim($cell)))), $cells);
            $out[] = '| ' . implode(' | ', array_map(fn($c) => str_replace('|', '\\|', $c), $cells)) . ' |';
            if ($index === 0)
                $out[] = '|' . str_repeat('---|', max(1, count($cells)));
        }
        return $out;
    }

    public function inline(string $text): string
    {
        $tokens = [];
        $protect = function (string $markdown) use (&$tokens): string {
            $tokens[] = $markdown;
            return "\u{E010}" . (count($tokens) - 1) . "\u{E011}";
        };

        // %%dosłownie%% i ''monospace''
        $text = preg_replace_callback('/%%(.*?)%%/', fn($m) => $protect($m[1]), $text);
        $text = preg_replace_callback("/''(.+?)''/", fn($m) => $protect('`' . $m[1] . '`'), $text);

        // media {{ :ns:plik.png?400 |opis}}
        $text = preg_replace_callback('/\{\{\s*([^}|]+?)\s*(?:\|([^}]*))?\}\}/', function ($m) use ($protect) {
            $target = preg_replace('/\?.*$/', '', trim($m[1]));
            $alt = trim($m[2] ?? '');
            if (preg_match('#^https?://#', $target)) {
                $this->warnings[] = "obrazek zewnętrzny pominięty: $target";
                return $alt;
            }
            $name = mediaName($target);
            if (!preg_match('/\.(png|jpe?g|webp|gif)$/', $name)) {
                $this->warnings[] = "plik (nie obrazek) pominięty: $target";
                return $alt;
            }
            $this->media[] = $name;
            return $protect('![' . str_replace(']', '', $alt) . "](media:$name)");
        }, $text);

        // linki [[cel|tekst]]
        $text = preg_replace_callback('/\[\[([^\]|]+?)(?:\|([^\]]*))?\]\]/', function ($m) use ($protect) {
            $target = trim($m[1]);
            $label = trim($m[2] ?? '');
            if (preg_match('#^(https?://|mailto:)#i', $target) || preg_match('/^[^:@\s]+@[^\s]+\.[a-z]+$/i', $target)) {
                $url = str_contains($target, '@') && !str_contains($target, ':') ? "mailto:$target" : $target;
                return $protect($label === '' ? "<$url>" : '[' . $this->inline($label) . "]($url)");
            }
            [$page, $anchor] = array_pad(explode('#', $target, 2), 2, '');
            $id = $page === '' ? '' : resolveRelative($page, $this->namespace);
            $url = $page === '' ? '' : ($this->resolveLink)($id);
            if ($page !== '' && $url === null) {
                $this->warnings[] = "link do nieznanej strony: $target";
                return $label !== '' ? $label : $page;
            }
            if ($anchor !== '') $url .= '#' . \wiki\slugify(str_replace('_', ' ', $anchor));
            if ($label === '') $label = ($this->resolveTitle ? ($this->resolveTitle)($id) : null) ?? (preg_replace('/^.*:/', '', $page) ?: $anchor);
            return $protect('[' . $this->inline($label) . "]($url)");
        }, $text);

        // przypisy ((…)) → w nawiasie
        $text = preg_replace('/\(\((.+?)\)\)/', ' ($1)', $text);
        // wymuszone łamanie linii
        $text = preg_replace('/\s*\\\\\\\\(\s|$)/', "\\\n", $text);
        // kursywa //…// (URL-e są już schowane w tokenach), podkreślenie __…__ (bez odpowiednika — zwykły tekst)
        $text = preg_replace('#(?<![:/])//(?=\S)(.+?)(?<=\S)//#', '*$1*', $text);
        $text = preg_replace('/__(?=\S)(.+?)(?<=\S)__/', '$1', $text);
        $text = preg_replace('/<del>(.*?)<\/del>/', '~~$1~~', $text);
        if (preg_match('/<\/?[a-z][^>]*>/i', preg_replace('/<(https?:|mailto:)[^>]*>/', '', $text)))
            $this->warnings[] = 'znacznik HTML w treści (zostanie wycięty): ' . mb_substr($text, 0, 80);

        return preg_replace_callback("/\u{E010}(\d+)\u{E011}/", fn($m) => $tokens[(int)$m[1]], $text);
    }
}

/** Id względem przestrzeni nazw strony (DokuWiki: bez ':' = ta sama przestrzeń, ':x' = od korzenia). */
function resolveRelative(string $page, string $namespace): string
{
    $page = trim($page);
    if (str_starts_with($page, ':')) return cleanId($page);
    if (str_contains($page, ':') || $namespace === '') return cleanId($page);
    return cleanId("$namespace:$page");
}

function mediaName(string $mediaId): string
{
    return \wiki\dokuMediaName($mediaId);
}

/**
 * Jednostki, których stare strony mają dużo więcej niż `hint` w konfiguracji — notatka importowana mimo `hint`.
 * Pozostałe `hint`-y powstały jako streszczenia tych stron, więc notatka byłaby powtórzeniem karty.
 */
const KEEP_NOTES = ['sm' => ['wrocław']];

/** Domyślny hint dopisywany przez tools/sm-parser.js jednostkom bez opisu doświadczeń. */
const DEFAULT_HINT_PREFIX = 'Masz doświadczenia we współpracy z tą jednostką?';

/**
 * Wolny tekst ze strony jednostki po odcięciu pól, które pochodzą z konfiguracji (adres, e-mail, kod, województwo).
 * Zwraca '' gdy nie zostało nic nowego: jednostka ma w konfiguracji własny `hint` (streszczenie tej strony, widoczne
 * na karcie; wyjątki w KEEP_NOTES) albo tekst tylko opisuje obsługiwany teren (lista jest na karcie).
 * @param list<string> $keys klucze konfiguracji tej jednostki
 */
function unitNote(string $doku, \SM $unit, string $type = 'sm', array $keys = []): string
{
    $lines = explode("\n", str_replace("\r\n", "\n", $doku));
    $address = array_map('mb_strtolower', $unit->address);
    $kept = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || preg_match('/^={2,6}.*={2,6}$/', $trimmed)) { $kept[] = ''; continue; }
        if (preg_match('/^(adres e-mail|e-mail|email|kod pocztowy|województwo|wojewodztwo)\s*:/iu', $trimmed)) continue;
        if (in_array(mb_strtolower($trimmed), $address, true)) continue;
        if (preg_match('/^(ul\.|al\.|pl\.|os\.)\s|^\d{2}-\d{3}\s/u', $trimmed)) continue; // adres w innej formie niż w konfiguracji
        if (preg_match('/^\[\[sluzby/', $trimmed)) continue; // nawigacja „wróć do listy”
        $kept[] = $line;
    }
    $note = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)));
    if ($note === '') return '';
    if (array_intersect($keys, KEEP_NOTES[$type] ?? [])) return $note;
    $hint = trim(strip_tags($unit->hint ?? ''));
    if ($hint !== '' && !str_starts_with($hint, DEFAULT_HINT_PREFIX)) return '';
    if (mb_strlen($note) < 40 || preg_match('/obsługiwan|obsługuje/u', $note) && mb_strlen($note) < 150) return '';
    return $note;
}

function similarity(string $a, string $b): float
{
    $a = mb_strtolower(preg_replace('/\s+/u', ' ', trim($a)));
    $b = mb_strtolower(preg_replace('/\s+/u', ' ', trim($b)));
    if ($a === $b) return 1.0;
    similar_text($a, $b, $percent);
    return $percent / 100;
}

<?PHP namespace letter;

require_once(__DIR__ . '/Vision.php'); // \vision\chat(), imgPart(), extractJson() (przez VisionSchema), visionCostUsd()
require_once(__DIR__ . '/../LetterMatcher.php'); // idFold()

// Rozpoznawanie pism z Policji / Straży Miejskiej (odpowiedzi na zgłoszenia): OCR + ekstrakcja identyfikatorów spraw
// przez multimodalny model OpenAI w jednym wywołaniu. Wzorzec jak vision\analyzeChunk(): jedna runda naprawy na nie-JSON
// i jedna na błędy walidacji, głośny błąd (\vision\VisionException) zamiast zgadywania.
//
// Model tylko CZYTA i klasyfikuje (outcome); status UD wyznacza deterministycznie LetterProposal.php. Numery spraw
// (RSOW/eRSOW/L.dz./UD/…) są dodatkowo sprawdzane regexami w transkrypcie – id, którego nie ma w tekście pisma, jest odrzucane.

const OUTCOMES = [
    'fined',               // ukarany mandatem / grzywną
    'instructed',          // pouczenie, środek oddziaływania wychowawczego (art. 41 KW)
    'no_offence',          // czyn nie zawiera znamion / nie popełniono wykroczenia
    'perpetrator_unknown', // niewykrycie sprawcy
    'dismissed_other',     // inna przyczyna odmowy: zmarł, ustanie karalności, przedawnienie…
    'in_progress',         // czynności w toku
    'closed_unspecified',  // zakończone bez podania wyniku
    'unknown',
];
const LETTER_KINDS = ['single', 'bulk', 'statistical', 'generic'];
const ID_KEYS = ['udNumber', 'rsow', 'ersow', 'ldz', 'caseMark', 'mandateNo'];

function letterPrompt(): string {
    $outcomes = implode('|', OUTCOMES);
    return <<<PROMPT
You are an OCR and information-extraction engine for Polish official letters (Policja, Straż Miejska) that reply to
a citizen's report of a parking/traffic offence. The attached images are the pages (or one photo showing several
letters). Read them carefully and answer with ONE raw JSON object, no prose, no markdown fences.

Shape:
{
  "transcript": "<full verbatim text of everything legible, all pages, keep line breaks as \\n; use [ZAMAZANE] for blacked-out/redacted parts and [?] for illegible characters>",
  "letter": {
    "kind": "single|bulk|statistical|generic",
    "authority": "<issuing unit, e.g. 'Komisariat Policji II w Krakowie'> or null",
    "date": "YYYY-MM-DD" or null
  },
  "cases": [
    {
      "ids": {
        "udNumber": "UD/<n>/<n> if printed, else null",
        "rsow": "number of the RSOW register entry, e.g. '101/26', else null",
        "ersow": "number of the eRSOW entry, else null",
        "ldz": "L.dz. value if filled in (ignore empty dotted lines), else null",
        "caseMark": "other case file mark like 'KP I-301/999/26', else null",
        "mandateNo": "ticket (mandat) series and number if printed, else null"
      },
      "plate": "registration plate exactly as printed (may contain a space) or null",
      "vehicleMake": "make/model or null",
      "date": "YYYY-MM-DD of the OFFENCE (not of the letter) or null; if the year is not printed use \"--MM-DD\"",
      "time": "HH:MM of the offence or null",
      "address": {"street": "street name without 'ul.' (and no house number) or null", "number": "house number or null", "city": "city or null"},
      "outcome": "$outcomes",
      "reason": "short Polish phrase with the stated reason/result or null",
      "articles": ["e.g. 'art. 97 kw'"],
      "confidence": 0.0
    }
  ]
}

Rules:
- kind: "single" = decision about one incident (several such letters photographed together still count as "single",
  with one case each); "bulk" = a reply listing many incidents/vehicles (e.g. to a freedom-of-information request);
  "statistical" = aggregate numbers or a list of incidents given only by date and street, with NO registration plate
  and NO case number on the items (a general statistic, not decisions on individual reports), return "cases": [];
  "generic" = a general statement without any identifiable incident, return "cases": [].
- One case per incident (vehicle + date). In a bulk list return one case for every listed item, copying the ids printed on that line.
- outcome: fined = punished with a ticket/fine (mandat karny, grzywna, "ukarany"); instructed = pouczenie / art. 41 KW;
  no_offence = "czyn nie zawiera znamion wykroczenia", "czynu nie popełniono", "nie popełnił wykroczenia";
  perpetrator_unknown = "niewykrycie sprawcy"; dismissed_other = any other reason for not filing a motion for punishment
  (e.g. the accused died, limitation period expired); in_progress = proceedings are ongoing / "w toku";
  closed_unspecified = proceedings finished but no result is stated; unknown = cannot tell.
- Never invent values. Use null when something is missing, redacted or unreadable. Do not guess a plate.
- Copy plates, numbers and dates exactly as printed; do not "fix" typos in the document.
- Write Polish letters (ą ć ę ł ń ó ś ź ż) as real UTF-8 characters in every string, never as \\uXXXX escape sequences.
- If a case date has no year and the letter date is known, still return "--MM-DD" (the server resolves the year).
PROMPT;
}

/** @return list<string> błędy kontraktu (pusta lista = OK) */
function validateLetter(mixed $p): array {
    $errs = [];
    if (!is_array($p)) return ['odpowiedź nie jest obiektem'];
    if (!isset($p['transcript']) || !is_string($p['transcript'])) $errs[] = 'brak transcript (tekst)';
    $l = $p['letter'] ?? null;
    if (!is_array($l)) {
        $errs[] = 'brak obiektu letter';
    } else {
        if (!in_array($l['kind'] ?? null, LETTER_KINDS, true)) $errs[] = 'letter.kind spoza listy';
        if (($l['date'] ?? null) !== null && !isIsoDate($l['date'])) $errs[] = 'letter.date nie jest YYYY-MM-DD';
    }
    if (!isset($p['cases']) || !is_array($p['cases'])) {
        $errs[] = 'brak tablicy cases';
        return $errs;
    }
    foreach ($p['cases'] as $i => $c) {
        if (!is_array($c)) { $errs[] = "cases[$i] nie jest obiektem"; continue; }
        if (!in_array($c['outcome'] ?? null, OUTCOMES, true)) $errs[] = "cases[$i].outcome spoza listy";
        $d = $c['date'] ?? null;
        if ($d !== null && !isIsoDate($d) && !preg_match('/^--\d{2}-\d{2}$/', (string)$d)) $errs[] = "cases[$i].date ma zły format";
        $t = $c['time'] ?? null;
        if ($t !== null && !preg_match('/^\d{1,2}:\d{2}$/', (string)$t)) $errs[] = "cases[$i].time ma zły format";
        if (isset($c['plate']) && !is_string($c['plate'])) $errs[] = "cases[$i].plate nie jest tekstem";
    }
    return $errs;
}

function isIsoDate(mixed $d): bool {
    if (!is_string($d) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Tablica w formie kluczowej: wielkie litery i cyfry bez spacji/myślników (znaki zapytania zostają – zamazane znaki). */
function plateKey(?string $plate): ?string {
    if ($plate === null) return null;
    $s = strtoupper(preg_replace('/[^A-Za-z0-9?*]/', '', $plate));
    return strlen($s) >= 3 ? $s : null;
}

/** Zestaw identyfikatorów wyciągnięty regexami z tekstu: ['rsow'=>[...], 'ersow'=>[...], ...] (znormalizowane wartości, bez duplikatów). */
function extractCaseIds(string $text): array {
    $found = array_fill_keys(ID_KEYS, []);
    if (preg_match_all('/\b(e)?RSOW\s*[-–—:]?\s*(\d{1,6})\s*\/\s*(\d{2,4})\b/iu', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $found[$x[1] !== '' ? 'ersow' : 'rsow'][] = (int)$x[2] . '/' . $x[3];
    }
    if (preg_match_all('/\bL\.?\s*dz\.?\s*:?\s*(\d[\w\/\-.]*)/iu', $text, $m)) {
        foreach ($m[1] as $v) $found['ldz'][] = rtrim($v, '.');
    }
    if (preg_match_all('/\bK[PW]\s?[IVX]*\s*[-–—]\s*(\d+)\s*\/\s*(\d+)\s*\/\s*(\d{2,4})\b/u', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $found['caseMark'][] = preg_replace('/\s+/', '', $x[0]);
    }
    if (preg_match_all('/\bUD\s*\/\s*(\d+)\s*\/\s*(\d+)\b/u', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $found['udNumber'][] = "UD/{$x[1]}/{$x[2]}";
    }
    if (preg_match_all('/mandat\w*\s+(?:karny\s+)?(?:kredytowany\s+)?(?:seria\s+)?((?-i:[A-Z]{1,2}))\s*(?:nr\s*)?(\d{6,8})\b/iu', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $found['mandateNo'][] = $x[1] . ' ' . $x[2];
    }
    return array_map(fn ($v) => array_values(array_unique($v)), $found);
}

/** Znormalizowana wartość id albo null, gdy ma zły format dla tego typu (model potrafi wstawić znak sprawy w pole udNumber albo zepsuty OCR „204”). */
function normalizeIdValue(string $key, string $v): ?string {
    $v = trim($v);
    return match ($key) {
        'rsow', 'ersow' => preg_match('/^(\d{1,6})\s*\/\s*(\d{2,4})$/', $v, $m) ? ((int)$m[1] . '/' . $m[2]) : null,
        'udNumber' => preg_match('/^UD\s*\/\s*(\d+)\s*\/\s*(\d+)$/i', $v, $m) ? "UD/{$m[1]}/{$m[2]}" : null,
        // „DJ nr 1234567”, „seria DJ 1234567” → „DJ 1234567”
        'mandateNo' => ($c = trim(preg_replace('/\s+/', ' ', preg_replace('/\b(?:nr|seria)\b\.?\s*/iu', '', $v)))) !== '' ? $c : null,
        default => $v !== '' ? $v : null,
    };
}

/**
 * Scala identyfikatory z modelu z tym, co jest w transkrypcie: id spoza tekstu odpada (halucynacja), a brakujące uzupełniamy,
 * gdy w całym piśmie występuje dokładnie jedna taka wartość (przy pismach zbiorczych model przypisuje id do pozycji).
 * @return array{0: array<string,?string>, 1: list<string>} [ids, ostrzeżenia]
 */
function reconcileIds(array $ids, array $textIds, string $transcript, int $caseCount = 1): array {
    $warnings = [];
    $foldedText = idFold($transcript);
    $out = [];
    foreach (ID_KEYS as $k) {
        $raw = $ids[$k] ?? null;
        $v = is_string($raw) && trim($raw) !== '' ? normalizeIdValue($k, $raw) : null;
        if ($v === null && is_string($raw) && trim($raw) !== '') $warnings[] = "id $k=" . trim($raw) . ' ma zły format – odrzucone';
        // numer mandatu w piśmie ma „nr” między serią a numerem („DJ nr 1234567”) – obecność sprawdzamy po samym numerze
        $needle = $k === 'mandateNo' && $v !== null ? preg_replace('/^[A-Za-z]+\s*/', '', $v) : $v;
        if ($v !== null && !str_contains($foldedText, idFold((string)$needle)) && !in_array($v, $textIds[$k], true)) {
            $warnings[] = "id $k=$v nie występuje w transkrypcie – odrzucone";
            $v = null;
        }
        // jedyny taki numer w piśmie przypisujemy tylko, gdy pismo ma jedną sprawę (przy kilku nie wiadomo, czyj to numer)
        if ($v === null && $caseCount === 1 && count($textIds[$k]) === 1) $v = $textIds[$k][0];
        $out[$k] = $v;
    }
    return [$out, $warnings];
}

/**
 * Rok dla daty „--MM-DD” z daty pisma: najpóźniejszy rok, dla którego wykroczenie nie jest po dacie pisma.
 * Bez daty pisma zostaje „--MM-DD” (dopasowanie ignoruje wtedy rok).
 */
function resolveYear(string $date, ?string $letterDate): string {
    if (!preg_match('/^--(\d{2})-(\d{2})$/', $date, $m)) return $date;
    if ($letterDate === null) return $date;
    $year = (int)substr($letterDate, 0, 4);
    $candidate = sprintf('%04d-%s-%s', $year, $m[1], $m[2]);
    if (!checkdate((int)$m[1], (int)$m[2], $year)) return $date;
    return $candidate > $letterDate ? sprintf('%04d-%s-%s', $year - 1, $m[1], $m[2]) : $candidate;
}

/** Normalizacja odpowiedzi modelu: tablice w formie kluczowej, rok z daty pisma, id spójne z transkryptem. @return array{0:array,1:list<string>} */
function normalizeLetter(array $p): array {
    $warnings = [];
    $transcript = (string)$p['transcript'];
    $textIds = extractCaseIds($transcript);
    $letterDate = $p['letter']['date'] ?? null;

    $cases = [];
    foreach ($p['cases'] as $c) {
        [$ids, $w] = reconcileIds(is_array($c['ids'] ?? null) ? $c['ids'] : [], $textIds, $transcript, count($p['cases']));
        array_push($warnings, ...$w);
        $addr = is_array($c['address'] ?? null) ? $c['address'] : [];
        $date = isset($c['date']) ? resolveYear((string)$c['date'], $letterDate) : null;
        $time = isset($c['time']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string)$c['time'], $tm) ? sprintf('%02d:%s', (int)$tm[1], $tm[2]) : null;
        $cases[] = [
            'ids' => $ids,
            'plate' => plateKey($c['plate'] ?? null),
            'vehicleMake' => nullIfBlank($c['vehicleMake'] ?? null),
            'date' => $date,
            'time' => $time,
            'address' => [
                'street' => nullIfBlank($addr['street'] ?? null),
                'number' => nullIfBlank($addr['number'] ?? null),
                'city' => nullIfBlank($addr['city'] ?? null),
            ],
            'outcome' => $c['outcome'],
            'reason' => nullIfBlank($c['reason'] ?? null),
            'articles' => array_values(array_filter(array_map('strval', is_array($c['articles'] ?? null) ? $c['articles'] : []))),
            'confidence' => is_numeric($c['confidence'] ?? null) ? max(0.0, min(1.0, (float)$c['confidence'])) : null,
        ];
    }
    $kind = $p['letter']['kind'];
    if (in_array($kind, ['statistical', 'generic'], true)) $cases = []; // jak w prompcie: nic do dopasowania

    return [[
        'transcript' => $transcript,
        'letter' => [
            'kind' => $kind,
            'authority' => nullIfBlank($p['letter']['authority'] ?? null),
            'date' => $letterDate,
        ],
        'cases' => $cases,
    ], $warnings];
}

/**
 * Model potrafi zwrócić polskie znaki jako dosłowne „\\u0143” (JSON podwójnie zakodowany – po json_decode zostaje w tekście
 * ukośnik i „u0143”, co aplikacja pokazywała jako „POZNA\\u0143SKA”). Zamieniamy takie sekwencje (także pary surogatów) na UTF-8
 * we wszystkich tekstach odpowiedzi.
 */
function decodeUnicodeEscapes(mixed $v): mixed {
    if (is_array($v)) return array_map(fn ($x) => decodeUnicodeEscapes($x), $v);
    if (!is_string($v) || !str_contains($v, '\\u')) return $v;
    return preg_replace_callback('/(?:\\\\u[0-9a-fA-F]{4})+/', fn ($m) => json_decode('"' . $m[0] . '"') ?? $m[0], $v);
}

function nullIfBlank(mixed $v): ?string {
    if (!is_string($v)) return null;
    $v = trim($v);
    return $v === '' ? null : $v;
}

/**
 * Punkt wejścia: strony (JPEG) → ['transcript','letter','cases'] + usage + warnings.
 * @param list<string> $jpegPages
 * @return array{transcript:string, letter:array, cases:list<array>, usage:array, warnings:list<string>}
 */
function analyzeLetter(array $jpegPages, ?string $model = null): array {
    $model ??= OPENAI_LETTER_MODEL;
    $usage = ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0];
    $warnings = [];

    $note = count($jpegPages) > 1 ? ' The ' . count($jpegPages) . ' images are consecutive pages of the same letter(s).' : '';
    $content = [['type' => 'text', 'text' => letterPrompt() . $note]];
    foreach ($jpegPages as $bytes) $content[] = \vision\imgPart($bytes, OPENAI_VISION_DETAIL);
    $history = [['role' => 'user', 'content' => $content]];

    $parse = function (string $raw): array {
        $r = \vision\extractJson($raw);
        if ($r['parsed'] === null) {
            throw new \vision\VisionException($r['truncated'] ? 'model uciął JSON' : 'model nie zwrócił JSON: ' . substr($raw, 0, 160));
        }
        return decodeUnicodeEscapes($r['parsed']);
    };

    $raw = \vision\chat($history, OPENAI_LETTER_MAX_TOKENS, $usage, 3, $model, 'low');
    try {
        $parsed = $parse($raw);
    } catch (\vision\VisionException $e) {
        $warnings[] = 'naprawa: model nie zwrócił JSON (' . $e->getMessage() . ')';
        $history[] = ['role' => 'assistant', 'content' => $raw];
        $history[] = ['role' => 'user', 'content' => 'That was not valid JSON. Repeat the answer as raw JSON only: no prose, no markdown fences, no commentary.'];
        $raw = \vision\chat($history, OPENAI_LETTER_MAX_TOKENS, $usage, 3, $model, 'low');
        $parsed = $parse($raw);
    }

    $errs = validateLetter($parsed);
    if ($errs) {
        $warnings[] = 'naprawa walidacji: ' . implode('; ', $errs);
        $history[] = ['role' => 'assistant', 'content' => $raw];
        $history[] = ['role' => 'user', 'content' => 'Your JSON has errors: ' . implode('; ', $errs) . '. Return the FULL corrected JSON object again. JSON only.'];
        $raw = \vision\chat($history, OPENAI_LETTER_MAX_TOKENS, $usage, 3, $model, 'low');
        $parsed = $parse($raw);
        $errs = validateLetter($parsed);
        if ($errs) throw new \vision\VisionException('walidacja: ' . implode('; ', $errs));
    }

    [$result, $w] = normalizeLetter($parsed);
    array_push($warnings, ...$w);
    $result['usage'] = $usage + ['cost_usd' => \vision\visionCostUsd($usage, $model)];
    $result['warnings'] = $warnings;
    return $result;
}

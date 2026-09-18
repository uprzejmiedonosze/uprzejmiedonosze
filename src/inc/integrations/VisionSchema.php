<?PHP namespace vision;

// Faza ANALIZA-wizja: stałe, prompt, walidacja — bez sieci, bez sekretów.
// Port src/lib/vision.ts + extractJson z src/api/spark.ts (repo uprzejmiedonosze-pro).
// LLM tylko rozpoznaje (role, auta, tablice, markery); grupowanie, walidacja, split, kategorie
// i kolejność są deterministyczne i zostają po stronie appki (compose.ts) — patrz komentarz przy
// CATEGORY_RULES niżej. Źródło prawdy dla kontraktu: ten plik + src/lib/vision.ts w apce, muszą
// zostać zsynchronizowane ręcznie (brak wspólnego repo) — każda zmiana promptu/markerów/walidacji
// tu MUSI być powtórzona tam (i odwrotnie), i musi podbić VISION_SCHEMA.

const VISION_SCHEMA = 5; // = SCHEMA w src/lib/vision.ts (appka); podbij przy zmianie kontraktu

const ROLES = ['context', 'car', 'third', 'unusable'];

const MARKERS = [
    'bus_stop_sign', 'bus_bay', 'public_transport_access', 'zebra_crossing',
    'intersection', 'tram_crossing', 'island_15m', 'sidewalk_parking',
    'narrow_passage', 'deep_sidewalk', 'heavy_vehicle', 'b36_sign',
    'b35_sign', 'd18_sign', 't30_plate', 'road_markings',
    'vertical_sign_mismatch', 'bike_lane', 'greenery', 'disabled_bay',
    'residential_zone',
];

// CATEGORY_RULES/ruleCategory z src/lib/vision.ts (appka) CELOWO NIE są tu portowane: to czyste
// post-processing nad markers[], bez LLM i bez sekretu, i tak zostaje w compose.ts po stronie
// klienta. Backend zwraca markers[] + suggested_category (podpowiedź modelu), appka liczy
// categoryId dokładnie jak dziś.

function visionPrompt(): string {
    return 'You analyze N photos of one parked car (same report, chronological order). '
        . 'Return a JSON object {"photos":[...]}, one entry per photo in input order, each:'."\n"
        . '{"photo_index":i, "role":"context|car|third|unusable", "quality":0..1,'."\n"
        . ' "car":{"present":bool, "bbox":[x1,y1,x2,y2]|null, "desc":str|null},'."\n"
        . ' "plate":{"readable":bool, "text":str|null, "bbox":[x1,y1,x2,y2]|null},'."\n"
        . ' "markers":[...], "suggested_category":int, "category_confidence":0..1}'."\n"
        . 'Roles: context = wide shot showing the violation setting (sign, crossing, stop, markings); '
        . 'car = vehicle dominates the frame, plate potentially readable; '
        . 'third = supplementary (second angle, sign close-up, extra evidence); '
        . 'unusable = blurred/dark/no car. bboxes in 0..1000 relative coords of the whole image. '
        . 'The plate bbox must tightly enclose the plate characters themselves, '
        . 'not the bumper, grille or road around it. '
        . 'markers only from: ' . implode(',', MARKERS) . '. '
        . 'suggested_category: best matching Polish parking-violation category id 0..26 '
        . '(2 bus stop, 3 intersection, 5 crosswalk, 13 disabled bay, 14 B-36 sign, 26 sidewalk). '
        . 'Polish plates like ZS1234A. JSON only, no prose.';
}

// Tablica w formie kluczowej (bez spacji, jak norm_plate): ^[A-Z0-9]{4,8}$ + litera i cyfra.
function normPlateKey(?string $t): ?string {
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $t ?? ''));
    if (!preg_match('/^[A-Z0-9]{4,8}$/', $s)) return null;
    if (!preg_match('/[A-Z]/', $s) || !preg_match('/[0-9]/', $s)) return null;
    return $s;
}

function validBox($b): bool {
    if (!is_array($b) || count($b) !== 4) return false;
    foreach ($b as $v) {
        if (!is_numeric($v) || $v < 0 || $v > 1000) return false;
    }
    return true;
}

function plateInsideCar(array $carBox, array $plateBox): bool {
    $mx = ($carBox[2] - $carBox[0]) * 0.05;
    $my = ($carBox[3] - $carBox[1]) * 0.05;
    return $plateBox[0] >= $carBox[0] - $mx && $plateBox[1] >= $carBox[1] - $my
        && $plateBox[2] <= $carBox[2] + $mx && $plateBox[3] <= $carBox[3] + $my;
}

// Faza 5: bramki walidacji (bez tokenów). Zwraca listę błędów; pusta = OK.
function validateVisionPhoto($p, int $idx): array {
    $errs = [];
    $p = is_array($p) ? $p : [];
    if (($p['photo_index'] ?? null) !== $idx) $errs[] = 'zly photo_index';
    $role = $p['role'] ?? null;
    if (!in_array($role, ROLES, true)) $errs[] = "zly role=" . ($role ?? '');
    $q = $p['quality'] ?? null;
    if (!is_numeric($q) || $q < 0 || $q > 1) $errs[] = 'zly quality';
    foreach (['car', 'plate'] as $key) {
        $b = $p[$key]['bbox'] ?? null;
        if ($b !== null && !validBox($b)) $errs[] = "zly bbox $key";
    }
    $mk = $p['markers'] ?? [];
    foreach ((is_array($mk) ? $mk : []) as $m) {
        if (!in_array($m, MARKERS, true)) { $errs[] = 'nieznany marker'; break; }
    }
    $car = $p['car'] ?? [];
    $plate = $p['plate'] ?? [];
    if ($plate['readable'] ?? false) {
        if (normPlateKey($plate['text'] ?? null) === null) $errs[] = 'nieczytelny format tablicy: ' . ($plate['text'] ?? '');
        $cb = $car['bbox'] ?? null;
        $pb = $plate['bbox'] ?? null;
        if (($car['present'] ?? false) && validBox($cb) && validBox($pb) && !plateInsideCar($cb, $pb)) {
            $errs[] = 'tablica poza autem';
        }
    }
    return $errs;
}

// Normalizacja po walidacji: klucz tablicy bez spacji.
function normalizeVisionPhoto(array &$p): void {
    if ($p['plate']['readable'] ?? false) {
        $t = normPlateKey($p['plate']['text'] ?? null);
        $p['plate']['text'] = $t;
        if ($t === null) $p['plate']['readable'] = false;
    }
}

// Wyciąga pierwszy KOMPLETNY obiekt {...} (zliczanie nawiasów, z obsługą stringów).
// Model reasoning potrafi dokleić tekst przed/po JSON albo go uciąć – to je rozróżnia.
// Port src/api/spark.ts:extractJson.
function extractJson(string $text): array {
    $clean = trim($text);
    if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $clean, $m)) {
        $clean = trim($m[1]);
    }

    $parsed = json_decode($clean, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return ['parsed' => $parsed, 'truncated' => false];
    }

    $start = strpos($clean, '{');
    if ($start === false) return ['parsed' => null, 'truncated' => false];

    $depth = 0;
    $inStr = null;
    $esc = false;
    $len = strlen($clean);
    for ($i = $start; $i < $len; $i++) {
        $c = $clean[$i];
        if ($inStr !== null) {
            if ($esc) $esc = false;
            elseif ($c === '\\') $esc = true;
            elseif ($c === $inStr) $inStr = null;
            continue;
        }
        if ($c === '"' || $c === "'") $inStr = $c;
        elseif ($c === '{') $depth++;
        elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                $obj = json_decode(substr($clean, $start, $i - $start + 1), true);
                return json_last_error() === JSON_ERROR_NONE
                    ? ['parsed' => $obj, 'truncated' => false]
                    : ['parsed' => null, 'truncated' => false];
            }
        }
    }
    return ['parsed' => null, 'truncated' => true]; // klamra otwarta, nigdy nie domknięta = ucięta odpowiedź
}

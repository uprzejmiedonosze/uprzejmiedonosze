<?PHP namespace vision;

// Faza ANALIZA-wizja: stałe, prompt, walidacja — bez sieci, bez sekretów.
// Port src/lib/vision.ts + extractJson z src/api/spark.ts (repo uprzejmiedonosze-pro).
// LLM tylko rozpoznaje (role, auta, tablice, markery); grupowanie, walidacja, split, kategorie
// i kolejność są deterministyczne i zostają po stronie appki (compose.ts) — patrz komentarz przy
// CATEGORY_RULES niżej. Źródło prawdy dla kontraktu: ten plik + src/lib/vision.ts w apce, muszą
// zostać zsynchronizowane ręcznie (brak wspólnego repo) — każda zmiana promptu/markerów/walidacji
// tu MUSI być powtórzona tam (i odwrotnie), i musi podbić VISION_SCHEMA.

const VISION_SCHEMA = 8; // = SCHEMA w src/lib/vision.ts (appka); podbij przy zmianie kontraktu
// SCHEMA 8: ALPR na każdym zdjęciu poza 'unusable' + pole alpr_all (wszystkie odczyty) — appka
// przydziela role context/car/third deterministycznie, patrz alprDetections() w Vision.php.

// Minimalny score PlateRecognizer (0..1), poniżej którego wynik ALPR jest ignorowany
// (zostaje odczyt modelu) — patrz applyAlprPlate() w Vision.php. Bez progu odczyt ALPR
// o niskiej pewności nadpisywałby poprawny odczyt modelu i tak dostawał plate_verified.
const ALPR_MIN_SCORE = 0.3;

const ROLES = ['context', 'car', 'third', 'unusable'];

// SCHEMA 7: usunięto suggested_category/category_confidence (model już nie zgaduje kategorii —
// to wciąż liczy appka deterministycznie z markers[], patrz CATEGORY_RULES w vision.ts) oraz
// markery numerowanych znaków drogowych (bus_stop_sign, b36_sign, b35_sign, d18_sign, t30_plate,
// vertical_sign_mismatch) — model już ich nie szuka, zostają tylko fizyczne cechy sceny.
const MARKERS = [
    'bus_bay', 'public_transport_access', 'zebra_crossing',
    'intersection', 'tram_crossing', 'island_15m', 'sidewalk_parking',
    'narrow_passage', 'deep_sidewalk', 'heavy_vehicle',
    'road_markings', 'bike_lane', 'greenery', 'disabled_bay',
    'residential_zone',
];

// CATEGORY_RULES/ruleCategory z src/lib/vision.ts (appka) CELOWO NIE są tu portowane: to czyste
// post-processing nad markers[], bez LLM i bez sekretu, i tak zostaje w compose.ts po stronie
// klienta. Backend zwraca TYLKO markers[] (model nie zgaduje kategorii — SCHEMA 7 usunęła
// suggested_category/category_confidence z kontraktu), appka liczy categoryId dokładnie jak dziś.

// $dims: photo_index (int, 0-based w tym chunku) => ['w'=>float,'h'=>float], wymiary PIKSELOWE
// wysłanej miniatury (nie 0..1000!). Model ma zwracać bboxy w tych realnych pikselach — testy
// batchowe (uprzejmiedonosze-pro/tests/eval, wariant promptu "v2-pixels") pokazały, że to daje
// wyraźnie lepsze IoU niż każenie modelowi samemu normalizować do 0..1000. Konwersja z powrotem
// do 0..1000 dzieje się zaraz po sparsowaniu odpowiedzi, patrz pixelBoxToVisionSpace() niżej i
// applyPixelBoxes() w Vision.php — dalej w pipeline (walidacja, appka) nic o pikselach nie wie.
function visionPrompt(array $dims): string {
    $dimsStr = implode('; ', array_map(
        fn ($idx, $d) => "photo_index $idx: {$d['w']}x{$d['h']}px",
        array_keys($dims),
        $dims,
    ));
    return 'You analyze N photos from one report (same walk/session, chronological order), '
        . 'documenting a parking violation. USUALLY all photos show the SAME parked car from '
        . 'different angles, but the set OCCASIONALLY contains photos of TWO OR MORE DIFFERENT '
        . 'parked cars (e.g. two separate violations photographed back-to-back, or another car '
        . 'visible in the background of one shot and close-up in another). Judge each photo on '
        . 'its own merits: a clear, sharp photo of a car with a license plate that does NOT match '
        . 'other photos in this set is still a valid "car" photo for a (possibly different) '
        . 'vehicle — do NOT mark it "unusable" NOR downgrade it to "context" just because its '
        . 'plate differs from other photos, or because you already assigned "car" to a different '
        . 'vehicle earlier in this set. Each distinct vehicle with a legible plate gets its OWN '
        . '"car" photo — assigning "car" once does not use up that role for the rest of the set. '
        . "Image pixel dimensions (width x height): $dimsStr. "
        . 'Return a JSON object {"photos":[...]}, one entry per photo in input order, each:'."\n"
        . '{"photo_index":i, "role":"context|car|third|unusable", "quality":0..1,'."\n"
        . ' "car":{"present":bool, "bbox":[x1,y1,x2,y2]|null, "desc":str|null},'."\n"
        . ' "plate":{"readable":bool, "text":str|null, "bbox":[x1,y1,x2,y2]|null},'."\n"
        . ' "markers":[...]}'."\n"
        . 'Roles — assign THOUGHTFULLY, do not default every clear car photo to "car": '
        . 'context = wide/establishing shot showing the violation SETTING (traffic signs, road '
        . 'markings, crossing, stop line, wide street view) — the plate does not need to be visible '
        . 'or legible here, and the car may be smaller in the frame or off to one side; '
        . 'car = the BEST close, sharp shot where ONE specific vehicle is the DOMINANT, primary '
        . 'subject (centered / closest / the vehicle the photo was clearly taken OF), its license '
        . 'plate legible — pick only ONE such photo per distinct vehicle in the set (the clearest '
        . 'one), even if several photos show that same car up close; a vehicle with a plate unrelated '
        . 'to other photos in the set is still a valid separate "car" photo, BUT ONLY IF some photo '
        . 'in the set treats IT as the dominant subject (its own close-up) — a second car that '
        . 'merely happens to also have a readable plate while sitting next to/behind the real subject '
        . 'in every photo (e.g. a neighboring parked car, not itself part of the violation) is NOT a '
        . 'second "car" photo, even though its plate is legible; '
        . 'third = any OTHER photo of a vehicle already covered by a "car" photo (extra angle, '
        . 'plate less clear, redundant close-up) — use this instead of "car" when you already '
        . 'picked a better shot of the same vehicle; '
        . 'unusable = blurred/dark/no vehicle clearly visible at all — never "unusable" merely '
        . 'because it shows a different vehicle than other photos. '
        . 'Typical set for ONE vehicle: exactly one "context" + exactly one "car" (+ optionally '
        . '"third" for any remaining photos of it). For TWO vehicles: one (possibly shared/reused) '
        . '"context" + one "car" per vehicle. Do NOT mark every photo that happens to contain a '
        . 'clear car as "car" — only its single best identifying shot. '
        . 'BBOX FORMAT: bbox is [x1,y1,x2,y2] in ACTUAL PIXELS of that specific photo (use the pixel '
        . 'dimensions given above for that photo_index) — x1=LEFT, y1=TOP, x2=RIGHT, y2=BOTTOM, '
        . 'top-left corner first, bottom-right corner second. Do NOT normalize to 0..1000 or 0..1 — '
        . 'use the real pixel coordinates of the image as given. '
        . 'The plate bbox must tightly enclose the plate characters themselves, '
        . 'not the bumper, grille or road around it. '
        . 'markers only from: ' . implode(',', MARKERS) . '. '
        . 'Polish plates like ZS1234A. JSON only, no prose.';
}

// Konwersja bbox modelu (piksele rzeczywiste zdjęcia $w x $h) -> 0..1000, kontrakt reszty
// pipeline'u (validBox/validateVisionPhoto niżej, appka). null -> null. Zniekształcone
// wejście nie-null (nie tablica, zła liczba elementów, element nie-numeryczny, zerowe
// wymiary) jest zwracane BEZ ZMIAN, żeby walidacja (validBox -> "zly bbox") je wyłapała
// i model dostał szansę naprawy — konwersja do null by je po cichu połykała (null bbox
// jest dozwolony), a dzielenie surowych wartości rzucałoby TypeError zamiast rundy naprawy.
//
// Współrzędne PO konwersji są przycinane do 0..1000 (nie odrzucane) — zaobserwowane w praktyce
// (lokalny CLI, R005): przy dużym, dominującym obiekcie blisko krawędzi model potrafi oddać
// współrzędną tuż za granicą zdjęcia (np. y2=1320px na obrazie 1205px wysokości), i to nie zawsze
// naprawia się nawet po rundzie naprawy w analyzeChunk() (model powtarza to samo przekroczenie).
// To nie błąd formatu tylko kilkuprocentowa niedokładność — przycięcie do krawędzi jest poprawną
// interpretacją, nie zgadywaniem; nie maskuje realnie zepsutego wejścia (te łapie is_numeric/
// count($box)!==4 wyżej, zanim dojdzie do przycinania).
function pixelBoxToVisionSpace($box, float $w, float $h): mixed {
    if ($box === null) return null;
    if (!is_array($box) || count($box) !== 4 || $w <= 0 || $h <= 0) return $box;
    foreach ($box as $v) {
        if (!is_numeric($v)) return $box;
    }
    $clamp = fn (float $v) => (int)max(0, min(1000, round($v)));
    return [
        $clamp($box[0] / $w * 1000),
        $clamp($box[1] / $h * 1000),
        $clamp($box[2] / $w * 1000),
        $clamp($box[3] / $h * 1000),
    ];
}

// Jak pixelBoxToVisionSpace, ale wejście w kształcie PlateRecognizer (xmin/ymin/xmax/ymax),
// używane przez applyAlprPlate() w Vision.php.
function alprBoxToVisionSpace(array $box, float $w, float $h): ?array {
    if ($w <= 0 || $h <= 0) return null;
    return pixelBoxToVisionSpace([$box['xmin'] ?? 0, $box['ymin'] ?? 0, $box['xmax'] ?? 0, $box['ymax'] ?? 0], $w, $h);
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
    // Nieznane markery NIE są tu błędem walidacji — patrz normalizeVisionPhoto() niżej, które je
    // po prostu odfiltrowuje. To był najczęstszy powód porażki całego kandydata mimo poprawnej
    // reszty odpowiedzi (rola/tablica/bbox) — model halucynuje realne, ale spoza listy nazwy
    // (np. "roundabout_sign", "pedestrian_crossing_sign" dla czegoś, co nie ma odpowiednika
    // w MARKERS) częściej niż się myli w czymkolwiek innym.
    $car = $p['car'] ?? [];
    $plate = $p['plate'] ?? [];
    if ($plate['readable'] ?? false) {
        // Zły format (np. same litery bez cyfry) NIE jest tu błędem walidacji — dawniej wywalał
        // całego kandydata (po nieudanej naprawie), zanim applyAlprPlate() (Vision.php) dostał
        // szansę poprawić odczyt z ALPR. Realny przypadek: model konsekwentnie czytał "ZOJKBRO"
        // (same litery) na polskiej tablicy, gdzie font nie odróżnia 0 od O; PlateRecognizer
        // poprawnie odczytał "Z0JKBRO" (96.9% pewności) — ale nigdy nie dostał szansy, bo request
        // padał wcześniej. normalizeVisionPhoto() niżej i tak łagodnie zejdzie do readable:false
        // przy złym formacie — to wystarcza, dalszy pipeline (w tym ALPR dla role==='car') robi
        // swoje bez przerywania całego kandydata.
        $cb = $car['bbox'] ?? null;
        $pb = $plate['bbox'] ?? null;
        if (($car['present'] ?? false) && validBox($cb) && validBox($pb) && !plateInsideCar($cb, $pb)) {
            $errs[] = 'tablica poza autem';
        }
    }
    return $errs;
}

// Normalizacja po walidacji: klucz tablicy bez spacji, odfiltrowanie nieznanych markerów.
function normalizeVisionPhoto(array &$p): void {
    if ($p['plate']['readable'] ?? false) {
        $t = normPlateKey($p['plate']['text'] ?? null);
        $p['plate']['text'] = $t;
        if ($t === null) $p['plate']['readable'] = false;
    }
    // Ciche odrzucenie zamiast błędu (patrz komentarz w validateVisionPhoto()) — trim() łapie
    // też warianty z białymi znakami (obserwowane w praktyce: " sidewalk_parking ").
    $mk = $p['markers'] ?? [];
    $p['markers'] = array_values(array_filter(
        array_map(fn ($m) => is_string($m) ? trim($m) : $m, is_array($mk) ? $mk : []),
        fn ($m) => is_string($m) && in_array($m, MARKERS, true),
    ));
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

<?PHP namespace vision;

require_once(__DIR__ . '/VisionSchema.php');
require_once(__DIR__ . '/../data.php'); // $MODEL_PRICING (koszt), jak ApiAiHandler.php
require_once(__DIR__ . '/plateRecognizer.php'); // \alpr\get_platerecognizer() — tablica/bbox auta, patrz applyAlprPlate()

// Faza 4 + 6: batch wizyjny na kandydata + tablica/bbox auta z ALPR. Port src/api/vision.ts
// (repo uprzejmiedonosze-pro), teraz po stronie serwera (klucz OpenAI nie może żyć w bundlu
// appki, i tak łatwiej chronić quotę per użytkownik). Retry sieciowy (3 próby, backoff 2s;
// tylko rate-limit i HTTP 5xx), fallback paczek po 3 przy dużym kandydacie, jedna runda naprawy
// na nie-JSON i jedna na błędy walidacji. Głośny błąd (VisionException) zamiast zgadywania.
//
// Tablicę/bbox auta czytał dawniej sam LLM (retry-crop nieczytelnej tablicy + druga opinia na
// weryfikację bbox) — batchowa ewaluacja (uprzejmiedonosze-pro/tests/eval, wariant pipeline'u
// "hybrid-pr") pokazała, że wyspecjalizowany ALPR (PlateRecognizer, już używany w klasycznym
// uploadzie zgłoszeń — src/inc/integrations/plateRecognizer.php) jest zauważalnie dokładniejszy
// (IoU tablicy 0.9+ vs 0.5-0.6 samego LLM) i tańszy (bez dodatkowych wywołań OpenAI). Stosowany
// TYLKO dla zdjęć z rolą 'car' (nie 'third') — patrz applyAlprPlate().

class VisionException extends \RuntimeException {}

// Sygnalizuje wyczerpane retry na rate-limit/5xx/transport (odpowiednik NetworkError w
// src/api/vision.ts appki) — TYLKO ten typ wyzwala fallback na paczki po 3 w analyzeCandidate();
// błędy parsowania/walidacji (VisionException zwykłe) propagują się od razu, jak w JS.
final class VisionNetworkException extends VisionException {}

// Błąd żądania (zły body) z gotowym statusem HTTP dla warstwy REST — odróżnia "klient przysłał
// śmieci" (4xx) od "model/upstream padł" (VisionException -> 502 w rest/index.php).
final class VisionRequestException extends \RuntimeException {
    public function __construct(string $message, public readonly int $httpStatus) {
        parent::__construct($message);
    }
}

/**
 * Dekoduje i waliduje body REST-owego POST /api/rest/vision/candidate (parametr `photos`):
 * kontrakt, limity rozmiaru, mime. Normalizuje PNG->JPEG (jak saveImgAndThumb w API.php), więc
 * dalszy pipeline (imgPart) ma jeden format. Zwraca list<array{photoId,photo_index,bytes}>.
 * @throws VisionRequestException z gotowym statusem HTTP (400/415)
 */
function decodeCandidatePhotos($photosParam): array {
    if (!is_array($photosParam) || count($photosParam) === 0) {
        throw new VisionRequestException('Brak zdjęć.', 400);
    }
    if (count($photosParam) > VISION_MAX_PHOTOS) {
        throw new VisionRequestException('Zbyt wiele zdjęć (max ' . VISION_MAX_PHOTOS . ').', 400);
    }

    $items = [];
    $seenIndex = [];
    $totalBytes = 0;
    foreach ($photosParam as $p) {
        $photoId = is_array($p) ? ($p['photoId'] ?? null) : null;
        $photoIndex = is_array($p) ? ($p['photo_index'] ?? null) : null;
        $image = is_array($p) ? ($p['image'] ?? null) : null;

        if (!is_string($photoId) || $photoId === '') {
            throw new VisionRequestException('Brak photoId dla jednego ze zdjęć.', 400);
        }
        if (!is_int($photoIndex)) {
            throw new VisionRequestException("Zły photo_index dla $photoId.", 400);
        }
        if (isset($seenIndex[$photoIndex])) {
            throw new VisionRequestException("Zduplikowany photo_index $photoIndex.", 400);
        }
        $seenIndex[$photoIndex] = true;

        if (!is_string($image) || !preg_match('#^data:image/(jpeg|jpg|png);base64,#i', $image, $m)) {
            throw new VisionRequestException("Złe zdjęcie $photoId (oczekiwano data:image/jpeg|png;base64,...).", 400);
        }
        $bytes = base64_decode(substr($image, strlen($m[0])), true);
        if ($bytes === false || $bytes === '') {
            throw new VisionRequestException("Nieprawidłowe base64 dla $photoId.", 400);
        }
        if (strlen($bytes) > VISION_MAX_PHOTO_BYTES) {
            throw new VisionRequestException("Zdjęcie $photoId zbyt duże (max " . intdiv(VISION_MAX_PHOTO_BYTES, 1000) . "kB).", 400);
        }
        $totalBytes += strlen($bytes);
        if ($totalBytes > VISION_MAX_TOTAL_BYTES) {
            throw new VisionRequestException('Zdjęcia razem zbyt duże (max ' . intdiv(VISION_MAX_TOTAL_BYTES, 1000000) . 'MB).', 400);
        }

        $info = @getimagesizefromstring($bytes);
        if (!$info) {
            throw new VisionRequestException("Przesłany plik $photoId nie jest obrazkiem.", 400);
        }
        $mime = $info['mime'] ?? '';
        if ($mime === 'image/png') {
            $bytes = normalizePngToJpeg($bytes);
            if ($bytes === null) throw new VisionRequestException("Nie można otworzyć pliku PNG $photoId.", 400);
        } elseif ($mime !== 'image/jpeg') {
            throw new VisionRequestException("Nieobsługiwany format obrazka $photoId: $mime.", 415);
        }

        $items[] = ['photoId' => $photoId, 'photo_index' => $photoIndex, 'bytes' => $bytes];
    }

    // 0..n-1 ciągłe — kontrakt z modelem tego wymaga (patrz analyzeChunk).
    $indices = array_map(fn ($i) => $i['photo_index'], $items);
    sort($indices);
    if ($indices !== range(0, count($items) - 1)) {
        throw new VisionRequestException('photo_index musi być ciągiem 0..n-1.', 400);
    }

    usort($items, fn ($a, $b) => $a['photo_index'] <=> $b['photo_index']);
    return $items;
}

// PNG -> JPEG na białym tle, tak jak saveImgAndThumb (API.php) dla uploadu zgłoszenia.
function normalizePngToJpeg(string $pngBytes): ?string {
    $src = @imagecreatefromstring($pngBytes);
    if ($src === false) return null;
    $img = imagecreatetruecolor(imagesx($src), imagesy($src));
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imagecopy($img, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
    imagedestroy($src);
    ob_start();
    imagejpeg($img, null, 85);
    $jpeg = ob_get_clean();
    imagedestroy($img);
    return $jpeg ?: null;
}

// Test seam: nadpisz realnego klienta OpenAI\Testing\ClientFake-em. Analogiczne do
// ReportMcpTools::setVehicleInfoFetcher, ale dla całego ClientContract na raz.
final class VisionClient {
    private static ?object $override = null;

    public static function set(?object $client): void {
        self::$override = $client;
    }

    public static function get(): object {
        if (self::$override !== null) return self::$override;
        return \OpenAI::factory()
            ->withApiKey(OPENAI_API_KEY)
            ->withProject(OPENAI_PROJECT)
            ->withHttpClient(new \GuzzleHttp\Client(['timeout' => OPENAI_VISION_TIMEOUT]))
            ->make();
    }
}

// Test seam analogiczny do VisionClient, dla \alpr\get_platerecognizer() — patrz applyAlprPlate().
final class PlateRecognizerClient {
    /** @var (callable(string): array)|null */
    private static $override = null;

    public static function set(?callable $fn): void {
        self::$override = $fn;
    }

    public static function call(string $bytes): array {
        if (self::$override !== null) return (self::$override)($bytes);
        return \alpr\get_platerecognizer($bytes);
    }
}

/**
 * Jedno wywołanie chat/completions z retry na rate-limit/5xx (3 próby, backoff 2s*attempt).
 * Sumuje tokeny do $usage (referencja: 'calls','prompt_tokens','completion_tokens').
 * Zwraca surową treść odpowiedzi (string) albo rzuca VisionException.
 */
function chat(array $messages, int $maxTokens, array &$usage, int $tries = 3): string {
    $lastErr = null;
    // Rodzina "reasoning" (gpt-5*, o1*, o3*, o4*) ma inny kontrakt niż klasyczne modele czatu
    // (gpt-4o-mini itd.), zweryfikowane bezpośrednio na API OpenAI:
    // - max_completion_tokens zamiast max_tokens (max_tokens -> HTTP 400 "Unsupported parameter")
    // - temperature tylko domyślna (1); jawne 0 -> HTTP 400 "Unsupported value"
    // - reasoning_effort:'minimal' ogranicza niewidoczne "myślenie" (completion_tokens_details.
    //   reasoning_tokens) — bez tego przy niskim maxTokens model potrafi zużyć CAŁY budżet na
    //   reasoning i zwrócić puste content (finish_reason=length, content=""). 'low' zamiast
    //   'minimal': cross-photo spójność ról (dokładnie jedno "car" na pojazd) wymaga porównania
    //   wszystkich zdjęć w grupie ze sobą, budżet (8000) ma na to zapas.
    $isReasoningModel = (bool)preg_match('/^(gpt-5|o1|o3|o4)/', OPENAI_VISION_MODEL);
    $params = [
        'model' => OPENAI_VISION_MODEL,
        'response_format' => ['type' => 'json_object'],
        'messages' => $messages,
    ];
    if ($isReasoningModel) {
        $params['max_completion_tokens'] = $maxTokens;
        $params['reasoning_effort'] = 'low';
    } else {
        $params['temperature'] = 0;
        $params['max_tokens'] = $maxTokens;
    }
    for ($attempt = 0; $attempt < $tries; $attempt++) {
        try {
            $resp = VisionClient::get()->chat()->create($params);
            $usage['calls'] = ($usage['calls'] ?? 0) + 1;
            $usage['prompt_tokens'] = ($usage['prompt_tokens'] ?? 0) + ($resp->usage->promptTokens ?? 0);
            $usage['completion_tokens'] = ($usage['completion_tokens'] ?? 0) + ($resp->usage->completionTokens ?? 0);

            $content = $resp->choices[0]->message->content ?? null;
            if (!is_string($content) || trim($content) === '') {
                throw new VisionException('puste content (finish=' . ($resp->choices[0]->finishReason ?? '?') . ')');
            }
            return $content;
        } catch (VisionException $e) {
            throw $e; // pusta treść nie jest błędem sieciowym - nie ma sensu retry'ować w kółko
        } catch (\OpenAI\Exceptions\RateLimitException $e) {
            $lastErr = $e;
            \telemetry\log('api_retry', null, ['vendor' => 'openai-vision']);
        } catch (\OpenAI\Exceptions\ErrorException $e) {
            if ($e->getStatusCode() >= 500) {
                $lastErr = $e;
                \telemetry\log('api_retry', null, ['vendor' => 'openai-vision']);
            } else {
                throw new VisionException('OpenAI ' . $e->getStatusCode() . ': ' . $e->getMessage());
            }
        } catch (\OpenAI\Exceptions\TransporterException $e) {
            $lastErr = $e;
            \telemetry\log('api_retry', null, ['vendor' => 'openai-vision']);
        }
        if ($attempt < $tries - 1) sleep(2 * ($attempt + 1));
    }
    throw new VisionNetworkException('blad sieci OpenAI: ' . ($lastErr !== null ? $lastErr->getMessage() : 'nieznany'));
}

function imgPart(string $jpegBytes, string $detail): array {
    return ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpegBytes), 'detail' => $detail]];
}

function parsePhotos(string $raw): array {
    $r = extractJson($raw);
    if ($r['parsed'] === null) {
        throw new VisionException($r['truncated'] ? 'model uciął JSON' : 'model nie zwrócił JSON: ' . substr($raw, 0, 160));
    }
    $photos = $r['parsed']['photos'] ?? null;
    if (!is_array($photos)) throw new VisionException('brak klucza photos w odpowiedzi');
    return array_values($photos);
}

// Mapuje car.bbox/plate.bbox z pikseli modelu (patrz visionPrompt()) do 0..1000 przez
// pixelBoxToVisionSpace(). Wołane po KAŻDYM parsePhotos() w analyzeChunk() (główny parse + po
// obu rundach naprawy) — walidacja i cała reszta pipeline'u dalej widzi tylko 0..1000.
function applyPixelBoxes(array &$photos, array $dims): void {
    foreach ($photos as &$p) {
        $d = $dims[$p['photo_index'] ?? null] ?? null;
        if (!$d) continue;
        if (isset($p['car']['bbox'])) $p['car']['bbox'] = pixelBoxToVisionSpace($p['car']['bbox'], $d['w'], $d['h']);
        if (isset($p['plate']['bbox'])) $p['plate']['bbox'] = pixelBoxToVisionSpace($p['plate']['bbox'], $d['w'], $d['h']);
    }
    unset($p);
}

/** @param list<array{photoId:string,photo_index:int,bytes:string}> $items */
function analyzeChunk(array $items, array &$usage): array {
    $idx = array_map(fn ($i) => $i['photo_index'], $items);
    $n = count($items);
    $note = ($n > 1 || $idx[0] !== 0)
        ? ' These are photos ' . $idx[0] . '..' . end($idx) . ' of the same report; use exactly these photo_index values.'
        : '';

    $dims = [];
    foreach ($items as $it) {
        $size = imageSize($it['bytes']);
        if ($size) $dims[$it['photo_index']] = $size;
    }

    $content = [['type' => 'text', 'text' => visionPrompt($dims) . $note]];
    foreach ($items as $it) $content[] = imgPart($it['bytes'], OPENAI_VISION_DETAIL);
    $history = [['role' => 'user', 'content' => $content]];

    $raw = chat($history, OPENAI_VISION_MAX_TOKENS, $usage);
    try {
        $photos = parsePhotos($raw);
        applyPixelBoxes($photos, $dims);
    } catch (VisionException $e) {
        // jedna próba naprawy: model odpowiedział prozą
        $history[] = ['role' => 'assistant', 'content' => $raw];
        $history[] = ['role' => 'user', 'content' => 'That was not valid JSON. Repeat the answer as raw JSON only: no prose, no markdown fences, no commentary.'];
        $raw = chat($history, OPENAI_VISION_MAX_TOKENS, $usage);
        $photos = parsePhotos($raw); // tu już głośno przy porażce
        applyPixelBoxes($photos, $dims);
    }

    $want = $idx; sort($want);
    $got = array_map(fn ($p) => $p['photo_index'] ?? null, $photos); sort($got);
    if ($got !== $want) {
        throw new VisionException('zwrócono indeksy [' . implode(',', $got) . '], oczekiwano [' . implode(',', $want) . ']');
    }
    usort($photos, fn ($a, $b) => ($a['photo_index'] ?? 0) <=> ($b['photo_index'] ?? 0));

    $errs = [];
    foreach ($photos as $k => $p) {
        foreach (validateVisionPhoto($p, $idx[$k]) as $e) $errs[] = "zdj.{$idx[$k]}: $e";
    }
    if ($errs) {
        // jedna próba naprawy: odeślij błędy + przypomnij kontrakt pikseli (gdy model
        // przysłał 0..1000 zamiast pikseli, konwersja daje >1000 i "zly bbox" — bez tego
        // zdania model powtórzyłby błąd, paląc rundę naprawy i kończąc 502).
        $dimsHint = implode('; ', array_map(
            fn ($idx, $d) => "photo_index $idx: {$d['w']}x{$d['h']}px",
            array_keys($dims),
            $dims,
        ));
        $history[] = ['role' => 'assistant', 'content' => $raw];
        $history[] = ['role' => 'user', 'content' => 'Your JSON has errors: ' . implode('; ', $errs)
            . '. Reminder: bbox is [x1,y1,x2,y2] in ACTUAL PIXELS of that photo (dimensions: ' . $dimsHint
            . ') — do NOT normalize to 0..1000 or 0..1. Return the FULL corrected JSON object again. JSON only.'];
        $raw = chat($history, OPENAI_VISION_MAX_TOKENS, $usage);
        $photos = parsePhotos($raw);
        applyPixelBoxes($photos, $dims);
        usort($photos, fn ($a, $b) => ($a['photo_index'] ?? 0) <=> ($b['photo_index'] ?? 0));
    }
    $errs = [];
    foreach ($photos as $k => $p) {
        foreach (validateVisionPhoto($p, $idx[$k]) as $e) $errs[] = "zdj.{$idx[$k]}: $e";
    }
    if ($errs) throw new VisionException('walidacja: ' . implode('; ', $errs));

    foreach ($photos as &$p) normalizeVisionPhoto($p);
    unset($p);
    return $photos;
}

function imageSize(string $bytes): ?array {
    $info = @getimagesizefromstring($bytes);
    if (!$info) return null;
    return ['w' => (float)$info[0], 'h' => (float)$info[1]];
}

// Wszystkie odczyty ALPR ze zdjęcia (nie tylko najlepszy): tekst, bbox tablicy i pojazdu w 0..1000
// oraz pole pojazdu jako ułamek kadru. Appka (compose.ts) przydziela z tego role deterministycznie:
// car = zdjęcie, na którym pojazd z daną tablicą jest największy; kontekst = inne zdjęcie, na którym
// ten pojazd też widać (może być wspólny dla kilku tablic). Model (gpt-5-nano) tego porównania
// między zdjęciami nie robi wiarygodnie mimo instrukcji w prompcie.
function alprDetections(array $resp, ?array $size): array {
    $out = [];
    foreach ($resp['results'] ?? [] as $r) {
        $text = normPlateKey($r['plate'] ?? null);
        if ($text === null) continue;
        $area = null;
        if ($size && isset($r['vehicle']['box'])) {
            $area = round(\alpr\vehicleArea($r) / ($size['w'] * $size['h']), 4);
        }
        $out[] = [
            'text' => $text,
            'score' => $r['score'] ?? null,
            'plate_bbox' => ($size && isset($r['box'])) ? alprBoxToVisionSpace($r['box'], $size['w'], $size['h']) : null,
            'vehicle_bbox' => ($size && isset($r['vehicle']['box'])) ? alprBoxToVisionSpace($r['vehicle']['box'], $size['w'], $size['h']) : null,
            'vehicle_area' => $area,
        ];
    }
    return $out;
}

// Tablica + bbox auta z ALPR (PlateRecognizer) dla każdego zdjęcia poza 'unusable' — patrz
// caller w analyzeCandidate(). Zawsze zapisuje alpr_all (patrz alprDetections()) i plate_debug
// (model vs ALPR + score, TYMCZASOWE do wglądu w appce — usunąć razem z UI po stronie klienta
// przy kolejnym podbiciu VISION_SCHEMA, appka nieznany klucz ignoruje). Nadpisuje plate/car
// modelu tylko gdy ALPR zwróci parsowalną tablicę ze score >= ALPR_MIN_SCORE; wpp. graceful
// fallback (plate_debug.alpr=null gdy ALPR nic nie znalazł, albo wpis + warning gdy score za
// niski) bez plate_verified.
function applyAlprPlate(string $bytes, array &$photo, array &$warnings): void {
    $modelPlate = $photo['plate'] ?? ['readable' => false, 'text' => null, 'bbox' => null];
    $modelCar = $photo['car'] ?? ['present' => false, 'bbox' => null, 'desc' => null];

    $best = null;
    $photo['alpr_all'] = [];
    try {
        $resp = PlateRecognizerClient::call($bytes);
        $photo['alpr_all'] = alprDetections($resp, imageSize($bytes));
        $best = \alpr\bestAlprResult($resp);
    } catch (\Throwable $e) {
        $msg = 'platerecognizer: ' . substr($e->getMessage(), 0, 150);
        log_info("vision $msg");
        $warnings[] = "zdj.{$photo['photo_index']}: $msg";
    }

    if (!$best) {
        $photo['plate_debug'] = [
            'model' => ['text' => $modelPlate['text'] ?? null, 'bbox' => $modelPlate['bbox'] ?? null],
            'alpr' => null,
        ];
        return;
    }

    $size = imageSize($bytes);
    $alprText = normPlateKey($best['plate'] ?? null);
    $alprPlateBbox = ($size && isset($best['box'])) ? alprBoxToVisionSpace($best['box'], $size['w'], $size['h']) : null;
    $alprCarBbox = ($size && isset($best['vehicle']['box'])) ? alprBoxToVisionSpace($best['vehicle']['box'], $size['w'], $size['h']) : null;

    $photo['plate_debug'] = [
        'model' => ['text' => $modelPlate['text'] ?? null, 'bbox' => $modelPlate['bbox'] ?? null],
        'alpr' => ['text' => $alprText, 'bbox' => $alprPlateBbox, 'score' => $best['score'] ?? null],
    ];

    // Próg pewności: słaby odczyt ALPR nie nadpisuje (być może poprawnego) odczytu modelu
    // ani nie dostaje plate_verified — zostaje graceful fallback jak przy braku wyniku.
    if ($alprText !== null && ($best['score'] ?? 0) >= ALPR_MIN_SCORE) {
        $photo['plate'] = ['readable' => true, 'text' => $alprText, 'bbox' => $alprPlateBbox];
        // plate_verified = ALPR (score >= ALPR_MIN_SCORE) dostarczył parsowalną tablicę;
        // NIE jest to już konsensus dwóch opinii jak w dawnym pipeline'ie LLM.
        $photo['plate_verified'] = true;
        if ($alprCarBbox) {
            $photo['car'] = ['present' => true, 'bbox' => $alprCarBbox, 'desc' => $modelCar['desc'] ?? null];
        }
        // applyAlprPlate() działa PO walidacji (analyzeChunk), więc nadpisane boxy muszą
        // ponownie przejść niezmiennik "tablica w aucie" — inaczej odpowiedź API łamałaby
        // gwarancję schematu (np. ALPR znalazł tablicę, ale nie vehicle.box, a modelowy
        // bbox auta nie obejmuje boxu ALPR). Przy rozjechaniu: wracamy do auta modelu
        // i sygnalizujemy to flagą plate_check zamiast cichej podmiany.
        //
        // UWAGA: sprawdzamy względem $modelCar['bbox'] (zapisanego na początku funkcji),
        // NIE $photo['car']['bbox'] — ten ostatni mógł już zostać nadpisany bboxem
        // pojazdu z ALPR dwie linijki wyżej. PlateRecognizer zwraca tablicę+pojazd jako
        // spójną parę z JEDNEJ detekcji, więc porównanie z WŁASNYM vehicle.box ALPR jest
        // tautologią i nigdy nie wykryje błędu — zaobserwowane w praktyce: dwa auta w
        // kadrze (jedno na pierwszym planie, drugie w tle), ALPR trafił tablicą w auto
        // z tła zamiast w to, które model wskazał jako fotografowany pojazd, a ten check
        // (porównujący ALPR z ALPR) przepuszczał to bez ostrzeżenia.
        $cb = $modelCar['bbox'] ?? null;
        $pb = $photo['plate']['bbox'] ?? null;
        if (validBox($cb) && validBox($pb) && !plateInsideCar($cb, $pb)) {
            // Cofamy CAŁĄ parę (auto + tablica) do odczytu modelu, nie tylko auto: zostawienie
            // tablicy ALPR (wskazującej na INNY pojazd) obok przywróconego auta modelu łamałoby
            // dokładnie ten sam niezmiennik "tablica w aucie", który ten blok ma pilnować —
            // modelowa para była już poprawna (przeszła walidację w analyzeChunk) PRZED tym, jak
            // applyAlprPlate() ją nadpisała, więc jest to bezpieczny punkt powrotu.
            $photo['car'] = $modelCar;
            $photo['plate'] = $modelPlate;
            $photo['plate_verified'] = false;
            $photo['plate_check'] = 'unverified-box';
            $warnings[] = "zdj.{$photo['photo_index']}: bbox ALPR poza autem modelu — zostawiam odczyt modelu (plate_check=unverified-box)";
        }
    } elseif ($alprText !== null) {
        $warnings[] = "zdj.{$photo['photo_index']}: niski score ALPR (" . ($best['score'] ?? '?') . ") — zostawiam odczyt modelu";
    }
}

/**
 * Punkt wejścia. $photos: list<array{photoId:string,photo_index:int,bytes:string}>
 * (bytes = zdekodowane, znormalizowane do JPEG). Zwraca ['photos'=>..., 'usage'=>..., 'warnings'=>...].
 */
function analyzeCandidate(array $photos, string $userEmail, ?string $reportId = null): array {
    $usage = ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0];
    $warnings = [];
    $byIndex = [];
    foreach ($photos as $p) $byIndex[$p['photo_index']] = $p;

    try {
        try {
            $result = analyzeChunk($photos, $usage);
        } catch (VisionNetworkException $e) {
            if (count($photos) <= 3) throw $e;
            log_info("vision {$reportId}: duzy kandydat (" . count($photos) . " zdjec), dziele na paczki po 3: " . $e->getMessage());
            $result = [];
            for ($s = 0; $s < count($photos); $s += 3) {
                $pack = array_slice($photos, $s, 3);
                $result = array_merge($result, analyzeChunk($pack, $usage));
            }
            usort($result, fn ($a, $b) => ($a['photo_index'] ?? 0) <=> ($b['photo_index'] ?? 0));
        }

        // photoId ze zdjęć wejściowych (model go nie widzi, mapujemy po photo_index).
        foreach ($result as &$p) {
            $src = $byIndex[$p['photo_index']] ?? null;
            if ($src) $p['photoId'] = $src['photoId'];
        }
        unset($p);

        foreach ($result as &$p) {
            $src = $byIndex[$p['photo_index']] ?? null;
            if (!$src) continue;
            // Każde zdjęcie poza 'unusable': role od modelu bywają błędne (dwa 'car' dla tego
            // samego auta, 'third' dla innego pojazdu), więc appka przydziela je sama na podstawie
            // alpr_all — potrzebuje odczytów ze wszystkich zdjęć, nie tylko z tego oznaczonego 'car'.
            if (($p['role'] ?? null) !== 'unusable') {
                applyAlprPlate($src['bytes'], $p, $warnings);
            }
        }
        unset($p);

        \telemetry\log('api_vision', null, ['status' => 'success']);
    } catch (\Throwable $e) {
        \telemetry\log('api_vision', null, ['status' => 'error']);
        throw $e;
    }

    $cost = visionCostUsd($usage);
    // force=true: log_info() jest cichy na produkcji bez tego – koszt trzeba widzieć zawsze,
    // nie tylko na dev/staging.
    log_info(sprintf(
        'vision cost: %s photos=%d calls=%d prompt_tokens=%d completion_tokens=%d cost_usd=%.5f model=%s user=%s',
        $reportId ?? '?', count($photos), $usage['calls'], $usage['prompt_tokens'], $usage['completion_tokens'], $cost, OPENAI_VISION_MODEL, $userEmail
    ), true);

    return [
        'photos' => array_values($result),
        'usage' => [
            'prompt_tokens' => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'calls' => $usage['calls'],
            'cost_usd' => $cost,
        ],
        'warnings' => $warnings,
    ];
}

function visionCostUsd(array $usage): float {
    global $MODEL_PRICING;
    $p = $MODEL_PRICING[OPENAI_VISION_MODEL] ?? null;
    if (!$p) return 0.0;
    return ($usage['prompt_tokens'] * ($p['prompt'] ?? 0) + $usage['completion_tokens'] * ($p['completion'] ?? 0)) / 1_000_000;
}

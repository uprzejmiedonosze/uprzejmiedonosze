<?PHP namespace vision;

require_once(__DIR__ . '/VisionSchema.php');
require_once(__DIR__ . '/../data.php'); // $MODEL_PRICING (koszt), jak ApiAiHandler.php

// Faza 4 + 6: batch wizyjny na kandydata + weryfikacja bbox cropem. Port src/api/vision.ts
// (repo uprzejmiedonosze-pro), teraz po stronie serwera (klucz OpenAI nie może żyć w bundlu
// appki, i tak łatwiej chronić quotę per użytkownik). Retry sieciowy (3 próby, backoff 2s;
// tylko rate-limit i HTTP 5xx), fallback paczek po 3 przy dużym kandydacie, jedna runda naprawy
// na nie-JSON i jedna na błędy walidacji. Głośny błąd (VisionException) zamiast zgadywania.
//
// Różnica względem appki: appka crop'owała do weryfikacji tablicy z ORYGINALNEGO zdjęcia z
// aparatu; tu crop pochodzi z tego samego ~1600-2000px obrazu co główna analiza (jedyny upload
// per zdjęcie — patrz plan). Dlatego OPENAI_VISION_CROP_DETAIL='high' (nie 'low' jak dla
// głównej analizy) – OpenAI 'low' downsample'uje twardo do 512px, za mało do OCR tablicy.

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
 * dalszy pipeline (crop, imgPart) ma jeden format. Zwraca list<array{photoId,photo_index,bytes}>.
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

/**
 * Jedno wywołanie chat/completions z retry na rate-limit/5xx (3 próby, backoff 2s*attempt).
 * Sumuje tokeny do $usage (referencja: 'calls','prompt_tokens','completion_tokens').
 * Zwraca surową treść odpowiedzi (string) albo rzuca VisionException.
 */
function chat(array $messages, int $maxTokens, array &$usage, int $tries = 3): string {
    $lastErr = null;
    for ($attempt = 0; $attempt < $tries; $attempt++) {
        try {
            $resp = VisionClient::get()->chat()->create([
                'model' => OPENAI_VISION_MODEL,
                'temperature' => 0,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => $messages,
            ]);
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

/** @param list<array{photoId:string,photo_index:int,bytes:string}> $items */
function analyzeChunk(array $items, array &$usage): array {
    $idx = array_map(fn ($i) => $i['photo_index'], $items);
    $n = count($items);
    $note = ($n > 1 || $idx[0] !== 0)
        ? ' These are photos ' . $idx[0] . '..' . end($idx) . ' of the same report; use exactly these photo_index values.'
        : '';

    $content = [['type' => 'text', 'text' => visionPrompt() . $note]];
    foreach ($items as $it) $content[] = imgPart($it['bytes'], OPENAI_VISION_DETAIL);
    $history = [['role' => 'user', 'content' => $content]];

    $raw = chat($history, OPENAI_VISION_MAX_TOKENS, $usage);
    try {
        $photos = parsePhotos($raw);
    } catch (VisionException $e) {
        // jedna próba naprawy: model odpowiedział prozą
        $history[] = ['role' => 'assistant', 'content' => $raw];
        $history[] = ['role' => 'user', 'content' => 'That was not valid JSON. Repeat the answer as raw JSON only: no prose, no markdown fences, no commentary.'];
        $raw = chat($history, OPENAI_VISION_MAX_TOKENS, $usage);
        $photos = parsePhotos($raw); // tu już głośno przy porażce
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
        // jedna próba naprawy: odeślij błędy
        $history[] = ['role' => 'assistant', 'content' => $raw];
        $history[] = ['role' => 'user', 'content' => 'Your JSON has errors: ' . implode('; ', $errs) . '. Return the FULL corrected JSON object again. JSON only.'];
        $raw = chat($history, OPENAI_VISION_MAX_TOKENS, $usage);
        $photos = parsePhotos($raw);
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

/**
 * GD crop bytes-in/bytes-out. $x/$y/$w/$h w pikselach (nie 0..1000). Skaluje do $maxSide gdy
 * dłuższy bok przekracza limit. null gdy box zdegenerowany albo dane nie do zdekodowania.
 */
function cropJpeg(string $bytes, float $x, float $y, float $w, float $h, int $maxSide): ?string {
    if ($w < 10 || $h < 10) return null;
    $src = @imagecreatefromstring($bytes);
    if (!$src) return null;

    $W = imagesx($src);
    $H = imagesy($src);
    $ix = max(0, min($W - 1, (int)round($x)));
    $iy = max(0, min($H - 1, (int)round($y)));
    $iw = max(1, min($W - $ix, (int)round($w)));
    $ih = max(1, min($H - $iy, (int)round($h)));

    $cropped = imagecrop($src, ['x' => $ix, 'y' => $iy, 'width' => $iw, 'height' => $ih]);
    imagedestroy($src);
    if ($cropped === false) return null;

    $cw = imagesx($cropped);
    $ch = imagesy($cropped);
    $out = $cropped;
    if (max($cw, $ch) > $maxSide) {
        $scale = $maxSide / max($cw, $ch);
        $nw = max(1, (int)round($cw * $scale));
        $nh = max(1, (int)round($ch * $scale));
        $resized = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($resized, $cropped, 0, 0, 0, 0, $nw, $nh, $cw, $ch);
        imagedestroy($cropped);
        $out = $resized;
    }

    ob_start();
    imagejpeg($out, null, 80);
    $jpeg = ob_get_clean();
    imagedestroy($out);
    return $jpeg ?: null;
}

// Retry: auto bez czytelnej tablicy (quality >= 0.3) - crop car+10% marginesu, tylko odczyt.
function retryPlateCrop(string $bytes, array $photo, array &$usage, array &$warnings): ?string {
    $car = $photo['car'] ?? null;
    if (!($car['present'] ?? false) || !validBox($car['bbox'] ?? null)) return null;
    try {
        $size = imageSize($bytes);
        if (!$size) return null;
        $W = $size['w']; $H = $size['h'];
        [$x1, $y1, $x2, $y2] = array_map(fn ($v) => $v / 1000, $car['bbox']);
        $mx = ($x2 - $x1) * 0.1;
        $my = ($y2 - $y1) * 0.1;
        $bx = max(0, ($x1 - $mx) * $W);
        $by = max(0, ($y1 - $my) * $H);
        $bw = min($W, ($x2 + $mx) * $W) - $bx;
        $bh = min($H, ($y2 + $my) * $H) - $by;
        $crop = cropJpeg($bytes, $bx, $by, $bw, $bh, 1600);
        if (!$crop) return null;

        $raw = chat([[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'This is a crop of a car. Read its Polish licence plate. Return JSON {"readable":bool, "text":str|null} only.'],
                imgPart($crop, OPENAI_VISION_CROP_DETAIL),
            ],
        ]], OPENAI_VISION_CROP_MAX_TOKENS, $usage, 2);
        $r = extractJson($raw);
        if (!($r['parsed']['readable'] ?? false)) return null;
        return normPlateKey($r['parsed']['text'] ?? null);
    } catch (\Throwable $e) {
        $msg = 'retry crop: ' . substr($e->getMessage(), 0, 150);
        logger("vision $msg");
        $warnings[] = "zdj.{$photo['photo_index']}: $msg";
        return null;
    }
}

// Weryfikacja pozycji tablicy drugą opinią. Crop bazuje na CAR.bbox (pewny), nigdy na
// plate.bbox (podejrzany o przesunięcie). Zwraca ['status'=>ok|mismatch|skip, 'bbox'=>...].
function verifyPlateBox(string $bytes, array $photo, array &$usage, array &$warnings): array {
    $plate = $photo['plate'] ?? [];
    if (!($plate['readable'] ?? false) || ($photo['plate_verified'] ?? false)) {
        return ['status' => 'skip', 'bbox' => null];
    }
    $expected = $plate['text'] ?? null;
    $car = $photo['car'] ?? [];
    try {
        $size = imageSize($bytes);
        if (!$size) return ['status' => 'skip', 'bbox' => null];
        $W = $size['w']; $H = $size['h'];

        if (($car['present'] ?? false) && validBox($car['bbox'] ?? null)) {
            [$x1, $y1, $x2, $y2] = array_map(fn ($v) => $v / 1000, $car['bbox']);
            $m = 0.1;
        } elseif (validBox($plate['bbox'] ?? null)) {
            [$x1, $y1, $x2, $y2] = array_map(fn ($v) => $v / 1000, $plate['bbox']);
            $m = 1.5;
        } else {
            return ['status' => 'skip', 'bbox' => null];
        }
        $mx = ($x2 - $x1) * $m;
        $my = ($y2 - $y1) * $m;
        $bx = max(0, ($x1 - $mx) * $W);
        $by = max(0, ($y1 - $my) * $H);
        $bw = min($W, ($x2 + $mx) * $W) - $bx;
        $bh = min($H, ($y2 + $my) * $H) - $by;
        $crop = cropJpeg($bytes, $bx, $by, $bw, $bh, 1000);
        if (!$crop) return ['status' => 'skip', 'bbox' => null];

        $raw = chat([[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'This crop shows a car (front). Find its Polish licence plate. Return JSON {"text": plate characters or null, "bbox": [x1,y1,x2,y2] tight around the plate characters in 0..1000 coords of THIS crop, or null if no plate visible}. JSON only.'],
                imgPart($crop, OPENAI_VISION_CROP_DETAIL),
            ],
        ]], OPENAI_VISION_CROP_MAX_TOKENS, $usage, 2);
        $r = extractJson($raw);
        $parsed = $r['parsed'];
        if (!$parsed) return ['status' => 'skip', 'bbox' => null];
        $text = normPlateKey($parsed['text'] ?? null);
        $fb = $parsed['bbox'] ?? null;
        if ($text !== $expected || !is_array($fb) || count($fb) !== 4) {
            return ['status' => 'mismatch', 'bbox' => null];
        }
        [$fx1, $fy1, $fx2, $fy2] = array_map(fn ($v) => $v / 1000, $fb);
        return [
            'status' => 'ok',
            'bbox' => [
                (int)round((($bx + $fx1 * $bw) / $W) * 1000),
                (int)round((($by + $fy1 * $bh) / $H) * 1000),
                (int)round((($bx + $fx2 * $bw) / $W) * 1000),
                (int)round((($by + $fy2 * $bh) / $H) * 1000),
            ],
        ];
    } catch (\Throwable $e) {
        $msg = 'verify crop: ' . substr($e->getMessage(), 0, 150);
        logger("vision $msg");
        $warnings[] = "zdj.{$photo['photo_index']}: $msg";
        return ['status' => 'skip', 'bbox' => null];
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
            logger("vision {$reportId}: duzy kandydat (" . count($photos) . " zdjec), dziele na paczki po 3: " . $e->getMessage());
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

            $role = $p['role'] ?? null;
            $plate = $p['plate'] ?? [];
            $quality = $p['quality'] ?? 0;
            if ($role === 'car' && !($plate['readable'] ?? false) && $quality >= 0.3) {
                $text = retryPlateCrop($src['bytes'], $p, $usage, $warnings);
                if ($text !== null) {
                    $p['plate'] = ['readable' => true, 'text' => $text, 'bbox' => null, 'from_crop' => true];
                }
            }

            $v = verifyPlateBox($src['bytes'], $p, $usage, $warnings);
            if ($v['status'] === 'ok') {
                $p['plate']['bbox'] = $v['bbox'];
                $p['plate_verified'] = true;
            } elseif ($v['status'] === 'mismatch') {
                $p['plate']['plate_check'] = 'unverified-box';
            }
        }
        unset($p);

        \telemetry\log('api_vision', null, ['status' => 'success']);
    } catch (\Throwable $e) {
        \telemetry\log('api_vision', null, ['status' => 'error']);
        throw $e;
    }

    $cost = visionCostUsd($usage);
    // force=true: logger() jest cichy na produkcji bez tego – koszt trzeba widzieć zawsze,
    // nie tylko na dev/staging.
    logger(sprintf(
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

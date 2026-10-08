<?PHP namespace faces;

require_once(__DIR__ . '/Vision.php'); // \vision\chat(), imgPart(), VisionClient, visionCostUsd()

// Wykrywanie twarzy na zdjęciu kontekstowym dla face-detect-consumer (zastępuje dawny
// self-hosted kontener dlib). Dwa kroki:
//  1. tania bramka gpt-5-nano (detail:'low', ~0,00004 USD/zdjęcie) — tylko liczy twarze,
//  2. przy count > 0 (w praktyce ~0,5% zdjęć) Google Cloud Vision FACE_DETECTION po dokładne
//     bboxy — LLM-y zwracają nieprecyzyjne pozycje (por. IoU tablic 0.5-0.6 w Vision.php), a
//     przeoczony fragment twarzy przy blurze to wyciek. ~10 zdjęć/mies. mieści się w darmowym
//     limicie Google (1000/mies.).
// Twarz przeoczona przez bramkę nie zostanie zblurowana — ryzyko zaakceptowane, takie samo jak
// przy dlib. Gdy Google nie znajdzie twarzy albo padnie, zdjęcie zostaje ukryte w całości
// (blurred:false), czyli bezpieczny fallback.

const NANO_MAX_PX = 1024;
const GVISION_MAX_PX = 4000; // limit Google to 20 MB / 75 MPx

// Test seam analogiczny do \vision\PlateRecognizerClient.
final class GoogleVisionClient {
    /** @var (callable(string): array)|null */
    private static $override = null;

    public static function set(?callable $fn): void {
        self::$override = $fn;
    }

    public static function call(string $jpegBytes): array {
        if (self::$override !== null) return (self::$override)($jpegBytes);
        return annotate($jpegBytes);
    }
}

/**
 * Wynik dla Application::$faces: {count, source, blurred?, boxes?}.
 * @throws \vision\VisionException gdy bramka OpenAI nie zwróci poprawnej odpowiedzi
 */
function detect(\app\Application $app): \JSONObject {
    $path = ROOT . $app->contextImage->url;
    if (!file_exists($path)) $app->ensureLocal();
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        throw new \RuntimeException("Brak pliku {$app->contextImage->url}");
    }
    return detectBytes($bytes, $app->id);
}

/**
 * @throws \vision\VisionException gdy bramka OpenAI nie zwróci poprawnej odpowiedzi
 */
function detectBytes(string $bytes, string $appId): \JSONObject {
    $usage = [];
    $count = countFaces($bytes, $usage);
    $result = ['count' => $count, 'source' => 'openai'];

    $located = null;
    if ($count > 0) {
        try {
            $located = locateFaces($bytes);
        } catch (\Throwable $e) {
            log_error("faces: $appId Google Vision: " . $e->getMessage(), $e);
        }
        if ($located) {
            $result = ['count' => count($located), 'source' => 'openai+gvision',
                'blurred' => true, 'boxes' => $located];
        } else {
            $result['blurred'] = false;
        }
    }

    log_info(sprintf('faces: %s nano=%d gvision=%s prompt_tokens=%d completion_tokens=%d cost_usd=%.5f model=%s',
        $appId, $count, $located === null ? '-' : count($located),
        $usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0,
        \vision\visionCostUsd($usage + ['prompt_tokens' => 0, 'completion_tokens' => 0], OPENAI_FACES_MODEL),
        OPENAI_FACES_MODEL), true);

    return new \JSONObject($result);
}

/**
 * Bramka: ile rozpoznawalnych twarzy jest na zdjęciu (gpt-5-nano).
 * @throws \vision\VisionException
 */
function countFaces(string $jpegBytes, array &$usage): int {
    $prompt = 'Policz twarze ludzi widoczne na zdjęciu na tyle wyraźnie, że mogłyby pozwolić '
        . 'na rozpoznanie osoby (także częściowo widoczne, z profilu, za szybą samochodu). '
        . 'Nie licz twarzy na plakatach, reklamach, billboardach, okładkach ani rzeźbach, '
        . 'ani osób widocznych wyłącznie od tyłu. W razie wątpliwości policz twarz. '
        . 'Odpowiedz wyłącznie JSON-em: {"faces": <liczba całkowita>}.';
    $messages = [['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => $prompt],
        \vision\imgPart(resizeJpeg($jpegBytes, NANO_MAX_PX), 'low'),
    ]]];

    $raw = \vision\chat($messages, 1000, $usage, model: OPENAI_FACES_MODEL, effort: 'minimal');
    $json = json_decode($raw, true);
    $faces = is_array($json) ? ($json['faces'] ?? null) : null;
    if (!is_int($faces) || $faces < 0) {
        throw new \vision\VisionException('faces: zła odpowiedź modelu: ' . mb_substr($raw, 0, 200));
    }
    return $faces;
}

/**
 * Bboxy twarzy z Google Vision, znormalizowane do ułamków 0..1.
 * @return list<array{x: float, y: float, w: float, h: float}>
 */
function locateFaces(string $jpegBytes): array {
    $resized = resizeJpeg($jpegBytes, GVISION_MAX_PX);
    $size = getimagesizefromstring($resized);
    if (!$size) throw new \RuntimeException('locateFaces: nie można odczytać rozmiaru obrazka');
    [$imgW, $imgH] = $size;

    $resp = GoogleVisionClient::call($resized);
    $error = $resp['responses'][0]['error']['message'] ?? null;
    if ($error) throw new \RuntimeException("Google Vision: $error");

    $boxes = [];
    foreach ($resp['responses'][0]['faceAnnotations'] ?? [] as $face) {
        // boundingPoly obejmuje całą głowę (z włosami), fdBoundingPoly samą skórę twarzy.
        // Szersza ramka + margines z blur() = bezpieczniej.
        $vertices = $face['boundingPoly']['vertices'] ?? $face['fdBoundingPoly']['vertices'] ?? [];
        $xs = array_map(fn ($v) => (int)($v['x'] ?? 0), $vertices);
        $ys = array_map(fn ($v) => (int)($v['y'] ?? 0), $vertices);
        if (!$xs || !$ys) continue;
        $x = max(0, min($xs));
        $y = max(0, min($ys));
        $w = min($imgW, max($xs)) - $x;
        $h = min($imgH, max($ys)) - $y;
        if ($w <= 0 || $h <= 0) continue;
        $boxes[] = ['x' => round($x / $imgW, 4), 'y' => round($y / $imgH, 4),
            'w' => round($w / $imgW, 4), 'h' => round($h / $imgH, 4)];
    }
    return $boxes;
}

/**
 * POST images:annotate (FACE_DETECTION), retry na 429/5xx/transport.
 * @SuppressWarnings(PHPMD.MissingImport)
 */
function annotate(string $jpegBytes, int $tries = 3): array {
    if (empty(GOOGLE_VISION_API_KEY)) throw new \RuntimeException('GOOGLE_VISION_API_KEY nie ustawiony');
    $body = json_encode(['requests' => [[
        'image' => ['content' => base64_encode($jpegBytes)],
        'features' => [['type' => 'FACE_DETECTION', 'maxResults' => 50]],
    ]]]);

    $lastErr = 'nieznany';
    for ($attempt = 0; $attempt < $tries; $attempt++) {
        $ch = curl_init('https://vision.googleapis.com/v1/images:annotate?key=' . urlencode(GOOGLE_VISION_API_KEY));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
        ]);
        $out = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($curlErr === null && $status === 200) {
            $json = json_decode($out, true);
            if (!is_array($json)) throw new \RuntimeException('Google Vision: odpowiedź nie jest JSON-em');
            return $json;
        }
        $lastErr = $curlErr ?? "HTTP $status: " . mb_substr((string)$out, 0, 300);
        if ($curlErr === null && $status !== 429 && $status < 500) break; // 4xx — retry nic nie da
        \telemetry\log('api_retry', null, ['vendor' => 'google-vision']);
        if ($attempt < $tries - 1) sleep(2 * ($attempt + 1));
    }
    throw new \RuntimeException("Google Vision: $lastErr");
}

// Zmniejsza JPEG do $maxPx po dłuższym boku (bez zmian, jeśli już mniejszy).
function resizeJpeg(string $jpegBytes, int $maxPx): string {
    $size = @getimagesizefromstring($jpegBytes);
    if (!$size || max($size[0], $size[1]) <= $maxPx) return $jpegBytes;
    $src = @imagecreatefromstring($jpegBytes);
    if ($src === false) return $jpegBytes;
    $ratio = $maxPx / max($size[0], $size[1]);
    $scaled = imagescale($src, (int)round($size[0] * $ratio), (int)round($size[1] * $ratio));
    imagedestroy($src);
    if ($scaled === false) return $jpegBytes;
    ob_start();
    imagejpeg($scaled, null, 85);
    $out = ob_get_clean();
    imagedestroy($scaled);
    return $out;
}

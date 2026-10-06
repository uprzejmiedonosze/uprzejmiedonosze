<?PHP namespace alpr;

use cache\Type;
use \JSONObject as JSONObject;

// Różnica score poniżej tego progu = ALPR sam jest "niezdecydowany" (mieści się w szumie
// silnika OCR) — patrz bestAlprResult() niżej. Dobrane empirycznie na 1000 losowych wpisach
// Platerecognizer z cache produkcyjnego (2026-10-01): mediana scoreGap przy "mniejszy pojazd
// wygrywa score'em" to 0.001 (czysty szum), krzywa "ile decyzji się zmienia" spłaszcza się
// koło 0.08-0.10 (band=0.05 -> 21.7% wpisów wielowynikowych, band=0.10 -> 23.9%, band=0.30 ->
// 25.5% - powyżej 0.10 praktycznie nic już się nie zmienia, populacja "spornych" przypadków
// kończy się twardo przy gap≈0.18).
const ALPR_SCORE_TIE_BAND = 0.1;

// Pole bboxa pojazdu (px²) — proxy na "jak blisko autora zdjęcia jest ten pojazd": fotografowane
// auto jest zwykle znacznie bliżej (i przez to większe w kadrze) niż przypadkowy samochód
// widoczny w tle/na boku.
function vehicleArea(array $r): float {
    $b = $r['vehicle']['box'] ?? null;
    if (!$b) return 0.0;
    return max(0, $b['xmax'] - $b['xmin']) * max(0, $b['ymax'] - $b['ymin']);
}

// Najlepszy wynik PlateRecognizer. Przy realnej różnicy pewności odczytu (>= ALPR_SCORE_TIE_BAND)
// wygrywa score. W paśmie remisu — zaobserwowane w praktyce: Skoda na pierwszym planie
// score=0.996, inne auto w tle score=1.000, różnica mieści się w szumie silnika OCR — rozstrzyga
// WIELKOŚĆ pojazdu w kadrze: większy pojazd = bliżej autora zdjęcia = większa szansa, że to o
// NIEGO chodzi (fotografujący podchodzi do zgłaszanego auta, nie do tła). Używane przez obie
// ścieżki (web: get_car_info_platerecognizer() niżej; mobile: applyAlprPlate() w Vision.php) —
// jedna definicja "najlepszego wyniku", żeby się nie rozjeżdżały.
function bestAlprResult(array $resp): ?array {
    $results = $resp['results'] ?? [];
    if (!is_array($results) || !count($results)) return null;
    usort($results, function ($a, $b) {
        $scoreA = $a['score'] ?? 0;
        $scoreB = $b['score'] ?? 0;
        if (abs($scoreA - $scoreB) < ALPR_SCORE_TIE_BAND) {
            return vehicleArea($b) <=> vehicleArea($a);
        }
        return $scoreB <=> $scoreA;
    });
    return $results[0];
}

/**
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
function get_car_info_platerecognizer(&$imageBytes, &$application, $baseFileName, $type, ?array $carInfo = null) {
    $carInfo ??= get_platerecognizer($imageBytes);
    $application->alpr = 'platerecognizer';

    $result = bestAlprResult($carInfo ?? []);
    if ($result) {
        $box = $result['box'];

        $imp = imagecreatefromjpeg(ROOT . "$baseFileName,$type.jpg");
        $plateImage = imagecrop($imp, ['x' => $box['xmin'], 'y' => $box['ymin'],
            'width' => ($box['xmax'] - $box['xmin']), 'height' => ($box['ymax'] - $box['ymin'])]);
        if ($plateImage !== FALSE) {
            $application->carInfo->plateImage = "$baseFileName,$type,p.jpg";
            imagejpeg($plateImage, ROOT . $application->carInfo->plateImage);
        }
        $application->carInfo->plateId = strtoupper($result["plate"]);
        $application->carInfo->plateIdFromImage = strtoupper($result["plate"]);

        if (isset($result['vehicle']['box'])) {
            $vehicleBox = $result['vehicle']['box'];
            $application->carInfo->vehicleBox = new JSONObject();
            $application->carInfo->vehicleBox->x = $vehicleBox['xmin'];
            $application->carInfo->vehicleBox->y = $vehicleBox['ymin'];
            $application->carInfo->vehicleBox->width = $vehicleBox['xmax'] - $vehicleBox['xmin'];
            $application->carInfo->vehicleBox->height = $vehicleBox['ymax'] - $vehicleBox['ymin'];
        }
    }
}

/**
 * Skaluje współrzędne (box tablicy i pojazdu) wyniku PlateRecognizer o podane współczynniki.
 * Czysta funkcja – używana, gdy wynik policzono dla obrazka o innych wymiarach niż ten zapisany na serwerze.
 */
function scalePlateRecognizerResult(array $resp, float $sx, float $sy): array {
    $scaleBox = function (&$box) use ($sx, $sy): void {
        if (!is_array($box)) return;
        foreach (['xmin', 'xmax'] as $k) if (isset($box[$k])) $box[$k] = (int)round($box[$k] * $sx);
        foreach (['ymin', 'ymax'] as $k) if (isset($box[$k])) $box[$k] = (int)round($box[$k] * $sy);
    };
    foreach ($resp['results'] ?? [] as $i => $r) {
        if (isset($r['box'])) $scaleBox($resp['results'][$i]['box']);
        if (isset($r['vehicle']['box'])) $scaleBox($resp['results'][$i]['vehicle']['box']);
    }
    return $resp;
}

/**
 * Wynik PlateRecognizer zawężony do odczytu o danej tablicy (porównanie bez spacji i wielkości liter) – gdy użytkownik wskazał
 * pojazd, którego dotyczy zgłoszenie, a na zdjęciu zwycięża inne auto. null = na zdjęciu nie ma takiego odczytu.
 */
function onlyPlate(array $resp, string $plate): ?array {
    $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate));
    foreach ($resp['results'] ?? [] as $r) {
        if (strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($r['plate'] ?? ''))) === $key) {
            return ['results' => [$r]] + $resp;
        }
    }
    return null;
}

/**
 * Przenosi współrzędne wyniku PlateRecognizer z obrazka źródłowego ($srcW x $srcH px) na jego wycinek: $region = ułamki kadru
 * źródła [x1, y1, x2, y2], a wycinek ma $dstW x $dstH px (może mieć inną rozdzielczość niż fragment źródła – np. wycięty z
 * oryginału zamiast z kopii 1600 px). Dzięki temu ALPR policzony raz na kopii źródłowej opisuje też wycinek w pełnej rozdzielczości.
 */
function mapPlateRecognizerResultToRegion(array $resp, int $srcW, int $srcH, array $region, int $dstW, int $dstH): array {
    [$fx1, $fy1, $fx2, $fy2] = $region;
    $sx = $dstW / max(1e-9, ($fx2 - $fx1) * $srcW);
    $sy = $dstH / max(1e-9, ($fy2 - $fy1) * $srcH);
    $map = function (&$box) use ($fx1, $fy1, $srcW, $srcH, $sx, $sy): void {
        if (!is_array($box)) return;
        foreach (['xmin', 'xmax'] as $k) if (isset($box[$k])) $box[$k] = (int)round(($box[$k] - $fx1 * $srcW) * $sx);
        foreach (['ymin', 'ymax'] as $k) if (isset($box[$k])) $box[$k] = (int)round(($box[$k] - $fy1 * $srcH) * $sy);
    };
    foreach ($resp['results'] ?? [] as $i => $r) {
        if (isset($r['box'])) $map($resp['results'][$i]['box']);
        if (isset($r['vehicle']['box'])) $map($resp['results'][$i]['vehicle']['box']);
    }
    return $resp;
}

/**
 * Prostokąt wycinka auta: box pojazdu z ALPR powiększony o $margin jego szerokości/wysokości z KAŻDEJ strony, docięty do kadru.
 * @param array{xmin:int|float,ymin:int|float,xmax:int|float,ymax:int|float} $box
 * @return array{0:int,1:int,2:int,3:int} [x, y, szerokość, wysokość] w pikselach
 */
function vehicleCropBox(array $box, int $imageW, int $imageH, float $margin = 0.2): array {
    $w = max(1, $box['xmax'] - $box['xmin']);
    $h = max(1, $box['ymax'] - $box['ymin']);
    $x1 = max(0, (int)floor($box['xmin'] - $margin * $w));
    $y1 = max(0, (int)floor($box['ymin'] - $margin * $h));
    $x2 = min($imageW, (int)ceil($box['xmax'] + $margin * $w));
    $y2 = min($imageH, (int)ceil($box['ymax'] + $margin * $h));
    return [$x1, $y1, max(1, $x2 - $x1), max(1, $y2 - $y1)];
}

/** Przesuwa współrzędne (box tablicy i pojazdu) wyniku PlateRecognizer o ($dx, $dy) – po wycięciu fragmentu obrazka. */
function translatePlateRecognizerResult(array $resp, int $dx, int $dy): array {
    $move = function (&$box) use ($dx, $dy): void {
        if (!is_array($box)) return;
        foreach (['xmin', 'xmax'] as $k) if (isset($box[$k])) $box[$k] = (int)round($box[$k] + $dx);
        foreach (['ymin', 'ymax'] as $k) if (isset($box[$k])) $box[$k] = (int)round($box[$k] + $dy);
    };
    foreach ($resp['results'] ?? [] as $i => $r) {
        if (isset($r['box'])) $move($resp['results'][$i]['box']);
        if (isset($r['vehicle']['box'])) $move($resp['results'][$i]['vehicle']['box']);
    }
    return $resp;
}

function get_platerecognizer(&$imageBytes) {
    $imageHash = sha1($imageBytes);
    $result = \cache\alpr\get(Type::Platerecognizer, $imageHash);
    if($result){
        return $result;
    }

    $data = array(
        'upload' => base64_encode($imageBytes),
        'regions' => 'pl',
        'mmc' => true
    );

    $result = platerecognizerRequest('/plate-reader/', $data);
    $usage = platerecognizerRequest('/statistics/');
    if (isset($usage['total_calls']) && isset($usage['usage'])) {
        log_debug("get_platerecognizer " . $usage['usage']["calls"] . "/" . $usage['total_calls']);
    }

    if(isset($result["results"]) && count($result["results"]))
        \cache\alpr\set(Type::Platerecognizer, $imageHash, $result);
    return $result;
}

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
function platerecognizerRequest($method, $data=null) {
    $chi = curl_init('https://api.platerecognizer.com/v1' . $method);

    curl_setopt($chi, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chi, CURLINFO_HEADER_OUT, true);
    curl_setopt($chi, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
    if (!empty($data)) {
        curl_setopt($chi, CURLOPT_POST, true);
        curl_setopt($chi, CURLOPT_POSTFIELDS, $data);
    }
    $secretKey = PLATERECOGNIZER_SECRET;

    curl_setopt($chi, CURLOPT_HTTPHEADER,
        array("Authorization: Token $secretKey")
    );
    $result = curl_exec($chi);
    if (curl_errno($chi)) {
        $error = curl_error($chi);
        curl_close($chi);
        log_info("Nie udało się pobrać danych platerecognizer: $error", true);
        \telemetry\log('api_platerecognizer', null, ['status' => 'error']);
        throw new \Exception("Nie udało się pobrać odpowiedzi z serwerów platerecognizer: $error", 500);
    }
    curl_close($chi);

    \telemetry\log('api_platerecognizer', null, ['status' => 'success']);
    return json_decode($result, true);
}

?>
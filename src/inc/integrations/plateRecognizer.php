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
function get_car_info_platerecognizer(&$imageBytes, &$application, $baseFileName, $type) {
    $carInfo = get_platerecognizer($imageBytes);
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
        $application->carInfo->brand = null;
        $application->carInfo->brandConfidence = 0;
        $application->carInfo->color = null;
        $application->carInfo->colorConfidence = 0;

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
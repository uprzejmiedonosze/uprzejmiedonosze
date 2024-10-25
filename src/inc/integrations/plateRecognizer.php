<?PHP namespace alpr;

use cache\Type;
use \JSONObject as JSONObject;


/**
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
function get_car_info_platerecognizer(&$imageBytes, &$application, $baseFileName, $type) {
    $carInfo = get_platerecognizer($imageBytes);
    $application->alpr = 'platerecognizer';

    if(isset($carInfo) && isset($carInfo["results"]) && count($carInfo["results"])){

        $result = (Array)$carInfo['results'];
        usort($result, function ($left, $right){
            if($left['score'] > $right['score']) return -1;
            if($left['score'] < $right['score']) return 1;
            return 0;
        });

        $result = $result[0];
        $box = $result['box'];

        $imp = imagecreatefromjpeg("/var/www/%HOST%/$baseFileName,$type.jpg");
        $plateImage = imagecrop($imp, ['x' => $box['xmin'], 'y' => $box['ymin'],
            'width' => ($box['xmax'] - $box['xmin']), 'height' => ($box['ymax'] - $box['ymin'])]);
        if ($plateImage !== FALSE) {
            $application->carInfo->plateImage = "$baseFileName,$type,p.jpg";
            imagejpeg($plateImage, '/var/www/%HOST%/' . $application->carInfo->plateImage);
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
        'upload' => $imageBytes,
        'regions' => 'pl',
        'mmc' => true
    );

    $result = platerecognizerRequest('/plate-reader/', $data);
    $usage = platerecognizerRequest('/statistics/');
    if (isset($usage['total_calls']) && isset($usage['usage'])) {
        logger("get_platerecognizer " . $usage['usage']["calls"] . "/" . $usage['total_calls'], true);
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
        logger("Nie udało się pobrać danych platerecognizer: $error");
        throw new \Exception("Nie udało się pobrać odpowiedzi z serwerów platerecognizer: $error", 500);
    }
    curl_close($chi);

    return json_decode($result, true);
}

?>
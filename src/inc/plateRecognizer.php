<?PHP

function cmp_platerecongnizer($left, $right){
    if($left['score'] > $right['score']) return -1;
    if($left['score'] < $right['score']) return 1;
    return 0;
}

/**
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
function get_car_info_platerecognizer(&$imageBytes, &$application, $baseFileName, $type) {       
    $carInfo = get_platerecognizer($imageBytes);

    if(isset($carInfo) && count($carInfo["results"])){

        $result = (Array)$carInfo['results'];
        usort($result, "cmp_platerecongnizer");

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
    }
}


function get_platerecognizer(&$imageBytes) {
    global $cache;
    $imageHash = sha1($imageBytes);
    $result = $cache->get("_platerecognizer-$imageHash");
    if($result){
        logger("get_platerecognizer cache-hit $imageHash");
        return $result;
    }
    logger("get_platerecognizer cache-miss $imageHash");

    $data = array(
        'upload' => $imageBytes,
        'regions' => 'pl',
        'mmc' => true
    );

    $chi = curl_init('https://api.platerecognizer.com/v1/plate-reader/');
    curl_setopt($chi, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chi, CURLINFO_HEADER_OUT, true);
    curl_setopt($chi, CURLOPT_POST, true);
    curl_setopt($chi, CURLOPT_POSTFIELDS, $data);
    curl_setopt($chi, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);

    $secretKey = PLATERECOGNIZER_SECRET;

    curl_setopt($chi, CURLOPT_HTTPHEADER, array(
        "Authorization: Token $secretKey"
        )
    );
    $result = curl_exec($chi);
    curl_close($chi);
    $json = json_decode($result, true);
    $cache->set("_platerecognizer-$imageHash", $json, MEMCACHE_COMPRESSED, 0);
    return $json;
}

?>
<?PHP
require_once(__DIR__ . '/include.php');

function cmp_alpr($left, $right){
    if($left['confidence'] > $right['confidence']) return -1;
    if($left['confidence'] < $right['confidence']) return 1;
    return 0;
}

function cmp_platerecongnizer($left, $right){
    if($left['score'] > $right['score']) return -1;
    if($left['score'] < $right['score']) return 1;
    return 0;
}

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
function get_car_info(&$imageBytes, &$application, $baseFileName, $type) {
    global $storage;

    $application->carImage = new stdClass();
    $application->carImage->url = "$baseFileName,$type.jpg";
    $application->carImage->thumb = "$baseFileName,$type,t.jpg";

    $application->carInfo = new stdClass();

    if(intval(date('s')) % 3) { // 2/3 hits
        get_car_info_alpr($imageBytes, $application, $baseFileName, $type);
    } else { // 1/3 hits
        get_car_info_platerecognizer($imageBytes, $application, $baseFileName, $type);
    }
    
    if ($application->carInfo->plateId) {
        $recydywa = $storage->getRecydywa($application->carInfo->plateId);
        $application->carInfo->recydywa = $recydywa;
    }
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

/**
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 */
function get_car_info_alpr(&$imageBytes, &$application, $baseFileName, $type) {
    
    $carInfo = get_alpr($imageBytes);

    if(isset($carInfo) && count($carInfo["results"])){
        _check_alpr_budget($carInfo['credits_monthly_used'], $carInfo['credits_monthly_total']);

        $result = (Array)$carInfo['results'];
        usort($result, "cmp_alpr");

        $result = $result[0];

        $coordinates = $result["coordinates"];
        $xes = Array();
        $yes = Array();
        foreach($coordinates as $ojb){
            $xes[] = $ojb->getX();
            $yes[] = $ojb->getY();
        }

        $imp = imagecreatefromjpeg("/var/www/%HOST%/$baseFileName,$type.jpg");
        $plateImage = imagecrop($imp, ['x' => min($xes), 'y' => min($yes), 'width' => (max($xes) - min($xes)), 'height' => (max($yes) - min($yes))]);
        if ($plateImage !== FALSE) {

            $application->carInfo->plateImage = "$baseFileName,$type,p.jpg";
            imagejpeg($plateImage, '/var/www/%HOST%/' . $application->carInfo->plateImage);
        }

        
        $application->carInfo->plateId = strtoupper($result["plate"]);
        $application->carInfo->plateIdFromImage = strtoupper($result["plate"]);
        $application->carInfo->brand = @ucfirst($result["vehicle"]['make'][0]['name']);
        $application->carInfo->brandConfidence = @$result["vehicle"]['make'][0]['confidence'];
        $application->carInfo->color = @ucfirst($result["vehicle"]['color'][0]['name']);
        $application->carInfo->colorConfidence = @$result["vehicle"]['color'][0]['confidence'];
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

    $secretKey = (intval(date('s')) % 2)? // mixing two API keys
        "684f9f53e7e96cd36e18ec2ff9c91a4a49e034fc": // ud@nieradka.net
        "8fdb8b5ce4201462e109c0cc2858e34e1b5e39d6"; // e@nieradka.net


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

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
function get_alpr(&$imageBytes){
    global $cache;
    $imageHash = sha1($imageBytes);
    $result = $cache->get("_alpr-$imageHash");
    if($result){
        logger("get_alpr cache-hit $imageHash");
        return $result;
    }
    logger("get_alpr cache-miss $imageHash");

	$apiInstance = new Swagger\Client\Api\DefaultApi();
    $secretKey = (intval(date('s')) % 2)? // mixing two API keys
        "sk_0bcc0e58dab1ea40c4389d70": // SZN key
        "sk_aa0b80a70b2ae2268b36734a"; // KS key

	try {
		$alpr = $apiInstance->recognizeBytes($imageBytes, $secretKey,
            "eu", // country
            1, // recognize_vehicle
            "", // state
            0, // return_image
            1, // topn
            "" // prewarp
        );
        $cache->set("_alpr-$imageHash", $alpr, MEMCACHE_COMPRESSED, 0);
        return $alpr;
	} catch (Exception $e) {
		logger('Exception when calling DefaultApi->recognizeBytes: ' . $e->getMessage(), true);
		return null;
	}
}

function _check_alpr_budget($used, $total){
    if((float)$used / (float)$total > 0.9){
        logger("$used credits out of $total used!");
		  if (((int)$used % 10) == 0) {
			_sendSlackError("$used credits out of $total used!");
		  }
    }
}

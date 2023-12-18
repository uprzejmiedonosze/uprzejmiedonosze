<?PHP

$cache = new Memcache;
$cache->connect('localhost', 11211);

function cmp_alpr($left, $right){
  if($left['confidence'] > $right['confidence']) return -1;
  if($left['confidence'] < $right['confidence']) return 1;
  return 0;
}

function _check_alpr_cache($imageHash) {
    global $cache;
    $result = $cache->get("_alpr-$imageHash");
    if($result){
        logger("get_alpr cache-hit $imageHash");
        unset($result['credits_monthly_used']);
        unset($result['credits_monthly_total']);
        return $result;
    }
    logger("get_alpr cache-miss $imageHash");
    return null;
}

/**
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
function get_car_info_alpr(&$imageBytes, &$application, $baseFileName, $type) {
    logger("get_car_info_alpr $baseFileName");
    $imageHash = sha1($imageBytes);
    $carInfo = _check_alpr_cache($imageHash);

    $application->alpr = 'paid';
    if(!$carInfo){
        // $carInfo = get_alpr_cli($application->carImage->url);
        // $application->alpr = 'opensource';
        $carInfo = get_alpr($imageBytes);
    }

    if(isset($carInfo) && count($carInfo["results"])){
        if(isset($carInfo['credits_monthly_used'])) {
            _check_alpr_budget($carInfo['credits_monthly_used'], $carInfo['credits_monthly_total']);
        }

        $result = (Array)$carInfo['results'];
        usort($result, "cmp_alpr");

        $result = $result[0];

        $coordinates = $result["coordinates"];
        $xes = Array();
        $yes = Array();
        foreach($coordinates as $coo){
            $xes[] = $coo['x'];
            $yes[] = $coo['y'];
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

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
function get_alpr(&$imageBytes){
    logger("  get_alpr");
    global $cache;
    $imageHash = sha1($imageBytes);
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

/**
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
function get_alpr_cli($imagePath) {
    logger("  get_alpr_cli");
    $response = shell_exec("alpr --country eu --topn 1 --json /var/www/%HOST%/$imagePath 2>/dev/null");
    $json = json_decode($response, true);
    if(!json_last_error() === JSON_ERROR_NONE){
        return null;
    }
    return $json;
}

function _check_alpr_budget($used, $total){
    global $cache;
    $budgetConsumed = (float)$used / (float)$total;
    $cache->set('alpr_budget_consumed', $budgetConsumed);
    if($budgetConsumed > 0.9){
        logger("$used credits out of $total used!");
		  if (((int)$used % 10) == 0) {
			_sendSlackError("$used credits out of $total used!");
		  }
    }
}

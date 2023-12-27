<?PHP
require_once(__DIR__ . '/include.php');
require(__DIR__ . '/openAlpr.php');
require(__DIR__ . '/plateRecognizer.php');

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
function get_car_info(&$imageBytes, &$application, $baseFileName, $type) {
    global $storage;

    $application->carImage = new stdClass();
    $application->carImage->url = "$baseFileName,$type.jpg";
    $application->carImage->thumb = "$baseFileName,$type,t.jpg";

    $application->carInfo = new stdClass();

    if (usePlaterecognizer()) {
        get_car_info_platerecognizer($imageBytes, $application, $baseFileName, $type);
    } else {
        get_car_info_alpr($imageBytes, $application, $baseFileName, $type);
    }
    
    if (isset($application->carInfo->plateId)) {
        $recydywa = $storage->getRecydywa($application->carInfo->plateId);
        $application->carInfo->recydywa = $recydywa;
    }
}

function usePlaterecognizer() {
    global $cache;
    $budgetConsumed = $cache->get('alpr_budget_consumed');
    $budgetConsumed = floor($budgetConsumed*100);
    $swithToPlaterec = false;
    if($budgetConsumed > 90) {
        $swithToPlaterec = (bool)(intval(date('s')) % 10); // 90% hits
        logger("budgetConsumed $budgetConsumed% > 90%" . ($swithToPlaterec ? ' using PR' : ''), true);
    }

    return $swithToPlaterec;
}
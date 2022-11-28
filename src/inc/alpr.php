<?PHP
require_once(__DIR__ . '/include.php');
require(__DIR__ . '/openAlpr.php');

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

    get_car_info_alpr($imageBytes, $application, $baseFileName, $type);
    
    if (isset($application->carInfo->plateId)) {
        $recydywa = $storage->getRecydywa($application->carInfo->plateId);
        $application->carInfo->recydywa = $recydywa;
    }
}

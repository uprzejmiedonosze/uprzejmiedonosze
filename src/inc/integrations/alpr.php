<?PHP namespace alpr;

use app\Application;
use stdClass;
use cache\Type;

require(__DIR__ . '/openAlpr.php');
require(__DIR__ . '/plateRecognizer.php');

/**
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
function get_car_info(&$imageBytes, Application &$application, string $baseFileName, string $type) {
    $application->carImage = new stdClass();
    $application->carImage->url = "$baseFileName,$type.jpg";
    $application->carImage->thumb = "$baseFileName,$type,t.jpg";

    $application->carInfo = new stdClass();

    $usePlaterecognizer = usePlaterecognizer();
    try {
        if ($usePlaterecognizer)
            get_car_info_platerecognizer($imageBytes, $application, $baseFileName, $type);
        else
            get_car_info_alpr($imageBytes, $application, $baseFileName, $type);
    } catch (\Exception $e) {
        logger("Exception on get_car_info, 1st attepmt with usePlaterecognizer=$usePlaterecognizer " . $e->getMessage(), true);
        $usePlaterecognizer = !$usePlaterecognizer;
        if ($usePlaterecognizer)
            get_car_info_platerecognizer($imageBytes, $application, $baseFileName, $type);
        else
            get_car_info_alpr($imageBytes, $application, $baseFileName, $type);
    }

    if (isset($application->carInfo->plateId)) {
        $recydywa = \recydywa\get($application->carInfo->plateId);
        $application->carInfo->recydywa = $recydywa;
    }
}

function usePlaterecognizer(): bool {
    $user = \user\current();

    if(!$user->hasApps()) {
        logger('use OpenAlpr if this is User first app', true);
        return false;
    }

    if($user->isPatron()) {
        logger('use OpenAlpr for Patrons', true);
        return false;
    }
    
    $budgetConsumed = \cache\get(Type::AlprBudgetConsumed);
    $budgetConsumed = floor($budgetConsumed*100);

    if(floor(log10(random_int(1, $budgetConsumed+1))) == 0) {
        logger("use OpenAlpr budgetConsumed $budgetConsumed%", true);
        return true;
    }
    logger("use plateRec budgetConsumed $budgetConsumed%", true);
    return false;
}

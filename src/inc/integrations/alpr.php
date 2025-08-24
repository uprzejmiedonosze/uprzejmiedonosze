<?PHP namespace alpr;

use app\Application;
use stdClass;
use cache\Type;

require(__DIR__ . '/openAlpr.php');
require(__DIR__ . '/plateRecognizer.php');

/**
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
function get(&$imageBytes, Application &$application, string $baseFileName, string $type) {
    $application->carImage = new stdClass();
    $application->carImage->url = "$baseFileName,$type.jpg";
    $application->carImage->thumb = "$baseFileName,$type,t.jpg";

    $application->carInfo = new stdClass();

    $use_openAlpr = _use_openAlpr($imageBytes);
    try {
        if ($use_openAlpr)
            get_car_info_alpr($imageBytes, $application, $baseFileName, $type);
        else
            get_car_info_platerecognizer($imageBytes, $application, $baseFileName, $type);
            
    } catch (\Exception $e) {
        logger("Exception on alpr\get, 1st attepmt with _use_openAlpr=$use_openAlpr " . $e->getMessage(), true);
        if ($use_openAlpr) // do the opposite
            get_car_info_platerecognizer($imageBytes, $application, $baseFileName, $type);
        else
            get_car_info_alpr($imageBytes, $application, $baseFileName, $type);
    }
}

function _use_openAlpr(&$imageBytes): bool {
    $imageHash = sha1($imageBytes);
    $cache = \cache\alpr\get(Type::OpenAlpr, $imageHash);

    if($cache) {
        logger('use OpenAlpr cos its cached');
        return true;
    }

    $budgetConsumed = \cache\get(Type::AlprBudgetConsumed);
    if ($budgetConsumed === false) {
        logger('use plateRec as OpenAlpr budget is unknown!', true);
        return false;
    }
    $budgetConsumed = floor($budgetConsumed*100);

    if ($budgetConsumed == 100) {
        logger('use plateRec as OpenAlpr budget is consumed', true);
        return false;
    }

    $user = \user\current();

    if(!$user->hasApps()) {
        logger('use OpenAlpr if this is User first app');
        return true;
    }

    if($user->isPatron()) {
        logger('use OpenAlpr for Patrons');
        return true;
    }

    if(floor(log10(random_int(1, $budgetConsumed+1))) == 0) {
        logger("use OpenAlpr budgetConsumed $budgetConsumed%");
        return true;
    }
    logger("use plateRec budgetConsumed $budgetConsumed%");
    return false;
}
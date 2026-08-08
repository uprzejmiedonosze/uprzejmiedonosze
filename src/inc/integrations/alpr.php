<?PHP namespace alpr;

use app\Application;
use stdClass;
use cache\Type;
use user\User;

require(__DIR__ . '/openAlpr.php');
require(__DIR__ . '/plateRecognizer.php');

/**
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
function get(&$imageBytes, Application &$application, string $baseFileName, string $type, ?User $user = null) {
    $application->carImage = new stdClass();
    $application->carImage->url = "$baseFileName,$type.jpg";
    $application->carImage->thumb = "$baseFileName,$type,t.jpg";

    $application->carInfo = new stdClass();

    $use_openAlpr = _use_openAlpr($imageBytes, $user);
    try {
        if ($use_openAlpr)
            get_car_info_alpr($imageBytes, $application, $baseFileName, $type);
        else
            get_car_info_platerecognizer($imageBytes, $application, $baseFileName, $type);

    } catch (\Exception $e) {
        $msg = "Exception on alpr\\get, 1st attepmt with _use_openAlpr=$use_openAlpr " . $e->getMessage();

        $isAlprOverBudged = $use_openAlpr && $e instanceof \Swagger\Client\ApiException && $e->getCode() == 402;
        if (!$isAlprOverBudged) {
            logger($msg, true);
            \telemetry\log('app_error', $application->id, ['msg' => $msg, 'source' => 'alpr::get']);
        } else {
            logger($msg);
        }

        if ($use_openAlpr) // do the opposite
            get_car_info_platerecognizer($imageBytes, $application, $baseFileName, $type);
        else
            get_car_info_alpr($imageBytes, $application, $baseFileName, $type);
    }
}

function _use_openAlpr(&$imageBytes, ?User $user = null): bool {
    $imageHash = sha1($imageBytes);
    $cache = \cache\alpr\get(Type::OpenAlpr, $imageHash);

    if($cache) {
        logger('use OpenAlpr cos its cached');
        return true;
    }

    // Explicit user wins (e.g. the MCP identity); otherwise fall back to the
    // session user. \user\current() is cached at module load, which in a
    // sessionless MCP request predates the session bridge — passing the real
    // user keeps premium/patron routing identical to the web.
    $user = $user ?? \user\current();
    $isPremium = !$user->hasApps() || $user->isPatron();

    if (!$isPremium) {
        return false;
    }

    $budgetConsumed = \cache\get(Type::AlprBudgetConsumed);
    if ($budgetConsumed === false) {
        logger('OpenAlpr budget is unknown, allowing use for premium user to refresh cache', true);
        return true;
    }

    $budgetConsumedPercent = floor($budgetConsumed*100);
    if ($budgetConsumedPercent >= 100) {
        logger('use plateRec as OpenAlpr budget is consumed', true);
        return false;
    }

    if (!$user->hasApps()) {
        logger('use OpenAlpr if this is User first app');
    } elseif ($user->isPatron()) {
        logger('use OpenAlpr for Patrons');
    }

    return true;
}

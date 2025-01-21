<?PHP namespace queue;

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/../inc/include.php');

if (!isset($argv[1]))
    die("Usage: php face-detect-publisher.php <plateId>\n");

$cleanPlateId = \recydywa\cleanPlateId($argv[1]);
$apps = \app\byPlate($cleanPlateId);

foreach($apps as $app)
    \queue\produce($app->id);

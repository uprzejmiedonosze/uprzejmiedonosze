<?PHP namespace queue;

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/../inc/include.php');
require_once(__DIR__ . '/../inc/integrations/curl.php');

logger("Starting face-blur-consumer...", true);

$consumer = function (string $appId): void {
  try {
    $application = \app\get($appId);
    $filename = ROOT . $application->contextImage->url;
    $url = "http://localhost:2000/detect/$filename";
    $faces = \curl\request($url, [], "FaceRecogniton");
    $application->faces = $faces;
    \app\save($application);
    sleep(5);
  } catch (\Exception $e) {
    logger("ERROR: Failed detect face in $appId " . $e->getMessage(), true);
  }
};

consume($consumer);

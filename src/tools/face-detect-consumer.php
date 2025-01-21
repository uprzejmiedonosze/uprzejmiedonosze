<?PHP namespace queue;

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/../inc/include.php');
require_once(__DIR__ . '/../inc/integrations/curl.php');

logger("Starting face-blur-consumer...", true);

$consumer = function (string $appId): void {
  try {
    $app = \app\get($appId);
    if (isset($app->faces->count)) {
      logger("Faces already detected in $appId");
      return;
    }

    $filename = ROOT . $app->contextImage->url;
    $url = "http://localhost:2000/detect/$filename";
    $faces = \curl\request($url, [], "FaceRecogniton");
    $app->faces = $faces;
    \app\save($app);

    logger("Detected faces in $appId: " . ($faces->count ?? 0)); 
    sleep(5);
  } catch (\Exception $e) {
    logger("ERROR: Failed detect face in $appId " . $e->getMessage(), true);
  }
};

consume($consumer);

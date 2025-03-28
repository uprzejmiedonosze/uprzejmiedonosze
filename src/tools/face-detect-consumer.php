<?PHP namespace queue;

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/../inc/include.php');
require_once(__DIR__ . '/../inc/integrations/curl.php');
require_once(__DIR__ . '/../inc/integrations/Tumblr.php');

logger("Starting face-blur-consumer...", true);

$consumer = function (string $appId): void {
  try {
    $app = \app\get($appId);
    if (isset($app->faces->count)) {
      logger("Faces already detected in $appId");
      addToGallery($app);
      return;
    }

    $filename = ROOT . $app->contextImage->url;
    $url = "http://localhost:2000/detect/$filename";
    $faces = new \JSONObject(\curl\request($url, [], "FaceRecognition"));

    try {
      \semaphore\acquire($appId, "face-detect-consumer");
      $app = \app\get($appId);
      $app->faces = $faces;
      $facesCount = $faces->count ?? 0;

      if ($facesCount == 0) {
        logger("no facces, adding to gallery $appId");
        addToGallery($app);
        return;
      }

      $app->addComment("admin", "Wykryto " . num($facesCount, ['twarzy', 'twarz', 'twarze']) . " na zdjęciu.");      
    } finally {
      \app\save($app);
      \semaphore\release($appId, "face-detect-consumer");
    }

    logger("Detected faces in $appId: " . ($faces->count ?? 0)); 
    sleep(5);
  } catch (\Exception $e) {
    $plateId = $app->carInfo->plateId ?? '[plateId]';
    logger("ERROR: Failed detect face in $appId ($plateId) " . $e->getMessage(), true);
    sleep(30);
  }
};

function addToGallery(\app\Application &$app): void {
  if (isset($app->addedToGallery)) return;
  if ($app->faces->count ?? 0 > 0) return;
  if (!$app->canImageBeShown(whoIsWathing:null)) return;
  
  $app->addedToGallery = \addToTumblr($app);
  $app->addComment("admin", "Zdjęcie dodane do galerii.");
}

consume($consumer);

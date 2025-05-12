<?PHP namespace queue;

use JSONObject;

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
        $app = addToGallery($app);
      } else {
        $app->addComment("admin", "Wykryto " . num($facesCount, ['twarzy', 'twarz', 'twarze']) . " na zdjęciu.");
      }
    } finally {
      \app\save($app);
      \semaphore\release($appId, "face-detect-consumer");
      logger("app saved, semaphore released $appId: " . json_encode($app->addedToGallery ?? null), true);
    }
    logger("Detected faces in $appId: " . ($faces->count ?? 0)); 
    sleep(5);
  } catch (\Exception $e) {
    $plateId = $app->carInfo->plateId ?? '[plateId]';

    $message = $e->getMessage();

    if (strpos($message, 'photo upload limit for today') !== false) {
      logger("Warning: Tumblr upload limit reached $appId ($plateId)", true);
    } else {
      logger("ERROR: Failed detect face in $appId ($plateId) $message", true);
    }
    
    
    sleep(30);
  }
};

consume($consumer);

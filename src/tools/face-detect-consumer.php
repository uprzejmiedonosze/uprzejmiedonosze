<?PHP namespace queue;

use JSONObject;

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/../inc/include.php');
require_once(__DIR__ . '/../inc/integrations/curl.php');
require_once(__DIR__ . '/../inc/integrations/Tumblr.php');

log_info("Starting face-blur-consumer...", true);

$consumer = function (string $appId): void {
  try {
    $faceDetectorUrl = getenv('FACE_DETECTOR_URL') ?: 'http://localhost:2000';
    $app = \app\get($appId);

    $faces = null;
    if (!isset($app->faces->count)) {
      $url = "$faceDetectorUrl/detect/" . BASE_URL . $app->contextImage->url;

      // We fetch the faces BEFORE acquiring the lock to avoid blocking other processes
      // if the face detection is slow.
      $faces = new \JSONObject(\curl\request($url, [], "FaceRecognition"));
    }

    try {
      \semaphore\acquire($appId, "face-detect-consumer");
      $app = \app\get($appId);
      
      if (isset($app->faces->count)) {
        log_debug("Faces already detected in $appId");
        $app = addToGallery($app);
      } else {
        $app->faces = $faces;
        $facesCount = $faces->count ?? 0;

        if ($facesCount == 0) {
          log_debug("no facces, adding to gallery $appId");
          $app = addToGallery($app);
        } else {
          $app->addComment("admin", "Wykryto " . num($facesCount, ['twarzy', 'twarz', 'twarze']) . " na zdjęciu.");
        }
      }
      \app\save($app);
    } finally {
      \semaphore\release($appId, "face-detect-consumer");
    }
    log_debug("app saved, semaphore released $appId: " . json_encode($app->addedToGallery ?? null));
    log_debug("Detected faces in $appId: " . ($faces->count ?? 0));
    sleep(5);
  } catch (\Exception $e) {
    $plateId = $app->carInfo->plateId ?? '[plateId]';

    $message = $e->getMessage();

    if (strpos($message, 'photo upload limit for today') !== false) {
      log_info("Warning: Tumblr upload limit reached $appId ($plateId)", true);
    } else {
      log_error("Failed detect face in $appId ($plateId) $message", $e);
    }


    sleep(30);
  }
};

consume($consumer);

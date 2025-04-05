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
      logger("semaphore acquired($appId, face-detect-consumer)", true);
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
      logger("app saved, semaphore released($appId, face-detect-consumer)", true);
    }
    logger("Detected faces in $appId: " . ($faces->count ?? 0)); 
    sleep(5);
  } catch (\Exception $e) {
    $plateId = $app->carInfo->plateId ?? '[plateId]';
    logger("ERROR: Failed detect face in $appId ($plateId) " . $e->getMessage(), true);
    sleep(30);
  }
};

function addToGallery(\app\Application $app): \app\Application {
  $canImageBeShown = $app->canImageBeShown(whoIsWathing:null);
  $facesCount = $app->faces->count ?? 0;
  $alreadyInGallery = isset($app->addedToGallery);
  logger("addToGallery faces:$facesCount canImageBeShown: $canImageBeShown alreadyInGallery:$alreadyInGallery", true);
  
  if ($alreadyInGallery) return $app;
  if ($facesCount > 0) return $app;
  if (!$canImageBeShown) return $app;
  $app->addedToGallery = \addToTumblr($app);
  logger("https://galeria.uprzejmiedonosze.net/post/" . $app->addedToGallery->id, true);

  $app->addComment("admin", "Zdjęcie dodane do galerii.");
  return $app;
}

consume($consumer);

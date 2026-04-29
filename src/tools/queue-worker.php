<?PHP namespace queue;

use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Filters\Video\ResizeFilter;
use JSONObject;

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/../inc/include.php');
require_once(__DIR__ . '/../inc/integrations/curl.php');
require_once(__DIR__ . '/../inc/integrations/Tumblr.php');

logger("Starting unified queue-worker...", true);

/**
 * Checks system load and waits if it's too high.
 */
function checkLoad(string $appId): void {
    $maxLoad = (int) shell_exec("nproc") ?: 4;
    while (($load = sys_getloadavg()[0]) > $maxLoad) {
        logger("CPU load too high ($load > $maxLoad), waiting to process $appId...", true);
        sleep(10);
    }
}

function processFaceDetect(string $appId): void {
    try {
        $app = \app\get($appId);
        if (isset($app->faces->count)) {
            logger("Faces already detected in $appId");
            $app = addToGallery($app);
            \app\save($app);
            return;
        }

        $url = "http://localhost:2000/detect/" . BASE_URL . $app->contextImage->url;
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
    }

    return;
}

function processVideo(array $data): void {
    $appId = $data['appId'];
    $tempKey = $data['tempKey'];
    $baseDir = $data['baseDir']; // Passed from Session (ApiHandler)
    $tempPath = ROOT . $tempKey;

    if (!file_exists($tempPath)) {
        logger("Temporary video file not found: $tempPath", true);
        return;
    }

    try {
        checkLoad($appId);

        $ffmpeg = FFMpeg::create([
            'timeout' => 300,
            'ffmpeg.threads' => 4,
        ]);

        $video = $ffmpeg->open($tempPath);
        
        // 1. Generate thumbnail
        $thumbKey = "{$baseDir}/{$appId},v,t.jpg";
        $thumbPath = ROOT . $thumbKey;
        $video->frame(TimeCode::fromSeconds(0))->save($thumbPath);

        $width = 0;
        $height = 0;
        if (file_exists($thumbPath)) {
            list($width, $height) = getimagesize($thumbPath);
        }

        \semaphore\acquire($appId, "process-video");
        $app = \app\get($appId);
        $app->thirdImage = new \JSONObject();
        $app->thirdImage->thumb = $thumbKey;
        $app->thirdImage->width = $width;
        $app->thirdImage->height = $height;
        \app\save($app);
        \semaphore\release($appId, "process-video");


        // 2. Transcode video
        $videoKey = "{$baseDir}/{$appId},v.mp4";
        $videoPath = ROOT . $videoKey;

        $format = new X264();
        $format->setKiloBitrate(800)
               ->setAudioChannels(1)
               ->setAudioKiloBitrate(128);

        $video->filters()
              ->resize(new Dimension(1280, 720), ResizeFilter::RESIZEMODE_INSET)
              ->clip(TimeCode::fromSeconds(0), TimeCode::fromSeconds(60))
              ->synchronize();

        $video->save($format, $videoPath);

        // 3. Upload to S3
        if (\storage\isEnabled()) {
            \storage\upload($thumbPath, $thumbKey);
            \storage\upload($videoPath, $videoKey);
            @unlink($thumbPath);
            @unlink($videoPath);
        }


        \semaphore\acquire($appId, "process-video");
        $app = \app\get($appId);
        $app->thirdImage->url = $videoKey;
        $app->thirdImage->type = 'video';
        \app\save($app);
        \semaphore\release($appId, "process-video");
        logger("Video processed successfully for $appId", true);

    } catch (\Exception $e) {
        logger("ERROR: Failed to process video for $appId: " . $e->getMessage(), true);
        throw $e;
        
    } finally {
        @unlink($tempPath);
    }
}

$consumer = function (string $msg): void {
    $msg = trim($msg);
    if (empty($msg)) return;

    $data = json_decode($msg, true);
    if ($data && isset($data['type'])) {
        if ($data['type'] === 'video') {
            processVideo($data);
        } elseif ($data['type'] === 'face-detect') {
            processFaceDetect($data['appId']);
        } else {
            logger("Unknown task type in queue: " . $data['type']);
        }
    } else {
        // Fallback for simple appId messages (face detect)
        if (preg_match('/^[a-zA-Z0-9]+$/', $msg)) {
            processFaceDetect($msg);
        } else {
            logger("Skipping invalid task in queue: " . substr($msg, 0, 50));
        }
    }
};

consume($consumer);

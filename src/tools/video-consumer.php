<?PHP namespace queue;

use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Filters\Video\ResizeFilter;
use JSONObject;

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/../inc/include.php');

logger("Starting video-consumer...", true);

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

$consumer = function (string $msg): void {
    $data = json_decode($msg, true);
    if (($data['type'] ?? '') !== 'video') {
        return;
    }

    $appId = $data['appId'];
    $tempKey = $data['tempKey'];
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
        
        $userNumber = \app\get($appId)->getUserNumber();
        $baseDir = \storage\cdnPrefix() . '/' . $userNumber;
        
        // 1. Generate thumbnail
        $thumbKey = "{$baseDir}/{$appId},v,t.jpg";
        $thumbPath = ROOT . $thumbKey;
        $video->frame(TimeCode::fromSeconds(0))->save($thumbPath);

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

        // 4. Update Application
        try {
            \semaphore\acquire($appId, "video-consumer");
            $app = \app\get($appId);
            $app->videoUrl = $videoKey;
            $app->thirdImage = new \stdClass();
            $app->thirdImage->url = $thumbKey; // We use thumb as URL for consistency in PDF
            $app->thirdImage->thumb = $thumbKey;
            
            // Get dimensions of the thumbnail for the model
            if (file_exists($thumbPath)) {
                list($width, $height) = getimagesize($thumbPath);
                $app->thirdImage->width = $width;
                $app->thirdImage->height = $height;
            }
            
            \app\save($app);
        } finally {
            \semaphore\release($appId, "video-consumer");
        }

        @unlink($tempPath);
        logger("Video processed successfully for $appId", true);

    } catch (\Exception $e) {
        logger("ERROR: Failed to process video for $appId: " . $e->getMessage(), true);
        @unlink($tempPath);
    }
};

consume($consumer);

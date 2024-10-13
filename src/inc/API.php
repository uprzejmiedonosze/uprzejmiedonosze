<?PHP
require_once(__DIR__ . '/include.php');
require(__DIR__ . '/integrations/alpr.php');
require(__DIR__ . '/integrations/Geolocation.php');

use app\Application;
use \stdClass as stdClass;
use \DateTime as DateTime;
use \Exception as Exception;
use user\User;
use cache\Type;

/**
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
function updateApplication(
    $appId,
    $date,
    $dtFromPicture,
    int $category,
    $address,
    $plateId,
    $comment,
    $witness,
    $extensions,
    User $user,
): Application {

    $application = \app\get($appId);

    if ($application->user->email !== $user->getEmail()) {
        throw new ForbiddenException("Odmawiam aktualizacji zgłoszenia '$appId' przez '{$user->getEmail()}'");
    }

    if (!$application->isEditable()) {
        if ($application->status == 'confirmed-waiting') // Sentry UD-PHP-B3
            throw new Exception("Ponowna aktualizacja wysłanego zgłoszenia");
        throw new ForbiddenException("Zgłoszenie w stanie '{$application->status}' nie może być aktualizowane");
    }

    $application->date = date_format(new DateTime(preg_replace('/[^T0-9: -]/', '', $date)), DT_FORMAT);
    $application->dtFromPicture = (bool) $dtFromPicture;

    $application->category = $category;

    $application->address ??= new stdClass();
    $application->address->address = $address->address;
    $application->address->addressGPS = $address->addressGPS ?? null;
    $application->address->city = $address->city;
    $application->address->voivodeship = $address->voivodeship;
    $application->address->lat = $address->lat;
    $application->address->lng = $address->lng;
    $application->address->district = $address->district ?? null;
    $application->address->county = $address->county ?? null;
    $application->address->municipality = $address->municipality ?? null;
    $application->address->postcode = $address->postcode ?? null;

    $application->updateUserData($user);

    /** @var \SM|\StopAgresji $sm */
    $sm = $application->guessSMData(true); // stores sm city inside the object

    /** @var array<int, Category> $CATEGORIES */
    global $CATEGORIES;
    if ($CATEGORIES[$category]->isStopAgresjiOnly() && !$sm->isPolice()) {
        $application->stopAgresji = true;
        $application->guessSMData(true);
    }

    $application->carInfo ??= new stdClass();
    $application->carInfo->plateId = strtoupper(cleanWhiteChars($plateId));
    $application->userComment = capitalizeSentence($comment);
    $application->initStatements();
    $application->statements->witness = $witness;
    $application->extensions = [];
    if (!is_null($extensions)) {
        try {
            $application->extensions = array_map('intval', $extensions);
        } catch (Throwable $e) {
            $application->extensions = [];
        }
    }
    $application->setStatus("ready");

    \app\save($application);
    return $application;
}


/**
 * Sets application status.
 */
function setStatus(string $status, string $appId, User $user): Application {
    $application = \app\get($appId);
    $application->setStatus($status);
    \app\save($application);
    if (isset($application->carInfo->plateId))
        \recydywa\update($application->carInfo->plateId);
    $stats = \user\stats(false, $user); // update cache

    $patronite = $status == 'confirmed-fined' && $application->seq % 5 == 1;
    if (in_array('patron', $stats['badges'])) {
        $patronite = false;
    }
    $application->patronite = $patronite;
    return $application;
}

/**
 * Sends application to SM via API (if possible), and updates status.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function sendApplication(string $appId, User $user): Application {
    $application = \app\get($appId);
    CityAPI::checkApplication($application);
    $sm = $application->guessSMData();
    $api = new $sm->api;
    $application = $api->send($application);
    \user\stats(false, $user);
    return $application;
}

/**
 * Saves uploaded image + automatically create thumbnail + read plate data
 * for `carImage`.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
function uploadImage($application, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng) {
    $semKey = $application->user->number;
    $semaphore = sem_get($semKey, 1, 0666, 1);
    sem_acquire($semaphore);

    $type = substr($pictureType, 0, 2);
    $baseFileName = saveImgAndThumb($application, $imageBytes, $type);

    $fileName = "/var/www/%HOST%/$baseFileName,$type.jpg";
    list($width, $height) = getimagesize($fileName);

    if ($pictureType == 'carImage') {
        if (!empty($dateTime)) $application->date = $dateTime;
        if (!empty($dtFromPicture)) $application->dtFromPicture = $dtFromPicture;
        if (!empty($latLng)) $application->setLatLng($latLng);
        \alpr\get_car_info($imageBytes, $application, $baseFileName, $type);
        $application->carImage->width = $width;
        $application->carImage->height = $height;
    } else if ($pictureType == 'contextImage') {
        $application->contextImage = new stdClass();
        $application->contextImage->url = "$baseFileName,$type.jpg";
        $application->contextImage->thumb = "$baseFileName,$type,t.jpg";
        $application->contextImage->width = $width;
        $application->contextImage->height = $height;
    } else {
        sem_release($semaphore);
        throw new Exception("Nieznany rodzaj zdjęcia '$pictureType' ($application->id)", 400);
    }

    \app\save($application);
    sem_release($semaphore);
    return $application;
}

/**
 * Saves byte_stream to `ROOT/userId/appId,type.jpg`
 * and it's thumb to    `ROOT/userId/appId,typet.jpg`
 *
 * Returns:
 *   $prefix
 */
function saveImgAndThumb($application, $imageBytes, $type) {
    $baseDir = 'cdn2/' . $application->getUserNumber();
    $baseFileName = $baseDir . '/' . $application->id;

    if (!file_exists('/var/www/%HOST%/' . $baseDir)) {
        mkdir('/var/www/%HOST%/' . $baseDir, 0755, true);
    }

    $fileName     = "/var/www/%HOST%/$baseFileName,$type.jpg";
    $thumbName    = "/var/www/%HOST%/$baseFileName,$type,t.jpg";
    $ifp = fopen($fileName, 'wb');
    if ($ifp === false) {
        throw new Exception("Can't open $fileName for write", 500);
    }

    if (fwrite($ifp, base64_decode($imageBytes)) === false) {
        throw new Exception("Can't write to $fileName", 500);
    }
    fclose($ifp);

    if (!imagejpeg(resize_image($fileName, 600, 600, false), $thumbName)) {
        logger("Wasn't able to write $fileName as thumb to $thumbName.", true);
    }
    return $baseFileName;
}

/**
 * Resizes given .jpg file data_stream.
 *
 * Returns:
 *   destination image link resource
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
function resize_image($file, $w, $h, $crop = FALSE) {
    list($width, $height) = getimagesize($file);
    $r = $width / $height;
    if ($crop) {
        if ($width > $height) {
            $width = ceil($width - ($width * abs($r - $w / $h)));
        } else {
            $height = ceil($height - ($height * abs($r - $w / $h)));
        }
        $newwidth = $w;
        $newheight = $h;
    } else {
        if ($w / $h > $r) {
            $newwidth = $h * $r;
            $newheight = $h;
        } else {
            $newheight = $w / $r;
            $newwidth = $w;
        }
    }
    $src = imagecreatefromjpeg($file);
    $dst = imagecreatetruecolor($newwidth, $newheight);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);

    return $dst;
}

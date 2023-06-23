<?PHP
require_once(__DIR__ . '/include.php');
require(__DIR__ . '/../autoload.php');
require(__DIR__ . '/alpr.php');

use \Application as Application;
use \Memcache as Memcache;
use \stdClass as stdClass;
use \finfo as finfo;

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function isAjax() {
    global $_SERVER;
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
function parseHeaders($headers) {
    $head = array();
    foreach ($headers as $k => $v) {
        $header = explode(':', $v, 2);
        if (isset($header[1])) {
            $head[trim($header[0])] = trim($header[1]);
            continue;
        }
        $head[] = $v;
        if (preg_match("#HTTP/[0-9\.]+\s+([0-9]+)#", $v, $out))
            $head['reponse_code'] = intval($out[1]);
    }
    return $head;
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function getParam($method, $paramName) {
    global $_GET, $_POST;
    $params = $_GET;
    if ($method === 'POST') {
        $params = $_POST;
    }
    if (!isset($params[$paramName]))
        raiseError("`$paramName` $method parameter is missing", 400);
    return $params[$paramName];
}

/**
 * Sets application status.
 */
function setStatus($status, $appId) {
    global $storage;
    $application = $storage->getApplication($appId);
    try {
        $application->setStatus($status);
    } catch (Exception $e) {
        raiseError($e, 500);
    }
    $storage->saveApplication($application);
    $storage->updateRecydywa($application->carInfo->plateId);
    $storage->getUserStats(false); // update cache

    $patronite = $status == 'confirmed-fined' && $application->seq % 5 == 1;

    echo json_encode(array(
        "status" => "OK",
        "patronite" => $patronite
    ));
}

/**
 * Sends application to SM via API (if possible), and updates status.
 * 
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 */
function sendApplication($appId) {
    global $storage;
    try {
        $application = $storage->getApplication($appId);
        $sm = $application->guessSMData();

        if (!$sm->api) {
            throw new Exception("SM " . $sm->city . " nie posiada API");
        }
        $api = new $sm->api;
        $newStatus = $api->send($application);
        _sendSlackOnNewApp($application);
    } catch (Exception $e) {
        raiseError($e, 500);
    }
    echo json_encode(array("status" => "$newStatus"));
}

/**
 * Returns app details JSON
 */
function getAppDetails($appId) {
    global $storage;
    try {
        $application = $storage->getApplication($appId);
    } catch (Exception $e) {
        raiseError($e, 404);
    }
    echo json_encode($application);
}

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
function geoToAddress($lat, $lng) {
    $cache = new Memcache;
    $cache->connect('localhost', 11211);

    $latlng = number_format((float) $lat, 4, '.', '') . ',' . number_format((float) $lng, 4, '.', '');

    $result = $cache->get("_geoToAddress-$latlng");
    if ($result) {
        logger("_geoToAddress cache-hit $latlng");
        echo json_encode($result);
        return;
    }
    logger("_geoToAddress cache-miss $latlng");

    $ch = curl_init("https://maps.googleapis.com/maps/api/geocode/json?latlng=$latlng&key=AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8&language=pl&result_type=street_address");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $output = curl_exec($ch);
    if (curl_errno($ch)) {
        logger("Nie udało się pobrać danych latlng: " . curl_error($ch));
        raiseError("Nie udało się pobrać odpowiedzi z serwerów GeoAPI: " . curl_error($ch), 500);
        curl_close($ch);
        return;
    }
    curl_close($ch);

    $json = json_decode($output, true);
    if (!json_last_error() === JSON_ERROR_NONE) {
        logger("Parsowanie JSON z Google Maps APIS " . $output . " " . json_last_error_msg());
        raiseError("Bełkotliwa odpowiedź z serwerów GeoAPI: " . $output, 500);
        return;
    }
    if ($json['status'] == 'OK' && $json['results']) {
        $result = $json['results'][0];
        $cache->set("_geoToAddress-$latlng", $result, MEMCACHE_COMPRESSED, 0);
        echo json_encode($result);
        return;
    }
    if ($json['status'] == 'ZERO_RESULTS') {
        raiseError("Brak wyników z serwerów GeoAPI dla $lat, $lng: $output", 404, false);
    }
    raiseError("Niepoprawna odpowiedź z serwerów GeoAPI: " . $output, 500);
}

function addToGallery($appId) {
    global $storage;

    $application = $storage->getApplication($appId);
    if (!$application->isCurrentUserOwner()) {
        raiseError("Próba zmiany cudzego zgłoszenia $appId!", 401);
    }

    $application->initStatements();
    $application->statements->gallery = date(DT_FORMAT);
    $storage->saveApplication($application);

    echo json_encode(array("status" => "OK"));
}

/**
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
function moderateApp($appId, $decision) {
    require __DIR__ . '/../../inc/Tumblr.php';
    global $storage;
    if (!isAdmin()) {
        raiseError("Dostęp zabroniony", 401);
    }

    $application = $storage->getApplication($appId);

    if ($decision == 'true') {
        try {
            $application->addedToGallery = addToTumblr($application);
            $application->addComment('admin', "Zdjęcie dodane do galerii.");
        } catch (Exception $ex) {
            $application->addedToGallery = null;
            raiseError("Błąd Tumblr " . print_r($ex, true), 500);
        }
    } else {
        $application->addedToGallery = false;
    }

    $storage->saveApplication($application);
    echo json_encode(array("status" => "OK"));
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function uploadedFileToBase64() {
    global $_FILES;
    try {
        if (!isset($_FILES['image']['error']) || is_array($_FILES['image']['error'])) {
            raiseError($_FILES['image']['error'], 400);
            // 415 Unsupported Media Type
            // 400 Bad Request
        }

        if ($_FILES['image']['size'] > 500000) {
            raiseError("Image too big", 413);
        }

        // DO NOT TRUST $_FILES['uploaded_file']['mime'] VALUE !!
        // Check MIME Type by yourself.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $ext = array_search(
            $finfo->file($_FILES['image']['tmp_name']),
            array('jpg' => 'image/jpeg', 'png' => 'image/png'),
            true);
        if (false === $ext) {
            raiseError("File type $ext is not supported", 415);
        }

        $data = file_get_contents($_FILES['image']['tmp_name']);
        return base64_encode($data);
    } catch (Exception $e) {
        raiseError($e, 500);
    }
}


/**
 * Saves uploaded image + automatically create thumbnail + read plate data
 * for `carImage`.
 * 
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
function uploadImage($appId, $pictureType, $imageBytes) {
    global $storage;

    $semKey = $storage->getCurrentUser()->getNumber();
    $semaphore = sem_get($semKey, 1, 0666, 1);
    sem_acquire($semaphore);

    try {
        $application = $storage->getApplication($appId);
    } catch (Exception $e) {
        $application = new Application();
        $storage->saveApplication($application);
    }

    $type = substr($pictureType, 0, 2);
    $baseFileName = saveImgAndThumb($application, $imageBytes, $type);

    if ($pictureType == 'carImage') {
        get_car_info($imageBytes, $application, $baseFileName, $type);
    } else if ($pictureType == 'contextImage') {
        $application->contextImage = new stdClass();
        $application->contextImage->url = "$baseFileName,$type.jpg";
        $application->contextImage->thumb = "$baseFileName,$type,t.jpg";
    } else {
        sem_release($semaphore);
        raiseError("Unknown picture type: $pictureType ($appId)", 400);
    }

    $storage->saveApplication($application);
    sem_release($semaphore);
    echo json_encode($application);
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
    if (!file_exists('/var/www/%HOST%/' . $baseDir)) {
        mkdir('/var/www/%HOST%/' . $baseDir, 0755, true);
    }
    $baseFileName = $baseDir . '/' . $application->id;

    $fileName     = "/var/www/%HOST%/$baseFileName,$type.jpg";
    $thumbName    = "/var/www/%HOST%/$baseFileName,$type,t.jpg";
    $ifp = fopen($fileName, 'wb');
    if ($ifp === false) {
        raiseError("Can't open $fileName for write", 500);
    }

    if (fwrite($ifp, base64_decode($imageBytes)) === false) {
        raiseError("Can't write to $fileName", 500);
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

function initLogs() {
    logger("INIT %VERSION%", true);
}

function getAppByNumber($number, $apiToken) {
    global $storage;
    try {
        $application = $storage->getAppByNumber($number, $apiToken);
    } catch (Exception $e) {
        raiseError($e, 404);
    }
    echo json_encode($application);
}

function getUserByName($name, $apiToken) {
    global $storage;
    try {
        $user = $storage->getUserByName($name, $apiToken);
    } catch (Exception $e) {
        raiseError($e, 404);
    }
    echo json_encode($user);
}

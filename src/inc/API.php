<?PHP
require_once(__DIR__ . '/include.php');
require(__DIR__ . '/alpr.php');

use \Memcache as Memcache;
use \stdClass as stdClass;
use \DateTime as DateTime;
use \Exception as Exception;

$cache = new Memcache;
$cache->connect('localhost', 11211);

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

    global $storage;
    $application = $storage->getApplication($appId);

    if ($application->user->email !== $user->getEmail()) {
        throw new Exception("Odmawiam aktualizacji zgłoszenia '$appId' przez '{$user->getEmail()}'", 401);
    }

    if (!$application->isEditable()) {
        throw new Exception("Zgłoszenie w stanie '{$application->status}' nie może być aktualizowane", 401);
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

    $storage->saveApplication($application);
    return $application;
}


/**
 * Sets application status.
 */
function setStatus(string $status, string $appId, User $user): Application {
    global $storage;
    $application = $storage->getApplication($appId);
    $application->setStatus($status);
    $storage->saveApplication($application);
    if (isset($application->carInfo->plateId))
        $storage->updateRecydywa($application->carInfo->plateId);
    $stats = $storage->getUserStats(false, $user); // update cache

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
    global $storage;

    $application = $storage->getApplication($appId);
    CityAPI::checkApplication($application);
    $sm = $application->guessSMData();
    $api = new $sm->api;
    $application = $api->send($application);
    $storage->getUserStats(false, $user);
    return $application;
}

function addToGallery(string $appId): void {
    global $storage;

    $application = $storage->getApplication($appId);
    if (!$application->isCurrentUserOwner()) {
        throw new Exception("Próba zmiany cudzego zgłoszenia $appId!", 401);
    }

    $application->initStatements();
    $application->statements->gallery = date(DT_FORMAT);
    $storage->saveApplication($application);
}

/**
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
function moderateApp(User $user, string $appId, string $decision): void {
    require __DIR__ . '/Tumblr.php';
    global $storage;

    if (!$user->isModerator()) {
        throw new Exception("Dostęp zabroniony", 401);
    }
    $who = $user->isAdmin() ? 'admin' : 'moderator';
    $application = $storage->getApplication($appId);

    if ($decision == 'true') {
        try {
            $application->addedToGallery = addToTumblr($application);
            $application->addComment($who, "Zdjęcie dodane do galerii.");
        } catch (Exception $ex) {
            $application->addedToGallery = null;
            throw new Exception("Błąd Tumblr " . print_r($ex, true), 500, $ex);
        }
    } else {
        $application->addedToGallery = false;
    }

    $storage->saveApplication($application);
}

/**
 * Saves uploaded image + automatically create thumbnail + read plate data
 * for `carImage`.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
function uploadImage($application, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng) {
    global $storage;

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
        get_car_info($imageBytes, $application, $baseFileName, $type);
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

    $storage->saveApplication($application);
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

function normalizeGeo(float|string $geo): string {
    return sprintf('%.4F', $geo);
}

function normalizeLatLng($lat, $lng) {
    return normalizeGeo($lat) . "," . normalizeGeo($lng);
}

function checkCache(string $key): array|bool {
    global $cache;
    $result = $cache->get($key);
    if ($result) logger("geo cache-hit $key");
    else logger("geo cache-miss $key");
    return $result;
}

function setCache(string $key, array $value): void {
    global $cache;
    $cache->set($key, $value, MEMCACHE_COMPRESSED, 0);
}

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
function GoogleMaps($lat, $lng) {
    $lat = normalizeGeo($lat);
    $lng = normalizeGeo($lng);
    $prefix = "google-maps-v2";
    $result = checkCache("$prefix $lat,$lng");
    if ($result) return $result;

    $params = array(
        "latlng" => "$lat,$lng",
        "key" => "AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8",
        "language" => "pl",
        "result_type" => "street_address"
    );
    $url = "https://maps.googleapis.com/maps/api/geocode/json?";
    $json = curlRequest($url, $params, "Google Maps");

    if ($json['status'] == 'OK' && $json['results']) {
        $result = $json['results'][0];
        setCache("$prefix $lat,$lng", $result);
        return $result;
    }
    if ($json['status'] == 'ZERO_RESULTS') {
        throw new Exception("Brak wyników z serwerów Google Maps dla $lat,$lng: " . json_encode($json), 404);
    }
    throw new Exception("Niepoprawna odpowiedź z serwerów Google Maps: " . json_encode($json), 500);
}

/**
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 */
function Nominatim(float $lat, float $lng): array {
    $lat = normalizeGeo($lat);
    $lng = normalizeGeo($lng);
    $params = array(
        "lat" => $lat,
        "lon" => $lng,
        "format" => 'jsonv2',
        "addressdetails" => 1
    );
    $url = "https://nominatim.openstreetmap.org/reverse?";

    $prefix = "nominatim-v1";
    $json = checkCache("$prefix $lat,$lng");
    if (!$json) $json = curlRequest($url, $params, "Nominatim");

    if (!$json || !isset($json['address'])) {
        throw new Exception("Brak wyników z serwerów OpenStreetMap dla $lat,$lng " . json_encode($json), 404);
    }

    $address = $json['address'];

    if ($address["country_code"] !== "pl") {
        throw new Exception("Poza granicami kraju OpenStreetMap dla $lat,$lng {$address['country_code']}", 404);
    }

    $address['voivodeship'] = str_replace("województwo ", "", $address['state'] ?? "");
    unset($address['state']);

    $address['district'] = $address['suburb'] ?? $address['borough'] ?? $address['quarter'] ?? $address['neighbourhood'] ?? '';

    $address['city'] = $address['city'] ?? $address['town'] ?? $address['village'] ?? null;

    $county = $address['county'] ?? (($address['city']) ? "gmina {$address['city']}" : null);
    $municipality = $address['municipality'] ?? (($address['city']) ? "powiat {$address['city']}" : null);

    // nominantin can replace county and municipality...
    if (str_starts_with($county, 'powiat'))
        $address['municipality'] = $county;
    if (str_starts_with($municipality, 'powiat'))
        $address['municipality'] = $municipality;

    if (str_starts_with($county, 'gmina'))
        $address['county'] = $county;
    if (str_starts_with($municipality, 'gmina'))
        $address['county'] = $municipality;

    $address['address'] = trim(($address['road'] ?? '') . " " . ($address['house_number'] ?? '')) . ", " . ($address['city'] ?? '');

    global $SM_ADDRESSES;
    global $STOP_AGRESJI;

    $address['lat'] = $lat; // needed by StopAgresji::guess()
    $address['lng'] = $lng; // needed by StopAgresji::guess()

    setCache("$prefix $lat,$lng", $json);

    return array(
        'address' => $address,
        'sm' => $SM_ADDRESSES[SM::guess((object)$address)],
        'sa' => $STOP_AGRESJI[StopAgresji::guess((object)$address)]
    );
}

function MapBox(float $lat, float $lng): array {
    $lat = normalizeGeo($lat);
    $lng = normalizeGeo($lng);
    $prefix = "mapbox-v1";
    $properties = checkCache("$prefix $lat,$lng");
    if ($properties) return $properties;

    $params = array(
        "country" => 'pl',
        "limit" => 1,
        "types" => 'address,place,district,postcode,region,neighborhood',
        "language" => 'pl',
        "longitude" => $lng,
        "latitude" => $lat,
        "access_token" => 'pk.eyJ1IjoidXByemVqbWllZG9ub3N6ZXQiLCJhIjoiY2xxc2VkbWU3NGthZzJrcnExOWxocGx3bSJ9.r1y7A6C--2S2psvKDJcpZw'
    );
    $url = "https://api.mapbox.com/search/geocode/v6/reverse?";
    $json = curlRequest($url, $params, "MapBox");

    if (!$json || !isset($json['features']) || sizeof($json['features']) == 0) {
        throw new Exception("Brak wyników z serwerów MapBox dla $lat,$lng " . json_encode($json), 404);
    }
    $properties = reset($json['features'])['properties'];
    $properties['address'] = array();
    array_walk($properties['context'], function ($val, $key) use (&$properties) {
        $properties['address'][$key] = $val['name'];
    });

    $properties['address']['voivodeship'] = str_replace("województwo ", "", $properties['address']["region"] ?? "");
    unset($properties['address']['region']);

    $properties['address']['city'] = $properties['address']['place'] ?? "";
    unset($properties['address']['place']);

    $properties['address']['address'] = ($properties['address']['city']) ? "{$properties['name']}, {$properties['address']['city']}" : $properties['name'];

    unset($properties['coordinates']);
    unset($properties['bbox']);
    unset($properties['context']);

    setCache("$prefix $lat,$lng", $properties);
    return $properties;
}


/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
function curlRequest(string $url, array $params, string $vendor) {
    $ch = curl_init($url . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_REFERER, "https://uprzejmiedonosze.net");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Accept-Language: pl"]);
    $output = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        logger("Nie udało się pobrać danych z $vendor: $error");
        throw new Exception("Nie udało się pobrać odpowiedzi z serwerów $vendor: $error", 500);
    }
    curl_close($ch);

    $json = json_decode($output, true);
    //echo "$output\n\n";
    if (!json_last_error() === JSON_ERROR_NONE) {
        logger("Parsowanie JSON z $vendor " . $output . " " . json_last_error_msg());
        throw new Exception("Bełkotliwa odpowiedź z serwerów $vendor: $output", 500);
    }
    return $json;
}

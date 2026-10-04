<?PHP
require_once(__DIR__ . '/include.php');
require(__DIR__ . '/integrations/alpr.php');
require(__DIR__ . '/integrations/Geolocation.php');
require_once(__DIR__ . '/integrations/VehicleInfo.php');

use app\Application;
use Psr\Http\Message\ServerRequestInterface;
use \stdClass as stdClass;
use \DateTime as DateTime;
use \Exception as Exception;
use user\User;
use cache\Type;

// Must match MAX_IMAGE_DIM / JPEG_QUALITY on the client (src/js/new-app/images.js):
// the browser already resizes to these before upload; the server re-enforces them.
const MAX_IMAGE_DIM = 1600;
const JPEG_QUALITY = 85;

/** Image upload cap shared by the cookie (/api/app) and JWT (/api/rest/app) image endpoints. */
const MAX_IMAGE_UPLOAD_BYTES = 3 * 1_048_576;

/**
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
function updateApplication(
    Application $application,
    $date,
    $dtFromPicture,
    int $category,
    $address,
    $plateId,
    $comment,
    $witness,
    $extensions,
    User $user,
    ?bool $stopAgresji = null,
): Application {

    if ($application->email !== $user->getEmail()) {
        throw new ForbiddenException("Nie posiadasz zgłoszenia o ID {$application->id}");
    }

    if (!$application->isEditable()) {
        throw new ForbiddenException("Zgłoszenie '{$application->id}' w stanie '{$application->status}' nie może być aktualizowane");
    }

    if (!$application->hasRequiredImages()) {
        throw new NotSendableException("Zgłoszenie '{$application->id}' nie posiada wymaganych zdjęć");
    }

    // Server-side mirror of the web form checks (src/js/new-app/validate-form.js,
    // lib/validation.js), so every client (web, REST/mobile) gets the same rules.
    // The web edit-window (dtMin) is deliberately not enforced: edits of old reports are allowed.
    if (mb_strlen(cleanWhiteChars((string)$plateId)) < 3)
        throw new \ValidationException('plateId', 'Podaj numer rejestracyjny (min. 3 znaki)');
    if (empty(trim((string)($address->address ?? ''))))
        throw new \ValidationException('address', 'Podaj adres lub wskaż go na mapie');
    try {
        $dateParsed = new DateTime(preg_replace('/[^T0-9: -]/', '', (string)$date));
    } catch (\Exception $e) {
        throw new \ValidationException('datetime', 'Niepoprawna data i godzina zgłoszenia');
    }
    if ($dateParsed > (new DateTime())->modify('+5 minutes'))
        throw new \ValidationException('datetime', 'Data zgłoszenia nie może być z przyszłości');
    if ($category === 0 && empty(trim((string)$comment)))
        throw new \ValidationException('comment', 'Dla kategorii „inne” komentarz jest wymagany');

    $application->date = date_format($dateParsed, DT_FORMAT);
    $application->dtFromPicture = (bool) $dtFromPicture;

    $application->category = $category;

    $application->address ??= new stdClass();
    // A stored map screenshot (numbered apps keep reusing it) would show the
    // old location once the pin is moved.
    if (($application->address->lat ?? null) != $address->lat
        || ($application->address->lng ?? null) != $address->lng) {
        unset($application->address->mapImage);
    }
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

    if ($stopAgresji !== null) {
        $application->stopAgresji = $stopAgresji;
        // Persist a deliberate ad hoc choice to the account, so it's remembered
        // for future reports. Must happen before the stopAgresjiOnly forced
        // override below, so a forced flip never leaks into the saved preference.
        $user->setStopAgresjiPreference($stopAgresji);
        \user\save($user);
    }

    /** @var \SM|\StopAgresji $sm */
    $sm = $application->guessSMData(true); // stores sm city inside the object

    /** @var array<int, Category> $CATEGORIES */
    global $CATEGORIES;
    $application->stopAgresjiForced = false;
    if ($CATEGORIES[$category]->isStopAgresjiOnly() && !$sm->isPolice()) {
        $application->stopAgresji = true;
        $application->stopAgresjiForced = true;
        $application->guessSMData(true);
    }

    $application->carInfo ??= new stdClass();
    $application->carInfo->plateId = strtoupper(cleanWhiteChars($plateId));
    \vehicle_info\refresh($application);
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
    $application = \semaphore\withLock($appId, "setStatus", function () use ($appId, $status) {
        $application = \app\get($appId);
        $application->setStatus($status);
        return \app\save($application);
    });
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
 * @param callable|null $validate
 * @param User|null     $user     Overrides the session user used for ALPR
 *                                provider selection (e.g. the MCP identity).
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
function uploadImage(string $appId, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng, ?callable $validate = null, ?User $user = null) {
    return \semaphore\withLock($appId, "uploadImage:$pictureType", function () use ($appId, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng, $validate, $user) {
        $application = \app\get($appId);
        if ($validate) $validate($application);

        $type = substr($pictureType, 0, 2);
        $baseFileName = saveImgAndThumb($application, $imageBytes, $type);

        $fileName = ROOT . "$baseFileName,$type.jpg";
        list($width, $height) = getimagesize($fileName);

        if ($pictureType == 'carImage') {
            if (!empty($dateTime)) $application->date = $dateTime;
            if (!empty($dtFromPicture)) $application->dtFromPicture = $dtFromPicture;
            if (!empty($latLng)) $application->setLatLng($latLng);
            // ALPR measures its plate/vehicle boxes on the bytes it receives
            // but the crop is cut from the stored file — so it must see the
            // stored (≤1600px) bytes, not the original upload. The web uploads
            // pre-resized images so both are identical there; direct/API
            // uploads (up to 2 MB, any dimensions) would otherwise shift every
            // box by the downscale ratio.
            $alprBytes = @file_get_contents($fileName);
            if ($alprBytes === false) {
                $alprBytes = $imageBytes;
            }
            \alpr\get($alprBytes, $application, $baseFileName, $type, $user);
            \vehicle_info\refresh($application);
            $application->carImage->width = $width;
            $application->carImage->height = $height;
        } else if ($pictureType == 'contextImage') {
            $application->contextImage = new stdClass();
            $application->contextImage->url = "$baseFileName,$type.jpg";
            $application->contextImage->thumb = "$baseFileName,$type,t.jpg";
            $application->contextImage->width = $width;
            $application->contextImage->height = $height;
        } else if ($pictureType == 'thirdImage') {
            $application->thirdImage = new stdClass();
            $application->thirdImage->url = "$baseFileName,$type.jpg";
            $application->thirdImage->thumb = "$baseFileName,$type,t.jpg";
            $application->thirdImage->width = $width;
            $application->thirdImage->height = $height;
        } else {
            throw new Exception("Nieznany rodzaj zdjęcia '$pictureType' ($appId)", 400);
        }

        if ($pictureType === 'contextImage') {
            $application->generateGalleryImages();
        }

        \app\save($application);
        return $application;
    });
}

/**
 * Saves byte_stream to `ROOT/userId/appId,type.jpg`
 * and it's thumb to    `ROOT/userId/appId,typet.jpg`
 *
 * Returns:
 *   $prefix
 */
function saveImgAndThumb($application, $imageBytes, $type) {
    $baseDir = \storage\cdnPrefix() . '/' . $application->getUserNumber();
    $baseFileName = $baseDir . '/' . $application->id;

    if (!file_exists(ROOT . $baseDir)) {
        mkdir(ROOT . $baseDir, 0755, true);
    }

    $fileName     = ROOT . "$baseFileName,$type.jpg";
    $thumbName    = ROOT . "$baseFileName,$type,t.jpg";

    $rawBytes = $imageBytes;
    $info = getimagesizefromstring($rawBytes);
    if ($info === false) {
        throw new Exception("Przesłany plik nie jest obrazkiem", 400);
    }

    if ($info['mime'] === 'image/png') {
        $src = imagecreatefromstring($rawBytes);
        if ($src === false) {
            throw new Exception("Nie można otworzyć pliku PNG", 400);
        }
        $img = imagecreatetruecolor(imagesx($src), imagesy($src));
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagecopy($img, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
        imagedestroy($src);
    } elseif ($info['mime'] === 'image/jpeg') {
        $img = imagecreatefromstring($rawBytes);
        if ($img === false) {
            throw new Exception("Nie można otworzyć pliku JPEG", 400);
        }
    } else {
        throw new Exception("Nieobsługiwany format obrazka: {$info['mime']}", 415);
    }

    if (!imagejpeg($img, $fileName, JPEG_QUALITY)) {
        throw new Exception("Can't write to $fileName", 500);
    }
    imagedestroy($img);

    $fullSize = getimagesize($fileName);
    if ($fullSize[0] > MAX_IMAGE_DIM || $fullSize[1] > MAX_IMAGE_DIM) {
        imagejpeg(resize_image($fileName, MAX_IMAGE_DIM, MAX_IMAGE_DIM, false), $fileName, JPEG_QUALITY);
    }

    if (!imagejpeg(resize_image($fileName, 600, 600, false), $thumbName)) {
        $msg = "Wasn't able to write $fileName as thumb to $thumbName.";
        log_error($msg);
        \telemetry\log('app_error', $application->id, ['msg' => $msg, 'source' => 'API::saveImgAndThumb']);
        @unlink($fileName);
        throw new Exception("Nie udało się zapisać miniatury zdjęcia", 500);
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


/**
 * Parses an image upload request — shared by the cookie API
 * (SessionApiHandler::image, /api/app/{id}/image) and the JWT REST API
 * (POST /api/rest/app/{id}/image), so both accept exactly the same contract:
 *
 *  - multipart `image` file, or `image_data` (base64 data URI, legacy web client),
 *    or — legacy REST contract — a `carImage` / `contextImage` / `thirdImage`
 *    data-URI field, whose name doubles as the picture type;
 *  - `pictureType`: contextImage | carImage | thirdImage (required unless implied by the legacy field);
 *  - optional (carImage): `dateTime` ("2018-02-02T19:48:10"), `dtFromPicture` ("true"/true),
 *    `latLng` ("53.4,14.5") or `lat` + `lng`.
 *
 * @return array{bytes: string, pictureType: string, dateTime: ?string, dtFromPicture: ?bool, latLng: ?string}
 * @throws Exception (code 400) on a missing/oversized/undecodable file
 */
function imageUploadFromRequest(ServerRequestInterface $request): array {
    $params = (array)$request->getParsedBody();
    $uploadedFiles = $request->getUploadedFiles();
    $limitMb = MAX_IMAGE_UPLOAD_BYTES / 1_048_576;

    $pictureType = $params['pictureType'] ?? null;
    $bytes = null;

    if (isset($uploadedFiles['image'])) {
        $upload = $uploadedFiles['image'];
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new Exception("Błąd przesyłania pliku (kod {$upload->getError()})", 400);
        }
        // Reject by declared size before buffering the stream into memory.
        if ($upload->getSize() > MAX_IMAGE_UPLOAD_BYTES) {
            $actualMb = round($upload->getSize() / 1_048_576, 1);
            throw new Exception("Zbyt duże zdjęcie ({$actualMb}MB > {$limitMb}MB)", 400);
        }
        $bytes = $upload->getStream()->getContents();
    } else {
        // data-URI: `image_data` (old web client) or a legacy REST field named after the picture type.
        $dataUri = $params['image_data'] ?? null;
        if ($dataUri === null) {
            foreach (['carImage', 'contextImage', 'thirdImage'] as $slot) {
                if (isset($params[$slot])) {
                    $dataUri = $params[$slot];
                    $pictureType ??= $slot;
                    break;
                }
            }
        }
        if ($dataUri !== null) {
            $parts = explode(',', $dataUri, 2);
            $bytes = base64_decode(count($parts) === 2 ? $parts[1] : $parts[0], true);
        }
    }

    if ($bytes === null || $bytes === false || strlen($bytes) === 0) {
        throw new Exception("Brak pliku obrazka", 400);
    }
    if (strlen($bytes) > MAX_IMAGE_UPLOAD_BYTES) {
        $actualMb = round(strlen($bytes) / 1_048_576, 1);
        throw new Exception("Zbyt duże zdjęcie ({$actualMb}MB > {$limitMb}MB)", 400);
    }
    if ($pictureType === null) {
        throw new MissingParamException('pictureType');
    }

    $dateTime = $params['dateTime'] ?? null;
    $dtFromPicture = isset($params['dtFromPicture'])
        ? in_array($params['dtFromPicture'], ['true', true, '1', 1], true)
        : null;
    // The legacy REST contract implied dtFromPicture from the presence of dateTime.
    if ($dtFromPicture === null && !empty($dateTime) && !isset($params['pictureType'])) {
        $dtFromPicture = true;
    }
    $latLng = $params['latLng'] ?? null;
    if ($latLng === null && !empty($params['lat']) && !empty($params['lng'])) {
        $latLng = \geo\normalizeLatLng($params['lat'], $params['lng']);
    }

    return [
        'bytes' => $bytes,
        'pictureType' => $pictureType,
        'dateTime' => $dateTime,
        'dtFromPicture' => $dtFromPicture,
        'latLng' => $latLng,
    ];
}

/**
 * Removes one image slot (contextImage | carImage | thirdImage) from an
 * application: files on disk/storage, gallery copies, and the slot itself.
 * Shared by the cookie API (DELETE /api/app/{id}/image/{image}) and the JWT
 * REST API (DELETE /api/rest/app/{id}/image/{image}). Does NOT save.
 */
function removeApplicationImage(Application $app, string $imageId): Application {
    if (!in_array($imageId, ['contextImage', 'carImage', 'thirdImage'], true)) {
        throw new Exception("Nieznany rodzaj zdjęcia '$imageId'", 400);
    }

    $rmFile = function(string $fileName): void {
        $allowedBase = realpath(ROOT . 'cdn2');
        $file = realpath(ROOT . $fileName);
        if ($file && $allowedBase && str_starts_with($file, $allowedBase . '/')) {
            @unlink($file); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
        \storage\delete($fileName);
    };

    isset($app->$imageId->url) && $rmFile($app->$imageId->url);
    isset($app->$imageId->thumb) && $rmFile($app->$imageId->thumb);

    if ($imageId === 'contextImage' && ($app->contextImage->galleryReady ?? false)) {
        $thumb     = $app->contextImage->thumb;
        $prefix    = \storage\cdnPrefix();
        \storage\delete($prefix . '/gallery/' . \crypto\encode($thumb, CRYPTO_KEY, CRYPTO_IV) . '.jpg');
        \storage\delete($prefix . '/gallery/' . \crypto\encode("{$thumb}?pixelate", CRYPTO_KEY, CRYPTO_IV) . '.jpg');
    }

    unset($app->$imageId);
    return $app;
}


/**
 * Who the report will go to: shared by every REST response that returns an
 * application, so clients don't need the SM/Policja resolution logic
 * (web: _application-short-details.html.twig, nowe-zgloszenie `#unitToggle`).
 *
 * @return array{key: ?string, name: string, shortName: string, isPolice: bool, automated: bool, unknown: bool, stopAgresjiForced: bool}|null
 */
function recipientData(Application $application): ?array {
    try {
        $sm = $application->guessSMData();
        return [
            'key' => $application->smCity ?? null,
            'name' => $sm->getName(),
            'shortName' => $sm->getShortName(),
            'isPolice' => $sm->isPolice(),
            'automated' => $sm->automated(),
            'unknown' => $sm->unknown(),
            'stopAgresjiForced' => (bool)($application->stopAgresjiForced ?? false),
        ];
    } catch (\Throwable $e) {
        return null; // never break a listing because of an unresolvable SM
    }
}

/** Application as returned by the JWT REST API: raw JSON plus derived fields (`recipient`). */
function applicationToRest(Application $application): array {
    $data = $application->jsonSerialize();
    unset($data['browser']);
    $data['recipient'] = recipientData($application);
    return $data;
}

/**
 * "Potwierdź" step shared by the web (POST /app/done → ApplicationHandler::finish)
 * and the REST API (POST /api/rest/app/{id}/finish): status → confirmed (assigns
 * the report number) plus every side effect the web has always had — telemetry,
 * last location, apps counter, recidivism queue and stats cache. Doing these only
 * in one entry point made account/rank/recydywa drift between web and mobile.
 * Does NOT send the report (see sendApplication()).
 *
 * @return array{application: Application, edited: bool, changed: bool, appsCount: int, isPatron: bool}
 * @throws Exception 403 not an owner, 422 not saved via the confirm step / missing photos
 */
function finishApplication(string $appId, User $user): array {
    $result = \semaphore\withLock($appId, "finish", function () use ($appId, $user) {
        $application = \app\get($appId);
        if ($application->email !== $user->getEmail())
            throw new Exception("Nie posiadasz zgłoszenia o ID $appId", 403);
        if (!$application->isEditable()) // already finished (double submit)
            return [$application, false, false];
        if (!in_array($application->status, ['ready', 'confirmed'], true))
            throw new \ValidationException('status', "Zgłoszenie '$appId' nie zostało jeszcze zapisane (status '{$application->status}')");
        if (!$application->hasRequiredImages())
            throw new \ValidationException('images', "Zgłoszenie '$appId' nie posiada wymaganych zdjęć");

        $edited = $application->hasNumber();
        $application->setStatus("confirmed");
        return [\app\save($application), $edited, true]; // save also assigns the number
    });
    [$application, $edited, $changed] = $result;

    if (!$changed) {
        return ['application' => $application, 'edited' => false, 'changed' => false,
            'appsCount' => (int)$user->appsCount, 'isPatron' => $user->isPatron()];
    }

    \telemetry\log('report_finished', $application->id);

    $user->setLastLocation($application->getLatLng());
    $user->appsCount = $application->seq;
    \user\save($user);

    \recydywa\update($application->carInfo->plateId);
    \user\stats(false, $user); // update cache

    if ($edited) {
        $application->address->mapImage = null;
    }

    return ['application' => $application, 'edited' => $edited, 'changed' => true,
        'appsCount' => (int)$user->appsCount, 'isPatron' => $user->isPatron()];
}


/**
 * Everything the dashboard (web /app, app.html.twig) shows, already localized for the user's sex
 * (levels/badges texts via sexify() and SEXSTRINGS) so API clients just render it.
 *
 * @return array{name: string, stats: array, introMsg: string, levels: list<array>, rank: array, badges: list<array>}
 */
function dashboardData(User $user): array {
    global $LEVELS, $BADGES;
    $sex = $user->getSex();
    $stats = \user\stats(true, $user);
    $levelId = (string)($stats['level'] ?? 0);
    $earned = $stats['badges'] ?? [];

    $levels = [];
    foreach ($LEVELS as $id => $level)
        $levels[] = ['id' => (string)$id, 'desc' => $sex[$level->desc] ?? $level->desc, 'active' => (string)$id === $levelId];

    // Rank as "N of M" plus what is missing for the next one (levels.json `points` = threshold of the
    // drivers' penalty points collected by the user's reports), so clients don't need the level table.
    $ids = array_map('strval', array_keys($LEVELS));
    $pos = max(0, array_search($levelId, $ids, true) ?: 0);
    $points = (int)($stats['points'] ?? 0);
    $nextLevel = isset($ids[$pos + 1]) ? $LEVELS[$ids[$pos + 1]] ?? $LEVELS[(int)$ids[$pos + 1]] : null;
    $rank = [
        'index' => $pos + 1,
        'total' => count($ids),
        'desc' => $levels[$pos]['desc'] ?? '',
        'points' => $points,
        'next' => $nextLevel ? [
            'desc' => $sex[$nextLevel->desc] ?? $nextLevel->desc,
            'points' => $nextLevel->points,
            'missing' => max(0, $nextLevel->points - $points),
        ] : null,
    ];

    $badges = [];
    foreach ($BADGES as $id => $badge) {
        $former = $id === 'patron' && !in_array('patron', $earned, true) && in_array('former_patron', $earned, true);
        $badges[] = [
            'id' => $id,
            'name' => $former ? $sex['Była patronka'] : ($sex[$badge['name']] ?? $badge['name']),
            'desc' => $badge['desc'], // may contain links (HTML)
            'img' => $badge['img'] ?? null,
            'earned' => in_array($id, $earned, true),
            'former' => $former,
        ];
    }

    return [
        'name' => $user->getFirstName(),
        'stats' => $stats,
        'introMsg' => sexify($LEVELS[$levelId]->introMsg ?? '', $sex),
        'levels' => $levels,
        'rank' => $rank,
        'badges' => $badges,
    ];
}

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

// Must match MAX_IMAGE_DIM / JPEG_QUALITY on the clients:
//  - web:    src/js/new-app/images.js (JPEG_QUALITY 0.85, MAX_IMAGE_DIM 1600)
//  - mobile: ../uprzejmiedonosze-pro/src/lib/resize.ts + EXPO_PUBLIC_PHOTO_MAX_WIDTH / _COMPRESS (src/config.ts,
//    .env.example); the app uploads ONE downscaled file to POST /api/rest/photos (the `wip` stage, src/inc/WipPhotos.php);
//    analysis (vision/candidate) and slot assignment (POST /app/{id}/image with photoId) then read it from the server,
//    and ALPR runs once per photo (result kept next to the file, reused by assignPhoto()).
// The clients resize before upload; the server re-enforces the limits. Changing a value here? Update the clients too
// (a 1600px / 0.85 JPEG must stay under MAX_IMAGE_UPLOAD_BYTES; VISION_MAX_* in inc/config.php only limit the base64 variant of vision/candidate).
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
    // jak checkAddress() na webie: tekst adresu > 10 znaków, rozpoznana miejscowość i współrzędne (bez nich nie da się
    // ustalić SM/Policji ani odbiorcy)
    if (mb_strlen(trim((string)($address->address ?? ''))) <= 10)
        throw new \ValidationException('address', 'Podaj adres lub wskaż go na mapie');
    if (mb_strlen(trim((string)($address->city ?? ''))) <= 2
        || !is_numeric($address->lat ?? null) || !is_numeric($address->lng ?? null)
        || (float)$address->lat <= 0 || (float)$address->lng <= 0)
        throw new \ValidationException('address', 'Nie udało się ustalić miejsca zgłoszenia – wskaż je na mapie albo wpisz adres w formacie „Ulica 10, Miasto”');
    try {
        $dateParsed = new DateTime(preg_replace('/[^T0-9: -]/', '', (string)$date));
    } catch (\Exception $e) {
        throw new \ValidationException('datetime', 'Niepoprawna data i godzina zgłoszenia');
    }
    if ($dateParsed > (new DateTime())->modify('+5 minutes'))
        throw new \ValidationException('datetime', 'Data zgłoszenia nie może być z przyszłości');
    // jak checkDateTimeValue() na webie: wykroczenie starsze niż 7 miesięcy jest odrzucane, ale nie przy ponownym
    // zapisie niezmienionej daty (edycja starego zgłoszenia, patrz komentarz wyżej)
    if ($dateParsed < (new DateTime())->modify('-7 months') && date_format($dateParsed, DT_FORMAT) !== ($application->date ?? null))
        throw new \ValidationException('datetime', 'Wykroczenie starsze niż 7 miesięcy. SM/Policja nie zdąży zareagować!');
    // jak na webie (validation.js checkCommentvalue): automatyczna linia „Pojazd marki XXX.” nie liczy się jako opis
    $ownComment = trim(preg_replace('/^Pojazd (prawdopodobnie )?marki \w+[\s-]?\w*\.?/i', '', trim((string)$comment)));
    if ($category === 0 && mb_strlen($ownComment) <= 10)
        throw new \ValidationException('comment', 'Wybierz rodzaj wykroczenia z listy albo opisz je w polu komentarza');

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
    return finalizeImage($appId, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng, $validate, $user);
}

/**
 * Podmienia wycinek tablicy rejestracyjnej zgłoszenia (`carInfo->plateImage`, widoczny w formularzu, na stronie zgłoszenia i w PDF)
 * na wycinek przysłany przez klienta – mobilna aplikacja tnie go z oryginału zdjęcia w pełnej rozdzielczości, a serwer ma tylko
 * kopię ≤1600 px (stąd ~80 px szerokości). Wycinek serwerowy zostaje jako rezerwa, gdy klient niczego nie wyśle.
 * @throws Exception 403 nie właściciel / niedostępne do edycji, 409 brak zdjęcia auta, 400 to nie obrazek
 */
function replacePlateImage(string $appId, string $imageBytes, User $user): Application {
    return \semaphore\withLock($appId, "plateImage", function () use ($appId, $imageBytes, $user) {
        $application = \app\get($appId);
        if ($application->email !== $user->getEmail()) throw new Exception("Nie posiadasz zgłoszenia o ID $appId", 403);
        if (!$application->isEditable()) throw new Exception("Zgłoszenie $appId nie może być edytowane", 403);
        if (!isset($application->carImage->url)) throw new Exception('Zgłoszenie nie ma jeszcze zdjęcia auta', 409);

        $img = @imagecreatefromstring($imageBytes);
        if ($img === false) throw new Exception('Przesłany plik nie jest obrazkiem', 400);
        $w = imagesx($img);
        if ($w > 800) { // tablica na dokumencie nie potrzebuje więcej; chroni też przed przypadkowym wysłaniem całego zdjęcia
            $scaled = imagescale($img, 800);
            if ($scaled !== false) { imagedestroy($img); $img = $scaled; }
        }
        $path = $application->carInfo->plateImage ?? preg_replace('/,ca\.jpg$/', ',ca,p.jpg', $application->carImage->url);
        if (!imagejpeg($img, ROOT . $path, 92)) { imagedestroy($img); throw new Exception('Nie udało się zapisać wycinka tablicy', 500); }
        imagedestroy($img);

        $application->carInfo ??= new stdClass();
        $application->carInfo->plateImage = $path;
        return \app\save($application);
    });
}

/**
 * Zdjęcie z etapu `wip` (src/inc/WipPhotos.php) → slot zgłoszenia: skalowanie, miniatura, wycinek tablicy, dane auta.
 * Bajty czyta z dysku serwera (klient nie wysyła ich drugi raz), a ALPR bierze z sidecara (policzony raz przy analizie albo
 * tu, gdy zdjęcie nie było analizowane) – kolejne przydziały tego samego zdjęcia (zamiana ról) nie wołają ALPR ponownie.
 * `$crop = 'vehicle'` (tylko carImage): zamiast całego zdjęcia zapisujemy wycinek zwycięskiego pojazdu z ALPR + 20% marginesu
 * (jedno zdjęcie = kontekst + auto); wynik ALPR jest przesuwany do współrzędnych wycinka, więc ALPR nadal liczy się raz.
 * `$plate` (tylko carImage): tablica pojazdu wskazanego przez użytkownika, gdy na zdjęciach są dwa auta – carInfo i wycinek
 * dotyczą jego odczytu zamiast zwycięzcy zdjęcia (brak takiego odczytu → zwycięzca).
 * `wip` zostaje do zakończenia zgłoszenia (finishApplication) albo TTL; id zdjęć zapamiętujemy w `$application->wipPhotos`.
 */
function assignPhoto(string $appId, string $pictureType, string $photoId, User $user, ?callable $validate = null, ?string $crop = null, ?string $plate = null) {
    $photo = \wip\load($user, $photoId);
    if (!$photo) throw new Exception("Nie znaleziono zdjęcia '$photoId' (wygasło albo nie należy do użytkownika)", 404);
    if (!in_array($pictureType, ['carImage', 'contextImage', 'thirdImage'], true)) {
        throw new Exception("Nieznany rodzaj zdjęcia '$pictureType' ($appId)", 400);
    }
    $meta = $photo['meta'];
    $alpr = null;
    if ($pictureType === 'carImage') {
        try {
            $alpr = \wip\alpr($user, $photoId);
        } catch (\Throwable $e) {
            log_info("assignPhoto $appId: ALPR niedostępny dla wip $photoId – zwykła ścieżka: " . $e->getMessage());
        }
    }
    $latLng = (isset($meta['lat'], $meta['lng']) && $pictureType === 'carImage') ? \geo\normalizeLatLng($meta['lat'], $meta['lng']) : null;
    $dateTime = $pictureType === 'carImage' ? ($meta['dateTime'] ?? null) : null;
    if ($plate && $pictureType === 'carImage' && $alpr) $alpr = \alpr\onlyPlate($alpr, $plate) ?? $alpr;
    $bytes = $photo['bytes'];
    $alprSize = isset($meta['width'], $meta['height']) ? [(int)$meta['width'], (int)$meta['height']] : null;
    if ($crop === 'vehicle' && $pictureType === 'carImage') {
        $vehicle = \wip\vehicleCrop($user, $photoId, 0.2, $plate);
        if (!$vehicle) throw new \ValidationException('images', 'Nie wykryto pojazdu z widoczną tablicą rejestracyjną na tym zdjęciu');
        [$bytes, $alpr, $alprSize] = [$vehicle['bytes'], $vehicle['alpr'], $vehicle['size']];
    }
    $application = finalizeImage($appId, $pictureType, $bytes, $dateTime, !empty($dateTime), $latLng, $validate, $user,
        $alpr, $alprSize);

    return \semaphore\withLock($appId, "wipPhotos", function () use ($appId, $pictureType, $photoId) {
        $application = \app\get($appId);
        $application->wipPhotos = (object)array_merge((array)($application->wipPhotos ?? []), [$pictureType => $photoId]);
        \app\save($application);
        return $application;
    });
}

/**
 * @param array<string,mixed>|null $alprResult gotowy wynik PlateRecognizer dla $imageBytes (np. z `wip`) – wtedy ALPR nie jest wołany
 * @param array{0:int,1:int}|null $alprSize wymiary obrazka, dla którego policzono $alprResult (do przeskalowania boxów)
 */
function finalizeImage(string $appId, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng, ?callable $validate = null, ?User $user = null, ?array $alprResult = null, ?array $alprSize = null) {
    return \semaphore\withLock($appId, "uploadImage:$pictureType", function () use ($appId, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng, $validate, $user, $alprResult, $alprSize) {
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
            $preset = null;
            if ($alprResult !== null && $alprSize && $alprSize[0] > 0 && $alprSize[1] > 0) {
                $preset = ($alprSize[0] === $width && $alprSize[1] === $height)
                    ? $alprResult
                    : \alpr\scalePlateRecognizerResult($alprResult, $width / $alprSize[0], $height / $alprSize[1]);
            }
            \alpr\get($alprBytes, $application, $baseFileName, $type, $user, $preset);
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
    // jawne (int): GD i tak obcinało ułamki, ale PHP 8.1+ zgłasza dla tego „Implicit conversion from float” (deprecation)
    [$newwidth, $newheight, $width, $height] = [(int)$newwidth, (int)$newheight, (int)$width, (int)$height];
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
 *  - multipart `image` file, or `image_data` (base64 data URI, older web client);
 *  - `pictureType`: contextImage | carImage | thirdImage;
 *  - optional (carImage): `dateTime` ("2018-02-02T19:48:10"), `dtFromPicture` ("true"/true),
 *    `latLng` ("53.4,14.5") or `lat` + `lng`.
 *
 * @return array{bytes: string, pictureType: string, dateTime: ?string, dtFromPicture: ?bool, latLng: ?string}
 * @throws Exception (code 400) on a missing/oversized/undecodable file
 */
function imageUploadFromRequest(ServerRequestInterface $request, bool $requirePictureType = true): array {
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
        // data-URI: `image_data` (old web client)
        $dataUri = $params['image_data'] ?? null;
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
    if ($pictureType === null && $requirePictureType) {
        throw new MissingParamException('pictureType');
    }

    $dateTime = $params['dateTime'] ?? null;
    $dtFromPicture = isset($params['dtFromPicture'])
        ? in_array($params['dtFromPicture'], ['true', true, '1', 1], true)
        : null;
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
 * Pola edytowalne także po wysłaniu zgłoszenia (numer sprawy SM/Policji, prywatne uwagi). Wspólne dla weba
 * (PATCH /api/app/{id}/fields → SessionApiHandler::setFields) i REST (PATCH /api/rest/app/{id}/fields).
 * @param array<string,mixed> $fields
 * @throws \InvalidArgumentException nieznane pole albo wartość inna niż tekst
 */
function applyEditableFields(Application $application, array $fields): void {
    foreach ($fields as $field => $value) {
        if (!in_array($field, ['externalId', 'privateComment'], true))
            throw new \InvalidArgumentException("Pole $field nie może być edytowane");
        if (!is_string($value))
            throw new \InvalidArgumentException("Pole $field musi być tekstem");
        $application->$field = $value;
    }
}

/**
 * Zapis pól z applyEditableFields() dla właściciela zgłoszenia (REST). `suggestStatusChange` = zgłoszenie jest wysłane
 * i dostało numer sprawy – klient może zaproponować zmianę statusu na „potwierdzone” (jak confirm() na webie).
 * @return array{application: Application, suggestStatusChange: bool}
 * @throws Exception 403 nie właściciel; \InvalidArgumentException jak wyżej
 */
function updateApplicationFields(string $appId, array $fields, User $user): array {
    return \semaphore\withLock($appId, "setFields", function () use ($appId, $fields, $user) {
        $application = \app\get($appId);
        if ($application->email !== $user->getEmail())
            throw new Exception("Nie posiadasz zgłoszenia o ID $appId", 403);
        applyEditableFields($application, $fields);
        $suggest = in_array($application->status, ['confirmed-waiting', 'confirmed-waitingE'], true) && !empty($application->externalId);
        return ['application' => \app\save($application), 'suggestStatusChange' => $suggest];
    });
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
        // zdjęcia z etapu `wip` (assignPhoto) nie są już potrzebne – zgłoszenie ma własne kopie
        foreach ((array)($application->wipPhotos ?? []) as $wipPhotoId)
            \wip\delete($user, (string)$wipPhotoId);
        unset($application->wipPhotos);
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
function dashboardData(User $user, bool $fresh = false): array {
    global $LEVELS, $BADGES;
    $sex = $user->getSex();
    $stats = \user\stats(!$fresh, $user); // $fresh skips the 24 h stats cache
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

/**
 * What the confirmation step shows before a report is sent/saved (web: potwierdz.html.twig), built from
 * the same Application methods the template uses so the gendered phrases and the formal text match.
 * Clients render it as is: formal text, witness statement, short address, recipient and the sender block.
 *
 * @return array<string, mixed>
 */
function confirmationData(Application $application): array {
    $sm = $application->guessSMData();
    $sex = $application->guessUserSex();
    $witness = (bool)($application->statements->witness ?? false);
    $bylas = $sex['bylas'];
    $user = $application->user;

    return [
        // category formal text + extensions + the user's comment (plain text; the web only turns URLs into links)
        'body' => trim($application->getCategory()->formal . ' ' . $application->getExtensionsText()
            . ' ' . ($application->userComment ?? '')),
        'witness' => $witness
            ? mb_convert_case($bylas, MB_CASE_TITLE_SIMPLE) . ' świadkiem parkowania.'
            : "Nie $bylas świadkiem parkowania.",
        'shortAddress' => $application->getShortAddress(),
        'plateId' => $application->carInfo->plateId ?? null,
        // the vehicle frame is only drawn when the plate on the photo is the one the user confirmed
        'vehicleBox' => ($application->shouldIncludePlateImage() && isset($application->carInfo->vehicleBox->x))
            ? (array)$application->carInfo->vehicleBox : null,
        'recipient' => [
            'name' => $sm->getName(),
            'shortName' => $sm->getShortName(),
            'automated' => (bool)$sm->automated(),
            'unknown' => (bool)$sm->unknown(),
        ],
        'sender' => [
            'name' => $user->name ?? '',
            'email' => $application->email,
            'address' => $user->address ?? '',
            'msisdn' => $user->msisdn ?? '',
            'edelivery' => $user->edelivery ?? '',
        ],
    ];
}

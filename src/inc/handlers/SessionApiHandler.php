<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require(__DIR__ . '/../API.php');

use app\Application;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

class SessionApiHandler extends AbstractHandler {

    /** Hard limit: client caps uploads at 1600×1600 JPEG Q0.85, which stays under 1MB. */
    private const MAX_IMAGE_UPLOAD_BYTES = 1_048_576;

    private function checkEditable(Request $request, Application $app) {
        if (!$app->isEditable())
            throw new HttpForbiddenException($request, "Zgłoszenie {$app->id} nie może być edytowane");
    }

    private function checkOwnership(Request $request, Application $app) {
        if(!$app->isCurrentUserOwner())
            throw new HttpNotFoundException($request, "Nie posiadasz zgłoszenia o ID {$app->id}");
    }

    /**
     * Validates user session - BACKEND ONLY endpoint
     * Security measures:
     * 1. Requires X-API-Key header for authentication
     * 2. Session ID passed via X-Session-ID header (not in body)
     * 3. No CORS headers (SecureApiMiddleware)
     * 
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function validateUser(Request $request, Response $response, $args): Response {
        $apiKey = $request->getHeaderLine('X-API-Key');
        if (empty($apiKey) || !hash_equals(BACKEND_API_KEY, $apiKey)) {
            throw new HttpForbiddenException($request, "Invalid or missing API key");
        }
        
        $sessionId = $request->getHeaderLine('X-Session-ID');
        if (empty($sessionId)) {
            throw new MissingParamException("X-Session-ID header");
        }
        
        $currentSessionId = session_id();
        
        session_write_close();
        
        try {
            session_id($sessionId);
            session_start();
            
            $isValid = isset($_SESSION['user_id'])
                && isset($_SESSION['user_email'])
                && stripos($_SESSION['user_email'], '@') !== false;
            
            if ($isValid) {
                try {
                    $user = \user\get($_SESSION['user_email']);
                    $isRegistered = $user->isRegistered();
                } catch (Exception) {
                    $isRegistered = false;
                }
                $userData = [
                    'valid' => true,
                    'user_id' => $_SESSION['user_id'],
                    'user_email' => $_SESSION['user_email'],
                    'user_name' => $_SESSION['user_name'] ?? '',
                    'user_picture' => $_SESSION['user_picture'] ?? '',
                    'isRegistered' => $isRegistered
                ];
            } else {
                $userData = ['valid' => false];
            }
            
            session_write_close();
            
        } finally {
            if ($currentSessionId) {
                session_id($currentSessionId);
                session_start();
            }
        }
        
        return $this->renderJson($response, $userData);
    }

    public function deleteImage(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $imageId = $args['image'];
        $application = \semaphore\withLock($appId, "deleteImage", function () use ($appId, $request, $imageId) {
            $application = \app\get($appId);
            $this->checkEditable($request, $application);
            $this->checkOwnership($request, $application);
            $application = $this->removeImageFile($application, $imageId);
            return \app\save($application);
        });
        return $this->renderJson($response, $application);
    }

    private function removeImageFile(Application $app, string $imageId): Application {
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

    public function image(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $params = (array)$request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();

        if (!isset($uploadedFiles['image'])) {
            throw new Exception("Brak pliku obrazka", 400);
        }

        $upload = $uploadedFiles['image'];
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new Exception("Błąd przesyłania pliku (kod {$upload->getError()})", 400);
        }
        $tooLarge = "Zbyt duże zdjęcie (>" . (self::MAX_IMAGE_UPLOAD_BYTES >> 20) . "MB)";
        // Reject by declared size before buffering the stream into memory.
        if ($upload->getSize() > self::MAX_IMAGE_UPLOAD_BYTES) {
            throw new Exception($tooLarge, 400);
        }
        $imageBytes = $upload->getStream()->getContents();
        if (strlen($imageBytes) > self::MAX_IMAGE_UPLOAD_BYTES) {
            throw new Exception($tooLarge, 400);
        }

        $pictureType = $this->getParam($params, 'pictureType');

        $dateTime = isset($params['dateTime']) ? $params['dateTime'] : null;
        $dtFromPicture = isset($params['dtFromPicture']) ? $params['dtFromPicture'] == 'true' : null;
        $latLng = isset($params['latLng']) ? $params['latLng'] : null;
        $application = uploadImage($appId, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng,
            function (Application $application) use ($request) {
                $this->checkEditable($request, $application);
                $this->checkOwnership($request, $application);
            }
        );

        \telemetry\log('report_edited', $appId, ['type' => 'image']);

        return $this->renderJson($response, $application);
    }

    public function setStatus(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $status = $args['status'];
        $user = $request->getAttribute('user');
        $application = setStatus($status, $appId, $user);
        $this->checkOwnership($request, $application);
        return $this->renderJson($response, array(
            "status" => "OK",
            "patronite" => $application->patronite
        ));
    }
    public function setFields(Request $request, Response $response, array $args): Response
    {
        $appId = $args['appId'];
        [$isSent, $hasExternalId] = \semaphore\withLock($appId, "setFields", function () use ($appId, $request) {
            $application = \app\get($appId);
            $this->checkOwnership($request, $application);

            $fields = json_decode($request->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
            foreach ($fields as $field => $value) {
                match ($field) {
                    'externalId' => $application->externalId = $value,
                    'privateComment' => $application->privateComment = $value,
                    default => throw new HttpForbiddenException($request, 'Pole ' . $field . ' nie może być edytowane'),
                };
            }

            $isSent = in_array($application->status, ['confirmed-waiting', 'confirmed-waitingE']);
            $hasExternalId = !empty($application->externalId);
            \app\save($application);
            return [$isSent, $hasExternalId];
        });

        \telemetry\log('report_edited', $appId, ['type' => 'fields']);

        return self::renderJson($response, [
            "suggestStatusChange" => $isSent && $hasExternalId
        ]);
    }

    public function sendApplication(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $user = $request->getAttribute('user');
        try {
            $application = sendApplication($appId, $user);
            \telemetry\log('report_sent', $appId);
        } catch (NotSendableException $e) {
            return $this->renderJson($response->withStatus(409), array(
                "error" => $e->getMessage()
            ));
        }
        return $this->renderJson($response, array(
            "status" => $application->status
        ));
    }

    /**
     * @SuppressWarnings(PHPMD.MissingImport)
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function verifyToken(Request $request, Response $response): Response {
        $firebaseUser = $request->getAttribute('firebaseUser');

        // Session collision: the session contains a user_id belonging to a DIFFERENT user.
        // Do not reset when the session is empty (user_id not set) — that is a normal
        // first login. Do not reset when user_id matches either — that may be a
        // concurrent request from the same user (race condition).
        $sessionUserId = $_SESSION['user_id'] ?? null;
        if ($sessionUserId !== null && $sessionUserId !== $firebaseUser['user_id']) {
            // This is expected when users switch accounts without logging out.
            // The SessionMiddleware no longer resets sessions on /login.html if already logged in.
            logger("Session user switch: {$_SESSION['user_email']} -> {$firebaseUser['user_email']}", true);
            resetSession();
        }

        $_SESSION['user_email'] = $firebaseUser['user_email'];
        $_SESSION['user_name'] = $firebaseUser['user_name'];
        $_SESSION['user_picture'] = $firebaseUser['user_picture'];
        $_SESSION['user_id'] = $firebaseUser['user_id'];

        \telemetry\log('user_login');

        return $this->renderJson($response, $firebaseUser);
    }

    public function recydywa(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $application = \app\get($appId);
        $this->checkEditable($request, $application);
        $this->checkOwnership($request, $application);
        if (!isset($application->carInfo->plateId)) {
            throw new \Exception("Brak numeru rejestracyjnego dla zgłoszenia $appId", 404);
        }
        $recydywa = \recydywa\getDetailed($application->carInfo->plateId);
        return $this->renderJson($response, $recydywa);
    }

    public function Nominatim(Request $request, Response $response, $args) {
        extract($args);
        $result = \geo\Nominatim($lat, $lng);
        $response->getBody()->write(json_encode($result));
        return $response;
    }

    public function MapBox(Request $request, Response $response, $args) {
        extract($args);
        $result = \geo\MapBox($lat, $lng);
        $response->getBody()->write(json_encode($result));
        return $response;
    }

}

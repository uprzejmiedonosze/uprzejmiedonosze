<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require(__DIR__ . '/../API.php');

use app\Application;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

class SessionApiHandler extends AbstractHandler {

    private function checkEditable(Request $request, Application $app) {
        if (!$app->isEditable())
            throw new HttpForbiddenException($request, "Zgłoszenie {$app->id} nie może być edytowane");
    }

    private function checkOwnership(Request $request, Application $app) {
        if(!$app->isCurrentUserOwner())
            throw new HttpNotFoundException($request, "Nie posiadasz zgłoszenia o ID {$app->id}");
    }

    public function validateUser(Request $request, Response $response, $args): Response {
        $user = $request->getAttribute('user');
        return $this->renderJson($response, $user);
    }

    public function deleteImage(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $imageId = $args['image'];
        $application = \app\get($appId);
        $this->checkEditable($request, $application);
        $this->checkOwnership($request, $application);
        $application = $this->removeImageFile($application, $imageId);
        \app\save($application);
        return $this->renderJson($response, $application);
    }

    private function removeImageFile(Application $app, string $imageId): Application {
        $rmFile = fn($fileName) => @unlink(ROOT . $fileName);

        isset($app->$imageId->url) && $rmFile($app->$imageId->url);
        isset($app->$imageId->thumb ) && $rmFile($app->$imageId->thumb);
        unset($app->$imageId);
        return $app;
    }

    public function image(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $params = (array)$request->getParsedBody();

        $imageBytes = explode( ',', $this->getParam($params, 'image_data'))[1];
        $pictureType = $this->getParam($params, 'pictureType');

        $application = \app\get($appId);
        $this->checkEditable($request, $application);
        $this->checkOwnership($request, $application);

        $dateTime = isset($params['dateTime']) ? $params['dateTime'] : null;
        $dtFromPicture = isset($params['dtFromPicture']) ? $params['dtFromPicture'] == 'true' : null;
        $latLng = isset($params['latLng']) ? $params['latLng'] : null;
        $application = uploadImage($application, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng);

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

        return self::renderJson($response, [
            "suggestStatusChange" => $isSent && $hasExternalId
        ]);
    }

    public function sendApplication(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $user = $request->getAttribute('user');
        try {
            $application = sendApplication($appId, $user);
        } catch (MissingSMException $e) {
            return $this->renderJson($response, array(
                "status" => "redirect"
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

        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] !== $firebaseUser['user_id']) {
            $errorMsg = "Session collision! {$_SESSION['user_email']} != {$firebaseUser['user_email']}";
            logger($errorMsg, true);
            resetSession();
            \Sentry\captureException(new Exception($errorMsg));
        }

        $_SESSION['user_email'] = $firebaseUser['user_email'];
        $_SESSION['user_name'] = $firebaseUser['user_name'];
        $_SESSION['user_picture'] = $firebaseUser['user_picture'];
        $_SESSION['user_id'] = $firebaseUser['user_id'];
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

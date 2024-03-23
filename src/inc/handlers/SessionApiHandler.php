<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require(__DIR__ . '/../API.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SessionApiHandler extends AbstractHandler {
    public function image(Request $request, Response $response, $args): Response {
        global $storage;
        $appId = $args['appId'];
        $params = (array)$request->getParsedBody();

        $imageBytes = explode( ',', $this->getParam($params, 'image_data'))[1];
        $pictureType = $this->getParam($params, 'pictureType');

        $application = $storage->getApplication($appId);

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
        return $this->renderJson($response, array(
            "status" => "OK",
            "patronite" => $application->patronite
        ));
    }
    public function setFields(Request $request, Response $response, array $args): Response
    {
        global $storage;
        $appId = $args['appId'];
        $application = $storage->getApplication($appId);
        if(!$application->isCurrentUserOwner()) {
            throw new \Exception('Nie posiadasz aplikacji o ID ' . $appId, 404);
        }

        $fields = json_decode($request->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fields as $field => $value) {
            match ($field) {
                'externalId' => $application->externalId = $value,
                'privateComment' => $application->privateComment = $value,
                default => throw new \Exception('Pole ' . $field . ' nie może być edytowane'),
            };
        }

        $storage->saveApplication($application);

        return self::renderJson($response, []);
    }

    public function sendApplication(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $user = $request->getAttribute('user');
        $application = sendApplication($appId, $user);
        return $this->renderJson($response, array(
            "status" => $application->status
        ));
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function addToGallery(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        addToGallery($appId);
        return $this->renderJson($response, array(
            "status" => "OK"
        ));
    }
    public function moderateGallery(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $decision = $args['decision'];
        $user = $request->getAttribute('user');
        moderateApp($user, $appId, $decision);
        return $this->renderJson($response, array(
            "status" => "OK"
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

    public function Nominatim(Request $request, Response $response, $args) {
        extract($args);
        $result = Nominatim($lat, $lng);
        $response->getBody()->write(json_encode($result));
        return $response;
    }

    public function MapBox(Request $request, Response $response, $args) {
        extract($args);
        $result = MapBox($lat, $lng);
        $response->getBody()->write(json_encode($result));
        return $response;
    }

}

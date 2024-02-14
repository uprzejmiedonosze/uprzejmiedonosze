<?PHP

require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require(__DIR__ . '/../API.php');

class SessionApiHandler extends AbstractHandler {
    public function image(Request $request, Response $response, $args): Response {
        global $storage;
        $appId = $args['appId'];
        $params = (array)$request->getParsedBody();

        $imageBytes = explode( ',', getParam($params, 'image_data'))[1]; 
        $pictureType = getParam($params, 'pictureType');

        $application = $storage->getApplication($appId);

        $dateTime = isset($_POST['dateTime']) ? $_POST['dateTime'] : null;
        $dtFromPicture = isset($_POST['dtFromPicture']) ? $_POST['dtFromPicture'] == 'true' : null;
        $latLng = isset($_POST['latLng']) ? $_POST['latLng'] : null;
        $application = uploadImage($application, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng);

        return $this->renderJson($response, $application);
    }

    public function setStatus(Request $request, Response $response, $args): Response {
        global $storage;
        $appId = $args['appId'];
        $status = $args['status'];
        $application = setStatus($status, $appId, $storage->getCurrentUser());
        return $this->renderJson($response, array(
            "status" => "OK",
            "patronite" => $application->patronite
        ));
    }

    public function sendApplication(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        $application = sendApplication($appId);
        return $this->renderJson($response, array(
            "status" => $application->status
        ));
    }

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
        moderateApp($appId, $decision);
        return $this->renderJson($response, array(
            "status" => "OK"
        ));
    }

    public function verifyToken(Request $request, Response $response, $args): Response {
        $firebaseUser = $request->getAttribute('firebaseUser');
        $_SESSION['user_email'] = $firebaseUser['user_email'];
        $_SESSION['user_name'] = $firebaseUser['user_name'];
        $_SESSION['user_picture'] = $firebaseUser['user_picture'];
        $_SESSION['user_id'] = $firebaseUser['user_id'];
        return $this->renderJson($response, $firebaseUser);
    }
    
}
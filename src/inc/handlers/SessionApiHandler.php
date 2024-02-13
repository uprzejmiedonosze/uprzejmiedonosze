<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require(__DIR__ . '/../API.php');

class SessionApiHandler {
    public function image(Request $request, Response $response, $args) {
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

        $response->getBody()->write(json_encode($application));
        return $response;
    }

    public function setStatus(Request $request, Response $response, $args) {
        global $storage;
        $appId = $args['appId'];
        $status = $args['status'];
        $application = setStatus($status, $appId, $storage->getCurrentUser());
        $response->getBody()->write(json_encode(array(
            "status" => "OK",
            "patronite" => $application->patronite
        )));
        return $response;
    }

    public function sendApplication(Request $request, Response $response, $args) {
        $appId = $args['appId'];
        $application = sendApplication($appId);
        $response->getBody()->write(json_encode(array(
            "status" => $application->status
        )));
        return $response;
    }

    public function addToGallery(Request $request, Response $response, $args) {
        $appId = $args['appId'];
        addToGallery($appId);
        $response->getBody()->write(json_encode(array(
            "status" => "OK"
        )));
        return $response;
    }
    public function moderateGallery(Request $request, Response $response, $args) {
        $appId = $args['appId'];
        $decision = $args['decision'];
        moderateApp($appId, $decision);
    }

    public function verifyToken(Request $request, Response $response, $args) {
        $firebaseUser = $request->getAttribute('firebaseUser');
        $_SESSION['user_email'] = $firebaseUser['user_email'];
        $_SESSION['user_name'] = $firebaseUser['user_name'];
        $_SESSION['user_picture'] = $firebaseUser['user_picture'];
        $_SESSION['user_id'] = $firebaseUser['user_id'];
        $response->getBody()->write(json_encode($firebaseUser));
        return $response;
    }
    
}
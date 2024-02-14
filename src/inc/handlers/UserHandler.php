<?PHP

require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserHandler extends AbstractHandler {
    public function register(Request $request, Response $response, $args): Response {
        global $storage;
        try {
            $user = $storage->getCurrentUser();
        } catch (Exception $e) {
            $user = new User();
            $storage->saveUser($user);
        }
        return AbstractHandler::render($request, $response, 'register', [
            'signInSuccessUrl' => isset($_GET['next']) ? $_GET['next'] : 'start.html',
            'user' => $user
        ]);
    }

    public function finish(Request $request, Response $response, $args): Response {
        global $storage;
        $params = (array)$request->getParsedBody();
        $signInSuccessUrl = getParam($params, 'next', '/start.html');
        $name = capitalizeName(getParam($params, 'name'));

        $address = getParam($params, 'address');
        $address = str_replace(', Polska', '', cleanWhiteChars($address));

        $msisdn = getParam($params, 'msisdn', '');

        $exposeData  = getParam($params, 'exposeData', 'N') == 'Y';
        $stopAgresji = getParam($params, 'stopAgresji', 'SM') == 'SA';
        $autoSend    = getParam($params, 'autoSend', 'Y') == 'Y';
        $myAppsSize  = getParam($params, 'myAppsSize', 200);

        $user = $request->getAttribute('user');
        $user->updateUserData($name, $msisdn, $address, $exposeData, $stopAgresji, $autoSend, $myAppsSize);
        $storage->saveUser($user);

        return AbstractHandler::redirect($signInSuccessUrl);
    }
}

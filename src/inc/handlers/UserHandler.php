<?PHP

require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class UserHandler extends AbstractHandler {
    public function register(Request $request, Response $response): Response {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        return AbstractHandler::renderHtml($request, $response, 'register', [
            'signInSuccessUrl' => $this->getParam($params, 'next', '/start.html'),
            'user' => $user
        ]);
    }

    public function finish(Request $request): Response {
        $params = (array)$request->getParsedBody();
        $signInSuccessUrl = $this->getParam($params, 'next', '/start.html');
        $name = capitalizeName($this->getParam($params, 'name'));

        $address = $this->getParam($params, 'address');
        $address = str_replace(', Polska', '', cleanWhiteChars($address));

        $msisdn = $this->getParam($params, 'msisdn', '');

        $stopAgresji = $this->getParam($params, 'stopAgresji', 'SM') == 'SA';
        $shareRecydywa=$this->getParam($params, 'shareRecydywa', 'Y') == 'Y';

        $user = $request->getAttribute('user');
        $user->updateUserData($name, $msisdn, $address, $stopAgresji, $shareRecydywa);
        \user\save($user);

        return AbstractHandler::redirect($signInSuccessUrl);
    }
}

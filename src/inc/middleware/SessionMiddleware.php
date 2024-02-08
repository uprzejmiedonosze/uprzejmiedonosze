<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class SessionMiddleware implements MiddlewareInterface {

    private $mustBeRegistered = true;
    private $optional = false;

    public function __construct(bool $mustBeRegistered=true, bool $optional=false) {
        $this->mustBeRegistered = $mustBeRegistered;
        $this->optional = $optional;
    }

    private static function isLoggedIn(): bool {
        return isset($_SESSION['token'])
            && isset($_SESSION['user_email'])
            && stripos($_SESSION['user_email'], '@') !== false;
    }

    private static function redirect(Request $request, string $path): Response {
        $path = urlencode($request->getUri()->getPath());
        $response = new Response();
        return $response->withHeader('Location', "/$path?next=$path")
                ->withStatus(302);
    }


    public function process(Request $request, RequestHandler $handler): Response {
        $isLoggedIn = $this->isLoggedIn();

        $request = $request->withAttribute('isLoggedIn', $isLoggedIn);
        if (!$isLoggedIn && !$this->optional)
            return $this->redirect($request, 'login.html');

        if ($isLoggedIn)
            $request = $request->withAttribute('user_email', $_SESSION['user_email']);

        if ($isLoggedIn && $this->mustBeRegistered) {
            global $storage;
            $user = $storage->getCurrentUser();

            $request = $request->withAttribute('user', $user);

            $isRegistered = $user->isRegistered();
            $request = $request->withAttribute('isRegistered', $isRegistered);
            if (!$isRegistered && !$this->optional)
                return $this->redirect($request, 'register.html');
        }
        
        return $handler->handle($request);
    }
}

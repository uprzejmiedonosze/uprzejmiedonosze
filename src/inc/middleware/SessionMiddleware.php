<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;

class SessionMiddleware implements MiddlewareInterface {

    protected static function isLoggedIn(): bool {
        return isset($_SESSION['token'])
            && isset($_SESSION['user_email'])
            && stripos($_SESSION['user_email'], '@') !== false;
    }

    protected static function redirect(Request $request, string $path): Response {
        $destination = urlencode($request->getUri()->getPath());
        $response = new Response();
        return $response->withHeader('Location', "/$path?next=$destination")
                ->withStatus(302);
    }

    protected static function checkLoggedIn(Request $request): void {
        $isLoggedIn = $request->getAttribute('isLoggedIn');
        if (!$isLoggedIn)
            SessionMiddleware::redirect($request, 'login.html');
    }

    protected static function checkRegistered(Request $request): void {
        $isRegistered = $request->getAttribute('isRegistered');
        if (!$isRegistered)
            SessionMiddleware::redirect($request, 'register.html');
    }

    public function process(Request $request, RequestHandler $handler): Response {
        logger('SessionMiddleware');
        $isLoggedIn = $this->isLoggedIn();
        $request = $request->withAttribute('isLoggedIn', $isLoggedIn);
        if ($isLoggedIn)
            $request = $request->withAttribute('user_email', $_SESSION['user_email']);


        if ($isLoggedIn) {
            global $storage;
            $user = $storage->getCurrentUser();
            $request = $request->withAttribute('user', $user);
            $isRegistered = $user->isRegistered();
            $request = $request->withAttribute('isRegistered', $isRegistered);
        }
        
        return $handler->handle($request);
    }
}

class LoggedInMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        SessionMiddleware::checkLoggedIn($request);
        return $handler->handle($request);
    }
}

class RegisteredMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        SessionMiddleware::checkLoggedIn($request);
        SessionMiddleware::checkRegistered($request);
        return $handler->handle($request);
    }
}

class ModeratorMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        SessionMiddleware::checkLoggedIn($request);
        SessionMiddleware::checkRegistered($request);
        $user = $request->getAttribute('user');
        if (!$user->isModerator())
            throw new HttpForbiddenException($request, "Użytkownik '{$user->getEmail()}' nie ma uprawnień dla tej strony");
        return $handler->handle($request);
    }
}

class AdminMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        SessionMiddleware::checkLoggedIn($request);
        SessionMiddleware::checkRegistered($request);
        $user = $request->getAttribute('user');
        if (!$user->isAdmin())
            throw new HttpForbiddenException($request, "Użytkownik '{$user->getEmail()}' nie ma uprawnień dla tej strony");
        return $handler->handle($request);
    }
}
<?PHP

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Response;


class SessionMiddleware implements MiddlewareInterface {
    private static function redirect(string $newLocation): Response {
        $httpCode = 302;
        $response = new Response($httpCode);
        $response = $response->withHeader('Location', $newLocation);
        return $response;
    }
    
    public static function isLoggedIn(): bool {
        return isset($_SESSION['user_id'])
            && isset($_SESSION['user_email'])
            && stripos($_SESSION['user_email'], '@') !== false;
    }

    protected static function checkLoggedIn(Request $request): Response|null {
        $isLoggedIn = $request->getAttribute('isLoggedIn');
        $destination = urlencode($request->getUri()->getPath());
        if (!$isLoggedIn)
            return SessionMiddleware::redirect("/login.html?next=$destination");
        return null;
    }

    protected static function checkRegistered(Request $request): Response|null {
        $isRegistered = $request->getAttribute('isRegistered');
        $destination = urlencode($request->getUri()->getPath());
        if (!$isRegistered)
            return SessionMiddleware::redirect("/register.html?next=$destination");
        return null;
    }

    public function process(Request $request, RequestHandler $handler): ResponseInterface {
        logger(static::class . ": {$request->getUri()->getPath()}");
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
    public function process(Request $request, RequestHandler $handler): ResponseInterface {
        logger(static::class . ": {$request->getUri()->getPath()}");
        $checkLoggedIn = SessionMiddleware::checkLoggedIn($request);
        if($checkLoggedIn) return $checkLoggedIn;
        return $handler->handle($request);
    }
}

class RegisteredMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): ResponseInterface {
        logger(static::class . ": {$request->getUri()->getPath()}");
        $checkLoggedIn = SessionMiddleware::checkLoggedIn($request);
        if($checkLoggedIn) return $checkLoggedIn;
        $checkRegistered = SessionMiddleware::checkRegistered($request);
        if($checkRegistered) return $checkRegistered;
        return $handler->handle($request);
    }
}

class ModeratorMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): ResponseInterface {
        logger(static::class . ": {$request->getUri()->getPath()}");
        $checkLoggedIn = SessionMiddleware::checkLoggedIn($request);
        if($checkLoggedIn) return $checkLoggedIn;
        $checkRegistered = SessionMiddleware::checkRegistered($request);
        if($checkRegistered) return $checkRegistered;

        $user = $request->getAttribute('user');
        if (!$user->isModerator())
            throw new HttpForbiddenException($request, "Użytkownik '{$user->getEmail()}' nie ma uprawnień dla tej strony");
        return $handler->handle($request);
    }
}

class AdminMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): ResponseInterface {
        logger(static::class . ": {$request->getUri()->getPath()}");
        $checkLoggedIn = SessionMiddleware::checkLoggedIn($request);
        if($checkLoggedIn) return $checkLoggedIn;
        $checkRegistered = SessionMiddleware::checkRegistered($request);
        if($checkRegistered) return $checkRegistered;

        $user = $request->getAttribute('user');
        if (!$user->isAdmin())
            throw new HttpForbiddenException($request, "Użytkownik '{$user->getEmail()}' nie ma uprawnień dla tej strony");
        return $handler->handle($request);
    }
}
<?PHP


use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;


abstract class SessionMiddleware implements MiddlewareInterface {
    public static function isLoggedIn(): bool {
        return isset($_SESSION['user_id'])
            && isset($_SESSION['user_email'])
            && stripos($_SESSION['user_email'], '@') !== false;
    }

    protected static function checkLoggedIn(Request $request): Response|null {
        $isLoggedIn = $request->getAttribute('isLoggedIn');
        $destination = urlencode($request->getUri()->getPath());
        if (!$isLoggedIn) {
            if ($request->getAttribute('content') == 'json')
                throw new HttpForbiddenException($request, "User not logged in");
            return AbstractHandler::redirect("/login.html?next=$destination");
        }
        return null;
    }

    protected static function checkRegistered(Request $request): Response|null {
        $isRegistered = $request->getAttribute('isRegistered');
        $destination = urlencode($request->getUri()->getPath());
        if (!$isRegistered) {
            if ($request->getAttribute('content') == 'json')
                throw new HttpForbiddenException($request, "User not registered");
            return AbstractHandler::redirect("/register.html?next=$destination");
        }
        return null;
    }

    public abstract function process(Request $request, RequestHandler $handler): Response;

    public function preprocess(Request $request, RequestHandler $handler): Array {
        logger("SessionMiddleware: {$request->getUri()->getPath()}");
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
        
        return [$request, $handler];
    }
}

class OptionalUserMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        [$request, $handler] = parent::preprocess($request, $handler);
        logger(static::class . ": {$request->getUri()->getPath()}");
        return $handler->handle($request);
    }
}


class LoggedInMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        [$request, $handler] = parent::preprocess($request, $handler);
        logger(static::class . ": {$request->getUri()->getPath()}");
        $checkLoggedIn = SessionMiddleware::checkLoggedIn($request);
        if($checkLoggedIn) return $checkLoggedIn;
        return $handler->handle($request);
    }
}

class RegisteredMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        [$request, $handler] = parent::preprocess($request, $handler);
        logger(static::class . ": {$request->getUri()->getPath()}");
        $checkLoggedIn = SessionMiddleware::checkLoggedIn($request);
        if($checkLoggedIn) return $checkLoggedIn;
        $checkRegistered = SessionMiddleware::checkRegistered($request);
        if($checkRegistered) return $checkRegistered;
        return $handler->handle($request);
    }
}

class ModeratorMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        [$request, $handler] = parent::preprocess($request, $handler);
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
    public function process(Request $request, RequestHandler $handler): Response {
        [$request, $handler] = parent::preprocess($request, $handler);
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
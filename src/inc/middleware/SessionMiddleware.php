<?PHP


use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;


/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.Superglobals)
 */
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
            return AbstractHandler::redirect("/app/account?next=$destination");
        }
        return null;
    }

    public abstract function process(Request $request, RequestHandler $handler): Response;

    public function preprocess(Request $request, RequestHandler $handler): Array {
        $path = $request->getUri()->getPath();
        log_debug("SessionMiddleware: $path");
        if ($path == '/logout.html') {
            resetSession();
        } elseif ($path == '/login.html' && !$this->isLoggedIn()) {
            // Login page: only reset an anonymous session (e.g. a stale token).
            // If the user is already logged in and opens /login.html in a new tab,
            // do NOT destroy their active session.
            resetSession();
        }

        $isLoggedIn = $this->isLoggedIn();
        $request = $request->withAttribute('isLoggedIn', $isLoggedIn);
        if ($isLoggedIn)
            $request = $request->withAttribute('user_email', $_SESSION['user_email']);


        if ($isLoggedIn) {
            $user = \user\current();
            $request = $request->withAttribute('user', $user);
            $isRegistered = $user->isRegistered();
            $request = $request->withAttribute('isRegistered', $isRegistered);
        }

        // Nothing past this point (any handler behind LoggedInMiddleware/
        // RegisteredMiddleware/ModeratorMiddleware/OptionalUserMiddleware) ever
        // writes to $_SESSION — the only writes are at bootstrap (index.php,
        // before routing) and in TokenSessionMiddleware (the separate,
        // sessionless REST/MCP token path, not used by these routes). So it's
        // safe to release the memcached-backed session lock here rather than
        // holding it for the handler's full duration. Otherwise a slow handler
        // (e.g. image upload + ALPR, 1-2s) starves out concurrent requests for
        // the same session: PHP's memcached session lock retry budget
        // (memcached.sess_lock_retries/sess_lock_wait_*) is a handful of
        // milliseconds, so a losing request silently proceeds with an empty
        // $_SESSION instead of waiting — surfacing as a spurious "User not
        // logged in" 403 (confirmed via Papertrail 2026-09-29: e.g. a
        // concurrent /api/geo/.../n racing against /api/app/.../image).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return [$request, $handler];
    }
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class OptionalUserMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        [$request, $handler] = parent::preprocess($request, $handler);
        log_debug(static::class . ": {$request->getUri()->getPath()}");
        return $handler->handle($request);
    }
}


/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class LoggedInMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        [$request, $handler] = parent::preprocess($request, $handler);
        log_debug(static::class . ": {$request->getUri()->getPath()}");
        $checkLoggedIn = SessionMiddleware::checkLoggedIn($request);
        if($checkLoggedIn) return $checkLoggedIn;
        return $handler->handle($request);
    }
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class RegisteredMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        [$request, $handler] = parent::preprocess($request, $handler);
        log_debug(static::class . ": {$request->getUri()->getPath()}");
        $checkLoggedIn = SessionMiddleware::checkLoggedIn($request);
        if($checkLoggedIn) return $checkLoggedIn;
        $checkRegistered = SessionMiddleware::checkRegistered($request);
        if($checkRegistered) return $checkRegistered;
        return $handler->handle($request);
    }
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class ModeratorMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        [$request, $handler] = parent::preprocess($request, $handler);
        log_debug(static::class . ": {$request->getUri()->getPath()}");
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

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class AdminMiddleware extends SessionMiddleware {
    public function process(Request $request, RequestHandler $handler): Response {
        [$request, $handler] = parent::preprocess($request, $handler);
        log_debug(static::class . ": {$request->getUri()->getPath()}");
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
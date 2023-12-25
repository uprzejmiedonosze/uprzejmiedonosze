<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

class UserMiddleware implements MiddlewareInterface {
    private $createIfNonExists = false;
    public function __construct($createIfNonExists=false) {
        $this->createIfNonExists = $createIfNonExists;
    }

    public function process(Request $request, RequestHandler $handler): Response {
        global $storage;

        $firebaseUser = $request->getAttribute('firebaseUser');

        try{
            $user = $storage->getUser($firebaseUser['user_email']);
        }catch(Exception $e){
            if (!$this->createIfNonExists) {
                throw new HttpNotFoundException($request, null, $e);
            }
            $user = User::withEmail($firebaseUser['user_email']);
            $storage->saveUser($user);
        }

        $user->isRegistered = $user->isRegistered();
        $user->isTermsConfirmed = $user->checkTermsConfirmation();
        $request = $request->withAttribute('user', $user);
        return $handler->handle($request);
    }
}

/**
 * Ensure the User is registered.
 */
class RegisteredMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        $user = $request->getAttribute('user');
        if(!$user->isRegistered) {
            throw new HttpForbiddenException($request, "User is not registered!");
        }
        return $handler->handle($request);
    }
}

/**
 * Ensure the User is registered.
 */
class TermsConfirmedMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        $user = $request->getAttribute('user');
        if(!$user->isTermsConfirmed) {
            throw new HttpForbiddenException($request, "User hasn't confirmed terms of use!");
        }
        return $handler->handle($request);
    }
}

/** 
 * Add stats (from cache) to User object.
 */
class AddStatsMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        global $storage;
        $response = $handler->handle($request);
        $user = $request->getAttribute('user');
        $user->stats = $storage->getUserStats(true, $user);
        $request = $request->withAttribute('user', $user);
        $response->getBody()->write(json_encode($user));
        return $response;
    }
}

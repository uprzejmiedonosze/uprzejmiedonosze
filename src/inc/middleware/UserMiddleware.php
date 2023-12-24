<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

class UserMiddleware implements MiddlewareInterface {
    private $createIfNonExists = false;
    private $withStats = false;
    public function __construct($createIfNonExists=false, $withStats=false) {
        $this->createIfNonExists = $createIfNonExists;
        $this->withStats = $withStats;
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
        if ($this->withStats)
            $user->stats = $storage->getUserStats(true, $user);
        $request = $request->withAttribute('user', $user);
        return $handler->handle($request);
    }
}


class RegisteredMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        $user = $request->getAttribute('user');
        if(!$user->isRegistered()) {
            throw new HttpForbiddenException($request, "User is not registered!");
        }
        return $handler->handle($request);
    }
}


<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;

class LoginMiddleware implements MiddlewareInterface {
    private $registrationRequired = true;
    public function __construct($registrationRequired=true) {
        $this->registrationRequired = $registrationRequired;
    }

    public function process(Request $request, RequestHandler $handler): Response {
        global $storage;

        $firebaseUser = $request->getAttribute('firebaseUser');
        $user = $storage->getUser($firebaseUser['user_email']);

        if($this->registrationRequired && !$user->isRegistered()) {
            throw new HttpForbiddenException($request, "User is not registered!");
        }
        $request = $request->withAttribute('user', $user);
        return $handler->handle($request);
    }
}



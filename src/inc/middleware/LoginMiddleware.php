<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

class LoginMiddleware implements MiddlewareInterface {
    private $registrationRequired = true;
    private $createIfNonExists = false;
    public function __construct($registrationRequired=true, $createIfNonExists=false) {
        $this->registrationRequired = $registrationRequired;
        $this->createIfNonExists = $createIfNonExists;
        logger("LoginMiddleware.__construct '{$registrationRequired}'/'{$createIfNonExists}'");
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

        if($this->registrationRequired && !$user->isRegistered()) {
            throw new HttpForbiddenException($request, "User is not registered!");
        }
        $request = $request->withAttribute('user', $user);
        return $handler->handle($request);
    }
}



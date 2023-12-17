$request = $request->withAttribute('user', $user);

<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;

class AppMiddleware implements MiddlewareInterface {
    public function __construct($failOnWrongOwnership=true) {
        $this->failOnWrongOwnership = $failOnWrongOwnership;
    }

    public function process(Request $request, RequestHandler $handler): Response {
        global $storage;

        $route = $request->getAttribute('route');
        $args = $route->getArguments();
        $appId = $args['appId'];

        $application = $storage->getApplication($appId);
        $user = $request->getAttribute('user');

        if ($this->failOnWrongOwnership && $application->user->email !== $user->getEmail()) {
            throw new HttpForbiddenException($request, "User '{$user->getEmail()}' is not allowed to change app '$appId'");
        }

        $request = $request->withAttribute('application', $application);
        return $handler->handle($request);
    }
}



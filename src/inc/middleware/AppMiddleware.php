<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;
use Slim\Routing\RouteContext;

class AppMiddleware implements MiddlewareInterface {
    private $failOnWrongOwnership = true;

    public function __construct($failOnWrongOwnership=true) {
        $this->failOnWrongOwnership = $failOnWrongOwnership;
    }

    public function process(Request $request, RequestHandler $handler): Response {
        global $storage;

        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
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

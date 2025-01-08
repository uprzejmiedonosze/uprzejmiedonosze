<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;
use Slim\Routing\RouteContext;

/**
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class AppMiddleware implements MiddlewareInterface {
    private $failOnWrongOwnership = true;

    public function __construct($failOnWrongOwnership=true) {
        $this->failOnWrongOwnership = $failOnWrongOwnership;
    }

    public function process(Request $request, RequestHandler $handler): Response {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $args = $route->getArguments();
        $appId = $args['appId'];

        $application = \app\get($appId);
        $user = $request->getAttribute('user');

        if ($this->failOnWrongOwnership && $application->email !== $user->getEmail()) {
            throw new HttpForbiddenException($request, "User '{$user->getEmail()}' is not allowed to change app '$appId'");
        }

        unset($application->browser);
        $request = $request->withAttribute('application', $application);
        return $handler->handle($request);
    }
}

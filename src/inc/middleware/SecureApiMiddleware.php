<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Secure API Middleware - no CORS headers, for backend-to-backend communication only
 */
class SecureApiMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        $request = $request->withAttribute('content', 'json');
        $response = $handler->handle($request);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}

<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class XlsMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        logger(static::class . ": {$request->getUri()->getPath()}");
        $request = $request->withAttribute('content', 'xlsx');
        $response = $handler->handle($request);

        $allowedOrigin = getCorsOrigin($request);
        if ($allowedOrigin) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
                ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                ->withHeader('Access-Control-Allow-Methods', 'GET')
                ->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}

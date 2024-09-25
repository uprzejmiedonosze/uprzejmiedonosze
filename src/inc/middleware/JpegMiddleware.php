<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class JpegMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        logger(static::class . ": {$request->getUri()->getPath()}");
        $request = $request->withAttribute('content', 'image');
        $response = $handler->handle($request);
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept, Origin')
            ->withHeader('Access-Control-Allow-Methods', 'GET')
            ->withHeader('Content-Type', 'image/jpeg');
    }
}

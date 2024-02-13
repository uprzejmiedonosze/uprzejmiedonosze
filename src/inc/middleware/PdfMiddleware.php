<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class PdfMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        logger(static::class . ": {$request->getUri()->getPath()}");
        $response = $handler->handle($request);
        return $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                ->withHeader('Access-Control-Allow-Methods', 'GET')
                ->withHeader('Access-Control-Allow-Credentials', 'false')
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Transfer-Encoding', 'Binary');
    }
}

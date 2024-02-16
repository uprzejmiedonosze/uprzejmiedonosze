<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class HtmlMiddleware implements MiddlewareInterface {
    public static function getDefaultParameters(bool $isDialog=false): array {
        $isLoggedIn = SessionMiddleware::isLoggedIn();
        
        $parameters = Array();
        $parameters['config'] = [
            'menu' => ''
        ];
        $parameters['dialog'] = $isDialog;

        $parameters['general'] = [
            'uri' => $_SERVER['REQUEST_URI'],
            'isLoggedIn' => $isLoggedIn,
            'hasApps' => false,
            'isAdmin' => false,
            'galleryCount' => 0,
            'isProd' => isProd(),
            'isStaging' => isStaging()
        ];

        global $STATUSES;
        $parameters['statuses'] = $STATUSES;

        global $CATEGORIES;
        $parameters['categories'] = $CATEGORIES;

        global $EXTENSIONS;
        $parameters['extensions'] = $EXTENSIONS;

        global $LEVELS;
        $parameters['levels'] = $LEVELS;

        global $BADGES;
        $parameters['badges'] = $BADGES;
        return $parameters;
    }

    public function process(Request $request, RequestHandler $handler): Response {
        logger(static::class . ": {$request->getUri()->getPath()}");
        $request = $request->withAttribute('content', 'html');
        $queryParams = $request->getQueryParams();

        $parameters = HtmlMiddleware::getDefaultParameters(
            isset($queryParams['dialog'])
        );

        $request = $request->withAttribute('parameters', $parameters);

        $response = $handler->handle($request);
        return $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST')
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Content-Type', 'text/html')
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}

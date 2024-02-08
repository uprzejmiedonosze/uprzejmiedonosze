<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Views\Twig;

class HtmlMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        $queryParams = $request->getQueryParams();

        $parameters = Array();
        $parameters['config'] = [
            'menu' => ''
        ];
        $parameters['head'] = [
            'dialog' => isset($queryParams['dialog'])
        ];

        $parameters['general'] = [
            'uri' => $_SERVER['REQUEST_URI'],
            'isLoggedIn' => false,
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

    public static function render(Request $request, Response $response, string $route, Array $extraParameters=[]) {
        $parameters = $request->getAttribute('parameters');
        $parameters['head']['mainClass'] = $route;
        $parameters['config']['menu'] = $route;
        $parameters['config']['isIOS'] = isIOS();
        $view = Twig::fromRequest($request);
        return $view->render(
            $response,
            "$route.html.twig",
            array_merge_recursive($parameters, $extraParameters)
        );
    }
}

<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Views\Twig;

class HtmlMiddleware implements MiddlewareInterface {
    public static function getDefaultParameters(bool $isLoggedIn=false, bool $isDialog=false): array {
        
        $parameters = Array();
        $parameters['config'] = [
            'menu' => ''
        ];
        $parameters['head'] = [
            'dialog' => $isDialog
        ];

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
        logger('HtmlMiddleware');
        $queryParams = $request->getQueryParams();

        $parameters = HtmlMiddleware::getDefaultParameters(
            $request->getAttribute('isLoggedIn') ?? false,
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

    public static function render(Request $request, Response $response, string $route, Array $extraParameters=[]) {
        global $storage;

        $parameters = $request->getAttribute('parameters');
        $parameters['head']['mainClass'] = $route;
        $parameters['config']['menu'] = $route;
        $parameters['config']['isIOS'] = isIOS();

        $user = $request->getAttribute('user', null);
        if($user) {
            $parameters['config']['sex'] = $user->getSex();
            $parameters['config']['userNumber'] = $user->getNumber();
            $parameters['general']['userName'] = $user->getFirstName();
            // force update cache if ?update GET param is set
            $parameters['general']['stats'] = $storage->getUserStats(!isset($_GET['update']), $user);
        }
        $view = Twig::fromRequest($request);
        return $view->render(
            $response,
            "$route.html.twig",
            array_merge_recursive($parameters, $extraParameters)
        );
    }
}

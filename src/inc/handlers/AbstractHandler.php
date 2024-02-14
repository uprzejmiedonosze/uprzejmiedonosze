<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

abstract class AbstractHandler {
    public static function redirect(string $path) {
        return function (Request $request, Response $response, $args) use ($path): Response {
            return $response
                ->withHeader('Location', $path)
                ->withStatus(302);
        };
    }

    public static function renderJson(Response $response, object|array $object): Response {
        $response->getBody()->write(json_encode($object));
        return $response;
    }

    public static function render(Request $request, Response $response, string $route, Array $extraParameters=[]): Response {
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
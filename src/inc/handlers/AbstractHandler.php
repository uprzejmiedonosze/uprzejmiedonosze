<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as ResponseObject;
use Slim\Views\Twig;

abstract class AbstractHandler {
    public static function redirect(string $newLocation): Response {
        logger(static::class . ":redirect to $newLocation");
        $response = new ResponseObject(302);
        $response = $response->withHeader('Location', $newLocation);
        return $response;
    }

    public static function renderJson(Response $response, object|array $object): Response {
        $response->getBody()->write(json_encode($object));
        return $response;
    }

    public static function renderPdf(Response $response, $path, $filename): Response {
        $response = $response->withHeader('Content-disposition', "attachment; filename=$filename");
        $response->getBody()->write(file_get_contents($path));
        return $response;
    }

    public static function renderJpeg(Response $response, $path): Response {
        logger("renderJpeg: $path");
        $response = $response->withHeader('Content-disposition', "inline");
        $response = $response->withStatus(200);

        $pixelate  = strpos($path, '?pixelate') !== false;
        $cleanPath = str_replace('?pixelate', '', $path);

        \storage\ensure_local($cleanPath);

        $fullImagePath = ROOT . $cleanPath;
        $image = $pixelate
            ? AbstractHandler::pixelate($fullImagePath)
            : file_get_contents($fullImagePath);

        \storage\release_local($cleanPath);

        $response->getBody()->write($image);
        return $response;
    }

    private static function pixelate(string $imagePath): string {
        $src = imagecreatefromjpeg($imagePath);
        imagefilter($src, IMG_FILTER_PIXELATE, 10, true);
        ob_start();
        imagejpeg($src);
        $stringdata = ob_get_contents();
        ob_end_clean();
        return $stringdata;
    }

    public static function renderCsv(Response $response, $content, $filename): Response {
        $response = $response->withHeader('Content-disposition', "inline; filename=$filename");
        $response->getBody()->write($content);
        return $response;
    }

    public static function renderXls(Response $response, $content, $filename): Response {
        $response = $response->withHeader('Content-disposition', "attachment; filename=$filename");
        $response->getBody()->write($content);
        return $response;
    }

    public static function renderDoc(Response $response, $content, $filename): Response {
        $response = $response->withHeader('Content-disposition', "attachment; filename=$filename");
        $response->getBody()->write($content);
        return $response;
    }

    /**
     * @SuppressWarnings(PHPMD.MissingImport)
     */
    public static function getParam(array $params, string $name, mixed $default=null) {
        $param = $params[$name] ?? $default;
        if (is_null($param)) {
            throw new MissingParamException($name);
        }
        return $param;
    }
    

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public static function renderHtml(Request $request, Response $response, string $route, Array $extraParameters=[]): Response {
        $params = $request->getQueryParams();

        $parameters = $request->getAttribute('parameters');
        $parameters['HOST'] = HOST;
        $parameters['BASE_URL'] = BASE_URL;
        $parameters['CSS_HASH'] = CSS_HASH;
        $parameters['JS_HASH'] = JS_HASH;
        $parameters['mainClass'] = $route;
        $parameters['config']['menu'] = $route;
        $parameters['config']['isIOS'] = isIOS();

        $user = $request->getAttribute('user', null);

        $parameters['config']['sex'] = ($user)? $user->getSex(): SEXSTRINGS['?'];
        if($user) {
            $parameters['config']['userNumber'] = $user->getNumber();
            $parameters['general']['userName'] = $user->getFirstName();
            // force update cache if ?update GET param is set
            $parameters['general']['stats'] = \user\stats(!isset($params['update']), $user);
        }
        $view = Twig::fromRequest($request);
        return $view->render(
            $response,
            "$route.html.twig",
            array_merge_recursive($parameters, $extraParameters)
        );
    }
}
<?PHP

require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class GeneratorHandler extends AbstractHandler {
    public function generator(Request $request, Response $response): Response {

        $user = $request->getAttribute('user');
        $email = $user->getEmail();
        $mainPageStats = \global_stats\mainPage(useCache: true);

        return AbstractHandler::renderHtml($request, $response, 'generator', [
            'isPatron' => $user->isPatron(),
            'config' => [
                'stats' => $mainPageStats
            ]
        ]);
    }

    public function landing(Request $request, Response $response): Response {
        return AbstractHandler::renderHtml($request, $response, 'generator-landing');
    }
}

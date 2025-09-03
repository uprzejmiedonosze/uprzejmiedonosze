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
        $isPatron = $user->isFormerPatron() || $user->isPatron() || $user->isAdmin();
        return AbstractHandler::renderHtml($request, $response, 'generator', ['isPatron' => $isPatron]);
    }
}

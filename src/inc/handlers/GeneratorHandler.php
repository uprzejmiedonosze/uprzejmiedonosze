<?PHP

require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class GeneratorHandler extends AbstractHandler {
    public function generator(Request $request, Response $response): Response {
        return AbstractHandler::renderHtml($request, $response, 'generator');
    }
}

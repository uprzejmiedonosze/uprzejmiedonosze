<?PHP
require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class JpegHandler extends AbstractHandler {

    private function decode(string $hash): string {
        $decoded = (new Crypto())->decode($hash);
        logger("decoded = $decoded", true);
        return $decoded;
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function jpeg(Request $request, Response $response, $args): Response {
        $hash = $args['hash'];
        logger("hash: $hash", true);
        return AbstractHandler::renderJpeg($response, $this->decode($hash));
    }
}
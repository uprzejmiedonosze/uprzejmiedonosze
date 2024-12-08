<?PHP
require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class JpegHandler extends AbstractHandler {

    private function decode(string $hash): string {
        return \crypto\decode($hash, CRYPTO_KEY, CRYPTO_IV);
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function jpeg(Request $request, Response $response, $args): Response {
        $hash = $args['hash'];
        return AbstractHandler::renderJpeg($response, $this->decode($hash));
    }
}
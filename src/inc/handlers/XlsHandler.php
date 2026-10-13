<?PHP
require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class XlsHandler extends AbstractHandler {

    public function xls(Request $request, Response $response, $args): Response {
        $user = $request->getAttribute('user');

        if (!$user->isPatron() && !$user->isAdmin()) {
            return AbstractHandler::redirect('/patronite.html');
        }

        // decoding >10k reports takes a few seconds
        set_time_limit(120);

        $path = tempnam(sys_get_temp_dir(), 'ud-xlsx-');
        try {
            \app\appsToXlsx(\user\appsCursor($user), $user->getSanitizedName(), $path);
            return AbstractHandler::renderXls($response, $path, $user->getSanitizedName() . ".xlsx");
        } catch (\Throwable $e) {
            if (is_file($path))
                unlink($path);
            throw $e;
        }
    }
}

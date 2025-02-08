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
        $applications = \user\apps(user: $user);

        $xls = \app\appsToXlsx($applications, $user->getSanitizedName());
        
        return AbstractHandler::renderXls($response, $xls, $user->getSanitizedName().".xls");
    }
}
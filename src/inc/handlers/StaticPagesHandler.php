<?PHP

require(__DIR__ . '/../PDFGenerator.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StaticPagesHandler {

    public function application(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        [$pdf, $filename] = application2PDFById($appId);
        $response = $response->withHeader('Content-disposition', "attachment; filename=$filename");
        readfile($pdf);
        return $response;
    }

    public function package(Request $request, Response $response, $args): Response {
        $city = $args['city'];
        [$pdf, $filename] = readyApps2PDF($city);
        $response = $response->withHeader('Content-disposition', "attachment; filename=$filename");
        readfile($pdf);
        return $response;
    }
}
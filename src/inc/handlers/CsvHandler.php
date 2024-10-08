<?PHP
require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

class CsvHandler extends AbstractHandler {

    private function csv2str(Array $data): string {
        $memory = fopen('php://memory', 'r+');
        foreach($data as $row)
            fputcsv($memory, $row);
        rewind($memory);
        return rtrim(stream_get_contents($memory));
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function csv(Request $request, Response $response, $args): Response {
        $file = $args['file'];
        $stats = Array();
        switch($file) {
            case "appsByDay":
                $stats = \global_stats\appsByDay(); break;
            case "appsByCity":
                $stats = \global_stats\appsByCity(); break;
            case "byDay":
                $stats = \global_stats\statsByDay(); break;
            case "byYear":
                $stats = \global_stats\statsByYear(); break;
            case "byCarBrand":
                $stats = \global_stats\statsByCarBrand(); break;
            default: 
                throw new HttpNotFoundException($request);
        }
        
        return AbstractHandler::renderCsv($response, $this->csv2str($stats), "$file.csv");
    }
}
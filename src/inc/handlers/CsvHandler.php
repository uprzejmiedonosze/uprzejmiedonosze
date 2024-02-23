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
        global $storage;
        $file = $args['file'];
        $stats = Array();
        switch($file) {
            case "appsByDay":
                $stats = $storage->getStatsAppsByDay(); break;
            case "appsByCity":
                $stats = $storage->getStatsAppsByCity(); break;
            case "byDay":
                $stats = $storage->getStatsByDay(); break;
            case "byYear":
                $stats = $storage->getStatsByYear(); break;
            case "byCarBrand":
                $stats = $storage->getStatsByCarBrand(); break;
            default: 
                throw new HttpNotFoundException($request);
        }
        
        return AbstractHandler::renderCsv($response, $this->csv2str($stats), "$file.csv");
    }
}
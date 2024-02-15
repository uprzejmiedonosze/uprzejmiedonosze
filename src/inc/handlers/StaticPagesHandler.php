<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../PDFGenerator.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

class StaticPagesHandler extends AbstractHandler {

    function root(Request $request, Response $response): Response {
        global $storage;
        $mainPageStats = $storage->getMainPageStats();
        return AbstractHandler::renderHtml($request, $response, 'index', [
            'config' => [
                'stats' => $mainPageStats
            ]
        ]);
    }

    function rules(Request $request, Response $response): Response {
        return AbstractHandler::renderHtml($request, $response, 'regulamin', [
            'latestTermUpdate' => LATEST_TERMS_UPDATE
        ]);
    }

    function faq(Request $request, Response $response) {
        global $SM_ADDRESSES;

        $smNames = array_map(function ($sm) { return $sm->city; }, $SM_ADDRESSES);
        $collator = new Collator('pl_PL');
        $collator->sort($smNames);

        $smNames = array_unique($smNames, SORT_LOCALE_STRING);

        $SMHints = array();
        foreach ($SM_ADDRESSES as $sm) {
            if($sm->hint){
                if(!str_starts_with($sm->hint, 'Miejscowość ')) {
                    $SMHints[$sm->city] = $sm->hint;
                }
            }
        }
        $sortedSMHints = array_unique($SMHints, SORT_LOCALE_STRING);
        return AbstractHandler::renderHtml($request, $response, 'faq', [
            'smAddresses' => implode(', ', $smNames),
            'SMHints' => $sortedSMHints
        ]);
    }

    function gallery(Request $request, Response $response) {
        global $storage;
        return AbstractHandler::renderHtml($request, $response, 'galeria', [
            'appActionButtons' => false,
            'galleryByCity' => $storage->getGalleryByCity()
        ]);
    }

    public function application(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        [$path, $filename] = application2PDFById($appId);
        return AbstractHandler::renderPdf($response, $path, $filename);
    }

    private function csv2str(Array $data): string {
        $memory = fopen('php://memory', 'r+');
        foreach($data as $row)
            fputcsv($memory, $row);
        rewind($memory);
        return rtrim(stream_get_contents($memory));
    }

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
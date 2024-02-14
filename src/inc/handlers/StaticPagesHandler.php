<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../PDFGenerator.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StaticPagesHandler extends AbstractHandler {

    function root(Request $request, Response $response): Response {
        global $storage;
        $mainPageStats = $storage->getMainPageStats();
        return AbstractHandler::render($request, $response, 'index', [
            'config' => [
                'stats' => $mainPageStats
            ]
        ]);
    }

    function rules(Request $request, Response $response): Response {
        return AbstractHandler::render($request, $response, 'regulamin', [
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
        return AbstractHandler::render($request, $response, 'faq', [
            'smAddresses' => implode(', ', $smNames),
            'SMHints' => $sortedSMHints
        ]);
    }

    function gallery(Request $request, Response $response) {
        global $storage;
        return AbstractHandler::render($request, $response, 'galeria', [
            'appActionButtons' => false,
            'galleryByCity' => $storage->getGalleryByCity()
        ]);
    }

    public function application(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        [$pdf, $filename] = application2PDFById($appId);
        $response = $response->withHeader('Content-disposition', "attachment; filename=$filename");
        readfile($pdf);
        return $response;
    }
}
<?PHP
require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

class XlsHandler extends AbstractHandler {

    private function array2xls(Array &$data): string {
        $header = implode("\t", array_keys($data)) . "\n";
        function filterData(string &$str): void { 
            $str = preg_replace("/\t/", "\\t", $str); 
            $str = preg_replace("/\r?\n/", "\\n", $str); 
            if(strstr($str, '"')) $str = '"' . str_replace('"', '""', $str) . '"';
        }
        array_walk($data, 'filterData');
        $content = implode("\t", array_values($data)) . "\n"; 
        return $header . $content;
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function xls(Request $request, Response $response, $args): Response {
        global $storage;

        $app = $storage->getApplication($args['appId']);
        $data = array(
            "L.P." => '',
            "Nr służbowy" => '',
            "Osoba zgłaszająca" => $app->user->name,
            "Adres" => $app->user->address,
            "Telefon" => $app->user->msisdn ?? '',
            "Mail" => $app->user->email,
            "Znak zgłoszenia/maila" => $app->number,
            "Data wpływu" => date('Y-m-d'),
            "Data rejestr." => '',
            "Miejsce zdarzenia na ul." => $app->address->address,
            "Data zdarzenia" => $app->getDate(),
            "Godzina" => $app->getTime(),
            "Zdarzenie polegające na:" => $app->getCategory()->short . " " . $app->getExtensionsText(),
            "Podstawa prawna" => $app->getCategory()->law,
            "Pojazd" => $app->carInfo->brand,
            "Nr rej." => $app->carInfo->plateId,
            "Właściciel CEPIK" => '',
            "Adres2" => '',
            "Gmina" => '',
            "Płeć" => '',
            "Sposób zakończenia" => '',
            "Data zakończenia" => '',
            "Nr służbowy3" => '',
            "UWAGI" => $app->userComment,
            "TECZKA" => '',
            "ILOŚĆ ZGŁOSZEŃ" => ''
        );

        $xls = $this->array2xls($data);
        $fileName = $app->getAppXlsFilename();

        return AbstractHandler::renderXls($response, $xls, "$fileName");
    }
}
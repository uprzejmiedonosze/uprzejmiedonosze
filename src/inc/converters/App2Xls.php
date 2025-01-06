<?PHP

namespace app;

function App2Xls(Application &$app) {
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
        "Zdarzenie polegające na:" => $app->getCategory()->formal . " " . $app->getExtensionsText(),
        "Podstawa prawna" => $app->getCategory()->law,
        "Pojazd" => $app->carInfo->brand ?? '',
        "Nr rej." => $app->carInfo->plateId,
        "Właściciel CEPIK" => '',
        "Adres2" => '',
        "Gmina" => '',
        "Płeć" => '',
        "Sposób zakończenia" => '',
        "Data zakończenia" => '',
        "Nr służbowy3" => '',
        "Uwagi" => $app->userComment,
        "Teczka" => '',
        "Liczba zgłoszeń" => $app->getRecydywa()
    );

    return _array2xls($data);
}

function _array2xls(array &$data): string {
    $header = implode("\t", array_keys($data)) . "\n";
    function filterData(string|null &$str): void {
        $str = $str ?? '';
        $str = preg_replace("/\t/", "\\t", $str);
        $str = preg_replace("/\r?\n/", "\\n", $str);
        if (strstr($str, '"')) $str = '"' . str_replace('"', '""', $str) . '"';
    }
    array_walk($data, 'filterData');
    $content = implode("\t", array_values($data)) . "\n";
    return $header . $content;
}

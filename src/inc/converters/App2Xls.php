<?PHP

namespace app;

function app2Xls(Application &$app, bool $withHeader) {
    $data = array(
        "Numer" => '=HYPERLINK("%HTTPS%://%HOST%/ud-' . $app->id . '.html"; "' . $app->number . '")',
        "Status" => $app->getStatus()->name,
        "Data" => $app->getDate("d.MM.y H:mm"),
        "Miejsce" => '=HYPERLINK("' . $app->getMapUrl() . '"; "' . $app->getShortAddress() . '")',
        "Nr rej." => $app->carInfo->plateId,
        "Kategoria" => $app->getCategory()->formal,
        "Dodatki" => $app->getExtensionsText(),
        "Naocznie?" => $app->statements->witness ? "Tak" : "",
        "Uwagi" => $app->userComment,
        "Uwagi prywatne" => $app->privateComment,
        "RSOW" =>  trimstr2upper($app->externalId),
        "Wysłano do" => $app->guessSMData()->getEmail(),
        "Wysłania dnia"  => $app->getSentDate("d.MM.y H:mm")
    );

    return _array2xls($data, $withHeader);
}


function _array2xls(array &$data, bool $withHeader): string {
    $header = implode("\t", array_keys($data)) . "\n";
    
    array_walk($data, function (string|null &$str): void {
        $str = $str ?? '';
        $str = preg_replace("/\t/", "\\t", $str);
        $str = preg_replace("/\r?\n/", "\\n", $str);
        if (strstr($str, '"')) $str = '"' . str_replace('"', '""', $str) . '"';
    });
    $content = implode("\t", array_values($data)) . "\n";
    return ($withHeader ? $header : '') . $content;
}

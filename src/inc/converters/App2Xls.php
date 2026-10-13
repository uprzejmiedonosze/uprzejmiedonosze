<?PHP

namespace app;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;

// header => column width (in characters; fixed, auto-size would need every row in memory)
const COLUMNS = array(
    "Numer" => 14,
    "Status" => 18,
    "Data" => 16,
    "Miejsce" => 40,
    "Nr rej." => 11,
    "Kategoria" => 40,
    "Dodatki" => 30,
    "Naocznie?" => 10,
    "Uwagi" => 40,
    "Uwagi prywatne" => 30,
    "RSOW" => 16,
    "Wysłano do" => 30,
    "Wysłania dnia" => 16,
);

/**
 * Streams reports into an XLSX file at $path, one row at a time (OpenSpout),
 * so memory does not grow with the number of reports — PhpSpreadsheet kept
 * every cell in memory and ran out of it for accounts with >10k reports.
 *
 * @param iterable<Application> $apps e.g. \user\appsCursor()
 */
function appsToXlsx(iterable $apps, string $name, string $path): void {
    $options = new Options();
    $options->setProperties(new Properties(
        title: "Zgłoszenia $name",
        application: 'Uprzejmie Donoszę',
        creator: 'Uprzejmie Donoszę',
        lastModifiedBy: 'Uprzejmie Donoszę',
    ));
    $col = 1;
    foreach (COLUMNS as $width)
        $options->setColumnWidth($width, $col++);

    $writer = new Writer($options);
    $writer->openToFile($path);
    try {
        $writer->addRow(Row::fromValues(array_keys(COLUMNS), (new Style())->setFontBold()));
        foreach ($apps as $app)
            $writer->addRow(__appRow($app));
    } finally {
        $writer->close();
    }
}

function __appRow(Application $app): Row {
    return new Row([
        __link(HTTPS . '://' . HOST . '/app/' . $app->id, $app->number ?? ''),
        __text($app->getStatus()->name),
        __text($app->getDate("d.MM.y H:mm")),
        __link($app->getMapUrl(), $app->getAddress()),
        __text($app->carInfo->plateId ?? ''),
        __text($app->getCategoryFormal()),
        __text($app->getExtensionsText()),
        __text($app->statements->witness ? "Tak" : ""),
        __text($app->userComment ?? ''),
        __text($app->privateComment ?? ''),
        __text(trimstr2upper($app->externalId ?? '')),
        __text($app->guessSMData()->getEmail()),
        __text($app->getSentDate("d.MM.y H:mm")),
    ]);
}

/**
 * Always a plain string cell: Cell::fromValue() would turn user text
 * starting with "=" (e.g. a comment) into a live formula.
 */
function __text(?string $value): Cell {
    return new Cell\StringCell($value ?? '', null);
}

/**
 * Clickable cell. OpenSpout has no hyperlink support, so a HYPERLINK() formula;
 * Excel caps string literals in formulas at 255 characters.
 */
function __link(string $url, string $label): Cell {
    if ($url === '' || mb_strlen($url) > 255 || mb_strlen($label) > 255)
        return __text($label);
    $quote = fn(string $s) => '"' . str_replace('"', '""', $s) . '"';
    return new Cell\FormulaCell('=HYPERLINK(' . $quote($url) . ',' . $quote($label) . ')', null, $label);
}

<?PHP

namespace app;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;


const COLUMN_NAMES = array(
    "Numer",
    "Status",
    "Data",
    "Miejsce",
    "Nr rej.",
    "Kategoria",
    "Dodatki",
    "Naocznie?",
    "Uwagi",
    "Uwagi prywatne",
    "RSOW",
    "Wysłano do",
    "Wysłania dnia"
);

function appsToXlsx(array $apps, string $name) {

    $spreadsheet = __createEmptySpreadsheet($name);
    $sheet = $spreadsheet->getActiveSheet();

    $rowNum = 2;
    foreach ($apps as $app) {
        $sheet->setCellValue(__coordinate(1, $rowNum), $app->number);
        $sheet->getCell(__coordinate(1, $rowNum))->getHyperlink()->setUrl(HTTPS . '://' . HOST . '/ud-' . $app->id . '.html');
        $sheet->setCellValue(__coordinate(2, $rowNum), $app->getStatus()->name);
        $sheet->setCellValue(__coordinate(3, $rowNum), $app->getDate("d.MM.y H:mm"));
        $sheet->setCellValue(__coordinate(4, $rowNum), $app->getAddress());
        $sheet->getCell(__coordinate(4, $rowNum))->getHyperlink()->setUrl($app->getMapUrl());
        $sheet->setCellValue(__coordinate(5, $rowNum), $app->carInfo->plateId);
        $sheet->setCellValue(__coordinate(6, $rowNum), $app->getCategory()->formal);
        $sheet->setCellValue(__coordinate(8, $rowNum), $app->statements->witness ? "Tak" : "");
        $sheet->setCellValue(__coordinate(7, $rowNum), $app->getExtensionsText());
        $sheet->setCellValue(__coordinate(9, $rowNum), $app->userComment);
        $sheet->setCellValue(__coordinate(10, $rowNum), $app->privateComment);
        $sheet->setCellValue(__coordinate(11, $rowNum), trimstr2upper($app->externalId));
        $sheet->setCellValue(__coordinate(12, $rowNum), $app->guessSMData()->getEmail());
        $sheet->setCellValue(__coordinate(13, $rowNum), $app->getSentDate("d.MM.y H:mm"));
        $rowNum++;
        if ($rowNum % 500 === 0) // Free memory every 500 rows
            gc_collect_cycles();
    }

    $colNum = 1;
    foreach (COLUMN_NAMES as $header) {
        $sheet->setCellValue(__coordinate($colNum, 1), $header);
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colNum))->setAutoSize(true);
        $colNum++;
    }

    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    return ob_get_clean();
}

function __createEmptySpreadsheet(string $name): Spreadsheet {
    \PhpOffice\PhpSpreadsheet\Settings::setLocale('pl');
    $spreadsheet = new Spreadsheet();

    $spreadsheet->getProperties()
        ->setCreator("Uprzejmie Donoszę")
        ->setTitle("Zgłoszenia $name");

    return $spreadsheet;
}

function __coordinate(int $colNum, $rowNum): string {
    $colLetter = Coordinate::stringFromColumnIndex($colNum);
    return $colLetter . $rowNum;
}

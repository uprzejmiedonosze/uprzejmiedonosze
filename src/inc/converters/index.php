<?PHP namespace app;

require_once(__DIR__ . '/../include.php');

function checkUserFoder(string $userNumber): string{
    $baseDir = ROOT . "cdn2/$userNumber";
    if(!file_exists($baseDir))
        mkdir($baseDir, 0755, true);
    return $baseDir;
}

require(__DIR__ . '/App2Pdf.php');
require(__DIR__ . '/App2Zip.php');
require(__DIR__ . '/App2Xls.php');


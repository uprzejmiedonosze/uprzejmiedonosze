<?PHP namespace app;

function toZip(Application &$application): array{
    $userNumber = $application->getUserNumber();
    $baseDir = checkUserFoder($userNumber);

    $filename = $application->getAppFilename('.zip');
    $fullPath = "$baseDir/$filename";

    $zip = new \ZipArchive;
    if ($zip->open($fullPath, \ZIPARCHIVE::CREATE | \ZIPARCHIVE::OVERWRITE) !== TRUE) {
        throw new \Exception("Błąd tworzenia archiwum ZIP.");
    }
    $zip->addFile(ROOT . $application->contextImage->url,
        $application->getAppFilename('-1.jpg'));
    $zip->addFile(ROOT . $application->carImage->url,
        $application->getAppFilename('-2.jpg'));
    $zip->close();

    return [$fullPath, $filename];
}

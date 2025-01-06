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
        $application->getAppFilename('a.jpg'));
    $zip->addFile(ROOT . $application->carImage->url,
        $application->getAppFilename('b.jpg'));
    $zip->close();

    return [$fullPath, $filename];
}

function rmZip(Application &$application): void{
    $userNumber = $application->getUserNumber();
    $baseDir = checkUserFoder($userNumber);

    $filename = $application->getAppFilename('.zip');
    $fullPath = "$baseDir/$filename";

    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
}
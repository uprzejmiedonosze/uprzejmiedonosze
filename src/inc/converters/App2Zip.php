<?PHP namespace app;

function toZip(Application &$application): array{
    $userNumber = $application->getUserNumber();
    $baseDir = checkUserFoder($userNumber);

    $filename = $application->getAppFilename('.zip', 'Zdjecia_');
    $fullPath = "$baseDir/$filename";

    $application->ensureLocal(true);

    try {
        $zip = new \ZipArchive;
        if ($zip->open($fullPath, \ZIPARCHIVE::CREATE | \ZIPARCHIVE::OVERWRITE) !== TRUE) {
            throw new \Exception("Błąd tworzenia archiwum ZIP.");
        }
        $zip->addFile(ROOT . $application->contextImage->url,
            $application->getAppFilename('a.jpg'));
        $zip->addFile(ROOT . $application->carImage->url,
            $application->getAppFilename('b.jpg'));

        if (isset($application->thirdImage->url))
            $zip->addFile(ROOT . $application->thirdImage->url,
                $application->getAppFilename('c.jpg'));

        if (isset($application->videoUrl)) {
            $ext = pathinfo($application->videoUrl, PATHINFO_EXTENSION);
            $zip->addFile(ROOT . $application->videoUrl,
                $application->getAppFilename('.' . $ext, 'Zdjecia_video'));
        }
        $zip->close();
    } finally {
        $application->releaseLocal(true);
    }

    return [$fullPath, $filename];
}

function rmZip(Application &$application): void{
    $userNumber = $application->getUserNumber();
    $baseDir = checkUserFoder($userNumber);

    $filename = $application->getAppFilename('.zip', 'Zdjecia_');
    $fullPath = "$baseDir/$filename";

    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
}
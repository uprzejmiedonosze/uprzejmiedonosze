<?PHP namespace app;

function toZip(Application &$application): array{
    $userNumber = $application->getUserNumber();
    $baseDir = checkUserFoder($userNumber);

    $filename = $application->getAppFilename('.zip', 'Zdjecia_');
    $fullPath = "$baseDir/$filename";

    $imageKeys = array_filter([
        $application->contextImage->url,
        $application->carImage->url,
        $application->thirdImage->url ?? null,
    ]);
    foreach ($imageKeys as $key) \storage\ensure_local($key);

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
        $zip->close();
    } finally {
        foreach ($imageKeys as $key) \storage\release_local($key);
    }

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
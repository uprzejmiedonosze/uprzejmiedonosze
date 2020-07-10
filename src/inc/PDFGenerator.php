<?PHP
require_once(__DIR__ . '/include.php');

const ROOT = '/var/www/%HOST%/';

function application2PDF($appId){
    global $storage;

    if(!isset($appId)){
        raiseError("Próba pobrania zgłoszenia w formacie PDF bez wskazania appId", 400);
    }

    $application = $storage->getApplication($appId);
    if(!isset($application)){
        raiseError("Próba pobrania zgłoszenia nieistniejącego zgłoszenia $appId", 404);
    }

    $userNumber = $application->getUserNumber();
    $baseDir = ROOT . "cdn2/$userNumber";
    if(!file_exists($baseDir)){
        mkdir($baseDir, 0755, true);
    }

    $filename = $application->getAppPDFFilename();
    $pdf = "$baseDir/$appId.pdf";
    //if(!file_exists($pdf))
    tex2pdf($application, $pdf, 'application');

    return [$pdf, $filename];
}

function readyApps2PDF(){
    global $storage;

    checkIfLogged();
    $user = $storage->getCurrentUser();
    $userNumber = $user->number;

    $applications = $storage->getAllApplicationsByStatus('confirmed');

    if(sizeof($applications) == 0){
        $filename = "download-error.pdf";
    }else{
        $city = reset($applications)->getSanitizedCity();
        $filename = "Zgloszenia-$city-" . $user->getSanitizedName() . '-' . date('Y-m-d') . '.pdf';
    }

    $baseDir = ROOT . "cdn2/$userNumber";
    if(!file_exists($baseDir)){
        mkdir($baseDir, 0755, true);
    }
    $pdf = "$baseDir/$filename";
    //if(!file_exists($pdf))
    tex2pdf($applications, $pdf, 'readyApps');

    return [$pdf, $filename];
}

function tex2pdf($application, $destFile, $type) {
    if(($f = tempnam(sys_get_temp_dir(), 'tex-')) === false) {
        throw new Exception("Failed to create temporary file");
    }

    $tex_f = "$f.tex";
    $aux_f = "$f.aux";
    $out_f = "$f.out";
    $log_f = "$f.log";
    $pdf_f = "$f.pdf";

    $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
    $twig = new \Twig\Environment($loader,
    [
        'debug' => !isProd(),
        'cache' => new \Twig\Cache\FilesystemCache('/var/cache/uprzejmiedonosze.net/twig-%HOST%-%TWIG_HASH%', \Twig\Cache\FilesystemCache::FORCE_BYTECODE_INVALIDATION),
        'strict_variables' => true,
        'auto_reload' => true
    ]);

    $texFile = 'application.tex.twig';
    if($type == 'readyApps'){
        if(sizeof($application) == 0){
            $texFile = 'readyApps-error.tex.twig';
        }else{
            $texFile = 'readyApps.tex.twig';
        }
    }

    $params = [
        'app' => $application,
        'root' => realpath(ROOT)
    ];

    if($type == 'readyApps'){
        global $user;
        $params['user'] = $user; 
    }

    file_put_contents($tex_f, $twig->render($texFile, $params));

    $cmd = sprintf("/usr/bin/pdflatex -interaction nonstopmode %s", // -halt-on-error
   	escapeshellarg($tex_f));
    chdir(sys_get_temp_dir());
    exec($cmd, $output, $ret);
    unset($output);

    @unlink($tex_f);
    @unlink($aux_f);
    @unlink($log_f);
    @unlink($out_f);

    if(!file_exists($pdf_f)) {
        @unlink($f);
        throw new Exception("Output was not generated and latex returned: $ret.");
    }
    
    rename($pdf_f, $destFile);
    @unlink($f);
}
?>
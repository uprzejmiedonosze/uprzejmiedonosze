<?PHP
require_once(__DIR__ . '/include.php');
use \Exception as Exception;
use \Twig\Cache\FilesystemCache as FilesystemCache;
use \Twig\Environment as Environment;
use \Twig\Loader\FilesystemLoader as FilesystemLoader;

const ROOT = '/var/www/%HOST%/';

function application2PDFById($appId){
    global $storage;

    if(!isset($appId)){
        raiseError("Próba pobrania zgłoszenia w formacie PDF bez wskazania appId", 400);
    }

    $application = $storage->getApplication($appId);
    if(!isset($application)){
        raiseError("Próba pobrania zgłoszenia nieistniejącego zgłoszenia $appId", 404);
    }

    return application2PDF($application);
}

function application2PDF(&$application){
    $appId = $application->id;

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

/**
 * * @SuppressWarnings(ElseExpression)
 */
function readyApps2PDF($city){
    global $storage;

    checkIfLogged();
    $user = $storage->getCurrentUser();
    $userNumber = $user->number;

    $applications = $storage->getConfirmedAppsByCity($city);

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

/**
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 */
function tex2pdf($application, $destFile, $type) {
    $file = tempnam(sys_get_temp_dir(), 'tex-');
    if($file === false) {
        throw new Exception("Failed to create temporary file");
    }

    $tex_f = "$file.tex";
    $aux_f = "$file.aux";
    $out_f = "$file.out";
    $log_f = "$file.log";
    $pdf_f = "$file.pdf";

    $loader = new FilesystemLoader(__DIR__ . '/../templates');
    $twig = new Environment($loader,
    [
        'debug' => !isProd(),
        'cache' => new FilesystemCache('/var/cache/uprzejmiedonosze.net/twig-%HOST%-%TWIG_HASH%', FilesystemCache::FORCE_BYTECODE_INVALIDATION),
        'strict_variables' => true,
        'auto_reload' => true
    ]);

    $texFile = 'application.tex.twig';
    if($type == 'readyApps'){
        $texFile = 'readyApps.tex.twig';
        if(sizeof($application) == 0){
            $texFile = 'readyApps-error.tex.twig';
        }
    }

    global $CATEGORIES;
    $params = [
        'app' => $application,
        'root' => realpath(ROOT),
        'categories' => $CATEGORIES
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
        @unlink($file);
        throw new Exception("Output was not generated and latex returned: $ret.");
    }
    
    rename($pdf_f, $destFile);
    @unlink($file);
}
?>
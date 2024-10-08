<?PHP
require_once(__DIR__ . '/include.php');

use app\Application;
use \Exception as Exception;
use \Twig\Cache\FilesystemCache as FilesystemCache;
use \Twig\Environment as Environment;
use \Twig\Loader\FilesystemLoader as FilesystemLoader;
use user\User;

const ROOT = '/var/www/%HOST%/';

function application2PDFById(string $appId): array {
    if(!isset($appId)){
        throw new Exception("Próba pobrania zgłoszenia w formacie PDF bez wskazania appId", 400);
    }

    $application = \app\get($appId);
    if(!isset($application)){
        throw new Exception("Próba pobrania zgłoszenia nieistniejącego zgłoszenia $appId", 404);
    }

    return application2PDF($application);
}

function application2PDF(Application &$application): array{
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
function readyApps2PDF(User $user, string $city): array{
    $userNumber = $user->number;

    $applications = \user\appsConfirmedByCity($city);

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
    tex2pdf($applications, $pdf, 'readyApps');

    return [$pdf, $filename];
}

/**
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 */
function tex2pdf(array|Application $application, string $destFile, string $type) {
    $file = tempnam(sys_get_temp_dir(), 'tex-' . $application->id . '-');
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
    global $EXTENSIONS;
    $params = [
        'app' => $application,
        'root' => realpath(ROOT),
        'categories' => $CATEGORIES,
        'extensions' => $EXTENSIONS
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

    @unlink($aux_f);
    @unlink($out_f);

    if(!file_exists($pdf_f)) {
        @unlink($file);
        throw new Exception("Błąd generowania pliku PDF.");
    }

    @unlink($log_f);
    @unlink($tex_f);
    
    rename($pdf_f, $destFile);
    @unlink($file);
}
?>
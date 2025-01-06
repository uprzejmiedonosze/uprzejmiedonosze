<?PHP namespace app;

use \Twig\Cache\FilesystemCache as FilesystemCache;
use \Twig\Environment as Environment;
use \Twig\Loader\FilesystemLoader as FilesystemLoader;

function toPdf(Application &$application): array{
    $appId = $application->id;

    $userNumber = $application->getUserNumber();
    $baseDir = checkUserFoder($userNumber);

    $filename = $application->getAppFilename('.pdf');
    $pdf = "$baseDir/$appId.pdf";
    _tex2pdf($application, $pdf);

    return [$pdf, $filename];
}

/**
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 */
function _tex2pdf(array|Application $application, string $destFile) {
    $file = tempnam(sys_get_temp_dir(), 'tex-' . $application->id . '-');
    if($file === false) {
        throw new \Exception("Failed to create temporary file");
    }

    $tex_f = "$file.tex";
    $aux_f = "$file.aux";
    $out_f = "$file.out";
    $log_f = "$file.log";
    $pdf_f = "$file.pdf";

    $loader = new FilesystemLoader(__DIR__ . '/../../templates');
    $twig = new Environment($loader,
    [
        'debug' => !isProd(),
        'cache' => new FilesystemCache('/var/cache/uprzejmiedonosze.net/twig-%HOST%-%TWIG_HASH%', FilesystemCache::FORCE_BYTECODE_INVALIDATION),
        'strict_variables' => true,
        'auto_reload' => true
    ]);

    $texFile = 'application.tex.twig';

    global $CATEGORIES;
    global $EXTENSIONS;
    $params = [
        'app' => $application,
        'root' => realpath(ROOT),
        'categories' => $CATEGORIES,
        'extensions' => $EXTENSIONS
    ];

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
        throw new \Exception("Błąd generowania pliku PDF.");
    }

    @unlink($log_f);
    @unlink($tex_f);
    
    rename($pdf_f, $destFile);
    @unlink($file);
}

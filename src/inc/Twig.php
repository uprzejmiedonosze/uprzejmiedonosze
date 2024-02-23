<?PHP
use Slim\Views\Twig;
use \Twig\Cache\FilesystemCache as FilesystemCache;
use \Twig\Loader\FilesystemLoader as FilesystemLoader;
use \Twig\Environment as Environment;
use \Twig\TwigFunction;
use \Twig\Extension\AbstractExtension;

class TwigExtension extends AbstractExtension {
    public function getFunctions() {
        return [
            new TwigFunction('iff', function ($bool, $string) {
                if ($bool) return $string;
                return '';
            }),
            new TwigFunction('active', function ($menu, $menuPos) {
                if ($menu == $menuPos) return 'class="active"';
                return '';
            })
        ];
    }
}

function _twigConfig(): array {
    return [
        'debug' => !isProd(),
        'cache' => isProd() ? new FilesystemCache('/var/cache/uprzejmiedonosze.net/twig-%HOST%-%TWIG_HASH%', FilesystemCache::FORCE_BYTECODE_INVALIDATION) : false,
        'strict_variables' => true,
        'auto_reload' => true
    ];
}

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function initSlimTwig() {
    $twig = Twig::create(__DIR__ . '/../templates', _twigConfig());
    $twig->addExtension(new TwigExtension());
    return $twig;
}

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
function initBareTwig() {
    $loader = new FilesystemLoader(__DIR__ . '/../templates');
    $twig = new Environment($loader, _twigConfig());
    $twig->addExtension(new TwigExtension());
    return $twig;
}


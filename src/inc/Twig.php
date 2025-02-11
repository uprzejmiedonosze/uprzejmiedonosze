<?PHP
use Slim\Views\Twig;
use \Twig\Cache\FilesystemCache as FilesystemCache;
use Twig\Extension\DebugExtension;
use \Twig\Loader\FilesystemLoader as FilesystemLoader;
use \Twig\Environment as Environment;
use \Twig\TwigFunction;
use \Twig\TwigFilter;
use \Twig\Extension\AbstractExtension;

class TwigExtension extends AbstractExtension {
    public function getFunctions() {
        return [
            new TwigFunction('iff', function ($bool, $string) {
                if ($bool) return $string;
                return '';
            }),
            new TwigFunction('active', function ($menu, $menuPos, $classes="") {
                $active = ($menu == $menuPos) ? "active " : "";
                return "class=\"$active$classes\"";;
            }),
            new TwigFunction('cast_to_array', function ($object) {
                return (array)$object;
            }),
            new TwigFunction('num', 'num')
        ];
    }

    public function getFilters() {
        return [
            new TwigFilter('cast_to_array', array($this, 'castToArray'))
        ];
    }

    public function castToArray($object) {
        return (array)$object;
    }
}

function _twigConfig(): array {
    return [
        'debug' => !isProd(),
        'cache' => isProd() ? new FilesystemCache('/var/cache/uprzejmiedonosze.net/twig-' . HOST . '-' .TWIG_HASH, FilesystemCache::FORCE_BYTECODE_INVALIDATION) : false,
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
    if (!isProd()){
        $twig->addExtension(new DebugExtension());
    }

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

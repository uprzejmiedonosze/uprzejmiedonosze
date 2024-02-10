<?PHP
use Slim\Views\Twig;
use \Twig\Cache\FilesystemCache as FilesystemCache;
use \Twig\Loader\FilesystemLoader as FilesystemLoader;
use \Twig\Environment as Environment;

function _twigConfig(): array {
    return [
        'debug' => !isProd(),
        'cache' => isProd() ? new FilesystemCache('/var/cache/uprzejmiedonosze.net/twig-%HOST%-%TWIG_HASH%', FilesystemCache::FORCE_BYTECODE_INVALIDATION) : false,
        'strict_variables' => true,
        'auto_reload' => true
    ];
}

function initSlimTwig() {
    $twig = Twig::create(__DIR__ . '/../templates', _twigConfig());
    $twig->addExtension(new TwigExtension());
    return $twig;
}

function initBareTwig() {
    $loader = new FilesystemLoader(__DIR__ . '/../templates');
    $twig = new Environment($loader, _twigConfig());
    $twig->addExtension(new TwigExtension());
    return $twig;
}

class TwigExtension extends \Twig\Extension\AbstractExtension {
    public function getFunctions() {
        return [
            new \Twig\TwigFunction('iff', function ($bool, $string) {
                if ($bool) return $string;
                return '';
            }),
            new \Twig\TwigFunction('active', function ($menu, $menuPos) {
                if ($menu == $menuPos) return 'class="active"';
                return '';
            })
        ];
    }
}

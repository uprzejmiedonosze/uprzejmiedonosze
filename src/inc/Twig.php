<?PHP
use Slim\Views\Twig;
use \Twig\Cache\FilesystemCache as FilesystemCache;

function initTwig() {
    $twig = Twig::create([__DIR__ . '/../templates', __DIR__ . '/../public/api/config'], [
        'debug' => !isProd(),
        'cache' => isProd() ? new FilesystemCache('/var/cache/uprzejmiedonosze.net/twig-%HOST%-%TWIG_HASH%', FilesystemCache::FORCE_BYTECODE_INVALIDATION) : false,
        'strict_variables' => true,
        'auto_reload' => true]);
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

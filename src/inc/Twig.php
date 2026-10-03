<?PHP

use \Slim\Views\Twig;
use \Twig\Cache\FilesystemCache as FilesystemCache;
use \Twig\Environment as Environment;
use \Twig\Extension\AbstractExtension;
use \Twig\Extension\DebugExtension;
use \Twig\Loader\FilesystemLoader as FilesystemLoader;
use \Twig\TwigFilter;
use \Twig\TwigFunction;

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
            new TwigFilter('cast_to_array', array($this, 'castToArray')),
            new TwigFilter('asset_url', array($this, 'assetUrl'))
        ];
    }

    /**
     * Application image url/thumb fields (and the 'img/...' placeholder
     * fallbacks) are stored as bare storage keys, e.g. "cdn2/12/abc,ca.jpg" -
     * no leading slash, since that's also the S3 object key and local
     * filesystem path relative to ROOT. Rendered directly as <img src>,
     * a relative path resolves against the current page URL rather than
     * the site root, breaking on any page not one path segment deep
     * (e.g. /app/list). This makes it web-root-absolute for display.
     */
    public function assetUrl(?string $path): ?string {
        if ($path === null || $path === '') return $path;
        if (str_starts_with($path, '/') || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return '/' . $path;
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
        'auto_reload' => true,
        // Render via generators instead of the ob_start(callback) buffer.
        // The non-yield prod path wraps every render in an output buffer with
        // a display-handler callback (Template::render()); an ob_* call made
        // while the engine is inside that handler is an uncatchable fatal
        // ("ob_get_clean(): Cannot use output buffering in output buffering
        // display handlers", Sentry UD-PHP-PQ, first seen 2026-09-28, always on
        // multi-second /app/list renders). Yield mode uses no output buffering
        // at all, eliminating the whole class — and is the only mode left in
        // Twig 4. All templates verified to render byte-identically in both
        // modes (2026-10-02).
        'use_yield' => true,
    ];
}

function initSlimTwig() {
    $twig = Twig::create(__DIR__ . '/../templates', _twigConfig());
    $twig->addExtension(new TwigExtension());
    if (!isProd()){
        $twig->addExtension(new DebugExtension());
    }

    return $twig;
}

function initBareTwig() {
    $loader = new FilesystemLoader(__DIR__ . '/../templates');
    $twig = new Environment($loader, _twigConfig());
    $twig->addExtension(new TwigExtension());
    return $twig;
}

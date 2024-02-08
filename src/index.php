<?php
require(__DIR__ . '/../inc/include.php');
require(__DIR__ . '/../inc/middleware/HtmlMiddleware.php');
require(__DIR__ . '/../inc/middleware/PdfMiddleware.php');
require(__DIR__ . '/../inc/middleware/SessionMiddleware.php');

$DISABLE_SESSION=false;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use \Twig\Cache\FilesystemCache as FilesystemCache;
use Slim\Routing\RouteCollectorProxy;

$app = AppFactory::create();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('text/html');

$twig = Twig::create([__DIR__ . '/../templates', __DIR__ . '/../public/api/config'], [
    'debug' => !isProd(),
    'cache' => isProd() ? new FilesystemCache('/var/cache/uprzejmiedonosze.net/twig-%HOST%-%TWIG_HASH%', FilesystemCache::FORCE_BYTECODE_INVALIDATION) : false,
    'strict_variables' => true,
    'auto_reload' => true]);


class Project_Twig_Extension extends \Twig\Extension\AbstractExtension
{
    public function getFunctions()
    {
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

$twig->addExtension(new Project_Twig_Extension());

$app->add(TwigMiddleware::create($app, $twig));

$app->get('/{appId}.pdf', function (Request $request, Response $response, $args) {
    $appId = $args['appId'];
    require(__DIR__ . '/../inc/PDFGenerator.php');
    [$pdf, $filename] = application2PDFById($appId);
    $response = $response->withHeader('Content-Type', 'application/pdf');
    $response = $response->withHeader('Content-Transfer-Encoding', 'Binary');
    $response = $response->withHeader('Content-disposition', "attachment; filename=$filename");
    readfile($pdf);
})->add(new PdfMiddleware());

$app->group('', function (RouteCollectorProxy $group) use ($storage) {
    $group->get('/zapytaj-o-status.html', function (Request $request, Response $response, $args) use ($storage) {
        $sent = $storage->getSentApplications(31);

        return HtmlMiddleware::render($request, $response, 'zapytaj-o-status', [
            'applications' => $sent
        ]);
    });
})->add(new SessionMiddleware(false))->add(new HtmlMiddleware());

$app->group('', function (RouteCollectorProxy $group) use ($storage) {
    $group->get('/', function (Request $request, Response $response, $args) use ($storage) {
        $stats = $storage->getMainPageStats();
        return HtmlMiddleware::render($request, $response, 'index', [
            'config' => [
                'stats' => $stats
            ]
        ]);
    });
    
    $group->get('/ud-{appId}.html', function (Request $request, Response $response, $args) use ($storage) {
        $appId = $args['appId'];
        $application = $storage->getApplication($appId);
    
        $isAppOwner = $application->isAppOwner();
        $isAppOwnerOrAdmin = isAdmin() || $isAppOwner;
    
        return HtmlMiddleware::render($request, $response, "zgloszenie", [
            'head' => [
                'title' => "Zgłoszenie {$application->number} z dnia {$application->getDate()}",
                'shortTitle' => "Zgłoszenie {$application->number}",
                'image' => $application->contextImage->thumb,
                'description' => "Samochód o nr. rejestracyjnym {$application->carInfo->plateId} " .
                    "w okolicy adresu {$application->address->address}. {$application->getCategory()->getShort()}"
            ], 'config' => [
                'isAppOwnerOrAdmin' => $isAppOwnerOrAdmin,
                'isAppOwner' => $isAppOwner
            ], 'app' => $application
        ]);
    });

    $group->get('/regulamin.html', function (Request $request, Response $response, $args) {
        return HtmlMiddleware::render($request, $response, 'regulamin', [
            'latestTermUpdate' => LATEST_TERMS_UPDATE
        ]);
    });

    $group->get('/{route}.html', function (Request $request, Response $response, $args) {
        $ROUTES = [
            '404.html',
            'changelog.html',
            
            'brak-sm.html',

            
            'dostep-do-informacji-publicznej.html',
            'dziekujemy.html',
            'epuap.html',
            'faq.html',
            'galeria.html',
            'index.html',
            'jak-zglosic-nielegalne-parkowanie.html',
            'login-ok.html',
            'login.html',
            'maintenance.html',
            'mandat.html',
            'moje-zgloszenia.html',
            'nowe-zgloszenie.html',
            'polityka-prywatnosci.html',
            'potwierdz.html',
            'projekt.html',
            'przepisy.html',
            'register-ok.html',
            'register.html',
            'regulamin.html',
            'robtodobrze.html',
            'start.html',
            'statystyki.html',
            'wniosek-odpowiedz1.html',
            'wniosek-rpo.html',
            'wysylka.html',
            'zapytaj-o-status.html',
            'zgloszenie.html',
        ];
        $authRoutes = [
            'adm-gallery.html',

        ];
        $route = $args['route'];
    
        try {
            return HtmlMiddleware::render($request, $response, $route);
        }catch (\Twig\Error\LoaderError $error) {
            return HtmlMiddleware::render($request, $response, "404");
        }catch (\Twig\Error\RuntimeError $error) {
            return HtmlMiddleware::render($request, $response, "error", [
                "exception" => $error,
                "email" => "",
                "time" => ""
            ]);
        }
    });

    $group->map(['GET', 'POST'], '/{routes:.+}', function ($request, $response) {
        throw new HttpNotFoundException($request);
    });

})->add(new HtmlMiddleware());

$app->run();

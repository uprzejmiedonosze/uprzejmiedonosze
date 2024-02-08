<?php
require(__DIR__ . '/../inc/include.php');
require(__DIR__ . '/../inc/Twig.php');
require(__DIR__ . '/../inc/middleware/HtmlMiddleware.php');
require(__DIR__ . '/../inc/middleware/PdfMiddleware.php');
require(__DIR__ . '/../inc/middleware/SessionMiddleware.php');

$DISABLE_SESSION=false;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;

use Slim\Views\TwigMiddleware;
use Slim\Routing\RouteCollectorProxy;

$app = AppFactory::create();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('text/html');

$twig = initTwig();

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

    $group->get('/dostep-do-informacji-publicznej.html', function (Request $request, Response $response, $args) {
        $email = '<i>[xxx@xxx.pl]</i>';
        $msisdn = '<i>[XXX XXX XXX]</i>';
        $name = '<i>[Imię Nazwisko]</i>';

        if ($request->getAttribute('isRegistered')) {
            $user = $request->getAttribute('user');
            if (!empty($user->data->msisdn))
                $msisdn = $user->data->msisdn;
            $email = $user->data->email;
            $name = $user->data->name;
        }

        return HtmlMiddleware::render($request, $response, 'dostep-do-informacji-publicznej', [
            'callDate' => date('j-m-Y', strtotime('-6 hour')),
            'callTime' => date('H:i', strtotime('-6 hour')),
            'checkTime' => date('H:00', strtotime('-2 hour')),
            'msisdn' => $msisdn,
            'email' => $email,
            'name' => $name
        ]);
    });
})->add(new SessionMiddleware($mustBeRegisterer=false, $optional=true))->add(new HtmlMiddleware());

$app->group('', function (RouteCollectorProxy $group) use ($storage, $SM_ADDRESSES) {
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

    $group->get('/faq.html', function (Request $request, Response $response, $args) use ($SM_ADDRESSES) {
        $smAddresses = $SM_ADDRESSES;

        $smNames = array_map(function ($sm) { return $sm->city; }, $SM_ADDRESSES);
        $collator = new Collator('pl_PL');
        $collator->sort($smNames);

        $smNames = array_unique($smNames, SORT_LOCALE_STRING);

        $SMHints = array();
        foreach ($smAddresses as $sm) {
            if($sm->hint){
                if(!str_starts_with($sm->hint, 'Miejscowość ')) {
                    $SMHints[$sm->city] = $sm->hint;
                }
            }
        }
        $sortedSMHints = array_unique($SMHints, SORT_LOCALE_STRING);
        return HtmlMiddleware::render($request, $response, 'faq', [
            'smAddresses' => implode(', ', $smNames),
            'SMHints' => $sortedSMHints
        ]);
    });

    $group->get('/galeria.html', function (Request $request, Response $response, $args) use ($storage){
        return HtmlMiddleware::render($request, $response, 'galeria', [
            'appActionButtons' => false,
            'galleryByCity' => $storage->getGalleryByCity()
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

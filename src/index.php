<?php
require(__DIR__ . '/../inc/include.php');
require(__DIR__ . '/../inc/Twig.php');

require(__DIR__ . '/../inc/handlers/ApplicationHandler.php');
require(__DIR__ . '/../inc/handlers/SessionApiHandler.php');
require(__DIR__ . '/../inc/handlers/UserHandler.php');
require(__DIR__ . '/../inc/handlers/StaticPagesHandler.php');

require(__DIR__ . '/../inc/middleware/AuthMiddleware.php');
require(__DIR__ . '/../inc/middleware/HtmlErrorRenderer.php');
require(__DIR__ . '/../inc/middleware/HtmlMiddleware.php');
require(__DIR__ . '/../inc/middleware/JsonBodyParser.php');
require(__DIR__ . '/../inc/middleware/JsonMiddleware.php');
require(__DIR__ . '/../inc/middleware/PdfMiddleware.php');
require(__DIR__ . '/../inc/middleware/SessionMiddleware.php');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\TwigMiddleware;
use Slim\Routing\RouteCollectorProxy;


$app = AppFactory::create();
$app->addRoutingMiddleware();
$twig = initSlimTwig();

$app->add(TwigMiddleware::create($app, $twig));

$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->registerErrorRenderer('text/html', HtmlErrorRenderer::class);

$app->group('', function (RouteCollectorProxy $group) { // PDFs
    $group->get('/{appId}.pdf', StaticPagesHandler::class . 'application');
    $group->get('/city/{city}.pdf', ApplicationHandler::class . 'package')
        ->add(new RegisteredMiddleware());


$app->group('', function (RouteCollectorProxy $group) use ($storage) { // Admin stuff
    $group->get('/adm-gallery.html', function (Request $request, Response $response, $args) use ($storage) {
        $applications = $storage->getGalleryModerationApps();
        return AbstractHandler::render($request, $response, 'adm-gallery', [
            'appActionButtons' => false,
            'applications' => $applications
        ]);
    });
})  ->add(new ModeratorMiddleware())
    ->add(new SessionMiddleware())
    ->add(new HtmlMiddleware());

$app->group('/api', function (RouteCollectorProxy $group) use ($storage) { // JSON API
    $group->post('/verify-token', SessionApiHandler::class . ':verifyToken')->add(new AuthMiddleware());
    $group->post('/app/{appId}/image', SessionApiHandler::class . ':image');
    $group->patch('/app/{appId}/status/{status}', SessionApiHandler::class . ':setStatus');
    $group->patch('/app/{appId}/send', SessionApiHandler::class . ':sendApplication');
    $group->patch('/app/{appId}/gallery/add', SessionApiHandler::class . ':addToGallery');
    $group->patch('/app/{appId}/gallery/moderate/{decision}', SessionApiHandler::class . ':moderateGallery');
})->add(new JsonMiddleware())->add(new JsonBodyParser());

$app->group('', function (RouteCollectorProxy $group) use ($storage) { // Application

    $group->get('/start.html', ApplicationHandler::class . ':start');
    $group->get('/nowe-zgloszenie.html', ApplicationHandler::class . ':newApplication');

    $group->post('/potwierdz.html', ApplicationHandler::class . ':confirm');
    $group->get('/potwierdz.html', AbstractHandler::redirect('/moje-zgloszenia.html'));

    $group->post('/dziekujemy.html', ApplicationHandler::class . ':finish');
    $group->get('/dziekujemy.html', function (Request $request, Response $response, $args) {
        return $response
            ->withHeader('Location', '/moje-zgloszenia.html')
            ->withStatus(302);
    });

    $group->get('/brak-sm.html', ApplicationHandler::class . ':missingSM');
    $group->get('/moje-zgloszenia.html', ApplicationHandler::class . ':myApps');
    $group->get('/wysylka.html', ApplicationHandler::class . ':shipment');

})  ->add(new HtmlMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new SessionMiddleware());

$app->group('', function (RouteCollectorProxy $group) { // user register
    $group->get('/register.html', UserHandler::class . ':register');
    $group->post('/register-ok.html', UserHandler::class . ':finish');
    $group->get('/register-ok.html', function (Request $request, Response $response, $args) {
        return $response
            ->withHeader('Location', '/register.html')
            ->withStatus(302);
    });
})  ->add(new HtmlMiddleware())
    ->add(new LoggedInMiddleware())
    ->add(new SessionMiddleware());

$app->group('', function (RouteCollectorProxy $group) use ($storage, $SM_ADDRESSES) { // sessionless pages

    $group->get('/login-ok.html', function (Request $request, Response $response, $args) {
        $params = $request->getQueryParams();
        $next = getParam($params, 'next', '/start.html');
        
        return AbstractHandler::render($request, $response, 'login-ok', [
            'config' => [
                'signInSuccessUrl' => $next
            ]
        ]);
    });

    $group->get('/zgloszenie.html', function (Request $request, Response $response, $args) {
        $params = $request->getQueryParams();
        $appId = getParam($params, 'id');
        return $response->withHeader('Location', "/ud-$appId.html")
                ->withStatus(302);

    });
    $group->get('/ud-{appId}.html', function (Request $request, Response $response, $args) use ($storage) {
        $appId = $args['appId'];
        $application = $storage->getApplication($appId);
    
        $isAppOwner = $application->isAppOwner();
        $user = $request->getAttribute('user', null);
        $isAppOwnerOrAdmin = $user?->isAdmin() || $isAppOwner;
    
        return AbstractHandler::render($request, $response, "zgloszenie", [
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

    $group->get('/zapytaj-o-status.html', function (Request $request, Response $response, $args) use ($storage) {
        $sent = $storage->getSentApplications(31);

        return AbstractHandler::render($request, $response, 'zapytaj-o-status', [
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

        return AbstractHandler::render($request, $response, 'dostep-do-informacji-publicznej', [
            'callDate' => date('j-m-Y', strtotime('-6 hour')),
            'callTime' => date('H:i', strtotime('-6 hour')),
            'checkTime' => date('H:00', strtotime('-2 hour')),
            'msisdn' => $msisdn,
            'email' => $email,
            'name' => $name
        ]);
    });

    $group->get('/login.html', function (Request $request, Response $response, $args) {
        $params = $request->getQueryParams();
        $logout = getParam($params, 'logout', false);
        if($logout !== false){
            unset($_SESSION['token']);
            $logout = true;
        }
        $next = getParam($params, 'next', '/');
        $error = getParam($params, 'error', '');
        
        return AbstractHandler::render($request, $response, 'login', [
            'config' => [
                'signInSuccessUrl' => $next,
                'logout' => $logout,
                'error' => $error
            ]
        ]);
    });


    $group->get('/', StaticPagesHandler::class . ':root');

    $group->get('/regulamin.html', StaticPagesHandler::class . ':rules');

    $group->get('/faq.html', StaticPagesHandler::class . ':faq');

    $group->get('/galeria.html', StaticPagesHandler::class . ':gallery');

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
            return AbstractHandler::render($request, $response, $route);
        }catch (\Twig\Error\LoaderError $error) {
            return AbstractHandler::render($request, $response, "404");
        }catch (\Twig\Error\RuntimeError $error) {
            return AbstractHandler::render($request, $response, "error", [
                "exception" => $error,
                "email" => "",
                "time" => ""
            ]);
        }
    });

    $group->map(['GET', 'POST'], '/{routes:.+}', function ($request, $response) {
        throw new HttpNotFoundException($request);
    });
})  ->add(new HtmlMiddleware())
    ->add(new SessionMiddleware());


$app->run();

function getParam(array $params, string $name, mixed $default=null) {
    $param = $params[$name] ?? $default;
    if (is_null($param)) {
        throw new MissingParamException($name);
    }
    return $param;
}

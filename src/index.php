<?php

date_default_timezone_set('Europe/Warsaw');

require_once(__DIR__ . '/../inc/Logger.php');

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function resetSession() {
    session_unset();
    session_regenerate_id(true);
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
}

if (session_status() == PHP_SESSION_NONE) {
    $timeout = 60 * 60 * 24 * 31;
    ini_set("session.gc_maxlifetime", $timeout);
    ini_set("session.cookie_lifetime", $timeout);
    session_start();

    $userEmail = $_SESSION['user_email'] ?? '@?';
    if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
        $errorMsg = "Browser changed! $userEmail";
        logger($errorMsg, true);
        resetSession();
    }
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
}

require(__DIR__ . '/../inc/include.php');
require(__DIR__ . '/../inc/Twig.php');

require(__DIR__ . '/../inc/handlers/index.php');

require(__DIR__ . '/../inc/middleware/AuthMiddleware.php');
require(__DIR__ . '/../inc/middleware/CsvMiddleware.php');
require(__DIR__ . '/../inc/middleware/HtmlMiddleware.php');
require(__DIR__ . '/../inc/middleware/JsonMiddleware.php');
require(__DIR__ . '/../inc/middleware/PdfMiddleware.php');
require(__DIR__ . '/../inc/middleware/SessionMiddleware.php');

require(__DIR__ . '/../inc/middleware/HtmlErrorRenderer.php');
require(__DIR__ . '/../inc/middleware/JsonBodyParser.php');
require(__DIR__ . '/../inc/middleware/JsonErrorRenderer.php');



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

$errorMiddleware = $app->addErrorMiddleware(true, true, !isProd());
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->registerErrorRenderer('text/html', HtmlErrorRenderer::class);
$errorHandler->registerErrorRenderer('application/json', JsonErrorRenderer::class);

$app->group('', function (RouteCollectorProxy $group) { // PDFs
    $group->get('/{appId}.pdf', StaticPagesHandler::class . ':applicationPdf');
    $group->get('/city/{city}.pdf', ApplicationHandler::class . ':package')
        ->add(new RegisteredMiddleware());
})  ->add(new OptionalUserMiddleware())
    ->add(new PdfMiddleware());

$app->get('/stats/{file}.csv', CsvHandler::class . ':csv')
    ->add(new CsvMiddleware());

$app->group('', function (RouteCollectorProxy $group) use ($storage) { // Admin stuff
    $group->get('/adm-gallery.html', function (Request $request, Response $response, $args) use ($storage) {
        $applications = $storage->getGalleryModerationApps();
        return AbstractHandler::renderHtml($request, $response, 'adm-gallery', [
            'appActionButtons' => false,
            'applications' => $applications
        ]);
    });
})  ->add(new ModeratorMiddleware())
    ->add(new HtmlMiddleware());

$app->post('/api/verify-token', SessionApiHandler::class . ':verifyToken')
    ->add(new AuthMiddleware())
    ->add(new JsonMiddleware())
    ->add(new JsonBodyParser());

$app->group('/api', function (RouteCollectorProxy $group) use ($storage) { // JSON API
    $group->post('/app/{appId}/image', SessionApiHandler::class . ':image');
    $group->patch('/app/{appId}/status/{status}', SessionApiHandler::class . ':setStatus');
    $group->patch('/app/{appId}/send', SessionApiHandler::class . ':sendApplication');
    $group->patch('/app/{appId}/gallery/add', SessionApiHandler::class . ':addToGallery');
    $group->patch('/app/{appId}/gallery/moderate/{decision}', SessionApiHandler::class . ':moderateGallery')
        ->add(new ModeratorMiddleware());
})  ->add(new RegisteredMiddleware())
    ->add(new JsonMiddleware())
    ->add(new JsonBodyParser());

$app->group('', function (RouteCollectorProxy $group) use ($storage) { // Application

    $group->get('/start.html', ApplicationHandler::class . ':start');
    $group->get('/nowe-zgloszenie.html', ApplicationHandler::class . ':newApplication');

    $group->post('/potwierdz.html', ApplicationHandler::class . ':confirm');
    $group->get('/potwierdz.html', function () { return AbstractHandler::redirect('/moje-zgloszenia.html'); });

    $group->post('/dziekujemy.html', ApplicationHandler::class . ':finish');
    $group->get('/dziekujemy.html', function () { return AbstractHandler::redirect('/moje-zgloszenia.html'); });

    $group->get('/brak-sm.html', ApplicationHandler::class . ':missingSM');
    $group->get('/moje-zgloszenia.html', ApplicationHandler::class . ':myApps');
    $group->get('/wysylka.html', ApplicationHandler::class . ':shipment');

    $group->get('/zapytaj-o-status.html', ApplicationHandler::class . ':askForStatus');

})  ->add(new HtmlMiddleware())
    ->add(new RegisteredMiddleware());

$app->group('', function (RouteCollectorProxy $group) { // user register
    $group->get('/register.html', UserHandler::class . ':register');
    $group->post('/register-ok.html', UserHandler::class . ':finish');
    $group->get('/register-ok.html', function () { return AbstractHandler::redirect('/register.html'); });
})  ->add(new HtmlMiddleware())
    ->add(new LoggedInMiddleware());

$app->group('/.well-known', function (RouteCollectorProxy $group) { // user register
    $group->get('/traffic-advice', StaticPagesHandler::class . ':trafficAdvice');
    $group->get('/assetlinks.json', StaticPagesHandler::class . ':assetlinks');
})   ->add(new JsonMiddleware());

$app->group('', function (RouteCollectorProxy $group) use ($storage) { // sessionless pages
    $group->get('/', StaticPagesHandler::class . ':root');

    $group->get('/zgloszenie.html', StaticPagesHandler::class . ':applicationRedirect');
    $group->get('/ud-{appId}.html', StaticPagesHandler::class . ':applicationHtml');

    $group->get('/login.html', StaticPagesHandler::class . ':login');
    $group->get('/login-ok.html', StaticPagesHandler::class . ':loginOK');
    $group->get('/logout.html', StaticPagesHandler::class . ':logout');

    $group->get('/dostep-do-informacji-publicznej.html', StaticPagesHandler::class . ':publicInfo');

    $group->get('/regulamin.html', StaticPagesHandler::class . ':rules');
    $group->get('/faq.html', StaticPagesHandler::class . ':faq');
    $group->get('/przesluchanie.html', StaticPagesHandler::class . ':hearing');
    $group->get('/galeria.html', StaticPagesHandler::class . ':gallery');

    $group->get('/{route}.html', StaticPagesHandler::class . ':default');

    $group->map(['GET', 'POST', 'PATCH'], '/{routes:.+}', function ($request) {
        $path = $request->getUri()->getPath();
        logger("404 $path", true);
        throw new HttpNotFoundException($request);
    });
})  ->add(new HtmlMiddleware())
    ->add(new OptionalUserMiddleware());


$app->run();

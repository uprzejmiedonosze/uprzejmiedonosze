<?php

date_default_timezone_set('Europe/Warsaw');

require_once(__DIR__ . '/../inc/Logger.php');
require_once(__DIR__ . '/../inc/config.php');

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function resetSession() {
    session_unset();
    session_regenerate_id(true);
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '(user agent missing)';
}

if (session_status() == PHP_SESSION_NONE && !isset($_GET["sessionless"])) {
    $timeout = 60 * 60 * 24 * 180;
    ini_set("session.gc_maxlifetime", $timeout);
    ini_set("session.cookie_lifetime", $timeout);

    set_error_handler(fn($errno, $errstr) => logger($errstr), E_WARNING);
    session_start();
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '(user agent missing)';
    restore_error_handler();
}

require(__DIR__ . '/../inc/include.php');
require(__DIR__ . '/../inc/Twig.php');
require(__DIR__ . '/../inc/handlers/index.php');
require(__DIR__ . '/../inc/middleware/index.php');


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
$customErrorHandler = getCustomErrorHandler($app);
$errorHandler->registerErrorRenderer('text/html', HtmlErrorRenderer::class);
$errorHandler->registerErrorRenderer('application/json', JsonErrorRenderer::class);
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

$app->group('', function (RouteCollectorProxy $group) { // PDFs
    $group->get('/{appId}.pdf', ApplicationHandler::class . ':applicationPdf');
})  ->add(new RegisteredMiddleware())
    ->add(new PdfMiddleware());

$app->get('/zgloszenia.xlsx', XlsHandler::class . ':xls')
    ->add(new RegisteredMiddleware())
    ->add(new XlsMiddleware());

$app->get('/stats/{file}.csv', CsvHandler::class . ':csv')
    ->add(new CsvMiddleware());

$app->get('/img-{hash}.php', JpegHandler::class . ':jpeg')
    ->add(new JpegMiddleware());

$app->post('/webhooks/mailgun', WebhooksHandler::class . ':mailgun')
    ->add(new JsonMiddleware())
    ->add(new JsonBodyParser());

$app->post('/api/verify-token', SessionApiHandler::class . ':verifyToken')
    ->add(new AuthMiddleware())
    ->add(new JsonMiddleware())
    ->add(new JsonBodyParser());

$app->group('/api', function (RouteCollectorProxy $group) { // JSON API
    $group->post('/app/{appId}/image', SessionApiHandler::class . ':image');
    $group->delete('/app/{appId}/image/{image}', SessionApiHandler::class . ':deleteImage');
    $group->patch('/app/{appId}/status/{status}', SessionApiHandler::class . ':setStatus');
    $group->patch('/app/{appId}/fields', SessionApiHandler::class . ':setFields');
    $group->patch('/app/{appId}/send', SessionApiHandler::class . ':sendApplication');
    $group->get('/app/{appId}/recydywa', SessionApiHandler::class . ':recydywa');
    $group->get('/geo/{lat},{lng}/n', SessionApiHandler::class . ':Nominatim');
    $group->get('/geo/{lat},{lng}/m', SessionApiHandler::class . ':MapBox');
})  ->add(new RegisteredMiddleware())
    ->add(new JsonMiddleware())
    ->add(new JsonBodyParser());

$app->group('/generator', function (RouteCollectorProxy $group) {        
    $group->get('/stream', \generator\ApiAiHandler::class . ':stream');
    $group->get('/topics', \generator\ApiAiHandler::class . ':getTopics');
    $group->get('/form_types', \generator\ApiAiHandler::class . ':getForms');
    $group->get('/targets', \generator\ApiAiHandler::class . ':getTargets');
    $group->get('/parlamentary', \generator\ApiAiHandler::class . ':getParlamentary');
})  ->add(new RegisteredMiddleware())
    ->add(new JsonMiddleware())
    ->add(new JsonBodyParser());

$app->group('', function (RouteCollectorProxy $group) { // Application
    //$group->any('/start.html', function () { return AbstractHandler::redirect('/maintenance.html'); });
    //$group->any('/nowe-zgloszenie.html', function () { return AbstractHandler::redirect('/maintenance.html'); });

    $group->get('/start.html', ApplicationHandler::class . ':start');
    $group->get('/nowe-zgloszenie.html', ApplicationHandler::class . ':newApplication');

    $group->post('/potwierdz.html', ApplicationHandler::class . ':confirm');
    $group->get('/potwierdz.html', function () { return AbstractHandler::redirect('/moje-zgloszenia.html'); });

    $group->post('/dziekujemy.html', ApplicationHandler::class . ':finish');
    $group->get('/dziekujemy.html', function () { return AbstractHandler::redirect('/moje-zgloszenia.html'); });

    $group->get('/brak-sm.html', ApplicationHandler::class . ':missingSM');
    $group->get('/moje-zgloszenia.html', ApplicationHandler::class . ':myApps');
    $group->get('/my-apps-partial.html', ApplicationHandler::class . ':myAppsPartial');
    $group->get('/short-{appId}-partial.html', ApplicationHandler::class . ':applicationShortHtml');
    $group->get('/wysylka.html', ApplicationHandler::class . ':shipment');

    $group->get('/zapytaj-o-status.html', ApplicationHandler::class . ':askForStatus');

    $group->get('/tablica-rejestracyjna-{plateId}.html', StaticPagesHandler::class . ':carStatsFull');
    $group->get('/recydywa-{plateId}-partial.html', StaticPagesHandler::class . ':carStatsPartial');
    //$group->get('/top100.html', StaticPagesHandler::class . ':top100');
})  ->add(new HtmlMiddleware())
    ->add(new RegisteredMiddleware());

$app->group('', function (RouteCollectorProxy $group) { // Generator
    $group->get('/generator.html', GeneratorHandler::class . ':generator');
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

$app->group('', function (RouteCollectorProxy $group) { // session-less pages
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
        logger("not-found $path");
        throw new HttpNotFoundException($request);
    });
})  ->add(new HtmlMiddleware())
    ->add(new OptionalUserMiddleware());

$app->run();

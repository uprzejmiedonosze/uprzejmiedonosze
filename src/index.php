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

    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_secure", isProd() ? "1" : "0");
    ini_set("session.cookie_samesite", "Lax");

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

$app->post('/user/validate', SessionApiHandler::class . ':validateUser')
    ->add(new SecureApiMiddleware())
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
    $group->get('/suggested_parlamentary', \generator\ApiAiHandler::class . ':getSuggestedParlamentary');
    $group->get('/all_parlamentary', \generator\ApiAiHandler::class . ':getAllParlamentaryForSelector');
})  ->add(new RegisteredMiddleware())
    ->add(new JsonMiddleware())
    ->add(new JsonBodyParser());

$app->get('/petition/{petitionId}.docx', DocHandler::class . ':doc')
    ->add(new RegisteredMiddleware())
    ->add(new DocMiddleware());



$app->group('', function (RouteCollectorProxy $group) { // Application
    //$group->any('/start.html', function () { return AbstractHandler::redirect('/maintenance.html'); });
    //$group->any('/nowe-zgloszenie.html', function () { return AbstractHandler::redirect('/maintenance.html'); });

    $group->get('/app', StaticPagesHandler::class . ':app');
    $group->get('/app/', function () { return AbstractHandler::redirect('/app'); });
    $group->get('/app/start', ApplicationHandler::class . ':start');
    $group->get('/start.html', function () { return AbstractHandler::redirect('/app/start'); });
    $group->get('/app/new', ApplicationHandler::class . ':newApplication');
    $group->get('/nowe-zgloszenie.html', function ($request) { return AbstractHandler::redirect('/app/new' . (($qs = $request->getUri()->getQuery()) ? "?$qs" : '')); });

    // POST target kept at both URLs (a redirect can't carry a POST body/method
    // reliably) so old cached form actions keep working; GET is just the
    // refresh/back-button safety net, same as before the rename.
    $group->post('/app/confirm', ApplicationHandler::class . ':confirm');
    $group->post('/potwierdz.html', ApplicationHandler::class . ':confirm');
    $group->get('/app/confirm', function () { return AbstractHandler::redirect('/app/list'); });
    $group->get('/potwierdz.html', function () { return AbstractHandler::redirect('/app/list'); });

    $group->post('/app/done', ApplicationHandler::class . ':finish');
    $group->post('/dziekujemy.html', ApplicationHandler::class . ':finish');
    $group->get('/app/done', function () { return AbstractHandler::redirect('/app/list'); });
    $group->get('/dziekujemy.html', function () { return AbstractHandler::redirect('/app/list'); });

    $group->get('/app/list', ApplicationHandler::class . ':myApps');
    $group->get('/moje-zgloszenia.html', function () { return AbstractHandler::redirect('/app/list'); });
    $group->get('/app/partial', ApplicationHandler::class . ':myAppsPartial');
    $group->get('/my-apps-partial.html', function ($request) { return AbstractHandler::redirect('/app/partial' . (($qs = $request->getUri()->getQuery()) ? "?$qs" : '')); });
    $group->get('/app/partial/{appId}', ApplicationHandler::class . ':applicationShortHtml');
    $group->get('/short-{appId}-partial.html', function ($request, $response, $args) { return AbstractHandler::redirect('/app/partial/' . $args['appId']); });
    $group->get('/app/send', ApplicationHandler::class . ':shipment');
    $group->get('/wysylka.html', function ($request) { return AbstractHandler::redirect('/app/send' . (($qs = $request->getUri()->getQuery()) ? "?$qs" : '')); });

    $group->get('/app/ask-for-status', ApplicationHandler::class . ':askForStatus');
    $group->get('/zapytaj-o-status.html', function ($request) { return AbstractHandler::redirect('/app/ask-for-status' . (($qs = $request->getUri()->getQuery()) ? "?$qs" : '')); });

    $group->get('/tablica-rejestracyjna-{plateId}.html', StaticPagesHandler::class . ':carStatsFull');
    $group->get('/app/recydywa/partial/{plateId}', StaticPagesHandler::class . ':carStatsPartial');
    $group->get('/recydywa-{plateId}-partial.html', function ($request, $response, $args) { return AbstractHandler::redirect('/app/recydywa/partial/' . $args['plateId']); });
    //$group->get('/top100.html', StaticPagesHandler::class . ':top100');
})  ->add(new HtmlMiddleware())
    ->add(new RegisteredMiddleware());

$app->group('', function (RouteCollectorProxy $group) { // Generator
    $group->get('/napisz-pismo-do-polityka.html', GeneratorHandler::class . ':landing');
})  ->add(new HtmlMiddleware());

$app->group('', function (RouteCollectorProxy $group) { // Generator
    $group->get('/generator.html', GeneratorHandler::class . ':generator');
})  ->add(new HtmlMiddleware())
    ->add(new RegisteredMiddleware());    

$app->group('', function (RouteCollectorProxy $group) { // user register
    $group->get('/app/account', UserHandler::class . ':register');
    $group->get('/register.html', function ($request) { return AbstractHandler::redirect('/app/account' . (($qs = $request->getUri()->getQuery()) ? "?$qs" : '')); });
    $group->post('/app/register-ok', UserHandler::class . ':finish');
    $group->post('/register-ok.html', UserHandler::class . ':finish');
    $group->get('/app/register-ok', function () { return AbstractHandler::redirect('/app/account'); });
    $group->get('/register-ok.html', function () { return AbstractHandler::redirect('/app/account'); });
})  ->add(new HtmlMiddleware())
    ->add(new LoggedInMiddleware());

$app->group('/.well-known', function (RouteCollectorProxy $group) { // user register
    $group->get('/traffic-advice', StaticPagesHandler::class . ':trafficAdvice');
    $group->get('/assetlinks.json', StaticPagesHandler::class . ':assetlinks');
})   ->add(new JsonMiddleware());

$app->group('/oauth', function (RouteCollectorProxy $group) { // OAuth consent (browser)
    $group->get('/authorize', OAuthHandler::class . ':authorize');
    $group->post('/authorize', OAuthHandler::class . ':consent');
})  ->add(new HtmlMiddleware())
    ->add(new OptionalUserMiddleware());

$app->group('', function (RouteCollectorProxy $group) { // Connected applications (browser)
    $group->get('/polaczone-aplikacje.html', OAuthHandler::class . ':connectedApps');
    $group->post('/polaczone-aplikacje.html', OAuthHandler::class . ':revokeConnection');
})  ->add(new HtmlMiddleware())
    ->add(new LoggedInMiddleware());

$app->group('', function (RouteCollectorProxy $group) { // session-less pages
    $group->get('/', StaticPagesHandler::class . ':root');

    // Kanoniczny adres zgłoszenia: /app/{appId} (widok „app" dla właściciela,
    // „focus" dla osoby postronnej — patrz zgloszenie.html.twig). Dostępny
    // publicznie (OptionalUserMiddleware tej grupy), bo to też link do
    // udostępniania. appId to losowy 12-znakowy ciąg base64url, więc nie koliduje
    // ze statycznymi trasami /app/* (te i tak mają w FastRoute pierwszeństwo).
    $group->get('/app/{appId}', StaticPagesHandler::class . ':applicationHtml');
    // Stare, publiczne adresy → trwałe przekierowanie na kanoniczny /app/{appId}.
    $group->get('/zgloszenie.html', StaticPagesHandler::class . ':applicationRedirect');
    $group->get('/ud-{appId}.html', function ($request, $response, $args) { return AbstractHandler::redirect('/app/' . $args['appId'], 301); });

    $group->get('/login.html', StaticPagesHandler::class . ':login');
    $group->get('/login-ok.html', StaticPagesHandler::class . ':loginOK');
    $group->get('/logout.html', StaticPagesHandler::class . ':logout');

    $group->get('/dostep-do-informacji-publicznej.html', StaticPagesHandler::class . ':publicInfo');

    $group->get('/regulamin.html', StaticPagesHandler::class . ':rules');
    $group->get('/faq.html', StaticPagesHandler::class . ':faq');
    $group->get('/przesluchanie.html', StaticPagesHandler::class . ':hearing');
    $group->get('/galeria.html', StaticPagesHandler::class . ':gallery');

    $group->get('/misja.html', StaticPagesHandler::class . ':misja');

    // Strony przeniesione do wiki — trwałe przekierowanie (301). Statyczne trasy
    // mają w FastRoute pierwszeństwo przed wzorcem /{route}.html poniżej.
    $wikiRedirects = [
        '/przepisy.html' => 'https://uprzejmiedonosze.net/wiki/niezbednik/przepisy_i_kategorie_zgloszen',
        '/e-doreczenia.html' => 'https://uprzejmiedonosze.net/wiki/niezbednik/wysylka_przez_e-doreczenia',
        '/epuap.html' => 'https://uprzejmiedonosze.net/wiki/niezbednik/wysylka_przez_epuap',
        '/zwrot-za-przesluchanie.html' => 'https://uprzejmiedonosze.net/wiki/niezbednik/zwrot_srodkow_za_przesluchanie',
        '/zazalenie-na-brak-mandatu.html' => 'https://uprzejmiedonosze.net/wiki/niezbednik/jak_napisac_zazalenie_na_decyzje_strazy_miejskiej_lub_policji',
    ];
    foreach ($wikiRedirects as $from => $to) {
        $group->get($from, function () use ($to) { return AbstractHandler::redirect($to, 301); });
    }

    $group->get('/{route}.html', StaticPagesHandler::class . ':default');

    $group->map(['GET', 'POST', 'PATCH'], '/{routes:.+}', function ($request) {
        $path = $request->getUri()->getPath();
        logger("not-found $path");
        throw new HttpNotFoundException($request);
    });
})  ->add(new HtmlMiddleware())
    ->add(new OptionalUserMiddleware());

$app->run();

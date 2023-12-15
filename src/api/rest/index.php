<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpBadRequestException;

const INC_DIR=__DIR__ . '/../../../inc';

$DISABLE_SESSION=true;

require(INC_DIR . '/include.php');
require(INC_DIR . '/API.php');
require(INC_DIR . '/middleware/APIUtils.php');
require(INC_DIR . '/middleware/JsonBodyParser.php');
require(INC_DIR . '/middleware/ErrorRenderer.php');
require(INC_DIR . '/middleware/AuthMiddleware.php');

$app = AppFactory::create();
$app->addRoutingMiddleware();

/**
 * @param bool                  $displayErrorDetails -> Should be set to false in production
 * @param bool                  $logErrors -> Parameter is passed to the default ErrorHandler
 * @param bool                  $logErrorDetails -> Display error details in error log
 */
$errorMiddleware = $app->addErrorMiddleware(false, true, true);

$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('application/json');
$errorHandler->registerErrorRenderer('application/json', ErrorRenderer::class);

$app->add(new JsonBodyParser());

$app->options('/{routes:.+}', function ($request, $response, $args) {
    return $response;
});

$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
});

$app->get('/user', function (Request $request, Response $response, $args) use ($storage) {
    $userEmail = getUserEmail($request);
    $user = $storage->getUser($userEmail);
    $response->getBody()->write(json_encode($user));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware());

$app->get('/user/apps', function (Request $request, Response $response, $args) use ($storage) {
    $params = $request->getQueryParams();
    $status = getParam($params, 'status', 'all');
    $search = getParam($params, 'search', '%');
    $limit =  getParam($params, 'limit', 0); // 0 == no limi)t
    $offset = getParam($params, 'offset', 0);

    $userEmail = getUserEmail($request);
    $apps = $storage->getUserApplications($status, $search, $limit, $offset, $userEmail);
    
    $response->getBody()->write(json_encode($apps));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware());

$app->post('/user/register', function (Request $request, Response $response, $args) use ($storage) {
    $params = (array)$request->getParsedBody();

    try {
        $name = capitalizeName(getParam($params, 'name'));
        $address = str_replace(', Polska', '', getParam($params, 'address'));
        $msisdn = getParam($params, 'msisdn', '');
        $exposeData = (bool) getParam($params, 'exposeData', 'N') == 'Y';
    } catch (Exception $e) {
        throw new HttpBadRequestException($request, $e->getMessage());
    }

    $userEmail = getUserEmail($request);
    try {
        $user = $storage->getUser($userEmail);
    } catch (Exception $e) {
        $user = new User();
    }
    $user->updateUserData($name, $msisdn, $address, $exposeData, false, true, 200);
    $storage->saveUser($user);
    $exposeData = (bool) (getParam('POST', 'exposeData', 'N') == 'Y');

    $response->getBody()->write(json_encode($user));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware());


$app->post('/user/update', function (Request $request, Response $response, $args) use ($storage) {
    $params = (array)$request->getParsedBody();
    $name = capitalizeName(getParam($params, 'name'));
    $address = str_replace(', Polska', '', getParam($params, 'address'));
    $msisdn = getParam($params, 'msisdn', '');
    $exposeData = (bool) getParam($params, 'exposeData', 'N') == 'Y';

    $stopAgresji = (bool) (getParam($params, 'stopAgresji', 'SM') == 'SA');
    $autoSend = (bool) (getParam($params, 'autoSend', 'Y') == 'Y');
    $myAppsSize = getParam($params, 'myAppsSize', 200);

    $userEmail = getUserEmail($request);
    $user = $storage->getUser($userEmail);

    $user->updateUserData($name, $msisdn, $address, $exposeData, $stopAgresji, $autoSend, $myAppsSize);
    $storage->saveUser($user);

    $response->getBody()->write(json_encode($user));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware());

$CONFIG_FILES = Array(
    'badges', 'categories', 'extensions', 'levels', 'patronite', 'sm', 'statuses', 'stop-agresji');

$app->get('/config', function (Request $request, Response $response, $args) use ($CONFIG_FILES) {
    $response->withHeader('Content-Type', 'application/json');
    $response->getBody()->write(json_encode($CONFIG_FILES));
    return $response;
});

$app->get('/config/{name}', function (Request $request, Response $response, $args) use ($CONFIG_FILES) {
    $name = $args['name'];
    
    if (!in_array($name, $CONFIG_FILES))
        throw new HttpNotFoundException($request,
            "Config $name not found. Available " . join(", ", $CONFIG_FILES));

    $response->withHeader('Content-Type', 'application/json');
    $response->getBody()->write(file_get_contents(__DIR__ . "/../config/$name.json"));
    return $response;
});


$app->get('/app/get/{appId}', function (Request $request, Response $response, $args) use ($storage) {
    $appId = $args['appId'];
    $application = $storage->getApplication($appId);
    
    $userEmail = getUserEmail($request);

    if ($application->user->email !== $userEmail) {
        $application->user->email = '';
        $application->user->name = '';
        $application->user->address = '';
        $application->user->msisdn = '';
        $application->user->sex = 'f';
    }

    $response->getBody()->write(json_encode($application));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware());

$app->get('/geo/{lat},{lng}', function (Request $request, Response $response, $args) {
    $lat = $args['lat'];
    $lng = $args['lng'];
    $response->getBody()->write(json_encode(geoToAddress($lat, $lng, $request)));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
});

$app->map(['GET', 'POST'], '/{routes:.+}', function ($request, $response) {
    throw new HttpNotFoundException($request);
});

$app->run();

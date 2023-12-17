<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpInternalServerErrorException;

$DISABLE_SESSION=true;

const INC_DIR=__DIR__ . '/../../../inc';
require(INC_DIR . '/middleware/ApiErrorHandler.php');
set_error_handler("ApiErrorHandler");

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

// USER

$app->get('/user', function (Request $request, Response $response, $args) use ($storage) {
    $user = $request->getAttribute('user');

    $response->getBody()->write(json_encode($user));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware())->add(new LoginMiddleware(false));

$app->get('/user/apps', function (Request $request, Response $response, $args) use ($storage) {
    $params = $request->getQueryParams();
    $status = getParam($params, 'status', 'all');
    $search = getParam($params, 'search', '%');
    $limit =  getParam($params, 'limit', 0); // 0 == no limi)t
    $offset = getParam($params, 'offset', 0);

    $user = $request->getAttribute('user');
    $apps = $storage->getUserApplications($status, $search, $limit, $offset, $user->getEmail());
    
    $response->getBody()->write(json_encode($apps));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware())->add(new LoginMiddleware());

$app->post('/user/register', function (Request $request, Response $response, $args) use ($storage) {
    $params = (array)$request->getParsedBody();

    try {
        $name = capitalizeName(getParam($params, 'name'));
        $address = str_replace(', Polska', '', getParam($params, 'address'));
        $msisdn = getParam($params, 'msisdn', '');
        $exposeData = (bool) getParam($params, 'exposeData', false);
    } catch (Exception $e) {
        throw new HttpBadRequestException($request, $e->getMessage());
    }

    try {
        $user = $request->getAttribute('user');
    } catch (Exception $e) {
        $user = new User();
    }
    $user->updateUserData($name, $msisdn, $address, $exposeData, false, true, 200);
    $storage->saveUser($user);
    $request = $request->withAttribute('user', $user);

    $response->getBody()->write(json_encode($user));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware())->add(new LoginMiddleware(false));


$app->post('/user/update', function (Request $request, Response $response, $args) use ($storage) {
    $params = (array)$request->getParsedBody();
    $name = capitalizeName(getParam($params, 'name'));
    $address = str_replace(', Polska', '', getParam($params, 'address'));
    $msisdn = getParam($params, 'msisdn', '');
    $exposeData = (bool) getParam($params, 'exposeData', 'N') == 'Y';

    $stopAgresji = (bool) (getParam($params, 'stopAgresji', 'SM') == 'SA');
    $autoSend = (bool) (getParam($params, 'autoSend', 'Y') == 'Y');
    $myAppsSize = getParam($params, 'myAppsSize', 200);

    $user = $request->getAttribute('user');

    $user->updateUserData($name, $msisdn, $address, $exposeData, $stopAgresji, $autoSend, $myAppsSize);
    $storage->saveUser($user);
    $request = $request->withAttribute('user', $user);

    $response->getBody()->write(json_encode($user));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware())->add(new LoginMiddleware());

// CONFIG

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

// APPLICATION

$app->get('/app/get/{appId}', function (Request $request, Response $response, $args) use ($storage) {
    $appId = $args['appId'];
    $application = $storage->getApplication($appId);
    
    $user = $request->getAttribute('user');
    $application = $request->getAttribute('application');

    if ($application->user->email !== $user->getEmail()) {
        $application->user->email = '';
        $application->user->name = '';
        $application->user->address = '';
        $application->user->msisdn = '';
        $application->user->sex = 'f';
    }

    $response->getBody()->write(json_encode($application));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware())->add(new LoginMiddleware())->add(new AppMiddleware(false));

$app->post('/app/update/{appId}', function (Request $request, Response $response, $args) {
    $appId = $args['appId'];
    $params = (array)$request->getParsedBody();

    $plateId = getParam($params, 'plateId');
    $address = getParam($params, 'address'); // Mazurska 37, Szczecin
    $city = getParam($params, 'city');
    $voivodeship = getParam($params, 'voivodeship');
    $district = getParam($params, 'district');
    $dtFromPicture = (bool)getParam($params, 'dtFromPicture'); // 1|0 - was date and time extracted from picture?

    $datetime = getParam($params, 'datetime'); // "2018-02-02T19:48:10"

    $latlng = getParam($params, 'latlng'); // lat,lng: 53.431786,14.551586
    $comment = getParam($params, 'comment', '');
    $category = intval(getParam($params, 'category'));

    $witness = getParam($params, 'witness');

    $extensions = getParam($params, 'extensions', ''); // "6,7", "6", "", missing
    $extensions = array_filter(explode(',', $extensions));

    $fullAddress = new JSONObject();
    $fullAddress->address = $address;
    $fullAddress->city = $city;
    $fullAddress->voivodeship = $voivodeship;
    $fullAddress->latlng = $latlng;
    $fullAddress->district = $district;

    $user = $request->getAttribute('user');
    
    try {
        updateApplication($appId, $datetime, $dtFromPicture, $category, $fullAddress,
            $plateId, $comment, $witness, $extensions, $user->getEmail());
    } catch (Exception $e) {
        throw new HttpForbiddenException($request, $e->getMessage());
    }

    return $response;
})->add(new AuthMiddleware())->add(new LoginMiddleware())->add(new AppMiddleware());


$app->post('/app/update/{appId}/status/{status}', function (Request $request, Response $response, $args) use ($storage) {
    $status = $args['status'];
    $application = $request->getAttribute('application');
    try {
        $application = setStatus($status, $application->id);
    } catch (Exception $e) {
        throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
    }
    $response->getBody()->write(json_encode($application));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware())->add(new LoginMiddleware())->add(new AppMiddleware());


$app->post('/app/{appId}/send', function (Request $request, Response $response, $args) use ($storage) {
    $appId = $args['appId'];
    $application = $storage->getApplication($appId);
    $user = $request->getAttribute('user');

    if ($application->user->email !== $user->getEmail()) {
        throw new HttpForbiddenException($request, "User '{$user->getEmail()}' is not allowed to send app '$appId'");
    }

    $application = sendApplication($appId);
    $response->getBody()->write(json_encode($application));
    $response->withHeader('Content-Type', 'application/json');
    return $response;
})->add(new AuthMiddleware())->add(new LoginMiddleware())->add(new AppMiddleware());


// GEO

$app->get('/geo/{lat},{lng}', function (Request $request, Response $response, $args) {
    $lat = $args['lat'];
    $lng = $args['lng'];
    try {
        $response->getBody()->write(json_encode(geoToAddress($lat, $lng, $request)));
    } catch (Exception $e) {
        if ($e->code ?? -1 == 404) {
            throw new HttpNotFoundException($request, $e->getMessage(), $e);
        }
        throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
    }
    $response->withHeader('Content-Type', 'application/json');
    return $response;
});

// OTHER

$app->map(['GET', 'POST'], '/{routes:.+}', function ($request, $response) {
    throw new HttpNotFoundException($request);
});

$app->run();

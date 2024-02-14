<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Exception\HttpException;

$DISABLE_SESSION=true;

const INC_DIR=__DIR__ . '/../../../inc';
require(INC_DIR . '/middleware/ApiErrorHandler.php');
set_error_handler("ApiErrorHandler");

require(INC_DIR . '/include.php');
require(INC_DIR . '/API.php');
require(INC_DIR . '/middleware/JsonBodyParser.php');
require(INC_DIR . '/middleware/JsonErrorRenderer.php');
require(INC_DIR . '/middleware/AuthMiddleware.php');
require(INC_DIR . '/middleware/UserMiddleware.php');
require(INC_DIR . '/middleware/AppMiddleware.php');

$app = AppFactory::create();
$app->addRoutingMiddleware();

/**
 * @param bool                  $displayErrorDetails -> Should be set to false in production
 * @param bool                  $logErrors -> Parameter is passed to the default ErrorHandler
 * @param bool                  $logErrorDetails -> Display error details in error log
 */
$errorMiddleware = $app->addErrorMiddleware(false, false, false);

$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('application/json');
$errorHandler->registerErrorRenderer('application/json', JsonErrorRenderer::class);

$jsonErrorHandler = function (
    ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    $payload = exceptionToErrorJson($exception);
    $response = $app->getResponseFactory()->createResponse();
    $response = $response->withStatus($payload['status']);
    $response->getBody()->write(
        json_encode($payload, JSON_UNESCAPED_UNICODE)
    );

    return $response;
};

$errorMiddleware->setDefaultErrorHandler($jsonErrorHandler);


$app->add(new JsonBodyParser());

$app->options('/{routes:.+}', function ($request, $response, $args) {
    return $response;
});

$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PATCH')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');
});

// USER

$app->get('/api/rest/user', function (Request $request, Response $response, $args) {
    return $response;
})  ->add(new AddStatsMiddleware())
    ->add(new UserMiddleware($createIfNonExists=false))
    ->add(new AuthMiddleware());

$app->patch('/api/rest/user', function (Request $request, Response $response, $args) {
    return $response;
})  ->add(new AddStatsMiddleware())
    ->add(new UserMiddleware(true))
    ->add(new AuthMiddleware());

$app->patch('/api/rest/user/confirm-terms', function (Request $request, Response $response, $args) use ($storage) {
    $user = $request->getAttribute('user');
    $user->confirmTerms();
    $storage->saveUser($user);
    $user->isTermsConfirmed = $user->checkTermsConfirmation();
    return $response;
})  ->add(new RegisteredMiddleware())
    ->add(new AddStatsMiddleware())
    ->add(new UserMiddleware($createIfNonExists=false))
    ->add(new AuthMiddleware());

$app->post('/api/rest/user', function (Request $request, Response $response, $args) use ($storage) {
    $params = (array)$request->getParsedBody();
    $name = getParam($params, 'name');
    $address = getParam($params, 'address');
    $msisdn = getParam($params, 'msisdn', '');
    $exposeData = getParam($params, 'exposeData', 'N') == 'Y';
    if ($exposeData) throw new HttpBadRequestException($request, "Naprawdę chcesz ukrywać swoje dane.");
    $stopAgresji = getParam($params, 'stopAgresji', 'SM') == 'SA';
    $autoSend = getParam($params, 'autoSend', 'Y') == 'Y';
    if (!$autoSend) throw new HttpBadRequestException($request, "Odmawiam wyłączenia funkcji automatycznej wysyłki zgłoszeń");
    $myAppsSize = getParam($params, 'myAppsSize', 200);

    $user = $request->getAttribute('user');

    $user->updateUserData($name, $msisdn, $address, $exposeData, $stopAgresji, $autoSend, $myAppsSize);
    $storage->saveUser($user);
    $user->isRegistered = $user->isRegistered();
    $request = $request->withAttribute('user', $user);
    return $response;
})  ->add(new AddStatsMiddleware())
    ->add(new UserMiddleware($createIfNonExists=false))
    ->add(new AuthMiddleware());


$app->get('/api/rest/user/apps', function (Request $request, Response $response, $args) use ($storage) {
    $params = $request->getQueryParams();
    $status = getParam($params, 'status', 'all');
    $search = getParam($params, 'search', '%');
    $limit =  getParam($params, 'limit', 0); // 0 == no limit
    $offset = getParam($params, 'offset', 0);

    $user = $request->getAttribute('user');
    $apps = $storage->getUserApplications($status, $search, $limit, $offset, $user->getEmail());
    
    $response->getBody()->write(json_encode($apps));
    return $response;
})  ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new AuthMiddleware());

// CONFIG

$CONFIG_FILES = Array(
    'badges', 'categories', 'extensions', 'levels', 'patronite', 'sm', 'statuses', 'stop-agresji', "terms");

$app->get('/api/rest/config', function (Request $request, Response $response, $args) use ($CONFIG_FILES) {
    $response->getBody()->write(json_encode($CONFIG_FILES));
    return $response;
});

$app->get('/api/rest/config/categories', function (Request $request, Response $response, $args) {
    $categories = file_get_contents(__DIR__ . "/../config/categories.json");
    $categories = json_decode($categories, true);
    array_walk($categories, function(&$val, $key) { $val["id"] = (string)$key; });
    $response->getBody()->write(json_encode(array_values($categories)));
    return $response;
});

$app->get('/api/rest/config/terms', function (Request $request, Response $response, $args) {
    $terms = generate('regulamin.json.twig', ['latestTermUpdate' => LATEST_TERMS_UPDATE]);
    $response->getBody()->write($terms);
    return $response;
});

$app->get('/api/rest/config/{name}', function (Request $request, Response $response, $args) use ($CONFIG_FILES) {
    $name = $args['name'];

    if (!in_array($name, $CONFIG_FILES))
        throw new HttpNotFoundException($request,
            "Nie znam konfiguracji o nazwie '$name'");

    $response->getBody()->write(file_get_contents(__DIR__ . "/../config/$name.json"));
    return $response;
});

// APPLICATION

$app->post('/api/rest/app/new', function (Request $request, Response $response, $args) use ($storage) {
    $user = $request->getAttribute('user');
    $application = Application::withUser($user);
    $storage->saveApplication($application);
    unset($application->browser);
    $response->getBody()->write(json_encode($application));
    return $response;
})  ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new AuthMiddleware());

$app->get('/api/rest/app/{appId}', function (Request $request, Response $response, $args) {
    $user = $request->getAttribute('user');
    $application = $request->getAttribute('application');

    $application->formattedText = json_decode(generate('_application.json.twig', ['app' => $application]));

    if ($application->user->email !== $user->getEmail()) {
        $application->user->email = '';
        $application->user->name = '';
        $application->user->address = '';
        $application->user->msisdn = '';
        $application->user->sex = 'f';
    }

    $response->getBody()->write(json_encode($application));
    return $response;
})  ->add(new AppMiddleware($failOnWrongOwnership=false))
    ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new AuthMiddleware());

$app->post('/api/rest/app/{appId}', function (Request $request, Response $response, $args) {
    $appId = $args['appId'];
    $params = (array)$request->getParsedBody();

    $plateId = getParam($params, 'plateId');
    $address = getParam($params, 'address'); // Mazurska 37, Szczecin
    $city = getParam($params, 'city');
    $voivodeship = getParam($params, 'voivodeship');
    $district = getParam($params, 'district');
    $dtFromPicture = getParam($params, 'dtFromPicture') == 1; // 1|0 - was date and time extracted from picture?

    $datetime = getParam($params, 'datetime'); // "2018-02-02T19:48:10"

    $lat = getParam($params, 'lat');
    $lng = getParam($params, 'lng');
    $comment = getParam($params, 'comment', '');
    $category = intval(getParam($params, 'category'));

    $witness = getParam($params, 'witness');

    $extensions = getParam($params, 'extensions', ''); // "6,7", "6", "", missing
    $extensions = array_filter(explode(',', $extensions));

    $fullAddress = new JSONObject();
    $fullAddress->address = $address;
    $fullAddress->city = $city;
    $fullAddress->voivodeship = $voivodeship;
    $fullAddress->lat = $lat;
    $fullAddress->lng = $lng;
    $fullAddress->district = $district;

    $user = $request->getAttribute('user');
    
    try {
        $application = updateApplication($appId, $datetime, $dtFromPicture, $category, $fullAddress,
            $plateId, $comment, $witness, $extensions, $user);
    } catch (Exception $e) {
        throw new HttpForbiddenException($request, $e->getMessage(), $e);
    }
    unset($application->browser);
    $response->getBody()->write(json_encode($application));
    return $response;
})  ->add(new AppMiddleware())
    ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new AuthMiddleware());


$app->patch('/api/rest/app/{appId}/status/{status}', function (Request $request, Response $response, $args) {
    $status = $args['status'];
    $application = $request->getAttribute('application');
    $user = $request->getAttribute('user');
    try {
        $application = setStatus($status, $application->id, $user);
    } catch (Exception $e) {
        throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
    }
    unset($application->browser);
    $response->getBody()->write(json_encode($application));
    return $response;
})  ->add(new AppMiddleware())
    ->add(new TermsConfirmedMiddleware())    
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new AuthMiddleware());

$app->post('/api/rest/app/{appId}/image', function (Request $request, Response $response, $args) {
    $params = (array)$request->getParsedBody();

    $imageUri = getParam($params, 'carImage', -1);
    $pictureType = 'carImage';
    if ($imageUri == -1) {
        $imageUri = getParam($params, 'contextImage');
        $pictureType = 'contextImage';
    }

    list($type, $imageBytes) = explode(',', $imageUri);
    
    // valid only for $pictureType == 'carImage'
    $dateTime = getParam($params, 'dateTime', ''); // date&time of application event, in ISO format: "2018-02-02T19:48:10"
    $lat = getParam($params, 'lat', '');
    $lng = getParam($params, 'lng', '');
    $latLng = null;
    if ($lat && $lng) $latLng = normalizeLatLng($lat, $lng);
    $dtFromPicture = !!$dateTime;

    $imagemime = getimagesize($imageUri);
    if (empty($imagemime['mime']) || strpos($imagemime['mime'], 'image/') !== 0)
        throw new HttpBadRequestException($request, "Przekazany plik nie jest obrazkiem");

    if (strlen(rtrim($imageBytes, '=')) * 0.75 > 500000)
        throw new HttpBadRequestException($request, "Zbyt duże zdjęcie (>500kb)");

    $ext = substr($imagemime['mime'], 6);
    if (!in_array($ext, ['png', 'jpeg', 'jpg']))
        throw new HttpException($request, "Niewspierane rozszerzenie $ext", 415);
    
    $application = $request->getAttribute('application');
    $application = uploadImage($application, $pictureType, $imageBytes, $dateTime, $dtFromPicture, $latLng);
    unset($application->browser);
    $response->getBody()->write(json_encode($application));
    return $response;
})  ->add(new AppMiddleware())
    ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new AuthMiddleware());

$app->patch('/api/rest/app/{appId}/send', function (Request $request, Response $response, $args) use ($storage) {
    $appId = $args['appId'];
    $application = $storage->getApplication($appId);
    $user = $request->getAttribute('user');

    if ($application->user->email !== $user->getEmail()) {
        throw new HttpForbiddenException($request, "Użytkownik '{$user->getEmail()}' nie ma uprawnień do wysłania zgłoszenia '$appId'");
    }

    $application = sendApplication($appId);
    unset($application->browser);
    $response->getBody()->write(json_encode($application));
    return $response;
})  ->add(new AppMiddleware())
    ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new AuthMiddleware());


// GEO

$app->get('/api/rest/geo/{lat},{lng}/g', function (Request $request, Response $response, $args) {
    $lat = $args['lat'];
    $lng = $args['lng'];
    try {
        $response->getBody()->write(json_encode(GoogleMaps($lat, $lng)));
    } catch (Exception $e) {
        if ($e->getCode() ?? -1 == 404) {
            throw new HttpNotFoundException($request, $e->getMessage(), $e);
        }
        throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
    }
    return $response;
});

$app->get('/api/rest/geo/{lat},{lng}/n', function (Request $request, Response $response, $args) {
    $lat = $args['lat'];
    $lng = $args['lng'];

    $result = Nominatim($lat, $lng);
    $response->getBody()->write(json_encode($result));
    return $response;
});

$app->get('/api/rest/geo/{lat},{lng}/m', function (Request $request, Response $response, $args) {
    $lat = $args['lat'];
    $lng = $args['lng'];

    $result = MapBox($lat, $lng);
    $response->getBody()->write(json_encode($result));
    return $response;
});


// OTHER

$app->map(['GET', 'POST', 'PATCH'], '/{routes:.+}', function ($request, $response) {
    throw new HttpNotFoundException($request);
});

$app->run();

function getParam(array $params, string $name, mixed $default=null) {
    $param = $params[$name] ?? $default;
    if (is_null($param)) {
        throw new MissingParamException($name);
    }
    return $param;
}


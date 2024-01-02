<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
require(INC_DIR . '/middleware/ErrorRenderer.php');
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
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PATCH')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');
});

// USER

$app->get('/user', function (Request $request, Response $response, $args) {
    return $response;
})  ->add(new AddStatsMiddleware())
    ->add(new UserMiddleware($createIfNonExists=false))
    ->add(new AuthMiddleware());

$app->patch('/user', function (Request $request, Response $response, $args) {
    return $response;
})  ->add(new AddStatsMiddleware())
    ->add(new UserMiddleware(true))
    ->add(new AuthMiddleware());

$app->patch('/user/confirm-terms', function (Request $request, Response $response, $args) use ($storage) {
    $user = $request->getAttribute('user');
    $user->confirmTerms();
    $storage->saveUser($user);
    $user->isTermsConfirmed = $user->checkTermsConfirmation();
    return $response;
})  ->add(new RegisteredMiddleware())
    ->add(new AddStatsMiddleware())
    ->add(new UserMiddleware($createIfNonExists=false))
    ->add(new AuthMiddleware());

$app->post('/user', function (Request $request, Response $response, $args) use ($storage) {
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


$app->get('/user/apps', function (Request $request, Response $response, $args) use ($storage) {
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

$app->get('/config', function (Request $request, Response $response, $args) use ($CONFIG_FILES) {
    $response->getBody()->write(json_encode($CONFIG_FILES));
    return $response;
});

$app->get('/config/{name}', function (Request $request, Response $response, $args) use ($CONFIG_FILES) {
    $name = $args['name'];
    
    if (!in_array($name, $CONFIG_FILES))
        throw new HttpNotFoundException($request,
            "Nie znam konfiguracji o nazwie '$name'");

    if ($name == "terms") {
        $terms = generate('regulamin.json.twig', ['latestTermUpdate' => LATEST_TERMS_UPDATE]);
        $response->getBody()->write($terms);
        return $response;
    }
    $response->getBody()->write(file_get_contents(__DIR__ . "/../config/$name.json"));
    return $response;
});

// APPLICATION

$app->post('/app/new', function (Request $request, Response $response, $args) use ($storage) {   
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

$app->get('/app/{appId}', function (Request $request, Response $response, $args) use ($storage) {   
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

$app->post('/app/{appId}', function (Request $request, Response $response, $args) {
    $appId = $args['appId'];
    $params = (array)$request->getParsedBody();

    $plateId = getParam($params, 'plateId');
    $address = getParam($params, 'address'); // Mazurska 37, Szczecin
    $city = getParam($params, 'city');
    $voivodeship = getParam($params, 'voivodeship');
    $district = getParam($params, 'district');
    $dtFromPicture = getParam($params, 'dtFromPicture') == 1; // 1|0 - was date and time extracted from picture?

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
        $application = updateApplication($appId, $datetime, $dtFromPicture, $category, $fullAddress,
            $plateId, $comment, $witness, $extensions, $user->getEmail());
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


$app->patch('/app/{appId}/status/{status}', function (Request $request, Response $response, $args) use ($storage) {
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

$app->post('/app/{appId}/image', function (Request $request, Response $response, $args) use ($storage) {
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
    $latLng = getParam($params, 'latLng', ''); // lat,lng: 53.431786,14.551586
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

$app->patch('/app/{appId}/send', function (Request $request, Response $response, $args) use ($storage) {
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

// @TODO, params does not support dots inside
$app->get('/geo/{lat},{lng}', function (Request $request, Response $response, $args) {
    $lat = $args['lat'];
    $lng = $args['lng'];
    try {
        $response->getBody()->write(json_encode(GoogleMaps($lat, $lng)));
    } catch (Exception $e) {
        if ($e->code ?? -1 == 404) {
            throw new HttpNotFoundException($request, $e->getMessage(), $e);
        }
        throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
    }
    return $response;
});

$app->get('/geo2/{lat},{lng}', function (Request $request, Response $response, $args) {
    $lat = $args['lat'];
    $lng = $args['lng'];

    $result = OpenStreetMaps($lat, $lng);
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

function GoogleMaps($lat, $lng) {
    $json = geoToAddress($lat, $lng);
    $result = new JSONObject();

    $formatted_address = $json->formatted_address;
    $formatted_address = str_replace(", Polska", "", $formatted_address);
    $formatted_address = preg_replace("/\d\d-\d\d\d\s/", "", $formatted_address);
    $formatted_address = preg_replace("/\/\d+[a-zA-Z]?, /", ", ", $formatted_address);
    $result->formatted_address = $formatted_address;

    function findInGoogleOutput($json, $key, $longName=true) {
        $result = array_filter($json->address_components, function($ac) use ($key) {
            return in_array($key, $ac->types);
        });
        $result = reset($result);
        if(!$result) return null;
        if ($longName) return $result->long_name;
        return $result->short_name;
    }
    $result->voivodeship = findInGoogleOutput($json, "administrative_area_level_1");
    $result->voivodeship = mb_convert_case(str_replace("Województwo ", "", $result->voivodeship), MB_CASE_TITLE, 'UTF-8');
    $result->country = findInGoogleOutput($json, "country");
    $result->city = findInGoogleOutput($json, "locality");
    $result->postcode = findInGoogleOutput($json, "postal_code");
    $result->district = findInGoogleOutput($json, "sublocality_level_1", false);
    $result->suburb = null;
    $result->json = $json;
    return $result;
}

function OpenStreetMaps($lat, $lng) {
    $ch = curl_init("https://nominatim.openstreetmap.org/reverse?lat=$lat&lon=$lng&format=jsonv2&addressdetails=1");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_REFERER, "https://uprzejmiedonosze.net");
    $output = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        logger("Nie udało się pobrać danych latlng: $error");
        throw new Exception("Nie udało się pobrać odpowiedzi z serwerów OpenStreetMap: $error", 500);
    }
    curl_close($ch);

    $json = json_decode($output);
    if (!json_last_error() === JSON_ERROR_NONE) {
        logger("Parsowanie JSON z OpenStreetMap " . $output . " " . json_last_error_msg());
        throw new Exception("Bełkotliwa odpowiedź z serwerów OpenStreetMap:. $output", 500);
    }
    if (!$json || isset($json->error)) {
        throw new Exception("Brak wyników z serwerów OpenStreetMap dla $lat, $lng: $output", 404);
    }
    if ($json->address) {
        $result = new JSONObject();
        logger(print_r($json->address, true));
        $road = $json->address->road ?? "";
        $house_number = isset($json->address->house_number) ? " " . $json->address->house_number : "";
        $city = $json->address->city ?? '';
        $result->formattedAddress = "$road$house_number, {$city}";
        $result->country = $json->address->country;
        $result->voivodeship = mb_convert_case(str_replace("województwo ", "", $json->address->state), MB_CASE_TITLE, 'UTF-8');
        $result->city = $json->address->city ?? null;
        $result->postcode = $json->address->postcode ?? null;
        $result->district = $json->address->borough ?? null;
        $result->suburb = $json->address->suburb ?? null;

        $result->json = $json;

        $result->isValid = isset($json->address->road) && isset($json->address->house_number);
        return $result;
    }
    throw new Exception("Niepoprawna odpowiedź z serwerów OpenStreetMap: $output", 500);
}
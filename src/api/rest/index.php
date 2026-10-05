<?php

use app\Application;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpTooManyRequestsException;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

$DISABLE_SESSION=true;

const INC_DIR=__DIR__ . '/../../../inc';
require(INC_DIR . '/middleware/ApiErrorHandler.php');
set_error_handler("ApiErrorHandler");

require(INC_DIR . '/include.php');
require(INC_DIR . '/API.php');
require(INC_DIR . '/middleware/JsonBodyParser.php');
require(INC_DIR . '/middleware/JsonErrorRenderer.php');
require(INC_DIR . '/middleware/AuthMiddleware.php');
require(INC_DIR . '/middleware/TokenSessionMiddleware.php');
require(INC_DIR . '/middleware/UserMiddleware.php');
require(INC_DIR . '/middleware/AppMiddleware.php');
require(INC_DIR . '/Twig.php');
require(INC_DIR . '/integrations/Vision.php');
require_once(INC_DIR . '/UserRemoval.php');

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
    $code = $exception->getCode();
    if ($exception instanceof HttpException) {
        $code = $exception->getCode();
    }
    if (!is_int($code) || $code < 100 || $code > 599) {
        $code = 500;
    }
    $response = $response->withStatus($code);
    $response->getBody()->write($payload);
    return $response;
};

$errorMiddleware->setDefaultErrorHandler($jsonErrorHandler);


$app->add(new JsonBodyParser());

$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

$app->add(function ($request, $handler) {
    $response = $handler->handle($request);

    $allowedOrigin = getCorsOrigin($request);
    if ($allowedOrigin) {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PATCH')
            ->withHeader('Access-Control-Allow-Credentials', 'true');
    }

    // JSON by default; a handler that sets its own type (e.g. the PNG map preview) keeps it
    return $response->hasHeader('Content-Type') ? $response : $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
});

$app->group('/api/rest/user', function (RouteCollectorProxy $group) { // USER
    $group->get('/', function (Request $request, Response $response) {
        // `lastLocation` ("lat,lng") = ostatnie zgłoszenie, a gdy go brak – geokodowany adres zamieszkania (jak na webie
        // w ApplicationHandler); gdy nic nie wiadomo, pole jest nieustawione (klient użyje środka Polski).
        $request->getAttribute('user')?->getLastLocation();
        return $response;
    })  ->add(new AddStatsMiddleware())
        ->add(new UserMiddleware(createIfNonExists: false))
        ->add(new TokenSessionMiddleware())
        ->add(new AuthMiddleware());
    
    $group->patch('/', function (Request $request, Response $response) {
        return $response;
    })  ->add(new AddStatsMiddleware())
        ->add(new UserMiddleware(createIfNonExists: true))
        ->add(new TokenSessionMiddleware())
        ->add(new AuthMiddleware());
    
    $group->patch('/confirm-terms', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        $user->confirmTerms();
        \user\save($user);
        $user->isTermsConfirmed = $user->checkTermsConfirmation();
        return $response;
    })  ->add(new RegisteredMiddleware())
        ->add(new AddStatsMiddleware())
        ->add(new UserMiddleware(createIfNonExists: false))
        ->add(new TokenSessionMiddleware())
        ->add(new AuthMiddleware());
    
    $group->post('/', function (Request $request, Response $response) {
        $params = (array)$request->getParsedBody();
        $name = getParam($params, 'name');
        $address = getParam($params, 'address');
        $msisdn = getParam($params, 'msisdn', '');
        $edelivery = getParam($params, 'edelivery', '');
        // No default -> leaves any previously saved preference untouched if absent.
        $stopAgresjiRaw = $params['stopAgresji'] ?? null;
        $stopAgresji = $stopAgresjiRaw === null ? null : ($stopAgresjiRaw === 'SA');
        $shareRecydywa = getParam($params, 'shareRecydywa', 'Y') == 'Y';
    
        /** @var \user\User $user */
        $user = $request->getAttribute('user');
    
        $user->updateUserData($name, $msisdn, $address, $edelivery, $stopAgresji, $shareRecydywa);
        \user\save($user);
        $user->isRegistered = $user->isRegistered();
        $request = $request->withAttribute('user', $user);
        return $response;
    })  ->add(new AddStatsMiddleware())
        ->add(new UserMiddleware(createIfNonExists: false))
        ->add(new TokenSessionMiddleware())
        ->add(new AuthMiddleware());
    
    
    // Dashboard (web /app) with texts already localized for the user's sex – see dashboardData().
    $group->get('/dashboard', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(dashboardData($request->getAttribute('user'), fresh: true)));
        return $response;
    })  ->add(new RegisteredMiddleware())
        ->add(new UserMiddleware())
        ->add(new TokenSessionMiddleware())
        ->add(new AuthMiddleware());

    // Passkeys (web /app/account): list + remove. Adding one needs WebAuthn in a native app
    // (RP/origin association), so registration stays on the web for now.
    $group->get('/passkeys', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        $rows = array_map(fn($p) => [
            'id' => $p['credential_id'],
            'label' => $p['label'],
            'createdAt' => $p['created_at'],
            'lastUsedAt' => $p['last_used_at'],
        ], \passkey\forEmail($user->getEmail()));
        $response->getBody()->write(json_encode(['passkeys' => $rows]));
        return $response;
    })  ->add(new RegisteredMiddleware())
        ->add(new UserMiddleware())
        ->add(new TokenSessionMiddleware())
        ->add(new AuthMiddleware());

    $group->delete('/passkeys/{credentialId}', function (Request $request, Response $response, $args) {
        $user = $request->getAttribute('user');
        if (!\passkey\remove($args['credentialId'], $user->getEmail()))
            throw new HttpNotFoundException($request, 'Nie znaleziono passkeya');
        \telemetry\log('passkey_removed');
        $response->getBody()->write(json_encode(['status' => 'OK']));
        return $response;
    })  ->add(new RegisteredMiddleware())
        ->add(new UserMiddleware())
        ->add(new TokenSessionMiddleware())
        ->add(new AuthMiddleware());

    // Self-service account deletion; the e-mail must be retyped (same as the web form, minus CSRF –
    // a Bearer token isn't sent automatically by browsers).
    $group->delete('/', function (Request $request, Response $response) {
        $params = (array)$request->getParsedBody();
        $user = $request->getAttribute('user');
        if (!\admin\selfDelete($user, (string)($params['email'] ?? '')))
            throw new HttpException($request, 'Wpisany adres e-mail nie zgadza się z adresem Twojego konta', 422);
        $response->getBody()->write(json_encode(['status' => 'OK']));
        return $response;
    })  ->add(new RegisteredMiddleware())
        ->add(new UserMiddleware())
        ->add(new TokenSessionMiddleware())
        ->add(new AuthMiddleware());

    $group->get('/apps', function (Request $request, Response $response) {
        $params = $request->getQueryParams();
        $status = getParam($params, 'status', 'all');
        $search = getParam($params, 'search', '%');
        $limit =  getParam($params, 'limit', 0); // 0 == no limit
        $offset = getParam($params, 'offset', 0);
    
        $user = $request->getAttribute('user');
        $apps = \user\apps($user, $status, $search, $limit, $offset);
        
        $response->getBody()->write(json_encode(array_map('applicationToRest', $apps)));
        return $response;
    })  ->add(new RegisteredMiddleware())
        ->add(new UserMiddleware())
        ->add(new TokenSessionMiddleware())
        ->add(new AuthMiddleware());
    
}); 

$app->group('/api/rest/config', function (RouteCollectorProxy $group) { // CONFIG
    $CONFIG_FILES = Array(
        'badges', 'categories', 'category-groups', 'extensions', 'levels', 'patronite', 'sm', 'statuses', 'stop-agresji', 'terms');
    
    $group->get('/', function (Request $request, Response $response) use ($CONFIG_FILES) {
        $response->getBody()->write(json_encode($CONFIG_FILES));
        return $response;
    });
    
    $group->get('/categories', function (Request $request, Response $response) {
        $categories = file_get_contents(__DIR__ . "/../config/categories.json");
        $categories = json_decode($categories, true);
        array_walk($categories, function(&$val, $key) { $val["id"] = (string)$key; });
        $response->getBody()->write(json_encode(array_values($categories)));
        return $response;
    });
    
    $group->get('/terms', function (Request $request, Response $response) {
        $twig = initBareTwig();
        $terms = $twig->render('regulamin.json.twig', ['latestTermUpdate' => LATEST_TERMS_UPDATE]);
        $response->getBody()->write($terms);
        return $response;
    });
    
    $group->get('/{name}', function (Request $request, Response $response, $args) use ($CONFIG_FILES) {
        $name = $args['name'];
    
        if (!in_array($name, $CONFIG_FILES))
            throw new HttpNotFoundException($request,
                "Nie znam konfiguracji o nazwie '$name'");
    
        $response->getBody()->write(file_get_contents(__DIR__ . "/../config/$name.json"));
        return $response;
    });
});

$app->group('/api/rest/app', function (RouteCollectorProxy $group) { // APPLICATION
    $group->post('/new', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        $application = Application::withUser($user);
        \app\save($application);
        \telemetry\log('report_started', $application->id); // like the web /app/new
        $response->getBody()->write(json_encode(applicationToRest($application)));
        return $response;
    });

    $group->get('/{appId}', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        $application = $request->getAttribute('application');
    
        $twig = initBareTwig();
        $user = \user\current();
        $sex = ($user)? $user->getSex(): SEXSTRINGS['?'];
        $appJson = $twig->render('_application.json.twig', [
            'app' => $application,
            'config' => [
                'sex' => $sex
            ]
        ]);
    
        $application->formattedText = json_decode($appJson);
    
        if ($application->email !== $user->getEmail()) {
            $application->user = '';
        }
    
        $response->getBody()->write(json_encode(applicationToRest($application)));
        return $response;
    })  ->add(new AppMiddleware(failOnWrongOwnership: false));

    $group->post('/{appId}', function (Request $request, Response $response, $args) {
        $appId = $args['appId'];
        $params = (array)$request->getParsedBody();

        $plateId = getParam($params, 'plateId');
        $address = getParam($params, 'address'); // Mazurska 37, Szczecin (displayed address; web: `lokalizacja`)
        $dtFromPicture = getParam($params, 'dtFromPicture') == 1; // 1|0 - was date and time extracted from picture?

        $datetime = getParam($params, 'datetime'); // "2018-02-02T19:48:10"

        $comment = getParam($params, 'comment', '');
        $category = intval(getParam($params, 'category'));

        // JSON `true`/`false` as well as "1"/"on"/"true" (HTML-form style) – a bare "false" string must not be truthy.
        $witness = filter_var($params['witness'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // "6,7" (legacy) or [6, 7]
        $extensions = $params['extensions'] ?? '';
        $extensions = array_filter(is_array($extensions) ? $extensions : explode(',', (string)$extensions));

        // optional: ad hoc Policja/SM choice ('SA' | 'SM' or bool); null = keep the account default
        $stopAgresji = $params['stopAgresji'] ?? null;
        if ($stopAgresji !== null)
            $stopAgresji = is_bool($stopAgresji) ? $stopAgresji : ($stopAgresji === 'SA');

        // Same shape as the web form's hidden `address` JSON (ApplicationHandler::confirm):
        // `address` = what the user sees, `addressGPS` = what the geocoder returned.
        $fullAddress = new JSONObject();
        $fullAddress->address = $address;
        $fullAddress->addressGPS = $params['addressGPS'] ?? null;
        foreach (['city', 'voivodeship', 'district', 'county', 'municipality', 'postcode', 'lat', 'lng'] as $field)
            $fullAddress->$field = $params[$field] ?? null;

        $user = $request->getAttribute('user');
        
        \semaphore\withLock($appId, "restUpdate", function () use (
            $appId, $request, $response, $datetime, $dtFromPicture, $category, $fullAddress,
            $plateId, $comment, $witness, $extensions, $user, $stopAgresji
        ) {
            $application = \app\get($appId);

            try {
                $application = updateApplication($application, $datetime, $dtFromPicture, $category, $fullAddress,
                    $plateId, $comment, $witness, $extensions, $user, $stopAgresji);
            } catch (ValidationException $e) {
                throw new HttpException($request, $e->getMessage(), 422, $e); // JsonErrorRenderer adds `field`
            } catch (NotSendableException $e) {
                throw new HttpException($request, $e->getMessage(), 409, $e);
            } catch (Exception $e) {
                throw new HttpForbiddenException($request, $e->getMessage(), $e);
            }
            $response->getBody()->write(json_encode(applicationToRest($application)));
        });
        return $response;
    })  ->add(new AppMiddleware());

    $group->patch('/{appId}/status/{status}', function (Request $request, Response $response, $args) {
        $status = $args['status'];
        $application = $request->getAttribute('application');
        $user = $request->getAttribute('user');
        try {
            $application = setStatus($status, $application->id, $user);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        $response->getBody()->write(json_encode(applicationToRest($application)));
        return $response;
    })  ->add(new AppMiddleware());


    // Numer sprawy SM/Policji i prywatne uwagi – edytowalne także po wysłaniu (web: PATCH /api/app/{id}/fields).
    $group->patch('/{appId}/fields', function (Request $request, Response $response, $args) {
        $user = $request->getAttribute('user');
        try {
            $result = updateApplicationFields($args['appId'], (array)$request->getParsedBody(), $user);
        } catch (\InvalidArgumentException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        } catch (Exception $e) {
            if ($e->getCode() === 403) throw new HttpForbiddenException($request, $e->getMessage(), $e);
            throw $e;
        }
        \telemetry\log('report_edited', $args['appId'], ['type' => 'fields']);
        $response->getBody()->write(json_encode([
            'app' => applicationToRest($result['application']),
            'suggestStatusChange' => $result['suggestStatusChange'],
        ]));
        return $response;
    })  ->add(new AppMiddleware());

    $group->post('/{appId}/image', function (Request $request, Response $response) {
        $application = $request->getAttribute('application');
        $user = $request->getAttribute('user');

        // Zdjęcie już wysłane i przeanalizowane w etapie `wip` (POST /api/rest/photos): przydział do slotu bez ponownego uploadu.
        $params = (array)$request->getParsedBody();
        if (!empty($params['photoId'])) {
            try {
                $pictureType = $params['pictureType'] ?? null;
                if ($pictureType === null) throw new MissingParamException('pictureType');
                $application = assignPhoto($application->id, $pictureType, (string)$params['photoId'], $user,
                    function (Application $app) use ($request) {
                        if (!$app->isEditable())
                            throw new HttpForbiddenException($request, "Zgłoszenie {$app->id} nie może być edytowane");
                    });
            } catch (MissingParamException $e) {
                throw new HttpBadRequestException($request, $e->getMessage(), $e);
            } catch (HttpException $e) {
                throw $e;
            } catch (Exception $e) {
                if ($e->getCode() === 404) throw new HttpNotFoundException($request, $e->getMessage(), $e);
                if ($e->getCode() === 400) throw new HttpBadRequestException($request, $e->getMessage(), $e);
                throw $e;
            }
            \telemetry\log('report_edited', $application->id, ['type' => 'image', 'source' => 'wip']);
            $response->getBody()->write(json_encode(applicationToRest($application)));
            return $response;
        }

        try {
            $up = imageUploadFromRequest($request); // same contract as the cookie API /api/app/{id}/image
            $application = uploadImage($application->id, $up['pictureType'], $up['bytes'], $up['dateTime'],
                $up['dtFromPicture'], $up['latLng'],
                function (Application $app) use ($request) {
                    if (!$app->isEditable())
                        throw new HttpForbiddenException($request, "Zgłoszenie {$app->id} nie może być edytowane");
                },
                $user);
        } catch (MissingParamException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }
        \telemetry\log('report_edited', $application->id, ['type' => 'image']);
        $response->getBody()->write(json_encode(applicationToRest($application)));
        return $response;
    })  ->add(new AppMiddleware());

    $group->delete('/{appId}/image/{image}', function (Request $request, Response $response, $args) {
        $appId = $args['appId'];
        $user = $request->getAttribute('user');
        $application = \semaphore\withLock($appId, "deleteImage", function () use ($appId, $args, $request, $user) {
            $application = \app\get($appId);
            if ($application->email !== $user->getEmail())
                throw new HttpForbiddenException($request, "Nie posiadasz zgłoszenia o ID $appId");
            if (!$application->isEditable())
                throw new HttpForbiddenException($request, "Zgłoszenie $appId nie może być edytowane");
            return \app\save(removeApplicationImage($application, $args['image'])); // shared with cookie API
        });
        $response->getBody()->write(json_encode(applicationToRest($application)));
        return $response;
    })  ->add(new AppMiddleware());

    // Data for the confirmation screen shown before finish/send (web: potwierdz.html.twig).
    $group->get('/{appId}/confirmation', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(confirmationData($request->getAttribute('application'))));
        return $response;
    })  ->add(new AppMiddleware());

    // "Potwierdź" (+ optional send) in one call – see finishApplication() in API.php.
    $group->post('/{appId}/finish', function (Request $request, Response $response, $args) {
        $appId = $args['appId'];
        $params = (array)$request->getParsedBody();
        $send = filter_var($params['send'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $user = $request->getAttribute('user');

        try {
            $result = finishApplication($appId, $user);
        } catch (ValidationException $e) {
            throw new HttpException($request, $e->getMessage(), 422, $e);
        } catch (Exception $e) {
            throw new HttpForbiddenException($request, $e->getMessage(), $e);
        }
        $application = $result['application'];

        // sendMode: sent | manual (no automated channel for this SM/Policja – finish on the web) |
        //           failed (automated channel exists but sending failed; report stays `confirmed`) | not_requested
        $sendMode = 'not_requested';
        $sendError = null;
        if ($send) {
            if (!$application->guessSMData()->automated()) {
                $sendMode = 'manual';
            } else {
                try {
                    $application = sendApplication($appId, $user);
                    \telemetry\log('report_sent', $appId);
                    $sendMode = 'sent';
                } catch (Exception $e) {
                    $sendMode = 'failed';
                    $sendError = $e->getMessage();
                }
            }
        }

        $response->getBody()->write(json_encode([
            'app' => applicationToRest($application),
            'edited' => $result['edited'],
            'appsCount' => $result['appsCount'],
            'isPatron' => $result['isPatron'],
            'sendMode' => $sendMode,
            'sendError' => $sendError,
        ]));
        return $response;
    })  ->add(new AppMiddleware());

    $group->patch('/{appId}/send', function (Request $request, Response $response, $args) {
        $appId = $args['appId'];
        $application = \app\get($appId);
        $user = $request->getAttribute('user');
    
        if ($application->email !== $user->getEmail()) {
            throw new HttpForbiddenException($request, "Użytkownik '{$user->getEmail()}' nie ma uprawnień do wysłania zgłoszenia '$appId'");
        }
    
        $application = sendApplication($appId, $user);
        $response->getBody()->write(json_encode(applicationToRest($application)));
        return $response;
    })  ->add(new AppMiddleware());

})  ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new TokenSessionMiddleware())
    ->add(new AuthMiddleware());

// Etap `wip`: zdjęcia wysyłane PRZED powstaniem szkicu zgłoszenia i analizowane po photoId (vision/candidate) – patrz inc/WipPhotos.php.
$app->group('/api/rest/photos', function (RouteCollectorProxy $group) { // PHOTOS (wip)
    $group->post('/', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        if (!\cache\throttle\attempt(\cache\Type::Vision, 'wip-' . $user->getEmail(), WIP_RATE_MAX, WIP_RATE_WINDOW))
            throw new HttpTooManyRequestsException($request, 'Zbyt wiele zdjęć w krótkim czasie. Spróbuj później.');
        try {
            $up = imageUploadFromRequest($request, requirePictureType: false);
            $body = (array)$request->getParsedBody();
            $staged = \wip\stage($user, $up['bytes'], [
                'dateTime' => $up['dateTime'],
                'lat' => $body['lat'] ?? null,
                'lng' => $body['lng'] ?? null,
            ]);
        } catch (Exception $e) {
            $code = in_array($e->getCode(), [400, 415], true) ? $e->getCode() : 500;
            throw new HttpException($request, $e->getMessage(), $code, $e);
        }
        $response->getBody()->write(json_encode($staged));
        return $response->withStatus(201);
    });

    $group->delete('/{photoId}', function (Request $request, Response $response, $args) {
        if (!\wip\delete($request->getAttribute('user'), $args['photoId']))
            throw new HttpNotFoundException($request, 'Nie znaleziono zdjęcia');
        return $response->withStatus(204);
    });
})  ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new TokenSessionMiddleware())
    ->add(new AuthMiddleware());

$app->group('/api/rest/geo', function (RouteCollectorProxy $group) { // GEO
    // Static map preview for the report form (Mapbox Static Images through the backend: the token stays
    // server-side, and the app gets a plain PNG). ?lat=&lng= puts a pin there; without them all of Poland.
    $group->get('/map', function (Request $request, Response $response) {
        $q = $request->getQueryParams();
        $lat = isset($q['lat'], $q['lng']) && is_numeric($q['lat']) && is_numeric($q['lng']) ? (float)$q['lat'] : null;
        $lng = $lat !== null ? (float)$q['lng'] : null;
        $png = \geo\staticMap($lat, $lng, (int)($q['w'] ?? 600), (int)($q['h'] ?? 300));
        if ($png === null)
            throw new HttpNotFoundException($request, 'Podgląd mapy jest chwilowo niedostępny');
        $response->getBody()->write($png);
        return $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'private, max-age=86400');
    });

    // Address text → coordinates + structured address (+ SM/Policja hints) in one call, so a typed
    // address ("Ulica 10, Miasto" – the comma is required) gets the same data as a GPS point.
    // Forward geocoding is shared with MCP create_report_draft (\geo\NominatimSearch).
    $group->get('/search', function (Request $request, Response $response) {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        if ($q === '')
            throw new HttpBadRequestException($request, "Brak wymaganego parametru 'q'");
        try {
            $coords = \geo\NominatimSearch($q);
        } catch (Exception $e) {
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        if (!is_array($coords))
            throw new HttpNotFoundException($request, "Nie znaleziono adresu '$q' (format: „Ulica 10, Miasto”)");
        $lat = (float)($coords['lat'] ?? $coords[0]);
        $lng = (float)($coords['lng'] ?? $coords[1]);
        try {
            $result = \geo\Nominatim($lat, $lng);
        } catch (Exception $e) {
            throw new HttpNotFoundException($request, $e->getMessage(), $e);
        }
        $response->getBody()->write(json_encode(['lat' => $lat, 'lng' => $lng] + $result));
        return $response;
    });

    $group->get('/{lat},{lng}/g', function (Request $request, Response $response, $args) {
        $lat = $args['lat'];
        $lng = $args['lng'];
        try {
            $response->getBody()->write(json_encode(\geo\GoogleMaps($lat, $lng)));
        } catch (Exception $e) {
            if ($e->getCode() ?? -1 == 404) {
                throw new HttpNotFoundException($request, $e->getMessage(), $e);
            }
            throw new HttpInternalServerErrorException($request, $e->getMessage(), $e);
        }
        return $response;
    });
    
    $group->get('/{lat},{lng}/n', function (Request $request, Response $response, $args) {
        $lat = $args['lat'];
        $lng = $args['lng'];
    
        $result = \geo\Nominatim($lat, $lng);
        $response->getBody()->write(json_encode($result));
        return $response;
    });
    
    $group->get('/{lat},{lng}/m', function (Request $request, Response $response, $args) {
        $lat = $args['lat'];
        $lng = $args['lng'];
    
        $result = \geo\MapBox($lat, $lng);
        $response->getBody()->write(json_encode($result));
        return $response;
    });
})  ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new TokenSessionMiddleware())
    ->add(new AuthMiddleware());

$app->group('/api/rest/recydywa', function (RouteCollectorProxy $group) { // RECYDYWA
    // Same lookup as the MCP check_plate tool (\recydywa\checkPlate, shared in
    // RecydywaStore.php) — counts + history for a plate, without the
    // \recydywa\get()/update() side effect of re-queuing matching reports.
    $group->get('/{plateId}', function (Request $request, Response $response, $args) {
        $plateId = $args['plateId'];
        $user = $request->getAttribute('user');

        try {
            $result = \recydywa\checkPlate($plateId, $user->getEmail());
        } catch (\InvalidArgumentException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }

        $response->getBody()->write(json_encode($result));
        return $response;
    });
})  ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new TokenSessionMiddleware())
    ->add(new AuthMiddleware());

$app->group('/api/rest/vehicle', function (RouteCollectorProxy $group) { // VEHICLE
    // Editor preview of what \vehicle_info\refresh stores on save: make/model and the weight warning ({} when unknown).
    $group->get('/{plateId}', function (Request $request, Response $response, $args) {
        $info = \vehicle_info\lookup($args['plateId']);
        $response->getBody()->write(json_encode($info ?? new \stdClass()));
        return $response;
    });
})  ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new TokenSessionMiddleware())
    ->add(new AuthMiddleware());

$app->group('/api/rest/vision', function (RouteCollectorProxy $group) { // VISION
    // Analiza wizyjna (LLM) zdjęć jednego kandydata zgłoszenia dla appki UD Pro: role/markery/
    // tablica w jednym żądaniu (tablica/bbox auta z ALPR gdy score >= ALPR_MIN_SCORE,
    // wpp. zostaje odczyt modelu) — patrz src/inc/integrations/Vision.php.
    $group->post('/candidate', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        $email = $user->getEmail();

        if (!\cache\throttle\attempt(\cache\Type::Vision, 'candidate-' . $email, VISION_RATE_MAX, VISION_RATE_WINDOW)) {
            \telemetry\log('vision_rate_limited', null, ['status' => 'error']);
            throw new HttpTooManyRequestsException($request,
                'Limit analiz wizyjnych wyczerpany (' . VISION_RATE_MAX . '/h). Spróbuj później.');
        }

        $params = (array)$request->getParsedBody();
        $reportId = getParam($params, 'reportId', '');

        try {
            $items = \vision\decodeCandidatePhotos($params['photos'] ?? null, $user);
        } catch (\vision\VisionRequestException $e) {
            throw new HttpException($request, $e->getMessage(), $e->httpStatus);
        }

        try {
            $result = \vision\analyzeCandidate($items, $email, $reportId ?: null);
        } catch (\vision\VisionException $e) {
            throw new HttpException($request, $e->getMessage(), 502);
        }
        $result['reportId'] = $reportId;
        $result['schema'] = \vision\VISION_SCHEMA;
        $result['model'] = OPENAI_VISION_MODEL;

        $response->getBody()->write(json_encode($result));
        return $response;
    });
})  ->add(new TermsConfirmedMiddleware())
    ->add(new RegisteredMiddleware())
    ->add(new UserMiddleware())
    ->add(new TokenSessionMiddleware())
    ->add(new AuthMiddleware());

// OTHER

$app->map(['GET', 'POST', 'PATCH'], '/{routes:.+}', function ($request) {
    throw new HttpNotFoundException($request);
});

$app->run();

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
function getParam(array $params, string $name, mixed $default=null) {
    $param = $params[$name] ?? $default;
    if (is_null($param)) {
        throw new MissingParamException($name);
    }
    return $param;
}

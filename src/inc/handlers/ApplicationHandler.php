<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

class ApplicationHandler {
    public function start(Request $request, Response $response, $args) {
        return HtmlMiddleware::render($request, $response, 'start', [
            'latestTermUpdate' => LATEST_TERMS_UPDATE
        ]);
    }

    public function newApplication(Request $request, Response $response, $args) {
        global $storage;
        $params = $request->getQueryParams();
        if (isset($params['cleanup'])) {
            unset($_SESSION['newAppId']);
            return $response
                ->withHeader('Location', '/nowe-zgloszenie.html')
                ->withStatus(302);
        }

        $user = $request->getAttribute('user');

        if (isset($params['TermsConfirmation'])) {
            $user->confirmTerms();
            $storage->saveUser($user);
        }

        if (!$user->checkTermsConfirmation()) {
            return $response
                ->withHeader('Location', '/start.html')
                ->withStatus(302);
        }

        if (isset($params['edit'])) {
            $application = $storage->getApplication($params['edit']);
            if (!$application->isEditable()) {
                throw new Exception("Nie mogę pozwolić na edycję zgłoszenia w statusie " . $application->getStatus()->name);
            }

            if (!($application->isAppOwner() || isAdmin())) {
                throw new Exception("Próba edycji cudzego zgłoszenia. Nieładnie!");
            }

            $_SESSION['newAppId'] = $application->id;
            $edit = true;
        } elseif (isset($_SESSION['newAppId'])) { // edit mode
            try {
                $application = $storage->getApplication($_SESSION['newAppId']);
                $edit = isset($application->carImage) || isset($application->contextImage);
                $application->updateUserData($user);
                if (!$edit) {
                    unset($application);
                } elseif (!$application->isEditable()) {
                    unset($application);
                }
            } catch (Exception $e) {
                unset($application);
            }
        }

        if (!isset($application)) { // new application mode
            $application = Application::withUser($user);
            $storage->saveApplication($application);
            $_SESSION['newAppId'] = $application->id;
            $edit = false;
        }


        $dt = (new DateTime($application->date))->format(DT_FORMAT_SHORT);

        $now = new DateTime();
        $dtMax = $now->format(DT_FORMAT_SHORT);
        $dtMin = $now->modify("-1 year")->format(DT_FORMAT_SHORT);

        // edit app older than 1y
        if ($dtMin > $dt) $dtMin = $dt;

        return HtmlMiddleware::render($request, $response, 'nowe-zgloszenie', [
            'config' => [
                'edit' => $edit,
                'lastLocation' => $user->getLastLocation()
            ],
            'categoriesMatrix' => CATEGORIES_MATRIX,
            'app' => $application,
            'dtMin' => $dtMin,
            'dt' => $edit ? $dt : '',
            'dtMax' => $dtMax
        ]);
    }

    public function confirm(Request $request, Response $response, $args) {
        $params = (array)$request->getParsedBody();

        $appId = getParam($params, 'applicationId', -1);
        if ($appId == -1) {
            return $response
                ->withHeader('Location', '/nowe-zgloszenie.html')
                ->withStatus(302);
        }

        $plateId = getParam($params, 'plateId');

        $dtFromPicture = getParam($params, 'dtFromPicture') == 1; // 1|0 - was date and time extracted from picture?
        $datetime = getParam($params, 'datetime'); // "2018-02-02T19:48:10"
        $comment = getParam($params, 'comment', '');
        $category = intval(getParam($params, 'category'));
        $witness = isset($params['witness']);
        $extensions = getParam($params, 'extensions', ''); // "6,7", "6", "", missing
        $extensions = array_filter(explode(',', $extensions));

        $fullAddress = json_decode(getParam($params, 'address'));
        $fullAddress->addressGPS = $fullAddress->address;
        $fullAddress->address = getParam($params, 'lokalizacja');

        $user = $request->getAttribute('user');

        try {
            $application = updateApplication(
                $appId,
                $datetime,
                $dtFromPicture,
                $category,
                $fullAddress,
                $plateId,
                $comment,
                $witness,
                $extensions,
                $user
            );
        } catch (Exception $e) {
            throw new HttpForbiddenException($request, $e->getMessage(), $e);
        }

        return HtmlMiddleware::render($request, $response, 'potwierdz', [
            'config' => [
                'isAppOwnerOrAdmin' => true,
                'confirmationScreen' => true
            ],
            'app' => $application,
            'autoSend' => $user->autoSend() && $application->automatedSM()

        ]);
    }

    public function finish(Request $request, Response $response, $args) {
        global $storage;
        $params = (array)$request->getParsedBody();

        $appId = getParam($params, 'applicationId', -1);

        if (!isset($_SESSION['newAppId']) || $appId == -1) {
            return $response
                ->withHeader('Location', '/moje-zgloszenia.html')
                ->withStatus(302);
        }

        unset($_SESSION['newAppId']);
        $application = $storage->getApplication($appId);
        $user = $request->getAttribute('user');

        $edited = $application->hasNumber();

        $application->setStatus("confirmed");
        $application = $storage->saveApplication($application); // this also sets app number

        $user->setLastLocation($application->getLatLng());
        $user->appsCount = $application->seq;
        $storage->saveUser($user);

        $storage->updateRecydywa($application->carInfo->plateId);
        $storage->getUserStats(false, $user); // update cache

        if ($edited) {
            $application->address->mapImage = null;
        }

        return HtmlMiddleware::render($request, $response, 'dziekujemy', [
            'app' => $application,
            'appsCount' => $user->appsCount,
            'edited' => $edited,
            'autoSend' => $user->autoSend() && $application->automatedSM()

        ]);
    }

    public function missingSM(Request $request, Response $response, $args) {
        global $storage;
        $params = $request->getQueryParams();
        $appId = getParam($params, 'id', -1);
        $appCity = '';
        $appNumber = '';
        if ($appId !== -1) {
            try {
                $app = $storage->getApplication($appId);
                $appCity = $app->address->city;
                $appNumber = $app->number;
            } catch (Exception $e) {
                $appCity = '';
                $appNumber = '';
            }
        }

        return HtmlMiddleware::render($request, $response, 'brak-sm', [
            'appCity' => $appCity,
            'appNumber' => $appNumber
        ]);
    }

    public function myApps(Request $request, Response $response, $args) {
        global $storage;

        $user = $request->getAttribute('user');
        $applications = $storage->getUserApplications();

        $params = $request->getQueryParams();

        $changeMail = isset($params['changeMail']) && isset($params['city']);
        $city = urldecode(getParam($params, 'city', ''));

        $countChanged = 0;

        foreach ($applications as $application) {
            if ($changeMail) {
                if ($application->status == 'confirmed') {
                    if ($changeMail && $application->smCity == $city) {
                        $application->setStatus('confirmed-waiting');
                        $countChanged++;
                    }
                    $storage->saveApplication($application);
                }
            }
        }

        if ($changeMail) {
            $storage->getUserStats(false, $user); // updates the cache
        }

        return HtmlMiddleware::render($request, $response, 'moje-zgloszenia', [
            'appActionButtons' => true,
            'applications' => $applications,
            'countChanged' => $countChanged,
            'applicationsCount' => count($applications),
            'myAppsSize' => $user->myAppsSize(),
            'autoSend' => $user->autoSend()
        ]);
    }

    public function shipment(Request $request, Response $response, $args) {
        global $storage;
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        if (!isset($params['city'])) {
            $city = $storage->getNextCityToSent();
            logger("nextcity = $city");
            if ($city) {
                return $response
                    ->withHeader('Location', '/wysylka.html?city=' . urlencode($city))
                    ->withStatus(302);
            }
            return $response
                ->withHeader('Location', '/moje-zgloszenia.html')
                ->withStatus(302);
        }

        $city = urldecode($params['city']);

        $apps = $storage->getConfirmedAppsByCity($city);

        if (count($apps) == 0) {
            return $response
                ->withHeader('Location', '/moje-zgloszenia.html')
                ->withStatus(302);
        }

        $sm = reset($apps)->guessSMData();

        return HtmlMiddleware::render($request, $response, 'wysylka', [
            'appActionButtons' => false,
            'wysylka' => true,
            'apps' => $apps,
            'user' => $user,
            'city' => $city,
            'sm' => $sm,
            'autoSend' => $user->autoSend()
        ]);
    }
}

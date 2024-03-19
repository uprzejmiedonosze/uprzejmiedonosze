<?PHP

require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class ApplicationHandler extends AbstractHandler {
    public function start(Request $request, Response $response): Response {
        return AbstractHandler::renderHtml($request, $response, 'start', [
            'latestTermUpdate' => LATEST_TERMS_UPDATE
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.MissingImport)
     * @SuppressWarnings(PHPMD.Superglobals)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(ShortVariable)
     * @TODO
     */
    public function newApplication(Request $request, Response $response): Response {
        global $storage;
        $params = $request->getQueryParams();
        if (isset($params['cleanup'])) {
            unset($_SESSION['newAppId']);
            return $this->redirect('/nowe-zgloszenie.html');
        }

        $user = $request->getAttribute('user');

        if (isset($params['TermsConfirmation'])) {
            $user->confirmTerms();
            $storage->saveUser($user);
        }

        if (!$user->checkTermsConfirmation()) {
            return $this->redirect('/start.html');
        }

        if (isset($params['edit'])) {
            $application = $storage->getApplication($params['edit']);
            if (!$application->isEditable()) {
                throw new Exception("Nie mogę pozwolić na edycję zgłoszenia w statusie " . $application->getStatus()->name);
            }

            if (!($application->isAppOwner($user) || $user->isAdmin())) {
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

        return AbstractHandler::renderHtml($request, $response, 'nowe-zgloszenie', [
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

    public function confirm(Request $request, Response $response): Response {
        $params = (array)$request->getParsedBody();

        $appId = $this->getParam($params, 'applicationId', -1);
        if ($appId == -1) {
            return $this->redirect('/nowe-zgloszenie.html');
        }

        $plateId = $this->getParam($params, 'plateId');

        $dtFromPicture = $this->getParam($params, 'dtFromPicture') == 1; // 1|0 - was date and time extracted from picture?
        $datetime = $this->getParam($params, 'datetime'); // "2018-02-02T19:48:10"
        $comment = $this->getParam($params, 'comment', '');
        $category = (int) $this->getParam($params, 'category');
        $witness = isset($params['witness']);
        $extensions = $this->getParam($params, 'extensions', []); // "6,7", "6", "", missing
        $extensions = array_filter($extensions);

        $fullAddress = json_decode($this->getParam($params, 'address'));
        $fullAddress->addressGPS = $fullAddress->address;
        $fullAddress->address = $this->getParam($params, 'lokalizacja');

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
                $user,
            );
        } catch (Exception $e) {
            throw new HttpForbiddenException($request, $e->getMessage(), $e);
        }

        return AbstractHandler::renderHtml($request, $response, 'potwierdz', [
            'config' => [
                'isAppOwnerOrAdmin' => true,
                'confirmationScreen' => true
            ],
            'app' => $application,
            'autoSend' => $user->autoSend() && $application->automatedSM(),
            'user' => $user
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function finish(Request $request, Response $response): Response {
        global $storage;
        $params = (array)$request->getParsedBody();

        $appId = $this->getParam($params, 'applicationId', -1);

        if (!isset($_SESSION['newAppId']) || $appId == -1) {
            return $this->redirect('/moje-zgloszenia.html');
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

        return AbstractHandler::renderHtml($request, $response, 'dziekujemy', [
            'app' => $application,
            'appsCount' => $user->appsCount,
            'edited' => $edited,
            'autoSend' => $user->autoSend() && $application->automatedSM()

        ]);
    }

    public function missingSM(Request $request, Response $response): Response {
        global $storage;
        $params = $request->getQueryParams();
        $appId = $this->getParam($params, 'id', -1);
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

        return AbstractHandler::renderHtml($request, $response, 'brak-sm', [
            'appCity' => $appCity,
            'appNumber' => $appNumber
        ]);
    }

    public function myApps(Request $request, Response $response): Response {
        global $storage;

        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $query = $this->getParam($params, 'q', '');
        $applications = $storage->getUserApplications(user: $user, search: $query);
        $changeMail = isset($params['changeMail']) && isset($params['city']);
        $city = urldecode($this->getParam($params, 'city', ''));

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

        return AbstractHandler::renderHtml($request, $response, 'moje-zgloszenia', [
            'appActionButtons' => true,
            'applications' => $applications,
            'countChanged' => $countChanged,
            'applicationsCount' => count($applications),
            'myAppsSize' => 500,
            'autoSend' => $user->autoSend(),
            'query' => $query
        ]);
    }

    public function myAppsPartial(Request $request, Response $response): Response {
        global $storage;
        $user = $request->getAttribute('user');

        $params = $request->getQueryParams();
        $status = $this->getParam($params, 'status', 'all');
        $search = $this->getParam($params, 'search', '%');
        $limit =  $this->getParam($params, 'limit', 0);
        $offset = $this->getParam($params, 'offset', 0);
        
        $apps = $storage->getUserApplications($user, $status, $search, $limit, $offset);

        return AbstractHandler::renderHtml($request, $response, 'my-apps-partial', [
            'appActionButtons' => true,
            'applications' => $apps,
            'myAppsSize' => 500,
            'applicationsCount' => count($apps),
            'autoSend' => $user->autoSend()
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function shipment(Request $request, Response $response): Response {
        global $storage;
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        if (!isset($params['city'])) {
            $city = $storage->getNextCityToSent();
            logger("nextcity = $city");
            if ($city) {
                return $this->redirect('/wysylka.html?city=' . urlencode($city));
            }
            return $this->redirect('/moje-zgloszenia.html?update');
        }

        $city = urldecode($params['city']);

        $apps = $storage->getConfirmedAppsByCity($city);

        if (count($apps) == 0) {
            return $this->redirect('/moje-zgloszenia.html?update');
        }

        $firstApp = reset($apps);
        $sm = $firstApp->guessSMData();

        if ($sm->unknown()) {
            return $this->redirect('/brak-sm.html?id=' . $firstApp->id);
        }

        return AbstractHandler::renderHtml($request, $response, 'wysylka', [
            'appActionButtons' => false,
            'wysylka' => true,
            'apps' => $apps,
            'user' => $user,
            'city' => $city,
            'sm' => $sm,
            'autoSend' => $user->autoSend()
        ]);
    }

    public function package(Request $request, Response $response, $args): Response {
        $city = $args['city'];
        $user = $request->getAttribute('user');
        [$path, $filename] = readyApps2PDF($user, $city);
        return AbstractHandler::renderPdf($response, $path, $filename);
    }

    public function askForStatus(Request $request, Response $response) {
        global $storage;
        $sent = $storage->getSentApplications(31);

        return AbstractHandler::renderHtml($request, $response, 'zapytaj-o-status', [
            'applications' => $sent
        ]);
    }

    public function applicationShortHtml(Request $request, Response $response, $args) {
        global $storage;
        $appId = $args['appId'];
        $user = $request->getAttribute('user');
        $application = $storage->getApplication($appId);

        return AbstractHandler::renderHtml($request, $response, '_application-short-details', [
            'appActionButtons' => true,
            'app' => $application,
            'autoSend' => $user->autoSend()
        ]);
    }

}

<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../PDFGenerator.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class StaticPagesHandler extends AbstractHandler {

    function root(Request $request, Response $response): Response {
        global $storage;
        $mainPageStats = $storage->getMainPageStats();
        return AbstractHandler::renderHtml($request, $response, 'index', [
            'config' => [
                'stats' => $mainPageStats
            ]
        ]);
    }

    function rules(Request $request, Response $response): Response {
        return AbstractHandler::renderHtml($request, $response, 'regulamin', [
            'latestTermUpdate' => LATEST_TERMS_UPDATE
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.MissingImport)
     * @SuppressWarnings(PHPMD.ShortVariable)
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    function faq(Request $request, Response $response) {
        global $SM_ADDRESSES;
        $smNames = array_map(function ($sm) { return $sm->city; }, $SM_ADDRESSES);
        $collator = new Collator('pl_PL');
        $collator->sort($smNames);
        $smNames = array_unique($smNames, SORT_LOCALE_STRING);

        return AbstractHandler::renderHtml($request, $response, 'faq', [
            'smAddresses' => implode(', ', $smNames)
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    function hearing(Request $request, Response $response) {
        global $SM_ADDRESSES;
        $SMHints = array();
        foreach ($SM_ADDRESSES as $sm) {
            if($sm->hint){
                if(!str_starts_with($sm->hint, 'Miejscowość ')) {
                    $SMHints[$sm->city] = $sm->hint;
                }
            }
        }
        $sortedSMHints = array_unique($SMHints, SORT_LOCALE_STRING);
        return AbstractHandler::renderHtml($request, $response, 'przesluchanie', [
            'SMHints' => $sortedSMHints
        ]);
    }

    function gallery(Request $request, Response $response) {
        global $storage;
        return AbstractHandler::renderHtml($request, $response, 'galeria', [
            'appActionButtons' => false,
            'galleryByCity' => $storage->getGalleryByCity()
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function applicationPdf(Request $request, Response $response, $args): Response {
        $appId = $args['appId'];
        [$path, $filename] = application2PDFById($appId);
        return AbstractHandler::renderPdf($response, $path, $filename);
    }

    public function applicationRedirect(Request $request) {
        $params = $request->getQueryParams();
        $appId = $this->getParam($params, 'id');
        return AbstractHandler::redirect("/ud-$appId.html");
    }

    public function applicationHtml(Request $request, Response $response, $args) {
        global $storage;
        $appId = $args['appId'];
        $application = $storage->getApplication($appId);
    
        $user = $request->getAttribute('user');
        $isAppOwner = $application->isAppOwner($user);
        $isAppOwnerOrAdmin = $user?->isAdmin() || $isAppOwner;
    
        return AbstractHandler::renderHtml($request, $response, "zgloszenie", [
            'title' => "Zgłoszenie {$application->number} z dnia {$application->getDate()}",
            'shortTitle' => "Zgłoszenie {$application->number}",
            'image' => $application->contextImage->thumb,
            'description' => "Samochód o nr. rejestracyjnym {$application->carInfo->plateId} " .
                "w okolicy adresu {$application->address->address}. {$application->getCategory()->getInformal()}",
            'app' => $application,
            'config' => [
                'isAppOwnerOrAdmin' => $isAppOwnerOrAdmin,
                'isAppOwner' => $isAppOwner
            ]
        ]);
    }

    public function publicInfo(Request $request, Response $response) {
        $email = '<i>[xxx@xxx.pl]</i>';
        $msisdn = '<i>[XXX XXX XXX]</i>';
        $name = '<i>[Imię Nazwisko]</i>';

        if ($request->getAttribute('isRegistered')) {
            $user = $request->getAttribute('user');
            if (!empty($user->data->msisdn))
                $msisdn = $user->data->msisdn;
            $email = $user->data->email;
            $name = $user->data->name;
        }

        return AbstractHandler::renderHtml($request, $response, 'dostep-do-informacji-publicznej', [
            'callDate' => date('j.m.Y', strtotime('-6 hour')),
            'callTime' => date('H:i', strtotime('-6 hour')),
            'checkTime' => date('H:00', strtotime('-2 hour')),
            'msisdn' => $msisdn,
            'email' => $email,
            'name' => $name
        ]);
    }

    public function login(Request $request, Response $response) {
        $params = $request->getQueryParams();
        $next = $this->getParam($params, 'next', '/');
        $error = $this->getParam($params, 'error', '');
        
        return AbstractHandler::renderHtml($request, $response, 'login', [
            'config' => [
                'signInSuccessUrl' => $next,
                'logout' => false,
                'error' => $error
            ]
        ]);
    }

    public function loginOK(Request $request, Response $response): Response {
        $params = $request->getQueryParams();
        $next = $this->getParam($params, 'next', '/start.html');
        
        return AbstractHandler::renderHtml($request, $response, 'login-ok', [
            'config' => [
                'signInSuccessUrl' => $next
            ]
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function logout(Request $request, Response $response) {
        unset($_SESSION['token']);
        unset($_SESSION['user_id']);
        unset($_SESSION['user_email']);
        return AbstractHandler::renderHtml($request, $response, 'login', [
            'config' => [
                'logout' => true
            ]
        ]);
    }

    public function carStatsPartial(Request $request, Response $response, $args) {
        $request = $request->withAttribute('partial', true);
        return $this->carStats($request, $response, $args);
    }

    public function carStatsFull(Request $request, Response $response, $args) {
        $request = $request->withAttribute('partial', false);
        return $this->carStats($request, $response, $args);
    }

    private function carStats(Request $request, Response $response, $args) {
        global $storage;
        $user = $request->getAttribute('user');
        
        $plateId = $args['plateId'];
        $apps = array_reverse($storage->getApplicationsByPlate($plateId));

        $users = array();
        $cities = array();
        $image = null;
        $imagesCount = 0;

        foreach($apps as $app) {
            $users[$app->user->number] = 1;
            $cities[$app->address->city] = 1;
            $app->isAppOwner = $app->isAppOwner($user);

            // display image if this is user's own application, other user
            // added it to gallery or allowed sharing globally
            $app->showImage = $storage->canShareRecydywa($app->user->email)
                || $app->statements->gallery
                || $app->isAppOwner;

            if($app->showImage) {
                $imagesCount++;
                if (!$image) $image = $app->contextImage->thumb;
            }
        }

        return AbstractHandler::renderHtml($request, $response, "carStats", [
            'title' => "Tablica rejestracyjna {$plateId}",
            'shortTitle' => "Tablica $plateId",
            'image' => $image,
            'description' => "Samochód o nr. rejestracyjnym {$plateId}",
            'apps' => $apps,
            'users' => count($users),
            'cities' => count($cities),
            'recydywaCnt' => count($apps),
            'plateId' => $plateId,
            'partial' => $request->getAttribute('partial'),
            'allImagesVisible' => count($apps) == $imagesCount,
            'userAllowedSharing' => $user ? $user->shareRecydywa() : true
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function trafficAdvice(Request $request, Response $response) {
        return $this->renderJson($response, json_decode('[{
            "user_agent": "prefetch-proxy",
            "google_prefetch_proxy_eap": {
              "fraction": 1.0 
            }
        }]'));
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function assetlinks(Request $request, Response $response) {
        return $this->renderJson($response, json_decode('[]'));
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function default(Request $request, Response $response, $args) {
        $ROUTES = [
            '404',
            'aplikacja',
            'changelog',
            'epuap',
            'jak-zglosic-nielegalne-parkowanie',
            'maintenance',
            'mandat',
            'polityka-prywatnosci',
            'projekt',
            'przepisy',
            'robtodobrze',
            'statystyki',
            'wniosek-odpowiedz1',
            'wniosek-rpo',
            'zwrot-za-przesluchanie',
            'patronite',
            'naklejki-robisz-to-zle'
        ];
        $route = $args['route'];

        if (!in_array($route, $ROUTES)) {
            throw new HttpNotFoundException($request);
        }
    
        try {
            return AbstractHandler::renderHtml($request, $response, $route);
        } catch (\Twig\Error\LoaderError $error) {
            return AbstractHandler::renderHtml($request, $response, "404");
        }
    }
}
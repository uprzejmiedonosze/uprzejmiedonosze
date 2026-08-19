<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 */
class HtmlMiddleware implements MiddlewareInterface {
    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public static function getDefaultParameters(bool $isDialog=false): array {
        $isLoggedIn = SessionMiddleware::isLoggedIn();

        $parameters = Array();
        $parameters['config'] = [
            'menu' => ''
        ];
        $parameters['dialog'] = $isDialog;

        $parameters['general'] = [
            'uri' => $_SERVER['REQUEST_URI'],
            'isLoggedIn' => $isLoggedIn,
            'hasApps' => false,
            'galleryCount' => 0,
            'isProd' => isProd(),
            'isStaging' => isStaging(),
            'matomoSiteId' => MATOMO_SITE_ID,
            'suggestPasskey' => self::suggestPasskey($isLoggedIn),
        ];

        global $STATUSES;
        $parameters['statuses'] = $STATUSES;

        global $CATEGORIES;
        $parameters['categories'] = $CATEGORIES;

        global $CATEGORY_GROUPS;
        $parameters['categoryGroups'] = $CATEGORY_GROUPS;

        global $EXTENSIONS;
        $parameters['extensions'] = $EXTENSIONS;

        global $LEVELS;
        $parameters['levels'] = $LEVELS;

        global $BADGES;
        $parameters['badges'] = $BADGES;

        global $FOOTER_LINKS;
        $parameters['footerLinks'] = $FOOTER_LINKS;

        $parameters['email_status'] = EMAIL_STATUS;
        return $parameters;
    }

    /**
     * Whether to show the "add a passkey" prompt: logged in, account
     * registered, no passkey yet, and not previously dismissed. Actual
     * browser support is checked client-side (server can't know it).
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private static function suggestPasskey(bool $isLoggedIn): bool {
        if (!$isLoggedIn) return false;
        try {
            $user = \user\current();
            if (!$user->isRegistered() || ($user->data->passkeyPromptDismissed ?? false)) return false;
            return \passkey\countForEmail($_SESSION['user_email']) === 0;
        } catch (\Exception $e) {
            logger('suggestPasskey failed: ' . $e->getMessage(), true);
            return false;
        }
    }

    public function process(Request $request, RequestHandler $handler): Response {
        logger(static::class . ": {$request->getUri()->getPath()}");
        $request = $request->withAttribute('content', 'html');
        $queryParams = $request->getQueryParams();

        $parameters = HtmlMiddleware::getDefaultParameters(
            isset($queryParams['dialog'])
        );

        $request = $request->withAttribute('parameters', $parameters);

        $response = $handler->handle($request);

        $allowedOrigin = getCorsOrigin($request);
        if ($allowedOrigin) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
                ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST')
                ->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}

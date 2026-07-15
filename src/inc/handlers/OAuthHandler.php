<?PHP

require_once(__DIR__ . '/../oauth/Entities.php');
require_once(__DIR__ . '/../oauth/Repositories.php');
require_once(__DIR__ . '/../oauth/OAuthServerFactory.php');

use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Response as ResponseObject;

/**
 * The browser-facing half of the OAuth provider: the consent screen for
 * /oauth/authorize. The user is authenticated with the existing Firebase
 * login (session); this only adds the "allow this app?" step and hands the
 * approved request to league.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
class OAuthHandler extends AbstractHandler {

    public function authorize(Request $request, Response $response) {
        $server = \oauth\authorizationServer();
        try {
            $authRequest = $server->validateAuthorizationRequest($request);
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse($response);
        }

        // Not logged in: bounce through Firebase login, preserving the FULL
        // authorize URL (LoggedInMiddleware would drop the query string).
        if (!$request->getAttribute('isLoggedIn')) {
            $uri = $request->getUri();
            $full = $uri->getPath() . ($uri->getQuery() ? '?' . $uri->getQuery() : '');
            return AbstractHandler::redirect('/login.html?next=' . urlencode($full));
        }

        $csrf = bin2hex(random_bytes(16));
        $_SESSION['oauth_csrf'] = $csrf;

        $scopeLabels = array_map(
            fn ($scope) => \oauth\SCOPES[$scope->getIdentifier()] ?? $scope->getIdentifier(),
            $authRequest->getScopes()
        );

        return AbstractHandler::renderHtml($request, $response, 'oauth-authorize', [
            'oauth' => [
                'clientName' => $authRequest->getClient()->getName(),
                'scopes' => $scopeLabels,
                'query' => $request->getUri()->getQuery(),
                'csrf' => $csrf,
            ],
        ]);
    }

    public function consent(Request $request, Response $response) {
        if (!$request->getAttribute('isLoggedIn')) {
            throw new HttpForbiddenException($request, 'Not logged in');
        }

        $body = (array) $request->getParsedBody();
        if (empty($_SESSION['oauth_csrf']) || !hash_equals($_SESSION['oauth_csrf'], (string) ($body['csrf'] ?? ''))) {
            throw new HttpForbiddenException($request, 'Invalid CSRF token');
        }
        unset($_SESSION['oauth_csrf']);

        $server = \oauth\authorizationServer();
        try {
            $authRequest = $server->validateAuthorizationRequest($request);
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse($response);
        }

        // Record uid -> email so issued tokens can resolve the owner (data is
        // encrypted by Firebase uid, but users are keyed by email).
        \store\prepare('REPLACE INTO oauth_users (user_id, user_email, updated_at) VALUES (:u, :e, :t)')
            ->execute([
                ':u' => $_SESSION['user_id'],
                ':e' => $_SESSION['user_email'],
                ':t' => date('c'),
            ]);

        $authRequest->setUser(new \oauth\UserEntity($_SESSION['user_id']));
        $authRequest->setAuthorizationApproved(($body['decision'] ?? '') === 'approve');

        try {
            return $server->completeAuthorizationRequest($authRequest, new ResponseObject());
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse($response);
        }
    }

    /** Settings page: the OAuth clients the user has connected via MCP. */
    public function connectedApps(Request $request, Response $response) {
        $connections = array_map(function ($row) {
            $ids = array_values(array_filter(explode(' ', str_replace(',', ' ', (string) $row['scopes']))));
            $row['scopeLabels'] = array_values(array_unique(array_map(
                fn ($id) => \oauth\SCOPES[$id] ?? $id,
                $ids
            )));
            return $row;
        }, \oauth\connectionsForUser($_SESSION['user_id']));

        $csrf = bin2hex(random_bytes(16));
        $_SESSION['oauth_csrf'] = $csrf;

        return AbstractHandler::renderHtml($request, $response, 'polaczone-aplikacje', [
            'connections' => $connections,
            'csrf' => $csrf,
        ]);
    }

    /** Revoke a whole connection (all tokens for one client) for this user. */
    public function revokeConnection(Request $request, Response $response) {
        $body = (array) $request->getParsedBody();
        if (empty($_SESSION['oauth_csrf']) || !hash_equals($_SESSION['oauth_csrf'], (string) ($body['csrf'] ?? ''))) {
            throw new HttpForbiddenException($request, 'Invalid CSRF token');
        }
        unset($_SESSION['oauth_csrf']);

        $clientId = (string) ($body['client_id'] ?? '');
        if ($clientId !== '') {
            \oauth\revokeConnection($clientId, $_SESSION['user_id']);
        }
        return AbstractHandler::redirect('/polaczone-aplikacje.html');
    }
}

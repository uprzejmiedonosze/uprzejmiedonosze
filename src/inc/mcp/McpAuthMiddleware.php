<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as ResponseObject;

/**
 * Authenticates /mcp requests by either an opaque OAuth access token or a
 * Firebase ID token (dual auth). Either way it sets the firebaseUser attribute
 * (uid/email) that the session bridge + UserMiddleware consume, plus the
 * granted scopes (mcpScopes).
 *
 * On a missing or unknown/expired opaque token it returns 401 with a
 * WWW-Authenticate header pointing at the protected-resource metadata, so MCP
 * clients can discover the OAuth flow and connect.
 */
class McpAuthMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        $token = $this->bearer($request);
        if ($token === null) {
            return $this->unauthorized('missing_token');
        }

        // Opaque OAuth token — validated by hashed DB lookup.
        $oauth = \oauth\validateAccessToken($token);
        if ($oauth !== null) {
            $request = $request
                ->withAttribute('firebaseUser', [
                    'user_id' => $oauth['uid'],
                    'user_email' => $oauth['email'],
                    'user_name' => '',
                    'user_picture' => '',
                    'token' => $token,
                ])
                ->withAttribute('mcpScopes', $oauth['scopes']);
            return $handler->handle($request);
        }

        // Dev only: a JWT-shaped token takes the Firebase ID-token path (the
        // user's own full session) so contributors can exercise /mcp locally
        // without the whole OAuth flow. In production /mcp is OAuth-only — a
        // Firebase token would otherwise grant unscoped access, bypassing the
        // per-client consent/scope model — so real clients get 401 + discovery.
        if (isDev() && substr_count($token, '.') >= 2) {
            $request = $request->withAttribute('mcpScopes', array_keys(\oauth\SCOPES));
            return (new AuthMiddleware())->process($request, $handler);
        }

        return $this->unauthorized('invalid_token');
    }

    private function bearer(Request $request): ?string {
        if (preg_match('/Bearer\s+(\S+)/', $request->getHeaderLine('Authorization'), $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function unauthorized(string $error): Response {
        $metadata = BASE_URL . '.well-known/oauth-protected-resource/mcp';
        return (new ResponseObject(401))->withHeader(
            'WWW-Authenticate',
            sprintf('Bearer resource_metadata="%s", error="%s"', $metadata, $error)
        );
    }
}

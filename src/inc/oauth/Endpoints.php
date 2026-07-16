<?PHP

namespace oauth;

use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

function jsonResponse(Response $response, array $data, int $status = 200): Response {
    $response->getBody()->write(json_encode($data));
    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
}

function jsonError(Response $response, int $status, string $error, string $description): Response {
    return jsonResponse($response, ['error' => $error, 'error_description' => $description], $status);
}

/** RFC 8414 authorization-server metadata. */
function authorizationServerMetadata(Request $request, Response $response): Response {
    return jsonResponse($response, [
        'issuer' => rtrim(BASE_URL, '/'),
        'authorization_endpoint' => BASE_URL . 'oauth/authorize',
        'token_endpoint' => BASE_URL . 'oauth/token',
        'registration_endpoint' => BASE_URL . 'oauth/register',
        'revocation_endpoint' => BASE_URL . 'oauth/revoke',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'scopes_supported' => array_keys(SCOPES),
    ]);
}

/** RFC 9728 protected-resource metadata for the /mcp resource. */
function protectedResourceMetadata(Request $request, Response $response): Response {
    return jsonResponse($response, [
        'resource' => mcpResource(),
        'authorization_servers' => [rtrim(BASE_URL, '/')],
        'scopes_supported' => array_keys(SCOPES),
    ]);
}

/**
 * RFC 7591 Dynamic Client Registration. Public clients only (PKCE), so no
 * secret is issued. redirect_uris are stored and later matched exactly.
 */
function register(Request $request, Response $response): Response {
    $body = (array) $request->getParsedBody();
    $redirectUris = $body['redirect_uris'] ?? null;

    if (!is_array($redirectUris) || $redirectUris === []) {
        return jsonError($response, 400, 'invalid_redirect_uri', 'redirect_uris is required');
    }
    foreach ($redirectUris as $uri) {
        if (!is_string($uri) || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return jsonError($response, 400, 'invalid_redirect_uri', "Invalid redirect_uri: $uri");
        }
    }

    $clientId = bin2hex(random_bytes(16));
    $name = is_string($body['client_name'] ?? null) ? $body['client_name'] : 'MCP client';

    $stmt = \store\prepare(
        'INSERT INTO oauth_clients (client_id, name, redirect_uris, is_confidential, created_at)
         VALUES (:id, :n, :u, 0, :t)'
    );
    $stmt->execute([
        ':id' => $clientId,
        ':n' => $name,
        ':u' => json_encode(array_values($redirectUris)),
        ':t' => date('c'),
    ]);

    return jsonResponse($response, [
        'client_id' => $clientId,
        'client_name' => $name,
        'redirect_uris' => array_values($redirectUris),
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ], 201);
}

/** OAuth token endpoint (authorization_code + refresh_token grants). */
function token(Request $request, Response $response): Response {
    try {
        return authorizationServer()->respondToAccessTokenRequest($request, $response);
    } catch (OAuthServerException $e) {
        return $e->generateHttpResponse($response);
    }
}

/**
 * RFC 7009 token revocation. The presented token may be either an opaque
 * access token (hash lookup) or a league-encrypted refresh token (decrypted
 * to recover its id); whichever it isn't is a harmless no-op. Always returns
 * 200 per the spec, even for an unknown token. Whole-connection revocation
 * (all tokens for a client) is done from the connected-apps page.
 */
function revoke(Request $request, Response $response): Response {
    $token = (string) (((array) $request->getParsedBody())['token'] ?? '');
    if ($token !== '') {
        (new AccessTokenRepository())->revokeAccessToken($token);

        $refreshTokenId = decryptRefreshTokenId($token);
        if ($refreshTokenId !== null) {
            (new RefreshTokenRepository())->revokeRefreshToken($refreshTokenId);
        }
    }
    return $response->withStatus(200);
}

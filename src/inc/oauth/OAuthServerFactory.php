<?PHP

namespace oauth;

use DateInterval;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;

/**
 * Builds the league AuthorizationServer for the MCP OAuth provider:
 * authorization-code grant with PKCE (public clients) + refresh-token grant.
 * Access tokens are opaque (see AccessTokenEntity).
 */
function authorizationServer(): AuthorizationServer {
    $accessTokenRepo = new AccessTokenRepository();
    $refreshTokenRepo = new RefreshTokenRepository();

    $server = new AuthorizationServer(
        new ClientRepository(),
        $accessTokenRepo,
        new ScopeRepository(),
        OAUTH_PRIVATE_KEY,
        OAUTH_ENCRYPTION_KEY
    );

    $authCodeGrant = new AuthCodeGrant(
        new AuthCodeRepository(),
        $refreshTokenRepo,
        new DateInterval('PT10M') // authorization code lifetime
    );
    $authCodeGrant->setRefreshTokenTTL(new DateInterval('P30D'));
    // PKCE is required by default for the auth-code grant (not disabled here).
    $server->enableGrantType($authCodeGrant, new DateInterval('PT1H')); // access token lifetime

    $refreshTokenGrant = new RefreshTokenGrant($refreshTokenRepo);
    $refreshTokenGrant->setRefreshTokenTTL(new DateInterval('P30D'));
    $server->enableGrantType($refreshTokenGrant, new DateInterval('PT1H'));

    return $server;
}

<?php

namespace UprzejmieDonosze\Tests\Oauth;

require_once __DIR__ . '/../../export/inc/oauth/Entities.php';
require_once __DIR__ . '/../../export/inc/oauth/Repositories.php';
require_once __DIR__ . '/../../export/inc/oauth/Endpoints.php';
require_once __DIR__ . '/../../export/inc/oauth/OAuthServerFactory.php';
require_once __DIR__ . '/../../export/inc/handlers/AbstractHandler.php';
require_once __DIR__ . '/../../export/inc/handlers/OAuthHandler.php';

use OAuthHandler;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use UprzejmieDonosze\Tests\DatabaseTestCase;

/**
 * The consent screen lets a user grant a subset of the requested scopes
 * (per-scope checkboxes). Drives authorize-consent → token and checks the
 * scopes the issued token actually carries.
 */
class ConsentScopesTest extends DatabaseTestCase
{
    private const REDIRECT = 'http://localhost/cb';
    // PKCE verifier must be 43-128 chars (RFC 7636).
    private const VERIFIER = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJ';

    protected function setUp(): void
    {
        parent::setUp();
        // The league AuthorizationServer needs OAUTH_PRIVATE_KEY. The test
        // bootstrap normally generates an ephemeral one, so this runs by
        // default; skip only in the unusual case where none is available (e.g.
        // openssl missing when the bootstrap ran).
        if (!defined('OAUTH_PRIVATE_KEY') || OAUTH_PRIVATE_KEY === '') {
            self::markTestSkipped('OAUTH_PRIVATE_KEY not available in this environment.');
        }
    }

    private function registerClient(): string
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/register')
            ->withParsedBody(['redirect_uris' => [self::REDIRECT], 'client_name' => 'Consent Test']);
        $response = \oauth\register($request, new Response());
        return json_decode((string) $response->getBody(), true)['client_id'];
    }

    /** @param string[] $requested @param string[] $checked */
    private function consent(string $clientId, array $requested, array $checked, string $decision = 'approve'): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'scope' => implode(' ', $requested),
            'state' => 'st4te',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $_SESSION['user_id'] = 'consent-uid';
        $_SESSION['user_email'] = 'consent@example.com';
        $_SESSION['oauth_csrf'] = 'csrf-token';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/authorize?' . $query)
            ->withParsedBody(['csrf' => 'csrf-token', 'decision' => $decision, 'scopes' => $checked])
            ->withAttribute('isLoggedIn', true);

        $response = (new OAuthHandler())->consent($request, new Response());
        self::assertSame(302, $response->getStatusCode());
        return $response->getHeaderLine('Location');
    }

    private function codeFrom(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertArrayHasKey('code', $params, "no code in redirect: $location");
        return $params['code'];
    }

    private function exchange(string $clientId, string $code): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/token')
            ->withParsedBody([
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'redirect_uri' => self::REDIRECT,
                'code' => $code,
                'code_verifier' => self::VERIFIER,
            ]);
        return json_decode((string) \oauth\token($request, new Response())->getBody(), true);
    }

    /** The scopes actually persisted for an issued opaque access token. */
    private function storedScopes(string $accessToken): string
    {
        $stmt = \store\prepare('SELECT scopes FROM oauth_access_tokens WHERE token_hash = :h');
        $stmt->execute([':h' => \oauth\tokenHash($accessToken)]);
        return (string) $stmt->fetch(\PDO::FETCH_COLUMN);
    }

    public function testConsentDownscopesToCheckedSubset(): void
    {
        $client = $this->registerClient();
        $location = $this->consent($client, ['reports:read', 'reports:status:write'], ['reports:read']);
        $token = $this->exchange($client, $this->codeFrom($location));

        self::assertArrayHasKey('access_token', $token);
        self::assertSame('reports:read', $this->storedScopes($token['access_token']), 'only the checked scope is granted');
    }

    public function testConsentKeepsAllWhenAllChecked(): void
    {
        $client = $this->registerClient();
        $location = $this->consent(
            $client,
            ['reports:read', 'reports:status:write'],
            ['reports:read', 'reports:status:write']
        );
        $token = $this->exchange($client, $this->codeFrom($location));

        $scopes = $this->storedScopes($token['access_token']);
        self::assertStringContainsString('reports:read', $scopes);
        self::assertStringContainsString('reports:status:write', $scopes);
    }

    public function testApproveWithNoScopesIsTreatedAsDenied(): void
    {
        $client = $this->registerClient();
        $location = $this->consent($client, ['reports:read', 'reports:status:write'], [], 'approve');

        self::assertStringContainsString('error=access_denied', $location);
    }
}

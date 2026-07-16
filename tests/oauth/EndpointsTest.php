<?php

namespace UprzejmieDonosze\Tests\Oauth;

require_once __DIR__ . '/../../export/inc/oauth/Entities.php';
require_once __DIR__ . '/../../export/inc/oauth/Repositories.php';
require_once __DIR__ . '/../../export/inc/oauth/Endpoints.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use UprzejmieDonosze\Tests\DatabaseTestCase;

class EndpointsTest extends DatabaseTestCase
{
    private function jsonBody(Response $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    // ── register() ──────────────────────────────────────────────────────────

    public function testRegisterCreatesAPublicClient(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/register')
            ->withParsedBody(['redirect_uris' => ['https://example.com/cb'], 'client_name' => 'My App']);

        $response = \oauth\register($request, new Response());

        self::assertSame(201, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('My App', $body['client_name']);
        self::assertSame(['https://example.com/cb'], $body['redirect_uris']);
        self::assertSame('none', $body['token_endpoint_auth_method']);
        self::assertNotEmpty($body['client_id']);

        $entity = (new \oauth\ClientRepository())->getClientEntity($body['client_id']);
        self::assertNotNull($entity);
        self::assertSame('My App', $entity->getName());
        self::assertFalse($entity->isConfidential());
    }

    public function testRegisterDefaultsClientNameWhenMissing(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/register')
            ->withParsedBody(['redirect_uris' => ['https://example.com/cb']]);

        $response = \oauth\register($request, new Response());

        self::assertSame('MCP client', $this->jsonBody($response)['client_name']);
    }

    public function testRegisterRejectsMissingRedirectUris(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/register')
            ->withParsedBody([]);

        $response = \oauth\register($request, new Response());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_redirect_uri', $this->jsonBody($response)['error']);
    }

    public function testRegisterRejectsInvalidRedirectUri(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/register')
            ->withParsedBody(['redirect_uris' => ['not-a-url']]);

        $response = \oauth\register($request, new Response());

        self::assertSame(400, $response->getStatusCode());
    }

    // ── revoke() ─────────────────────────────────────────────────────────────

    public function testRevokeAccessTokenMarksItRevoked(): void
    {
        \store\prepare(
            'INSERT INTO oauth_access_tokens
                (token_hash, client_id, user_id, user_email, scopes, resource, revoked, expires_at, created_at)
             VALUES (:h, :c, :u, :e, :s, :r, 0, :exp, :now)'
        )->execute([
            ':h' => \oauth\tokenHash('access-to-revoke'),
            ':c' => 'client-1', ':u' => 'uid-1', ':e' => 'a@b.com',
            ':s' => 'reports:read', ':r' => \oauth\mcpResource(),
            ':exp' => date('Y-m-d H:i:s', strtotime('+1 hour')), ':now' => date('Y-m-d H:i:s'),
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/revoke')
            ->withParsedBody(['token' => 'access-to-revoke']);
        $response = \oauth\revoke($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        self::assertNull(\oauth\validateAccessToken('access-to-revoke'));
    }

    /** Regression test for the /oauth/revoke refresh-token fix. */
    public function testRevokeRefreshTokenMarksItRevoked(): void
    {
        \store\prepare(
            'INSERT INTO oauth_refresh_tokens (id, access_token_id, client_id, user_id, revoked, expires_at)
             VALUES (:id, :at, :c, :u, 0, :exp)'
        )->execute([
            ':id' => 'rt-to-revoke', ':at' => 'at-1', ':c' => 'client-1', ':u' => 'uid-1',
            ':exp' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        $payload = json_encode([
            'client_id' => 'client-1',
            'refresh_token_id' => 'rt-to-revoke',
            'access_token_id' => 'at-1',
            'scopes' => [],
            'user_id' => 'uid-1',
            'expire_time' => time() + 86400 * 30,
        ]);
        $encrypted = \Defuse\Crypto\Crypto::encryptWithPassword($payload, OAUTH_ENCRYPTION_KEY);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/revoke')
            ->withParsedBody(['token' => $encrypted]);
        $response = \oauth\revoke($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue((new \oauth\RefreshTokenRepository())->isRefreshTokenRevoked('rt-to-revoke'));
    }

    public function testRevokeDoesNotTouchUnrelatedTokens(): void
    {
        \store\prepare(
            'INSERT INTO oauth_refresh_tokens (id, access_token_id, client_id, user_id, revoked, expires_at)
             VALUES (:id, :at, :c, :u, 0, :exp)'
        )->execute([
            ':id' => 'rt-untouched', ':at' => 'at-2', ':c' => 'client-1', ':u' => 'uid-1',
            ':exp' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/revoke')
            ->withParsedBody(['token' => 'some-unrelated-access-token']);
        \oauth\revoke($request, new Response());

        self::assertFalse((new \oauth\RefreshTokenRepository())->isRefreshTokenRevoked('rt-untouched'));
    }

    public function testRevokeUnknownTokenStillReturns200(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/revoke')
            ->withParsedBody(['token' => 'never-issued']);

        self::assertSame(200, \oauth\revoke($request, new Response())->getStatusCode());
    }

    public function testRevokeEmptyTokenReturns200(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/oauth/revoke')
            ->withParsedBody([]);

        self::assertSame(200, \oauth\revoke($request, new Response())->getStatusCode());
    }

    // ── metadata ─────────────────────────────────────────────────────────────

    public function testAuthorizationServerMetadata(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/.well-known/oauth-authorization-server');
        $body = $this->jsonBody(\oauth\authorizationServerMetadata($request, new Response()));

        self::assertSame(['code'], $body['response_types_supported']);
        self::assertSame(['authorization_code', 'refresh_token'], $body['grant_types_supported']);
        self::assertSame(['S256'], $body['code_challenge_methods_supported']);
        self::assertContains('reports:read', $body['scopes_supported']);
        self::assertContains('reports:status:write', $body['scopes_supported']);
    }

    public function testProtectedResourceMetadata(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/.well-known/oauth-protected-resource/mcp');
        $body = $this->jsonBody(\oauth\protectedResourceMetadata($request, new Response()));

        self::assertSame(\oauth\mcpResource(), $body['resource']);
        self::assertContains('reports:read', $body['scopes_supported']);
    }
}

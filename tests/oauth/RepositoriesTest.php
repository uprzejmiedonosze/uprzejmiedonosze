<?php

namespace UprzejmieDonosze\Tests\Oauth;

require_once __DIR__ . '/../../export/inc/oauth/Entities.php';
require_once __DIR__ . '/../../export/inc/oauth/Repositories.php';

use DateTimeImmutable;
use oauth\AccessTokenRepository;
use oauth\AuthCodeRepository;
use oauth\ClientEntity;
use oauth\ClientRepository;
use oauth\RefreshTokenRepository;
use oauth\ScopeEntity;
use oauth\ScopeRepository;
use UprzejmieDonosze\Tests\DatabaseTestCase;

class RepositoriesTest extends DatabaseTestCase
{
    private function insertClient(string $id = 'client-1'): void
    {
        \store\prepare(
            'INSERT INTO oauth_clients (client_id, name, redirect_uris, is_confidential, created_at)
             VALUES (:id, :n, :u, 0, :t)'
        )->execute([
            ':id' => $id,
            ':n' => 'Test Client',
            ':u' => json_encode(['https://example.com/callback']),
            ':t' => date('c'),
        ]);
    }

    private function insertOauthUser(string $uid, string $email): void
    {
        \store\prepare('REPLACE INTO oauth_users (user_id, user_email, updated_at) VALUES (:u, :e, :t)')
            ->execute([':u' => $uid, ':e' => $email, ':t' => date('c')]);
    }

    /** Inserts an access-token row directly (bypassing persistNewAccessToken) for validateAccessToken() tests. */
    private function insertAccessToken(string $rawToken, array $overrides = []): void
    {
        $row = array_merge([
            'client_id' => 'client-1',
            'user_id' => 'uid-1',
            'user_email' => 'user@example.com',
            'scopes' => 'reports:read',
            'resource' => \oauth\mcpResource(),
            'revoked' => 0,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'created_at' => date('Y-m-d H:i:s'),
        ], $overrides);

        \store\prepare(
            'INSERT INTO oauth_access_tokens
                (token_hash, client_id, user_id, user_email, scopes, resource, revoked, expires_at, created_at)
             VALUES (:h, :c, :u, :e, :s, :r, :rev, :exp, :now)'
        )->execute([
            ':h' => \oauth\tokenHash($rawToken),
            ':c' => $row['client_id'],
            ':u' => $row['user_id'],
            ':e' => $row['user_email'],
            ':s' => $row['scopes'],
            ':r' => $row['resource'],
            ':rev' => $row['revoked'],
            ':exp' => $row['expires_at'],
            ':now' => $row['created_at'],
        ]);
    }

    public function testMcpResourceIsBaseUrlPlusMcp(): void
    {
        self::assertSame(BASE_URL . 'mcp', \oauth\mcpResource());
    }

    public function testDecryptRefreshTokenIdRoundTrip(): void
    {
        $payload = json_encode([
            'client_id' => 'client-1',
            'refresh_token_id' => 'rt-abc123',
            'access_token_id' => 'at-1',
            'scopes' => [],
            'user_id' => 'uid-1',
            'expire_time' => time() + 3600,
        ]);
        $token = \Defuse\Crypto\Crypto::encryptWithPassword($payload, OAUTH_ENCRYPTION_KEY);

        self::assertSame('rt-abc123', \oauth\decryptRefreshTokenId($token));
    }

    public function testDecryptRefreshTokenIdRejectsNonCiphertext(): void
    {
        self::assertNull(\oauth\decryptRefreshTokenId('not-a-valid-refresh-token'));
    }

    public function testDecryptRefreshTokenIdRejectsOpaqueAccessTokenShapedString(): void
    {
        // An opaque access token is just random hex — never valid Defuse ciphertext.
        self::assertNull(\oauth\decryptRefreshTokenId(bin2hex(random_bytes(32))));
    }

    public function testEmailForUidReturnsRegisteredEmail(): void
    {
        $this->insertOauthUser('uid-42', 'someone@example.com');
        self::assertSame('someone@example.com', \oauth\emailForUid('uid-42'));
    }

    public function testEmailForUidReturnsNullForUnknownUid(): void
    {
        self::assertNull(\oauth\emailForUid('no-such-uid'));
    }

    public function testEmailForUidReturnsNullForEmptyUid(): void
    {
        self::assertNull(\oauth\emailForUid(null));
        self::assertNull(\oauth\emailForUid(''));
    }

    public function testValidateAccessTokenHappyPath(): void
    {
        $this->insertAccessToken('raw-token-1', [
            'user_id' => 'uid-1',
            'user_email' => 'a@b.com',
            'scopes' => 'reports:read reports:status:write',
        ]);

        $result = \oauth\validateAccessToken('raw-token-1');

        self::assertNotNull($result);
        self::assertSame('uid-1', $result['uid']);
        self::assertSame('a@b.com', $result['email']);
        self::assertSame(['reports:read', 'reports:status:write'], $result['scopes']);
    }

    public function testValidateAccessTokenRejectsUnknownToken(): void
    {
        self::assertNull(\oauth\validateAccessToken('never-issued'));
    }

    public function testValidateAccessTokenRejectsRevoked(): void
    {
        $this->insertAccessToken('revoked-token', ['revoked' => 1]);
        self::assertNull(\oauth\validateAccessToken('revoked-token'));
    }

    public function testValidateAccessTokenRejectsExpired(): void
    {
        $this->insertAccessToken('expired-token', [
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);
        self::assertNull(\oauth\validateAccessToken('expired-token'));
    }

    public function testValidateAccessTokenRejectsWrongResource(): void
    {
        $this->insertAccessToken('other-resource-token', ['resource' => 'https://not-us.example/mcp']);
        self::assertNull(\oauth\validateAccessToken('other-resource-token'));
    }

    public function testConnectionsForUserExcludesRevokedAndGroupsByClient(): void
    {
        $this->insertClient('client-a');
        $this->insertClient('client-b');

        $this->insertAccessToken('tok-a1', ['client_id' => 'client-a', 'user_id' => 'uid-9']);
        $this->insertAccessToken('tok-a2', ['client_id' => 'client-a', 'user_id' => 'uid-9']);
        $this->insertAccessToken('tok-b1', ['client_id' => 'client-b', 'user_id' => 'uid-9']);
        $this->insertAccessToken('tok-revoked', ['client_id' => 'client-b', 'user_id' => 'uid-9', 'revoked' => 1]);
        // Different user entirely — must not leak into uid-9's connections.
        $this->insertAccessToken('tok-other-user', ['client_id' => 'client-a', 'user_id' => 'uid-other']);

        $connections = \oauth\connectionsForUser('uid-9');
        $byClient = array_column($connections, null, 'client_id');

        self::assertCount(2, $connections);
        self::assertArrayHasKey('client-a', $byClient);
        self::assertArrayHasKey('client-b', $byClient);
        self::assertSame('Test Client', $byClient['client-a']['client_name']);
    }

    public function testRevokeConnectionOnlyRevokesMatchingClientAndUser(): void
    {
        $this->insertClient('client-a');
        $this->insertAccessToken('to-revoke', ['client_id' => 'client-a', 'user_id' => 'uid-9']);
        $this->insertAccessToken('unrelated-user', ['client_id' => 'client-a', 'user_id' => 'uid-other']);
        $this->insertAccessToken('unrelated-client', ['client_id' => 'client-b', 'user_id' => 'uid-9']);

        \oauth\revokeConnection('client-a', 'uid-9');

        self::assertNull(\oauth\validateAccessToken('to-revoke'));
        self::assertNotNull(\oauth\validateAccessToken('unrelated-user'));
        self::assertNotNull(\oauth\validateAccessToken('unrelated-client'));
    }

    public function testClientRepositoryReturnsRegisteredClient(): void
    {
        $this->insertClient('client-x');
        $entity = (new ClientRepository())->getClientEntity('client-x');

        self::assertNotNull($entity);
        self::assertSame('client-x', $entity->getIdentifier());
        self::assertSame('Test Client', $entity->getName());
        self::assertSame(['https://example.com/callback'], $entity->getRedirectUri());
        self::assertFalse($entity->isConfidential());
    }

    public function testClientRepositoryReturnsNullForUnknownClient(): void
    {
        self::assertNull((new ClientRepository())->getClientEntity('no-such-client'));
    }

    public function testClientRepositoryValidateClientAlwaysTrue(): void
    {
        // Public clients only — PKCE is the proof, there is no secret to check.
        self::assertTrue((new ClientRepository())->validateClient('anything', null, 'authorization_code'));
        self::assertTrue((new ClientRepository())->validateClient('anything', 'some-secret', null));
    }

    public function testScopeRepositoryKnownAndUnknownIdentifiers(): void
    {
        $repo = new ScopeRepository();
        self::assertSame('reports:read', $repo->getScopeEntityByIdentifier('reports:read')->getIdentifier());
        self::assertNull($repo->getScopeEntityByIdentifier('not-a-real-scope'));
    }

    public function testScopeRepositoryFinalizeScopesFiltersUnknownScopes(): void
    {
        $client = new ClientEntity('client-1', 'Test Client', 'https://example.com/callback');
        $scopes = [new ScopeEntity('reports:read'), new ScopeEntity('made-up-scope')];

        $finalized = (new ScopeRepository())->finalizeScopes($scopes, 'authorization_code', $client);

        self::assertCount(1, $finalized);
        self::assertSame('reports:read', $finalized[0]->getIdentifier());
    }

    public function testAccessTokenRepositoryPersistRevokeAndIsRevoked(): void
    {
        $this->insertClient('client-1');
        $this->insertOauthUser('uid-1', 'a@b.com');

        $repo = new AccessTokenRepository();
        $client = new ClientEntity('client-1', 'Test Client', 'https://example.com/callback');
        $scope = new ScopeEntity('reports:read');

        $token = $repo->getNewToken($client, [$scope], 'uid-1');
        $token->setIdentifier('persisted-raw-token');
        $token->setExpiryDateTime(new DateTimeImmutable('+1 hour'));
        $repo->persistNewAccessToken($token);

        self::assertFalse($repo->isAccessTokenRevoked('persisted-raw-token'));
        $validated = \oauth\validateAccessToken('persisted-raw-token');
        self::assertNotNull($validated);
        self::assertSame('a@b.com', $validated['email']);

        $repo->revokeAccessToken('persisted-raw-token');
        self::assertTrue($repo->isAccessTokenRevoked('persisted-raw-token'));
    }

    public function testAccessTokenRepositoryIsRevokedTrueForUnknownToken(): void
    {
        // No row at all is treated the same as revoked — safe default.
        self::assertTrue((new AccessTokenRepository())->isAccessTokenRevoked('never-existed'));
    }

    public function testAuthCodeRepositoryPersistRevokeAndIsRevoked(): void
    {
        $repo = new AuthCodeRepository();
        $code = $repo->getNewAuthCode();
        $code->setIdentifier('auth-code-1');
        $code->setExpiryDateTime(new DateTimeImmutable('+10 minutes'));

        $repo->persistNewAuthCode($code);
        self::assertFalse($repo->isAuthCodeRevoked('auth-code-1'));

        $repo->revokeAuthCode('auth-code-1');
        self::assertTrue($repo->isAuthCodeRevoked('auth-code-1'));
    }

    public function testAuthCodeRepositoryIsRevokedTrueForUnknownCode(): void
    {
        self::assertTrue((new AuthCodeRepository())->isAuthCodeRevoked('never-existed'));
    }

    public function testRefreshTokenRepositoryPersistRevokeAndIsRevoked(): void
    {
        $this->insertClient('client-1');
        $client = new ClientEntity('client-1', 'Test Client', 'https://example.com/callback');

        $accessTokenRepo = new AccessTokenRepository();
        $accessToken = $accessTokenRepo->getNewToken($client, [], 'uid-1');
        $accessToken->setIdentifier('at-for-refresh');
        $accessToken->setExpiryDateTime(new DateTimeImmutable('+1 hour'));

        $refreshRepo = new RefreshTokenRepository();
        $refreshToken = $refreshRepo->getNewRefreshToken();
        $refreshToken->setIdentifier('refresh-1');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime(new DateTimeImmutable('+30 days'));

        $refreshRepo->persistNewRefreshToken($refreshToken);
        self::assertFalse($refreshRepo->isRefreshTokenRevoked('refresh-1'));

        $refreshRepo->revokeRefreshToken('refresh-1');
        self::assertTrue($refreshRepo->isRefreshTokenRevoked('refresh-1'));
    }

    public function testRefreshTokenRepositoryIsRevokedTrueForUnknownToken(): void
    {
        self::assertTrue((new RefreshTokenRepository())->isRefreshTokenRevoked('never-existed'));
    }
}

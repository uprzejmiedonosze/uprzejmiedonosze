<?PHP

namespace oauth;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\CryptoException;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * The single source of truth for OAuth scopes: machine identifier => label
 * shown on the (Polish) consent screen. The identifiers are API constants and
 * appear in the metadata's scopes_supported; the labels are user-facing. To
 * add a scope, add one entry here; validation, metadata, and the consent UI
 * all read from it. Enforcement on a specific tool is done where that tool runs.
 */
const SCOPES = [
    'reports:read'         => 'Odczyt Twoich zgłoszeń',
    'reports:status:write' => 'Zmiana statusu Twoich zgłoszeń',
    'reports:notes:write'  => 'Zapisywanie notatek i numeru sprawy w Twoich zgłoszeniach',
    'reports:create'       => 'Tworzenie wersji roboczych zgłoszeń w Twoim imieniu',
];

/** Opaque tokens are stored only as SHA-256 hashes. */
function tokenHash(string $token): string {
    return hash('sha256', $token);
}

/** The resource (audience) every MCP token is bound to. */
function mcpResource(): string {
    return BASE_URL . 'mcp';
}

/**
 * Recover the identifier of a league-issued refresh token, for /oauth/revoke.
 * Unlike access tokens, refresh tokens aren't opaque: league encrypts a JSON
 * payload (see BearerTokenResponse) with OAUTH_ENCRYPTION_KEY. A token that
 * isn't one of ours — e.g. an access token presented to the same endpoint —
 * simply fails to decrypt, so this returns null rather than throwing.
 */
function decryptRefreshTokenId(string $token): ?string {
    try {
        $payload = json_decode(Crypto::decryptWithPassword($token, OAUTH_ENCRYPTION_KEY), true);
    } catch (CryptoException $e) {
        return null;
    }
    $id = is_array($payload) ? ($payload['refresh_token_id'] ?? null) : null;
    return is_string($id) ? $id : null;
}

/** Resolve a Firebase uid to its email (populated at consent time). */
function emailForUid(?string $uid): ?string {
    if (!$uid) {
        return null;
    }
    $stmt = \store\prepare('SELECT user_email FROM oauth_users WHERE user_id = :u');
    $stmt->execute([':u' => $uid]);
    $email = $stmt->fetch(\PDO::FETCH_COLUMN);
    return $email ?: null;
}

/**
 * Validate an opaque MCP access token for the resource server. Returns
 * [uid, email, scopes] for a live token bound to the MCP resource, else null
 * (the caller then falls back to the Firebase-bearer path).
 */
function validateAccessToken(string $token): ?array {
    $stmt = \store\prepare(
        'SELECT user_id, user_email, scopes, resource, revoked, expires_at
         FROM oauth_access_tokens WHERE token_hash = :h'
    );
    $stmt->execute([':h' => tokenHash($token)]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row || (int) $row['revoked'] === 1) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null;
    }
    if ($row['resource'] !== mcpResource()) {
        return null;
    }
    return [
        'uid' => $row['user_id'],
        'email' => $row['user_email'],
        'scopes' => array_values(array_filter(explode(' ', (string) $row['scopes']))),
    ];
}

/**
 * Active connections (authorized clients) for a user, for the connected-apps
 * page: one row per client the user still has a live access token for.
 */
function connectionsForUser(string $userId): array {
    $stmt = \store\prepare(
        "SELECT t.client_id,
                COALESCE(c.name, t.client_id) AS client_name,
                group_concat(DISTINCT t.scopes) AS scopes,
                min(t.created_at) AS since
         FROM oauth_access_tokens t
         LEFT JOIN oauth_clients c ON c.client_id = t.client_id
         WHERE t.user_id = :u AND t.revoked = 0
         GROUP BY t.client_id
         ORDER BY since DESC"
    );
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Revoke a whole connection: every access and refresh token issued to this
 * client for this user. Takes effect immediately (opaque tokens).
 */
function revokeConnection(string $clientId, string $userId): void {
    foreach (['oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
        $stmt = \store\prepare("UPDATE $table SET revoked = 1 WHERE client_id = :c AND user_id = :u");
        $stmt->execute([':c' => $clientId, ':u' => $userId]);
    }
}

class ClientRepository implements ClientRepositoryInterface {
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface {
        $stmt = \store\prepare(
            'SELECT name, redirect_uris, is_confidential FROM oauth_clients WHERE client_id = :id'
        );
        $stmt->execute([':id' => $clientIdentifier]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $uris = json_decode($row['redirect_uris'], true) ?: [];
        return new ClientEntity($clientIdentifier, $row['name'], $uris, (bool) $row['is_confidential']);
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool {
        // Public clients only: PKCE (enforced by the auth-code grant) is the proof,
        // so there is no secret to validate.
        return true;
    }
}

class ScopeRepository implements ScopeRepositoryInterface {
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface {
        return array_key_exists($identifier, SCOPES) ? new ScopeEntity($identifier) : null;
    }

    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null
    ): array {
        return array_values(array_filter(
            $scopes,
            fn ($scope) => array_key_exists($scope->getIdentifier(), SCOPES)
        ));
    }
}

class AccessTokenRepository implements AccessTokenRepositoryInterface {
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null
    ): AccessTokenEntityInterface {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        if ($userIdentifier !== null) {
            $token->setUserIdentifier($userIdentifier);
        }
        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void {
        $uid = $accessTokenEntity->getUserIdentifier();
        $scopes = implode(' ', array_map(
            fn ($scope) => $scope->getIdentifier(),
            $accessTokenEntity->getScopes()
        ));
        $stmt = \store\prepare(
            'INSERT INTO oauth_access_tokens
                (token_hash, client_id, user_id, user_email, scopes, resource, revoked, expires_at, created_at)
             VALUES (:h, :c, :u, :e, :s, :r, 0, :exp, :now)'
        );
        $stmt->execute([
            ':h' => tokenHash($accessTokenEntity->getIdentifier()),
            ':c' => $accessTokenEntity->getClient()->getIdentifier(),
            ':u' => $uid,
            ':e' => emailForUid($uid),
            ':s' => $scopes,
            ':r' => mcpResource(),
            ':exp' => $accessTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
            ':now' => date('Y-m-d H:i:s'),
        ]);
    }

    public function revokeAccessToken(string $tokenId): void {
        $stmt = \store\prepare('UPDATE oauth_access_tokens SET revoked = 1 WHERE token_hash = :h');
        $stmt->execute([':h' => tokenHash($tokenId)]);
    }

    public function isAccessTokenRevoked(string $tokenId): bool {
        $stmt = \store\prepare('SELECT revoked, expires_at FROM oauth_access_tokens WHERE token_hash = :h');
        $stmt->execute([':h' => tokenHash($tokenId)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return true;
        }
        return (int) $row['revoked'] === 1 || strtotime($row['expires_at']) < time();
    }
}

class AuthCodeRepository implements AuthCodeRepositoryInterface {
    public function getNewAuthCode(): AuthCodeEntityInterface {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void {
        $stmt = \store\prepare(
            'INSERT INTO oauth_auth_codes (id, revoked, expires_at) VALUES (:id, 0, :exp)'
        );
        $stmt->execute([
            ':id' => $authCodeEntity->getIdentifier(),
            ':exp' => $authCodeEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
        ]);
    }

    public function revokeAuthCode(string $codeId): void {
        $stmt = \store\prepare('UPDATE oauth_auth_codes SET revoked = 1 WHERE id = :id');
        $stmt->execute([':id' => $codeId]);
    }

    public function isAuthCodeRevoked(string $codeId): bool {
        $stmt = \store\prepare('SELECT revoked FROM oauth_auth_codes WHERE id = :id');
        $stmt->execute([':id' => $codeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return !$row || (int) $row['revoked'] === 1;
    }
}

class RefreshTokenRepository implements RefreshTokenRepositoryInterface {
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void {
        $accessToken = $refreshTokenEntity->getAccessToken();
        $stmt = \store\prepare(
            'INSERT INTO oauth_refresh_tokens (id, access_token_id, client_id, user_id, revoked, expires_at)
             VALUES (:id, :atid, :c, :u, 0, :exp)'
        );
        $stmt->execute([
            ':id' => $refreshTokenEntity->getIdentifier(),
            ':atid' => $accessToken->getIdentifier(),
            ':c' => $accessToken->getClient()->getIdentifier(),
            ':u' => $accessToken->getUserIdentifier(),
            ':exp' => $refreshTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
        ]);
    }

    public function revokeRefreshToken(string $tokenId): void {
        $stmt = \store\prepare('UPDATE oauth_refresh_tokens SET revoked = 1 WHERE id = :id');
        $stmt->execute([':id' => $tokenId]);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool {
        $stmt = \store\prepare('SELECT revoked FROM oauth_refresh_tokens WHERE id = :id');
        $stmt->execute([':id' => $tokenId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return !$row || (int) $row['revoked'] === 1;
    }
}

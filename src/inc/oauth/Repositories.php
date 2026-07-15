<?PHP

namespace oauth;

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

const ALLOWED_SCOPES = ['reports:read', 'reports:status:write'];

/** Opaque tokens are stored only as SHA-256 hashes. */
function tokenHash(string $token): string {
    return hash('sha256', $token);
}

/** The resource (audience) every MCP token is bound to. */
function mcpResource(): string {
    return BASE_URL . 'mcp';
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
        return in_array($identifier, ALLOWED_SCOPES, true) ? new ScopeEntity($identifier) : null;
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
            fn ($scope) => in_array($scope->getIdentifier(), ALLOWED_SCOPES, true)
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

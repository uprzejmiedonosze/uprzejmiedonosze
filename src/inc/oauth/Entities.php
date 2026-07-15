<?PHP

namespace oauth;

use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

class ClientEntity implements ClientEntityInterface {
    use EntityTrait;
    use ClientTrait;

    public function __construct(string $identifier, string $name, string|array $redirectUri, bool $isConfidential = false) {
        $this->setIdentifier($identifier);
        $this->name = $name;
        $this->redirectUri = $redirectUri;
        $this->isConfidential = $isConfidential;
    }
}

class ScopeEntity implements ScopeEntityInterface {
    use EntityTrait;
    use ScopeTrait;

    public function __construct(string $identifier) {
        $this->setIdentifier($identifier);
    }
}

/**
 * Opaque access token: toString() returns the random identifier rather than a
 * signed JWT, so the token the client receives is opaque and validated by a
 * hashed DB lookup in the resource server.
 */
class AccessTokenEntity implements AccessTokenEntityInterface {
    use EntityTrait;
    use TokenEntityTrait;

    public function setPrivateKey(CryptKeyInterface $privateKey): void {
        // No signing: the token is opaque.
    }

    public function toString(): string {
        return (string) $this->getIdentifier();
    }
}

class AuthCodeEntity implements AuthCodeEntityInterface {
    use EntityTrait;
    use TokenEntityTrait;
    use AuthCodeTrait;
}

class RefreshTokenEntity implements RefreshTokenEntityInterface {
    use EntityTrait;
    use RefreshTokenTrait;
}

class UserEntity implements UserEntityInterface {
    use EntityTrait;

    public function __construct(string $firebaseUid) {
        $this->setIdentifier($firebaseUid);
    }
}

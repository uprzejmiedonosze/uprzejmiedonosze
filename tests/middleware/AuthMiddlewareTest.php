<?php

namespace UprzejmieDonosze\Tests\Middleware;

require_once __DIR__ . '/../../export/inc/middleware/AuthMiddleware.php';

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Emulator branch only (the real one needs Firebase public keys over the network). Pins the status contract:
 * an unusable token is 401, so clients refresh the ID token and retry, instead of a dead-end 403.
 */
class AuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('FIREBASE_AUTH_EMULATOR_HOST=127.0.0.1:9099');
    }

    protected function tearDown(): void
    {
        putenv('FIREBASE_AUTH_EMULATOR_HOST');
    }

    private static function jwt(array $payload): string
    {
        $b64 = fn (array $o) => rtrim(strtr(base64_encode(json_encode($o)), '+/', '-_'), '=');
        return $b64(['alg' => 'none', 'typ' => 'JWT']) . '.' . $b64($payload) . '.';
    }

    private function authenticate(?string $authorization): ?array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/rest/user/apps');
        if ($authorization !== null) $request = $request->withHeader('Authorization', $authorization);
        $handler = new class implements RequestHandlerInterface {
            public ?array $user = null;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->user = $request->getAttribute('firebaseUser');
                return new Response();
            }
        };
        (new \AuthMiddleware())->process($request, $handler);
        return $handler->user;
    }

    public function testValidTokenPassesTheUserOn(): void
    {
        $user = $this->authenticate('Bearer ' . self::jwt(['email' => 'auth-test@example.com', 'user_id' => 'uid-1']));
        self::assertNotNull($user);
        self::assertArrayHasKey('user_email', $user);
    }

    public function testInvalidTokenIsUnauthorizedNotForbidden(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $this->authenticate('Bearer ' . self::jwt(['sub' => 'no-email']));
    }

    public function testMissingTokenStaysBadRequest(): void
    {
        $this->expectException(HttpBadRequestException::class);
        $this->authenticate(null);
    }
}

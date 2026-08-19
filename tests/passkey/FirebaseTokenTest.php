<?php

namespace UprzejmieDonosze\Tests\Passkey;

require_once __DIR__ . '/../../export/inc/passkey/FirebaseToken.php';

use UprzejmieDonosze\Tests\DatabaseTestCase;

/**
 * These tests deliberately avoid the "service account file present" branch
 * (it would need a real/valid Firebase credentials file and network access).
 * What they DO pin down is the security-critical contract: resolveUid() must
 * never fall back to something other than the explicitly-passed uid, and must
 * never silently invent one — see the 'dev-user' pinning trap this guards
 * against in src/inc/passkey/FirebaseToken.php.
 */
class FirebaseTokenTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        putenv('FIREBASE_AUTH_EMULATOR_HOST');
        parent::tearDown();
    }

    public function testResolveUidWithoutServiceAccountUsesFallback(): void
    {
        self::assertFalse(\passkey\hasServiceAccount(), 'test env must not ship a real service-account file');
        self::assertSame('fallback-uid', \passkey\resolveUid('nobody@example.com', 'fallback-uid'));
    }

    public function testResolveUidWithoutServiceAccountOrFallbackThrows(): void
    {
        $this->expectExceptionMessage('Nie udało się zweryfikować konta');
        \passkey\resolveUid('nobody@example.com', null);
    }

    public function testCustomTokenWithoutServiceAccountOrEmulatorThrows(): void
    {
        putenv('FIREBASE_AUTH_EMULATOR_HOST');
        self::assertFalse(\passkey\usingEmulator());
        $this->expectExceptionMessage('Logowanie passkeyem jest chwilowo niedostępne');
        \passkey\customToken('some-uid');
    }

    public function testCustomTokenDevFallbackProducesAWellFormedUnsignedJwtForTheEmulator(): void
    {
        putenv('FIREBASE_AUTH_EMULATOR_HOST=127.0.0.1:9099');
        self::assertTrue(\passkey\usingEmulator());

        $token = \passkey\customToken('emulator-uid-123', ['name' => 'Test User']);
        $parts = explode('.', $token);
        self::assertCount(3, $parts, 'JWT must have header.payload.signature');

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        self::assertSame('emulator-uid-123', $payload['uid']);
        self::assertSame('Test User', $payload['name']);
        self::assertSame(
            'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
            $payload['aud']
        );
    }
}

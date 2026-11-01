<?php

namespace UprzejmieDonosze\Tests\Passkey;

use UprzejmieDonosze\Tests\DatabaseTestCase;

class PasskeyStoreTest extends DatabaseTestCase
{
    public function testUserHandleIsRandomAndStable(): void
    {
        $handle = \passkey\userHandle('a@example.com');
        self::assertNotSame('', $handle);
        // 32 random bytes, base64url-encoded without padding -> 43 chars.
        self::assertSame(43, strlen($handle));

        // Idempotent: same email always resolves to the same handle.
        self::assertSame($handle, \passkey\userHandle('a@example.com'));

        // Different accounts never share a handle (it must not be guessable/derivable).
        self::assertNotSame($handle, \passkey\userHandle('b@example.com'));
    }

    public function testUserHandleIsNotTheFirebaseUid(): void
    {
        // The regression this guards against: using $_SESSION['user_id'] (the
        // Firebase uid, and thus the crypto passphrase for user data) as the
        // WebAuthn user handle, which the authenticator echoes back to the client.
        $_SESSION['user_id'] = 'firebase-uid-123';
        $handle = \passkey\userHandle('c@example.com');
        self::assertNotSame('firebase-uid-123', $handle);
    }

    public function testEmailForHandle(): void
    {
        $handle = \passkey\userHandle('d@example.com');
        self::assertSame('d@example.com', \passkey\emailForHandle($handle));
        self::assertNull(\passkey\emailForHandle('does-not-exist'));
    }

    public function testAddAndByCredentialId(): void
    {
        \passkey\add('cred-1', 'e@example.com', 'uid-1', '-----BEGIN PUBLIC KEY-----...', 'aaguid', ['internal'], 'Test Key');

        $row = \passkey\byCredentialId('cred-1');
        self::assertNotNull($row);
        self::assertSame('e@example.com', $row['user_email']);
        self::assertSame('uid-1', $row['user_id']);
        self::assertSame(0, (int)$row['sign_count']);
        self::assertSame('Test Key', $row['label']);

        self::assertNull(\passkey\byCredentialId('does-not-exist'));
    }

    public function testForEmailAndCount(): void
    {
        \passkey\add('cred-a', 'f@example.com', 'uid', 'pk', null, [], 'A');
        \passkey\add('cred-b', 'f@example.com', 'uid', 'pk', null, [], 'B');
        \passkey\add('cred-c', 'other@example.com', 'uid', 'pk', null, [], 'C');

        self::assertCount(2, \passkey\forEmail('f@example.com'));
        self::assertSame(2, \passkey\countForEmail('f@example.com'));
        self::assertSame(1, \passkey\countForEmail('other@example.com'));
        self::assertSame(0, \passkey\countForEmail('nobody@example.com'));
    }

    public function testForEmailRestExposesOnlyTheApiContract(): void
    {
        // The regression this guards against: register-verify returning raw rows crashed
        // the app's account screen (UD-MOBILE-T) and sent it the public key and e-mail.
        \passkey\add('cred-r', 'r@example.com', 'uid', 'pk', null, [], 'R');

        $rows = \passkey\forEmailRest('r@example.com');
        self::assertCount(1, $rows);
        self::assertSame(['id', 'label', 'createdAt', 'lastUsedAt'], array_keys($rows[0]));
        self::assertSame('cred-r', $rows[0]['id']);
        self::assertSame('R', $rows[0]['label']);
        self::assertNotEmpty($rows[0]['createdAt']);
        self::assertNull($rows[0]['lastUsedAt']);
    }

    public function testRemoveOnlyDeletesOwnCredential(): void
    {
        \passkey\add('cred-x', 'owner@example.com', 'uid', 'pk', null, [], 'X');

        // Wrong email: not removed (this IS the authorization check).
        self::assertFalse(\passkey\remove('cred-x', 'attacker@example.com'));
        self::assertNotNull(\passkey\byCredentialId('cred-x'));

        self::assertTrue(\passkey\remove('cred-x', 'owner@example.com'));
        self::assertNull(\passkey\byCredentialId('cred-x'));

        // Removing again is a no-op, not an error.
        self::assertFalse(\passkey\remove('cred-x', 'owner@example.com'));
    }

    public function testTouchUpdatesSignCountAndLastUsed(): void
    {
        \passkey\add('cred-t', 'g@example.com', 'uid', 'pk', null, [], 'T');
        \passkey\touch('cred-t', 42);

        $row = \passkey\byCredentialId('cred-t');
        self::assertSame(42, (int)$row['sign_count']);
        self::assertNotNull($row['last_used_at']);
    }

    public function testLabelForKnownAaguid(): void
    {
        self::assertSame('iCloud Keychain', \passkey\labelFor('dd4ec289e01d41c9bb8970fa845d4bf2'));
    }

    public function testLabelForUnknownOrZeroAaguidFallsBackToDate(): void
    {
        self::assertStringStartsWith('Klucz z ', \passkey\labelFor('00000000000000000000000000000000'));
        self::assertStringStartsWith('Klucz z ', \passkey\labelFor(null));
        self::assertStringStartsWith('Klucz z ', \passkey\labelFor('ffffffffffffffffffffffffffffffff'));
    }
}

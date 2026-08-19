<?php

namespace UprzejmieDonosze\Tests\Passkey;

require_once __DIR__ . '/../../export/inc/passkey/WebAuthnFactory.php';

use lbuchs\WebAuthn\Binary\ByteBuffer;
use UprzejmieDonosze\Tests\DatabaseTestCase;

class ChallengeTest extends DatabaseTestCase
{
    public function testRoundTrip(): void
    {
        $webAuthn = \passkey\webAuthn();
        // Force a challenge to exist by requesting create args.
        $webAuthn->getCreateArgs('uid', 'a@example.com', 'A', 30, true, true);
        \passkey\storeChallenge($webAuthn, 'create');

        $challenge = \passkey\takeChallenge('create');
        self::assertInstanceOf(ByteBuffer::class, $challenge);
        self::assertSame(
            $webAuthn->getChallenge()->getBinaryString(),
            $challenge->getBinaryString()
        );
    }

    public function testSingleUse(): void
    {
        $webAuthn = \passkey\webAuthn();
        $webAuthn->getGetArgs([], 30);
        \passkey\storeChallenge($webAuthn, 'get');

        \passkey\takeChallenge('get');

        $this->expectExceptionMessage('Sesja logowania wygasła, spróbuj ponownie');
        \passkey\takeChallenge('get');
    }

    public function testRejectsMissingChallenge(): void
    {
        unset($_SESSION['passkey_challenge'], $_SESSION['passkey_challenge_type'], $_SESSION['passkey_challenge_exp']);
        $this->expectExceptionMessage('Sesja logowania wygasła, spróbuj ponownie');
        \passkey\takeChallenge('get');
    }

    public function testRejectsWrongType(): void
    {
        $webAuthn = \passkey\webAuthn();
        $webAuthn->getCreateArgs('uid', 'a@example.com', 'A', 30);
        \passkey\storeChallenge($webAuthn, 'create');

        $this->expectExceptionMessage('Sesja logowania wygasła, spróbuj ponownie');
        \passkey\takeChallenge('get');
    }

    public function testRejectsExpiredChallenge(): void
    {
        $webAuthn = \passkey\webAuthn();
        $webAuthn->getGetArgs([], 30);
        \passkey\storeChallenge($webAuthn, 'get');
        $_SESSION['passkey_challenge_exp'] = time() - 1;

        $this->expectExceptionMessage('Sesja logowania wygasła, spróbuj ponownie');
        \passkey\takeChallenge('get');
    }
}

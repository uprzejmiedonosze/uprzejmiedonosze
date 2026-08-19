<?php

namespace UprzejmieDonosze\Tests\Passkey;

require_once __DIR__ . '/../../export/inc/passkey/WebAuthnFactory.php';

use UprzejmieDonosze\Tests\DatabaseTestCase;

class WebAuthnFactoryTest extends DatabaseTestCase
{
    public function testRpIdFollowsHost(): void
    {
        self::assertSame(HOST, \passkey\rpId());
    }

    public function testCreateArgsRequireResidentKeyAndUserVerification(): void
    {
        $webAuthn = \passkey\webAuthn();
        $args = $webAuthn->getCreateArgs('user-handle-bytes', 'a@example.com', 'A', 60, true, true);

        self::assertSame(HOST, $args->publicKey->rp->id);
        self::assertSame('required', $args->publicKey->authenticatorSelection->userVerification);
        self::assertTrue($args->publicKey->authenticatorSelection->requireResidentKey);
        self::assertSame('required', $args->publicKey->authenticatorSelection->residentKey);
    }

    public function testGetArgsWithoutAllowCredentialsIsUsernameless(): void
    {
        $webAuthn = \passkey\webAuthn();
        $args = $webAuthn->getGetArgs([], 60, true, true, true, true, true, true);

        self::assertFalse(property_exists($args->publicKey, 'allowCredentials'));
        self::assertSame('required', $args->publicKey->userVerification);
        self::assertSame(HOST, $args->publicKey->rpId);
    }

    /**
     * Regression test: the browser (src/js/lib/webauthn.js bufToB64u) sends
     * base64URL ('-'/'_', no padding). Plain base64_decode() expects the
     * standard '+'/'/' alphabet and silently corrupts anything containing
     * '-'/'_' instead of erroring — which surfaced in the field as lbuchs
     * throwing "ByteBuffer: Invalid offset or length" while parsing the
     * corrupted attestation/assertion bytes.
     */
    public function testB64uDecodeHandlesUrlSafeAlphabetAndMissingPadding(): void
    {
        // Fixed bytes whose standard base64 form contains both '+' and '/'
        // (so the base64url form contains '-' and '_'), and whose length
        // isn't a multiple of 4 (exercises the padding restoration).
        $raw = base64_decode('/vv+Pj7//qA=');
        $standard = base64_encode($raw);
        self::assertTrue(str_contains($standard, '+') && str_contains($standard, '/'), 'fixture must exercise +/- translation');

        $urlSafe = rtrim(strtr($standard, '+/', '-_'), '=');
        self::assertSame($raw, \passkey\b64uDecode($urlSafe));
    }

    public function testB64uDecodeMatchesJsBufToB64uOutputFormat(): void
    {
        // A concrete fixture pinned to a known base64url string (as produced
        // by bufToB64u in src/js/lib/webauthn.js), not just a round-trip.
        self::assertSame('hello world!!', \passkey\b64uDecode('aGVsbG8gd29ybGQhIQ'));
    }
}

<?PHP namespace passkey;

use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;

const CHALLENGE_TTL_SECONDS = 120;

/** RP ID must be a bare host: localhost / staging.uprzejmiedonosze.net / uprzejmiedonosze.net. */
function rpId(): string {
    return HOST;
}

/**
 * A fresh WebAuthn instance per call — it carries no state we need across
 * requests (challenges are generated inside getCreateArgs()/getGetArgs() and
 * captured immediately via getChallenge()). No attestation formats beyond
 * 'none': we don't validate device attestation (no MDS root certs shipped),
 * only that the assertion/registration is cryptographically sound.
 */
function webAuthn(): WebAuthn {
    $webAuthn = new WebAuthn('Uprzejmie Donoszę', rpId(), ['none'], true);
    // Native Android app (Credential Manager) sends origin `android:apk-key-hash:<base64url SHA-256 of the
    // signing cert>`; without a whitelist the library rejects it. Comma-separated, per host (prod: Play app
    // signing key, shadow: EAS internal key). iOS uses https://<rpId> and needs no entry.
    $hashes = array_filter(array_map('trim', explode(',', getenv('PASSKEY_ANDROID_KEY_HASHES') ?: '')));
    if ($hashes) $webAuthn->addAndroidKeyHashes(array_values($hashes));
    return $webAuthn;
}

/**
 * Decodes a base64url string (as sent by the browser via
 * src/js/lib/webauthn.js's bufToB64u — '-'/'_', no padding). Plain
 * base64_decode() expects the standard '+'/'/' alphabet and silently
 * corrupts anything containing '-'/'_', which is fatal here: lbuchs parses
 * these as binary CBOR/authenticator-data structures.
 */
function b64uDecode(string $value): string {
    $base64 = strtr($value, '-_', '+/');
    $padded = str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '=');
    return base64_decode($padded);
}

/** Single-use challenge, tagged so a registration challenge can't be replayed at login or vice versa. */
function storeChallenge(WebAuthn $webAuthn, string $type): void {
    $_SESSION['passkey_challenge'] = base64_encode($webAuthn->getChallenge()->getBinaryString());
    $_SESSION['passkey_challenge_type'] = $type;
    $_SESSION['passkey_challenge_exp'] = time() + CHALLENGE_TTL_SECONDS;
}

/** @SuppressWarnings(PHPMD.Superglobals) */
function takeChallenge(string $type): ByteBuffer {
    $raw = $_SESSION['passkey_challenge'] ?? null;
    $got = $_SESSION['passkey_challenge_type'] ?? null;
    $exp = $_SESSION['passkey_challenge_exp'] ?? 0;
    unset($_SESSION['passkey_challenge'], $_SESSION['passkey_challenge_type'], $_SESSION['passkey_challenge_exp']);

    if (!$raw || $got !== $type || $exp < time()) {
        throw new \Exception('Sesja logowania wygasła, spróbuj ponownie', 400);
    }
    return new ByteBuffer(base64_decode($raw));
}

/**
 * Challenge store for the native app (REST routes have no PHP session/cookie): moves the challenge that
 * storeChallenge() put in $_SESSION into Memcached under a random `state`, which the client echoes back to
 * *-verify. Same TTL, single-use, same type tag.
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function exportChallenge(): string {
    $state = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    \cache\set(\cache\Type::Passkey, 'state-' . $state, [
        'raw' => $_SESSION['passkey_challenge'] ?? null,
        'type' => $_SESSION['passkey_challenge_type'] ?? null,
        'exp' => $_SESSION['passkey_challenge_exp'] ?? 0,
        'email' => $_SESSION['user_email'] ?? null,
    ], 0, CHALLENGE_TTL_SECONDS);
    unset($_SESSION['passkey_challenge'], $_SESSION['passkey_challenge_type'], $_SESSION['passkey_challenge_exp']);
    return $state;
}

/**
 * Inverse of exportChallenge(): restores the challenge into $_SESSION so takeChallenge() works unchanged.
 * Single-use (deleted on read). For registration the challenge must belong to the same e-mail.
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function importChallenge(string $state): void {
    $key = 'state-' . $state;
    $data = \cache\get(\cache\Type::Passkey, $key);
    \cache\delete(\cache\Type::Passkey, $key);
    if (!is_array($data) || ($data['email'] ?? null) !== ($_SESSION['user_email'] ?? null)) {
        throw new \Exception('Sesja logowania wygasła, spróbuj ponownie', 400);
    }
    $_SESSION['passkey_challenge'] = $data['raw'];
    $_SESSION['passkey_challenge_type'] = $data['type'];
    $_SESSION['passkey_challenge_exp'] = $data['exp'];
}

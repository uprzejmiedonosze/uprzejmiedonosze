<?PHP namespace passkey;

use Firebase\JWT\JWT;
use Kreait\Firebase\Factory;

function serviceAccountPath(): string {
    return __DIR__ . '/../../' . HOST . '-firebase-adminsdk.json';
}

function hasServiceAccount(): bool {
    return is_file(serviceAccountPath());
}

function usingEmulator(): bool {
    return !empty(getenv('FIREBASE_AUTH_EMULATOR_HOST'));
}

/**
 * Resolves the uid a custom token must be minted for.
 *
 * NEVER use $_SESSION['user_id']: in dev AuthMiddleware pins it to the
 * literal 'dev-user' (not a real emulator uid). Signing a custom token for
 * 'dev-user' would silently create a brand-new, email-less emulator account,
 * and AuthMiddleware's emulator branch rejects tokens without an email claim
 * ('Invalid emulator token') — so passkey login would break in dev.
 *
 * kreait honours FIREBASE_AUTH_EMULATOR_HOST, so getUserByEmail() resolves
 * against the emulator in dev and the real project in staging/prod. Falls
 * back to the uid captured at registration time (passkeys.user_id) if the
 * live lookup fails, so a transient Firebase outage doesn't lock users out.
 */
function resolveUid(string $email, ?string $fallbackUid = null): string {
    if (hasServiceAccount()) {
        try {
            $auth = (new Factory)->withServiceAccount(serviceAccountPath())->createAuth();
            return $auth->getUserByEmail($email)->uid;
        } catch (\Throwable $e) {
            logger("passkey: resolveUid($email) lookup failed: {$e->getMessage()}");
        }
    }
    if ($fallbackUid) {
        return $fallbackUid;
    }
    throw new \Exception("Nie udało się zweryfikować konta $email", 500);
}

/**
 * Mints a Firebase custom token for $uid. This is the ONLY thing a passkey
 * login does with Firebase: the browser exchanges the token via
 * signInWithCustomToken() for a normal ID token and posts that to the
 * existing /api/verify-token, which stays the sole session-creating endpoint.
 */
function customToken(string $uid, array $claims = []): string {
    if (hasServiceAccount()) {
        $auth = (new Factory)->withServiceAccount(serviceAccountPath())->createAuth();
        return $auth->createCustomToken($uid, $claims, 300)->toString();
    }

    if (!usingEmulator()) {
        throw new \Exception('Logowanie passkeyem jest chwilowo niedostępne', 500);
    }

    // Dev fallback for contributors without the (gitignored) service-account
    // file: the Auth emulator does not verify custom-token signatures, so a
    // well-formed token with a throwaway key is enough to sign in locally.
    $now = time();
    $payload = array_merge($claims, [
        'iss' => 'passkey-dev@example.iam.gserviceaccount.com',
        'sub' => 'passkey-dev@example.iam.gserviceaccount.com',
        'aud' => 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
        'iat' => $now,
        'exp' => $now + 300,
        'uid' => $uid,
    ]);
    return JWT::encode($payload, 'dev-emulator-unsigned-key-not-used-for-real-signing', 'HS256');
}

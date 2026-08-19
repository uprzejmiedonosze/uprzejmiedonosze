<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../passkey/WebAuthnFactory.php');
require_once(__DIR__ . '/../passkey/FirebaseToken.php');

use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthnException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpTooManyRequestsException;

/**
 * Passkey (WebAuthn) login — an additional method for EXISTING accounts.
 * Registration requires a session (LoggedInMiddleware); login is anonymous
 * and, on a verified assertion, returns a Firebase custom token. The browser
 * exchanges that for an ID token and posts it to the existing
 * /api/verify-token, which stays the only endpoint that creates a session.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
class PasskeyHandler extends AbstractHandler {

    private function requireCurrentHost(Request $request): void {
        $host = $request->getUri()->getHost();
        if ($host !== \passkey\rpId()) {
            throw new HttpForbiddenException($request, 'Nieprawidłowy host');
        }
    }

    // ---- Registration (session required) ----------------------------------

    public function registerOptions(Request $request, Response $response): Response {
        $this->requireCurrentHost($request);
        $email = $_SESSION['user_email'];

        if (\passkey\countForEmail($email) >= \passkey\MAX_PER_USER) {
            throw new \Exception('Osiągnięto limit passkeyów dla tego konta', 400);
        }

        $webAuthn = \passkey\webAuthn();
        $existing = array_map(
            fn($row) => ByteBuffer::fromBase64Url($row['credential_id']),
            \passkey\forEmail($email)
        );

        $args = $webAuthn->getCreateArgs(
            ByteBuffer::fromBase64Url(\passkey\userHandle($email))->getBinaryString(),
            $email,
            $_SESSION['user_name'] ?: $email,
            60,
            true,   // requireResidentKey -> discoverable credential (usernameless login)
            true,   // requireUserVerification
            null,   // allow both platform and roaming authenticators
            $existing
        );
        \passkey\storeChallenge($webAuthn, 'create');

        return $this->renderJson($response, $args);
    }

    public function registerVerify(Request $request, Response $response): Response {
        $this->requireCurrentHost($request);
        $email = $_SESSION['user_email'];
        $body = (array)$request->getParsedBody();

        if (!\cache\throttle\attempt(\cache\Type::Passkey, 'register-' . $email, 10, 3600)) {
            throw new HttpTooManyRequestsException($request, 'Zbyt wiele prób, spróbuj później');
        }

        $challenge = \passkey\takeChallenge('create');

        try {
            $data = \passkey\webAuthn()->processCreate(
                \passkey\b64uDecode($this->getParam($body, 'clientDataJSON')),
                \passkey\b64uDecode($this->getParam($body, 'attestationObject')),
                $challenge,
                true,  // requireUserVerification
                true   // requireUserPresent
            );
        } catch (WebAuthnException $e) {
            \telemetry\log('passkey_register_failed');
            throw new \Exception('Nie udało się dodać passkeya: ' . $e->getMessage(), 400);
        }

        $uid = $_SESSION['user_id'];
        $credentialId = rtrim(strtr(base64_encode($data->credentialId), '+/', '-_'), '=');
        $aaguid = bin2hex((string)$data->AAGUID);
        $transports = $this->getParam($body, 'transports', []);
        $label = \passkey\labelFor($aaguid);

        \passkey\add($credentialId, $email, $uid, $data->credentialPublicKey, $aaguid, $transports, $label);
        \telemetry\log('passkey_registered');

        return $this->renderJson($response, ['passkeys' => \passkey\forEmail($email)]);
    }

    public function listPasskeys(Request $request, Response $response): Response {
        return $this->renderJson($response, ['passkeys' => \passkey\forEmail($_SESSION['user_email'])]);
    }

    public function remove(Request $request, Response $response, $args): Response {
        $removed = \passkey\remove($args['credentialId'], $_SESSION['user_email']);
        if (!$removed) {
            throw new \Slim\Exception\HttpNotFoundException($request, 'Nie znaleziono passkeya');
        }
        \telemetry\log('passkey_removed');
        return $this->renderJson($response, ['status' => 'OK']);
    }

    // ---- Login (anonymous) -------------------------------------------------

    public function loginOptions(Request $request, Response $response): Response {
        $this->requireCurrentHost($request);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!\cache\throttle\attempt(\cache\Type::Passkey, 'login-opts-' . $ip, 30, 300)) {
            throw new HttpTooManyRequestsException($request, 'Zbyt wiele prób, spróbuj później');
        }

        $webAuthn = \passkey\webAuthn();
        // Empty allowCredentials -> discoverable/usernameless login: we never
        // reveal whether a given account has a passkey registered.
        $args = $webAuthn->getGetArgs([], 60, true, true, true, true, true, true);
        \passkey\storeChallenge($webAuthn, 'get');

        return $this->renderJson($response, $args);
    }

    public function loginVerify(Request $request, Response $response): Response {
        $this->requireCurrentHost($request);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!\cache\throttle\attempt(\cache\Type::Passkey, 'login-ip-' . $ip, 15, 300)) {
            throw new HttpTooManyRequestsException($request, 'Zbyt wiele prób, spróbuj później');
        }

        $body = (array)$request->getParsedBody();
        $credentialId = $this->getParam($body, 'id');
        $row = \passkey\byCredentialId($credentialId);

        $challenge = \passkey\takeChallenge('get');

        if (!$row) {
            // Generic message: don't reveal whether the credential exists.
            \telemetry\log('passkey_login_failed', null, ['status' => 'unknown_credential']);
            throw new \Exception('Logowanie passkeyem nie powiodło się', 400);
        }

        $webAuthn = \passkey\webAuthn();
        try {
            $webAuthn->processGet(
                \passkey\b64uDecode($this->getParam($body, 'clientDataJSON')),
                \passkey\b64uDecode($this->getParam($body, 'authenticatorData')),
                \passkey\b64uDecode($this->getParam($body, 'signature')),
                $row['public_key'],
                $challenge,
                (int)$row['sign_count'],
                true, // requireUserVerification
                true  // requireUserPresent
            );
        } catch (WebAuthnException $e) {
            $isCounterRegression = str_contains($e->getMessage(), 'signature counter');
            \telemetry\log('passkey_login_failed', null, [
                'status' => $isCounterRegression ? 'counter_regression' : 'assertion_invalid',
            ]);
            if ($isCounterRegression) {
                // Possible cloned authenticator: drop the credential rather
                // than let it keep trying.
                \passkey\remove($credentialId, $row['user_email']);
                if (isProd()) \Sentry\captureMessage("passkey signature counter regression: $credentialId");
            }
            throw new \Exception('Logowanie passkeyem nie powiodło się', 400);
        }

        $signCount = $webAuthn->getSignatureCounter() ?? $row['sign_count'];
        \passkey\touch($credentialId, (int)$signCount);

        $uid = \passkey\resolveUid($row['user_email'], $row['user_id']);
        $token = \passkey\customToken($uid);

        \telemetry\log('passkey_login_ok');
        return $this->renderJson($response, ['customToken' => $token]);
    }
}

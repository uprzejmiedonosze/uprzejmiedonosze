<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../UserRemoval.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class UserHandler extends AbstractHandler {
    public function register(Request $request, Response $response): Response {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $deleteCsrf = null;
        if ($user->isRegistered()) {
            $deleteCsrf = bin2hex(random_bytes(16));
            $_SESSION['delete_account_csrf'] = $deleteCsrf;
        }

        return AbstractHandler::renderHtml($request, $response, 'register', [
            'signInSuccessUrl' => $this->getParam($params, 'next', '/app/start'),
            // Nowa rejestracja idzie w trybie „focus” (onboarding bez menu),
            // a edycja istniejącego konta (link „Edycja konta” niesie ?update)
            // w normalnym trybie „app”.
            'update' => isset($params['update']),
            'user' => $user,
            'passkeys' => $user->isRegistered() ? \passkey\forEmail($user->getEmail()) : [],
            'deleteCsrf' => $deleteCsrf,
            'deleteError' => $this->getParam($params, 'error', '') === 'email'
        ]);
    }

    public function finish(Request $request): Response {
        $params = (array)$request->getParsedBody();
        $signInSuccessUrl = $this->getParam($params, 'next', '/app/start');
        $name = capitalizeName($this->getParam($params, 'name'));

        $address = $this->getParam($params, 'address');
        $address = str_replace(', Polska', '', cleanWhiteChars($address));

        $msisdn = $this->getParam($params, 'msisdn', '');
        $edelivery = $this->getParam($params, 'edelivery', '');

        // Field no longer present in the registration/edit-profile form (the only
        // place to set this is the ad hoc toggle on the new-application screen) —
        // pass null so updateUserData() leaves any previously saved preference untouched.
        $stopAgresjiRaw = $params['stopAgresji'] ?? null;
        $stopAgresji = $stopAgresjiRaw === null ? null : ($stopAgresjiRaw === 'SA');
        $shareRecydywa=$this->getParam($params, 'shareRecydywa', 'Y') == 'Y';

        /** @var \user\User $user */
        $user = $request->getAttribute('user');
        $user->updateUserData($name, $msisdn, $address, $edelivery, $stopAgresji, $shareRecydywa);
        \user\save($user);

        return AbstractHandler::redirect($signInSuccessUrl);
    }

    /**
     * Self-service "Skasuj konto": reuses \admin\removeUser() (the same code the
     * retention cron uses on inactive accounts) and the shared farewell-email copy,
     * confirmed by the user retyping their own e-mail address.
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function deleteAccount(Request $request, Response $response): Response {
        $body = (array) $request->getParsedBody();
        if (empty($_SESSION['delete_account_csrf'])
            || !hash_equals($_SESSION['delete_account_csrf'], (string) ($body['csrf'] ?? ''))) {
            throw new HttpForbiddenException($request, 'Invalid CSRF token');
        }
        unset($_SESSION['delete_account_csrf']);

        /** @var \user\User $user */
        $user = $request->getAttribute('user');
        $email = $user->getEmail();

        $typedEmail = mb_strtolower(trim((string) ($body['email'] ?? '')));
        if ($typedEmail !== mb_strtolower($email)) {
            return AbstractHandler::redirect('/app/account?update&error=email');
        }

        \admin\removeUser($email, dryRun: false);
        \admin\farewellEmail($user, selfService: true, dryRun: false);
        \telemetry\log('user_self_deleted');

        // Same session teardown as /logout.html — the account (and its session) is gone.
        unset($_SESSION['token']);
        unset($_SESSION['user_id']);
        unset($_SESSION['user_email']);
        session_regenerate_id(true);

        return AbstractHandler::redirect('/konto-usuniete.html');
    }
}

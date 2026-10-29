<?PHP namespace demo;

/**
 * Konto demo dla recenzentów sklepów (Google Play / App Store). Recenzent nie odbierze maila z linkiem logowania,
 * a na prodzie każde zgłoszenie idzie naprawdę do SM/policji – dlatego konto demo żyje WYŁĄCZNIE na stagingu
 * (MailGun::send() poza prodem wysyła zgłoszenie tylko do administratora).
 *
 * Przepływ (appka mobilna, src/lib/demoLogin.ts w uprzejmiedonosze-pro):
 *  1. prod:    POST /api/rest/demo-login {email}       → {env: "staging"}, gdy to DEMO_LOGIN_EMAIL. Kodu prod nie zna.
 *  2. appka przełącza się na staging (restart) i pyta o kod.
 *  3. staging: POST /api/rest/demo-login {email, code} → {customToken} (jak passkey: identitytoolkit → /api/verify-token).
 * prod: inny e-mail → 200 {}. staging: nie demo / brak konfiguracji → 404, zły kod → 403.
 */

const NOT_DEMO = 'not_demo';
const BAD_CODE = 'bad_code';
const SWITCH_TO_STAGING = 'switch';
const LOGIN = 'login';

/** Czysta decyzja – testowalna bez Firebase i bez env. */
function decide(string $email, string $code, string $demoEmail, string $demoCode, bool $prod): string {
    if ($demoEmail === '' || strcasecmp(trim($email), $demoEmail) !== 0) return NOT_DEMO;
    if ($prod) return SWITCH_TO_STAGING;
    if ($demoCode === '' || !hash_equals($demoCode, trim($code))) return BAD_CODE;
    return LOGIN;
}

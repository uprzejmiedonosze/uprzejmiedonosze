<?PHP
require_once(__DIR__ . '/dataclasses/ConfigClass.php');
require_once(__DIR__ . '/store/JsonStore.php');

const LATEST_TERMS_UPDATE = '2024-03-26';

const DT_FORMAT = 'Y-m-d\TH:i:s';
const DT_FORMAT_SHORT = 'Y-m-d\TH:i';
const DT_FORMAT_LONG = 'Y-m-d\TH:i:s.u';

/**
 * Wersja kontraktu REST z aplikacją mobilną (= API_SCHEMA w ../uprzejmiedonosze-pro/src/lib/apiSchema.ts); podbij przy każdej
 * zmianie kontraktu (endpointy, pola, prompt i walidacja Vision). Klient wysyła `X-UD-Schema`, a serwer:
 *  - odrzuca (426) klienta o schemacie NIŻSZYM (kompatybilność tylko „w dół”) – podbij dopiero PO buildzie z nowym schematem
 *    w sklepach (API.md);
 *  - w każdej odpowiedzi /api/rest/* zwraca `X-UD-Server-Schema`, żeby nowsza appka ze starszym backendem chowała to, czego
 *    ten backend jeszcze nie umie (np. film w zgłoszeniu od 11).
 * Do 11 nazywała się VISION_SCHEMA (src/inc/integrations/VisionSchema.php).
 */
const API_SCHEMA = 11;
// SCHEMA 11: film w zgłoszeniu (POST/DELETE /api/rest/app/{id}/video), kategorie `requiresVideo` (hałas, drift), bez flag `beta`
// i `features.adHocReports`; nagłówek X-UD-Server-Schema w odpowiedziach.
// SCHEMA 10: POST /api/rest/demo-login (konto demo recenzentów, src/inc/demoLogin.php); kształt odpowiedzi bez zmian
// względem 9. Wyjątkowo podbite PRZED buildem w sklepie: odcina (426) testowe buildy staging ≤ 1.1.7 przed premierą prod;
// w sklepie nie ma jeszcze wydania prod, które by to zablokowało.
// SCHEMA 9: limit unikalnych przetworzonych zdjęć na użytkownika (store/PhotoQuota.php) — nowy kontrakt REST: HTTP 402
// z `quota` na POST /photos/{id}/alpr, /app/{id}/image (photoId) i /vision/candidate oraz `photoQuota` w GET /user/dashboard i
// /photos/{id}/alpr (kształt odpowiedzi vision/candidate bez zmian względem 8).
// SCHEMA 8: ALPR na każdym zdjęciu poza 'unusable' + pole alpr_all (wszystkie odczyty) — appka
// przydziela role context/car/third deterministycznie, patrz alprDetections() w Vision.php.

$CATEGORIES = \json\get('categories.json', 'Category');
$CATEGORY_GROUPS = \json\get('category-groups.json');
$EXTENSIONS = \json\get('extensions.json', 'Extension');
$SM_ADDRESSES = \json\get('sm.json', 'SM');
$POLICE_ADDRESSES = \json\get('police.json', 'Police');
$STATUSES = \json\get('statuses.json', 'Status');
$STOP_AGRESJI = \json\get('stop-agresji.json', 'StopAgresji');
$LEVELS = \json\get('levels.json', 'Level');
$BADGES = \json\get('badges.json');

// Footer link columns, also reused to build each static page's "Przeczytaj
// także" sidebar (see _related-links.html.twig). 'key' must match the page's
// own explicit `current` value passed to that include; external links are
// shown in the footer but skipped from "Przeczytaj także".
$FOOTER_LINKS = [
    'know' => [
        'title' => 'Warto wiedzieć',
        'items' => [
            ['key' => 'jak-zglosic-nielegalne-parkowanie', 'href' => '/jak-zglosic-nielegalne-parkowanie.html', 'label' => 'Poradnik „jak zgłaszać”'],
            ['key' => 'czesto-popelniane-bledy', 'href' => '/czesto-popelniane-bledy.html', 'label' => 'Często popełniane błędy'],
            ['key' => 'faq', 'href' => '/faq.html', 'label' => 'Najczęstsze pytania'],
            ['key' => null, 'href' => '/wiki/prawo/przepisy-i-kategorie', 'label' => 'Przepisy zw. z parkowaniem'],
            ['key' => 'przesluchanie', 'href' => '/przesluchanie.html', 'label' => 'Wizyta na komisariacie'],
            ['key' => null, 'href' => '/wiki/zwrot-za-przesluchanie', 'label' => 'Zwrot środków za przesłuchanie'],
            ['key' => null, 'href' => '/wiki/zazalenie-na-decyzje-sm', 'label' => 'Zażalenie na brak mandatu'],
            ['key' => 'dostep-do-informacji-publicznej', 'href' => '/dostep-do-informacji-publicznej.html', 'label' => 'Jak sprawdzić efekty pracy SM?'],
            ['key' => null, 'href' => '/wiki/wysylka-e-doreczenia', 'label' => 'e-Doręczenia'],
            ['key' => null, 'href' => '/wiki/wysylka-epuap', 'label' => 'Wysyłka przez ePUAP'],
            ['key' => 'jak-zlozyc-wniosek-o-slupki', 'href' => '/jak-zlozyc-wniosek-o-slupki.html', 'label' => 'Wniosek o słupki'],
        ],
    ],
    'support' => [
        'title' => 'Warto wspierać',
        'items' => [
            ['key' => 'napisz-pismo-do-polityka', 'href' => '/napisz-pismo-do-polityka.html', 'label' => 'Napisz pismo do polityka'],
            ['key' => 'patronite', 'href' => '/patronite.html', 'label' => 'Patronite'],
            ['key' => null, 'href' => 'https://agendaparkingowa.pl/', 'label' => 'Agenda Parkingowa', 'external' => true],
            ['key' => null, 'href' => 'https://www.change.org/Rowne-Prawa-Dla-Pieszych-i-Kierowcow', 'label' => 'Podpisz wniosek do RPO', 'external' => true],
            ['key' => 'wniosek-rpo', 'href' => '/wniosek-rpo.html', 'label' => 'Wniosek do RPO'],
            ['key' => null, 'href' => 'https://www.facebook.com/groups/patologiaparkingowa/', 'label' => 'Grupa wsparcia na FB', 'external' => true],
            ['key' => null, 'href' => 'https://suppi.pl/uprzejmiedonosze', 'label' => 'Jednorazowa wpłata', 'external' => true],
            ['key' => 'naklejki-robisz-to-zle', 'href' => '/naklejki-robisz-to-zle.html', 'label' => 'Kup naklejki'],
            ['key' => 'robtodobrze', 'href' => '/robtodobrze.html', 'label' => 'Rób to dobrze'],
            ['key' => 'zenfeed', 'href' => '/zenfeed.html', 'label' => 'ZenFeed — nasz drugi projekt'],
        ],
    ],
    'project' => [
        'title' => 'O projekcie',
        'items' => [
            ['key' => 'aplikacja', 'href' => '/aplikacja.html', 'label' => 'Aplikacja mobilna'],
            ['key' => 'statystyki', 'href' => '/statystyki.html', 'label' => 'Statystyki'],
            ['key' => 'galeria', 'href' => '/galeria.html', 'label' => 'Galeria recydywistów'],
            ['key' => null, 'href' => 'https://patronite.pl/uprzejmiedonosze/posts', 'label' => 'Comiesięczna aktualizacja', 'external' => true],
            ['key' => null, 'href' => 'https://x.com/SzymonNieradka', 'label' => 'Codzienne aktualizacje', 'external' => true],
            ['key' => 'mcp', 'href' => '/mcp', 'label' => 'Asystent AI/MCP'],
            ['key' => 'changelog', 'href' => '/changelog.html', 'label' => 'Historia zmian'],
            ['key' => 'projekt', 'href' => '/projekt.html', 'label' => 'Dla programistów'],
            ['key' => 'regulamin', 'href' => '/regulamin.html', 'label' => 'Regulamin'],
            ['key' => 'polityka-prywatnosci', 'href' => '/polityka-prywatnosci.html', 'label' => 'Polityka prywatności'],
            ['key' => 'bezpieczenstwo', 'href' => '/bezpieczenstwo.html', 'label' => 'Bezpieczeństwo'],
            ['key' => 'zrodla', 'href' => '/zrodla.html', 'label' => 'Źródła danych'],
            ['key' => 'kontakt', 'href' => '/kontakt.html', 'label' => 'Kontakt'],
        ],
    ],
];

$_appEnv = getenv('APP_ENV');
$_configDev = __DIR__ . '/../config.dev.php';
// Dev bootstrap: config.dev.php defines fixture keys, Mailpit DSN, etc.
// getenv() below only fills constants the file did not set (cannot override defines).
// Opt-in on APP_ENV=dev only — an unset APP_ENV must never pull in dev config.
if ($_appEnv === 'dev' && is_file($_configDev)) {
    require $_configDev;
}

if (!defined('SMTP_HOST'))    define('SMTP_HOST',    getenv('SMTP_HOST')    ?: '');
if (!defined('SMTP_USER'))    define('SMTP_USER',    getenv('SMTP_USER')    ?: '');
if (!defined('EMAIL_SENDER')) define('EMAIL_SENDER', getenv('EMAIL_SENDER') ?: '');
if (!defined('SMTP_PASS'))    define('SMTP_PASS',    getenv('SMTP_PASS')    ?: '');
if (!defined('SMTP_GMAIL'))   define('SMTP_GMAIL',   getenv('SMTP_GMAIL')   ?: '');

if (!defined('PLATERECOGNIZER_SECRET')) define('PLATERECOGNIZER_SECRET', getenv('PLATERECOGNIZER_SECRET') ?: '');
if (!defined('OPEN_ALPR_SECRET_1'))     define('OPEN_ALPR_SECRET_1',     getenv('OPEN_ALPR_SECRET_1')     ?: '');
if (!defined('OPEN_ALPR_SECRET_2'))     define('OPEN_ALPR_SECRET_2',     getenv('OPEN_ALPR_SECRET_2')     ?: '');
if (!defined('MAPBOX_API_TOKEN'))       define('MAPBOX_API_TOKEN',       getenv('MAPBOX_API_TOKEN')       ?: '');
if (!defined('GOOGLE_MAPS_API_TOKEN'))  define('GOOGLE_MAPS_API_TOKEN',  getenv('GOOGLE_MAPS_API_TOKEN')  ?: '');
if (!defined('PATRONITE_TOKEN'))        define('PATRONITE_TOKEN',        getenv('PATRONITE_TOKEN')        ?: '');
// API_TOKEN (AppStore::byNumber) and TUMBLR_* (integrations/Tumblr.php)
// were only ever defined in the bare-metal-only config.prod.php (a whole
// parallel secrets file, never in git) — confirmed 2026-09-28. Never
// migrated here, so byNumber() would fatal on an undefined constant the
// moment it's called with a non-empty token (silently never hit so far),
// and addToTumblr() always took its "not set" fallback branch.
if (!defined('API_TOKEN'))              define('API_TOKEN',              getenv('API_TOKEN')              ?: '');
if (!defined('TUMBLR_CONSUMERKEY'))     define('TUMBLR_CONSUMERKEY',     getenv('TUMBLR_CONSUMERKEY')     ?: '');
if (!defined('TUMBLR_CONSUMERSECRET'))  define('TUMBLR_CONSUMERSECRET',  getenv('TUMBLR_CONSUMERSECRET')  ?: '');
if (!defined('TUMBLR_TOKEN'))           define('TUMBLR_TOKEN',           getenv('TUMBLR_TOKEN')           ?: '');
if (!defined('TUMBLR_SECRET'))          define('TUMBLR_SECRET',          getenv('TUMBLR_SECRET')          ?: '');

if (!defined('MAILER_DSN'))            define('MAILER_DSN',            getenv('MAILER_DSN')            ?: '');
if (!defined('MAILER_FROM'))           define('MAILER_FROM',           getenv('MAILER_FROM')           ?: '');
if (!defined('MAILER_WEBHOOK_SECRET')) define('MAILER_WEBHOOK_SECRET', getenv('MAILER_WEBHOOK_SECRET') ?: '');
if (!defined('MAILER_DSN_ALTER'))      define('MAILER_DSN_ALTER',      getenv('MAILER_DSN_ALTER')      ?: '');
if (!defined('MAILER_FROM_ALTER'))     define('MAILER_FROM_ALTER',     getenv('MAILER_FROM_ALTER')     ?: '');

if (!defined('CRYPTO_KEY')) define('CRYPTO_KEY', getenv('CRYPTO_KEY') ?: '');
if (!defined('CRYPTO_IV'))  define('CRYPTO_IV',  getenv('CRYPTO_IV')  ?: '');
if (!defined('CRYPTO_TAG')) define('CRYPTO_TAG', getenv('CRYPTO_TAG') ?: '');

// Stored as a single-line env var with literal \n escapes (real embedded
// newlines aren't reliably preserved across every deploy path — env files,
// shells, secret managers), so decode them back into a real PEM here.
if (!defined('OAUTH_PRIVATE_KEY'))    define('OAUTH_PRIVATE_KEY',    str_replace('\n', "\n", getenv('OAUTH_PRIVATE_KEY') ?: ''));
if (!defined('OAUTH_ENCRYPTION_KEY')) define('OAUTH_ENCRYPTION_KEY', getenv('OAUTH_ENCRYPTION_KEY') ?: '');

if (!defined('OPENAI_API_KEY')) define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
if (!defined('GOOGLE_API_KEY')) define('GOOGLE_API_KEY', getenv('GOOGLE_API_KEY') ?: '');
if (!defined('OPENAI_PROJECT')) define('OPENAI_PROJECT', getenv('OPENAI_PROJECT') ?: '');

// Analiza wizyjna zdjęć (src/inc/integrations/Vision.php) dla mobilnej appki UD Pro —
// multimodalny model OpenAI zamiast klienckiego OpenRouter (klucz nie może żyć w bundlu appki).
// gpt-5-nano + detail:'high' — wyłonione batchową ewaluacją (uprzejmiedonosze-pro/tests/eval,
// patrz tests/results/report.md tamże): 'low' i tak downsample'uje twardo do ~512px, a tańszy
// gpt-5-nano z lepszym promptem (bbox w pikselach, patrz visionPrompt()) bije gpt-4o-mini/low na
// każdej metryce. Tablica/bbox auta odtąd z ALPR (patrz applyAlprPlate() w Vision.php), nie
// z retry-crop LLM — stąd brak tu już OPENAI_VISION_CROP_*.
if (!defined('OPENAI_VISION_MODEL'))            define('OPENAI_VISION_MODEL',            getenv('OPENAI_VISION_MODEL') ?: 'gpt-5-nano');
if (!defined('OPENAI_VISION_MAX_TOKENS'))       define('OPENAI_VISION_MAX_TOKENS',       (int)(getenv('OPENAI_VISION_MAX_TOKENS') ?: 8000));
if (!defined('OPENAI_VISION_TIMEOUT'))          define('OPENAI_VISION_TIMEOUT',          (int)(getenv('OPENAI_VISION_TIMEOUT') ?: 90));
if (!defined('OPENAI_VISION_DETAIL'))           define('OPENAI_VISION_DETAIL',           getenv('OPENAI_VISION_DETAIL') ?: 'high');

// Rozpoznawanie pism z Policji/SM (src/inc/integrations/LetterVision.php): OCR drobnego druku, pieczątek i zdjęć pod kątem
// wymaga mocniejszego modelu niż nano z analizy zdjęć zgłoszeń – do potwierdzenia ewaluacją (tools/letter_eval.php).
if (!defined('OPENAI_LETTER_MODEL'))            define('OPENAI_LETTER_MODEL',            getenv('OPENAI_LETTER_MODEL') ?: 'gpt-5-mini');
if (!defined('OPENAI_LETTER_MAX_TOKENS'))       define('OPENAI_LETTER_MAX_TOKENS',       (int)(getenv('OPENAI_LETTER_MAX_TOKENS') ?: 12000));
if (!defined('LETTER_RATE_MAX'))                define('LETTER_RATE_MAX',                (int)(getenv('LETTER_RATE_MAX') ?: 20));
if (!defined('LETTER_RATE_WINDOW'))             define('LETTER_RATE_WINDOW',             (int)(getenv('LETTER_RATE_WINDOW') ?: 3600));

// Asystent AI na stronie (src/inc/assistant/): czat z bazą wiedzy, aktami prawnymi i zgłoszeniami użytkownika (tool calling).
// Rozmowa jest zapisywana w store.sqlite (store/ChatStore.php); w kontekst LLM trafia ostatnie CHAT_CONTEXT_MESSAGES wiadomości.
if (!defined('OPENAI_CHAT_MODEL'))              define('OPENAI_CHAT_MODEL',              getenv('OPENAI_CHAT_MODEL') ?: 'gpt-5-mini');
if (!defined('OPENAI_CHAT_MAX_TOKENS'))         define('OPENAI_CHAT_MAX_TOKENS',         (int)(getenv('OPENAI_CHAT_MAX_TOKENS') ?: 4000));
if (!defined('OPENAI_CHAT_TIMEOUT'))            define('OPENAI_CHAT_TIMEOUT',            (int)(getenv('OPENAI_CHAT_TIMEOUT') ?: 60));
if (!defined('CHAT_CONTEXT_MESSAGES'))          define('CHAT_CONTEXT_MESSAGES',          (int)(getenv('CHAT_CONTEXT_MESSAGES') ?: 20));
if (!defined('CHAT_MAX_TOOL_ROUNDS'))           define('CHAT_MAX_TOOL_ROUNDS',           (int)(getenv('CHAT_MAX_TOOL_ROUNDS') ?: 4));
if (!defined('CHAT_MAX_MESSAGE_CHARS'))         define('CHAT_MAX_MESSAGE_CHARS',         (int)(getenv('CHAT_MAX_MESSAGE_CHARS') ?: 2000));
// Załączniki w czacie (tylko patroni; src/inc/assistant/Attachments.php): obrazy JPEG/PNG i PDF — każdy plik zużywa kredyt zdjęć jak w appce i MCP
// (\quota\reserve). Body JSON/base64 musi się zmieścić w post_max_size=5M, stąd limity bajtów po dekodowaniu.
if (!defined('CHAT_MAX_ATTACHMENTS'))           define('CHAT_MAX_ATTACHMENTS',           (int)(getenv('CHAT_MAX_ATTACHMENTS') ?: 3));
if (!defined('CHAT_MAX_ATTACHMENT_BYTES'))      define('CHAT_MAX_ATTACHMENT_BYTES',      (int)(getenv('CHAT_MAX_ATTACHMENT_BYTES') ?: 1500000));
if (!defined('CHAT_MAX_ATTACHMENTS_BYTES'))     define('CHAT_MAX_ATTACHMENTS_BYTES',     (int)(getenv('CHAT_MAX_ATTACHMENTS_BYTES') ?: 3000000));
if (!defined('OPENAI_CHAT_IMAGE_DETAIL'))       define('OPENAI_CHAT_IMAGE_DETAIL',       getenv('OPENAI_CHAT_IMAGE_DETAIL') ?: 'auto');
if (!defined('CHAT_RATE_MAX'))                  define('CHAT_RATE_MAX',                  (int)(getenv('CHAT_RATE_MAX') ?: 30));
if (!defined('CHAT_RATE_WINDOW'))               define('CHAT_RATE_WINDOW',               (int)(getenv('CHAT_RATE_WINDOW') ?: 3600));

// Bramka wykrywania twarzy w face-detect-consumer (src/inc/integrations/FaceDetect.php): tylko liczy
// twarze, bboxy do blura daje Google Vision. Osobny klucz ograniczony do Cloud Vision API.
if (!defined('OPENAI_FACES_MODEL'))             define('OPENAI_FACES_MODEL',             getenv('OPENAI_FACES_MODEL') ?: 'gpt-5-nano');
if (!defined('GOOGLE_VISION_API_KEY'))          define('GOOGLE_VISION_API_KEY',          getenv('GOOGLE_VISION_API_KEY') ?: '');

// Etap `wip` (POST /api/rest/photos): limit zdjęć wysyłanych przez jednego użytkownika.
if (!defined('WIP_RATE_MAX'))        define('WIP_RATE_MAX',        (int)(getenv('WIP_RATE_MAX') ?: 60));
if (!defined('WIP_RATE_WINDOW'))     define('WIP_RATE_WINDOW',     (int)(getenv('WIP_RATE_WINDOW') ?: 3600));
// Limit unikalnych przetworzonych zdjęć (ALPR/LLM, bez cache) na użytkownika w kroczącym oknie – patrz store/PhotoQuota.php.
// Bez patronatu: PHOTO_QUOTA_FREE; aktywny patron: najwyższy próg PHOTO_QUOTA_TIERS (zł/mies. => limit, null = bez limitu).
if (!defined('PHOTO_QUOTA_DAYS'))    define('PHOTO_QUOTA_DAYS',    (int)(getenv('PHOTO_QUOTA_DAYS') ?: 30));
if (!defined('PHOTO_QUOTA_FREE'))    define('PHOTO_QUOTA_FREE',    (int)(getenv('PHOTO_QUOTA_FREE') ?: 50));
if (!defined('PHOTO_QUOTA_TIERS'))   define('PHOTO_QUOTA_TIERS',   [50 => null, 25 => 300, 10 => 100]);

// Rate-limit analizy wizyjnej per użytkownik (\cache\throttle\attempt, jak PasskeyHandler).
if (!defined('VISION_RATE_MAX'))     define('VISION_RATE_MAX',     (int)(getenv('VISION_RATE_MAX') ?: 60));
if (!defined('VISION_RATE_WINDOW'))  define('VISION_RATE_WINDOW',  (int)(getenv('VISION_RATE_WINDOW') ?: 3600));
if (!defined('VISION_MAX_PHOTOS'))         define('VISION_MAX_PHOTOS',         (int)(getenv('VISION_MAX_PHOTOS') ?: 12));
// Limity muszą się mieścić w zdjęciach z appki mobilnej: ../uprzejmiedonosze-pro/src/lib/resize.ts (szerokość
// EXPO_PUBLIC_PHOTO_MAX_WIDTH=1600, jakość EXPO_PUBLIC_PHOTO_COMPRESS=0.85). Te dwa limity dotyczą TYLKO starszego wariantu analizy z base64 w body;
// zdjęcia z etapu `wip` (POST /api/rest/photos, analiza po photoId) podlegają MAX_IMAGE_UPLOAD_BYTES w API.php.
if (!defined('VISION_MAX_PHOTO_BYTES'))    define('VISION_MAX_PHOTO_BYTES',    (int)(getenv('VISION_MAX_PHOTO_BYTES') ?: 800000));
if (!defined('VISION_MAX_TOTAL_BYTES'))    define('VISION_MAX_TOTAL_BYTES',    (int)(getenv('VISION_MAX_TOTAL_BYTES') ?: 6000000));

if (!defined('MATOMO_SITE_ID'))     define('MATOMO_SITE_ID',     (int)(getenv('MATOMO_SITE_ID') ?: 2));
if (!defined('BACKEND_API_KEY'))    define('BACKEND_API_KEY',    getenv('BACKEND_API_KEY')    ?: '');
if (!defined('CORS_ALLOWED_DOMAIN')) define('CORS_ALLOWED_DOMAIN', getenv('CORS_ALLOWED_DOMAIN') ?: '');
// Konto demo recenzentów sklepów (src/inc/demoLogin.php): prod zna tylko e-mail (przełącza appkę na staging),
// kod logowania ustawiaj wyłącznie na stagingu. Puste = wyłączone.
if (!defined('DEMO_LOGIN_EMAIL'))   define('DEMO_LOGIN_EMAIL',   getenv('DEMO_LOGIN_EMAIL')   ?: '');
if (!defined('DEMO_LOGIN_CODE'))    define('DEMO_LOGIN_CODE',    getenv('DEMO_LOGIN_CODE')    ?: '');

if (!defined('B2_KEY'))      define('B2_KEY',      getenv('B2_KEY')      ?: '');
if (!defined('B2_SECRET'))   define('B2_SECRET',   getenv('B2_SECRET')   ?: '');
if (!defined('B2_BUCKET'))   define('B2_BUCKET',   getenv('B2_BUCKET')   ?: '');
if (!defined('B2_ENDPOINT')) define('B2_ENDPOINT', getenv('B2_ENDPOINT') ?: '');
if (!defined('B2_REGION'))   define('B2_REGION',   getenv('B2_REGION')   ?: '');
// DB backups go to a separate B2 bucket with its own application key.
if (!defined('BACKUP_B2_KEY'))    define('BACKUP_B2_KEY',    getenv('BACKUP_B2_KEY')    ?: '');
if (!defined('BACKUP_B2_SECRET')) define('BACKUP_B2_SECRET', getenv('BACKUP_B2_SECRET') ?: '');
if (!defined('BACKUP_B2_BUCKET')) define('BACKUP_B2_BUCKET', getenv('BACKUP_B2_BUCKET') ?: '');
// Public age (X25519) recipient for encrypting DB backups. Private key stays offline.
if (!defined('AGE_RECIPIENT'))    define('AGE_RECIPIENT',    getenv('AGE_RECIPIENT')    ?: '');
unset($_appEnv, $_configDev);

// HOST/HTTPS from env win (Docker sets APP_HOST per environment). Asset hashes
// always come from the build (config.env.php): without them /js/index3.js?{hash}
// never changes and browsers keep stale JS/CSS for good (nginx: expires max).
$_appHost = getenv('APP_HOST');
if ($_appHost) {
    define('HOST',      $_appHost);
    define('HTTPS',     getenv('APP_HTTPS') ?: 'https');
}
if (is_file(__DIR__ . '/../config.env.php'))
    require(__DIR__ . '/../config.env.php'); // defines only what is not defined yet
foreach (['HOST' => 'uprzejmiedonosze.net', 'HTTPS' => 'https', 'TWIG_HASH' => '', 'CSS_HASH' => '', 'JS_HASH' => ''] as $_name => $_default)
    if (!defined($_name)) define($_name, $_default);
unset($_name, $_default);
unset($_appHost);
define('ROOT',     getenv('APP_ROOT') ?: '/var/www/' . HOST . '/');
define('BASE_URL', HTTPS . '://' . HOST . '/');

const ODDZIALY_TERENOWE = array(
    'Śródmieście' => 'warszawa_ot1',

    'Mokotów' => 'warszawa_ot2',
    'Wilanów' => 'warszawa_ot2',
    'Ursynów' => 'warszawa_ot2',

    'Ochota' => 'warszawa_ot3',
    'Ursus' => 'warszawa_ot3',
    'Włochy' => 'warszawa_ot3',

    'Wola' => 'warszawa_ot4',
    'Bemowo' => 'warszawa_ot4',

    'Bielany' => 'warszawa_ot5',
    'Żoliborz' => 'warszawa_ot5',

    'Targówek' => 'warszawa_ot6',
    'Białołęka' => 'warszawa_ot6',
    'Praga-Północ' => 'warszawa_ot6',

    'Wawer' => 'warszawa_ot7',
    'Praga-Południe' => 'warszawa_ot7',
    'Wesoła' => 'warszawa_ot7',
    'Rembertów' => 'warszawa_ot7'
);

const SEXSTRINGS = Array (
    '?' => [
        "bylam" => "byłam/em",
        "bylas" => "byłaś/eś",
        "swiadoma" => "świadoma/y",
        "wykonalam" => "wykonałam/em",
        "zglaszajacej" => "zgłaszającej/ego",
        "anonimowa" => "anonimowa",
        "musiala" => "musiał(a)",
        "Patronką" => "Patronką(em)",
        "żeńskiego" => "męskiego",
        "mogla" => "mógł/mogła",
        "mieszkanka" => "mieszkańcem i obywatelem zirytowanym",
        "Patronka" => "Patron",
        "Pisarka" => "Pisarz",
        "Obrończyni DDRek" => "Obrońca DDRek",
        "Obrończyni przystanków" => "Obrońca przystanków",
        "Obrończyni zieleni" => "Obrońca zieleni",
        "Obrończyni przejść dla pieszych" => "Obrońca przejść dla pieszych",
        "Była patronka" => "Były patron",
        "Wkurzona" => "Wkurzony",
        "Początkująca" => "Początkujący",
        "Ekspertka" => "Ekspert",
        "Hurtowniczka" => "Hurtownik",
        "Pro" => "Pro",
        "wezwana" => "wezwana",
        "zmieniłeś" => "zmieniłaś/eś",
        "trafilas" => "trafiłaś/eś",
        "wkurzony" => "wkurzony/a",
        "walczacy" => "walczący/a",
        "poczatkujacy" => "początkujący/a",
        "majacy" => "mający/a",
        "profesjonalista" => "profesjonalistą/ką",
        "ekspert" => "ekspertem/ką",
        "hurtownik" => "hurtownikiem/hurtowniczką"
    ],
    'm' => [
        "bylam" => "byłem",
        "bylas" => "byłeś",
        "swiadoma" => "świadomy",
        "wykonalam" => "wykonałem",
        "zglaszajacej" => "zgłaszającego",
        "anonimowa" => "anonimowy",
        "musiala" => "musiał",
        "Patronką" => "Patronem",
        "żeńskiego" => "męskiego",
        "mogla" => "mógł",
        "mieszkanka" => "mieszkańcem i obywatelem zirytowanym",
        "Patronka" => "Patron",
        "Pisarka" => "Pisarz",
        "Obrończyni DDRek" => "Obrońca DDRek",
        "Obrończyni przystanków" => "Obrońca przystanków",
        "Obrończyni zieleni" => "Obrońca zieleni",
        "Obrończyni przejść dla pieszych" => "Obrońca przejść dla pieszych",
        "Była patronka" => "Były patron",
        "Wkurzona" => "Wkurzony",
        "Początkująca" => "Początkujący",
        "Ekspertka" => "Ekspert",
        "Hurtowniczka" => "Hurtownik",
        "Pro" => "Pro",
        "wezwana" => "wezwany",
        "zmieniłeś" => "zmieniłeś",
        "trafilas" => "trafiłeś",
        "wkurzony" => "wkurzony",
        "walczacy" => "walczący",
        "poczatkujacy" => "początkujący",
        "majacy" => "mający",
        "profesjonalista" => "profesjonalistą",
        "ekspert" => "ekspertem",
        "hurtownik" => "hurtownikiem"
    ],
    'f' => [
        "bylam" => "byłam",
        "bylas" => "byłaś",
        "swiadoma" => "świadoma",
        "wykonalam" => "wykonałam",
        "zglaszajacej" => "zgłaszającej",
        "anonimowa" => "anonimowa",
        "musiala" => "musiała",
        "Patronką" => "Patronką",
        "żeńskiego" => "żeńskiego",
        "mogla" => "mogła",
        "mieszkanka" => "mieszkanką i obywatelką zirytowaną",
        "Patronka" => "Patronka",
        "Pisarka" => "Pisarka",
        "Obrończyni DDRek" => "Obrończyni DDRek",
        "Obrończyni przystanków" => "Obrończyni przystanków",
        "Obrończyni zieleni" => "Obrończyni zieleni",
        "Obrończyni przejść dla pieszych" => "Obrończyni przejść dla pieszych",
        "Była patronka" => "Była patronka",
        "Wkurzona" => "Wkurzona",
        "Początkująca" => "Początkująca",
        "Ekspertka" => "Ekspertka",
        "Hurtowniczka" => "Hurtowniczka",
        "Pro" => "Pro",
        "wezwana" => "wezwana",
        "zmieniłeś" => "zmieniłaś",
        "trafilas" => "trafiłaś",
        "wkurzony" => "wkurzona",
        "walczacy" => "walcząca",
        "poczatkujacy" => "początkująca",
        "majacy" => "mająca",
        "profesjonalista" => "profesjonalistką",
        "ekspert" => "ekspertką",
        "hurtownik" => "hurtowniczką"
    ]
);

const EMAIL_STATUS = Array (
    'accepted' => "wysyłam",
    'delivered' => "dostarczone",
    'failed' => "niewysłane",
    'problem' => "problem z wysyłką"
);

?>

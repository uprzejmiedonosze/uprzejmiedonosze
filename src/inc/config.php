<?PHP
require_once(__DIR__ . '/dataclasses/ConfigClass.php');
require_once(__DIR__ . '/store/JsonStore.php');

const LATEST_TERMS_UPDATE = '2024-03-26';

const DT_FORMAT = 'Y-m-d\TH:i:s';
const DT_FORMAT_SHORT = 'Y-m-d\TH:i';
const DT_FORMAT_LONG = 'Y-m-d\TH:i:s.u';

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
            ['key' => null, 'href' => 'https://uprzejmiedonosze.net/wiki/niezbednik/przepisy_i_kategorie_zgloszen', 'label' => 'Przepisy zw. z parkowaniem', 'external' => true],
            ['key' => 'przesluchanie', 'href' => '/przesluchanie.html', 'label' => 'Wizyta na komisariacie'],
            ['key' => null, 'href' => 'https://uprzejmiedonosze.net/wiki/niezbednik/zwrot_srodkow_za_przesluchanie', 'label' => 'Zwrot środków za przesłuchanie', 'external' => true],
            ['key' => null, 'href' => 'https://uprzejmiedonosze.net/wiki/niezbednik/jak_napisac_zazalenie_na_decyzje_strazy_miejskiej_lub_policji', 'label' => 'Zażalenie na brak mandatu', 'external' => true],
            ['key' => 'dostep-do-informacji-publicznej', 'href' => '/dostep-do-informacji-publicznej.html', 'label' => 'Jak sprawdzić efekty pracy SM?'],
            ['key' => null, 'href' => 'https://uprzejmiedonosze.net/wiki/niezbednik/wysylka_przez_e-doreczenia', 'label' => 'e-Doręczenia', 'external' => true],
            ['key' => null, 'href' => 'https://uprzejmiedonosze.net/wiki/niezbednik/wysylka_przez_epuap', 'label' => 'Wysyłka przez ePUAP', 'external' => true],
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
            ['key' => 'changelog', 'href' => '/changelog.html', 'label' => 'Historia zmian'],
            ['key' => 'projekt', 'href' => '/projekt.html', 'label' => 'Dla programistów'],
            ['key' => 'regulamin', 'href' => '/regulamin.html', 'label' => 'Regulamin'],
            ['key' => 'polityka-prywatnosci', 'href' => '/polityka-prywatnosci.html', 'label' => 'Polityka prywatności'],
            ['key' => 'bezpieczenstwo', 'href' => '/bezpieczenstwo.html', 'label' => 'Bezpieczeństwo'],
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
} elseif (!getenv('APP_ENV') && is_file(__DIR__ . '/../config.prod.php')) {
    // Non-Docker deployment (quickfix rsync): config.prod.php generated from .env.prod at build time
    require __DIR__ . '/../config.prod.php';
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

if (!defined('MATOMO_SITE_ID'))     define('MATOMO_SITE_ID',     (int)(getenv('MATOMO_SITE_ID') ?: 1));
if (!defined('BACKEND_API_KEY'))    define('BACKEND_API_KEY',    getenv('BACKEND_API_KEY')    ?: '');
if (!defined('CORS_ALLOWED_DOMAIN')) define('CORS_ALLOWED_DOMAIN', getenv('CORS_ALLOWED_DOMAIN') ?: '');

if (!defined('B2_KEY'))      define('B2_KEY',      getenv('B2_KEY')      ?: '');
if (!defined('B2_SECRET'))   define('B2_SECRET',   getenv('B2_SECRET')   ?: '');
if (!defined('B2_BUCKET'))   define('B2_BUCKET',   getenv('B2_BUCKET')   ?: '');
if (!defined('B2_ENDPOINT')) define('B2_ENDPOINT', getenv('B2_ENDPOINT') ?: '');
if (!defined('B2_REGION'))   define('B2_REGION',   getenv('B2_REGION')   ?: '');

if (!defined('S3_KEY'))      define('S3_KEY',      getenv('S3_KEY')      ?: '');
if (!defined('S3_SECRET'))   define('S3_SECRET',   getenv('S3_SECRET')   ?: '');
if (!defined('S3_BUCKET'))   define('S3_BUCKET',   getenv('S3_BUCKET')   ?: '');
if (!defined('S3_ENDPOINT')) define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: '');
if (!defined('S3_REGION'))   define('S3_REGION',   getenv('S3_REGION')   ?: '');
unset($_appEnv, $_configDev);

$_appHost = getenv('APP_HOST');
if ($_appHost) {
    define('HOST',      $_appHost);
    define('HTTPS',     getenv('APP_HTTPS') ?: 'https');
    define('TWIG_HASH', '');
    define('CSS_HASH',  '');
    define('JS_HASH',   '');
} elseif (is_file(__DIR__ . '/../config.env.php')) {
    require(__DIR__ . '/../config.env.php');
} else {
    define('HOST',      'uprzejmiedonosze.net');
    define('HTTPS',     'https');
    define('TWIG_HASH', '');
    define('CSS_HASH',  '');
    define('JS_HASH',   '');
}
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
        "zmieniłeś" => "zmieniłaś/eś"
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
        "zmieniłeś" => "zmieniłeś"
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
        "zmieniłeś" => "zmieniłaś"
    ]
);

const EMAIL_STATUS = Array (
    'accepted' => "wysyłam",
    'delivered' => "dostarczone",
    'failed' => "niewysłane",
    'problem' => "problem z wysyłką"
);

?>

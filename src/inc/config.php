<?PHP
require_once(__DIR__ . '/dataclasses/ConfigClass.php');
require_once(__DIR__ . '/store/JsonStore.php');

const LATEST_TERMS_UPDATE = '2024-03-26';

const DT_FORMAT = 'Y-m-d\TH:i:s';
const DT_FORMAT_SHORT = 'Y-m-d\TH:i';
const DT_FORMAT_LONG = 'Y-m-d\TH:i:s.u';

$CATEGORIES = \json\get('categories.json', 'Category');
$EXTENSIONS = \json\get('extensions.json', 'Extension');
$SM_ADDRESSES = \json\get('sm.json', 'SM');
$STATUSES = \json\get('statuses.json', 'Status');
$STOP_AGRESJI = \json\get('stop-agresji.json', 'StopAgresji');
$LEVELS = \json\get('levels.json', 'Level');
$BADGES = \json\get('badges.json');

define('SMTP_HOST',    getenv('SMTP_HOST')    ?: '');
define('SMTP_USER',    getenv('SMTP_USER')    ?: '');
define('EMAIL_SENDER', getenv('EMAIL_SENDER') ?: '');
define('SMTP_PASS',    getenv('SMTP_PASS')    ?: '');
define('SMTP_GMAIL',   getenv('SMTP_GMAIL')   ?: '');

define('PLATERECOGNIZER_SECRET', getenv('PLATERECOGNIZER_SECRET') ?: '');
define('OPEN_ALPR_SECRET_1',     getenv('OPEN_ALPR_SECRET_1')     ?: '');
define('OPEN_ALPR_SECRET_2',     getenv('OPEN_ALPR_SECRET_2')     ?: '');
define('MAPBOX_API_TOKEN',       getenv('MAPBOX_API_TOKEN')       ?: '');
define('GOOGLE_MAPS_API_TOKEN',  getenv('GOOGLE_MAPS_API_TOKEN')  ?: '');
define('PATRONITE_TOKEN',        getenv('PATRONITE_TOKEN')        ?: '');

define('MAILER_DSN',            getenv('MAILER_DSN')            ?: '');
define('MAILER_FROM',           getenv('MAILER_FROM')           ?: '');
define('MAILER_WEBHOOK_SECRET', getenv('MAILER_WEBHOOK_SECRET') ?: '');
define('MAILER_DSN_ALTER',      getenv('MAILER_DSN_ALTER')      ?: '');
define('MAILER_FROM_ALTER',     getenv('MAILER_FROM_ALTER')     ?: '');

define('CRYPTO_KEY', getenv('CRYPTO_KEY') ?: '');
define('CRYPTO_IV',  getenv('CRYPTO_IV')  ?: '');
define('CRYPTO_TAG', getenv('CRYPTO_TAG') ?: '');

define('OPENAI_API_KEY',  getenv('OPENAI_API_KEY')  ?: '');
define('GOOGLE_API_KEY',  getenv('GOOGLE_API_KEY')  ?: '');
define('OPENAI_PROJECT',  getenv('OPENAI_PROJECT')  ?: '');

define('MATOMO_SITE_ID',     (int)(getenv('MATOMO_SITE_ID') ?: 1));
define('BACKEND_API_KEY',    getenv('BACKEND_API_KEY')    ?: '');
define('CORS_ALLOWED_DOMAIN', getenv('CORS_ALLOWED_DOMAIN') ?: '');

define('S3_KEY',      getenv('S3_KEY')      ?: '');
define('S3_SECRET',   getenv('S3_SECRET')   ?: '');
define('S3_BUCKET',   getenv('S3_BUCKET')   ?: '');
define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: '');
define('S3_REGION',   getenv('S3_REGION')   ?: '');

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
        "Pro" => "Pro"
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
        "Pro" => "Pro"
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
        "Pro" => "Pro"
    ]
);

const EMAIL_STATUS = Array (
    'accepted' => "wysyłam",
    'delivered' => "dostarczone",
    'failed' => "niewysłane",
    'problem' => "problem z wysyłką"
);

?>

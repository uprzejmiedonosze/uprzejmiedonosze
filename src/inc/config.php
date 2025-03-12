<?PHP
require_once(__DIR__ . '/dataclasses/ConfigClass.php');

const LATEST_TERMS_UPDATE = '2024-03-26';

const DT_FORMAT = 'Y-m-d\TH:i:s';
const DT_FORMAT_SHORT = 'Y-m-d\TH:i';

const CONFIG_DIR = __DIR__ . '/../public/api/config';

const CATEGORIES_CONFIG = CONFIG_DIR . '/categories.json';
$categories = fopen(CATEGORIES_CONFIG, "r") or die("Unable to open config file: " . CATEGORIES_CONFIG);
$CATEGORIES = (array) new ConfigClass(fread($categories, filesize(CATEGORIES_CONFIG)), 'Category');
fclose($categories);

const EXTENSIONS_CONFIG = CONFIG_DIR . '/extensions.json';
$extensions = fopen(EXTENSIONS_CONFIG, "r") or die("Unable to open config file: " . EXTENSIONS_CONFIG);
$EXTENSIONS = (array) new ConfigClass(fread($extensions, filesize(EXTENSIONS_CONFIG)), 'Extension');
fclose($extensions);

const SM_CONFIG = CONFIG_DIR . '/sm.json';
$smAddressess = fopen(SM_CONFIG, "r") or die("Unable to open config file: " . SM_CONFIG);
$SM_ADDRESSES = (array) new ConfigClass(fread($smAddressess, filesize(SM_CONFIG)), 'SM');
fclose($smAddressess);

const STATUSES_CONFIG = CONFIG_DIR . '/statuses.json';
$st = fopen(STATUSES_CONFIG, "r") or die("Unable to open config file: " . STATUSES_CONFIG);
$STATUSES = (array) new ConfigClass(fread($st, filesize(STATUSES_CONFIG)), 'Status');
fclose($st);

const SA_CONFIG = CONFIG_DIR . '/stop-agresji.json';
$stopAgresji = fopen(SA_CONFIG, "r") or die("Unable to open config file: " . SA_CONFIG);
$STOP_AGRESJI = (array) new ConfigClass(fread($stopAgresji, filesize(SA_CONFIG)), 'StopAgresji');
fclose($stopAgresji);

const SA_LEVELS = CONFIG_DIR . '/levels.json';
$levels = fopen(SA_LEVELS, "r") or die("Unable to open config file: " . SA_LEVELS);
$LEVELS = (array) new ConfigClass(fread($levels, filesize(SA_LEVELS)), 'Level');
fclose($levels);

// I'm lazy, no specific class for that
const SA_BADGES = CONFIG_DIR . '/badges.json';
$badges = fopen(SA_BADGES, "r") or die("Unable to open config file: " . SA_BADGES);
$badgesStr = fread($badges, filesize(SA_BADGES));
$BADGES = json_decode($badgesStr, true);
fclose($badges);

if (file_exists(__DIR__ . '/../config.prod.php'))
    require(__DIR__ . '/../config.prod.php');
else
    require(__DIR__ . '/../config.php');

require(__DIR__ . '/../config.env.php');
const ROOT = '/var/www/' . HOST . '/';
const BASE_URL = HTTPS . '://' . HOST . '/';

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
        "Patronka" => "Patronką(em)"
    ],
    'm' => [
        "bylam" => "byłem",
        "bylas" => "byłeś",
        "swiadoma" => "świadomy",
        "wykonalam" => "wykonałem",
        "zglaszajacej" => "zgłaszającego",
        "anonimowy" => "anonimowy",
        "musiala" => "musiał",
        "Patronka" => "Patronem"
    ],
    'f' => [
        "bylam" => "byłam",
        "bylas" => "byłaś",
        "swiadoma" => "świadoma",
        "wykonalam" => "wykonałam",
        "zglaszajacej" => "zgłaszającej",
        "anonimowa" => "anonimowa",
        "musiala" => "musiała",
        "Patronka" => "Patronką"
    ]
);

const EMAIL_STATUS = Array (
    'accepted' => "wysyłam",
    'delivered' => "dostarczone",
    'failed' => "niewysłane",
    'problem' => "problem z wysyłką"
);

?>

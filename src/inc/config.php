<?PHP 
require_once(__DIR__ . '/ConfigClass.php');

$timeout = 60 * 60 * 24 * 365;
ini_set("session.gc_maxlifetime", $timeout);
ini_set("session.cookie_lifetime", $timeout);
date_default_timezone_set('Europe/Warsaw');

const DT_FORMAT = 'Y-m-d\TH:i:s';

const CATEGORIES_CONFIG = __DIR__ . '/../public/api/config/categories.json';
$categories = fopen(CATEGORIES_CONFIG, "r") or die("Unable to open config file: " . CATEGORIES_CONFIG);
$CATEGORIES = (array) new ConfigClass(fread($categories, filesize(CATEGORIES_CONFIG)), 'Category');
fclose($categories);

const SM_CONFIG = __DIR__ . '/../public/api/config/sm.json';
$smAddressess = fopen(SM_CONFIG, "r") or die("Unable to open config file: " . SM_CONFIG);
$SM_ADDRESSES = (array) new ConfigClass(fread($smAddressess, filesize(SM_CONFIG)), 'SM');
fclose($smAddressess);

const STATUSES_CONFIG = __DIR__ . '/../public/api/config/statuses.json';
$st = fopen(STATUSES_CONFIG, "r") or die("Unable to open config file: " . STATUSES_CONFIG);
$STATUSES = (array) new ConfigClass(fread($st, filesize(STATUSES_CONFIG)), 'Status');
fclose($st);

const CATEGORIES_MATRIX = Array('a', 'b');

require_once(__DIR__ . '/../config.php');

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
        "swiadoma" => "świadoma/y",
        "wykonalam" => "wykonałam/em"
    ],
    'm' => [
        "bylam" => "byłem",
        "swiadoma" => "świadomy",
        "wykonalam" => "wykonałem"
    ],
    'f' => [
        "bylam" => "byłam",
        "swiadoma" => "świadoma",
        "wykonalam" => "wykonałam"
    ]
);

?>

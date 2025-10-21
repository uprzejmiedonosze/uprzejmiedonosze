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
        "anonimowy" => "anonimowy",
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

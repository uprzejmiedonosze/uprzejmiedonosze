<?PHP
require_once(__DIR__ . '/config.php');
use \Exception as Exception;
use \Twig\Loader\FilesystemLoader as FilesystemLoader;
use \Twig\Environment as Environment;


/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
function trimstr2upper($in) {
    return trim(mb_strtoupper($in, 'UTF-8'));
}

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
function trimstr2lower($in) {
    return trim(mb_strtolower($in, 'UTF-8'));
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function exception_handler($exception) {
    try{
        $email = getCurrentUserEmail();
    }catch(Exception $e){
        $email = 'niezalogowany';
    }
    $msg = $exception->getMessage() . " szkodnik: $email, " . $exception->getFile()
        . ':' . $exception->getLine() . "\n" . $exception->getTraceAsString();
    if(posix_isatty(0)){
        echo($msg . "\n");
        return;
    }
    $time = logger($msg, true);

    _sendSlackError($msg);

    $loader = new FilesystemLoader(__DIR__ . '/../templates');
    $twig = new Environment($loader,
    [
        'debug' => false,
        'strict_variables' => false
    ]);

    echo $twig->render('error.html.twig', [
        'head' =>
        [
            'title' => "Wystąpił błąd",
            'shortTitle' => "Wystąpił błąd"
        ],
        'general' =>
        [
            'uri' => $_SERVER['REQUEST_URI'],
            'isProd' => isProd(),
            'isStaging' => isStaging()
        ],
        'msg' => $msg,
        'exception' => $exception,
        'email' => $email,
        'time' => $time
    ]);
}

set_exception_handler('exception_handler');

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function logger($msg, $force = null){
    $user = '';
    if(!empty($_SESSION['user_email'])){
        $user = " [" . $_SESSION['user_email'] . ']';
    }

    $time = date(DT_FORMAT);
    if(!isProd() || $force){
        error_log($time . $user . "\t$msg\n", 3, "/var/log/uprzejmiedonosze.net/%VERSION%.log");
    }
    return $time;
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function getCurrentUserEmail(){
    if(!empty($_SESSION['user_email'])){
        return $_SESSION['user_email'];
    }
    throw new Exception("Próba pobrania danych niezalogowanego użytkownika");
}

function checkIfLogged(){
    if(!isLoggedIn()){
        redirect("login.html?next=" . getRequestUri());
    }
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function getRequestUri(){
    return preg_replace('/^\/*/', '', $_SERVER['REQUEST_URI']);
}

function checkIfRegistered(){
    global $storage;
    checkIfLogged();

    try {
        $user = $storage->getCurrentUser();
    }catch (Exception $e){
        redirect("register.html?next=" . getRequestUri());
    }
    if(!$user){
        redirect("register.html?next=" . getRequestUri());
    }
    if(!$user->isRegistered()) {
        redirect("register.html?next=" . getRequestUri());
    }
}

function isAdmin(){
    global $storage;
    return isLoggedIn() && $storage->getCurrentUser()->isAdmin();
}

function genSafeId(){
    return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
}

/** @SuppressWarnings("exit") */
function raiseError($msg, $status, $notify = null){
    logger("raiseError $msg with $status", $notify);
    $error = Array(
        "code" => $status,
        "message" => $msg
    );
    if($notify) {
        _sendSlackError($msg);
    }
    http_response_code($status);
    echo json_encode($error);
    die();
}

function guess_sex_by_name($name){
    $names = preg_split('/\s+/', trimstr2lower($name));
    if(count($names) < 1){
        return '?';
    }
    if($names[0] == 'kuba' || substr($names[0], -1) != 'a'){
        return 'm';
    }
    return 'f';
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function guess_sex_current_user(){
    return SEXSTRINGS[guess_sex_by_name($_SESSION['user_name'])];
}

function capitalizeSentence($input){
    if(!isset($input) || trim($input) === ''){
        return '';
    }
    $isUpperCase = (mb_strlen($input, 'UTF-8') / 2) < (int)preg_match_all('/[A-Z]/', $input);
    
    $out = trim(
        preg_replace_callback('/([.!?])\s+(\w)/', function ($matches) {
            return mb_strtoupper($matches[1] . ' ' . $matches[2], 'UTF-8');
            }, ucfirst( $isUpperCase ? (mb_strtolower($input, 'UTF-8')): $input )
        )
    );
    return (substr($out, -1) == '.')? $out: "{$out}.";
}

function capitalizeName($input){
    if(!isset($input) || trim($input) === ''){
        return '';
    }
    return trim(mb_convert_case($input, MB_CASE_TITLE, 'UTF-8'));
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function isIOS(){
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $iPod    = (bool)stripos($userAgent, "iPod");
    $iPhone  = (bool)stripos($userAgent, "iPhone");
    $iPad    = (bool)stripos($userAgent, "iPad");
    return $iPod || $iPhone || $iPad;
}

/** @SuppressWarnings("exit") */
function redirect($destPath){
    $destPath = preg_replace('/\/+/', '/', $destPath);
    header("X-Redirect: %HTTPS%://%HOST%/$destPath");
    header("Location: %HTTPS%://%HOST%/$destPath");
    die();
}

/** 
 * Sends message to #updates slack channel at uprzejmiedonosze.slack.com
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function _sendSlackOnRegister($user){
    $title = "Nowa rejestracja {$user->data->name}";

    logger($title, true);

    $msg = [
        "fallback" => $title,
        "title" => $title,
        "color" => "#E7BF3D",
        "author_name" => $user->data->email,
        "author_link" => "mailto:{$user->data->email}",
        "image_url" => @$_SESSION['user_picture'],
        "footer" => $user->data->address,
    ];
    _sendSlackAsync($msg, isProd()? 1: 11);
}

/**
 * Sends formatted message to Slack.
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 */
function _sendSlackOnNewApp($app){
    $title = "Wysyłka zgłoszenia {$app->number} ({$app->address->city})";

    logger($title, true);

    $msg = [
        "fallback" => $title,
        "title" => "Wysyłka zgłoszenia {$app->number}",
        "title_link" => "%HTTPS%://%HOST%/ud-{$app->id}.html",
        
        "color" => "#229A7F",

        "author_name" => "{$app->user->name}",
        "author_icon" => @$_SESSION['user_picture'],
        "author_link" => "mailto:{$app->user->email}",

        'fields' => [[
                'title' => $app->address->city . (($app->guessSMData()->email)? "": " (!)"),
                'value' => ($app->category == 0)? 'Inne: ' . $app->userComment: $app->getCategory()->getTitle(),
                'short' => true
            ]],
        "image_url" => "%HTTPS%://%HOST%/{$app->contextImage->url}",
        "thumb_url" => "%HTTPS%://%HOST%/{$app->contextImage->thumb}",

        "footer" => $app->getCategory()->getTitle(),
        "footer_icon" => "%HTTPS%://%HOST%/img/{$app->category}.jpg",
        "ts" => strtotime($app->date)
    ];
    _sendSlackAsync($msg, isProd()? 1: 11);
}

/** 
 * Sends message to #errors slack channel at uprzejmiedonosze.slack.com
 */
function _sendSlackError($msg){
    _sendSlackAsync($msg, isProd()? 2: 12);
}

/**
 * $type: 1 update, 2 error
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function _sendSlackAsync($msg, $type){
    #$queue = msg_get_queue(9997);
    #return msg_send($queue, $type, $msg, true, false);
}

function isProd(){
    return '%HOST%' == 'uprzejmiedonosze.net' || '%HOST%' == 'shadow.uprzejmiedonosze.net';
}

function isStaging(){
    return '%HOST%' == 'staging.uprzejmiedonosze.net';
}

function extractAppNumer($appNumber) {
    $number = explode("/", $appNumber);
    return intval($number[2]);
}

/**
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
function policeStationsSzczecin(&$app) {
    preg_match('/(.*)\s([0-9]+)[a-zA-Z]?, Szczecin/', $app->address->address, $match);
    $street = $match[1] ?? "(not Szczecin)";
    $number = $match[2] ?? null;

    if(str_contains($street, 'Jagiellońska')) {
        if ($number < 45) return 'szczecin-niebuszewo';
        if ($number > 45) return 'szczecin-srodmiescie';
    }
    if(str_contains($street, 'Piastów')) {
        if ($number <= 5 || $number > 74) return 'szczecin-niebuszewo';
        return 'szczecin-srodmiescie';
    }
    if(str_contains($street, 'Wojska Polskiego')) {
        if ($number <= 50) return 'szczecin-srodmiescie';
        return 'szczecin-niebuszewo';
    }
    if(str_contains($street, 'Bolesława Śmiałego')) {
        if ($number < 11 || $number > 41) return 'szczecin-niebuszewo';
        return 'szczecin-srodmiescie';
    }
    if(str_contains($street, 'Bohaterów Warszawy')) {
        if ($number < 17 || $number > 106) return 'szczecin-niebuszewo';
        return 'szczecin-srodmiescie';
    }

    if(str_contains($street, 'plac Odrodzenia')) return 'szczecin-niebuszewo';
    if(str_contains($street, 'Jana Pawła II')) return 'szczecin-niebuszewo';
    if(str_contains($street, 'Monte Cassino')) return 'szczecin-niebuszewo';
    if(str_contains($street, 'Piłsudskiego')) return 'szczecin-niebuszewo';
    if(str_contains($street, 'Mazurska')) return 'szczecin-niebuszewo';
    if(str_contains($street, 'Michała Kleofasa Ogińskiego')) return 'szczecin-niebuszewo';
    if(str_contains($street, '5 Lipca')) return 'szczecin-niebuszewo';
    if(str_contains($street, 'Rayskiego')) return 'szczecin-niebuszewo';

    $x = $app->getLon();
    $y = $app->getLat();

    $odraY = 53.42745434366132;
    $odraX = 14.565803905874402;
    $toryX = 14.52274239523983;
    $toryY = 53.43503982962682;
    $xoffset = $odraX - $toryX;
    $yoffset = $odraY - $toryY;

    if ($x < $toryX) return 'szczecin-pogodno';
    if ($x > $odraX) return 'szczecin-miasto';
    
    $niebkoSrodmiescieSplit = ($x-$toryX) * ($yoffset/$xoffset) + $toryY;
    if ($y < $niebkoSrodmiescieSplit) return 'szczecin-srodmiescie';
    return 'szczecin-niebuszewo';
}

?>

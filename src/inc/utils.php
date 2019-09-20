<?PHP
require_once(__DIR__ . '/config.php');

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
    
    $loader = new Twig_Loader_Filesystem(__DIR__ . '/../templates');
    $twig = new Twig_Environment($loader,
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
            'uri' => $_SERVER['REQUEST_URI']
        ],
        'msg' => $msg,
        'exception' => $exception,
        'email' => $email,
        'time' => $time
    ]);
}

set_exception_handler('exception_handler');

function logger($msg, $force = null){
    $user = '';
    if(!empty($_SESSION['user_email'])){
        $user = " [" . $_SESSION['user_email'] . ']';
    }

    $time = date(DT_FORMAT);
    if('%HOST%' != 'uprzejmiedonosze.net' || $force){
        error_log($time . $user . "\t$msg\n", 3, "/var/log/uprzejmiedonosze.net/%VERSION%.log");
    }
    return $time;
}

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


function guidv4(){
	$data = openssl_random_pseudo_bytes(16);
    assert(strlen($data) == 16);

    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** @SuppressWarnings("exit") */
function raiseError($msg, $status){
    logger("raiseError $msg with $status", true);
    $error = Array(
        "code" => $status,
        "message" => $msg
    );
    http_response_code($status);
    echo json_encode($error);
    die();
}

function guess_sex_by_name($name){
    $names = preg_split('/\s+/', mb_strtolower($name, 'UTF-8'));
    if(count($names) < 1){
        return '?';
    }
    if($names[0] == 'kuba' || substr($names[0], -1) != 'a'){
        return 'm';
    }
    return 'f';
}

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

function isIOS(){
    $iPod    = (bool)stripos($_SERVER['HTTP_USER_AGENT'],"iPod");
    $iPhone  = (bool)stripos($_SERVER['HTTP_USER_AGENT'],"iPhone");
    $iPad    = (bool)stripos($_SERVER['HTTP_USER_AGENT'],"iPad");
    return $iPod || $iPhone || $iPad;
}

/** @SuppressWarnings("exit") */
function redirect($destPath){
    $destPath = preg_replace('/\/+/', '/', $destPath);

    logger("redirect to " . $destPath);
    header("X-Redirect: %HTTPS%://%HOST%/$destPath");
    header("Location: %HTTPS%://%HOST%/$destPath");
    die();
}

/** 
 * Sends message to #updates slack channel at uprzejmiedonosze.slack.com
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
    _sendSlackAsync($msg, ('%HOST%' == 'uprzejmiedonosze.net')? 1: 11);
}

/**
 * Sends formatted message to Slack.
 */
function _sendSlackOnNewApp($app, $todaysNewAppsCount){
    $title = "Nowe zgłoszenie {$app->number} ({$app->address->city}, {$todaysNewAppsCount} dzisiaj)";

    logger($title, true);

    $msg = [
        "fallback" => $title,
        "title" => "Nowe zgłoszenie {$app->number}",
        "title_link" => "%HTTPS%://%HOST%/ud-{$app->id}.html",
        
        "color" => "#229A7F",

        "author_name" => "{$app->user->name}",
        "author_icon" => @$_SESSION['user_picture'],
        "author_link" => "mailto:{$app->user->email}",

        'fields' => [[
                'title' => $app->address->city . (($app->guessSMData()->email)? "": " (!)"),
                'value' => ($app->category == 0)? 'Inne: ' . $app->userComment: $app->getCategory()->getTitle(),
                'short' => true
            ],[
                'title' => "Dzisiaj:",
                'value' => $todaysNewAppsCount,
                'short' => true
            ]],
        "image_url" => "%HTTPS%://%HOST%/{$app->contextImage->url}",
        "thumb_url" => "%HTTPS%://%HOST%/{$app->contextImage->thumb}",

        "footer" => $app->getCategory()->getTitle(),
        "footer_icon" => "%HTTPS%://%HOST%/img/{$app->category}.jpg",
        "ts" => strtotime($app->date)
    ];
    _sendSlackAsync($msg, ('%HOST%' == 'uprzejmiedonosze.net')? 1: 11);
}

/** 
 * Sends message to #errors slack channel at uprzejmiedonosze.slack.com
 */
function _sendSlackError($msg){
    _sendSlackAsync($msg, ('%HOST%' == 'uprzejmiedonosze.net')? 2: 12);
}

/**
 * $type: 1 update, 2 error
 */
function _sendSlackAsync($msg, $type){
    $queue = msg_get_queue(9997);
    return msg_send($queue, $type, $msg, true, false);
}

?>


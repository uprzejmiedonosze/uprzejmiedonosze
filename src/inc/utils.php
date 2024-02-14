<?PHP
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/Exceptions.php');
use \Exception as Exception;

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

function cleanWhiteChars($input) {
    return trim(preg_replace("/\s+/u", " ", $input));
}

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
        if (!isDev()) error_log($time . $user . "\t$msg\n", 3, "/var/log/uprzejmiedonosze.net/%HOST%.log");
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


/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function getRequestUri(){
    return preg_replace('/^\/*/', '', $_SERVER['REQUEST_URI']);
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
    if (!is_string($msg)) {
        if (method_exists($msg, 'getMessage')) {
            if (isProd()) \Sentry\captureException($msg);
            $msg = $msg->getMessage();
        }
    }

    logger("raiseError $msg with $status", $notify);
    $status = ($status ?? 0 > 300) ? $status : 500;
    $error = Array(
        "code" => $status,
        "message" => (string)$msg
    );
    if($notify) {
        _sendSlackError((string)$msg);
    }
    http_response_code($status);
    echo json_encode($error);
    die();
}

function capitalizeSentence($input){
    if(!isset($input) || trim($input) === ''){
        return '';
    }
    $isUpperCase = (mb_strlen($input, 'UTF-8') / 2) < (int)preg_match_all('/[A-Z]/', $input);
    
    $out = trim(
        preg_replace_callback('/(?<!tj|np|tzw|tzn)([.!?])\s+([[:lower:]])/', function ($matches) {
            return mb_strtoupper($matches[1] . ' ' . $matches[2], 'UTF-8');
            }, ucfirst( $isUpperCase ? (mb_strtolower($input, 'UTF-8')): $input )
        )
    );
    return (substr($out, -1) == '.')? $out: "{$out}.";
}

function capitalizeName($input){
    if(!isset($input) || cleanWhiteChars($input) === ''){
        return '';
    }
    return mb_convert_case(cleanWhiteChars($input), MB_CASE_TITLE, 'UTF-8');
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

function isDev() {
    return !isProd() && !isStaging();
}

function extractAppNumer($appNumber) {
    $number = explode("/", $appNumber);
    return intval($number[2]);
}

function setSentryTag(string $tag, $value): void {
    if (!isProd()) return;

    \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($tag, $value): void {
        $scope->setTag($tag, $value);
    });    
}


?>

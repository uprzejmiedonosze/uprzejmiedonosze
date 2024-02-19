<?PHP
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/dataclasses/Exceptions.php');
use \Exception as Exception;

session_start();

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
function logger(string|object|array $msg, $force = true): string {
    $time = date(DT_FORMAT);
    if(!isProd() || $force) {
        if (isDev()) return $time;

        $user = "[" . ($_SESSION['user_email'] ?? '') . ']';

        if (!is_string($msg))
            $msg = print_r($msg, true);

        $bt = debug_backtrace();
        $caller = array_shift($bt);
        $location = $caller['file'] . ':' . $caller['line'];
        $location = preg_replace('/^.var.www.%HOST%.webapp/i', '', $location);

        error_log("$time $user $location\t$msg\n", 3, "/var/log/uprzejmiedonosze.net/%HOST%.log");
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

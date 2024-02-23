<?PHP

function isProd(){
    return '%HOST%' == 'uprzejmiedonosze.net' || '%HOST%' == 'shadow.uprzejmiedonosze.net';
}

function isStaging(){
    return '%HOST%' == 'staging.uprzejmiedonosze.net';
}

function isDev() {
    return !isProd() && !isStaging();
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
function logger(string|object|array|null $msg, $force = true): string {
    $DT_FORMAT = 'Y-m-d\TH:i:s';
    $time = date($DT_FORMAT);
    if(!isProd() || $force) {
        if (isDev()) return $time;

        $sessionId = session_id() ?? 'no-session';

        $user = "[" . ($_SESSION['user_email'] ?? '') . ']';

        if (is_null($msg))
            $msg = 'null';
        if (!is_string($msg))
            $msg = print_r($msg, true);

        $debug_backtrace = debug_backtrace();
        $caller = array_shift($debug_backtrace);
        $location = $caller['file'] . ':' . $caller['line'];
        $location = preg_replace('/^.var.www.%HOST%.webapp/i', '', $location);

        error_log("$time $user $sessionId $location\t$msg\n", 3, "/var/log/uprzejmiedonosze.net/%HOST%.log");
    }
    return $time;
}
<?PHP

function isProd(): bool {
    return '%HOST%' == 'uprzejmiedonosze.net' || '%HOST%' == 'shadow.uprzejmiedonosze.net';
}

function isStaging(): bool {
    return '%HOST%' == 'staging.uprzejmiedonosze.net';
}

function isDev(): bool {
    return !isProd() && !isStaging();
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
function logger(string|object|array|null $msg, $force = null): string {
    $DT_FORMAT = 'Y-m-d\TH:i:s';
    $time = date($DT_FORMAT);
    if (is_null($msg))
        $msg = 'null';
    if (!is_string($msg))
        $msg = print_r($msg, true);

    if(!isProd() || $force) {
        $user = "[" . ($_SESSION['user_email'] ?? '') . ']';

        $debug_backtrace = debug_backtrace();
        $caller = array_shift($debug_backtrace);
        $location = $caller['file'] . ':' . $caller['line'];
        $location = preg_replace('/^.var.www.%HOST%.webapp/i', '', $location);
        $prefix = str_replace('uprzejmiedonosze.net', '', '%HOST%');

        error_log("$time $user $prefix$location\t$msg\n", 3, "/var/log/uprzejmiedonosze.net/%HOST%.log");
    }
    if($force)
        send_syslog($msg, false);
    return $time;
}

function send_syslog(string $msg, bool $debug) {
    openlog("uprzejmiedonosze", LOG_PID | LOG_PERROR, LOG_LOCAL0);
    syslog($debug ? LOG_DEBUG : LOG_INFO, ($debug ? '[DEBUG] ' : '[INFO] ') . $msg);
    closelog();
}

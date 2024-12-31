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

function trimAbsolutePaths(string $backtrace): string {
    $backtrace = preg_replace('/\/var\/www\/%HOST%\/webapp/i', '#UD', $backtrace);
    return preg_replace('/\/var\/www\/%HOST%\/vendor/i', '', $backtrace);
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
function logger(string|object|array|null $msg, $force = null): string {
    $DT_FORMAT = 'Y-m-d\TH:i:s';
    $time = date($DT_FORMAT);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?.?.?.?';
    if (is_null($msg))
        $msg = 'null';
    if (!is_string($msg))
        $msg = print_r($msg, true);

    if(!isProd() || $force) {
        $user = "[" . ($_SESSION['user_email'] ?? '') . ']';

        $debug_backtrace = debug_backtrace();
        $caller = array_shift($debug_backtrace);
        $location = $caller['file'] . ':' . $caller['line'];
        $location = trimAbsolutePaths($location);
        $prefix = str_replace('uprzejmiedonosze.net', '', '%HOST%');

        send_syslog("$ip $user $prefix$location \"$msg\"");
        error_log("$time $user $prefix$location\t$msg\n", 3, "/var/log/uprzejmiedonosze.net/%HOST%.log");
    }
        
    return $time;
}

function send_syslog(string $msg): void {
    if (str_ends_with($_SERVER['_'] ?? '', 'phpunit'))
        return;
    
    openlog("uprzejmiedonosze", LOG_PID | LOG_PERROR, LOG_LOCAL0);
    syslog(LOG_INFO, $msg);
    closelog();
}

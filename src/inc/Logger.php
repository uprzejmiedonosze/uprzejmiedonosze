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

function environment(): string {
    if (isProd()) return 'prod';
    if (isStaging()) return 'staging';
    return 'dev';

}

function trimAbsolutePaths(string $backtrace): string {
    $backtrace = preg_replace('/^.*\/var\/www\/.*\/webapp\//im', '  #UD ', $backtrace);
    return preg_replace('/^.*\/var\/www\/.*\/vendor\//im', '  ', $backtrace);
}

function removeVendor(string $backtrace): string {
    return preg_replace('/^.*\/var\/www\/.*\/vendor.*\n/im', '', $backtrace);
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
        $prefix = $force ? $prefix : "dbg $prefix";

        send_syslog("$ip $user $prefix$location \"$msg\"", debug:!$force);
        error_log("$time $user $prefix$location\t$msg\n", 3, "/var/log/uprzejmiedonosze.net/%HOST%.log");
        if ($force) {
            $e = new Exception();
            error_log(trimAbsolutePaths(removeVendor($e->getTraceAsString())), 3, "/var/log/uprzejmiedonosze.net/%HOST%.log");
        }
    }
        
    return $time;
}
    
function send_syslog(string $msg, bool $debug): void {
    if (str_ends_with($_SERVER['_'] ?? '', 'phpunit'))
        return;

    openlog("uprzejmiedonosze", LOG_PID | LOG_PERROR, LOG_LOCAL0);
    syslog($debug ? LOG_DEBUG: LOG_INFO, $msg);
    closelog();
}

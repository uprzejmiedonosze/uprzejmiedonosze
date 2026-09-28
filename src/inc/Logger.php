<?PHP

function isProd(): bool {
    return getenv('APP_ENV') === 'prod';
}

function isStaging(): bool {
    return getenv('APP_ENV') === 'staging';
}

function isDev(): bool {
    return !isProd() && !isStaging();
}

function environment(): string {
    if (isProd()) return 'prod';
    if (isStaging()) return 'staging';
    return 'dev';
}

function environmentSuffix(): string {
    if (isProd()) return '';
    if (isStaging()) return 'stg';
    return 'dev';
}

function trimAbsolutePaths(string $backtrace): string {
    $backtrace = preg_replace('/^.*\/var\/www\/.*\/webapp\//im', "  #UD ", $backtrace);
    return preg_replace('/^.*\/var\/www\/.*\/vendor\//im', "  ", $backtrace);
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
    if (defined('PHPUNIT_RUNNING')) return $time;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
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

        send_syslog("$ip $user $location \"$msg\"", debug:!$force);
        // Docker's syslog log driver (services/compose.yml) tags the whole
        // stderr stream as LOG_ERR and stdout as LOG_INFO — it has no
        // concept of per-line severity. Routine/debug logger() calls
        // (isStaging()/isDev() tracing, no $force) were all landing on
        // stderr and showing up as "errors" in Papertrail regardless of
        // content (confirmed 2026-09-28). Only forced calls (used for
        // real error conditions or things that must stay visible on prod)
        // go to stderr/LOG_ERR; the stack trace below is separately gated
        // on environment, not on $force.
        error_log("$time $user $location\t$msg\n", 3, $force ? 'php://stderr' : 'php://stdout');
        // Stack trace is gated on environment, not $force (2026-09-28
        // correction) — $force's job is only "show this on prod too", it
        // was never meant to also control the trace dump. That's how this
        // behaved before: $force made prod log the line, but the trace
        // itself only ever showed up on non-prod envs regardless of
        // $force.
        if (!isProd()) {
            $e = new Exception();
            error_log(trimAbsolutePaths(removeVendor($e->getTraceAsString())), 3, 'php://stderr');
        }
    }
        
    return $time;
}
    
function send_syslog(string $msg, bool $debug): void {
    if (str_ends_with($_SERVER['_'] ?? '', 'phpunit'))
        return;

    $environment = environmentSuffix();
    openlog("uprzejmiedonosze $environment", LOG_NDELAY, LOG_DAEMON);
    if (str_contains(mb_strtolower($msg), 'error'))
        syslog(LOG_ERR, $msg);
    else
        syslog($debug ? LOG_DEBUG: LOG_INFO, $msg);
    closelog();
}

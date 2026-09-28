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
function _log_write(string $level, string|object|array|null $msg, string $stream, int $syslogLevel, ?string $trace): void {
    if (defined('PHPUNIT_RUNNING')) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_null($msg))
        $msg = 'null';
    if (!is_string($msg))
        $msg = print_r($msg, true);

    $DT_FORMAT = 'Y-m-d\TH:i:s';
    $time = date($DT_FORMAT);
    $user = "[" . ($_SESSION['user_email'] ?? '') . ']';

    // Caller of log_debug()/log_info()/log_error(), not of this helper.
    $debug_backtrace = debug_backtrace();
    $caller = $debug_backtrace[1] ?? $debug_backtrace[0];
    $location = $caller['file'] . ':' . $caller['line'];
    $location = trimAbsolutePaths($location);

    send_syslog("$ip $user $location \"$msg\"", $syslogLevel);
    error_log("$time $level $user $location\t$msg\n", 3, $stream);
    if ($trace !== null) {
        error_log(trimAbsolutePaths(removeVendor($trace)), 3, 'php://stderr');
    }
}

// Tracing/routine noise, useful only while developing. Never shown on prod.
function log_debug(string|object|array|null $msg): void {
    if (isProd()) return;
    _log_write('DEBUG', $msg, 'php://stdout', LOG_DEBUG, null);
}

// Routine/business events worth keeping. Shown on dev/staging always;
// on prod only when $force is true (the caller decides it must stay visible there).
function log_info(string|object|array|null $msg, bool $force = false): void {
    if (isProd() && !$force) return;
    _log_write('INFO', $msg, 'php://stdout', LOG_INFO, null);
}

// Real error conditions. Always logged, on every environment, to stderr
// with a stack trace (from $e when given, otherwise captured here).
function log_error(string|object|array|null $msg, ?\Throwable $e = null): void {
    $trace = ($e ?? new Exception())->getTraceAsString();
    _log_write('ERROR', $msg, 'php://stderr', LOG_ERR, $trace);
}

function send_syslog(string $msg, int $level): void {
    if (str_ends_with($_SERVER['_'] ?? '', 'phpunit'))
        return;

    $environment = environmentSuffix();
    openlog("uprzejmiedonosze $environment", LOG_NDELAY, LOG_DAEMON);
    syslog($level, $msg);
    closelog();
}

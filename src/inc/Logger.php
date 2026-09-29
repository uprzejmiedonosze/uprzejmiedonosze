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

// ── Syslog transport ──────────────────────────────────────────────────────
//
// staging/prod bind-mount the host's journald socket directory
// (services/compose.yml) at this path; each log line is sent there as its
// own syslog datagram with a real PRI (facility<<3 | severity), so rsyslog
// forwards it to Papertrail with the correct severity already attached —
// no more "everything on stderr is ERROR" (see AGENTS.md § Logging for the
// full story of why the old stdout/stderr-stream-based approach couldn't
// work: php-fpm's catch_workers_output merges worker stdout+stderr into a
// single error_log destination before Docker ever sees two streams).
//
// dev/test never have that socket mounted, so every call here silently
// falls through to the php://stderr fallback below — readable via
// `docker logs`, no special dev setup needed.

const LOG_SYSLOG_SOCKET_DEFAULT = '/run/systemd/journal/dev-log';

function _log_socket_path(): string {
    return getenv('LOG_SYSLOG_SOCKET') ?: LOG_SYSLOG_SOCKET_DEFAULT;
}

function _log_ident(): string {
    return getenv('LOG_IDENT') ?: ('uprzejmiedonosze-' . environment());
}

/**
 * Lazily connects to the syslog datagram socket, caching the result for the
 * life of the process. Returns false when unavailable (no such socket, or
 * connect failed) so callers fall back to stderr. $reset re-attempts the
 * connection on the next call — used after a write fails, e.g. because
 * journald restarted and recreated the socket under us.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function _log_socket(bool $reset = false) {
    static $socket = null; // null = not yet tried, false = failed, resource = connected
    if ($reset) {
        $socket = null;
        return null;
    }
    if ($socket !== null) return $socket;

    $path = _log_socket_path();
    if (!file_exists($path)) {
        $socket = false;
        return $socket;
    }
    $s = @stream_socket_client("udg://$path", $errno, $errstr, 1);
    $socket = ($s !== false) ? $s : false;
    return $socket;
}

// Pure formatting, kept separate from the socket I/O so it's directly
// unit-testable (see tests/LoggerTest.php).
function _log_syslog_datagram(int $severity, string $ident, string $line): string {
    $pri = LOG_DAEMON | $severity;
    return "<$pri>$ident: $line";
}

function _log_send_syslog(int $severity, string $line): bool {
    $socket = _log_socket();
    if (!$socket) return false;

    $datagram = _log_syslog_datagram($severity, _log_ident(), $line);
    $written = @fwrite($socket, $datagram);
    if ($written === false) {
        _log_socket(reset: true);
        return false;
    }
    return true;
}

function _log_fallback(string $level, string $line): void {
    $time = date('Y-m-d\TH:i:s');
    error_log("$time $level $line\n", 3, 'php://stderr');
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
 */
function _log_write(int $severity, string $level, string|object|array|null $msg, ?string $trace): void {
    if (defined('PHPUNIT_RUNNING')) return;
    if (is_null($msg))
        $msg = 'null';
    if (!is_string($msg))
        $msg = print_r($msg, true);

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user = "[" . ($_SESSION['user_email'] ?? '') . ']';

    // Caller of log_debug()/log_info()/log_error(), not of this helper.
    $debug_backtrace = debug_backtrace();
    $caller = $debug_backtrace[1] ?? $debug_backtrace[0];
    $location = $caller['file'] . ':' . $caller['line'];
    $location = trimAbsolutePaths($location);

    $body = $trace !== null ? "$msg\n$trace" : $msg;
    // One syslog datagram per physical line — a multi-line datagram (print_r
    // dumps, stack traces) would otherwise arrive at journald as a single
    // blob that Papertrail can't usefully split on.
    foreach (explode("\n", $body) as $line) {
        if ($line === '') continue;
        $formatted = substr("$ip $user $location\t$line", 0, 8000);
        if (!_log_send_syslog($severity, $formatted)) {
            _log_fallback($level, $formatted);
        }
    }
}

// Tracing/routine noise, useful only while developing. Never shown on prod.
function log_debug(string|object|array|null $msg): void {
    if (isProd()) return;
    _log_write(LOG_DEBUG, 'DEBUG', $msg, null);
}

// Routine/business events worth keeping. Shown on dev/staging always;
// on prod only when $force is true (the caller decides it must stay visible there).
function log_info(string|object|array|null $msg, bool $force = false): void {
    if (isProd() && !$force) return;
    _log_write(LOG_INFO, 'INFO', $msg, null);
}

// Real error conditions. Always logged, on every environment, with a stack
// trace (from $e when given, otherwise captured here).
function log_error(string|object|array|null $msg, ?\Throwable $e = null): void {
    $trace = trimAbsolutePaths(removeVendor(($e ?? new Exception())->getTraceAsString()));
    _log_write(LOG_ERR, 'ERROR', $msg, $trace);
}

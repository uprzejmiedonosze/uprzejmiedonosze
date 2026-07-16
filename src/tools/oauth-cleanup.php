<?PHP namespace admin;

// Must match src/index.php + src/api/{mcp,oauth}/index.php: league builds
// expires_at from PHP's default timezone, so comparisons here need the same
// one or every "now" is off by the UTC offset.
date_default_timezone_set('Europe/Warsaw');

require(__DIR__ . '/../../vendor/autoload.php');
require(__DIR__ . '/../inc/include.php');

echo date('Y-m-d H:i:s') . " — oauth cleanup start\n";

cleanupOAuthAuthCodes();
cleanupOAuthAccessTokens();
cleanupOAuthRefreshTokens();

echo date('Y-m-d H:i:s') . " — oauth cleanup done\n";
\telemetry\log('cron_oauth_cleanup', null, ['status' => 'success']);

/** Single-use, 10-minute-lived codes (see OAuthServerFactory.php) — safe to purge once expired. */
function cleanupOAuthAuthCodes(): void {
    $stmt = \store\prepare('DELETE FROM oauth_auth_codes WHERE expires_at < :now');
    $stmt->execute([':now' => date('Y-m-d H:i:s')]);
    echo "  oauth_auth_codes: usunięto {$stmt->rowCount()}\n";
}

/**
 * Revoked access tokens are dead immediately. Naturally-expired-but-never-
 * revoked ones are kept a while past their 1h TTL: the connected-apps page
 * lists a connection by its live (revoked = 0) access-token rows, and a
 * client that simply hasn't refreshed yet should still show up there — so
 * only prune those once they're older than the refresh-token lifetime (30d),
 * by which point the connection is dead regardless.
 */
function cleanupOAuthAccessTokens(): void {
    $stmt = \store\prepare(
        'DELETE FROM oauth_access_tokens WHERE revoked = 1 OR expires_at < :grace'
    );
    $stmt->execute([':grace' => date('Y-m-d H:i:s', strtotime('-35 days'))]);
    echo "  oauth_access_tokens: usunięto {$stmt->rowCount()}\n";
}

/**
 * Refresh tokens carry their own expiry inside the encrypted payload league
 * hands to the client, so once a DB row is actually revoked or expired it's
 * pure bookkeeping garbage — league itself will never honour the token again.
 */
function cleanupOAuthRefreshTokens(): void {
    $stmt = \store\prepare(
        'DELETE FROM oauth_refresh_tokens WHERE revoked = 1 OR expires_at < :now'
    );
    $stmt->execute([':now' => date('Y-m-d H:i:s')]);
    echo "  oauth_refresh_tokens: usunięto {$stmt->rowCount()}\n";
}

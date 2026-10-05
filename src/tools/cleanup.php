<?PHP namespace admin;

require_once(__DIR__ . '/common.php');

echo date('Y-m-d H:i:s') . " — cleanup start\n";

removeAppsByStatus(olderThan: 10, status: 'draft', dryRun: false);
removeAppsByStatus(olderThan: 30, status: 'ready', dryRun: false);
cleanupStuckSendingApps(olderThanMinutes: 10, dryRun: false);
echo 'wip: usunięto ' . \wip\purgeOlderThan(\wip\WIP_TTL_HOURS) . " plików starszych niż " . \wip\WIP_TTL_HOURS . "h\n";

echo date('Y-m-d H:i:s') . " — cleanup done\n";
\telemetry\log('cron_cleanup', null, ['status' => 'success']);

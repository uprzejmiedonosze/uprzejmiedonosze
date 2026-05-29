<?PHP namespace admin;

require_once(__DIR__ . '/common.php');

echo date('Y-m-d H:i:s') . " — S3 sync start\n";

/**
 * Uploads all local CDN files to S3 and removes them locally.
 * Skips files belonging to "fresh" applications (draft/ready/confirmed) 
 * to ensure they are available for immediate operations like PDF/ZIP generation.
 * 
 * We only sync files for apps that are 'sent', 'confirmed-waiting', 'sending-failed', etc.
 * OR we just sync EVERYTHING that hasn't been modified in the last 2 hours.
 */
purgeLocalFiles(dryRun: false);

echo date('Y-m-d H:i:s') . " — S3 sync done\n";
\telemetry\log('cron_s3_sync', null, ['status' => 'success']);

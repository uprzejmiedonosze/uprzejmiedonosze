<?PHP namespace admin;

require_once(__DIR__ . '/common.php');

use store\S3;

$dryRun = in_array('--dry-run', $argv, true);
$prefix = 'uprzejmiedonosze-db/';

echo date('Y-m-d H:i:s') . " — uprzejmiedonosze-db-backup start\n";

$dbPath = \ROOT . 'db/store.sqlite';
if (!file_exists($dbPath)) {
    echo "ERROR: DB not found at $dbPath\n";
    \telemetry\log('cron_db_backup', null, ['status' => 'failed']);
    exit(1);
}

$tmpPath = "/tmp/store-backup-" . date('Y-m-d') . ".sqlite";
@unlink($tmpPath);

echo "Backup: $dbPath → $tmpPath\n";
$db = new \SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$dest = new \SQLite3($tmpPath);
$backupResult = $db->backup($dest);
$dest->close();
$db->close();

if (!$backupResult) {
    @unlink($tmpPath);
    echo "ERROR: SQLite backup failed\n";
    \telemetry\log('cron_db_backup', null, ['status' => 'failed']);
    exit(1);
}

$backupSize = filesize($tmpPath);
echo "Backup created: " . number_format($backupSize) . " bytes\n";

$backupBucket = \BACKUP_B2_BUCKET ?: '';
if (!$backupBucket) {
    echo "WARNING: BACKUP_B2_BUCKET not set, skipping upload\n";
    @unlink($tmpPath);
    \telemetry\log('cron_db_backup', null, ['status' => 'skipped_no_bucket']);
    exit(0);
}

$recipient = \AGE_RECIPIENT ?: '';
if (!$recipient) {
    echo "ERROR: AGE_RECIPIENT not set — refusing to upload an unencrypted backup\n";
    @unlink($tmpPath);
    \telemetry\log('cron_db_backup', null, ['status' => 'failed']);
    exit(1);
}

$client = new S3(
    $backupBucket,
    \BACKUP_B2_KEY,
    \BACKUP_B2_SECRET,
    \B2_ENDPOINT,
    \B2_REGION,
);

$date = date('Y-m-d');
$isSunday = date('w') === '0';
$suffix = $isSunday ? 'weekly' : 'daily';
$b2Key = $prefix . "store-{$date}-{$suffix}.sql.gz.age";

echo "Compressing: $b2Key\n";

$gzPath = $tmpPath . '.gz';
@unlink($gzPath);
$src = fopen($tmpPath, 'rb');
$dst = gzopen($gzPath, 'wb6');
while (!feof($src)) {
    $chunk = fread($src, 65536);
    if ($chunk === false) break;
    gzwrite($dst, $chunk);
}
fclose($src);
gzclose($dst);

echo "Compressed: " . number_format(filesize($gzPath)) . " bytes\n";

echo "Encrypting with age\n";
$agePath = $gzPath . '.age';
@unlink($agePath);
$encCmd = 'age -r ' . escapeshellarg($recipient)
    . ' --output=' . escapeshellarg($agePath)
    . ' ' . escapeshellarg($gzPath);
$encOut = '';
$encCode = -1;
runCommand($encCmd, $encOut, $encCode);
if ($encCode !== 0 || !is_file($agePath)) {
    @unlink($tmpPath);
    @unlink($gzPath);
    @unlink($agePath);
    echo "ERROR: age encryption failed: $encOut\n";
    \telemetry\log('cron_db_backup', null, ['status' => 'failed']);
    exit(1);
}

// Sanity check: age files always start with the version header.
$ageFh = fopen($agePath, 'rb');
$ageHeader = fread($ageFh, 22);
fclose($ageFh);
if ($ageHeader !== "age-encryption.org/v1\n") {
    @unlink($tmpPath);
    @unlink($gzPath);
    @unlink($agePath);
    echo "ERROR: age output is missing the v1 header — aborting\n";
    \telemetry\log('cron_db_backup', null, ['status' => 'failed']);
    exit(1);
}

$finalSize = filesize($agePath);
echo "Encrypted: " . number_format($finalSize) . " bytes\n";

if ($dryRun) {
    echo "DRY-RUN: skipping upload and retention cleanup\n";
    @unlink($tmpPath);
    @unlink($gzPath);
    @unlink($agePath);
    \telemetry\log('cron_db_backup', null, ['status' => 'dry_run']);
    exit(0);
}

$ok = $client->uploadMultipartPrivate($agePath, $b2Key);
@unlink($tmpPath);
@unlink($gzPath);
@unlink($agePath);

if (!$ok) {
    echo "ERROR: B2 upload failed\n";
    \telemetry\log('cron_db_backup', null, ['status' => 'failed']);
    exit(1);
}

echo "Uploaded: $b2Key (" . number_format($finalSize) . " bytes)\n";

echo "Retention cleanup...\n";
$allKeys = $client->listObjects($prefix);
$deleted = 0;

$dailyThreshold = (new \DateTime())->sub(new \DateInterval('P7D'));
$weeklyThreshold = (new \DateTime())->sub(new \DateInterval('P4W'));

usort($allKeys, 'strcmp');

$weeklyDates = [];
foreach ($allKeys as $key) {
    if (preg_match('/store-(\d{4}-\d{2}-\d{2})-weekly\.sql\.gz(\.age)?$/', $key, $m)) {
        $weeklyDates[$key] = $m[1];
    }
}
arsort($weeklyDates);
$keepWeekly = array_slice(array_keys($weeklyDates), 0, 4);

foreach ($allKeys as $key) {
    if (preg_match('/store-(\d{4}-\d{2}-\d{2})-daily\.sql\.gz(\.age)?$/', $key, $m)) {
        $fileDate = \DateTime::createFromFormat('Y-m-d', $m[1]);
        if ($fileDate < $dailyThreshold) {
            echo "  Deleting old daily: $key\n";
            $client->delete($key);
            $deleted++;
        }
    } elseif (preg_match('/store-(\d{4}-\d{2}-\d{2})-weekly\.sql\.gz(\.age)?$/', $key, $m)) {
        if (!in_array($key, $keepWeekly, true)) {
            $fileDate = \DateTime::createFromFormat('Y-m-d', $m[1]);
            if ($fileDate < $weeklyThreshold) {
                echo "  Deleting old weekly: $key\n";
                $client->delete($key);
                $deleted++;
            }
        }
    }
}

echo "Deleted $deleted old backup(s)\n";
echo date('Y-m-d H:i:s') . " — uprzejmiedonosze-db-backup done\n";
\telemetry\log('cron_db_backup', null, ['status' => 'success']);

/**
 * Runs an external command, capturing combined output and exit code.
 */
function runCommand(string $cmd, string &$output, int &$exitCode): void {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        $exitCode = -1;
        $output = 'proc_open failed';
        return;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    $output = trim($stdout . "\n" . $stderr);
}
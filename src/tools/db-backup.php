<?PHP namespace admin;

require_once(__DIR__ . '/common.php');

use store\S3;

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

$backupKey = getenv('BACKUP_B2_BUCKET') ?: '';
if (!$backupKey) {
    echo "WARNING: BACKUP_B2_BUCKET not set, skipping upload\n";
    @unlink($tmpPath);
    \telemetry\log('cron_db_backup', null, ['status' => 'skipped_no_bucket']);
    exit(0);
}

$client = new S3(
    $backupKey,
    getenv('BACKUP_B2_KEY') ?: '',
    getenv('BACKUP_B2_SECRET') ?: '',
    B2_ENDPOINT,
    B2_REGION,
);

$date = date('Y-m-d');
$isSunday = date('w') === '0';
$suffix = $isSunday ? 'weekly' : 'daily';
$b2Key = "uprzejmiedonosze-db/store-{$date}-{$suffix}.sql.gz";

echo "Compressing + uploading to B2: $b2Key\n";

$gzPath = $tmpPath . '.gz';
$src = fopen($tmpPath, 'rb');
$dst = gzopen($gzPath, 'wb9');
while (!feof($src)) {
    gzwrite($dst, fread($src, 65536));
}
fclose($src);
gzclose($dst);

$ok = $client->uploadPrivate($gzPath, $b2Key);
$finalSize = filesize($gzPath);
@unlink($tmpPath);
@unlink($gzPath);

if (!$ok) {
    echo "ERROR: B2 upload failed\n";
    \telemetry\log('cron_db_backup', null, ['status' => 'failed']);
    exit(1);
}

echo "Uploaded: $b2Key (" . number_format($finalSize) . " bytes)\n";

echo "Retention cleanup...\n";
$allKeys = $client->listObjects('uprzejmiedonosze-db/store-');
$deleted = 0;

$dailyThreshold = (new \DateTime())->sub(new \DateInterval('P7D'));
$weeklyThreshold = (new \DateTime())->sub(new \DateInterval('P4W'));

usort($allKeys, 'strcmp');

$weeklyDates = [];
foreach ($allKeys as $key) {
    if (preg_match('/uprzejmiedonosze-db\/store-(\d{4}-\d{2}-\d{2})-weekly\.sql\.gz$/', $key, $m)) {
        $weeklyDates[$key] = $m[1];
    }
}
arsort($weeklyDates);
$keepWeekly = array_slice(array_keys($weeklyDates), 0, 4);

foreach ($allKeys as $key) {
    if (preg_match('/uprzejmiedonosze-db\/store-(\d{4}-\d{2}-\d{2})-daily\.sql\.gz$/', $key, $m)) {
        $fileDate = \DateTime::createFromFormat('Y-m-d', $m[1]);
        if ($fileDate < $dailyThreshold) {
            echo "  Deleting old daily: $key\n";
            $client->delete($key);
            $deleted++;
        }
    } elseif (preg_match('/uprzejmiedonosze-db\/store-(\d{4}-\d{2}-\d{2})-weekly\.sql\.gz$/', $key, $m)) {
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

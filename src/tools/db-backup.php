<?PHP namespace admin;

require_once(__DIR__ . '/common.php');

use store\S3;

$dryRun = in_array('--dry-run', $argv, true);
$prefix = 'uprzejmiedonosze-db/';

echo date('Y-m-d H:i:s') . " — uprzejmiedonosze-db-backup start\n";

sweepStaleTempFiles();

$backupBucket = \BACKUP_B2_BUCKET ?: '';
if (!$backupBucket) {
    echo "WARNING: BACKUP_B2_BUCKET not set, skipping upload\n";
    \telemetry\log('cron_db_backup', null, ['status' => 'skipped_no_bucket']);
    exit(0);
}

$recipient = \AGE_RECIPIENT ?: '';
if (!$recipient) {
    echo "ERROR: AGE_RECIPIENT not set — refusing to upload an unencrypted backup\n";
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
$suffix = date('w') === '0' ? 'weekly' : 'daily';

$dbDir = \ROOT . 'db/';
$dbs = glob($dbDir . '*.sqlite');
if (!$dbs) {
    echo "ERROR: no SQLite databases found in $dbDir\n";
    \telemetry\log('cron_db_backup', null, ['status' => 'failed']);
    exit(1);
}
echo "Databases: " . implode(', ', array_map('basename', $dbs)) . "\n";

$okAll = true;
foreach ($dbs as $dbPath) {
    $base = basename($dbPath, '.sqlite');
    echo "\n== $base ==\n";
    if (backupDb($dbPath, $base, $client, $prefix, $date, $suffix, $dryRun) !== true) {
        $okAll = false;
    }
}

if (!$dryRun) {
    echo "\nRetention cleanup...\n";
    retentionCleanup($client, $prefix);
}

echo "\n" . date('Y-m-d H:i:s') . " — uprzejmiedonosze-db-backup done\n";
\telemetry\log('cron_db_backup', null, ['status' => $okAll ? 'success' : 'failed']);
exit($okAll ? 0 : 1);

/**
 * Backs up a single SQLite DB: online backup → gzip → age-encrypt → upload.
 * Returns true on success (dry-run: true after encrypt, before upload).
 * Temp files are always cleaned up before returning, including on exceptions.
 */
function backupDb(
    string $dbPath,
    string $base,
    S3 $client,
    string $prefix,
    string $date,
    string $suffix,
    bool $dryRun,
): bool {
    if (!is_file($dbPath)) {
        echo "  ERROR: DB not found at $dbPath\n";
        return false;
    }

    $tmpPath = "/tmp/{$base}-backup-{$date}.sqlite";
    $gzPath  = $tmpPath . '.gz';
    $agePath = $gzPath . '.age';

    $cleanup = static function () use ($tmpPath, $gzPath, $agePath): void {
        foreach ([$agePath, $gzPath, $tmpPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    };

    try {
        $db = new \SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $dest = new \SQLite3($tmpPath);
        $backupResult = $db->backup($dest);
        $dest->close();
        $db->close();

        if (!$backupResult) {
            throw new \RuntimeException("SQLite backup failed for $dbPath");
        }

        echo "  Backup created: " . number_format(filesize($tmpPath)) . " bytes\n";

        $b2Key = $prefix . "{$base}-{$date}-{$suffix}.sql.gz.age";

        $src = fopen($tmpPath, 'rb');
        $dst = gzopen($gzPath, 'wb6');
        while (!feof($src)) {
            $chunk = fread($src, 65536);
            if ($chunk === false) break;
            gzwrite($dst, $chunk);
        }
        fclose($src);
        gzclose($dst);
        echo "  Compressed: " . number_format(filesize($gzPath)) . " bytes\n";

        echo "  Encrypting with age\n";
        $encCmd = 'age -r ' . escapeshellarg(\AGE_RECIPIENT)
            . ' --output=' . escapeshellarg($agePath)
            . ' ' . escapeshellarg($gzPath);
        $encOut = '';
        $encCode = -1;
        runCommand($encCmd, $encOut, $encCode);
        if ($encCode !== 0 || !is_file($agePath)) {
            throw new \RuntimeException("age encryption failed: $encOut");
        }

        // Sanity check: age files always start with the version header.
        $ageFh = fopen($agePath, 'rb');
        $ageHeader = fread($ageFh, 22);
        fclose($ageFh);
        if ($ageHeader !== "age-encryption.org/v1\n") {
            throw new \RuntimeException('age output is missing the v1 header — aborting');
        }

        $finalSize = filesize($agePath);
        echo "  Encrypted: " . number_format($finalSize) . " bytes\n";

        if ($dryRun) {
            echo "  [DRY-RUN] skipping upload\n";
            return true;
        }

        $ok = false;
        for ($attempt = 1; $attempt <= 3 && !$ok; $attempt++) {
            $ok = $client->uploadMultipartPrivate($agePath, $b2Key);
            if (!$ok && $attempt < 3) {
                $backoff = $attempt * 5;
                echo "  Upload failed (attempt $attempt/3) — retrying in {$backoff}s\n";
                \usleep($backoff * 1000000);
            }
        }
        if (!$ok) {
            throw new \RuntimeException("B2 upload failed for $b2Key after 3 attempts");
        }

        echo "  Uploaded: $b2Key (" . number_format($finalSize) . " bytes)\n";
        return true;
    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        return false;
    } finally {
        $cleanup();
    }
}

/**
 * Removes temp files left behind by interrupted runs (e.g. killed while
 * uploading a multi-GB file). Only touches files matching our naming pattern
 * that are at least an hour old, so a concurrently-running backup is never
 * affected; the current run's files are always removed by backupDb() itself.
 */
function sweepStaleTempFiles(): void {
    $pattern = '/^([a-z0-9-]+)-backup-(19|20)\d{2}-\d{2}-\d{2}\.sqlite(\.gz)?(\.age)?$/';
    $staleBefore = time() - 3600;

    foreach (glob('/tmp/*.sqlite*') ?: [] as $path) {
        $name = basename($path);
        if (!preg_match($pattern, $name)) {
            continue;
        }
        $mtime = filemtime($path);
        if ($mtime === false || $mtime > $staleBefore) {
            continue;
        }
        $size = filesize($path) ?: 0;
        @unlink($path);
        echo "  Cleaned stale temp file: $name (" . number_format($size) . " bytes freed)\n";
    }
}

/**
 * Retention: keep 7 daily + 4 weekly backups per database name.
 * Matches encrypted (.sql.gz.age) and any legacy plain (.sql.gz) keys.
 */
function retentionCleanup(S3 $client, string $prefix): void {
    $allKeys = $client->listObjects($prefix);
    $deleted = 0;

    $dailyThreshold = (new \DateTime())->sub(new \DateInterval('P7D'));
    $weeklyThreshold = (new \DateTime())->sub(new \DateInterval('P4W'));

    // Keep the 4 newest weekly backups per db name.
    $weeklyDatesByDb = [];
    foreach ($allKeys as $key) {
        if (preg_match('/([a-z0-9_-]+)-(\d{4}-\d{2}-\d{2})-weekly\.sql\.gz(\.age)?$/', $key, $m)) {
            $weeklyDatesByDb[$m[1]][$key] = $m[2];
        }
    }
    $keepWeekly = [];
    foreach ($weeklyDatesByDb as $dates) {
        arsort($dates);
        $keepWeekly += array_flip(array_slice(array_keys($dates), 0, 4));
    }

    foreach ($allKeys as $key) {
        if (!preg_match('/([a-z0-9_-]+)-(\d{4}-\d{2}-\d{2})-(daily|weekly)\.sql\.gz(\.age)?$/', $key, $m)) {
            continue;
        }
        $date = \DateTime::createFromFormat('Y-m-d', $m[2]);
        $isWeekly = $m[3] === 'weekly';

        if ($date >= ($isWeekly ? $weeklyThreshold : $dailyThreshold)) continue;
        if ($isWeekly && isset($keepWeekly[$key])) continue;

        echo "  Deleting old {$m[3]}: $key\n";
        $client->delete($key);
        $deleted++;
    }

    echo "Deleted $deleted old backup(s)\n";
}

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
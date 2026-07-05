<?PHP namespace storage;

use store\S3;
use Aws\Exception\AwsException;

function isEnabled(): bool {
    return \isProd() || \isStaging();
}

/**
 * CDN path prefix for this environment.
 * staging → "cdn2stg", everything else (dev + prod) → "cdn2"
 */
function cdnPrefix(): string {
    return \isStaging() ? 'cdn2stg' : 'cdn2';
}

/**
 * Primary backend — all new uploads go here, and reads try this first.
 * Pass $override (e.g. in tests, with a mocked Aws handler) to replace the
 * cached instance.
 */
function b2(?S3 $override = null): S3 {
    static $client = null;
    if ($override !== null) $client = $override;
    if ($client === null) {
        $client = new S3(\B2_BUCKET, \B2_KEY, \B2_SECRET, \B2_ENDPOINT, \B2_REGION);
    }
    return $client;
}

/**
 * Legacy Hetzner backend — read-only fallback during the B2 migration window.
 * Remove once s3_get_fallback telemetry shows zero hits and Hetzner is decommissioned.
 */
function s3(?S3 $override = null): S3 {
    static $client = null;
    if ($override !== null) $client = $override;
    if ($client === null) {
        $client = new S3(\S3_BUCKET, \S3_KEY, \S3_SECRET, \S3_ENDPOINT, \S3_REGION);
    }
    return $client;
}

/**
 * Uploads a local file to B2 with public-read ACL. New files only ever go
 * to B2 — Hetzner is being orphaned, not written to anymore.
 * $key is the object key, e.g. "cdn2/12/abc,ca.jpg".
 */
function upload(string $localPath, string $key): bool {
    if (!isEnabled() || !file_exists($localPath)) return false;
    $ok = b2()->upload($localPath, $key);
    \telemetry\log('b2_put', null, ['status' => $ok ? 'success' : 'failed']);
    return $ok;
}

/**
 * Downloads an object to a local path, trying B2 first and falling back to
 * Hetzner S3 for content that hasn't been migrated yet.
 * Returns true on success, false if storage is not enabled.
 * Throws AwsException on total failure (caller gets the full error).
 */
function download(string $key, string $localPath): bool {
    if (!isEnabled()) return false;
    try {
        b2()->download($key, $localPath);
        \telemetry\log('b2_get', null, ['status' => 'success']);
        return true;
    } catch (AwsException $e) {
        try {
            s3()->download($key, $localPath);
            \telemetry\log('s3_get_fallback', null, ['status' => 'success']);
            return true;
        } catch (AwsException $e2) {
            logger("B2/S3 download failed for $key: " . $e2->getMessage(), true);
            \telemetry\log('b2_get', null, ['status' => 'failed']);
            throw $e2;
        }
    }
}

/**
 * Ensures a file is available at ROOT.$key by downloading it (B2, falling
 * back to Hetzner S3) if missing.
 * No-op when storage is not enabled or the file already exists locally.
 * Retries up to 3 times (2 s gaps) to handle propagation delays.
 * Throws \RuntimeException wrapping the last AwsException on permanent failure.
 */
function ensure_local(string $key): void {
    if (!isEnabled()) return;
    $localPath = ROOT . $key;
    if (file_exists($localPath)) return;
    $dir = dirname($localPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $tmpPath = $localPath . '.tmp.' . getmypid();
    $lastException = null;
    $maxAttempts = 3;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            download($key, $tmpPath);
            rename($tmpPath, $localPath);
            return;
        } catch (AwsException $e) {
            @unlink($tmpPath);
            $lastException = $e;
            if ($attempt < $maxAttempts) {
                // Exponential backoff: 1s, 4s (attempt * attempt seconds, last attempt skips)
                sleep($attempt * $attempt);
            }
        }
    }
    throw new \RuntimeException(
        "B2/S3 ensure_local failed after $maxAttempts attempts: '$key': " . $lastException->getMessage(),
        0,
        $lastException
    );
}

/**
 * Removes a locally cached file that was fetched via ensure_local().
 * No-op when storage is not enabled.
 */
function release_local(string $key): void {
    if (!isEnabled()) return;
    if (!exists($key)) return;
    @unlink(ROOT . $key);
}

/**
 * Returns true if the object exists in B2 or (as fallback) Hetzner S3.
 */
function exists(string $key): bool {
    if (!isEnabled()) return false;
    if (b2()->exists($key)) {
        \telemetry\log('b2_get', null, ['status' => 'success']);
        return true;
    }
    if (s3()->exists($key)) {
        \telemetry\log('s3_get_fallback', null, ['status' => 'success']);
        return true;
    }
    return false;
}

/**
 * Returns the ContentLength of the object — checks B2 first, then Hetzner
 * S3 as fallback — or null if missing from both / storage not enabled.
 */
function remote_size(string $key): ?int {
    if (!isEnabled()) return null;
    $size = b2()->remoteSize($key);
    if ($size !== null) {
        \telemetry\log('b2_get', null, ['status' => 'success']);
        return $size;
    }
    $size = s3()->remoteSize($key);
    if ($size !== null) {
        \telemetry\log('s3_get_fallback', null, ['status' => 'success']);
    }
    return $size;
}

/**
 * Deletes the object from both B2 and Hetzner S3 — it may exist on either
 * during the migration window. Silently ignores missing keys on both.
 */
function delete(string $key): void {
    if (!isEnabled()) return;
    b2()->delete($key);
    s3()->delete($key);
}

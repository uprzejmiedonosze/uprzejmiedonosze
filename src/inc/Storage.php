<?PHP namespace storage;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

function isEnabled(): bool {
    return \isProd() || \isStaging();
}

/**
 * CDN path prefix for this environment.
 * prod → "cdn2", staging → "cdn2stg", dev → "cdn2dev"
 */
function cdnPrefix(): string {
    return 'cdn2' . \environmentSuffix();
}

function client(): S3Client {
    static $client = null;
    if ($client === null) {
        $client = new S3Client([
            'version'                 => 'latest',
            'region'                  => \S3_REGION,
            'endpoint'                => \S3_ENDPOINT,
            'use_path_style_endpoint' => false,
            'credentials'             => [
                'key'    => \S3_KEY,
                'secret' => \S3_SECRET,
            ],
        ]);
    }
    return $client;
}

/**
 * Uploads a local file to S3 with public-read ACL.
 * $key is the S3 object key, e.g. "cdn2/12/abc,ca.jpg".
 */
function upload(string $localPath, string $key): void {
    if (!isEnabled() || !file_exists($localPath)) return;
    try {
        client()->putObject([
            'Bucket'      => \S3_BUCKET,
            'Key'         => $key,
            'SourceFile'  => $localPath,
            'ACL'         => 'public-read',
            'ContentType' => mime_content_type($localPath) ?: 'application/octet-stream',
        ]);
    } catch (AwsException $e) {
        logger("S3 upload failed for $key: " . $e->getMessage(), true);
        throw $e;
    }
}

/**
 * Downloads an S3 object to a local path.
 * Returns true on success, false if S3 is not enabled.
 * Throws AwsException on failure (caller gets the full error).
 */
function download(string $key, string $localPath): bool {
    if (!isEnabled()) return false;
    try {
        client()->getObject([
            'Bucket' => \S3_BUCKET,
            'Key'    => $key,
            'SaveAs' => $localPath,
        ]);
        return true;
    } catch (AwsException $e) {
        logger("S3 download failed for $key: " . $e->getMessage(), true);
        throw $e;
    }
}

/**
 * Ensures a file is available at ROOT.$key by downloading it from S3 if missing.
 * No-op when S3 is not enabled or the file already exists locally.
 * Retries up to 3 times (2 s gaps) to handle S3 propagation delays.
 * Throws \RuntimeException wrapping the last AwsException on permanent failure.
 */
function ensure_local(string $key): void {
    if (!isEnabled()) return;
    $localPath = ROOT . $key;
    if (file_exists($localPath)) return;
    $dir = dirname($localPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $lastException = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            download($key, $localPath);
            return;
        } catch (AwsException $e) {
            $lastException = $e;
            if ($attempt < 3) sleep(1);
        }
    }
    throw new \RuntimeException(
        "S3 ensure_local failed after 3 attempts: '$key': " . $lastException->getMessage(),
        0,
        $lastException
    );
}

/**
 * Removes a locally cached file that was fetched via ensure_local().
 * No-op when S3 is not enabled.
 */
function release_local(string $key): void {
    if (!isEnabled()) return;
    if (!exists($key)) return;
    @unlink(ROOT . $key);
}

/**
 * Returns true if the S3 object exists, false otherwise (including when S3 is disabled).
 */
function exists(string $key): bool {
    if (!isEnabled()) return false;
    try {
        client()->headObject([
            'Bucket' => \S3_BUCKET,
            'Key'    => $key,
        ]);
        return true;
    } catch (AwsException $e) {
        return false;
    }
}

/**
 * Deletes an S3 object. Silently ignores missing keys.
 */
function delete(string $key): void {
    if (!isEnabled()) return;
    try {
        client()->deleteObject([
            'Bucket' => \S3_BUCKET,
            'Key'    => $key,
        ]);
    } catch (AwsException $e) {
        logger("S3 delete failed for $key: " . $e->getMessage(), true);
    }
}

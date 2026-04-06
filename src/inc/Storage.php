<?PHP namespace storage;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

function isEnabled(): bool {
    return isProd();
}

function client(): S3Client {
    static $client = null;
    if ($client === null) {
        $client = new S3Client([
            'version'                 => 'latest',
            'region'                  => S3_REGION,
            'endpoint'                => S3_ENDPOINT,
            'use_path_style_endpoint' => false,
            'credentials'             => [
                'key'    => S3_KEY,
                'secret' => S3_SECRET,
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
            'Bucket'      => S3_BUCKET,
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
 * Returns true on success, false if the object is missing or on error.
 */
function download(string $key, string $localPath): bool {
    if (!isEnabled()) return false;
    try {
        client()->getObject([
            'Bucket' => S3_BUCKET,
            'Key'    => $key,
            'SaveAs' => $localPath,
        ]);
        return true;
    } catch (AwsException $e) {
        logger("S3 download failed for $key: " . $e->getMessage(), true);
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
            'Bucket' => S3_BUCKET,
            'Key'    => $key,
        ]);
    } catch (AwsException $e) {
        logger("S3 delete failed for $key: " . $e->getMessage(), true);
    }
}

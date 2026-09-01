<?PHP namespace store;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Thin wrapper around a single S3-compatible backend (Backblaze B2's
 * S3-compatible API). Knows nothing about which provider it talks to —
 * that policy lives in Storage.php.
 */
class S3 {
    private S3Client $client;

    /**
     * $clientConfig lets callers (tests) override/extend the underlying
     * S3Client config, e.g. ['handler' => $awsMockHandler].
     */
    public function __construct(
        private readonly string $bucket,
        string $key,
        string $secret,
        string $endpoint,
        string $region,
        array $clientConfig = [],
    ) {
        $this->client = new S3Client($clientConfig + [
            'version'                 => 'latest',
            'region'                  => $region,
            'endpoint'                => $endpoint,
            'use_path_style_endpoint' => false,
            'credentials'             => [
                'key'    => $key,
                'secret' => $secret,
            ],
            'retries' => [
                'mode' => 'adaptive',
                'max_attempts' => 3,
            ],
        ]);
    }

    /**
     * Uploads a local file with public-read ACL. $key is the object key,
     * e.g. "cdn2/12/abc,ca.jpg". Caller is responsible for checking the
     * local file exists first.
     */
    public function upload(string $localPath, string $key): bool {
        try {
            $this->client->putObject([
                'Bucket'      => $this->bucket,
                'Key'         => $key,
                'SourceFile'  => $localPath,
                'ACL'         => 'public-read',
                'ContentType' => mime_content_type($localPath) ?: 'application/octet-stream',
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Downloads an object to a local path. Throws AwsException on failure
     * (including "not found") so the caller can decide whether to fall back.
     */
    public function download(string $key, string $localPath): bool {
        $this->client->getObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
            'SaveAs' => $localPath,
        ]);
        return true;
    }

    public function exists(string $key): bool {
        try {
            $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    public function remoteSize(string $key): ?int {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);
            return (int) $result['ContentLength'];
        } catch (AwsException $e) {
            return null;
        }
    }

    /**
     * Uploads a local file without ACL (private by default on B2).
     * Use for backups or any non-public objects.
     */
    public function uploadPrivate(string $localPath, string $key): bool {
        try {
            $this->client->putObject([
                'Bucket'      => $this->bucket,
                'Key'         => $key,
                'SourceFile'  => $localPath,
                'ContentType' => mime_content_type($localPath) ?: 'application/octet-stream',
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Uploads a local file without ACL via the SDK's upload() helper, which
     * transparently switches to a multipart upload for large files (B2 single
     * PUT is limited to ~5 GB). Use for big backups.
     */
    public function uploadMultipartPrivate(string $localPath, string $key): bool {
        try {
            $this->client->upload($this->bucket, $key, fopen($localPath, 'rb'), null, [
                'params'     => ['ContentType' => mime_content_type($localPath) ?: 'application/octet-stream'],
                'concurrency' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->abortFailedMultipart($key, $e);
            return false;
        }
    }

    private function abortFailedMultipart(string $key, \Throwable $e): void {
        if (!$e instanceof \Aws\S3\Exception\S3MultipartUploadException) {
            return;
        }
        $id = $e->getState()->getId();
        if (empty($id['UploadId'])) {
            return;
        }
        try {
            $this->client->abortMultipartUpload([
                'Bucket'   => $this->bucket,
                'Key'      => $key,
                'UploadId' => $id['UploadId'],
            ]);
            logger('Aborted failed B2 multipart upload ' . $id['UploadId'] . ' for ' . $key);
        } catch (\Throwable $ignore) {
            // best-effort cleanup; B2 eventually prunes orphaned uploads
        }
    }

    /**
     * Lists object keys matching a prefix. Returns an array of key strings.
     */
    public function listObjects(string $prefix): array {
        try {
            $keys = [];
            $iterator = $this->client->getIterator('ListObjects', [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ]);
            foreach ($iterator as $object) {
                $keys[] = $object['Key'];
            }
            return $keys;
        } catch (AwsException $e) {
            return [];
        }
    }

    /**
     * Deletes an object. Silently ignores missing keys.
     */
    public function delete(string $key): void {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);
        } catch (AwsException $e) {
            logger("S3-compatible delete failed for '{$this->bucket}/$key': " . $e->getMessage(), true);
        }
    }
}

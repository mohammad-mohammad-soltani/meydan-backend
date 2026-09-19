<?php

declare(strict_types=1);

use Meydan\Core\Storage\LocalStorage;
use Meydan\Core\Storage\MediaPipeline;
use Meydan\Core\Storage\S3Storage;
use Meydan\Core\Storage\MediaLogger;
use Meydan\Core\Storage\StorageFactory;

if (!class_exists('WP_Error')) {
    final class WP_Error
    {
        public function __construct(private string $code, private string $message = '') {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }
}

require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/StorageInterface.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/LocalStorage.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/StorageFactory.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/MediaPipeline.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/S3Storage.php';
require_once __DIR__ . '/../wp-content/plugins/meydan-core/src/Storage/MediaLogger.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/meydan-storage-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
$source = $root . '/source.txt';
file_put_contents($source, 'meydan-storage');

$storage = new LocalStorage($root . '/objects', 'https://media.example.test', 'staging', true, 1024);
$result = $storage->put($source, 'users/7/2026/09/source.txt', 'text/plain');
check($result['key'] === 'staging/users/7/2026/09/source.txt', 'LocalStorage must apply the normalized prefix.');
check($result['size'] === 14, 'LocalStorage must return the uploaded size.');
check($result['checksum'] === hash_file('sha256', $source), 'LocalStorage must return SHA256 metadata.');
check($storage->exists($result['key']), 'LocalStorage must persist the object.');
check($storage->url($result['key']) === 'https://media.example.test/staging/users/7/2026/09/source.txt', 'LocalStorage must build a public URL.');
check($storage->head($result['key'])['size'] === 14, 'LocalStorage HEAD metadata must include size.');
$storage->delete($result['key']);
check(!$storage->exists($result['key']), 'LocalStorage delete must be idempotent.');
$storage->delete($result['key']);
$unsafeRejected = false;
try { (new LocalStorage($root . '/unsafe', 'https://media.example.test'))->put($source, '../escape.txt', 'text/plain'); } catch (RuntimeException) { $unsafeRejected = true; }
check($unsafeRejected, 'Storage keys must reject traversal segments.');

$factoryStorage = StorageFactory::create([
    'MEDIA_STORAGE' => 'local',
    'MEDIA_STORAGE_PREFIX' => 'test//',
    'MEDIA_PUBLIC_URL' => 'https://media.example.test/',
    'MEDIA_STORAGE_LOCAL_ROOT' => $root . '/factory',
]);
check($factoryStorage instanceof LocalStorage, 'StorageFactory must select LocalStorage.');

$pipeline = new MediaPipeline($factoryStorage, 10, ['text/plain'], static fn(string $path): string => 'text/plain');
$tooLarge = $pipeline->validateDeclared('large.txt', 11, 'text/plain');
check($tooLarge !== null && $tooLarge->get_error_code() === 'file_too_large', 'Declared oversized files must fail before staging.');
$badMime = $pipeline->validateDeclared('source.txt', 5, 'image/png');
check($badMime !== null && $badMime->get_error_code() === 'mime_not_allowed', 'Disallowed MIME types must fail before S3.');
$deletionStorage = new class implements \Meydan\Core\Storage\StorageInterface {
    public array $deleted = [];
    public function put(string $localPath, string $key, string $mimeType): array { throw new RuntimeException('unused'); }
    public function delete(string $key): void { $this->deleted[] = $key; }
    public function exists(string $key): bool { return false; }
    public function url(string $key): string { return $key; }
    public function head(string $key): array { throw new RuntimeException('unused'); }
};
(new MediaPipeline($deletionStorage, 10, ['text/plain']))->deleteObjects(['thumb', 'poster'], 'original');
check($deletionStorage->deleted === ['thumb', 'poster', 'original'], 'Deletion must remove derivatives before the original object.');
$failingDeletionStorage = new class implements \Meydan\Core\Storage\StorageInterface {
    public array $deleted = [];
    public function put(string $localPath, string $key, string $mimeType): array { throw new RuntimeException('unused'); }
    public function delete(string $key): void { $this->deleted[] = $key; if ($key === 'poster') throw new RuntimeException('delete failed'); }
    public function exists(string $key): bool { return false; }
    public function url(string $key): string { return $key; }
    public function head(string $key): array { throw new RuntimeException('unused'); }
};
try { (new MediaPipeline($failingDeletionStorage, 10, ['text/plain']))->deleteObjects(['thumb', 'poster'], 'original'); } catch (RuntimeException) {}
check($failingDeletionStorage->deleted === ['thumb', 'poster'], 'Original deletion must stop when a derivative deletion fails.');

$client = new class {
    public array $put = [];
    public array $aborted = [];
    public array $acls = [];
    public array $deleted = [];
    public bool $failPut = false;
    public function putObject(array $args): array { $this->put = $args; if ($this->failPut) throw new RuntimeException('network'); return ['ETag' => '"etag-small"']; }
    public function headObject(array $args): array {
        return ['ContentLength' => 14, 'ContentType' => 'text/plain', 'Metadata' => $this->put['Metadata'], 'ETag' => '"etag-small"'];
    }
    public function doesObjectExistV2(string $bucket, string $key): bool { return false; }
    public function deleteObject(array $args): void { $this->deleted[] = $args; }
    public function abortMultipartUpload(array $args): void { $this->aborted[] = $args; }
    public function listObjectsV2(array $args): array { return ['Contents' => [['Key' => 'production/old.jpg'], ['Key' => 'production/video.mp4']]]; }
    public function putObjectAcl(array $args): void { $this->acls[] = $args; }
};
$s3 = new S3Storage([
    'endpoint' => 'https://s3.example.test', 'region' => 'test-1', 'bucket' => 'media',
    'access_key' => 'key', 'secret_key' => 'secret', 'public_url' => 'https://media.example.test',
    'prefix' => '/production/', 'checksum_enabled' => true, 'checksum_max_bytes' => 1024,
], $client);
$s3Result = $s3->put($source, 'users/7/source.txt', 'text/plain');
check($s3Result['key'] === 'production/users/7/source.txt', 'S3Storage must apply production prefix.');
check(($client->put['ACL'] ?? '') === 'public-read', 'S3Storage uploads must request public-read ACL.');
check($client->put['SourceFile'] === $source, 'S3Storage small uploads must stream from SourceFile.');
check($s3->head($s3Result['key'])['checksum'] === hash_file('sha256', $source), 'S3 HEAD must expose checksum metadata.');
check($s3->head($s3Result['key'])['metadata_size'] === 14, 'S3 HEAD must verify the stored source-size metadata.');
$publicResult = $s3->makePublic();
check($publicResult === ['processed' => 2, 'failed' => 0] && count($client->acls) === 2, 'S3 migration must update ACLs in place for listed objects.');
$unsafeRejected = false;
try { $s3->put($source, '../escape.txt', 'text/plain'); } catch (RuntimeException) { $unsafeRejected = true; }
check($unsafeRejected, 'S3 keys must reject traversal segments.');
$client->failPut = true;
try { $s3->put($source, 'users/7/retry.txt', 'text/plain'); } catch (RuntimeException) {}
check(($client->deleted[0]['Key'] ?? '') === 'production/users/7/retry.txt', 'A failed upload must delete a possibly-created object before retry.');
$client->failPut = false;

$large = $root . '/large.bin';
$handle = fopen($large, 'wb');
ftruncate($handle, 67108865);
fclose($handle);
$failed = false;
$multipartOptions = [];
$multipart = new S3Storage([
    'endpoint' => 'https://s3.example.test', 'region' => 'test-1', 'bucket' => 'media',
    'access_key' => 'key', 'secret_key' => 'secret', 'public_url' => 'https://media.example.test',
    'prefix' => 'production', 'checksum_enabled' => false, 'checksum_max_bytes' => 0,
], $client, static function ($client, $path, array $options) use (&$multipartOptions): never {
    $multipartOptions = $options;
    throw new class('multipart failed') extends RuntimeException {
        public function getUploadId(): string { return 'upload-123'; }
    };
});
try {
    $multipart->put($large, 'videos/large.bin', 'application/octet-stream');
} catch (RuntimeException) {
    $failed = true;
}
check($failed, 'Multipart failure must surface to the caller.');
check(($client->aborted[0]['UploadId'] ?? '') === 'upload-123', 'Multipart failure must abort the incomplete upload.');
check(($multipartOptions['acl'] ?? '') === 'public-read', 'Multipart uploads must request public-read ACL.');

$logFile = $root . '/media.log';
$oldLog = ini_get('error_log');
ini_set('error_log', $logFile);
MediaLogger::log('s3_upload_completed', [
    'attachment_id' => 42, 'storage_key' => 'production/users/7/source.txt', 'status' => 'completed',
    'S3_ACCESS_KEY' => 'must-not-leak', 'signed_url' => 'https://secret.example.test/?signature=secret',
]);
ini_set('error_log', (string) $oldLog);
$logged = file_get_contents($logFile);
check(str_contains($logged, 's3_upload_completed') && str_contains($logged, '"attachment_id":42'), 'MediaLogger must emit structured lifecycle context.');
check(!str_contains($logged, 'must-not-leak') && !str_contains($logged, 'signature=secret'), 'MediaLogger must drop secrets and signed URLs.');

unlink($source);
unlink($large);
unlink($logFile);
echo "media storage tests ok\n";

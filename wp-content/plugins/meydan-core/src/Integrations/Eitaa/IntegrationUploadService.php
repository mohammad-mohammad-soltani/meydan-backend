<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

use Meydan\Core\Uploads\ChunkedUploadService;
use WP_Error;
use WP_REST_Request;

final class IntegrationUploadService
{
    public function __construct(
        private readonly BindingService $bindings = new BindingService(),
        private readonly ChunkedUploadService $uploads = new ChunkedUploadService(),
    ) {
    }

    public function start(array $payload): array|WP_Error
    {
        $squareId = (int) ($payload['square_id'] ?? 0);
        $userId = $this->bindings->ownerForSquare($squareId);
        if ($userId <= 0 || $this->bindings->channelForSquare($squareId) === '') {
            return new WP_Error('eitaa_square_invalid', 'میدان مقصد معتبر نیست.', ['status' => 422]);
        }
        $payload['purpose'] = 'narrative';
        $result = $this->uploads->start($payload, $userId);
        if (!is_wp_error($result)) {
            $result['square_id'] = $squareId;
        }
        return $result;
    }

    public function chunk(string $uploadId, int $index, WP_REST_Request $request): array|WP_Error
    {
        $userId = $this->ownerForUpload($uploadId);
        if ($userId <= 0) {
            return new WP_Error('not_found', 'آپلود فعال پیدا نشد.', ['status' => 404]);
        }
        return $this->uploads->chunk($uploadId, $index, $request, $userId);
    }

    public function complete(string $uploadId): array|WP_Error
    {
        $userId = $this->ownerForUpload($uploadId);
        if ($userId <= 0) {
            return new WP_Error('not_found', 'آپلود فعال پیدا نشد.', ['status' => 404]);
        }
        $squareId = (int) get_user_meta($userId, 'meydan_square_id', true);
        $result = $this->uploads->complete($uploadId, $userId);
        if (!is_wp_error($result) && !empty($result['media_id'])) {
            $mediaId = (int) $result['media_id'];
            add_post_meta($mediaId, 'meydan_import_source', 'eitaa', true);
            add_post_meta($mediaId, 'meydan_eitaa_square_id', $squareId, true);
        }
        return $result;
    }

    public function abort(string $uploadId): array|WP_Error
    {
        $userId = $this->ownerForUpload($uploadId);
        if ($userId <= 0) {
            return new WP_Error('not_found', 'آپلود فعال پیدا نشد.', ['status' => 404]);
        }
        return $this->uploads->abort($uploadId, $userId);
    }

    private function ownerForUpload(string $uploadId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_uploads';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE upload_id=%s AND status='started' AND expires_at>=UTC_TIMESTAMP() LIMIT 1",
            $uploadId
        ));
    }
}

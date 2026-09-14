<?php

declare(strict_types=1);

namespace Meydan\Core\Integrations\Eitaa;

use Meydan\Core\Support\Response;
use WP_Error;
use WP_REST_Request;

final class Controller
{
    private const NS = 'meydan/v1';

    public static function register(): void
    {
        $self = new self();
        register_rest_route(self::NS, '/integrations/eitaa/squares', ['methods' => 'GET', 'callback' => [$self, 'squares'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/integrations/eitaa/known', ['methods' => 'POST', 'callback' => [$self, 'known'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/integrations/eitaa/import', ['methods' => 'POST', 'callback' => [$self, 'import'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/integrations/eitaa/checkpoint', ['methods' => 'POST', 'callback' => [$self, 'checkpoint'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/integrations/eitaa/uploads', ['methods' => 'POST', 'callback' => [$self, 'uploadStart'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/integrations/eitaa/uploads/(?P<upload_id>[A-Za-z0-9_\-]+)/chunks/(?P<index>\d+)', ['methods' => 'PUT', 'callback' => [$self, 'uploadChunk'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/integrations/eitaa/uploads/(?P<upload_id>[A-Za-z0-9_\-]+)/complete', ['methods' => 'POST', 'callback' => [$self, 'uploadComplete'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/integrations/eitaa/uploads/(?P<upload_id>[A-Za-z0-9_\-]+)', ['methods' => 'DELETE', 'callback' => [$self, 'uploadAbort'], 'permission_callback' => '__return_true']);
    }

    public function squares(WP_REST_Request $request): mixed
    {
        if ($error = $this->authorize($request, 0)) return $error;
        $settings = (array) get_option('meydan_api_settings', []);
        $max = (int) ($settings['max_upload_size'] ?? 100 * 1024 * 1024);
        return Response::ok(['items' => (new BindingService())->list(), 'max_upload_size' => $max]);
    }

    public function known(WP_REST_Request $request): mixed
    {
        if ($error = $this->authorize($request, 1024 * 1024)) return $error;
        $payload = $this->json($request);
        return Response::ok(['items' => (new ImportService())->known((array) ($payload['items'] ?? []))]);
    }

    public function import(WP_REST_Request $request): mixed
    {
        if ($error = $this->authorize($request, 8 * 1024 * 1024)) return $error;
        $result = (new ImportService())->upsert($this->json($request));
        return is_wp_error($result) ? $result : Response::ok($result, [], $result['status'] === 'imported' ? 201 : 200);
    }

    public function checkpoint(WP_REST_Request $request): mixed
    {
        if ($error = $this->authorize($request, 64 * 1024)) return $error;
        $payload = $this->json($request);
        $squareId = (int) ($payload['square_id'] ?? 0);
        $timestamp = (int) ($payload['last_success_at'] ?? 0);
        $bindings = new BindingService();
        if ($timestamp <= 0 || $bindings->ownerForSquare($squareId) <= 0) {
            return new WP_Error('eitaa_checkpoint_invalid', 'Checkpoint نامعتبر است.', ['status' => 422]);
        }
        if (!$bindings->checkpoint($squareId, $timestamp)) {
            return new WP_Error('eitaa_checkpoint_failed', 'ذخیره Checkpoint ناموفق بود.', ['status' => 500]);
        }
        return Response::ok(['saved' => true]);
    }

    public function uploadStart(WP_REST_Request $request): mixed
    {
        if ($error = $this->authorize($request, 128 * 1024)) return $error;
        $result = (new IntegrationUploadService())->start($this->json($request));
        return is_wp_error($result) ? $result : Response::ok($result, [], 201);
    }

    public function uploadChunk(WP_REST_Request $request): mixed
    {
        if ($error = $this->authorize($request, 6 * 1024 * 1024)) return $error;
        $result = (new IntegrationUploadService())->chunk((string) $request['upload_id'], (int) $request['index'], $request);
        return is_wp_error($result) ? $result : Response::ok($result);
    }

    public function uploadComplete(WP_REST_Request $request): mixed
    {
        if ($error = $this->authorize($request, 64 * 1024)) return $error;
        $result = (new IntegrationUploadService())->complete((string) $request['upload_id']);
        return is_wp_error($result) ? $result : Response::ok($result);
    }

    public function uploadAbort(WP_REST_Request $request): mixed
    {
        if ($error = $this->authorize($request, 64 * 1024)) return $error;
        $result = (new IntegrationUploadService())->abort((string) $request['upload_id']);
        return is_wp_error($result) ? $result : Response::ok($result);
    }

    private function authorize(WP_REST_Request $request, int $maxBody): ?WP_Error
    {
        if ($maxBody >= 0 && strlen((string) $request->get_body()) > $maxBody) {
            return new WP_Error('eitaa_request_too_large', 'درخواست بیش از حد مجاز بزرگ است.', ['status' => 413]);
        }
        $verified = Auth::verify($request);
        return is_wp_error($verified) ? $verified : null;
    }

    /** @return array<string,mixed> */
    private function json(WP_REST_Request $request): array
    {
        $value = json_decode((string) $request->get_body(), true);
        return is_array($value) ? $value : [];
    }
}

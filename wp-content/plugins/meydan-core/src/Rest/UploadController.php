<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;
use Meydan\Core\Support\Response;use Meydan\Core\Uploads\ChunkedUploadService;use WP_REST_Request;
final class UploadController extends BaseController
{
    public function start(WP_REST_Request $r){if(!is_user_logged_in())return Response::error('unauthenticated','برای آپلود باید وارد شوید.',401);$v=(new ChunkedUploadService())->start($this->json($r),get_current_user_id());return is_wp_error($v)?$this->error($v):Response::ok($v,[],201);}
    public function chunk(WP_REST_Request $r){if(!is_user_logged_in())return Response::error('unauthenticated','برای آپلود باید وارد شوید.',401);$v=(new ChunkedUploadService())->chunk((string)$r['upload_id'],(int)$r['index'],$r,get_current_user_id());return is_wp_error($v)?$this->error($v):Response::ok($v);}
    public function complete(WP_REST_Request $r){if(!is_user_logged_in())return Response::error('unauthenticated','برای آپلود باید وارد شوید.',401);$v=(new ChunkedUploadService())->complete((string)$r['upload_id'],get_current_user_id());return is_wp_error($v)?$this->error($v):Response::ok($v);}
    public function abort(WP_REST_Request $r){if(!is_user_logged_in())return Response::error('unauthenticated','برای آپلود باید وارد شوید.',401);$v=(new ChunkedUploadService())->abort((string)$r['upload_id'],get_current_user_id());return is_wp_error($v)?$this->error($v):Response::ok($v);}
}

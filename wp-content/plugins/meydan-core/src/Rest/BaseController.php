<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;

use Meydan\Core\Support\Response;
use Meydan\Core\Support\Viewer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

abstract class BaseController
{
    protected function json(WP_REST_Request $request): array
    {
        $v=$request->get_json_params();
        return is_array($v)?$v:[];
    }
    protected function auth(): true|WP_Error {return is_user_logged_in()?true:new WP_Error('unauthenticated','برای انجام این عملیات باید وارد شوید.',['status'=>401]);}
    protected function capability(string $cap): true|WP_Error {return current_user_can($cap)?true:new WP_Error('forbidden','دسترسی کافی ندارید.',['status'=>403]);}
    protected function ownerOr(string $cap,int $owner): true|WP_Error {return get_current_user_id()===$owner||current_user_can($cap)?true:new WP_Error('forbidden','دسترسی کافی ندارید.',['status'=>403]);}
    protected function error(WP_Error $e): WP_REST_Response
    {
        $d=$e->get_error_data();$status=is_array($d)?(int)($d['status']??400):400;$fields=is_array($d)?(array)($d['fields']??[]):[];
        return Response::error($e->get_error_code(),$e->get_error_message(),$status,$fields);
    }
    protected function viewer(): Viewer{return Viewer::current();}
    protected function bool(mixed $v): bool{return filter_var($v,FILTER_VALIDATE_BOOLEAN);}
    protected function positive(mixed $v): int{return max(0,(int)$v);}
}

<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Auth\OtpService;
use Meydan\Core\Auth\SessionService;
use Meydan\Core\Notifications\NotificationService;
use Meydan\Core\Support\Crypto;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\SquareActivity;
use Meydan\Core\Support\UserEmails;
use WP_Error;
use WP_REST_Request;

final class AuthController extends BaseController
{
    public function requestOtp(WP_REST_Request $r)
    {
        try {
            $p = $this->json($r);
            $v = (new OtpService())->request((string) ($p['phone'] ?? ''));
            return is_wp_error($v) ? $this->error($v) : Response::ok($v);
        } catch (\Throwable $e) {
            error_log('[meydan-auth] OTP request failed: ' . $e->getMessage());
            AuditLogger::log('otp_request_exception', 'auth', null, null, [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return Response::error(
                'internal_error',
                'ارسال کد ورود با خطای داخلی روبه‌رو شد. دوباره تلاش کنید.',
                500
            );
        }
    }

    public function verifyOtp(WP_REST_Request $r){$p=$this->json($r);$v=(new OtpService())->verify((string)($p['challenge_id']??''),(string)($p['code']??''));return is_wp_error($v)?$this->error($v):Response::ok($v);}
    public function refresh(WP_REST_Request $r){$p=$this->json($r);$v=(new SessionService())->refresh(isset($p['refresh_token'])?(string)$p['refresh_token']:null);return is_wp_error($v)?$this->error($v):Response::ok($v);}
    public function logout(){(new SessionService())->logoutCurrent();return Response::ok(['logged_out'=>true]);}
    public function logoutAll(){if($a=$this->auth()){} if(is_wp_error($a))return $this->error($a);(new SessionService())->logoutAll(get_current_user_id());return Response::ok(['logged_out'=>true]);}

    public function registerUser(WP_REST_Request $r){return $this->register($r,'user');}
    public function registerSquare(WP_REST_Request $r){return $this->register($r,'square');}

    private function register(WP_REST_Request $r,string $type)
    {
        $p=$this->json($r);$token=(string)($p['registration_token']??'');
        $phone=(new OtpService())->consumeRegistrationToken($token);if(is_wp_error($phone))return $this->error($phone);
        $required=$type==='user'?['full_name','province_id','city_id']:['square_name','province_id','city_id','address','latitude','longitude'];
        $fields=[];foreach($required as $key){if(!isset($p[$key])||$p[$key]==='')$fields[$key]='required';}
        if($fields)return Response::error('validation_failed','اطلاعات واردشده معتبر نیست.',422,$fields);
        $login='meydan_internal_'.strtolower(wp_generate_password(20,false,false));
        $display=$type==='user'?sanitize_text_field((string)$p['full_name']):sanitize_text_field((string)$p['square_name']);
        $uid=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(64,true,true),'display_name'=>$display,'user_email'=>UserEmails::placeholderEmail(),'role'=>$type==='user'?'meydan_user':'meydan_square']);
        if(is_wp_error($uid))return $this->error(new WP_Error('registration_failed','ساخت حساب ناموفق بود.',['status'=>500]));
        update_user_meta($uid,'meydan_account_type',$type);update_user_meta($uid,'meydan_phone_hash',Crypto::hash($phone));update_user_meta($uid,'meydan_phone_ciphertext',Crypto::encrypt($phone));
        update_user_meta($uid,'meydan_province_id',(int)$p['province_id']);update_user_meta($uid,'meydan_city_id',(int)$p['city_id']);
        if($type==='user'){
            update_user_meta($uid,'meydan_full_name',$display);update_user_meta($uid,'meydan_avatar_media_id',(int)($p['avatar_media_id']??0));
        }else{
            $sid=wp_insert_post(['post_type'=>'meydan_square','post_status'=>'pending','post_title'=>$display,'post_content'=>wp_kses_post((string)($p['description']??'')),'post_author'=>$uid],true);
            if(is_wp_error($sid)){wp_delete_user($uid);return $this->error(new WP_Error('registration_failed','ساخت میدان ناموفق بود.',['status'=>500]));}
            update_user_meta($uid,'meydan_square_id',$sid);update_post_meta($sid,'meydan_owner_user_id',$uid);update_post_meta($sid,'meydan_approval_status','pending_verification');update_post_meta($sid,'meydan_verified',0);update_post_meta($sid,'meydan_avatar_media_id',(int)($p['avatar_media_id']??0));
            if(array_key_exists('start_date',$p)){$startSaved=SquareActivity::setStartDate((int)$sid,$p['start_date']);if(is_wp_error($startSaved)){wp_delete_post((int)$sid,true);wp_delete_user($uid);return $this->error($startSaved);}}
            update_post_meta($sid,'meydan_contact_name',sanitize_text_field((string)($p['contact_name']??'')));update_post_meta($sid,'meydan_contact_phone',sanitize_text_field((string)($p['contact_phone']??'')));
            global $wpdb;$wpdb->replace($wpdb->prefix.'meydan_square_geo',['square_id'=>$sid,'province_id'=>(int)$p['province_id'],'city_id'=>(int)$p['city_id'],'address'=>sanitize_textarea_field((string)$p['address']),'latitude'=>(float)$p['latitude'],'longitude'=>(float)$p['longitude'],'updated_at'=>current_time('mysql',true)]);
            AuditLogger::log('square_registration','square',$sid,null,['status'=>'pending_verification'],$uid);
        }
        $session=(new SessionService())->issue($uid);if(is_wp_error($session))return $this->error($session);
        return Response::ok(['authenticated'=>true,'access_token'=>$session['access_token'],'expires_in'=>$session['expires_in'],'account'=>['id'=>$uid,'account_type'=>$type]],[],201);
    }
}

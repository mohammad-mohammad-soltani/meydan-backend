<?php
declare(strict_types=1);
namespace Meydan\Core\Integrations\Bale;
use WP_Error;
final class BaleServiceClient {
 public function status():array|WP_Error{return $this->request('GET','/meydan-admin/bale/status');}
 public function sendCode(string $phone):array|WP_Error{return $this->request('POST','/meydan-admin/bale/send-code',['phone'=>$phone]);}
 public function verifyCode(string $id,string $code):array|WP_Error{return $this->request('POST','/meydan-admin/bale/verify-code',['challenge_id'=>$id,'code'=>$code]);}
 public function logout():array|WP_Error{return $this->request('POST','/meydan-admin/bale/logout');}
 private function request(string $method,string $path,array $payload=[]):array|WP_Error { $base=rtrim(self::url(),'/');$secret=Auth::secret();if($base===''||$secret==='')return new WP_Error('bale_unconfigured','آدرس یا کلید اتصال بله تنظیم نشده است.');$body=$method==='GET'?'':(wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}');$t=(string)time();$n=bin2hex(random_bytes(16));$c=strtoupper($method)."\n".$path."\n".$t."\n".$n."\n".hash('sha256',$body);$res=wp_remote_request($base.$path,['method'=>$method,'timeout'=>25,'redirection'=>0,'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json','X-Meydan-Bale-Timestamp'=>$t,'X-Meydan-Bale-Nonce'=>$n,'X-Meydan-Bale-Signature'=>hash_hmac('sha256',$c,$secret)],'body'=>$body]);if(is_wp_error($res))return $res;$status=(int)wp_remote_retrieve_response_code($res);$data=json_decode((string)wp_remote_retrieve_body($res),true);if(!is_array($data)||$status<200||$status>=300||empty($data['ok']))return new WP_Error((string)($data['error']??'bale_service_error'),(string)($data['detail']??$data['message']??'عملیات بله ناموفق بود.'),['status'=>$status?:502]);return $data; }
 private static function url():string{return trim((string)(get_option('meydan_bale_service_url','')?: (defined('BALE_SERVICE_URL')?constant('BALE_SERVICE_URL'):getenv('BALE_SERVICE_URL'))));}
}

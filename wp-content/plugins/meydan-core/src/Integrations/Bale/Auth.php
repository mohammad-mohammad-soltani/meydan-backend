<?php
declare(strict_types=1);
namespace Meydan\Core\Integrations\Bale;
use WP_Error;
use WP_REST_Request;
final class Auth {
 public static function secret(): string { $v=trim((string)get_option('meydan_bale_sync_secret','')); if($v!=='')return $v; if(defined('MEYDAN_BALE_SYNC_SECRET'))return trim((string)constant('MEYDAN_BALE_SYNC_SECRET')); return trim((string)getenv('MEYDAN_BALE_SYNC_SECRET')); }
 public static function verify(WP_REST_Request $r): true|WP_Error { $s=self::secret();$t=trim((string)$r->get_header('X-Meydan-Bale-Timestamp'));$n=trim((string)$r->get_header('X-Meydan-Bale-Nonce'));$sig=strtolower(trim((string)$r->get_header('X-Meydan-Bale-Signature'))); if($s===''||!ctype_digit($t)||$n===''||!preg_match('/^[a-f0-9]{64}$/',$sig)||abs(time()-(int)$t)>300||strlen($n)>128)return new WP_Error('bale_auth_invalid','امضای سرویس بله معتبر نیست.',['status'=>401]); $k='meydan_bale_nonce_'.hash('sha256',$n);if(get_transient($k)!==false)return new WP_Error('bale_auth_replay','درخواست تکراری رد شد.',['status'=>409]);$uri=(string)($_SERVER['REQUEST_URI']??$r->get_route());$path=parse_url($uri,PHP_URL_PATH)?:$r->get_route();$q=parse_url($uri,PHP_URL_QUERY);if($q)$path.='?'.$q;$c=strtoupper($r->get_method())."\n".$path."\n".$t."\n".$n."\n".hash('sha256',(string)$r->get_body());if(!hash_equals(hash_hmac('sha256',$c,$s),$sig))return new WP_Error('bale_auth_invalid','امضای سرویس بله معتبر نیست.',['status'=>401]);set_transient($k,1,600);return true; }
}

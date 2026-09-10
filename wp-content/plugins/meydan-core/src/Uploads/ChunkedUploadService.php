<?php

declare(strict_types=1);
namespace Meydan\Core\Uploads;

use Meydan\Core\Support\Crypto;
use WP_Error;
use WP_REST_Request;

final class ChunkedUploadService
{
    public const CHUNK_SIZE=5242880;
    private const BAD=['php','php3','php4','php5','phtml','phar','exe','sh','bash','bat','cmd','com','msi','dll','so','cgi','pl','py','rb'];
    public function start(array $p,int $uid):array|WP_Error
    {
        $name=sanitize_file_name((string)($p['filename']??''));$mime=sanitize_mime_type((string)($p['mime_type']??''));$size=(int)($p['size']??0);$purpose=sanitize_key((string)($p['purpose']??''));
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$max=(int)((array)get_option('meydan_api_settings',[]))['max_upload_size'];if($max<=0)$max=100*1024*1024;
        if($name===''||$size<=0||$size>$max||in_array($ext,self::BAD,true)||!in_array($purpose,['narrative','content','avatar','cover','creator','square'],true))return new WP_Error('validation_failed','مشخصات فایل معتبر نیست.',['status'=>422]);
        $allowed=apply_filters('meydan_allowed_upload_mimes',get_allowed_mime_types());$check=wp_check_filetype($name,$allowed);if(empty($check['type'])||($mime!==''&&$check['type']!==$mime)||((in_array($purpose,['avatar','cover'],true))&&!str_starts_with($check['type'],'image/')))return new WP_Error('validation_failed','برای آواتار و کاور فقط تصویر مجاز است.',['status'=>422,'fields'=>['mime_type'=>'invalid']]);
        $upload=Crypto::randomToken(18,'upl_');global $wpdb;$wpdb->insert($wpdb->prefix.'meydan_uploads',['upload_id'=>$upload,'user_id'=>$uid,'filename'=>$name,'mime_type'=>$check['type'],'size'=>$size,'purpose'=>$purpose,'chunk_size'=>self::CHUNK_SIZE,'status'=>'started','created_at'=>current_time('mysql',true),'expires_at'=>gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS)]);
        if(!$wpdb->insert_id)return new WP_Error('internal_error','شروع آپلود ناموفق بود.',['status'=>500]);$dir=$this->dir($upload);wp_mkdir_p($dir);$this->denyExecution($dir);
        return ['upload_id'=>$upload,'mode'=>'chunked','chunk_size'=>self::CHUNK_SIZE];
    }
    public function chunk(string $uploadId,int $index,WP_REST_Request $r,int $uid):array|WP_Error
    {
        $row=$this->row($uploadId,$uid);if(is_wp_error($row))return $row;if($index<0||$index>10000)return new WP_Error('validation_failed','شماره قطعه معتبر نیست.',['status'=>422]);$body=$r->get_body();if($body===''||strlen($body)>self::CHUNK_SIZE)return new WP_Error('validation_failed','اندازه قطعه معتبر نیست.',['status'=>422]);$file=$this->dir($uploadId).'/'.sprintf('%06d',$index).'.part';if(file_put_contents($file,$body,LOCK_EX)===false)return new WP_Error('internal_error','ذخیره قطعه ناموفق بود.',['status'=>500]);return ['upload_id'=>$uploadId,'index'=>$index,'received'=>strlen($body)];
    }
    public function complete(string $uploadId,int $uid):array|WP_Error
    {
        $row=$this->row($uploadId,$uid);if(is_wp_error($row))return $row;$parts=glob($this->dir($uploadId).'/*.part')?:[];sort($parts,SORT_STRING);if(!$parts)return new WP_Error('upload_incomplete','هیچ قطعه‌ای دریافت نشده است.',['status'=>409]);$tmp=$this->dir($uploadId).'/assembled.upload';$out=fopen($tmp,'wb');$total=0;foreach($parts as $part){$in=fopen($part,'rb');while(!feof($in)){$buf=fread($in,1024*1024);$total+=strlen($buf);fwrite($out,$buf);}fclose($in);}fclose($out);if($total!==(int)$row->size){@unlink($tmp);return new WP_Error('upload_incomplete','اندازه فایل نهایی با مقدار اعلام‌شده برابر نیست.',['status'=>409]);}
        $check=wp_check_filetype_and_ext($tmp,(string)$row->filename);if(empty($check['type'])||$check['type']!==(string)$row->mime_type){@unlink($tmp);return new WP_Error('validation_failed','MIME واقعی فایل با نوع اعلام‌شده سازگار نیست.',['status'=>422]);}
        $bits=wp_upload_bits((string)$row->filename,null,(string)file_get_contents($tmp));if(!empty($bits['error']))return new WP_Error('internal_error','انتقال فایل نهایی ناموفق بود.',['status'=>500]);$aid=wp_insert_attachment(['post_author'=>$uid,'post_mime_type'=>$check['type'],'post_title'=>sanitize_text_field(pathinfo((string)$row->filename,PATHINFO_FILENAME)),'post_status'=>'inherit'],$bits['file']);if(is_wp_error($aid))return $aid;add_post_meta($aid,'meydan_upload_owner_user_id',$uid,true);add_post_meta($aid,'meydan_upload_purpose',(string)$row->purpose,true);require_once ABSPATH.'wp-admin/includes/image.php';$meta=wp_generate_attachment_metadata($aid,$bits['file']);if($meta)wp_update_attachment_metadata($aid,$meta);global $wpdb;$wpdb->update($wpdb->prefix.'meydan_uploads',['status'=>'completed'],['id'=>(int)$row->id]);$this->cleanup($uploadId);return ['media_id'=>(int)$aid,'type'=>$this->kind($check['type']),'url'=>(string)$bits['url'],'size'=>$total];
    }
    public function abort(string $uploadId,int $uid):array|WP_Error{$row=$this->row($uploadId,$uid);if(is_wp_error($row))return $row;global $wpdb;$wpdb->update($wpdb->prefix.'meydan_uploads',['status'=>'aborted'],['id'=>(int)$row->id]);$this->cleanup($uploadId);return ['aborted'=>true];}
    private function row(string $id,int $uid):mixed{global $wpdb;$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_uploads WHERE upload_id=%s AND user_id=%d AND status='started' AND expires_at>=UTC_TIMESTAMP()",$id,$uid));return $r?:new WP_Error('not_found','آپلود فعال پیدا نشد.',['status'=>404]);}
    private function dir(string $id):string{$u=wp_upload_dir();return trailingslashit($u['basedir']).'meydan-chunks/'.sanitize_file_name($id);}
    private function denyExecution(string $dir):void{@file_put_contents($dir.'/.htaccess',"Options -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\nRequire all denied\n</FilesMatch>\n");@file_put_contents($dir.'/index.html','');}
    private function cleanup(string $id):void{$d=$this->dir($id);foreach(glob($d.'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($d);}
    private function kind(string $m):string{return str_starts_with($m,'image/')?'image':(str_starts_with($m,'video/')?'video':(str_starts_with($m,'audio/')?'audio':(str_contains($m,'pdf')||str_contains($m,'word')||str_contains($m,'presentation')?'document':'file')));}
}

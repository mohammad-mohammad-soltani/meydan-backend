<?php

declare(strict_types=1);
namespace Meydan\Core\Admin;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Auth\OtpService;
use Meydan\Core\Auth\SmsProvider;
use Meydan\Core\Domain\CreatorService;
use Meydan\Core\Domain\MediaOutletService;
use Meydan\Core\Notifications\NotificationService;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Serializer;
use Meydan\Core\Support\Stats;

final class Admin
{
 private static ?self $instance=null;
 public static function instance():self{return self::$instance??=new self();}
 public function register():void
 {
  add_action('admin_menu',[$this,'menus']);add_action('add_meta_boxes',[$this,'metaBoxes']);add_action('save_post',[$this,'saveMeta'],10,2);add_action('admin_init',[$this,'actions']);add_action('show_user_profile',[$this,'userFields']);add_action('edit_user_profile',[$this,'userFields']);add_action('personal_options_update',[$this,'saveUser']);add_action('edit_user_profile_update',[$this,'saveUser']);add_action('set_user_role',[$this,'syncAccountTypeForRole'],10,2);add_action('admin_enqueue_scripts',[$this,'assets']);add_action('admin_enqueue_scripts',[$this,'avatarAssets']);add_action('admin_enqueue_scripts',[$this,'contentAssets']);add_action('admin_enqueue_scripts',[$this,'creatorAssets']);add_action('admin_enqueue_scripts',[$this,'outletAssets']);add_action('admin_enqueue_scripts',[$this,'reflectionAssets']);add_action('admin_head',[$this,'styles']);
 }
 public function menus():void
 {
  add_menu_page('میدان','میدان','read_meydan','meydan',[$this,'dashboard'],'dashicons-networking',3);
  $this->cpt('meydan_narrative','روایت‌ها','publish_meydan_narratives');$this->cpt('meydan_content','محتوا','manage_meydan_content');$this->cpt('meydan_creator','تولیدکنندگان','manage_meydan_creators');$this->cpt('meydan_media_outlet','رسانه‌ها','manage_meydan_media_reflections');$this->cpt('meydan_square','میدان‌ها','manage_meydan_squares');
  add_submenu_page('meydan','درخواست‌های تأیید میدان','درخواست‌های تأیید میدان','verify_meydan_squares','meydan-square-approvals',[$this,'squareApprovals']);add_submenu_page('meydan','نقشه میدان‌ها','نقشه میدان‌ها','manage_meydan_squares','meydan-map',[$this,'map']);
  $this->cpt('meydan_initiative','ابتکارها','manage_meydan_initiatives');$this->cpt('meydan_campaign','کمپین‌ها','manage_meydan_campaigns');
  add_submenu_page('meydan','درخواست سخنران','درخواست سخنران','manage_meydan_speaker_requests','meydan-speaker-requests',[$this,'speakerRequests']);add_submenu_page('meydan','بازتاب‌های رسانه‌ای','بازتاب‌های رسانه‌ای','manage_meydan_media_reflections','meydan-reflections',[$this,'reflections']);add_submenu_page('meydan','کامنت‌ها','کامنت‌ها','moderate_meydan_narratives','edit-comments.php?comment_type=meydan_comment');add_submenu_page('meydan','نوتیفیکیشن‌ها','نوتیفیکیشن‌ها','manage_meydan_notifications','meydan-notifications',[$this,'notifications']);add_submenu_page('meydan','اعضای ابتکار','اعضای ابتکار','manage_meydan_initiatives','meydan-initiative-members',[$this,'initiativeMembers']);add_submenu_page('meydan','استان‌ها و شهرها','استان‌ها و شهرها','manage_options','meydan-geo',[$this,'geo']);add_submenu_page('meydan','نشست‌ها','نشست‌ها','manage_options','meydan-sessions',[$this,'sessions']);add_submenu_page('meydan','آمار','آمار','manage_meydan_stats','meydan-stats',[$this,'stats']);add_submenu_page('meydan','تنظیمات','تنظیمات','manage_options','meydan-settings',[$this,'settings']);add_submenu_page('meydan','گزارش تغییرات','گزارش تغییرات','view_meydan_audit_log','meydan-audit',[$this,'audit']);
 }
 private function cpt(string $type,string $label,string $cap):void{add_submenu_page('meydan',$label,$label,$cap,'edit.php?post_type='.$type);}
 public function dashboard():void{Dashboard::render();}
 public function metaBoxes():void
 {
  add_meta_box('meydan-narrative','جزئیات میدان',[$this,'narrativeBox'],'meydan_narrative','normal','high');add_meta_box('meydan-reflections','بازتاب‌های رسانه‌ای',[$this,'reflectionBox'],'meydan_narrative','normal','high');add_meta_box('meydan-content','جزئیات محتوا',[$this,'contentBox'],'meydan_content','normal','high');add_meta_box('meydan-creator','جزئیات تولیدکننده',[$this,'creatorBox'],'meydan_creator','normal','high');add_meta_box('meydan-media-outlet','جزئیات رسانه',[$this,'mediaOutletBox'],'meydan_media_outlet','normal','high');add_meta_box('meydan-square','جزئیات میدان و موقعیت',[$this,'squareBox'],'meydan_square','normal','high');add_meta_box('meydan-initiative','جزئیات ابتکار',[$this,'initiativeBox'],'meydan_initiative','normal','high');add_meta_box('meydan-campaign','جزئیات کمپین',[$this,'campaignBox'],'meydan_campaign','normal','high');
 }
 private function nonce():void{wp_nonce_field('meydan_save_meta','meydan_meta_nonce');}
 public function narrativeBox(\WP_Post $p):void{$this->nonce();$this->select('meydan_author_actor_type','نوع نویسنده',(string)get_post_meta($p->ID,'meydan_author_actor_type',true),['user'=>'User','square'=>'Square']);$this->input('meydan_author_actor_id','شناسه Actor',(string)get_post_meta($p->ID,'meydan_author_actor_id',true),'number');$this->input('meydan_initiative_id','Initiative ID',(string)get_post_meta($p->ID,'meydan_initiative_id',true),'number');$this->check('meydan_is_echo','Echo',(bool)get_post_meta($p->ID,'meydan_is_echo',true));$this->attachments($p->ID);$s=Stats::narrative($p->ID);echo '<p><b>Stats:</b> '.esc_html(wp_json_encode($s,JSON_UNESCAPED_UNICODE)).'</p><p><a href="'.esc_url(admin_url('edit-comments.php?comment_type=meydan_comment&p='.$p->ID)).'">کامنت‌ها</a></p>';}
 public function reflectionBox(\WP_Post $p):void
 {
  if($p->post_status==='auto-draft'||$p->ID<=0){echo '<p class="meydan-help">برای ثبت بازتاب رسانه‌ای، ابتدا روایت را ذخیره کنید.</p>';return;}
  global $wpdb;
  $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_media_reflections WHERE narrative_id=%d ORDER BY position ASC,id ASC",$p->ID),ARRAY_A)?:[];
  $outlets=get_posts(['post_type'=>'meydan_media_outlet','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
  if(!$outlets){echo '<p class="meydan-help">هنوز رسانه‌ای تعریف نشده است. از منوی «رسانه‌ها» ابتدا رسانه‌ها را اضافه کنید.</p>';return;}
  $options='';
  foreach($outlets as $o)$options.='<option value="'.esc_attr((string)$o->ID).'">'.esc_html($o->post_title).'</option>';
  echo '<div class="meydan-content-gui meydan-reflection-gui"><p>رسانه، تیتر بازتاب و لینک را وارد کنید. برای تغییر ترتیب نمایش، ردیف‌ها را با دسته‌ی <span aria-hidden="true">↕</span> جابه‌جا کنید؛ ترتیب ذخیره‌شده در API همان ترتیب خروجی است.</p><div id="meydan-reflection-list" data-outlets="'.esc_attr($options).'">';
  foreach($rows as $row)echo $this->reflectionRow((int)$row['id'],(int)($row['outlet_id']??0),(string)$row['title'],(string)$row['url'],$outlets);
  echo '</div><button type="button" class="button button-primary" id="meydan-reflection-add">افزودن بازتاب</button><p class="meydan-reflection-status" id="meydan-reflection-status" role="status" aria-live="polite"></p></div>';
 }
 private function reflectionRow(int $id,int $outletId,string $title,string $url,array $outlets):string
 {
  $select='<select data-field="outlet_id">';
  foreach($outlets as $o)$select.='<option value="'.esc_attr((string)$o->ID).'" '.selected($outletId,(int)$o->ID,false).'>'.esc_html($o->post_title).'</option>';
  $select.='</select>';
  return '<div class="meydan-reflection-row" data-id="'.esc_attr((string)$id).'" data-dirty="0"><span class="meydan-drag-handle" aria-hidden="true">↕</span>'.$select.'<input data-field="title" type="text" placeholder="تیتر بازتاب" value="'.esc_attr($title).'"><input data-field="url" type="url" inputmode="url" placeholder="https://..." value="'.esc_attr($url).'"><span class="meydan-reflection-actions"><button type="button" class="button button-primary meydan-reflection-save is-hidden">ذخیره</button><button type="button" class="button-link-delete meydan-reflection-remove">حذف</button></span></div>';
 }
 public function mediaOutletBox(\WP_Post $p):void
 {
  $this->nonce();
  $id=$p->ID;
  $mediaId=(int)get_post_meta($id,'meydan_avatar_media_id',true);
  $image=$mediaId>0&&wp_attachment_is_image($mediaId)?wp_get_attachment_image($mediaId,[120,120],false,['class'=>'meydan-creator-avatar-img']):'';
  echo '<div class="meydan-creator-avatar"><div class="meydan-creator-avatar-preview" id="meydan-outlet-avatar-preview">'.($image?:'<span class="meydan-creator-avatar-empty">بدون آواتار</span>').'</div><div class="meydan-creator-avatar-actions"><span class="meydan-label">آواتار رسانه</span><p>تصویر از کتابخانه رسانه انتخاب یا همان‌جا آپلود می‌شود و در API به شکل <code>avatar_url</code> برمی‌گردد.</p><input type="hidden" name="meydan_avatar_media_id" id="meydan-outlet-avatar-id" value="'.esc_attr((string)$mediaId).'"><button type="button" class="button button-primary" id="meydan-outlet-avatar-select">انتخاب یا آپلود تصویر</button> <button type="button" class="button" id="meydan-outlet-avatar-remove"'.($mediaId>0?'':' disabled').'>حذف آواتار</button></div></div>';
  echo '<div class="meydan-creator-grid">';
  $this->field('meydan_website','لینک سایت',(string)get_post_meta($id,'meydan_website',true),'آدرس کامل با https وارد شود.');
  echo '</div><div class="meydan-creator-grid">';
  $this->field('meydan_bale','لینک بله',(string)get_post_meta($id,'meydan_bale',true),'کانال یا پیام‌رسان بله.');
  $this->field('meydan_eitaa','لینک ایتا',(string)get_post_meta($id,'meydan_eitaa',true),'کانال یا پیام‌رسان ایتا.');
  echo '</div>';
 }
 public function contentBox(\WP_Post $p):void{$this->nonce();$this->select('meydan_format','Format',(string)get_post_meta($p->ID,'meydan_format',true)?:'mixed',array_combine(['text','image','gallery','audio','video','pdf','docx','pptx','zip','file','external','mixed'],['text','image','gallery','audio','video','PDF','DOCX','PPTX','ZIP','File','External','Mixed']));$this->textarea('meydan_usage_note','Usage Note',(string)get_post_meta($p->ID,'meydan_usage_note',true));$this->check('meydan_featured','Featured',(bool)get_post_meta($p->ID,'meydan_featured',true));$this->attachments($p->ID);$this->creatorRelations($p->ID);echo '<p><b>Stats:</b> '.esc_html(wp_json_encode(Stats::content($p->ID),JSON_UNESCAPED_UNICODE)).'</p>';}
 public function creatorBox(\WP_Post $p):void
 {
  $this->nonce();
  $id=$p->ID;
  $this->creatorAvatar($id);
  echo '<div class="meydan-creator-grid">';
  $this->field('meydan_role','نقش نمایشی',(string)get_post_meta($id,'meydan_role',true),'مثلاً «مداح و سخنران»، «خبرنگار حوزه فرهنگ»');
  $this->field('meydan_handle','شناسه/نام کاربری',(string)get_post_meta($id,'meydan_handle',true),'بدون @ وارد شود؛ در API با کلید handle برمی‌گردد.');
  $this->field('meydan_initials','سرواژه',(string)get_post_meta($id,'meydan_initials',true),'برای نمایش جایگزین آواتار؛ اگر خالی بماند از نام ساخته می‌شود.');
  echo '</div>';
  echo '<div class="meydan-creator-grid">';
  $this->field('meydan_expertise','حوزه تخصص',(string)get_post_meta($id,'meydan_expertise',true),'کاربر در فهرست تولیدکنندگان این متن را می‌بیند.');
  $this->check('meydan_verified','نشان تأییدشده',(bool)get_post_meta($id,'meydan_verified',true));
  echo '</div>';
  $this->creatorTypes($id);
  $this->creatorCities($id);
  $this->socialLinks($id);
 }
 private function field(string $name,string $label,string $value,string $description=''):void
 {
  echo '<div class="meydan-field"><label class="meydan-label" for="'.esc_attr($name).'">'.esc_html($label).'</label><input class="widefat" id="'.esc_attr($name).'" type="text" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';
  if($description!=='')echo '<small>'.esc_html($description).'</small>';
  echo '</div>';
 }
 private function creatorAvatar(int $id):void
 {
  $mediaId=(int)get_post_meta($id,'meydan_avatar_media_id',true);
  $image=$mediaId>0&&wp_attachment_is_image($mediaId)?wp_get_attachment_image($mediaId,[120,120],false,['class'=>'meydan-creator-avatar-img']):'';
  echo '<div class="meydan-creator-avatar"><div class="meydan-creator-avatar-preview" id="meydan-creator-avatar-preview">'.($image?:'<span class="meydan-creator-avatar-empty">بدون آواتار</span>').'</div><div class="meydan-creator-avatar-actions"><span class="meydan-label">آواتار تولیدکننده</span><p>تصویر از کتابخانه رسانه انتخاب یا همان‌جا آپلود می‌شود و در API به شکل <code>avatar_url</code> برمی‌گردد.</p><input type="hidden" name="meydan_avatar_media_id" id="meydan-creator-avatar-id" value="'.esc_attr((string)$mediaId).'"><button type="button" class="button button-primary" id="meydan-creator-avatar-select">انتخاب یا آپلود تصویر</button> <button type="button" class="button" id="meydan-creator-avatar-remove"'.($mediaId>0?'':' disabled').'>حذف آواتار</button></div></div>';
 }
 private function creatorTypes(int $id):void
 {
  $selected=wp_get_post_terms($id,'meydan_creator_type',['fields'=>'slugs']);
  $selected=is_wp_error($selected)?[]:$selected;
  echo '<div class="meydan-field"><span class="meydan-label">نوع تولیدکننده</span><small>یک یا چند مورد انتخاب کنید؛ نوع «سخنران» این مورد را در فهرست سخنرانان API هم نمایش می‌دهد.</small><div class="meydan-chip-row">';
  foreach(CreatorService::typeOptions() as $slug=>$label){echo '<label class="meydan-chip"><input type="checkbox" name="meydan_creator_types[]" value="'.esc_attr($slug).'" '.checked(in_array($slug,$selected,true),true,false).'><span>'.esc_html($label).'</span></label>';}
  echo '</div></div>';
 }
 private function creatorCities(int $id):void
 {
  global $wpdb;
  $selected=array_map('intval',(array)get_post_meta($id,'meydan_cities',true));
  $provinces=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}meydan_provinces WHERE active=1 ORDER BY sort_order,name",ARRAY_A)?:[];
  $cities=$wpdb->get_results("SELECT id,province_id,name FROM {$wpdb->prefix}meydan_cities WHERE active=1 ORDER BY sort_order,name",ARRAY_A)?:[];
  echo '<div class="meydan-field"><span class="meydan-label">شهرهای فعالیت</span><small>برای فیلتر <code>city_id</code> در API استفاده می‌شود. بدون انتخاب، تولیدکننده در همه شهرها دیده می‌شود.</small>';
  if(!$provinces||!$cities){echo '<p class="meydan-help">هنوز داده استان و شهری ثبت نشده است. از صفحه «استان‌ها و شهرها» فهرست ایران را همگام‌سازی کنید.</p></div>';return;}
  echo '<div class="meydan-city-picker" id="meydan-creator-cities">';
  echo '<div class="meydan-city-selected" id="meydan-creator-city-selected"></div>';
  echo '<div class="meydan-city-controls"><select id="meydan-creator-province"><option value="">همه استان‌ها</option>';
  foreach($provinces as $province)echo '<option value="'.esc_attr((string)$province['id']).'">'.esc_html($province['name']).'</option>';
  echo '</select><input type="search" id="meydan-creator-city-search" placeholder="جست‌وجوی شهر..."><span class="meydan-city-count" id="meydan-creator-city-count"></span></div>';
  echo '<div class="meydan-city-options" id="meydan-creator-city-options">';
  foreach($cities as $city)echo '<label class="meydan-city-option" data-province="'.esc_attr((string)$city['province_id']).'" data-name="'.esc_attr(mb_strtolower($city['name'])).'"><input type="checkbox" name="meydan_cities[]" value="'.esc_attr((string)$city['id']).'" '.checked(in_array((int)$city['id'],$selected,true),true,false).'><span>'.esc_html($city['name']).'</span></label>';
  echo '</div><input type="hidden" name="meydan_cities_present" value="1"></div></div>';
 }
 private function socialLinks(int $id):void
 {
  $links=array_values(array_filter((array)get_post_meta($id,'meydan_social_links',true),'is_array'));
  echo '<div class="meydan-content-gui meydan-social-gui"><h4>لینک‌های اجتماعی</h4><p>هر لینک را با پلتفرم، آدرس و یک برچسب اختیاری وارد کنید. ترتیب نمایش در API همان ترتیب ردیف‌هاست.</p><div id="meydan-social-list">';
  foreach($links as $link)echo $this->socialRow((string)($link['platform']??'website'),(string)($link['url']??''),(string)($link['label']??''));
  echo '</div><button type="button" class="button" id="meydan-social-add">افزودن لینک</button><input type="hidden" name="meydan_social_links_json" id="meydan-social-json" value="'.esc_attr(wp_json_encode($links,JSON_UNESCAPED_UNICODE)).'"></div>';
 }
 private function socialRow(string $platform,string $url,string $label):string
 {
  $options='';
  foreach(CreatorService::SOCIAL_PLATFORMS as $slug=>$title)$options.='<option value="'.esc_attr($slug).'" '.selected($platform,$slug,false).'>'.esc_html($title).'</option>';
  return '<div class="meydan-social-row"><span class="meydan-drag-handle" aria-hidden="true">↕</span><select data-field="platform">'.$options.'</select><input data-field="url" type="url" inputmode="url" placeholder="https://..." value="'.esc_attr($url).'"><input data-field="label" type="text" placeholder="برچسب (اختیاری)" value="'.esc_attr($label).'"><button type="button" class="button-link-delete meydan-social-remove">حذف</button></div>';
 }
 public function squareBox(\WP_Post $p):void{$this->nonce();$this->input('meydan_owner_user_id','Owner User ID',(string)get_post_meta($p->ID,'meydan_owner_user_id',true),'number');$this->select('meydan_approval_status','Approval Status',(string)get_post_meta($p->ID,'meydan_approval_status',true)?:'pending_verification',['pending_verification'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','suspended'=>'Suspended']);$this->check('meydan_verified','Verified',(bool)get_post_meta($p->ID,'meydan_verified',true));$this->input('meydan_avatar_media_id','Avatar Media ID',(string)get_post_meta($p->ID,'meydan_avatar_media_id',true),'number');global $wpdb;$g=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_geo WHERE square_id=%d",$p->ID),ARRAY_A)?:[];$this->input('meydan_geo_province','Province ID',(string)($g['province_id']??''),'number');$this->input('meydan_geo_city','City ID',(string)($g['city_id']??''),'number');$this->textarea('meydan_geo_address','Address',(string)($g['address']??''));$this->input('meydan_geo_lat','Latitude',(string)($g['latitude']??''),'number','any');$this->input('meydan_geo_lng','Longitude',(string)($g['longitude']??''),'number','any');echo '<div id="meydan-square-map" style="height:320px;border:1px solid #ccd0d4" data-lat="'.esc_attr((string)($g['latitude']??35.6892)).'" data-lng="'.esc_attr((string)($g['longitude']??51.389)).'"></div><p>Pin را جابه‌جا کنید؛ latitude/longitude به‌روزرسانی می‌شود.</p>';$this->scheduleEditor($p->ID);}
 public function initiativeBox(\WP_Post $p):void{$this->nonce();$this->input('meydan_cta_label','CTA',(string)get_post_meta($p->ID,'meydan_cta_label',true));$this->input('meydan_starts_at','Starts At',(string)get_post_meta($p->ID,'meydan_starts_at',true),'datetime-local');$this->input('meydan_ends_at','Ends At',(string)get_post_meta($p->ID,'meydan_ends_at',true),'datetime-local');$this->select('meydan_status','Status',(string)get_post_meta($p->ID,'meydan_status',true)?:'active',['draft'=>'Draft','active'=>'Active','ended'=>'Ended','disabled'=>'Disabled']);$this->check('meydan_allow_guest_join','Allow Guest Join',(bool)get_post_meta($p->ID,'meydan_allow_guest_join',true));global $wpdb;$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}meydan_initiative_members WHERE initiative_id=%d AND status='active'",$p->ID));echo '<p><b>Participants:</b> '.esc_html((string)$count).'</p>';}
 public function campaignBox(\WP_Post $p):void{$this->nonce();$this->input('meydan_starts_at','Starts At',(string)get_post_meta($p->ID,'meydan_starts_at',true),'datetime-local');$this->input('meydan_ends_at','Ends At',(string)get_post_meta($p->ID,'meydan_ends_at',true),'datetime-local');$this->check('meydan_current','Current',(bool)get_post_meta($p->ID,'meydan_current',true));$this->input('meydan_order','Order',(string)get_post_meta($p->ID,'meydan_order',true),'number');$this->textarea('meydan_labels_json','Labels JSON',wp_json_encode((array)get_post_meta($p->ID,'meydan_labels',true),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));$this->textarea('meydan_linked_content_json','Linked Content IDs JSON',wp_json_encode((array)get_post_meta($p->ID,'meydan_linked_content',true)));$this->textarea('meydan_schedule_json','Schedule JSON',wp_json_encode((array)get_post_meta($p->ID,'meydan_schedule',true),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));}
 public function saveMeta(int $id,\WP_Post $post):void
 {
  if(!isset($_POST['meydan_meta_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['meydan_meta_nonce'])),'meydan_save_meta')||wp_is_post_autosave($id)||wp_is_post_revision($id))return;if(!current_user_can('edit_post',$id))return;$before=['meta'=>get_post_meta($id)];
  if($post->post_type==='meydan_creator'){$this->saveCreatorMeta($id);AuditLogger::log('admin_post_updated',$post->post_type,$id,$before,['meta'=>get_post_meta($id)]);return;}
  if($post->post_type==='meydan_media_outlet'){$this->saveOutletMeta($id);AuditLogger::log('admin_post_updated',$post->post_type,$id,$before,['meta'=>get_post_meta($id)]);return;}
  $text=['meydan_author_actor_type','meydan_approval_status','meydan_format','meydan_cta_label','meydan_status','meydan_starts_at','meydan_ends_at'];foreach($text as $k)if(isset($_POST[$k]))update_post_meta($id,$k,sanitize_text_field(wp_unslash($_POST[$k])));
  $ints=['meydan_author_actor_id','meydan_initiative_id','meydan_avatar_media_id','meydan_owner_user_id','meydan_order'];foreach($ints as $k)if(isset($_POST[$k]))update_post_meta($id,$k,(int)$_POST[$k]);foreach(['meydan_is_echo','meydan_featured','meydan_verified','meydan_allow_guest_join','meydan_current'] as $k)update_post_meta($id,$k,isset($_POST[$k])?1:0);if(isset($_POST['meydan_usage_note']))update_post_meta($id,'meydan_usage_note',sanitize_textarea_field(wp_unslash($_POST['meydan_usage_note'])));
  if(isset($_POST['meydan_attachments_json'])){$a=json_decode(wp_unslash($_POST['meydan_attachments_json']),true);if(is_array($a))update_post_meta($id,'meydan_attachments',$this->cleanAttachments($a));}$primary=(int)($_POST['meydan_primary_attachment_id']??0);if($primary>0&&get_post_type($primary)==='attachment')update_post_meta($id,'meydan_primary_attachment_id',$primary);else delete_post_meta($id,'meydan_primary_attachment_id');
  if(isset($_POST['meydan_social_links_json'])){$x=json_decode(wp_unslash($_POST['meydan_social_links_json']),true);if(is_array($x))update_post_meta($id,'meydan_social_links',array_map('esc_url_raw',$x));}
  foreach(['meydan_labels_json'=>'meydan_labels','meydan_linked_content_json'=>'meydan_linked_content','meydan_schedule_json'=>'meydan_schedule'] as $src=>$dest)if(isset($_POST[$src])){$x=json_decode(wp_unslash($_POST[$src]),true);if(is_array($x))update_post_meta($id,$dest,$x);}
  if($post->post_type==='meydan_content'&&isset($_POST['meydan_creators_json'])){$x=json_decode(wp_unslash($_POST['meydan_creators_json']),true);if(is_array($x))$this->saveCreators($id,$x);}
  if($post->post_type==='meydan_square'&&isset($_POST['meydan_geo_lat'],$_POST['meydan_geo_lng'])){global $wpdb;$wpdb->replace($wpdb->prefix.'meydan_square_geo',['square_id'=>$id,'province_id'=>(int)($_POST['meydan_geo_province']??0),'city_id'=>(int)($_POST['meydan_geo_city']??0),'address'=>sanitize_textarea_field(wp_unslash($_POST['meydan_geo_address']??'')),'latitude'=>(float)$_POST['meydan_geo_lat'],'longitude'=>(float)$_POST['meydan_geo_lng'],'updated_at'=>current_time('mysql',true)]);if(isset($_POST['meydan_schedule_rows']))$this->saveSchedules($id,(array)$_POST['meydan_schedule_rows']);$status=(string)get_post_meta($id,'meydan_approval_status',true);if($status==='approved'){update_post_meta($id,'meydan_verified',1);if($post->post_status!=='publish')wp_update_post(['ID'=>$id,'post_status'=>'publish']);}}
  AuditLogger::log('admin_post_updated',$post->post_type,$id,$before,['meta'=>get_post_meta($id)]);
 }
 private function saveCreatorMeta(int $id):void
 {
  $input=[];
  foreach(['meydan_role'=>'role','meydan_handle'=>'handle','meydan_expertise'=>'expertise','meydan_initials'=>'initials'] as $field=>$key)if(isset($_POST[$field]))$input[$key]=sanitize_text_field(wp_unslash($_POST[$field]));
  $input['verified']=isset($_POST['meydan_verified']);
  if(isset($_POST['meydan_creator_types']))$input['types']=array_map('sanitize_key',(array)wp_unslash($_POST['meydan_creator_types']));
  if(isset($_POST['meydan_cities']))$input['cities']=array_map('intval',(array)wp_unslash($_POST['meydan_cities']));
  if(isset($_POST['meydan_avatar_media_id']))$input['avatar_media_id']=(int)$_POST['meydan_avatar_media_id'];
  if(isset($_POST['meydan_social_links_json'])){$links=json_decode(wp_unslash($_POST['meydan_social_links_json']),true);if(is_array($links))$input['social_links']=$links;}
  CreatorService::save($input,$id);
 }
 private function saveOutletMeta(int $id):void
 {
  $input=[];
  foreach(['meydan_website'=>'website','meydan_bale'=>'bale','meydan_eitaa'=>'eitaa'] as $field=>$key)if(isset($_POST[$field]))$input[$key]=wp_unslash($_POST[$field]);
  if(isset($_POST['meydan_avatar_media_id']))$input['avatar_media_id']=(int)$_POST['meydan_avatar_media_id'];
  MediaOutletService::save($input,$id);
 }
 private function attachments(int $id):void
 {
  $items=array_values(array_filter((array)get_post_meta($id,'meydan_attachments',true),'is_array'));$primary=(int)get_post_meta($id,'meydan_primary_attachment_id',true);
  echo '<div class="meydan-content-gui meydan-attachments-gui"><h4>Attachments</h4><p>چند فایل از هر نوعی را انتخاب یا آپلود کنید؛ ترتیب، عنوان و توضیح هر فایل قابل ویرایش است.</p><div id="meydan-attachments-list">';
  foreach($items as $item){$mid=(int)($item['media_id']??0);if(!$mid||get_post_type($mid)!=='attachment')continue;$url=(string)wp_get_attachment_url($mid);$title=(string)get_the_title($mid);$mime=(string)get_post_mime_type($mid);echo '<div class="meydan-attachment-row" data-id="'.esc_attr((string)$mid).'">';if(wp_attachment_is_image($mid))echo '<img class="meydan-attachment-thumb" src="'.esc_url(wp_get_attachment_image_url($mid,'thumbnail')?:$url).'" alt="">';else echo '<span class="meydan-attachment-file">'.esc_html(strtoupper((string)pathinfo($url,PATHINFO_EXTENSION)?:'FILE')).'</span>';echo '<div class="meydan-attachment-main"><strong>'.esc_html($title?:basename($url)).'</strong><small>'.esc_html($mime).'</small><label><input type="radio" name="meydan_primary_attachment_id" value="'.esc_attr((string)$mid).'" '.checked($primary,$mid,false).'> محتوای اصلی برای پخش</label><label>عنوان نمایشی<input data-field="label" type="text" value="'.esc_attr((string)($item['label']??'')).'"></label><label>توضیح<input data-field="caption" type="text" value="'.esc_attr((string)($item['caption']??'')).'"></label></div><button type="button" class="button-link-delete meydan-attachment-remove">حذف</button></div>';}
  echo '</div><button type="button" class="button" id="meydan-attachments-select">افزودن یا آپلود فایل‌ها</button><input type="hidden" name="meydan_attachments_json" id="meydan-attachments-json" value="'.esc_attr(wp_json_encode($items,JSON_UNESCAPED_UNICODE)).'"></div>';
 }
 private function creatorRelations(int $id):void
 {
  global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT creator_id AS id,position,role_label FROM {$wpdb->prefix}meydan_content_creators WHERE content_id=%d ORDER BY position",$id),ARRAY_A)?:[];$selected=[];foreach($rows as $row)$selected[(int)$row['id']]=$row;
  $creators=get_posts(['post_type'=>'meydan_creator','post_status'=>['publish','draft'],'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);echo '<div class="meydan-content-gui meydan-creators-gui"><h4>Creators</h4><p>تولیدکننده‌های مرتبط را جست‌وجو و چند مورد را انتخاب کنید.</p><input type="search" id="meydan-creators-search" class="regular-text" placeholder="جست‌وجوی تولیدکننده..."><div id="meydan-creators-list">';foreach($creators as $creator){$cid=(int)$creator->ID;$row=$selected[$cid]??[];echo '<label class="meydan-creator-option" data-name="'.esc_attr(mb_strtolower($creator->post_title)).'"><input type="checkbox" data-creator-id="'.$cid.'" '.checked(isset($selected[$cid]),true,false).'><span>'.esc_html($creator->post_title).' <small>#'.$cid.'</small></span><input data-role-label type="text" placeholder="نقش" value="'.esc_attr((string)($row['role_label']??'')).'"></label>';}echo '</div><input type="hidden" name="meydan_creators_json" id="meydan-creators-json" value="'.esc_attr(wp_json_encode($rows,JSON_UNESCAPED_UNICODE)).'"></div>';
 }
 private function saveCreators(int $id,array $rows):void{global $wpdb;$t=$wpdb->prefix.'meydan_content_creators';$wpdb->delete($t,['content_id'=>$id]);foreach($rows as $i=>$r){if(!is_array($r))continue;$cid=(int)($r['id']??$r['creator_id']??0);if(get_post_type($cid)!=='meydan_creator')continue;$wpdb->insert($t,['content_id'=>$id,'creator_id'=>$cid,'position'=>(int)($r['position']??$i),'role_label'=>sanitize_text_field((string)($r['role_label']??''))]);}}
 private function scheduleEditor(int $sid):void{global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_schedule WHERE square_id=%d ORDER BY starts_at,position",$sid),ARRAY_A);echo '<h4>Schedule</h4><table class="widefat"><thead><tr><th>Title</th><th>Start</th><th>End</th><th>Status</th><th>Order</th></tr></thead><tbody>';foreach(array_merge($rows?:[],[['id'=>0,'title'=>'','starts_at'=>'','ends_at'=>'','status'=>'published','position'=>0]]) as $i=>$r){echo '<tr><td><input name="meydan_schedule_rows['.$i.'][title]" value="'.esc_attr((string)$r['title']).'"><input type="hidden" name="meydan_schedule_rows['.$i.'][id]" value="'.esc_attr((string)$r['id']).'"></td><td><input name="meydan_schedule_rows['.$i.'][starts_at]" value="'.esc_attr((string)$r['starts_at']).'"></td><td><input name="meydan_schedule_rows['.$i.'][ends_at]" value="'.esc_attr((string)$r['ends_at']).'"></td><td><input name="meydan_schedule_rows['.$i.'][status]" value="'.esc_attr((string)$r['status']).'"></td><td><input type="number" name="meydan_schedule_rows['.$i.'][position]" value="'.esc_attr((string)$r['position']).'" style="width:70px"></td></tr>';}echo '</tbody></table><p>برای حذف یک ردیف، Title را خالی ذخیره کنید. ردیف آخر برای افزودن برنامه جدید است.</p>';}
 private function saveSchedules(int $sid,array $rows):void{global $wpdb;$t=$wpdb->prefix.'meydan_square_schedule';$seen=[];foreach($rows as $r){if(!is_array($r))continue;$id=(int)($r['id']??0);$title=sanitize_text_field(wp_unslash($r['title']??''));if($title===''){if($id)$wpdb->delete($t,['id'=>$id,'square_id'=>$sid]);continue;}$data=['square_id'=>$sid,'title'=>$title,'description'=>'','starts_at'=>sanitize_text_field(wp_unslash($r['starts_at']??'')),'ends_at'=>($r['ends_at']??'')?sanitize_text_field(wp_unslash($r['ends_at'])):null,'location_label'=>null,'status'=>sanitize_key(wp_unslash($r['status']??'published')),'position'=>(int)($r['position']??0),'updated_at'=>current_time('mysql',true)];if($id){$wpdb->update($t,$data,['id'=>$id,'square_id'=>$sid]);$seen[]=$id;}else{$data['created_at']=current_time('mysql',true);$wpdb->insert($t,$data);}}}
 private function cleanAttachments(array $a):array{$o=[];foreach($a as $i=>$x){if(!is_array($x))continue;$m=(int)($x['media_id']??$x['id']??0);if(get_post_type($m)!=='attachment')continue;$o[]=['media_id'=>$m,'order'=>(int)($x['order']??$i+1),'caption'=>sanitize_text_field((string)($x['caption']??'')),'label'=>sanitize_text_field((string)($x['label']??''))];}return $o;}
 public function contentAssets(string $hook):void
 {
  $screen=get_current_screen();if(!$screen||$screen->base!=='post'||$screen->post_type!=='meydan_content')return;wp_enqueue_media();wp_enqueue_script('jquery-ui-sortable');wp_register_style('meydan-content-gui',false);wp_enqueue_style('meydan-content-gui');wp_add_inline_style('meydan-content-gui','.meydan-content-gui{margin:20px 0;padding:16px;border:1px solid #dcdcde;background:#fff}.meydan-content-gui h4{margin:0 0 8px}.meydan-content-gui>p{color:#646970}.meydan-attachment-row{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-top:1px solid #eee}.meydan-attachment-thumb,.meydan-attachment-file{width:64px;height:64px;object-fit:cover;flex:0 0 64px}.meydan-attachment-file{display:grid;place-items:center;background:#f0f0f1;font-weight:700;font-size:11px}.meydan-attachment-main{display:grid;gap:5px;flex:1}.meydan-attachment-main small{color:#646970}.meydan-attachment-main label{display:flex;align-items:center;gap:8px}.meydan-attachment-main input[data-field]{width:100%;max-width:520px}.meydan-attachment-remove{margin-left:auto}.meydan-creator-option{display:grid;grid-template-columns:24px 1fr 220px;align-items:center;gap:8px;padding:9px 0;border-top:1px solid #eee}.meydan-creator-option small{color:#646970}.meydan-creator-option input[data-role-label]{width:100%}');wp_add_inline_script('jquery-ui-sortable',<<<'JS'
jQuery(function($){const attachmentList=$('#meydan-attachments-list'),attachmentJson=$('#meydan-attachments-json');function syncAttachments(){const rows=[];attachmentList.find('.meydan-attachment-row').each(function(i){const row=$(this);rows.push({media_id:Number(row.data('id')),order:i+1,caption:row.find('[data-field="caption"]').val()||'',label:row.find('[data-field="label"]').val()||''});});attachmentJson.val(JSON.stringify(rows));}function addAttachment(item){if(!item||attachmentList.find('[data-id="'+item.id+'"]').length)return;const row=$('<div/>',{class:'meydan-attachment-row'}).attr('data-id',item.id);const preview=item.type==='image'?$('<img/>',{class:'meydan-attachment-thumb',src:item.sizes?.thumbnail?.url||item.url,alt:''}):$('<span/>',{class:'meydan-attachment-file',text:(item.subtype||'file').toUpperCase()});const main=$('<div/>',{class:'meydan-attachment-main'}).append($('<strong/>',{text:item.filename||item.title||'File'}),$('<small/>',{text:item.mime||''}));main.append($('<label/>',{text:'عنوان نمایشی'}).append($('<input/>',{type:'text', 'data-field':'label',value:item.filename||item.title||''})));main.append($('<label/>',{text:'توضیح'}).append($('<input/>',{type:'text','data-field':'caption',value:''})));row.append($('<span/>',{class:'meydan-drag-handle',text:'↕'}),preview,main,$('<button/>',{type:'button',class:'button-link-delete meydan-attachment-remove',text:'حذف'}));attachmentList.append(row);}$('#meydan-attachments-select').on('click',function(e){e.preventDefault();const frame=wp.media({title:'انتخاب یا آپلود فایل‌ها',button:{text:'افزودن فایل‌ها'},multiple:true});frame.on('select',function(){frame.state().get('selection').each(function(model){addAttachment(model.toJSON());});syncAttachments();});frame.open();});attachmentList.on('click','.meydan-attachment-remove',function(){$(this).closest('.meydan-attachment-row').remove();syncAttachments();});attachmentList.on('input','input',syncAttachments);attachmentList.sortable({handle:'.meydan-drag-handle',update:syncAttachments});const creatorJson=$('#meydan-creators-json');function syncCreators(){const rows=[];$('.meydan-creator-option').each(function(i){const option=$(this);const checkbox=option.find('[data-creator-id]');if(checkbox.prop('checked'))rows.push({id:Number(checkbox.data('creator-id')),position:rows.length,role_label:option.find('[data-role-label]').val()||''});} );creatorJson.val(JSON.stringify(rows));}$('#meydan-creators-search').on('input',function(){const q=$(this).val().toLowerCase();$('.meydan-creator-option').each(function(){$(this).toggle(!q||String($(this).data('name')).includes(q));});});$('.meydan-creators-gui').on('change input','input',syncCreators);$('#post').on('submit',function(){syncAttachments();syncCreators();});});
JS
);}
 public function avatarAssets(string $hook):void{$screen=get_current_screen();if(!$screen||!in_array($screen->base,['profile','user-edit'],true))return;wp_enqueue_media();wp_add_inline_script('media-editor',<<<'JS'
jQuery(function($){function bindImagePicker(kind,title,style){let frame;const input=$('#meydan_user_'+kind+'_media_id'),preview=$('#meydan-user-'+kind+'-preview'),remove=$('#meydan-user-'+kind+'-remove');$('#meydan-user-'+kind+'-select').on('click',function(e){e.preventDefault();frame=wp.media({title:title,button:{text:'استفاده از این تصویر'},library:{type:'image'},multiple:false});frame.on('select',function(){const image=frame.state().get('selection').first().toJSON();input.val(image.id);preview.html($('<img>',{src:image.url,alt:''}).css(style));remove.prop('disabled',false);});frame.open();});remove.on('click',function(e){e.preventDefault();input.val('');preview.empty();remove.prop('disabled',true);});}bindImagePicker('avatar','انتخاب آواتار کاربر',{display:'block',maxWidth:'96px',height:'auto',marginBottom:'8px',borderRadius:'50%'});bindImagePicker('cover','انتخاب کاور کاربر',{display:'block',maxWidth:'320px',height:'auto',marginBottom:'8px',borderRadius:'8px'});});
JS
);}
 public function creatorAssets(string $hook):void
 {
  $screen=get_current_screen();
  if(!$screen||$screen->base!=='post'||$screen->post_type!=='meydan_creator')return;
  wp_enqueue_media();wp_enqueue_script('jquery-ui-sortable');
  wp_register_style('meydan-creator-gui',false);wp_enqueue_style('meydan-creator-gui');
  wp_add_inline_style('meydan-creator-gui','.meydan-creator-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:0 0 14px}.meydan-creator-grid .meydan-field:last-child:nth-child(odd){grid-column:1/-1}.meydan-creator-avatar{display:flex;align-items:center;gap:18px;margin:0 0 18px;padding:16px;border:1px solid #dcdcde;border-radius:12px;background:#fff}.meydan-creator-avatar-preview{width:96px;height:96px;flex:0 0 96px;border-radius:50%;overflow:hidden;background:#f0f0f1;display:grid;place-items:center}.meydan-creator-avatar-img{width:96px;height:96px;object-fit:cover}.meydan-creator-avatar-empty{color:#646970;font-size:11px;text-align:center}.meydan-creator-avatar-actions{display:grid;gap:6px}.meydan-creator-avatar-actions p{margin:0;color:#646970}.meydan-chip-row{display:flex;flex-wrap:wrap;gap:8px}.meydan-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border:1px solid #dcdcde;border-radius:999px;background:#fff;cursor:pointer}.meydan-chip input{margin:0}.meydan-chip:has(input:checked){border-color:#0f766e;background:#f0fdfa;color:#0f766e;font-weight:600}.meydan-city-picker{border:1px solid #dcdcde;border-radius:12px;padding:14px;background:#fff}.meydan-city-selected{display:flex;flex-wrap:wrap;gap:6px;min-height:32px;margin-bottom:10px}.meydan-city-selected:empty:before{content:"شهری انتخاب نشده است";color:#646970;font-size:12px}.meydan-city-tag{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;background:#f0fdfa;border:1px solid #99c7c1;font-size:12px}.meydan-city-tag button{border:0;background:none;cursor:pointer;font-size:14px;line-height:1;color:#b32d2e}.meydan-city-controls{display:flex;gap:8px;align-items:center;margin-bottom:10px}.meydan-city-controls select{min-width:170px}.meydan-city-controls input[type=search]{flex:1}.meydan-city-count{color:#646970;font-size:12px;white-space:nowrap}.meydan-city-options{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:4px;max-height:220px;overflow:auto;padding:4px;border:1px solid #f0f0f1;border-radius:8px}.meydan-city-option{display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:6px;cursor:pointer}.meydan-city-option:hover{background:#f6f7f7}.meydan-city-option input{margin:0}.meydan-city-option.is-hidden{display:none}.meydan-social-row{display:grid;grid-template-columns:20px 170px 1fr 1fr auto;align-items:center;gap:8px;padding:9px 0;border-top:1px solid #eee}.meydan-social-row input,.meydan-social-row select{width:100%}.meydan-drag-handle{cursor:grab;color:#646970;text-align:center}@media(max-width:782px){.meydan-creator-grid{grid-template-columns:1fr}.meydan-social-row{grid-template-columns:20px 1fr;grid-auto-flow:row}.meydan-creator-avatar{flex-direction:column;align-items:flex-start}}');
  wp_add_inline_script('jquery-ui-sortable',<<<'JS'
jQuery(function($){
  var TYPE_LABELS = window.MEYDAN_CREATOR_TYPES || {};
  var avatarId = $('#meydan-creator-avatar-id');
  var avatarPreview = $('#meydan-creator-avatar-preview');
  var avatarFrame;
  $('#meydan-creator-avatar-select').on('click', function(e){
    e.preventDefault();
    avatarFrame = wp.media({title:'انتخاب آواتار تولیدکننده', button:{text:'استفاده از این تصویر'}, library:{type:'image'}, multiple:false});
    avatarFrame.on('select', function(){
      var image = avatarFrame.state().get('selection').first().toJSON();
      var src = (image.sizes && image.sizes.thumbnail && image.sizes.thumbnail.url) || image.url;
      avatarId.val(image.id);
      avatarPreview.html($('<img>', {class:'meydan-creator-avatar-img', src:src, alt:''}));
      $('#meydan-creator-avatar-remove').prop('disabled', false);
    });
    avatarFrame.open();
  });
  $('#meydan-creator-avatar-remove').on('click', function(e){
    e.preventDefault();
    avatarId.val('0');
    avatarPreview.html($('<span>', {class:'meydan-creator-avatar-empty', text:'بدون آواتار'}));
    $(this).prop('disabled', true);
  });

  var province = $('#meydan-creator-province');
  var search = $('#meydan-creator-city-search');
  var options = $('#meydan-creator-city-options');
  var selectedBox = $('#meydan-creator-city-selected');
  var countBox = $('#meydan-creator-city-count');
  function cityLabel(input){ return $.trim(input.closest('.meydan-city-option').find('span').text()); }
  function syncSelected(){
    selectedBox.empty();
    options.find('input:checked').each(function(){
      var input = $(this);
      selectedBox.append($('<span>', {class:'meydan-city-tag'}).append($('<span>', {text:cityLabel(input)}), $('<button>', {type:'button', 'aria-label':'حذف شهر', text:'×'}).on('click', function(){ input.prop('checked', false).trigger('change'); })));
    });
  }
  function filterCities(){
    var pid = province.val();
    var query = String(search.val() || '').toLowerCase();
    var visible = 0;
    options.find('.meydan-city-option').each(function(){
      var option = $(this);
      var matches = (!pid || option.data('province') == pid) && (!query || String(option.data('name')).indexOf(query) !== -1);
      option.toggleClass('is-hidden', !matches);
      if (matches) visible++;
    });
    countBox.text(visible + ' شهر');
  }
  options.on('change', 'input', syncSelected);
  province.on('change', filterCities);
  search.on('input', filterCities);
  if (options.length) { filterCities(); syncSelected(); }

  var socialJson = $('#meydan-social-json');
  var socialList = $('#meydan-social-list');
  var socialOptions = '';
  $.each(TYPE_LABELS.social || {}, function(slug, label){ socialOptions += '<option value="'+slug+'">'+label+'</option>'; });
  function syncSocial(){
    var rows = [];
    socialList.find('.meydan-social-row').each(function(){
      var row = $(this);
      var url = $.trim(row.find('[data-field="url"]').val());
      if (!url) return;
      rows.push({platform: row.find('[data-field="platform"]').val(), url: url, label: $.trim(row.find('[data-field="label"]').val())});
    });
    socialJson.val(JSON.stringify(rows));
  }
  $('#meydan-social-add').on('click', function(e){
    e.preventDefault();
    var row = $('<div>', {class:'meydan-social-row'});
    row.append($('<span>', {class:'meydan-drag-handle', 'aria-hidden':'true', text:'↕'}), $('<select>', {'data-field':'platform'}).html(socialOptions), $('<input>', {type:'url', 'data-field':'url', placeholder:'https://...'}), $('<input>', {type:'text', 'data-field':'label', placeholder:'برچسب (اختیاری)'}), $('<button>', {type:'button', class:'button-link-delete meydan-social-remove', text:'حذف'}));
    socialList.append(row);
    row.find('[data-field="url"]').trigger('focus');
  });
  socialList.on('click', '.meydan-social-remove', function(e){ e.preventDefault(); $(this).closest('.meydan-social-row').remove(); syncSocial(); });
  socialList.on('input change', 'input,select', syncSocial);
  if (socialList.length && socialList.sortable) { socialList.sortable({handle:'.meydan-drag-handle', update:syncSocial}); }

  $('#post').on('submit', function(){ syncSocial(); });
});
JS
  );
  wp_localize_script('jquery-ui-sortable','MEYDAN_CREATOR_TYPES',[
   'social'=>CreatorService::SOCIAL_PLATFORMS,
   'types'=>CreatorService::TYPE_LABELS,
  ]);
 }
 public function outletAssets(string $hook):void
 {
  $screen=get_current_screen();
  if(!$screen||$screen->base!=='post'||$screen->post_type!=='meydan_media_outlet')return;
  wp_enqueue_media();
  wp_register_style('meydan-outlet-gui',false);wp_enqueue_style('meydan-outlet-gui');
  wp_add_inline_style('meydan-outlet-gui','.meydan-creator-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:0 0 14px}.meydan-creator-avatar{display:flex;align-items:center;gap:18px;margin:0 0 18px;padding:16px;border:1px solid #dcdcde;border-radius:12px;background:#fff}.meydan-creator-avatar-preview{width:96px;height:96px;flex:0 0 96px;border-radius:50%;overflow:hidden;background:#f0f0f1;display:grid;place-items:center}.meydan-creator-avatar-img{width:96px;height:96px;object-fit:cover}.meydan-creator-avatar-empty{color:#646970;font-size:11px;text-align:center}.meydan-creator-avatar-actions{display:grid;gap:6px}.meydan-creator-avatar-actions p{margin:0;color:#646970}@media(max-width:782px){.meydan-creator-grid{grid-template-columns:1fr}.meydan-creator-avatar{flex-direction:column;align-items:flex-start}}');
  wp_add_inline_script('media-editor',<<<'JS'
jQuery(function($){
  var id = $('#meydan-outlet-avatar-id');
  var preview = $('#meydan-outlet-avatar-preview');
  var frame;
  $('#meydan-outlet-avatar-select').on('click', function(e){
    e.preventDefault();
    frame = wp.media({title:'انتخاب آواتار رسانه', button:{text:'استفاده از این تصویر'}, library:{type:'image'}, multiple:false});
    frame.on('select', function(){
      var image = frame.state().get('selection').first().toJSON();
      var src = (image.sizes && image.sizes.thumbnail && image.sizes.thumbnail.url) || image.url;
      id.val(image.id);
      preview.html($('<img>', {class:'meydan-creator-avatar-img', src:src, alt:''}));
      $('#meydan-outlet-avatar-remove').prop('disabled', false);
    });
    frame.open();
  });
  $('#meydan-outlet-avatar-remove').on('click', function(e){
    e.preventDefault();
    id.val('0');
    preview.html($('<span>', {class:'meydan-creator-avatar-empty', text:'بدون آواتار'}));
    $(this).prop('disabled', true);
  });
});
JS
  );
 }
 public function reflectionAssets(string $hook):void
 {
  $screen=get_current_screen();
  if(!$screen||$screen->base!=='post'||$screen->post_type!=='meydan_narrative')return;
  $postId=isset($_GET['post'])?(int)$_GET['post']:0;
  if($postId<=0)return;
  wp_enqueue_script('jquery-ui-sortable');
  wp_register_style('meydan-reflection-gui',false);wp_enqueue_style('meydan-reflection-gui');
  wp_add_inline_style('meydan-reflection-gui','.meydan-reflection-gui #meydan-reflection-list{margin:12px 0}.meydan-reflection-row{display:grid;grid-template-columns:20px 200px 1fr 1fr auto;align-items:center;gap:8px;padding:9px 0;border-top:1px solid #eee}.meydan-reflection-row select,.meydan-reflection-row input{width:100%}.meydan-reflection-row.is-busy{opacity:.5;pointer-events:none}.meydan-reflection-actions{display:flex;align-items:center;gap:8px;white-space:nowrap}.meydan-reflection-actions .is-hidden{display:none}.meydan-reflection-status{min-height:18px;color:#646970}.meydan-reflection-status.is-error{color:#b32d2e}@media(max-width:782px){.meydan-reflection-row{grid-template-columns:20px 1fr;grid-auto-flow:row}}');
  wp_localize_script('jquery-ui-sortable','MEYDAN_REFLECTIONS',[
   'restUrl'=>esc_url_raw(rest_url('meydan/v1')),
   'nonce'=>wp_create_nonce('wp_rest'),
   'narrativeId'=>$postId,
  ]);
  wp_add_inline_script('jquery-ui-sortable',<<<'JS'
jQuery(function($){
  var cfg = window.MEYDAN_REFLECTIONS || {};
  var list = $('#meydan-reflection-list');
  if (!list.length || !cfg.restUrl) return;
  var status = $('#meydan-reflection-status');
  var outlets = list.attr('data-outlets') || '';

  function say(message, isError){ status.text(message || '').toggleClass('is-error', !!isError); }
  function busy(row, on){ row.toggleClass('is-busy', !!on); }
  function rowData(row){ return {
    outlet_id: parseInt(row.find('[data-field="outlet_id"]').val(), 10) || 0,
    title: $.trim(row.find('[data-field="title"]').val()),
    url: $.trim(row.find('[data-field="url"]').val())
  }; }
  function request(method, path, data){
    return $.ajax({url: cfg.restUrl + path, method: method, dataType: 'json', contentType: 'application/json',
      beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', cfg.nonce); },
      data: data ? JSON.stringify(data) : undefined});
  }
  function messageOf(xhr){
    var body = xhr && xhr.responseJSON;
    if (body && body.message) return body.message;
    return 'ذخیره‌سازی ناموفق بود. دوباره تلاش کنید.';
  }
  function buildRow(id, values){
    var row = $('<div>', {class:'meydan-reflection-row', 'data-id':id, 'data-dirty': id ? '0' : '1'});
    row.append($('<span>', {class:'meydan-drag-handle', 'aria-hidden':'true', text:'↕'}));
    row.append($('<select>', {'data-field':'outlet_id'}).html(outlets).val(String(values.outlet_id)));
    row.append($('<input>', {type:'text', 'data-field':'title', placeholder:'تیتر بازتاب', value:values.title}));
    row.append($('<input>', {type:'url', 'data-field':'url', placeholder:'https://...', value:values.url}));
    var actions = $('<span>', {class:'meydan-reflection-actions'});
    actions.append($('<button>', {type:'button', class:'button button-primary meydan-reflection-save', text:'ذخیره'}));
    actions.append($('<button>', {type:'button', class:'button-link-delete meydan-reflection-remove', text:'حذف'}));
    row.append(actions);
    return syncRowState(row);
  }
  function syncRowState(row){
    var id = parseInt(row.attr('data-id'), 10) || 0;
    var dirty = id === 0 || row.attr('data-dirty') === '1';
    row.find('.meydan-reflection-save').toggleClass('is-hidden', !dirty);
    return row;
  }
  list.on('input change', '[data-field]', function(){
    $(this).closest('.meydan-reflection-row').attr('data-dirty', '1');
    syncRowState($(this).closest('.meydan-reflection-row'));
  });

  $('#meydan-reflection-add').on('click', function(e){
    e.preventDefault();
    var row = buildRow(0, {outlet_id:0, title:'', url:''});
    list.append(row);
    row.find('[data-field="title"]').trigger('focus');
    say('');
  });

  list.on('click', '.meydan-reflection-save', function(e){
    e.preventDefault();
    var row = $(this).closest('.meydan-reflection-row');
    var id = parseInt(row.data('id'), 10) || 0;
    var values = rowData(row);
    if (!values.outlet_id || !values.title || !values.url) { say('رسانه، تیتر و لینک را کامل وارد کنید.', true); return; }
    busy(row, true);
    var path = id ? '/admin/media-reflections/' + id : '/admin/narratives/' + cfg.narrativeId + '/media-reflections';
    request(id ? 'PATCH' : 'POST', path, values)
      .done(function(res){
        var data = res && res.data ? res.data : {};
        if (!id && data.id) row.attr('data-id', data.id);
        row.attr('data-dirty', '0');
        busy(row, false);
        syncRowState(row);
        say('بازتاب ذخیره شد.');
      })
      .fail(function(xhr){ busy(row, false); say(messageOf(xhr), true); });
  });

  list.on('click', '.meydan-reflection-remove', function(e){
    e.preventDefault();
    var row = $(this).closest('.meydan-reflection-row');
    var id = parseInt(row.data('id'), 10) || 0;
    if (!id) { row.remove(); say('ردیف حذف شد.'); return; }
    busy(row, true);
    request('DELETE', '/admin/media-reflections/' + id)
      .done(function(){ row.remove(); say('بازتاب حذف شد.'); })
      .fail(function(xhr){ busy(row, false); say(messageOf(xhr), true); });
  });

  function persistOrder(){
    var rows = list.find('.meydan-reflection-row').toArray();
    $.each(rows, function(index, el){
      var row = $(el);
      var id = parseInt(row.data('id'), 10) || 0;
      if (!id) return;
      request('PATCH', '/admin/media-reflections/' + id, {position: index + 1})
        .done(function(){ say('ترتیب ذخیره شد.'); })
        .fail(function(xhr){ say(messageOf(xhr), true); });
    });
  }
  if (list.sortable) list.sortable({handle:'.meydan-drag-handle', update:persistOrder});
  list.find('.meydan-reflection-row').each(function(){ syncRowState($(this)); });
});
JS
  );
 }
 public function actions():void
 {
  if(isset($_GET['meydan_export_stats'])&&current_user_can('manage_meydan_stats')){check_admin_referer('meydan_export_stats');$this->exportStats();}
  if(empty($_POST['meydan_admin_action']))return;$action=sanitize_key(wp_unslash($_POST['meydan_admin_action']));check_admin_referer('meydan_admin_action');global $wpdb;
  if($action==='geo_import'&&current_user_can('manage_options')){$ok=GeoManager::importFromApi();wp_safe_redirect(add_query_arg(['page'=>'meydan-geo','meydan_geo_import'=>$ok?'success':'error'],admin_url('admin.php')));exit;}
  if($action==='settings_save'&&current_user_can('manage_options')){SettingsPage::save();return;}
  if($action==='sms_test'&&current_user_can('manage_options')){$phone=OtpService::normalizePhone((string)wp_unslash($_POST['sms_test_phone']??''));if($phone===''){add_settings_error('meydan','sms_test_invalid_phone','شماره گیرنده تست معتبر نیست.','error');return;}try{$result=(new SmsProvider())->sendTest($phone,(string)random_int(100000,999999));if(is_wp_error($result)){add_settings_error('meydan','sms_test_failed','ارسال پیامک تست ناموفق بود: '.sanitize_text_field($result->get_error_message()),'error');}else{AuditLogger::log('sms_test_sent','sms',null,null,['recipient_hash'=>wp_hash($phone)]);add_settings_error('meydan','sms_test_sent','ایران‌پیامک ارسال آزمایشی را با موفقیت پذیرفت.','updated');}}catch(\Throwable $e){add_settings_error('meydan','sms_test_failed','ارسال پیامک تست ناموفق بود.','error');}return;}
  if($action==='square_status'&&current_user_can('verify_meydan_squares')){$id=(int)$_POST['id'];$status=sanitize_key(wp_unslash($_POST['status']));$before=Serializer::square($id);update_post_meta($id,'meydan_approval_status',$status);update_post_meta($id,'meydan_admin_note',sanitize_textarea_field(wp_unslash($_POST['admin_note']??'')));if($status==='approved'){update_post_meta($id,'meydan_verified',1);wp_update_post(['ID'=>$id,'post_status'=>'publish']);}elseif($status==='rejected'){update_post_meta($id,'meydan_verified',0);wp_update_post(['ID'=>$id,'post_status'=>'pending']);}elseif($status==='suspended'){update_post_meta($id,'meydan_verified',0);wp_update_post(['ID'=>$id,'post_status'=>'draft']);}AuditLogger::log('square_'.$status,'square',$id,$before,Serializer::square($id));$owner=(int)get_post_meta($id,'meydan_owner_user_id',true);if($owner&&(in_array($status,['approved','rejected'],true)))(new NotificationService())->fromTemplate($owner,$status==='approved'?'square_verified':'square_rejected',null,null,'square',$id,'/profile');}
  if($action==='speaker_update'&&current_user_can('manage_meydan_speaker_requests')){$id=(int)$_POST['id'];$before=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_speaker_requests WHERE id=%d",$id),ARRAY_A);$data=['status'=>sanitize_key(wp_unslash($_POST['status'])),'internal_note'=>sanitize_textarea_field(wp_unslash($_POST['internal_note']??'')),'assigned_manager'=>(int)($_POST['assigned_manager']??0),'updated_at'=>current_time('mysql',true)];$wpdb->update($wpdb->prefix.'meydan_speaker_requests',$data,['id'=>$id]);AuditLogger::log('speaker_request_updated','speaker_request',$id,$before,$data);if($before&&$before['requester_user_id'])(new NotificationService())->fromTemplate((int)$before['requester_user_id'],'speaker_request_status_changed',null,null,'speaker_request',$id,'/speaker-requests/'.$id,null,false,['status'=>$data['status']]);}
  if($action==='notification_broadcast'&&current_user_can('manage_meydan_notifications')){$aud=['type'=>sanitize_key(wp_unslash($_POST['audience_type']??'all'))];if(isset($_POST['audience_id']))$aud['id']=(int)$_POST['audience_id'];$n=(new NotificationService())->broadcast(sanitize_text_field(wp_unslash($_POST['title']??'')),sanitize_textarea_field(wp_unslash($_POST['body']??'')),$aud,sanitize_text_field(wp_unslash($_POST['deep_link']??''))?:null);AuditLogger::log('notification_broadcast','notification',null,null,['count'=>$n,'audience'=>$aud]);}
  if($action==='notification_create'&&current_user_can('manage_meydan_notifications')){$uid=(int)($_POST['recipient_user_id']??0);$type=in_array($_POST['type']??'',['system','admin_notice'],true)?sanitize_key(wp_unslash($_POST['type'])):'admin_notice';(new NotificationService())->create($uid,$type,null,null,'admin',null,sanitize_text_field(wp_unslash($_POST['title']??'')),sanitize_textarea_field(wp_unslash($_POST['body']??'')),sanitize_text_field(wp_unslash($_POST['deep_link']??''))?:null);AuditLogger::log('notification_created','notification',null,null,['recipient'=>$uid,'type'=>$type]);}
  if($action==='session_revoke'&&current_user_can('manage_options')){$id=(int)$_POST['id'];$before=$wpdb->get_row($wpdb->prepare("SELECT id,user_id,device_name,last_used_at,created_at,revoked_at FROM {$wpdb->prefix}meydan_sessions WHERE id=%d",$id),ARRAY_A);$wpdb->update($wpdb->prefix.'meydan_sessions',['revoked_at'=>current_time('mysql',true)],['id'=>$id]);AuditLogger::log('session_revoked','session',$id,$before,['revoked'=>true]);}
  if($action==='initiative_member_save'&&current_user_can('manage_meydan_initiatives')){$id=(int)($_POST['id']??0);$type=in_array($_POST['member_type']??'', ['user','guest'],true)?sanitize_key(wp_unslash($_POST['member_type'])):'user';$data=['initiative_id'=>(int)$_POST['initiative_id'],'member_type'=>$type,'user_id'=>$type==='user'?(int)($_POST['user_id']??0):null,'guest_id'=>$type==='guest'?sanitize_text_field(wp_unslash($_POST['guest_id']??'')):null,'joined_at'=>sanitize_text_field(wp_unslash($_POST['joined_at']??''))?:current_time('mysql',true),'status'=>sanitize_key(wp_unslash($_POST['status']??'active'))];if($id)$wpdb->update($wpdb->prefix.'meydan_initiative_members',$data,['id'=>$id]);else{$wpdb->insert($wpdb->prefix.'meydan_initiative_members',$data);$id=(int)$wpdb->insert_id;}AuditLogger::log('initiative_member_saved','initiative_member',$id,null,$data);}
  if($action==='initiative_member_delete'&&current_user_can('manage_meydan_initiatives')){$id=(int)$_POST['id'];$before=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_initiative_members WHERE id=%d",$id),ARRAY_A);$wpdb->delete($wpdb->prefix.'meydan_initiative_members',['id'=>$id]);AuditLogger::log('initiative_member_deleted','initiative_member',$id,$before,null);}
  if($action==='geo_province_save'&&current_user_can('manage_options')){$id=(int)($_POST['id']??0);$data=['name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'slug'=>sanitize_title(wp_unslash($_POST['slug']??'')),'sort_order'=>(int)($_POST['sort_order']??0),'active'=>isset($_POST['active'])?1:0];if($id)$wpdb->update($wpdb->prefix.'meydan_provinces',$data,['id'=>$id]);else{$wpdb->insert($wpdb->prefix.'meydan_provinces',$data);$id=(int)$wpdb->insert_id;}AuditLogger::log('province_saved','province',$id,null,$data);}
  if($action==='geo_city_save'&&current_user_can('manage_options')){$id=(int)($_POST['id']??0);$data=['province_id'=>(int)$_POST['province_id'],'name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'slug'=>sanitize_title(wp_unslash($_POST['slug']??'')),'sort_order'=>(int)($_POST['sort_order']??0),'active'=>isset($_POST['active'])?1:0];if($id)$wpdb->update($wpdb->prefix.'meydan_cities',$data,['id'=>$id]);else{$wpdb->insert($wpdb->prefix.'meydan_cities',$data);$id=(int)$wpdb->insert_id;}AuditLogger::log('city_saved','city',$id,null,$data);}
  if($action==='geo_delete'&&current_user_can('manage_options')){$kind=sanitize_key(wp_unslash($_POST['kind']??''));$id=(int)$_POST['id'];if($kind==='province'){$used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}meydan_cities WHERE province_id=%d",$id));if(!$used)$wpdb->delete($wpdb->prefix.'meydan_provinces',['id'=>$id]);}elseif($kind==='city')$wpdb->delete($wpdb->prefix.'meydan_cities',['id'=>$id]);AuditLogger::log('geo_deleted',$kind,$id,null,null);}
  if($action==='settings_save'&&current_user_can('manage_options')){foreach(['feature_flags','quick_actions','ranking','timeline','trends','notification_templates','api_settings'] as $key){$field='meydan_'.$key.'_json';if(isset($_POST[$field])){$v=json_decode(wp_unslash($_POST[$field]),true);if(is_array($v))update_option('meydan_'.$key,$v,false);}}AuditLogger::log('settings_updated','settings',null,null,['keys'=>array_keys($_POST)]);}
  if($action==='stats_correct'&&current_user_can('manage_meydan_stats')){$type=sanitize_key(wp_unslash($_POST['entity_type']));$id=(int)$_POST['entity_id'];$values=[];foreach(['views','likes','comments','reposts','shares','downloads','bookmarks'] as $k)if(isset($_POST[$k])&&$_POST[$k]!=='')$values[$k]=max(0,(int)$_POST[$k]);$before=$type==='content'?Stats::content($id):Stats::narrative($id);Stats::correct($type,$id,$values);AuditLogger::log('stats_adjusted',$type,$id,$before,$values);}
  wp_safe_redirect(wp_get_referer()?:admin_url('admin.php?page=meydan'));exit;
 }
 public function squareApprovals():void{global $wpdb;$q=new \WP_Query(['post_type'=>'meydan_square','post_status'=>['pending','publish','draft'],'posts_per_page'=>100,'meta_key'=>'meydan_approval_status','orderby'=>'date','order'=>'DESC']);echo '<div class="wrap"><h1>درخواست‌های تأیید میدان</h1><table class="widefat striped"><thead><tr><th>ID</th><th>نام</th><th>وضعیت</th><th>موقعیت</th><th>عملیات</th></tr></thead><tbody>';foreach($q->posts as $p){$s=Serializer::square($p);$g=$s['location']??[];echo '<tr><td>'.$p->ID.'</td><td><a href="'.esc_url(get_edit_post_link($p->ID)).'">'.esc_html($p->post_title).'</a></td><td>'.esc_html($s['approval_status']).'</td><td>'.esc_html(($g['address']??'').' '.($g['latitude']??'').' '.($g['longitude']??'')).'</td><td><form method="post">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="square_status"><input type="hidden" name="id" value="'.$p->ID.'"><select name="status"><option value="approved">Approve</option><option value="rejected">Reject</option><option value="suspended">Suspend</option><option value="pending_verification">Restore pending</option></select><input name="admin_note" placeholder="Admin note"><button class="button">اعمال</button></form></td></tr>';}echo '</tbody></table></div>';}
 public function map():void{global $wpdb;$rows=$wpdb->get_results("SELECT g.*,p.post_title FROM {$wpdb->prefix}meydan_square_geo g JOIN {$wpdb->posts} p ON p.ID=g.square_id WHERE p.post_type='meydan_square'",ARRAY_A);echo '<div class="wrap"><h1>نقشه میدان‌ها</h1><div id="meydan-admin-map" style="height:70vh"></div><script>window.MEYDAN_MAP_POINTS='.wp_json_encode($rows).';</script></div>';}
 public function speakerRequests():void{global $wpdb;$rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}meydan_speaker_requests ORDER BY created_at DESC LIMIT 300",ARRAY_A);echo '<div class="wrap"><h1>درخواست سخنران</h1><table class="widefat striped"><thead><tr><th>ID</th><th>Creator</th><th>Venue</th><th>Date</th><th>Status/Admin</th></tr></thead><tbody>';foreach($rows?:[] as $r){echo '<tr><td>'.$r['id'].'</td><td><a href="'.esc_url(get_edit_post_link((int)$r['creator_id'])).'">'.$r['creator_id'].'</a></td><td>'.esc_html($r['venue']).'</td><td>'.esc_html($r['requested_at']).'</td><td><form method="post">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="speaker_update"><input type="hidden" name="id" value="'.$r['id'].'"><select name="status">';foreach(['pending','accepted','rejected','cancelled','completed'] as $s)echo '<option '.selected($r['status'],$s,false).'>'.$s.'</option>';echo '</select><input name="assigned_manager" type="number" value="'.esc_attr((string)$r['assigned_manager']).'" placeholder="Manager ID"><input name="internal_note" value="'.esc_attr((string)$r['internal_note']).'" placeholder="Internal note"><button class="button">ذخیره</button></form></td></tr>';}echo '</tbody></table></div>';}
 public function reflections():void{global $wpdb;$nid=(int)($_GET['narrative_id']??0);$where=$nid?$wpdb->prepare(' WHERE mr.narrative_id=%d',$nid):'';$rows=$wpdb->get_results("SELECT mr.*,o.post_title AS outlet_name FROM {$wpdb->prefix}meydan_media_reflections mr LEFT JOIN {$wpdb->posts} o ON o.ID=mr.outlet_id{$where} ORDER BY mr.narrative_id DESC,mr.position ASC,mr.id ASC LIMIT 300",ARRAY_A);echo '<div class="wrap"><h1>بازتاب‌های رسانه‌ای</h1><p>ثبت و ویرایش بازتاب‌ها از روی صفحه ویرایش هر روایت انجام می‌شود. این صفحه فقط برای مرور است.</p><table class="widefat striped"><thead><tr><th>ID</th><th>روایت</th><th>رسانه</th><th>تیتر</th><th>لینک</th><th>ترتیب</th></tr></thead><tbody>';foreach($rows?:[] as $r){echo '<tr><td>'.$r['id'].'</td><td><a href="'.esc_url(get_edit_post_link((int)$r['narrative_id'])).'">'.$r['narrative_id'].'</a></td><td>'.esc_html($r['outlet_name']?:$r['outlet']).'</td><td>'.esc_html($r['title']).'</td><td><a href="'.esc_url($r['url']).'" rel="noopener noreferrer">link</a></td><td>'.$r['position'].'</td></tr>';}echo '</tbody></table></div>';}
 public function notifications():void{global $wpdb;$type=sanitize_key(wp_unslash($_GET['type']??''));$recipient=(int)($_GET['recipient']??0);$state=sanitize_key(wp_unslash($_GET['state']??''));$where=['1=1'];$args=[];if($type){$where[]='type=%s';$args[]=$type;}if($recipient){$where[]='recipient_user_id=%d';$args[]=$recipient;}if($state==='unread')$where[]='read_at IS NULL';elseif($state==='read')$where[]='read_at IS NOT NULL';$sql="SELECT * FROM {$wpdb->prefix}meydan_notifications WHERE ".implode(' AND ',$where)." ORDER BY created_at DESC LIMIT 300";if($args)$sql=$wpdb->prepare($sql,...$args);$rows=$wpdb->get_results($sql,ARRAY_A);echo '<div class="wrap"><h1>نوتیفیکیشن‌ها</h1><h2>Broadcast</h2><form method="post">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="notification_broadcast"><input name="title" required placeholder="Title"><input name="body" required placeholder="Body"><select name="audience_type"><option>all</option><option>users</option><option>squares</option><option>province</option><option>city</option></select><input type="number" name="audience_id" placeholder="Province/City ID"><input name="deep_link" placeholder="/content/123"><button class="button button-primary">Broadcast</button></form><h2>Create single</h2><form method="post">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="notification_create"><input type="number" name="recipient_user_id" required placeholder="User ID"><select name="type"><option value="admin_notice">admin_notice</option><option value="system">system</option></select><input name="title" required placeholder="Title"><input name="body" required placeholder="Body"><input name="deep_link" placeholder="Deep link"><button class="button">Create</button></form><h2>Filters</h2><form method="get"><input type="hidden" name="page" value="meydan-notifications"><input name="type" value="'.esc_attr($type).'" placeholder="type"><input type="number" name="recipient" value="'.esc_attr((string)$recipient).'" placeholder="recipient"><select name="state"><option value="">all</option><option value="unread" '.selected($state,'unread',false).'>unread</option><option value="read" '.selected($state,'read',false).'>read</option></select><button class="button">Filter</button></form><table class="widefat striped"><thead><tr><th>ID</th><th>Recipient</th><th>Type</th><th>Title</th><th>Entity</th><th>Payload</th><th>Read/Archived</th><th>Created</th></tr></thead><tbody>';foreach($rows?:[] as $r)echo '<tr><td>'.$r['id'].'</td><td>'.$r['recipient_user_id'].'</td><td>'.esc_html($r['type']).'</td><td>'.esc_html($r['title']).'</td><td>'.esc_html(($r['entity_type']??'').':'.($r['entity_id']??'')).'</td><td><code>'.esc_html(substr((string)$r['payload_json'],0,180)).'</code></td><td>'.esc_html((string)$r['read_at']).' / '.esc_html((string)$r['archived_at']).'</td><td>'.esc_html($r['created_at']).'</td></tr>';echo '</tbody></table></div>';}
 public function initiativeMembers():void{global $wpdb;$iid=(int)($_GET['initiative_id']??0);$where=$iid?$wpdb->prepare(' WHERE initiative_id=%d',$iid):'';$rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}meydan_initiative_members{$where} ORDER BY joined_at DESC LIMIT 500",ARRAY_A);echo '<div class="wrap"><h1>اعضای ابتکار</h1><form method="post">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="initiative_member_save"><input type="number" name="initiative_id" required placeholder="Initiative ID" value="'.esc_attr((string)$iid).'"><select name="member_type"><option value="user">user</option><option value="guest">guest</option></select><input type="number" name="user_id" placeholder="User ID"><input name="guest_id" placeholder="Guest UUID"><input name="status" value="active"><button class="button button-primary">Add member</button></form><table class="widefat striped"><thead><tr><th>ID</th><th>Initiative</th><th>Member</th><th>Joined</th><th>Status</th><th>Manage</th></tr></thead><tbody>';foreach($rows?:[] as $r){echo '<tr><td>'.$r['id'].'</td><td><a href="'.esc_url(get_edit_post_link((int)$r['initiative_id'])).'">'.$r['initiative_id'].'</a></td><td>'.esc_html($r['member_type'].':'.($r['user_id']?:$r['guest_id'])).'</td><td>'.esc_html($r['joined_at']).'</td><td>'.esc_html($r['status']).'</td><td><form method="post" style="display:inline">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="initiative_member_save"><input type="hidden" name="id" value="'.$r['id'].'"><input type="hidden" name="initiative_id" value="'.$r['initiative_id'].'"><input type="hidden" name="member_type" value="'.esc_attr($r['member_type']).'"><input type="hidden" name="user_id" value="'.esc_attr((string)$r['user_id']).'"><input type="hidden" name="guest_id" value="'.esc_attr((string)$r['guest_id']).'"><input name="status" value="'.esc_attr($r['status']).'" style="width:90px"><button class="button">Save</button></form> <form method="post" style="display:inline">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="initiative_member_delete"><input type="hidden" name="id" value="'.$r['id'].'"><button class="button-link-delete">Delete</button></form></td></tr>';}echo '</tbody></table></div>';}
 public function geo():void{GeoManager::render();}
 private function geoForm(string $kind,array $r,array $ps):void{echo '<form method="post" style="display:inline-flex;gap:4px;align-items:center;margin:3px">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="geo_'.$kind.'_save"><input type="hidden" name="id" value="'.esc_attr((string)($r['id']??0)).'">';if($kind==='city'){echo '<select name="province_id">';foreach($ps as $p)echo '<option value="'.$p['id'].'" '.selected((int)($r['province_id']??0),(int)$p['id'],false).'>'.esc_html($p['name']).'</option>';echo '</select>';}echo '<input name="name" required placeholder="Name" value="'.esc_attr((string)($r['name']??'')).'"><input name="slug" required placeholder="slug" value="'.esc_attr((string)($r['slug']??'')).'"><input type="number" name="sort_order" value="'.esc_attr((string)($r['sort_order']??0)).'" style="width:70px"><label><input type="checkbox" name="active" '.checked((int)($r['active']??1),1,false).'>active</label><button class="button">Save</button></form>';}
 private function geoDelete(string $kind,int $id):void{echo '<form method="post" style="display:inline">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="geo_delete"><input type="hidden" name="kind" value="'.esc_attr($kind).'"><input type="hidden" name="id" value="'.$id.'"><button class="button-link-delete">Delete</button></form>';}
 public function sessions():void{global $wpdb;$rows=$wpdb->get_results("SELECT id,user_id,device_name,access_expires_at,refresh_expires_at,last_used_at,created_at,revoked_at FROM {$wpdb->prefix}meydan_sessions ORDER BY id DESC LIMIT 500",ARRAY_A);echo '<div class="wrap"><h1>نشست‌ها</h1><p>Tokenها و hashهای آن‌ها عمداً در پنل نمایش داده نمی‌شوند. فقط revoke مجاز است.</p><table class="widefat striped"><thead><tr><th>ID</th><th>User</th><th>Device</th><th>Access Exp.</th><th>Refresh Exp.</th><th>Last Used</th><th>Revoked</th><th>Action</th></tr></thead><tbody>';foreach($rows?:[] as $r){echo '<tr><td>'.$r['id'].'</td><td>'.$r['user_id'].'</td><td>'.esc_html((string)$r['device_name']).'</td><td>'.esc_html($r['access_expires_at']).'</td><td>'.esc_html($r['refresh_expires_at']).'</td><td>'.esc_html((string)$r['last_used_at']).'</td><td>'.esc_html((string)$r['revoked_at']).'</td><td>';if(!$r['revoked_at']){echo '<form method="post">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="session_revoke"><input type="hidden" name="id" value="'.$r['id'].'"><button class="button">Revoke</button></form>';}echo '</td></tr>';}echo '</tbody></table></div>';}
 private function exportStats():void{global $wpdb;nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="meydan-stats-'.gmdate('Ymd-His').'.csv"');$out=fopen('php://output','w');fputcsv($out,['type','id','views','likes','comments','reposts','shares','downloads','bookmarks','updated_at']);foreach($wpdb->get_results("SELECT * FROM {$wpdb->prefix}meydan_narrative_stats",ARRAY_A)?:[] as $r)fputcsv($out,['narrative',$r['narrative_id'],$r['views'],$r['likes'],$r['comments'],$r['reposts'],$r['shares'],'','',$r['updated_at']]);foreach($wpdb->get_results("SELECT * FROM {$wpdb->prefix}meydan_content_stats",ARRAY_A)?:[] as $r)fputcsv($out,['content',$r['content_id'],$r['views'],'','','',$r['shares'],$r['downloads'],$r['bookmarks'],$r['updated_at']]);fclose($out);exit;}
 public function stats():void{global $wpdb;$n=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}meydan_narrative_stats ORDER BY views DESC LIMIT 100",ARRAY_A);$c=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}meydan_content_stats ORDER BY views DESC LIMIT 100",ARRAY_A);echo '<div class="wrap"><h1>آمار</h1><p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin.php?page=meydan-stats&meydan_export_stats=1'),'meydan_export_stats')).'">Export CSV</a></p><form method="post">';wp_nonce_field('meydan_admin_action');echo '<input type="hidden" name="meydan_admin_action" value="stats_correct"><select name="entity_type"><option value="narrative">Narrative</option><option value="content">Content</option></select><input type="number" name="entity_id" required placeholder="ID">';foreach(['views','likes','comments','reposts','shares','downloads','bookmarks'] as $k)echo '<input type="number" min="0" name="'.$k.'" placeholder="'.$k.'">';echo '<button class="button button-primary">Correction + Audit</button></form>';$this->simpleTable('Narrative Stats',$n);$this->simpleTable('Content Stats',$c);echo '</div>';}
 public function settings():void{SettingsPage::render();}
 public function audit():void{global $wpdb;$rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}meydan_audit_log ORDER BY id DESC LIMIT 500",ARRAY_A);echo '<div class="wrap"><h1>گزارش تغییرات</h1>';$this->simpleTable('Audit Log',$rows);echo '</div>';}
 private function simpleTable(string $title,array $rows):void{echo '<h2>'.esc_html($title).'</h2>';if(!$rows){echo '<p>داده‌ای وجود ندارد.</p>';return;}$keys=array_keys($rows[0]);echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr>';foreach($keys as $k)echo '<th>'.esc_html($k).'</th>';echo '</tr></thead><tbody>';foreach($rows as $r){echo '<tr>';foreach($keys as $k){$v=$r[$k];if(is_string($v)&&strlen($v)>160)$v=substr($v,0,160).'…';echo '<td><code>'.esc_html((string)$v).'</code></td>';}echo '</tr>';}echo '</tbody></table></div>';}
 public function userFields(\WP_User $u):void{if(!current_user_can('edit_user',$u->ID))return;echo '<h2>پروفایل میدان</h2><table class="form-table">';foreach(['account_type','full_name','headline','province_id','city_id','location_label','verified'] as $k){$v=get_user_meta($u->ID,'meydan_'.$k,true);echo '<tr><th>'.esc_html($k).'</th><td><input name="meydan_user_'.$k.'" value="'.esc_attr((string)$v).'" class="regular-text"></td></tr>';} $this->userAvatarField($u->ID);$this->userCoverField($u->ID); echo '<tr><th>About</th><td><textarea name="meydan_user_about" class="large-text">'.esc_textarea((string)get_user_meta($u->ID,'meydan_about',true)).'</textarea></td></tr><tr><th>Skills JSON</th><td><textarea name="meydan_user_skills" class="large-text code">'.esc_textarea(wp_json_encode((array)get_user_meta($u->ID,'meydan_skills',true),JSON_UNESCAPED_UNICODE)).'</textarea></td></tr></table><p>Username و password داخلی برای Login عمومی استفاده نمی‌شوند.</p>';}
 private function userAvatarField(int $uid):void{$id=(int)get_user_meta($uid,'meydan_avatar_media_id',true);$image=$id&&wp_attachment_is_image($id)?wp_get_attachment_image($id,[96,96],false,['class'=>'meydan-user-avatar-preview','style'=>'display:block;max-width:96px;height:auto;margin-bottom:8px;border-radius:50%;']):'';echo '<tr><th><label for="meydan_user_avatar_media_id">آواتار کاربر</label></th><td><div id="meydan-user-avatar-preview">'.$image.'</div><input id="meydan_user_avatar_media_id" type="hidden" name="meydan_user_avatar_media_id" value="'.esc_attr((string)$id).'" /><button type="button" class="button" id="meydan-user-avatar-select">انتخاب یا آپلود تصویر</button> <button type="button" class="button" id="meydan-user-avatar-remove"'.($id?'':' disabled').'>حذف آواتار</button><p class="description">تصویر از Media Library انتخاب یا همان‌جا آپلود می‌شود.</p></td></tr>';}
 private function userCoverField(int $uid):void{$id=(int)get_user_meta($uid,'meydan_cover_media_id',true);$image=$id&&wp_attachment_is_image($id)?wp_get_attachment_image($id,'medium',false,['style'=>'display:block;max-width:320px;height:auto;margin-bottom:8px;border-radius:8px;']):'';echo '<tr><th><label for="meydan_user_cover_media_id">کاور کاربر</label></th><td><div id="meydan-user-cover-preview">'.$image.'</div><input id="meydan_user_cover_media_id" type="hidden" name="meydan_user_cover_media_id" value="'.esc_attr((string)$id).'" /><button type="button" class="button" id="meydan-user-cover-select">انتخاب یا آپلود تصویر</button> <button type="button" class="button" id="meydan-user-cover-remove"'.($id?'':' disabled').'>حذف کاور</button><p class="description">کاور در پروفایل کاربر و میدان متصل به او نمایش داده می‌شود.</p></td></tr>';}
 public function saveUser(int $uid):void{if(!current_user_can('edit_user',$uid))return;foreach(['account_type','full_name','headline','province_id','city_id','location_label','verified'] as $k)if(isset($_POST['meydan_user_'.$k]))update_user_meta($uid,'meydan_'.$k,sanitize_text_field(wp_unslash($_POST['meydan_user_'.$k])));foreach(['avatar','cover'] as $kind)if(isset($_POST['meydan_user_'.$kind.'_media_id'])){$id=(int)$_POST['meydan_user_'.$kind.'_media_id'];if($id===0||(wp_attachment_is_image($id)&&current_user_can('edit_post',$id)))update_user_meta($uid,'meydan_'.$kind.'_media_id',$id);}if(isset($_POST['meydan_user_about']))update_user_meta($uid,'meydan_about',wp_kses_post(wp_unslash($_POST['meydan_user_about'])));if(isset($_POST['meydan_user_skills'])){$v=json_decode(wp_unslash($_POST['meydan_user_skills']),true);if(is_array($v))update_user_meta($uid,'meydan_skills',array_map('sanitize_text_field',$v));}AuditLogger::log('user_profile_updated','user',$uid,null,['admin_edit'=>true]);}
 public function syncAccountTypeForRole(int $uid,string $role):void{if($role!=='meydan_square'){update_user_meta($uid,'meydan_account_type','user');return;}$sid=(int)get_user_meta($uid,'meydan_square_id',true);if(get_post_type($sid)!=='meydan_square'){$user=get_userdata($uid);$name=(string)get_user_meta($uid,'meydan_full_name',true);$sid=wp_insert_post(['post_type'=>'meydan_square','post_status'=>'pending','post_title'=>$name?:($user?->display_name?:'میدان'),'post_content'=>(string)get_user_meta($uid,'meydan_about',true),'post_author'=>$uid],true);if(is_wp_error($sid))return;update_user_meta($uid,'meydan_square_id',(int)$sid);update_post_meta($sid,'meydan_owner_user_id',$uid);update_post_meta($sid,'meydan_approval_status','pending_verification');update_post_meta($sid,'meydan_verified',0);AuditLogger::log('square_created_from_role_change','square',(int)$sid,null,['user_id'=>$uid]);}update_user_meta($uid,'meydan_account_type','square');}
 public function assets(string $hook):void{if(!str_contains($hook,'meydan')&&!in_array(get_current_screen()?->post_type,['meydan_square','meydan_narrative','meydan_content','meydan_creator','meydan_media_outlet','meydan_initiative','meydan_campaign'],true))return;wp_enqueue_style('meydan-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',[], '1.9.4');wp_enqueue_script('meydan-leaflet','https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',[], '1.9.4',true);wp_add_inline_style('meydan-leaflet','.meydan-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px}.meydan-card{background:#fff;border:1px solid #ccd0d4;padding:18px}.meydan-card strong{display:block;font-size:28px}.meydan-inline-form{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0}.meydan-inline-form textarea{width:240px;height:38px}');wp_add_inline_script('meydan-leaflet',"document.addEventListener('DOMContentLoaded',()=>{if(!window.L)return;const box=document.getElementById('meydan-square-map');if(box){const lat=parseFloat(box.dataset.lat)||35.6892,lng=parseFloat(box.dataset.lng)||51.389;const m=L.map(box).setView([lat,lng],13);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(m);const pin=L.marker([lat,lng],{draggable:true}).addTo(m);pin.on('dragend',e=>{const p=e.target.getLatLng();document.querySelector('[name=meydan_geo_lat]').value=p.lat.toFixed(7);document.querySelector('[name=meydan_geo_lng]').value=p.lng.toFixed(7);});}const all=document.getElementById('meydan-admin-map');if(all){const m=L.map(all).setView([32.4,53.7],5);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(m);(window.MEYDAN_MAP_POINTS||[]).forEach(p=>L.marker([parseFloat(p.latitude),parseFloat(p.longitude)]).addTo(m).bindPopup('<b>'+String(p.post_title).replace(/[<>]/g,'')+'</b><br>ID '+p.square_id));}}});");}
 private function input(string $name,string $label,string $value,string $type='text',string $step='1'):void{echo '<p><label><b>'.esc_html($label).'</b><br><input class="widefat" type="'.esc_attr($type).'" step="'.esc_attr($step).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'"></label></p>';}
 private function textarea(string $name,string $label,string $value):void{echo '<p><label><b>'.esc_html($label).'</b><br><textarea class="widefat" rows="4" name="'.esc_attr($name).'">'.esc_textarea($value).'</textarea></label></p>';}
 private function select(string $name,string $label,string $value,array $opts):void{echo '<p><label><b>'.esc_html($label).'</b><br><select class="widefat" name="'.esc_attr($name).'">';foreach($opts as $k=>$v)echo '<option value="'.esc_attr((string)$k).'" '.selected($value,(string)$k,false).'>'.esc_html((string)$v).'</option>';echo '</select></label></p>';}
 private function check(string $name,string $label,bool $value):void{echo '<p><label><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($value,true,false).'> '.esc_html($label).'</label></p>';}
 public function styles():void
 {
  echo '<style id="meydan-sms-test-design">.meydan-sms-test{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-top:16px;padding:16px;border:1px dashed #99c7c1;border-radius:12px;background:#f8fffe}.meydan-sms-test p{margin:5px 0 0;color:#64748b;line-height:1.7}.meydan-sms-test-form{display:flex;align-items:flex-end;gap:12px;min-width:390px}.meydan-sms-test-form .meydan-field{flex:1}.meydan-sms-test-form .button{min-height:42px;white-space:nowrap}@media(max-width:900px){.meydan-sms-test{align-items:stretch;flex-direction:column}.meydan-sms-test-form{min-width:0;flex-direction:column;align-items:stretch}}</style>';
  $screen=get_current_screen();if(!$screen||(!str_contains((string)$screen->id,'meydan')&&!in_array($screen->post_type,['meydan_square','meydan_narrative','meydan_content','meydan_creator','meydan_media_outlet','meydan_initiative','meydan_campaign'],true)))return;
  echo '<style id="meydan-admin-design">.meydan-admin{max-width:1500px;color:#172033}.meydan-page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin:24px 0 20px;padding:28px 32px;border:1px solid #dbe3ef;border-radius:18px;background:linear-gradient(135deg,#fff,#f3f7fb);box-shadow:0 8px 30px rgba(15,23,42,.06)}.meydan-page-header h1{margin:6px 0 8px;font-size:30px}.meydan-page-header p{max-width:720px;margin:0;color:#526174;line-height:1.8}.meydan-eyebrow,.meydan-section-kicker{color:#147d73;font-size:11px;font-weight:700;letter-spacing:.08em}.meydan-header-mark{width:54px;height:54px;display:grid;place-items:center;border-radius:16px;background:#0f766e;color:#fff;font-size:25px;font-weight:800}.meydan-panel{margin:18px 0;padding:24px;border:1px solid #dbe3ef;border-radius:16px;background:#fff;box-shadow:0 5px 20px rgba(15,23,42,.04)}.meydan-panel-accent{border-top:4px solid #0f766e}.meydan-panel-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin-bottom:20px}.meydan-panel-heading h2{margin:4px 0 8px;font-size:19px}.meydan-panel-heading p{max-width:760px;margin:0;color:#64748b;line-height:1.8}.meydan-form-grid,.meydan-geo-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.meydan-field{display:flex;flex-direction:column;gap:7px}.meydan-field input,.meydan-field textarea,.meydan-field select,.meydan-inline-form input,.meydan-inline-form select{min-height:42px;border:1px solid #cbd5e1;border-radius:9px;box-shadow:none;padding:8px 11px;background:#fff;transition:border-color .2s,box-shadow .2s}.meydan-field textarea{min-height:100px;resize:vertical}.meydan-field input:focus,.meydan-field textarea:focus,.meydan-field select:focus{border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.16);outline:0}.meydan-label{font-weight:700;color:#243247}.meydan-field small,.meydan-toggle small{color:#64748b;font-size:12px;line-height:1.7}.meydan-span-2{grid-column:1/-1}.meydan-help{padding:13px 16px;border-radius:10px;background:#f0fdfa;color:#275e5a;line-height:1.8}.meydan-toggle{display:flex;align-items:flex-start;gap:12px;padding:12px;border:1px solid #dbe3ef;border-radius:10px;cursor:pointer}.meydan-toggle input{position:absolute;opacity:0}.meydan-toggle-track{width:38px;height:22px;flex:0 0 38px;border-radius:20px;background:#cbd5e1;position:relative}.meydan-toggle-track:after{content:"";position:absolute;top:3px;right:19px;width:16px;height:16px;border-radius:50%;background:#fff;transition:.2s}.meydan-toggle input:checked+.meydan-toggle-track{background:#0f766e}.meydan-toggle input:checked+.meydan-toggle-track:after{right:3px}.meydan-toggle strong{display:block;margin-bottom:3px}.meydan-configured{font-size:11px;color:#166534;font-style:normal}.meydan-advanced-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.meydan-advanced-card{border:1px solid #dbe3ef;border-radius:12px;padding:14px;background:#fbfdff}.meydan-advanced-card summary{display:flex;flex-direction:column;gap:5px;cursor:pointer;font-weight:700}.meydan-advanced-card summary small{font-weight:400;color:#64748b;line-height:1.6}.meydan-json-field{margin-top:14px}.meydan-form-actions{display:flex;align-items:center;gap:16px;margin:20px 0}.meydan-form-actions>span{color:#64748b;font-size:12px}.meydan-geo-toolbar{display:flex;align-items:center;justify-content:space-between;gap:24px;background:linear-gradient(135deg,#f0fdfa,#fff)}.meydan-stat-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:18px 0}.meydan-stat-strip>div{padding:18px 20px;border:1px solid #dbe3ef;border-radius:14px;background:#fff}.meydan-stat-strip strong{display:block;font-size:25px;color:#0f766e}.meydan-stat-strip span{color:#64748b;font-size:12px}.meydan-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:10px}.meydan-data-table{border:0;min-width:760px}.meydan-data-table th{color:#526174;font-size:12px}.meydan-data-table td,.meydan-data-table th{padding:13px 12px;vertical-align:middle}.meydan-row-actions{white-space:nowrap}.meydan-inline-form{display:inline-flex;flex-wrap:wrap;align-items:center;gap:6px;margin:0 4px 0 0}.meydan-inline-form input,.meydan-inline-form select{min-height:34px;width:125px}.meydan-inline-form input[name=name]{width:150px}.meydan-inline-form input[name=slug]{width:140px}.meydan-delete-form{display:inline}.meydan-badge{display:inline-flex;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:600}.meydan-badge.is-active{background:#dcfce7;color:#166534}.meydan-badge.is-muted{background:#f1f5f9;color:#64748b}@media(max-width:900px){.meydan-form-grid,.meydan-advanced-grid,.meydan-geo-grid{grid-template-columns:1fr}.meydan-span-2{grid-column:auto}.meydan-geo-toolbar,.meydan-page-header{flex-direction:column}.meydan-stat-strip{grid-template-columns:1fr}.meydan-panel{padding:18px}}@media(prefers-reduced-motion:reduce){.meydan-admin *{transition:none!important}}</style>';
  echo '<style>.meydan-dashboard-stats{grid-template-columns:repeat(4,1fr)}.meydan-dashboard-card{display:block;padding:20px;text-decoration:none;border:1px solid #dbe3ef;border-radius:14px;background:#fff;transition:transform .2s,box-shadow .2s}.meydan-dashboard-card:hover,.meydan-dashboard-card:focus{transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,23,42,.1);outline:2px solid #0f766e;outline-offset:2px}.meydan-dashboard-card strong{display:block;font-size:28px;color:#0f766e}.meydan-dashboard-card span{display:block;color:#172033;font-weight:700;margin:5px 0}.meydan-dashboard-card small{display:block;color:#64748b;line-height:1.7}.meydan-quick-links{display:grid;gap:10px}.meydan-quick-links a{display:flex;justify-content:space-between;gap:16px;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;text-decoration:none}.meydan-quick-links a:hover{border-color:#0f766e;background:#f0fdfa}.meydan-quick-links span{color:#64748b;font-size:12px}.meydan-api-callout{padding:22px;border-radius:12px;background:#172033;color:#fff}.meydan-api-callout code{color:#a7f3d0;font-size:16px}@media(max-width:1100px){.meydan-dashboard-stats{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.meydan-dashboard-stats{grid-template-columns:1fr}.meydan-quick-links a{flex-direction:column;gap:5px}}</style>';
 }
}

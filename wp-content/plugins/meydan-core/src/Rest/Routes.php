<?php

declare(strict_types=1);namespace Meydan\Core\Rest;
final class Routes
{
 private const NS='meydan/v1';
 public static function register():void
 {
  $auth=new AuthController();$me=new MeController();$n=new NarrativeController();$c=new CommentController();$u=new UploadController();$tl=new TimelineController();$actor=new ActorController();$content=new ContentController();$creator=new CreatorController();$spk=new SpeakerController();$square=new SquareController();$adminSquare=new AdminSquareController();$adminUser=new AdminUserController();$init=new InitiativeController();$adminProgram=new AdminProgramController();$exp=new ExploreController();$notif=new NotificationController();$mr=new MediaReflectionController();$outlet=new MediaOutletController();$speaker=new SpeakerRequestController();$adminSpeakerRequest=new AdminSpeakerRequestController();$invite=new SpeakerInvitationController();$misc=new MiscController();
  self::r('/admin/users','GET',[$adminUser,'list']);self::r('/admin/users','POST',[$adminUser,'create']);self::r('/admin/users/roles','GET',[$adminUser,'roles']);self::r('/admin/users/(?P<id>\d+)','GET',[$adminUser,'get']);self::r('/admin/users/(?P<id>\d+)','PATCH',[$adminUser,'update']);self::r('/admin/users/(?P<id>\d+)','DELETE',[$adminUser,'delete']);self::r('/admin/users/(?P<id>\d+)/status','PATCH',[$adminUser,'status']);
  self::r('/auth/otp/request','POST',[$auth,'requestOtp']);self::r('/auth/otp/verify','POST',[$auth,'verifyOtp']);self::r('/auth/refresh','POST',[$auth,'refresh']);self::r('/auth/logout','POST',[$auth,'logout']);self::r('/auth/logout-all','POST',[$auth,'logoutAll']);self::r('/auth/register/user','POST',[$auth,'registerUser']);self::r('/auth/register/square','POST',[$auth,'registerSquare']);
  self::r('/me','GET',[$me,'me']);self::r('/me/profile','PATCH',[$me,'patchProfile']);self::r('/me/narratives','GET',[$me,'narratives']);self::r('/me/following','GET',[$me,'following']);self::r('/me/initiatives','GET',[$me,'initiatives']);self::r('/me/bookmarks','GET',[$me,'bookmarks']);self::r('/me/speaker-requests','GET',[$me,'speakerRequests']);self::r('/me/square','PATCH',[$me,'patchSquare']);self::r('/me/square/location','PUT',[$me,'putSquareLocation']);self::r('/me/square/schedule','GET',[$me,'schedule']);self::r('/me/square/schedule','POST',[$me,'createSchedule']);self::r('/me/square/schedule/order','PUT',[$me,'reorderSchedule']);self::r('/me/square/schedule/(?P<id>\d+)','PATCH',[$me,'updateSchedule']);self::r('/me/square/schedule/(?P<id>\d+)','DELETE',[$me,'deleteSchedule']);
  self::r('/users/(?P<id>\d+)','GET',[$actor,'user']);self::r('/users/(?P<id>\d+)/narratives','GET',[$actor,'userNarratives']);
  self::r('/editorial/narratives','GET',[$n,'editorial']);self::r('/narratives/(?P<id>\d+)','GET',[$n,'get']);self::r('/narratives','POST',[$n,'create']);self::r('/narratives/(?P<id>\d+)','PATCH',[$n,'update']);self::r('/narratives/(?P<id>\d+)','DELETE',[$n,'delete']);self::r('/admin/narratives/(?P<id>\d+)/editorial','PUT',[$n,'markEditorial']);self::r('/admin/narratives/(?P<id>\d+)/editorial','DELETE',[$n,'unmarkEditorial']);self::r('/narratives/(?P<id>\d+)/like','PUT',[$n,'like']);self::r('/narratives/(?P<id>\d+)/like','DELETE',[$n,'unlike']);self::r('/narratives/(?P<id>\d+)/repost','PUT',[$n,'repost']);self::r('/narratives/(?P<id>\d+)/repost','DELETE',[$n,'unrepost']);self::r('/narratives/(?P<id>\d+)/share','POST',[$n,'share']);
  self::r('/narratives/(?P<id>\d+)/comments','GET',[$c,'list']);self::r('/narratives/(?P<id>\d+)/comments','POST',[$c,'create']);self::r('/comments/(?P<id>\d+)/replies','GET',[$c,'replies']);self::r('/comments/(?P<id>\d+)','PATCH',[$c,'update']);self::r('/comments/(?P<id>\d+)','DELETE',[$c,'delete']);
  self::r('/narratives/(?P<id>\d+)/media-reflections','GET',[$mr,'list']);self::r('/admin/narratives/(?P<id>\d+)/media-reflections','POST',[$mr,'create']);self::r('/admin/media-reflections/(?P<id>\d+)','PATCH',[$mr,'update']);self::r('/admin/media-reflections/(?P<id>\d+)','DELETE',[$mr,'delete']);
  self::r('/admin/narratives/(?P<id>\d+)/content','POST',[$content,'convertNarrative']);self::r('/admin/narratives/(?P<id>\d+)/content','DELETE',[$content,'removeNarrativeContent']);
  self::r('/media-outlets','GET',[$outlet,'list']);self::r('/media-outlets/(?P<id>\d+)','GET',[$outlet,'get']);self::r('/admin/media-outlets','POST',[$outlet,'adminCreate']);self::r('/admin/media-outlets/(?P<id>\d+)','PATCH',[$outlet,'adminUpdate']);self::r('/admin/media-outlets/(?P<id>\d+)','DELETE',[$outlet,'adminDelete']);
  self::r('/uploads','POST',[$u,'start']);self::r('/uploads/(?P<upload_id>[A-Za-z0-9_\-]+)/chunks/(?P<index>\d+)','PUT',[$u,'chunk']);self::r('/uploads/(?P<upload_id>[A-Za-z0-9_\-]+)/complete','POST',[$u,'complete']);self::r('/uploads/(?P<upload_id>[A-Za-z0-9_\-]+)','DELETE',[$u,'abort']);
  self::r('/timeline','GET',[$tl,'timeline']);
  self::r('/actors/(?P<type>user|square)/(?P<id>\d+)/replies','GET',[$actor,'replies']);self::r('/actors/(?P<type>user|square)/(?P<id>\d+)/follow','PUT',[$actor,'follow']);self::r('/actors/(?P<type>user|square)/(?P<id>\d+)/follow','DELETE',[$actor,'unfollow']);self::r('/actors/(?P<type>user|square)/(?P<id>\d+)/followers','GET',[$actor,'followers']);self::r('/actors/(?P<type>user|square)/(?P<id>\d+)/following','GET',[$actor,'following']);
  self::r('/content','GET',[$content,'list']);self::r('/content/(?P<id>\d+)','GET',[$content,'get']);self::r('/content/(?P<id>\d+)/bookmark','PUT',[$content,'bookmark']);self::r('/content/(?P<id>\d+)/bookmark','DELETE',[$content,'unbookmark']);self::r('/content/(?P<id>\d+)/share','POST',[$content,'share']);self::r('/content/(?P<id>\d+)/files/(?P<file_id>\d+)/download','POST',[$content,'download']);
  self::r('/admin/content','POST',[$content,'adminCreate']);self::r('/admin/content/(?P<id>\d+)','PATCH',[$content,'adminUpdate']);self::r('/admin/content/(?P<id>\d+)','DELETE',[$content,'adminDelete']);
  self::r('/creators','GET',[$creator,'list']);self::r('/creators/(?P<id>\d+)','GET',[$creator,'get']);self::r('/admin/creators','POST',[$creator,'adminCreate']);self::r('/admin/creators/(?P<id>\d+)','PATCH',[$creator,'adminUpdate']);self::r('/admin/creators/(?P<id>\d+)','DELETE',[$creator,'adminDelete']);
  self::r('/speakers','GET',[$spk,'list']);self::r('/speakers/(?P<id>\d+)','GET',[$spk,'get']);self::r('/speaker-categories','GET',[$spk,'categories']);self::r('/admin/speakers','GET',[$spk,'adminList']);self::r('/admin/speakers','POST',[$spk,'adminCreate']);self::r('/admin/speakers/new-account','POST',[$spk,'adminCreateAccount']);self::r('/admin/speakers/(?P<id>\d+)','PATCH',[$spk,'adminUpdate']);self::r('/admin/speakers/(?P<id>\d+)','DELETE',[$spk,'adminDelete']);self::r('/admin/speakers/linkable-users','GET',[$spk,'linkableUsers']);
  self::r('/admin/squares','GET',[$adminSquare,'list']);self::r('/admin/squares','POST',[$adminSquare,'create']);self::r('/admin/squares/(?P<id>\d+)','GET',[$adminSquare,'get']);self::r('/admin/squares/(?P<id>\d+)','PATCH',[$adminSquare,'update']);self::r('/admin/squares/(?P<id>\d+)','DELETE',[$adminSquare,'delete']);self::r('/admin/squares/(?P<id>\d+)/status','POST',[$adminSquare,'status']);self::r('/admin/squares/map','GET',[$adminSquare,'map']);
  self::r('/speaker-requests','POST',[$speaker,'create']);self::r('/speaker-requests/(?P<id>\d+)','GET',[$speaker,'get']);self::r('/speaker-requests/(?P<id>\d+)','DELETE',[$speaker,'delete']);
  self::r('/admin/speaker-requests','GET',[$adminSpeakerRequest,'list']);self::r('/admin/speaker-requests/(?P<id>\d+)','GET',[$adminSpeakerRequest,'get']);self::r('/admin/speaker-requests/(?P<id>\d+)','PATCH',[$adminSpeakerRequest,'update']);self::r('/admin/speaker-invitations','GET',[$adminSpeakerRequest,'list']);self::r('/admin/speaker-invitations/(?P<id>\d+)','PATCH',[$adminSpeakerRequest,'update']);
  self::r('/admin/initiatives','GET',[$adminProgram,'listInitiatives']);self::r('/admin/initiatives','POST',[$adminProgram,'createInitiative']);self::r('/admin/initiatives/(?P<id>\d+)','GET',[$adminProgram,'getInitiative']);self::r('/admin/initiatives/(?P<id>\d+)','PATCH',[$adminProgram,'updateInitiative']);self::r('/admin/initiatives/(?P<id>\d+)','DELETE',[$adminProgram,'deleteInitiative']);self::r('/admin/initiatives/(?P<id>\d+)/participants','GET',[$adminProgram,'participants']);self::r('/admin/initiatives/(?P<id>\d+)/participants/(?P<member_id>\d+)','PATCH',[$adminProgram,'updateParticipant']);self::r('/admin/campaigns','GET',[$adminProgram,'listCampaigns']);self::r('/admin/campaigns','POST',[$adminProgram,'createCampaign']);self::r('/admin/campaigns/(?P<id>\d+)','GET',[$adminProgram,'getCampaign']);self::r('/admin/campaigns/(?P<id>\d+)','PATCH',[$adminProgram,'updateCampaign']);self::r('/admin/campaigns/(?P<id>\d+)','DELETE',[$adminProgram,'deleteCampaign']);
  self::r('/speaker-invitations/speakers','GET',[$invite,'speakers']);self::r('/speaker-invitations','GET',[$invite,'list']);self::r('/speaker-invitations','POST',[$invite,'create']);self::r('/speaker-invitations/(?P<id>\d+)','GET',[$invite,'get']);self::r('/speaker-invitations/(?P<id>\d+)','PATCH',[$invite,'decide']);self::r('/speaker-invitations/(?P<id>\d+)','DELETE',[$invite,'cancel']);
  self::r('/squares','GET',[$square,'list']);self::r('/squares/map','GET',[$square,'map']);self::r('/squares/(?P<id>\d+)','GET',[$square,'get']);self::r('/squares/(?P<id>\d+)/narratives','GET',[$square,'narratives']);self::r('/squares/(?P<id>\d+)/media-reflections/count','GET',[$square,'mediaReflectionCount']);self::r('/squares/(?P<id>\d+)/schedule','GET',[$square,'schedule']);
  self::r('/geo/provinces','GET',[$misc,'provinces']);self::r('/geo/cities','GET',[$misc,'cities']);self::r('/geo/reverse','GET',[$misc,'reverse']);self::r('/initiatives','GET',[$init,'list']);self::r('/initiatives/(?P<id>\d+)','GET',[$init,'get']);self::r('/initiatives/(?P<id>\d+)/participants','GET',[$init,'participants']);self::r('/initiatives/(?P<id>\d+)/join','PUT',[$init,'join']);self::r('/initiatives/(?P<id>\d+)/join','DELETE',[$init,'leave']);
  self::r('/explore/search','GET',[$exp,'search']);self::r('/explore/trends','GET',[$exp,'trends']);self::r('/explore/suggestions','GET',[$exp,'suggestions']);
  self::r('/campaigns','GET',[$misc,'campaigns']);self::r('/campaigns/current','GET',[$misc,'currentCampaign']);self::r('/campaigns/(?P<id>\d+)','GET',[$misc,'campaign']);self::r('/campaigns/(?P<id>\d+)/schedule','GET',[$misc,'campaignSchedule']);
  self::r('/notifications','GET',[$notif,'list']);self::r('/notifications/unread-count','GET',[$notif,'unread']);self::r('/notifications/push-config','GET',[$notif,'pushConfig']);self::r('/notifications/read-all','PUT',[$notif,'readAll']);self::r('/notifications/(?P<id>\d+)/read','PUT',[$notif,'read']);self::r('/notifications/(?P<id>\d+)/read','DELETE',[$notif,'unreadOne']);self::r('/notifications/(?P<id>\d+)/archive','PUT',[$notif,'archive']);self::r('/notifications/(?P<id>\d+)/archive','DELETE',[$notif,'unarchive']);self::r('/notifications/(?P<id>\d+)','DELETE',[$notif,'delete']);self::r('/admin/notifications/broadcast','POST',[$notif,'broadcast']);self::r('/config','GET',[$misc,'config']);
 }
 private static function r(string $route,string $method,callable $callback):void
 {
  register_rest_route(self::NS,$route,['methods'=>$method,'callback'=>$callback,'permission_callback'=>self::permission($route,$method)]);
 }

 private static function permission(string $route,string $method):callable
 {
  if (str_starts_with($route,'/admin/')) {
   return static fn():bool|\WP_Error => !is_user_logged_in()
    ? new \WP_Error('unauthenticated','برای استفاده از API مدیریتی باید وارد شوید.',['status'=>401])
    : (self::isAdministrator() ? \Meydan\Core\Domain\UserAccess::adminContext() : new \WP_Error('forbidden','فقط مدیرکل می‌تواند از API مدیریتی استفاده کند.',['status'=>403]));
  }
  if (str_starts_with($route,'/auth/')) {
   if (in_array($route,['/auth/logout','/auth/logout-all'],true)) {
    return static fn():bool|\WP_Error => is_user_logged_in() ? true : new \WP_Error('unauthenticated','برای انجام این عملیات باید وارد شوید.',['status'=>401]);
   }
   return static fn():true => true;
  }
  if (str_starts_with($route,'/initiatives/(?P<id>\d+)/join')) return static fn():true => true;
  $public = ['/timeline','/content','/creators','/speakers','/speaker-categories','/squares','/squares/map','/geo/provinces','/geo/cities','/geo/reverse','/initiatives','/explore/search','/explore/trends','/explore/suggestions','/campaigns','/campaigns/current','/media-outlets','/config','/editorial/narratives'];
  $isPublic = in_array($route,$public,true)
   || ($method === 'GET' && (
    str_starts_with($route,'/content/(?P<id>')
    || str_starts_with($route,'/creators/(?P<id>')
    || str_starts_with($route,'/media-outlets/(?P<id>')
    || str_starts_with($route,'/speakers/(?P<id>')
    || str_starts_with($route,'/squares/(?P<id>')
    || str_starts_with($route,'/initiatives/(?P<id>')
    || str_starts_with($route,'/campaigns/(?P<id>')
    || str_starts_with($route,'/narratives/(?P<id>')
    || str_starts_with($route,'/actors/(?P<type>user|square)/(?P<id>')
   ))
   || str_starts_with($route,'/users/');
  if ($isPublic) return static fn():true => true;
  return static fn():bool|\WP_Error => is_user_logged_in() ? true : new \WP_Error('unauthenticated','برای انجام این عملیات باید وارد شوید.',['status'=>401]);
 }
 private static function isAdministrator():bool
 {
  $user=wp_get_current_user();
  return $user instanceof \WP_User && in_array('administrator',(array)$user->roles,true);
 }
}

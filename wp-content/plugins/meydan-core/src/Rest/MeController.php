<?php

declare(strict_types=1);
namespace Meydan\Core\Rest;

use Meydan\Core\Audit\AuditLogger;
use Meydan\Core\Support\Actor;
use Meydan\Core\Support\Cursor;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\Serializer;
use WP_Query;
use WP_REST_Request;

final class MeController extends BaseController
{
    public function me()
    {
        if ($e = $this->guard()) return $e;
        $uid = get_current_user_id();
        $type = (string) get_user_meta($uid, 'meydan_account_type', true) ?: 'user';

        if ($type === 'square') {
            $sid = (int) get_user_meta($uid, 'meydan_square_id', true);
            return Response::cache(
                Response::ok([
                    'account_type' => 'square',
                    'square' => $this->squareProfile($sid),
                ]),
                'private, no-store'
            );
        }

        return Response::cache(
            Response::ok([
                'account_type' => 'user',
                'profile' => $this->profile($uid),
            ]),
            'private, no-store'
        );
    }

    public function patchProfile(WP_REST_Request $r)
    {
        if ($e = $this->guard()) return $e;
        $uid = get_current_user_id();
        $p = $this->json($r);
        $before = $this->profile($uid);

        foreach (['full_name', 'headline', 'about', 'location_label'] as $k) {
            if (array_key_exists($k, $p)) {
                update_user_meta(
                    $uid,
                    'meydan_' . $k,
                    $k === 'about' ? wp_kses_post((string) $p[$k]) : sanitize_text_field((string) $p[$k])
                );
            }
        }

        foreach (['province_id', 'city_id', 'avatar_media_id'] as $k) {
            if (array_key_exists($k, $p)) update_user_meta($uid, 'meydan_' . $k, (int) $p[$k]);
        }

        if (isset($p['skills'])) {
            update_user_meta(
                $uid,
                'meydan_skills',
                array_values(array_filter(array_map('sanitize_text_field', (array) $p['skills'])))
            );
        }

        if (isset($p['resume_stats'])) {
            $stats = [];
            foreach ((array) $p['resume_stats'] as $item) {
                if (!is_array($item)) continue;
                $label = sanitize_text_field((string) ($item['label'] ?? ''));
                $value = sanitize_text_field((string) ($item['value'] ?? ''));
                if ($label === '' || $value === '') continue;
                $row = ['label' => $label, 'value' => $value];
                if (($item['tone'] ?? '') === 'success') $row['tone'] = 'success';
                $stats[] = $row;
            }
            update_user_meta($uid, 'meydan_resume_stats', $stats);
        }

        AuditLogger::log('profile_updated', 'user', $uid, $before, $this->profile($uid));
        return Response::ok($this->profile($uid));
    }

    public function narratives(WP_REST_Request $r)
    {
        if ($e = $this->guard()) return $e;
        $uid = get_current_user_id();
        $type = (string) get_user_meta($uid, 'meydan_account_type', true) ?: 'user';
        $actorId = $type === 'square' ? (int) get_user_meta($uid, 'meydan_square_id', true) : $uid;
        return $this->actorNarratives($type, $actorId, $r);
    }

    public function following()
    {
        if ($e = $this->guard()) return $e;
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT object_type,object_id,created_at FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND action='follow' ORDER BY id DESC LIMIT 100", get_current_user_id()), ARRAY_A);
        $data = [];
        foreach ($rows ?: [] as $row) {
            $a = Actor::parse($row['object_type'], (int) $row['object_id']);
            if ($a) {
                $a['followed_at'] = gmdate(DATE_ATOM, strtotime($row['created_at'] . ' UTC'));
                $data[] = $a;
            }
        }
        return Response::ok($data);
    }

    public function initiatives()
    {
        if ($e = $this->guard()) return $e;
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT initiative_id FROM {$wpdb->prefix}meydan_initiative_members WHERE user_id=%d AND status='active' ORDER BY joined_at DESC", get_current_user_id()));
        return Response::ok(array_values(array_filter(array_map([Serializer::class, 'initiative'], array_map('intval', $ids ?: [])))));
    }

    public function bookmarks()
    {
        if ($e = $this->guard()) return $e;
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT object_id FROM {$wpdb->prefix}meydan_interactions WHERE user_id=%d AND object_type='content' AND action='bookmark' ORDER BY created_at DESC", get_current_user_id()));
        return Response::ok(array_values(array_filter(array_map([Serializer::class, 'content'], array_map('intval', $ids ?: [])))));
    }

    public function speakerRequests()
    {
        if ($e = $this->guard()) return $e;
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_speaker_requests WHERE requester_user_id=%d ORDER BY created_at DESC", get_current_user_id()), ARRAY_A);
        return Response::ok(array_map([$this, 'speakerRow'], $rows ?: []));
    }

    public function patchSquare(WP_REST_Request $r)
    {
        if ($e = $this->guardSquare()) return $e;
        $uid = get_current_user_id();
        $sid = (int) get_user_meta($uid, 'meydan_square_id', true);
        $p = $this->json($r);
        $before = $this->squareProfile($sid);
        $post = [];
        if (isset($p['name'])) $post['post_title'] = sanitize_text_field((string) $p['name']);
        if (isset($p['description'])) $post['post_content'] = wp_kses_post((string) $p['description']);
        if ($post) wp_update_post(['ID' => $sid] + $post);
        if (isset($p['avatar_media_id'])) update_post_meta($sid, 'meydan_avatar_media_id', (int) $p['avatar_media_id']);
        if (isset($p['subtitle'])) update_post_meta($sid, 'meydan_subtitle', sanitize_text_field((string) $p['subtitle']));
        if (isset($p['profile_about'])) update_post_meta($sid, 'meydan_profile_about', wp_kses_post((string) $p['profile_about']));
        if (isset($p['profile_skills'])) update_post_meta($sid, 'meydan_profile_skills', array_values(array_filter(array_map('sanitize_text_field', (array) $p['profile_skills']))));
        AuditLogger::log('square_updated', 'square', $sid, $before, $this->squareProfile($sid));
        return Response::ok($this->squareProfile($sid));
    }

    public function putSquareLocation(WP_REST_Request $r)
    {
        if ($e = $this->guardSquare()) return $e;
        $sid = (int) get_user_meta(get_current_user_id(), 'meydan_square_id', true);
        $p = $this->json($r);
        foreach (['province_id', 'city_id', 'address', 'latitude', 'longitude'] as $k) {
            if (!isset($p[$k])) return Response::error('validation_failed', 'اطلاعات موقعیت کامل نیست.', 422, [$k => 'required']);
        }
        global $wpdb;
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_geo WHERE square_id=%d", $sid), ARRAY_A);
        $wpdb->replace($wpdb->prefix . 'meydan_square_geo', [
            'square_id' => $sid,
            'province_id' => (int) $p['province_id'],
            'city_id' => (int) $p['city_id'],
            'address' => sanitize_textarea_field((string) $p['address']),
            'latitude' => (float) $p['latitude'],
            'longitude' => (float) $p['longitude'],
            'updated_at' => current_time('mysql', true),
        ]);
        AuditLogger::log('square_location_updated', 'square', $sid, $before, $p);
        return Response::ok($this->squareProfile($sid)['location']);
    }

    public function schedule(WP_REST_Request $r)
    {
        if ($e = $this->guardSquare()) return $e;
        $sid = (int) get_user_meta(get_current_user_id(), 'meydan_square_id', true);
        return Response::ok(Serializer::squareSchedule($sid));
    }

    public function createSchedule(WP_REST_Request $r){return $this->writeSchedule($r,0);}
    public function updateSchedule(WP_REST_Request $r){return $this->writeSchedule($r,(int)$r['id']);}

    public function deleteSchedule(WP_REST_Request $r)
    {
        if ($e = $this->guardSquare()) return $e;
        $sid = (int) get_user_meta(get_current_user_id(), 'meydan_square_id', true);
        global $wpdb;
        $id = (int) $r['id'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_schedule WHERE id=%d AND square_id=%d", $id, $sid), ARRAY_A);
        if (!$row) return Response::error('not_found', 'برنامه پیدا نشد.', 404);
        $wpdb->delete($wpdb->prefix . 'meydan_square_schedule', ['id' => $id]);
        AuditLogger::log('schedule_deleted', 'schedule', $id, $row, null);
        return Response::ok(['deleted' => true]);
    }

    private function writeSchedule(WP_REST_Request $r, int $id)
    {
        if ($e = $this->guardSquare()) return $e;
        $sid = (int) get_user_meta(get_current_user_id(), 'meydan_square_id', true);
        $p = $this->json($r);
        if (empty($p['title']) || empty($p['starts_at'])) return Response::error('validation_failed', 'عنوان و زمان شروع الزامی است.', 422);
        global $wpdb;
        $data = [
            'square_id' => $sid,
            'title' => sanitize_text_field((string) $p['title']),
            'description' => sanitize_textarea_field((string) ($p['description'] ?? '')),
            'starts_at' => gmdate('Y-m-d H:i:s', strtotime((string) $p['starts_at'])),
            'ends_at' => !empty($p['ends_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $p['ends_at'])) : null,
            'location_label' => sanitize_text_field((string) ($p['location_label'] ?? '')),
            'status' => sanitize_key((string) ($p['status'] ?? 'published')),
            'position' => (int) ($p['position'] ?? 0),
            'updated_at' => current_time('mysql', true),
        ];
        $before = null;
        if ($id) {
            $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_schedule WHERE id=%d AND square_id=%d", $id, $sid), ARRAY_A);
            if (!$before) return Response::error('not_found', 'برنامه پیدا نشد.', 404);
            $wpdb->update($wpdb->prefix . 'meydan_square_schedule', $data, ['id' => $id]);
        } else {
            $data['created_at'] = current_time('mysql', true);
            $wpdb->insert($wpdb->prefix . 'meydan_square_schedule', $data);
            $id = (int) $wpdb->insert_id;
        }
        AuditLogger::log($before ? 'schedule_updated' : 'schedule_created', 'schedule', $id, $before, $data);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}meydan_square_schedule WHERE id=%d", $id), ARRAY_A);
        return Response::ok(Serializer::scheduleRow($row), $before ? 200 : 201);
    }

    private function profile(int $uid): array
    {
        $narratives = (int) (new WP_Query([
            'post_type' => 'meydan_narrative',
            'post_status' => 'publish',
            'meta_query' => [
                ['key' => 'meydan_author_actor_type', 'value' => 'user'],
                ['key' => 'meydan_author_actor_id', 'value' => $uid],
            ],
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]))->found_posts;

        $resumeStats = array_values((array) get_user_meta($uid, 'meydan_resume_stats', true));
        if (!$resumeStats) {
            $resumeStats = [
                ['value' => (string) $narratives, 'label' => 'روایت منتشرشده'],
                ['value' => 'فعال', 'label' => 'وضعیت عضویت', 'tone' => 'success'],
                ['value' => (bool) get_user_meta($uid, 'meydan_verified', true) ? 'تأییدشده' : 'عادی', 'label' => 'اعتبار هویت'],
            ];
        }

        return [
            'id' => $uid,
            'full_name' => (string) get_user_meta($uid, 'meydan_full_name', true) ?: (get_userdata($uid)?->display_name ?: 'کاربر میدان'),
            'avatar_url' => Actor::mediaUrl((int) get_user_meta($uid, 'meydan_avatar_media_id', true)),
            'headline' => (string) get_user_meta($uid, 'meydan_headline', true),
            'verified' => (bool) get_user_meta($uid, 'meydan_verified', true),
            'province_id' => (int) get_user_meta($uid, 'meydan_province_id', true) ?: null,
            'city_id' => (int) get_user_meta($uid, 'meydan_city_id', true) ?: null,
            'location_label' => (string) get_user_meta($uid, 'meydan_location_label', true),
            'about' => (string) get_user_meta($uid, 'meydan_about', true),
            'skills' => array_values((array) get_user_meta($uid, 'meydan_skills', true)),
            'resume_stats' => $resumeStats,
            'stats' => ['narratives' => $narratives],
        ];
    }

    private function squareProfile(int $sid): ?array
    {
        $data = Serializer::square($sid);
        if (!$data) return null;
        $post = get_post($sid);
        $data['slug'] = $post ? $post->post_name : (string) $sid;
        $data['handle'] = (string) get_post_meta($sid, 'meydan_handle', true);
        $data['subtitle'] = (string) get_post_meta($sid, 'meydan_subtitle', true);
        $data['profile_about'] = (string) get_post_meta($sid, 'meydan_profile_about', true);
        $data['profile_skills'] = array_values((array) get_post_meta($sid, 'meydan_profile_skills', true));
        $data['square_stats'] = array_values((array) get_post_meta($sid, 'meydan_square_stats', true));
        $data['resume_stats'] = array_values((array) get_post_meta($sid, 'meydan_resume_stats', true));
        return $data;
    }

    private function actorNarratives(string $type, int $id, WP_REST_Request $r)
    {
        $q = new WP_Query([
            'post_type' => 'meydan_narrative',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                ['key' => 'meydan_author_actor_type', 'value' => $type],
                ['key' => 'meydan_author_actor_id', 'value' => $id],
            ],
        ]);
        return Response::ok(array_values(array_filter(array_map([Serializer::class, 'narrative'], $q->posts))));
    }

    private function guard(){return is_user_logged_in()?null:Response::error('unauthenticated','برای انجام این عملیات باید وارد شوید.',401);}
    private function guardSquare(){if($e=$this->guard())return $e;return get_user_meta(get_current_user_id(),'meydan_account_type',true)==='square'?null:Response::error('forbidden','این عملیات فقط برای حساب میدان مجاز است.',403);}
    public function speakerRow(array $r):array{return ['id'=>(int)$r['id'],'creator_id'=>(int)$r['creator_id'],'venue'=>$r['venue'],'requested_at'=>gmdate(DATE_ATOM,strtotime($r['requested_at'].' UTC')),'note'=>$r['note'],'status'=>$r['status'],'created_at'=>gmdate(DATE_ATOM,strtotime($r['created_at'].' UTC'))];}
}

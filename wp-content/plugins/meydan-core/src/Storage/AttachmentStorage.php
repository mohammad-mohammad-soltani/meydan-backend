<?php

declare(strict_types=1);

namespace Meydan\Core\Storage;

final class AttachmentStorage
{
    public static function isRemote(int $attachmentId): bool
    {
        return (string) get_post_meta($attachmentId, '_meydan_storage_driver', true) === 's3';
    }

    public static function localPath(int $attachmentId): string
    {
        if ($attachmentId <= 0 || self::isRemote($attachmentId)) return '';
        return (string) get_attached_file($attachmentId);
    }

    public static function attachmentIdFromUrl(string $url): int
    {
        $publicUrl = rtrim((string) getenv('MEDIA_PUBLIC_URL'), '/');
        if ($publicUrl !== '' && str_starts_with($url, $publicUrl . '/')) {
            $key = rawurldecode(substr($url, strlen($publicUrl) + 1));
            if ($key !== '') {
                global $wpdb;
                return (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1",
                    '_meydan_storage_key',
                    $key,
                ));
            }
        }
        return function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($url) : 0;
    }
}

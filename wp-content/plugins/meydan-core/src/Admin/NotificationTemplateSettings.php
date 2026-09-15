<?php

declare(strict_types=1);

namespace Meydan\Core\Admin;

use Meydan\Core\Notifications\NotificationService;

final class NotificationTemplateSettings
{
    public static function register(): void
    {
        add_action('admin_init', [self::class, 'save'], 20);
        add_action('admin_footer', [self::class, 'renderEditor']);
    }

    public static function save(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        if (sanitize_key((string) ($_POST['meydan_admin_action'] ?? '')) !== 'settings_save') {
            return;
        }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_wpnonce'])), 'meydan_admin_action')) {
            return;
        }

        $posted = isset($_POST['notification_templates']) && is_array($_POST['notification_templates'])
            ? wp_unslash($_POST['notification_templates'])
            : [];
        $templates = (array) get_option('meydan_notification_templates', []);

        foreach (NotificationService::TEMPLATES as $type => $defaults) {
            $row = isset($posted[$type]) && is_array($posted[$type]) ? $posted[$type] : [];
            $current = isset($templates[$type]) && is_array($templates[$type]) ? $templates[$type] : [];

            $title = sanitize_text_field((string) ($row['title'] ?? ($current['title'] ?? $defaults['title'])));
            $body = sanitize_textarea_field((string) ($row['body'] ?? ($current['body'] ?? $defaults['body'])));
            $iconMediaId = max(0, (int) ($current['icon_media_id'] ?? 0));

            if (!empty($row['remove_icon'])) {
                $iconMediaId = 0;
            }

            $fileField = 'notification_icon_' . $type;
            if (!empty($_FILES[$fileField]['name'])) {
                if (!function_exists('media_handle_upload')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }

                $attachmentId = media_handle_upload($fileField, 0, [], ['test_form' => false]);
                if (is_wp_error($attachmentId)) {
                    add_settings_error(
                        'meydan',
                        'notification_icon_upload_' . $type,
                        sprintf('آپلود آیکن «%s» ناموفق بود: %s', $type, $attachmentId->get_error_message()),
                        'error'
                    );
                } elseif (!wp_attachment_is_image((int) $attachmentId)) {
                    wp_delete_attachment((int) $attachmentId, true);
                    add_settings_error(
                        'meydan',
                        'notification_icon_type_' . $type,
                        sprintf('فایل آیکن «%s» باید تصویر باشد.', $type),
                        'error'
                    );
                } else {
                    $iconMediaId = (int) $attachmentId;
                }
            }

            $templates[$type] = [
                'title' => $title !== '' ? $title : (string) $defaults['title'],
                'body' => $body !== '' ? $body : (string) $defaults['body'],
                'icon_media_id' => $iconMediaId,
            ];
        }

        update_option('meydan_notification_templates', $templates, false);
    }

    public static function renderEditor(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (sanitize_key((string) ($_GET['page'] ?? '')) !== 'meydan-settings') {
            return;
        }

        $overrides = (array) get_option('meydan_notification_templates', []);
        ?>
        <template id="meydan-notification-template-editor">
            <section class="meydan-panel meydan-notification-template-panel">
                <div class="meydan-panel-heading">
                    <div>
                        <span class="meydan-section-kicker">اعلان‌ها</span>
                        <h2>قالب و آیکن اعلان‌ها</h2>
                        <p>عنوان، متن و آیکن هر اعلان را بدون ویرایش JSON تنظیم کنید. در اعلان‌هایی که فرستنده/Actor دارند (مثل دعوت سخنران، لایک و کامنت)، آواتار همان Actor اولویت دارد و آیکن قالب فقط برای اعلان‌های بدون Actor استفاده می‌شود.</p>
                    </div>
                </div>
                <div class="meydan-notification-template-grid">
                    <?php foreach (NotificationService::TEMPLATES as $type => $defaults):
                        $custom = isset($overrides[$type]) && is_array($overrides[$type]) ? $overrides[$type] : [];
                        $title = (string) ($custom['title'] ?? $defaults['title']);
                        $body = (string) ($custom['body'] ?? $defaults['body']);
                        $mediaId = max(0, (int) ($custom['icon_media_id'] ?? 0));
                        $iconUrl = $mediaId > 0 ? (string) (wp_get_attachment_image_url($mediaId, 'thumbnail') ?: '') : '';
                        ?>
                        <article class="meydan-notification-template-card">
                            <div class="meydan-notification-template-head">
                                <div>
                                    <strong><?php echo esc_html($type); ?></strong>
                                    <small><?php echo esc_html((string) $defaults['title']); ?></small>
                                </div>
                                <div class="meydan-notification-icon-preview">
                                    <?php if ($iconUrl !== ''): ?>
                                        <img src="<?php echo esc_url($iconUrl); ?>" alt="">
                                    <?php else: ?>
                                        <span aria-hidden="true">🔔</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <label class="meydan-field">
                                <span class="meydan-label">عنوان</span>
                                <input type="text" name="notification_templates[<?php echo esc_attr($type); ?>][title]" value="<?php echo esc_attr($title); ?>">
                            </label>
                            <label class="meydan-field">
                                <span class="meydan-label">متن</span>
                                <textarea rows="3" name="notification_templates[<?php echo esc_attr($type); ?>][body]" spellcheck="false"><?php echo esc_textarea($body); ?></textarea>
                            </label>
                            <label class="meydan-field">
                                <span class="meydan-label">آیکن تصویری</span>
                                <input type="file" name="notification_icon_<?php echo esc_attr($type); ?>" accept="image/*">
                                <small>PNG، JPG، WebP یا هر فرمت تصویری مجاز وردپرس. برای Actorها آواتار شخص/میدان نمایش داده می‌شود.</small>
                            </label>
                            <?php if ($mediaId > 0): ?>
                                <label class="meydan-notification-remove-icon">
                                    <input type="checkbox" name="notification_templates[<?php echo esc_attr($type); ?>][remove_icon]" value="1">
                                    حذف آیکن فعلی
                                </label>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </template>
        <style>
            .meydan-notification-template-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}.meydan-notification-template-card{border:1px solid #dcdcde;border-radius:12px;background:#fff;padding:16px;display:grid;gap:12px}.meydan-notification-template-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.meydan-notification-template-head strong{display:block;font-family:monospace;direction:ltr;text-align:left}.meydan-notification-template-head small{display:block;margin-top:4px;color:#646970}.meydan-notification-icon-preview{width:52px;height:52px;border-radius:14px;background:#f0f0f1;display:grid;place-items:center;overflow:hidden;font-size:22px;flex:0 0 auto}.meydan-notification-icon-preview img{width:100%;height:100%;object-fit:cover}.meydan-notification-template-card input[type=text],.meydan-notification-template-card textarea{width:100%}.meydan-notification-remove-icon{display:flex;align-items:center;gap:8px;color:#b32d2e}.meydan-notification-template-panel{margin-bottom:20px}
        </style>
        <script>
            (() => {
                const form = document.querySelector('.meydan-settings-form');
                const template = document.getElementById('meydan-notification-template-editor');
                if (!form || !template) return;
                form.enctype = 'multipart/form-data';
                const oldField = form.querySelector('[name="meydan_notification_templates_json"]');
                if (oldField) {
                    const oldCard = oldField.closest('.meydan-advanced-card');
                    if (oldCard) oldCard.remove();
                    else oldField.remove();
                }
                const advanced = form.querySelector('.meydan-advanced-grid')?.closest('.meydan-panel');
                const editor = template.content.cloneNode(true);
                if (advanced) advanced.before(editor);
                else form.querySelector('.meydan-form-actions')?.before(editor);
                template.remove();
            })();
        </script>
        <?php
    }
}

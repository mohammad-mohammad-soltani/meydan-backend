<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use WP_Error;

/**
 * «شناسه کاربری» — the public, unique @handle of an account.
 *
 * The single source of truth is the `meydan_handle` user meta. A square's
 * handle is its owner account's handle; the legacy square post meta and the
 * WordPress `user_nicename` are kept in sync so older readers keep working.
 */
final class Handles
{
    public const META = 'meydan_handle';
    public const MIN = 3;
    public const MAX = 30;

    /**
     * Handles live at the root of the site (`/{handle}`), so every top-level
     * route, static path and entity name of the app is reserved. Keep it in
     * sync with the app's routes (the frontend test checks its own copy).
     */
    public const RESERVED = [
        'admin', 'administrator', 'root', 'support', 'help', 'meydan', 'official', 'system',
        'api', 'www', 'null', 'undefined', 'me', 'user', 'users', 'square', 'speaker', 'works',
        'squares', 'media', 'collective', 'collectives', 'organization', 'organizations', 'entities', 'profiles',
        'home', 'explore', 'chat', 'compose', 'content', 'initiatives', 'map', 'podcasts', 'posts', 'post',
        'profile', 'speakers', 'auth', 'direct', 'login', 'logout', 'register', 'signup', 'settings',
        'notifications', 'search', 'images', 'maps', 'fonts', 'static', 'assets', 'offline', 'favicon',
        'icon', 'manifest', 'robots', 'sitemap', 'sw', 'terms', 'privacy', 'about', 'contact', 'bookmarks', 'videos', 'drafts', 'bistcall', 'screening',
    ];

    public static function normalize(string $handle): string
    {
        $handle = strtr(trim($handle), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        return strtolower(ltrim($handle, '@'));
    }

    /** Returns the normalised handle, or a 422 WP_Error with a `handle` field. */
    public static function validate(string $raw, int $exceptUserId = 0): string|WP_Error
    {
        $handle = self::normalize($raw);
        $fail = static fn(string $message, string $code): WP_Error => new WP_Error(
            'validation_failed',
            $message,
            ['status' => 422, 'fields' => ['handle' => $code]],
        );
        if ($handle === '') return $fail('شناسه کاربری الزامی است.', 'required');
        if (!preg_match('/^[a-z0-9_]+$/', $handle)) {
            return $fail('شناسه کاربری فقط می‌تواند شامل حروف انگلیسی، عدد و _ باشد.', 'invalid');
        }
        $len = strlen($handle);
        if ($len < self::MIN || $len > self::MAX) {
            return $fail('شناسه کاربری باید بین ۳ تا ۳۰ نویسه باشد.', 'length');
        }
        if (!preg_match('/[a-z]/', $handle)) return $fail('شناسه کاربری باید حداقل یک حرف انگلیسی داشته باشد.', 'invalid');
        if (in_array($handle, self::RESERVED, true) || str_starts_with($handle, 'meydan_internal')) {
            return $fail('این شناسه کاربری قابل استفاده نیست.', 'reserved');
        }
        if (!self::available($handle, $exceptUserId)) return $fail('این شناسه کاربری قبلاً گرفته شده است.', 'taken');
        return $handle;
    }

    public static function ownerId(string $handle): int
    {
        global $wpdb;
        $handle = self::normalize($handle);
        if ($handle === '') return 0;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s ORDER BY user_id ASC LIMIT 1",
            self::META,
            $handle,
        ));
        if ($id > 0) return $id;
        // Legacy: a square handle that has no migrated owner yet.
        $post = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND LOWER(TRIM(LEADING '@' FROM meta_value)) = %s ORDER BY post_id ASC LIMIT 1",
            self::META,
            $handle,
        ));
        if ($post > 0) return Actor::squareOwnerUserId($post) ?: -$post;
        return 0;
    }

    public static function available(string $handle, int $exceptUserId = 0): bool
    {
        $owner = self::ownerId($handle);
        return $owner === 0 || ($exceptUserId > 0 && $owner === $exceptUserId);
    }

    /** The handle without `@`, or '' when the account has none yet. */
    public static function ofUser(int $userId): string
    {
        $handle = self::normalize((string) get_user_meta($userId, self::META, true));
        if ($handle !== '') return $handle;
        $squareId = (int) get_user_meta($userId, 'meydan_square_id', true);
        return $squareId > 0 ? self::normalize((string) get_post_meta($squareId, self::META, true)) : '';
    }

    /** Display form (`@name`) with a stable fallback for unmigrated accounts. */
    public static function display(int $userId): string
    {
        $handle = self::ofUser($userId);
        return '@' . ($handle !== '' ? $handle : 'user' . $userId);
    }

    /** Stores an already validated handle and mirrors it to the legacy readers. */
    public static function store(int $userId, string $handle): void
    {
        // delete + add keeps exactly one row even if legacy data left duplicates.
        if (get_user_meta($userId, self::META) !== [$handle]) {
            delete_user_meta($userId, self::META);
            add_user_meta($userId, self::META, $handle, true);
        }
        $squareId = (int) get_user_meta($userId, 'meydan_square_id', true);
        if ($squareId > 0) update_post_meta($squareId, self::META, $handle);
        $user = get_userdata($userId);
        if ($user && $user->user_nicename !== $handle) {
            wp_update_user(['ID' => $userId, 'user_nicename' => $handle]);
        }
    }

    /** Validates then stores; used wherever a person types their handle. */
    public static function set(int $userId, string $raw): string|WP_Error
    {
        $handle = self::validate($raw, $userId);
        if (is_wp_error($handle)) return $handle;
        self::store($userId, $handle);
        return $handle;
    }

    /** Keeps an existing valid handle, otherwise derives one from the name. */
    public static function ensure(int $userId, string $nameHint = ''): string
    {
        $current = self::ofUser($userId);
        if ($current !== '' && !is_wp_error(self::validate($current, $userId))) {
            $user = get_userdata($userId);
            if (get_user_meta($userId, self::META) !== [$current] || ($user && $user->user_nicename !== $current)) self::store($userId, $current);
            return $current;
        }
        if ($nameHint === '') {
            $user = get_userdata($userId);
            $nameHint = (string) get_user_meta($userId, 'meydan_full_name', true) ?: (string) ($user->display_name ?? '');
        }
        $handle = self::generate($nameHint, $userId);
        self::store($userId, $handle);
        return $handle;
    }

    /**
     * One-off migration: gives every account without a (valid, unique) handle
     * one derived from its name by transliteration. Oldest accounts keep a
     * contested handle; the later ones are renumbered.
     */
    public static function backfill(bool $force = false): void
    {
        if (!$force && get_option('meydan_handles_backfilled', null) !== null) return;
        $ids = get_users(['fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC', 'number' => -1]);
        foreach ($ids as $id) {
            $id = (int) $id;
            $squareId = (int) get_user_meta($id, 'meydan_square_id', true);
            $hint = $squareId > 0 ? (string) get_the_title($squareId) : '';
            self::ensure($id, $hint);
        }
        update_option('meydan_handles_backfilled', 1, false);
    }

    /** A free handle derived from a (Persian) name, e.g. «رضا صالحی» → reza_salehi. */
    public static function generate(string $name, int $exceptUserId = 0): string
    {
        $base = self::slugFromName($name);
        if (strlen($base) < self::MIN) $base = 'user';
        $base = rtrim(substr($base, 0, self::MAX - 5), '_');
        $candidate = $base;
        for ($i = 2; $i < 10000; $i++) {
            if (!is_wp_error(self::validate($candidate, $exceptUserId))) return $candidate;
            $candidate = $base . '_' . $i;
        }
        return $base . '_' . wp_rand(10000, 99999);
    }

    public static function slugFromName(string $name): string
    {
        $words = preg_split('/[\s\x{200c}\-_.]+/u', trim($name)) ?: [];
        $parts = [];
        foreach ($words as $word) {
            if ($word === '') continue;
            $latin = self::finglish($word);
            if ($latin !== '') $parts[] = $latin;
        }
        $slug = implode('_', $parts);
        $slug = preg_replace('/[^a-z0-9_]+/', '', strtolower($slug)) ?? '';
        return trim(preg_replace('/_+/', '_', $slug) ?? '', '_');
    }

    /** Word-level Persian → Latin ("Finglish"); Latin and digits pass through. */
    private static function finglish(string $word): string
    {
        $word = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $word) ?? $word; // harakat, tatweel
        $word = strtr($word, ['ك' => 'ک', 'ي' => 'ی', 'ى' => 'ی', 'ۀ' => 'ه', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'ؤ' => 'و']);
        $word = strtr($word, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        if (isset(self::WORDS[$word])) return self::WORDS[$word];

        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        $prevConsonant = false;
        foreach ($chars as $i => $ch) {
            if (preg_match('/[A-Za-z0-9]/', $ch)) {
                $out .= strtolower($ch);
                $prevConsonant = false;
                continue;
            }
            $first = $i === 0;
            $last = $i === count($chars) - 1;
            switch ($ch) {
                case 'ا': case 'آ':
                    $out .= 'a';
                    $prevConsonant = false;
                    continue 2;
                case 'و':
                    $out .= $first ? 'v' : 'o';
                    $prevConsonant = false;
                    continue 2;
                case 'ی':
                    $out .= $first ? 'y' : 'i';
                    $prevConsonant = false;
                    continue 2;
                case 'ع': case 'ئ': case 'ء':
                    $out .= $first || $prevConsonant ? ($ch === 'ع' ? 'a' : '') : '';
                    $prevConsonant = false;
                    continue 2;
            }
            $latin = self::LETTERS[$ch] ?? null;
            if ($latin === null) continue;
            // Persian leaves short vowels unwritten; a lone 'a' between two
            // consonants makes the result pronounceable instead of "mhmd".
            if ($prevConsonant && !$last) $out .= 'a';
            $out .= $latin;
            $prevConsonant = true;
        }
        return $out;
    }

    private const LETTERS = [
        'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ث' => 's', 'ج' => 'j', 'چ' => 'ch', 'ح' => 'h', 'خ' => 'kh',
        'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh', 'س' => 's', 'ش' => 'sh', 'ص' => 's',
        'ض' => 'z', 'ط' => 't', 'ظ' => 'z', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'gh', 'ک' => 'k', 'گ' => 'g',
        'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h',
    ];

    /** Frequent names and words whose conventional spelling the letter map cannot guess. */
    private const WORDS = [
        'محمد' => 'mohammad', 'احمد' => 'ahmad', 'علی' => 'ali', 'حسین' => 'hossein', 'حسن' => 'hasan',
        'رضا' => 'reza', 'مهدی' => 'mahdi', 'امیر' => 'amir', 'مجتبی' => 'mojtaba', 'مرتضی' => 'morteza',
        'ابراهیم' => 'ebrahim', 'اسماعیل' => 'esmaeil', 'یوسف' => 'yousef', 'یحیی' => 'yahya', 'موسی' => 'mousa',
        'عیسی' => 'isa', 'عباس' => 'abbas', 'جواد' => 'javad', 'سجاد' => 'sajjad', 'صادق' => 'sadegh',
        'باقر' => 'bagher', 'کاظم' => 'kazem', 'هادی' => 'hadi', 'مصطفی' => 'mostafa', 'سعید' => 'saeed',
        'مسعود' => 'masoud', 'مهران' => 'mehran', 'مجید' => 'majid', 'حمید' => 'hamid', 'محسن' => 'mohsen',
        'ناصر' => 'naser', 'بشیر' => 'bashir', 'میثم' => 'meysam', 'علیرضا' => 'alireza', 'محمدرضا' => 'mohammadreza',
        'محمدحسین' => 'mohammadhossein', 'محمدمهدی' => 'mohammadmahdi', 'ابوالفضل' => 'abolfazl',
        'عبدالله' => 'abdollah', 'روح‌الله' => 'rouhollah', 'روح\u{200c}الله' => 'rouhollah', 'نیما' => 'nima',
        'فاطمه' => 'fatemeh', 'زهرا' => 'zahra', 'مریم' => 'maryam', 'زینب' => 'zeynab', 'خدیجه' => 'khadijeh',
        'سمیه' => 'somayeh', 'آسیه' => 'asieh', 'رقیه' => 'roghayeh', 'سارا' => 'sara', 'نرگس' => 'narges',
        'معصومه' => 'masoumeh', 'الهه' => 'elaheh', 'ریحانه' => 'reyhaneh', 'هاجر' => 'hajar',
        'سید' => 'seyed', 'سیدة' => 'seyedeh', 'حاج' => 'haj', 'حاجی' => 'haji', 'شیخ' => 'sheikh',
        'دکتر' => 'dr', 'استاد' => 'ostad', 'مهندس' => 'mohandes', 'حجت‌الاسلام' => 'hojjat', 'حجت' => 'hojjat',
        'الاسلام' => 'eslam', 'والمسلمین' => 'muslimin', 'آیت‌الله' => 'ayatollah', 'آقا' => 'agha', 'خانم' => 'khanom',
        'میدان' => 'meydan', 'پایگاه' => 'paygah', 'مسجد' => 'masjed', 'هیئت' => 'heyat', 'هیات' => 'heyat',
        'گروه' => 'goroh', 'رسانه' => 'resaneh', 'خیابان' => 'khiaban', 'شهدای' => 'shohada', 'شهدا' => 'shohada',
        'تهران' => 'tehran', 'مشهد' => 'mashhad', 'قم' => 'qom', 'اصفهان' => 'isfahan', 'شیراز' => 'shiraz',
        'تبریز' => 'tabriz', 'یزد' => 'yazd', 'رشت' => 'rasht', 'کرمان' => 'kerman', 'اهواز' => 'ahvaz',
        'انقلاب' => 'enghelab', 'ولیعصر' => 'valiasr', 'امام' => 'emam', 'رفیعی' => 'rafiei', 'حسینی' => 'hosseini',
        'موسوی' => 'mousavi', 'ماندگاری' => 'mandegari', 'مطیعی' => 'motiee', 'پناهیان' => 'panahian',
        'سلطانی' => 'soltani', 'صالحی' => 'salehi', 'احمدی' => 'ahmadi', 'محمدی' => 'mohammadi', 'رضایی' => 'rezaei',
        'کریمی' => 'karimi', 'حیدری' => 'heydari', 'جعفری' => 'jafari', 'نوری' => 'nouri', 'کاظمی' => 'kazemi',
        'غلامرضا' => 'gholamreza', 'غلام' => 'gholam', 'میرزا' => 'mirza', 'تست' => 'test', 'آزمون' => 'azmoun',
        'پنل' => 'panel', 'مدیر' => 'modir', 'کاربر' => 'karbar', 'سخنران' => 'sokhanran',
    ];
}

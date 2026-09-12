<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use Meydan\Core\Database\Migrations;
use Meydan\Core\Domain\Registrations;

final class SeedData
{
    public const VERSION = '2026-09-10.1';

    public static function run(bool $force = false): array
    {
        if (!$force && (string) get_option('meydan_seed_version', '') === self::VERSION) {
            return ['skipped' => true, 'version' => self::VERSION];
        }

        Registrations::registerPostTypes();
        Registrations::registerTaxonomies();

        $geo = self::seedGeo();
        $creators = self::seedCreators();
        $squares = self::seedSquares($geo);
        $narratives = self::seedNarratives($squares, $geo);
        $content = self::seedContent($creators);
        $campaign = self::seedCampaign();
        self::seedQuickActions();

        // Speakers are accounts: import the seeded speaker profiles once their
        // content links exist, so the content-ref filter stays accurate.
        Migrations::migrateSpeakerUsers(true);

        update_option('meydan_seed_version', self::VERSION, false);

        return [
            'skipped' => false,
            'version' => self::VERSION,
            'provinces' => count($geo['provinces']),
            'cities' => count($geo['cities']),
            'creators' => count($creators),
            'squares' => count($squares),
            'narratives' => count($narratives),
            'content' => count($content),
            'campaign_id' => $campaign,
        ];
    }

    private static function seedGeo(): array
    {
        global $wpdb;
        $provincesTable = $wpdb->prefix . 'meydan_provinces';
        $citiesTable = $wpdb->prefix . 'meydan_cities';

        $rows = [
            ['تهران', 'tehran', [['تهران', 'tehran']]],
            ['یزد', 'yazd', [['یزد', 'yazd']]],
            ['خراسان رضوی', 'khorasan-razavi', [['مشهد', 'mashhad']]],
            ['اصفهان', 'isfahan', [['اصفهان', 'isfahan']]],
            ['فارس', 'fars', [['شیراز', 'shiraz']]],
            ['گیلان', 'gilan', [['رشت', 'rasht']]],
            ['آذربایجان شرقی', 'east-azerbaijan', [['تبریز', 'tabriz']]],
            ['کرمان', 'kerman', [['کرمان', 'kerman']]],
            ['خوزستان', 'khuzestan', [['اهواز', 'ahvaz']]],
        ];

        $provinces = [];
        $cities = [];
        foreach ($rows as $pIndex => [$provinceName, $provinceSlug, $provinceCities]) {
            $provinceId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$provincesTable} WHERE slug=%s", $provinceSlug));
            if (!$provinceId) {
                $wpdb->insert($provincesTable, [
                    'name' => $provinceName,
                    'slug' => $provinceSlug,
                    'sort_order' => $pIndex + 1,
                    'active' => 1,
                ]);
                $provinceId = (int) $wpdb->insert_id;
            }
            $provinces[$provinceSlug] = $provinceId;

            foreach ($provinceCities as $cIndex => [$cityName, $citySlug]) {
                $cityId = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$citiesTable} WHERE province_id=%d AND slug=%s",
                    $provinceId,
                    $citySlug
                ));
                if (!$cityId) {
                    $wpdb->insert($citiesTable, [
                        'province_id' => $provinceId,
                        'name' => $cityName,
                        'slug' => $citySlug,
                        'sort_order' => $cIndex + 1,
                        'active' => 1,
                    ]);
                    $cityId = (int) $wpdb->insert_id;
                }
                $cities[$citySlug] = ['id' => $cityId, 'province_id' => $provinceId];
            }
        }

        $fixturePath = dirname(__DIR__, 2) . '/data/iran-cities.json';
        $fixture = is_file($fixturePath) ? json_decode((string) file_get_contents($fixturePath), true) : null;
        if (is_array($fixture)) {
            $fixtureProvinces = [];
            foreach ((array) ($fixture['ostan'] ?? []) as $index => $province) {
                $name = sanitize_text_field((string) ($province['name'] ?? ''));
                if ($name === '') continue;
                $provinceId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$provincesTable} WHERE name=%s LIMIT 1", $name));
                if (!$provinceId) {
                    $wpdb->insert($provincesTable, ['name' => $name, 'slug' => sanitize_title($name), 'sort_order' => $index + 1, 'active' => 1]);
                    $provinceId = (int) $wpdb->insert_id;
                }
                $fixtureProvinces[(int) ($province['id'] ?? 0)] = $provinceId;
            }
            foreach ((array) ($fixture['shahr'] ?? []) as $index => $city) {
                $name = sanitize_text_field((string) ($city['name'] ?? ''));
                $provinceId = $fixtureProvinces[(int) ($city['ostan'] ?? 0)] ?? 0;
                if ($name === '' || !$provinceId) continue;
                $cityId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$citiesTable} WHERE province_id=%d AND name=%s LIMIT 1", $provinceId, $name));
                if (!$cityId) {
                    $wpdb->insert($citiesTable, ['province_id' => $provinceId, 'name' => $name, 'slug' => sanitize_title($name), 'sort_order' => $index + 1, 'active' => 1]);
                }
            }
        }

        return ['provinces' => $provinces, 'cities' => $cities];
    }

    private static function seedCreators(): array
    {
        $rows = [
            ['mahdi-mandegari', 'حجت‌الاسلام مهدی ماندگاری', 'mandegari_live', 'سخنران و پژوهشگر معارف اسلامی', 'انگیزش ایمانی، سیره شهدا و امیدآفرینی در میدان.', 'ح.م'],
            ['bashir-hosseini', 'دکتر سید بشیر حسینی', 'dr_bashirhosseini', 'پژوهشگر رسانه و ارتباطات', 'سواد رسانه‌ای، پدافند شناختی و گفتگوی چهره‌به‌چهره با جوانان.', 'د.س'],
            ['alireza-panahian', 'حجت‌الاسلام علیرضا پناهیان', 'panahian_ir', 'سخنران و پژوهشگر معارف اسلامی', 'سازمان‌دهی اجتماعی، نبرد تمدنی و شرح مبانی مقاومت.', 'ع.پ'],
            ['naser-rafiei', 'استاد ناصر رفیعی', 'rafiei_ir', 'پژوهشگر تاریخ و معارف اسلامی', 'تاریخ اسلام، مواجهه اهل‌بیت با محاصره و خطبه‌های تبیین‌گر.', 'ن.ر'],
            ['meysam-motiee', 'حاج میثم مطیعی', 'meysam_motiee', 'مداح و تولیدکننده محتوای آیینی', 'آثار صوتی و آیینی مناسب همخوانی جمعی و اجتماعات بزرگ مردمی.', 'م.م'],
            ['meydan-media', 'گروه رسانه میدان خیابان', 'meydan_media', 'تیم روایت و مستندسازی مردمی', 'ثبت و روایت تجربه‌های جمعی شهرها با تمرکز بر آدم‌ها، جزئیات و قصه‌های میدان.', 'م.خ'],
        ];

        $ids = [];
        foreach ($rows as [$slug, $name, $handle, $role, $bio, $initials]) {
            $id = self::upsertPost('meydan_creator', $slug, $name, $bio);
            update_post_meta($id, 'meydan_handle', $handle);
            update_post_meta($id, 'meydan_role', $role);
            update_post_meta($id, 'meydan_expertise', $bio);
            update_post_meta($id, 'meydan_initials', $initials);
            update_post_meta($id, 'meydan_verified', 1);
            wp_set_post_terms($id, ['speaker'], 'meydan_creator_type');
            $ids[$slug] = $id;
        }
        return $ids;
    }

    private static function seedSquares(array $geo): array
    {
        $rows = [
            ['tehran-enghelab', 'پایگاه میدان انقلاب تهران', 'tehran_enghelab', 'تهران', 'tehran', 35.7007, 51.3913, 'میدان انقلاب، تهران'],
            ['yazd-amir-chakhmaq', 'میدان امیرچخماق یزد', 'yazd_chakhmaq', 'یزد', 'yazd', 31.8974, 54.3676, 'میدان امیرچخماق، یزد'],
            ['tehran-valiasr', 'پایگاه ولیعصر تهران', 'tehran_valiasr', 'تهران', 'tehran', 35.7112, 51.4074, 'میدان ولیعصر، تهران'],
            ['mashhad-shohada', 'میدان شهدای مشهد', 'mashhad_shohada', 'مشهد', 'mashhad', 36.2978, 59.6067, 'میدان شهدا، مشهد'],
            ['isfahan-naqsh-jahan', 'میدان نقش جهان اصفهان', 'isfahan_emam', 'اصفهان', 'isfahan', 32.6574, 51.6776, 'میدان نقش جهان، اصفهان'],
            ['shiraz-shahcheragh', 'پایگاه شاهچراغ شیراز', 'shiraz_shahcheragh', 'شیراز', 'shiraz', 29.6085, 52.5459, 'شاهچراغ، شیراز'],
            ['rasht-shahrdari', 'میدان شهرداری رشت', 'rasht_shahrdari', 'رشت', 'rasht', 37.2808, 49.5832, 'میدان شهرداری، رشت'],
            ['tabriz-saat', 'میدان ساعت تبریز', 'tabriz_saat', 'تبریز', 'tabriz', 38.0725, 46.2993, 'میدان ساعت، تبریز'],
            ['kerman-arg', 'میدان ارگ کرمان', 'kerman_arg', 'کرمان', 'kerman', 30.2939, 57.0644, 'میدان ارگ، کرمان'],
            ['ahvaz-saat', 'میدان ساعت اهواز', 'ahvaz_saat', 'اهواز', 'ahvaz', 31.3183, 48.6706, 'میدان ساعت، اهواز'],
        ];

        global $wpdb;
        $ids = [];
        foreach ($rows as [$slug, $name, $handle, $cityName, $citySlug, $lat, $lng, $address]) {
            $id = self::upsertPost('meydan_square', $slug, $name, 'پایگاه مردمی فعال برای روایت، همکاری اجتماعی و برنامه‌های میدانی.');
            update_post_meta($id, 'meydan_handle', $handle);
            update_post_meta($id, 'meydan_verified', 1);
            update_post_meta($id, 'meydan_approval_status', 'approved');
            update_post_meta($id, 'meydan_city_label', $cityName);
            if (isset($geo['cities'][$citySlug])) {
                $city = $geo['cities'][$citySlug];
                $wpdb->replace($wpdb->prefix . 'meydan_square_geo', [
                    'square_id' => $id,
                    'province_id' => $city['province_id'],
                    'city_id' => $city['id'],
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'address' => $address,
                    'updated_at' => current_time('mysql', true),
                ]);
            }
            $ids[$slug] = $id;
        }

        $main = $ids['tehran-enghelab'];
        update_post_meta($main, 'meydan_subtitle', 'پایگاه شماره ۱ تهران');
        update_post_meta($main, 'meydan_profile_about', 'پژوهشگر حوزه امنیت ملی و پایداری اجتماعی. مسئول هماهنگی تریبون‌های آزاد و اعزام سخنرانان جوان به میادین شمال و مرکز تهران.');
        update_post_meta($main, 'meydan_profile_skills', ['تحلیل جنگ شناختی', 'سخنرانی ۱۰ دقیقه‌ای منبر', 'خبرنگاری میدانی']);
        update_post_meta($main, 'meydan_square_stats', [
            ['value' => '۱۲ شب', 'label' => 'تجمع مستمر'],
            ['value' => '۴۵ هزار', 'label' => 'جمعیت امشب'],
            ['value' => '۱۸ خبر', 'label' => 'بازنشر رسانه‌ای', 'tone' => 'success'],
        ]);
        update_post_meta($main, 'meydan_resume_stats', [
            ['value' => '۳۵ منبر', 'label' => 'سخنرانی ایرادشده'],
            ['value' => '۱۴ یادداشت', 'label' => 'منتشرشده در مطبوعات'],
            ['value' => 'سطح ۱', 'label' => 'رتبه تبیین‌گری', 'tone' => 'success'],
        ]);

        self::seedSquareSchedule($main);
        return $ids;
    }

    private static function seedSquareSchedule(int $squareId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'meydan_square_schedule';
        $wpdb->delete($table, ['square_id' => $squareId]);
        $date = current_time('Y-m-d');
        $items = [
            ['نماز جماعت و قرائت قرآن', '20:30:00', 1],
            ['سخنرانی حجت‌الاسلام پناهیان', '21:00:00', 2],
            ['دم هم‌خوانی با حاج میثم مطیعی', '21:30:00', 3],
        ];
        foreach ($items as [$title, $time, $position]) {
            $wpdb->insert($table, [
                'square_id' => $squareId,
                'title' => $title,
                'description' => '',
                'starts_at' => $date . ' ' . $time,
                'ends_at' => null,
                'location_label' => 'میدان انقلاب تهران',
                'status' => 'published',
                'position' => $position,
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ]);
        }
    }

    private static function seedNarratives(array $squares, array $geo): array
    {
        $rows = [
            ['meydan-enghelab', 'tehran-enghelab', 'روایت شب دوازدهم', 'پایگاه میدان انقلاب تهران', 'امشب تجمع مردم با هم‌خوانی یکدست سرود «فرمانده کل قوا» برگزار شد. طومار ۵۰ متری تجدید بیعت نیز توسط بیش از ۲۰ هزار نفر از حاضرین امضا شد.', 1100, 3, 84],
            ['amir-chakhmaq', 'yazd-amir-chakhmaq', 'پژواک ابتکار موفق میدانی', 'میدان امیرچخماق یزد', 'با کمک اصناف بازار، ایستگاه شارژ اضطراری موبایل و فلاکس‌های آب برای مردم راه‌اندازی شد. این طرح با استقبال گسترده شهروندان روبه‌رو شد.', 670, 51, 0],
            ['vali-asr-book-exchange', 'tehran-valiasr', 'ابتکار شهروندی', 'میز تبادل کتاب در پیاده‌راه ولیعصر', 'دانشجویان و کتاب‌فروشان محلی، میز تبادل کتاب راه انداختند؛ هرکس می‌تواند یک کتاب بیاورد و با کتابی دیگر همراه شود.', 428, 27, 36],
            ['shohada-mashhad', 'mashhad-shohada', 'روایت زنده میدان', 'گزارش عصرگاهی از میدان شهدا', 'گروه‌های داوطلب، مسیرهای دسترسی و محل استقرار خدمات عمومی را برای مراجعه‌کنندگان راهنمایی کردند.', 892, 44, 91],
            ['naqsh-jahan-cleanup', 'isfahan-naqsh-jahan', 'همیاری محله', 'پاک‌سازی مشارکتی مسیرهای اطراف میدان', 'کسبه و ساکنان محله با تقسیم‌بندی مسیرها، پاک‌سازی عصرگاهی را به‌صورت هماهنگ انجام دادند و برای فردا هم گروه‌های جدید ثبت‌نام کردند.', 512, 19, 62],
            ['shahcheragh-story', 'shiraz-shahcheragh', 'روایت مردم‌نگار', 'روایت کاسب‌های گذر از یک عصر آرام', 'چند نفر از کاسبان قدیمی گذر، از تغییرات محله و راه‌هایی گفتند که ارتباط همسایه‌ها را دوباره نزدیک‌تر کرده است.', 768, 38, 54],
            ['rasht-rain-shelter', 'rasht-shahrdari', 'راه‌حل محلی', 'ایستگاه‌های امانت چتر در میدان شهرداری', 'چند فروشگاه اطراف میدان با یک سامانه ساده امانت چتر موافقت کردند تا شهروندان در روزهای بارانی، مسیر کوتاه‌تری تا مقصد داشته باشند.', 639, 72, 48],
            ['tabriz-crafts', 'tabriz-saat', 'پژواک فرهنگ محلی', 'نمایش کوچک صنایع‌دستی در میدان ساعت', 'هنرمندان جوان تبریزی محصولات دست‌ساز خود را در غرفه‌های کوچک معرفی کردند و بخشی از درآمد را به آموزش هنر برای کودکان اختصاص دادند.', 724, 31, 67],
            ['kerman-night-walk', 'kerman-arg', 'مسیریابی امن', 'مسیر پیاده‌روی شبانه با همراهان محله', 'یک گروه مردمی برای ساعات شلوغ عصر، مسیرهای پیاده‌روی امن و روشن اطراف میدان را نشانه‌گذاری کرده و همراهی داوطلبانه ترتیب داده است.', 383, 22, 29],
            ['ahvaz-water-coolers', 'ahvaz-saat', 'خدمت داوطلبانه', 'تکمیل ایستگاه‌های آب خنک برای عابران', 'پس از پیشنهاد چند شهروند، گروه‌های محلی مخزن‌های آب خنک را در سه نقطه پرتردد تکمیل کردند و برنامه نگهداری نوبتی ساختند.', 946, 66, 105],
        ];

        $topic = term_exists('meydan-khiaban', 'meydan_topic');
        if (!$topic) {
            $topic = wp_insert_term('میدان خیابان', 'meydan_topic', ['slug' => 'meydan-khiaban']);
        }

        $ids = [];
        foreach ($rows as [$slug, $squareSlug, $badge, $title, $body, $likes, $comments, $reposts]) {
            $squareId = $squares[$squareSlug];
            $id = self::upsertPost('meydan_narrative', $slug, $title, $body);
            update_post_meta($id, 'meydan_author_actor_type', 'square');
            update_post_meta($id, 'meydan_author_actor_id', $squareId);
            update_post_meta($id, 'meydan_badge', $badge);
            wp_set_post_terms($id, ['میدان خیابان'], 'meydan_narrative_tag');
            wp_set_post_terms($id, ['meydan-khiaban'], 'meydan_topic');
            Stats::correct('narrative', $id, [
                'views' => max(1000, $likes * 8),
                'likes' => $likes,
                'comments' => $comments,
                'reposts' => $reposts,
                'shares' => max(1, (int) round($reposts / 2)),
            ]);
            $ids[$slug] = $id;
        }
        return $ids;
    }

    private static function seedContent(array $creators): array
    {
        $rows = [
            [
                'nahj-jihad', 'شرح نهج‌البلاغه؛ جهاد اجتماعی و سیاسی', 'شرح خطبه جهاد متناسب با روحیه ایستادگی',
                'بسته‌ای آماده برای روایت پایداری در نبرد تبیین و حضور سازمان‌یافته مردمی؛ شامل متن کامل، پوستر و نسخه مناسب انتشار.',
                'featured', 'image', true, 'پیشنهاد امشب', 'تهران، میدان انقلاب', 'alireza-panahian',
                ['این بسته با محوریت فرازهایی از خطبه جهاد نهج‌البلاغه آماده شده و تلاش می‌کند نسبت میان ایستادگی فردی، مسئولیت اجتماعی و حضور آگاهانه در میدان را روشن کند.', 'متن اصلی به‌صورت فیش‌بندی‌شده تنظیم شده تا سخنران بتواند در یک ارائه کوتاه از آن استفاده کند. نسخه تصویری نیز برای انتشار در شبکه‌های اجتماعی و نمایش روی پرده در اختیار گروه‌های میدانی قرار گرفته است.'],
                ['نهج‌البلاغه', 'جهاد تبیین', 'منبر شبانه', 'ایستادگی'], 12400, 3100,
                [['poster', 'پوستر اصلی', 'JPG', '۴٫۸ مگابایت', 'کیفیت چاپ، ۳۰۰dpi'], ['social', 'نسخه شبکه اجتماعی', 'PNG', '۱٫۲ مگابایت', 'نسبت ۴:۵، مناسب انتشار'], ['text', 'متن کامل سخنرانی', 'PDF', '۸۴۰ کیلوبایت', '۱۲ صفحه، نسخه مطالعه']],
                'بازنشر این محتوا با ذکر نام تولیدکننده و نشان «میدان خیابان» آزاد است.'
            ],
            [
                'panahian-square', 'مفهوم «میدانِ خیابان» در دفاع اجتماعی', 'فیش ۱۰ دقیقه‌ای منبر',
                'سرفصل‌های تبیین میدانِ خیابان برای استفاده در جمع‌های مردمی و حلقه‌های گفتگو.',
                'talks', 'document', false, 'فیش منبر', '', 'alireza-panahian',
                ['این فیش، مفهوم میدان خیابان را به‌عنوان فضای شکل‌گیری اعتماد عمومی و همکاری اجتماعی توضیح می‌دهد.', 'ساختار متن برای یک ارائه ۱۰ دقیقه‌ای طراحی شده و شامل مقدمه، سه محور اصلی، شواهد پیشنهادی و جمع‌بندی است.'],
                ['دفاع اجتماعی', 'فیش منبر', 'مشارکت مردمی'], 8700, 2200,
                [['pdf', 'نسخه آماده مطالعه', 'PDF', '۷۲۰ کیلوبایت', '۸ صفحه، اندازه A4'], ['docx', 'نسخه قابل ویرایش', 'DOCX', '۱۸۰ کیلوبایت', 'مناسب یادداشت‌گذاری سخنران']],
                'استفاده در منبر، حلقه‌های گفتگو و بازنشر غیرتجاری با ذکر منبع آزاد است.'
            ],
            [
                'rafiei-crisis', 'سیره اهل‌بیت در مواجهه با محاصره و بحران', 'فیش ۱۰ دقیقه‌ای منبر',
                'استناد به آیات سوره احزاب و مسیر مواسات، امید اجتماعی و مسئولیت جمعی در بحران.',
                'talks', 'document', false, 'فیش منبر', '', 'naser-rafiei',
                ['این متن با مرور نمونه‌هایی از سیره اهل‌بیت، شیوه حفظ انسجام اجتماعی در دوره‌های فشار و محاصره را بررسی می‌کند.', 'در پایان هر بخش، یک پیشنهاد عملی برای تبدیل مفاهیم تاریخی به کنش جمعی امروز ارائه شده است.'],
                ['سیره اهل‌بیت', 'مواسات', 'بحران', 'امید اجتماعی'], 6300, 1800,
                [['brief', 'فیش سخنرانی', 'PDF', '۹۶۰ کیلوبایت', '۱۰ صفحه، نسخه نهایی'], ['references', 'آیات و منابع', 'PDF', '۴۶۰ کیلوبایت', 'فهرست منابع تکمیلی']],
                'نقل بخش‌هایی از متن با حفظ امانت و ذکر نام صاحب اثر مجاز است.'
            ],
            [
                'farmandeh-song', 'دم هماهنگ: «فرمانده کل قوا»', 'با نوای حاج میثم مطیعی',
                'اجرای جمعی در اجتماع ۳۰ هزار نفری میدان انقلاب تهران؛ آماده پخش و همخوانی در برنامه‌های میدانی.',
                'audio', 'audio', false, 'صوت منتخب', 'میدان انقلاب تهران، شب دوازدهم', 'meysam-motiee',
                ['نسخه اصلی این قطعه از اجرای زنده اجتماع شب دوازدهم تهیه شده است. تدوین صوتی به‌گونه‌ای انجام شده که صدای جمعیت و ریتم همخوانی برای تمرین گروه‌ها واضح باشد.', 'برای اجرای میدانی، نسخه بدون مقدمه پیشنهاد می‌شود. متن همخوانی نیز به‌صورت جداگانه در فایل‌های دانلود قرار دارد.'],
                ['سرود', 'همخوانی', 'میدان انقلاب', 'صوت'], 24900, 8600,
                [['mp3', 'نسخه اصلی', 'MP3', '۷٫۴ مگابایت', 'کیفیت ۳۲۰kbps'], ['mobile', 'نسخه کم‌حجم', 'MP3', '۲٫۱ مگابایت', 'مناسب پیام‌رسان‌ها'], ['lyrics', 'متن همخوانی', 'PDF', '۳۲۰ کیلوبایت', 'نسخه مناسب چاپ']],
                'پخش عمومی و بازنشر این اثر در برنامه‌های مردمی با ذکر اجراکننده مجاز است.'
            ],
            [
                'enghelab-night-video', 'روایت یک شب همدلی در میدان انقلاب', 'گزارش کوتاه از آماده‌سازی تا همخوانی جمعی',
                'روایتی سه‌دقیقه‌ای از پشت صحنه، حضور خانواده‌ها و شکل‌گیری یک اجرای جمعی در میدان.',
                'featured', 'video', false, 'روایت تصویری', 'تهران، میدان انقلاب', 'meydan-media',
                ['این ویدئو از چند ساعت پیش از آغاز برنامه شروع می‌شود و آماده‌سازی گروه‌های داوطلب، رسیدن خانواده‌ها و لحظه همخوانی پایانی را دنبال می‌کند.', 'دو خروجی افقی و عمودی برای نمایش روی پرده و انتشار در شبکه‌های اجتماعی آماده شده است.'],
                ['روایت تصویری', 'مستند کوتاه', 'میدان انقلاب'], 18200, 4700,
                [['landscape', 'نسخه افقی', 'MP4', '۱۴۸ مگابایت', 'Full HD، نسبت ۱۶:۹'], ['vertical', 'نسخه عمودی', 'MP4', '۹۶ مگابایت', 'Full HD، نسبت ۹:۱۶'], ['subtitle', 'زیرنویس فارسی', 'SRT', '۱۸ کیلوبایت', 'هماهنگ با هر دو نسخه']],
                'بازنشر کامل ویدئو بدون تغییر نشان و تیتراژ پایانی مجاز است.'
            ],
        ];

        global $wpdb;
        $ids = [];
        foreach ($rows as [$slug, $title, $subtitle, $excerpt, $categorySlug, $format, $featured, $badge, $location, $creatorSlug, $paragraphs, $tags, $views, $downloads, $files, $usage]) {
            $body = '<p>' . implode('</p><p>', array_map('esc_html', $paragraphs)) . '</p>';
            $id = self::upsertPost('meydan_content', $slug, $title, $body, $excerpt);
            update_post_meta($id, 'meydan_subtitle', $subtitle);
            update_post_meta($id, 'meydan_badge', $badge);
            update_post_meta($id, 'meydan_location_label', $location);
            update_post_meta($id, 'meydan_format', $format);
            update_post_meta($id, 'meydan_featured', $featured ? 1 : 0);
            update_post_meta($id, 'meydan_usage_note', $usage);
            update_post_meta($id, 'meydan_files', array_map(static fn($f) => [
                'id' => $f[0], 'label' => $f[1], 'format' => $f[2], 'size' => $f[3], 'detail' => $f[4],
            ], $files));
            if ($format === 'audio') update_post_meta($id, 'meydan_media_duration', '۳:۱۲');
            if ($format === 'video') update_post_meta($id, 'meydan_media_duration', '۲:۴۸');

            $term = term_exists($categorySlug, 'meydan_content_category');
            if (!$term) {
                $labels = ['featured' => 'ویژه', 'talks' => 'منبر', 'audio' => 'صوت'];
                wp_insert_term($labels[$categorySlug] ?? $categorySlug, 'meydan_content_category', ['slug' => $categorySlug]);
            }
            wp_set_post_terms($id, [$categorySlug], 'meydan_content_category');
            wp_set_post_terms($id, $tags, 'meydan_content_tag');
            wp_set_post_terms($id, ['meydan-khiaban'], 'meydan_topic');

            $wpdb->delete($wpdb->prefix . 'meydan_content_creators', ['content_id' => $id]);
            if (isset($creators[$creatorSlug])) {
                $wpdb->insert($wpdb->prefix . 'meydan_content_creators', [
                    'content_id' => $id,
                    'creator_id' => $creators[$creatorSlug],
                    'position' => 0,
                    'role_label' => null,
                ]);
            }
            Stats::correct('content', $id, ['views' => $views, 'downloads' => $downloads, 'shares' => max(1, (int) round($downloads / 20)), 'bookmarks' => max(1, (int) round($downloads / 10))]);
            $ids[$slug] = $id;
        }
        return $ids;
    }

    private static function seedCampaign(): int
    {
        $id = self::upsertPost('meydan_campaign', 'campaign-nightly', 'برنامه شب‌های میدان', 'برنامه محتوایی و میدانی شب‌های جاری.');
        update_post_meta($id, 'meydan_current', 1);
        update_post_meta($id, 'meydan_labels', ['شب‌های میدان']);
        update_post_meta($id, 'meydan_schedule', [
            ['id' => 'night-1', 'night' => 'شب اول', 'number' => '۰۱', 'title' => 'شهدای رمضان', 'description' => 'آغاز خروش خیابانی', 'current' => false],
            ['id' => 'night-12', 'night' => 'امشب', 'number' => '۱۲', 'title' => 'خونخواهی و بیعت', 'description' => 'حضور ۴۰۰ شهر', 'current' => true],
            ['id' => 'night-13', 'night' => 'شب سیزدهم', 'number' => '۱۳', 'title' => 'مقاومت پایدار', 'description' => 'تجدید عهد ملی', 'current' => false],
        ]);
        return $id;
    }

    private static function seedQuickActions(): void
    {
        update_option('meydan_quick_actions', [
            ['id' => 'speakers', 'label' => 'اعزام سخنران', 'detail' => 'درخواست و پیگیری', 'icon' => 'speakers', 'href' => '/speakers'],
            ['id' => 'contact', 'label' => 'ارتباط با ما', 'detail' => 'راه‌های ارتباطی', 'icon' => 'contact'],
            ['id' => 'print', 'label' => 'چاپ پلاکارد', 'detail' => 'فایل‌های آماده چاپ', 'icon' => 'print'],
            ['id' => 'safety', 'label' => 'راهنمای ایمنی', 'detail' => 'دستورالعمل میدانی', 'icon' => 'safety'],
        ], false);
    }

    private static function upsertPost(string $type, string $slug, string $title, string $content = '', string $excerpt = ''): int
    {
        $existing = get_page_by_path($slug, OBJECT, $type);
        $post = [
            'post_type' => $type,
            'post_status' => 'publish',
            'post_name' => $slug,
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
        ];
        if ($existing) {
            $post['ID'] = (int) $existing->ID;
            $result = wp_update_post($post, true);
        } else {
            $result = wp_insert_post($post, true);
        }
        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }
        return (int) $result;
    }
}

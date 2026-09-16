<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

use WP_Error;

/**
 * Reverse geocoding from map coordinates to an address plus the panel's own
 * province/city rows.
 *
 * Shared by the public `/geo/reverse` endpoint and the wp-admin map picker, so
 * a location picked in the panel resolves exactly like one picked in the app.
 */
final class Geocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    /** Coordinates are cached at four decimals, matching the lookup key. */
    private const PRECISION = 4;

    private const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Provincial capitals, used when an operator picks only a province and
     * Nominatim has no reliable hit for the province name itself. Keyed by the
     * province name as stored in `meydan_provinces`.
     */
    private const CAPITALS = [
        'آذربایجان شرقی' => 'تبریز',
        'آذربایجان غربی' => 'ارومیه',
        'اردبیل' => 'اردبیل',
        'اصفهان' => 'اصفهان',
        'البرز' => 'کرج',
        'ایلام' => 'ایلام',
        'بوشهر' => 'بوشهر',
        'تهران' => 'تهران',
        'چهارمحال و بختیاری' => 'شهرکرد',
        'خراسان جنوبی' => 'بیرجند',
        'خراسان رضوی' => 'مشهد',
        'خراسان شمالی' => 'بجنورد',
        'خوزستان' => 'اهواز',
        'زنجان' => 'زنجان',
        'سمنان' => 'سمنان',
        'سیستان و بلوچستان' => 'زاهدان',
        'فارس' => 'شیراز',
        'قزوین' => 'قزوین',
        'قم' => 'قم',
        'کردستان' => 'سنندج',
        'کرمان' => 'کرمان',
        'کرمانشاه' => 'کرمانشاه',
        'کهگیلویه و بویراحمد' => 'یاسوج',
        'گلستان' => 'گرگان',
        'گیلان' => 'رشت',
        'لرستان' => 'خرم‌آباد',
        'مازندران' => 'ساری',
        'مرکزی' => 'اراک',
        'هرمزگان' => 'بندرعباس',
        'همدان' => 'همدان',
        'یزد' => 'یزد',
    ];

    /**
     * @return array{latitude:float,longitude:float,address:string,province_id:?int,city_id:?int,province_name:?string,city_name:?string}|WP_Error
     */
    public static function reverse(float $latitude, float $longitude): array|WP_Error
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180
            || ($latitude === 0.0 && $longitude === 0.0)) {
            return new WP_Error('validation_failed', 'مختصات موقعیت معتبر نیست.', ['status' => 422]);
        }

        $key = self::cacheKey($latitude, $longitude);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        $url = add_query_arg([
            'format' => 'jsonv2',
            'lat' => $latitude,
            'lon' => $longitude,
            'zoom' => 18,
            'addressdetails' => 1,
            'accept-language' => 'fa',
        ], self::ENDPOINT);

        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => ['User-Agent' => 'Meydan/1.0 (location picker)'],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('geocoding_unavailable', 'امکان تشخیص مکان وجود ندارد؛ دوباره تلاش کنید.', ['status' => 503]);
        }

        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($json) || empty($json['address'])) {
            return new WP_Error('geocoding_unavailable', 'مکان انتخاب‌شده شناسایی نشد.', ['status' => 422]);
        }

        $data = self::shape($latitude, $longitude, (array) $json['address'], (string) ($json['display_name'] ?? ''));
        set_transient($key, $data, self::CACHE_TTL);

        return $data;
    }

    /**
     * @param array<string,mixed> $address
     * @return array{latitude:float,longitude:float,address:string,province_id:?int,city_id:?int,province_name:?string,city_name:?string}
     */
    private static function shape(float $latitude, float $longitude, array $address, string $displayName): array
    {
        // Nominatim is inconsistent about which key carries the real place name
        // in Iran ("Tehran" arrives in `city`, "Yazd" only in `county`), so every
        // candidate is tried in order of specificity until one matches our rows.
        $provinceCandidates = self::provinceCandidates($address);
        $provinceName = self::provinceName($address);
        $cityCandidates = self::candidates($address, ['city', 'town', 'village', 'municipality', 'county', 'district', 'suburb']);
        $cityName = $cityCandidates[0] ?? '';

        $provinceId = self::firstMatch(fn(string $name): int => self::matchProvince($name), $provinceCandidates);
        $cityId = $provinceId > 0
            ? self::firstMatch(fn(string $name): int => self::matchCity($provinceId, $name), $cityCandidates)
            : 0;
        // The matched row is nicer to echo back than the raw Nominatim label.
        if ($cityId > 0) {
            $cityName = self::placeName('meydan_cities', $cityId) ?: $cityName;
        }
        if ($provinceId > 0) {
            $provinceName = self::placeName('meydan_provinces', $provinceId) ?: $provinceName;
        }

        $parts = array_filter([
            (string) ($address['road'] ?? ''),
            (string) ($address['neighbourhood'] ?? $address['suburb'] ?? ''),
            $cityName,
            $provinceName,
        ]);
        $label = implode('، ', array_unique($parts));

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => mb_substr($label !== '' ? $label : $displayName, 0, 255),
            'province_id' => $provinceId > 0 ? $provinceId : null,
            'city_id' => $cityId > 0 ? $cityId : null,
            'province_name' => $provinceName !== '' ? $provinceName : null,
            'city_name' => $cityName !== '' ? $cityName : null,
        ];
    }

    /**
     * Every non-empty value of the given address keys, in order, de-duplicated.
     *
     * @param array<string,mixed> $address
     * @param array<int,string> $keys
     * @return array<int,string>
     */
    private static function candidates(array $address, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = trim((string) ($address[$key] ?? ''));
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * The candidate province names inside a Nominatim address.
     *
     * @param array<string,mixed> $address
     * @return array<int,string>
     */
    public static function provinceCandidates(array $address): array
    {
        return self::candidates($address, ['state', 'province', 'region']);
    }

    /** The most specific province name Nominatim reported. */
    public static function provinceName(array $address): string
    {
        return self::provinceCandidates($address)[0] ?? '';
    }

    /**
     * Resolves the centre of one of the panel's own province/city rows.
     *
     * Used when an operator picks a province or city from a dropdown: the map
     * focuses there and the coordinates follow. The row names are the only input
     * because the geo tables store no coordinates.
     *
     * @return array{latitude:float,longitude:float,label:string}|WP_Error
     */
    public static function center(int $provinceId, int $cityId = 0): array|WP_Error
    {
        global $wpdb;
        $province = $provinceId > 0
            ? (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}meydan_provinces WHERE id=%d", $provinceId))
            : '';
        $city = $cityId > 0
            ? (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}meydan_cities WHERE id=%d AND province_id=%d", $cityId, $provinceId))
            : '';

        if ($city === '' && $province === '') {
            return new WP_Error('validation_failed', 'استان یا شهر انتخاب‌شده پیدا نشد.', ['status' => 422]);
        }
        if ($cityId > 0 && $city === '') {
            return new WP_Error('validation_failed', 'شهر انتخاب‌شده برای این استان ثبت نشده است.', ['status' => 422]);
        }

        // A province on its own has no reliable Nominatim hit ("استان تهران" is
        // often missing its state key), so try its capital instead: the map only
        // needs to land inside the right province.
        $searchCity = $city;
        if ($searchCity === '') {
            $searchCity = self::capitalOf($province);
        }

        $key = 'meydan_center_' . md5($provinceId . '|' . $cityId . '|' . self::normalizePlace($searchCity));
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        $args = [
            'format' => 'jsonv2',
            'limit' => 5,
            'accept-language' => 'fa',
            'countrycodes' => 'ir',
            'addressdetails' => 1,
        ];
        if ($searchCity !== '') {
            $args['city'] = $searchCity;
            if ($province !== '') {
                $args['state'] = $province;
            }
        } else {
            $args['q'] = $province . '، ایران';
        }
        $args['country'] = 'ایران';

        $url = add_query_arg($args, 'https://nominatim.openstreetmap.org/search');
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => ['User-Agent' => 'Meydan/1.0 (location picker)'],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('geocoding_unavailable', 'امکان پیدا کردن موقعیت این شهر وجود ندارد؛ دوباره تلاش کنید.', ['status' => 503]);
        }

        $rows = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($rows)) {
            $rows = [];
        }

        // A wrong hit is worse than no hit: only accept a result whose own
        // province matches the row the operator picked.
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['lat'], $row['lon'])) {
                continue;
            }
            $address = (array) ($row['address'] ?? []);
            $resolved = self::provinceName($address);
            if ($province !== '' && $resolved !== '' && self::normalizePlace($resolved) !== self::normalizePlace($province)) {
                continue;
            }
            $data = [
                'latitude' => (float) $row['lat'],
                'longitude' => (float) $row['lon'],
                'label' => $city !== '' ? $city : $province,
            ];
            set_transient($key, $data, WEEK_IN_SECONDS);

            return $data;
        }

        return new WP_Error('geocoding_unavailable', 'موقعیت این شهر پیدا نشد؛ روی نقشه جابه‌جا کنید یا مختصات را دستی وارد کنید.', ['status' => 422]);
    }

    /**
     * @param callable(string):int $resolver
     * @param array<int,string> $candidates
     */
    private static function firstMatch(callable $resolver, array $candidates): int
    {
        foreach ($candidates as $candidate) {
            $id = $resolver($candidate);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private static function placeName(string $table, int $id): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}{$table} WHERE id=%d", $id));
    }

    /** The capital of a province name, matched loosely on Persian spelling. */
    public static function capitalOf(string $province): string
    {
        if (isset(self::CAPITALS[$province])) {
            return self::CAPITALS[$province];
        }
        $needle = self::normalizePlace($province);
        if ($needle === '') {
            return '';
        }
        foreach (self::CAPITALS as $provinceName => $capital) {
            if (self::normalizePlace($provinceName) === $needle) {
                return $capital;
            }
        }

        return '';
    }

    /**
     * Resolves a geocoder name onto one of the panel's provinces.
     *
     * The table can hold two rows with the same name (the seeded list and a
     * later import), so the lowest id wins: that is the row that owns the real
     * city list.
     */
    public static function matchProvince(string $name): int
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id,name FROM {$wpdb->prefix}meydan_provinces WHERE active=1 ORDER BY id ASC",
            ARRAY_A
        ) ?: [];

        return self::placeId($rows, $name);
    }

    /** Resolves a geocoder name onto a city of the given province. */
    public static function matchCity(int $provinceId, string $name): int
    {
        if ($provinceId <= 0) {
            return 0;
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,name FROM {$wpdb->prefix}meydan_cities WHERE active=1 AND province_id=%d ORDER BY id ASC",
            $provinceId
        ), ARRAY_A) ?: [];

        return self::placeId($rows, $name);
    }

    public static function cacheKey(float $latitude, float $longitude): string
    {
        return 'meydan_reverse_' . md5(
            number_format($latitude, self::PRECISION, '.', '') . '|' . number_format($longitude, self::PRECISION, '.', '')
        );
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function placeId(array $rows, string $name): int
    {
        $needle = self::normalizePlace($name);
        if ($needle === '') {
            return 0;
        }
        foreach ($rows as $row) {
            $candidate = self::normalizePlace((string) $row['name']);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === $needle || str_contains($needle, $candidate) || str_contains($candidate, $needle)) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /** Folds Arabic/Persian letter variants and spacing before comparing. */
    private static function normalizePlace(string $value): string
    {
        $value = str_replace(['ي', 'ى', 'ك'], ['ی', 'ی', 'ک'], $value);
        $value = str_replace(['استان', 'شهرستان'], '', $value);

        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value)) ?: '';
    }
}

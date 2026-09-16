<?php

declare(strict_types=1);

namespace Meydan\Core\Admin;

/**
 * Shared Leaflet location picker used by every wp-admin map (the manual square
 * page and the square edit screen).
 *
 * The container opts in with `data-meydan-picker`, keeps its starting point in
 * `data-lat`/`data-lng`, and names the map fields through data attributes, so a
 * screen can reuse the picker without duplicating any JavaScript.
 */
final class LocationPicker
{
    public const HANDLE = 'meydan-location-picker';

    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_meydan_reverse_geocode', [self::class, 'ajaxReverse']);
        add_action('wp_ajax_meydan_place_center', [self::class, 'ajaxCenter']);
    }

    public static function assets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        $postTypes = ['meydan_square', 'meydan_narrative', 'meydan_content', 'meydan_creator', 'meydan_media_outlet', 'meydan_initiative', 'meydan_campaign'];
        $onManualPage = $screen->id === 'meydan_page_meydan-square-new';
        if (!$onManualPage && !str_contains($hook, 'meydan') && !in_array($screen->post_type, $postTypes, true)) {
            return;
        }

        wp_enqueue_style('meydan-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_style(
            'meydan-location-picker',
            plugins_url('assets/location-picker.css', MEYDAN_CORE_FILE),
            ['meydan-leaflet'],
            MEYDAN_CORE_VERSION
        );
        wp_enqueue_script('meydan-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);

        wp_register_script(self::HANDLE, '', [ 'jquery', 'meydan-leaflet' ], null, true);
        wp_enqueue_script(self::HANDLE);
        wp_add_inline_script(self::HANDLE, 'window.meydanGeo = ' . wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('meydan_reverse_geocode'),
        ], JSON_UNESCAPED_UNICODE) . ';', 'before');
        wp_add_inline_script(self::HANDLE, self::script());
    }

    /** Reverse geocodes a point for the wp-admin forms (capability + nonce guarded). */
    public static function ajaxReverse(): void
    {
        check_ajax_referer('meydan_reverse_geocode', 'nonce');
        if (!current_user_can('manage_meydan_squares')) {
            wp_send_json_error(['message' => 'دسترسی کافی ندارید.'], 403);
        }

        $result = \Meydan\Core\Support\Geocoder::reverse(
            (float) ($_GET['latitude'] ?? 0),
            (float) ($_GET['longitude'] ?? 0)
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 422);
        }

        wp_send_json_success($result);
    }

    /**
     * Resolves the centre of a province/city picked in a dropdown so the map can
     * focus there. Same capability + nonce guard as the reverse lookup.
     */
    public static function ajaxCenter(): void
    {
        check_ajax_referer('meydan_reverse_geocode', 'nonce');
        if (!current_user_can('manage_meydan_squares')) {
            wp_send_json_error(['message' => 'دسترسی کافی ندارید.'], 403);
        }

        $result = \Meydan\Core\Support\Geocoder::center(
            (int) ($_GET['province_id'] ?? 0),
            (int) ($_GET['city_id'] ?? 0)
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 422);
        }

        wp_send_json_success($result);
    }

    /** A Leaflet map to display points; opt in with id="meydan-admin-map". */
    private static function script(): string
    {
        return <<<'JS'
(function($){
  var META = 'meydanGeo';
  var messages = {
    unknown: 'نشانی این نقطه پیدا نشد.',
    failed: 'تشخیص نشانی ناموفق بود.',
    working: 'در حال تشخیص نشانی...',
    hint: 'روی نقشه کلیک کنید یا نشانگر را بکشید؛ نشانی و استان و شهر خودکار پر می‌شود.',
    noCoords: 'ابتدا عرض و طول جغرافیایی را وارد کنید.'
  };

  function say(box, text, isError){
    // The status line is usually a sibling of the map box; where a screen nests
    // the box itself, its own status element wins.
    var status = box.querySelector('[data-meydan-picker-status]')
      || (box.parentNode && box.parentNode.querySelector('[data-meydan-picker-status]'));
    if (!status) { return; }
    status.textContent = text;
    status.style.color = isError ? '#b32d2e' : '#0f766e';
  }

  function geo(){
    return window[META] || {ajaxUrl: window.ajaxurl, nonce: ''};
  }

  // wp-admin stylesheets can collapse the container to zero height, which leaves
  // the map initialised but invisible: give it an explicit, measurable height
  // before Leaflet reads the box, then re-measure once the layout settles.
  function ensureSize(box){
    var wanted = parseInt(box.dataset.height, 10) || 320;
    if (box.offsetHeight < 120) {
      box.style.setProperty('height', wanted + 'px', 'important');
      box.style.setProperty('min-height', wanted + 'px', 'important');
      box.style.setProperty('display', 'block', 'important');
    }
    return box.offsetHeight;
  }

  function start(box){
    var cfg = geo();
    var latInput = document.querySelector(box.dataset.latField || '[name=latitude]');
    var lngInput = document.querySelector(box.dataset.lngField || '[name=longitude]');
    var addressInput = document.querySelector(box.dataset.addressField || '[name=address]');
    // A screen can name the dropdowns explicitly; otherwise fall back to the
    // ids the picker was originally written for.
    var province = document.querySelector(box.dataset.provinceSelect || (box.dataset.provinceField || '[name=province_id]'));
    var city = document.querySelector(box.dataset.citySelect || (box.dataset.cityField || '[name=city_id]'));
    if (!latInput || !lngInput) { return; }

    ensureSize(box);
    var lat = parseFloat(box.dataset.lat) || 35.6892;    var lng = parseFloat(box.dataset.lng) || 51.389;
    var zoom = parseInt(box.dataset.zoom, 10) || 11;
    var map = L.map(box).setView([lat, lng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'© OpenStreetMap'}).addTo(map);
    var pin = L.marker([lat, lng], {draggable:true}).addTo(map);

    // Re-measure after the surrounding page finishes laying out (metaboxes and
    // collapsible panels can change the container box right after boot).
    var refresh = function(){ map.invalidateSize(); };
    setTimeout(refresh, 200);
    setTimeout(refresh, 1200);
    if (window.jQuery) { jQuery(window).on('load', refresh); }
    $(document).on('postbox-toggled', refresh);
    var addressTouched = false, timer = null, request = null;
    // Set while a dropdown choice owns the form: until the lookup for the place
    // it moved the pin to answers, older answers must not rewrite province/city.
    var focusPending = false;
    if (addressInput) { addressInput.addEventListener('input', function(){ addressTouched = true; }); }
    say(box, messages.hint);

    function cities(){
      try { return JSON.parse((document.getElementById('meydan-manual-cities') || {}).value || '{}'); }
      catch (err) { return {}; }
    }

    // The manual page uses a <select> fed from the city JSON; the square edit
    // screen uses a plain numeric field, which must never be emptied.
    function citySelect(){
      return city && city.tagName === 'SELECT' ? city : null;
    }

    function fillCities(){
      var select = citySelect();
      if (!province || !select) { return; }
      var list = cities()[province.value] || [];
      select.innerHTML = '';
      select.appendChild(new Option(list.length ? 'انتخاب شهر' : 'ابتدا استان را انتخاب کنید', ''));
      list.forEach(function(item){ select.appendChild(new Option(item.name, String(item.id))); });
    }

    /**
     * Moves the map (and the pin) onto the centre of a chosen province/city, so
     * picking from the dropdown focuses the map. The user's own pin position is
     * replaced because the new place has no relation to the old one.
     */
    var focusRequest = null, focusTimer = null;
    function focusPlace(){
      if (!province) { return; }
      var provinceId = parseInt(province.value, 10) || 0;
      var cityId = citySelect() ? (parseInt(citySelect().value, 10) || 0) : 0;
      if (!provinceId && !cityId) { return; }
      if (focusTimer) { clearTimeout(focusTimer); }
      focusPending = true;
      // Wait out the city cascade so a province change does not fire twice.
      focusTimer = setTimeout(function(){
        if (focusRequest) { focusRequest.abort(); }
        say(box, 'در حال پیدا کردن موقعیت روی نقشه...');
        focusRequest = $.get(cfg.ajaxUrl, {
          action: 'meydan_place_center',
          nonce: cfg.nonce,
          province_id: provinceId,
          city_id: cityId
        }).done(function(res){
          var data = res && res.success && res.data ? res.data : null;
          if (!data) {
            // Nothing moved, so the picker may go back to owning the fields.
            focusPending = false;
            say(box, (res && res.data && res.data.message) || 'موقعیت این شهر پیدا نشد.', true);
            return;
          }
          var point = {lat: data.latitude, lng: data.longitude};
          pin.setLatLng([point.lat, point.lng]);
          map.setView([point.lat, point.lng], Math.max(map.getZoom(), 12));
          defer(point);
        }).fail(function(xhr){
          focusPending = false;
          var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
          say(box, message || 'موقعیت این شهر پیدا نشد.', true);
        });
      }, 150);
    }

    if (province) {
      province.addEventListener('change', function(){ fillCities(); focusPlace(); });
    }
    if (city) {
      city.addEventListener('change', function(){
        if (city.tagName === 'SELECT' && city.value === '') { return; }
        focusPlace();
      });
    }

    function setCoords(point){
      latInput.value = point.lat.toFixed(7);
      lngInput.value = point.lng.toFixed(7);
    }

    function apply(data){
      // Only an answer about where the pin actually is may drive the form; a late
      // reply for a place the operator has already left is discarded.
      var atPin = Math.abs((parseFloat(data.latitude) || 0) - pin.getLatLng().lat) < 0.0005
        && Math.abs((parseFloat(data.longitude) || 0) - pin.getLatLng().lng) < 0.0005;
      if (!atPin) { return; }
      if (focusPending) {
        // This is the answer for the dropdown choice, so it may refine the city
        // but must never replace the province the operator selected.
        focusPending = false;
        if (city && data.city_id && citySelect()) { city.value = String(data.city_id); }
      } else if (province && data.province_id) {
        province.value = String(data.province_id);
        fillCities();
        if (city && data.city_id) { city.value = String(data.city_id); }
      } else if (city && data.city_id) {
        city.value = String(data.city_id);
      }
      var addressOk = true;
      if (addressInput && data.address && !addressTouched && city && city.value !== '') {
        // Only accept an address that actually mentions the city it claims.
        var wanted = (city.options && city.options[city.selectedIndex]
          ? city.options[city.selectedIndex].text : '').trim();
        if (wanted !== '' && data.address.indexOf(wanted) === -1) { addressOk = false; }
      }
      if (addressInput && data.address && !addressTouched && addressOk) { addressInput.value = data.address; }
      if (province && data.province_name && !citySelect()) {
        // Numeric fields give no feedback, so name what was resolved.
        box.dataset.resolved = [data.province_name, data.city_name].filter(Boolean).join(' / ');
      }
      var bits = [];
      if (data.address) { bits.push('نشانی ثبت شد'); }
      if (data.province_name) { bits.push(data.province_name); }
      if (data.city_name) { bits.push(data.city_name); }
      if (!data.province_id) { bits.push('استان و شهر را دستی انتخاب کنید'); }
      say(box, bits.join(' — ') || 'موقعیت ثبت شد');
      $(document).trigger('meydan:location-picked', [data, box]);
    }

    function suggest(point){
      say(box, messages.working);
      if (request) { request.abort(); }
      request = $.get(cfg.ajaxUrl, {
        action: 'meydan_reverse_geocode',
        nonce: cfg.nonce,
        latitude: point.lat.toFixed(7),
        longitude: point.lng.toFixed(7)
      }).done(function(res){
        var data = res && res.success && res.data ? res.data : null;
        if (!data) { say(box, (res && res.data && res.data.message) || messages.unknown, true); return; }
        apply(data);
      }).fail(function(xhr){
        var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
        say(box, message || messages.failed, true);
      });
    }

    // Debounced so dragging or successive clicks do not hammer the geocoder.
    function defer(point){
      setCoords(point);
      if (timer) { clearTimeout(timer); }
      timer = setTimeout(function(){ suggest(point); }, 400);
    }

    pin.on('dragend', function(e){ defer(e.target.getLatLng()); });
    map.on('click', function(e){ box.__meydanPick(e.latlng); });

    // Shared by the map click handler and the UI test hook.
    box.__meydanPick = function(point){
      pin.setLatLng([point.lat, point.lng]);
      defer(point);
    };

    var lookup = box.parentNode ? box.parentNode.querySelector('[data-meydan-picker-lookup]') : null;
    if (lookup) {
      lookup.addEventListener('click', function(e){
        e.preventDefault();
        var point = {lat: parseFloat(latInput.value), lng: parseFloat(lngInput.value)};
        if (isNaN(point.lat) || isNaN(point.lng)) { say(box, messages.noCoords, true); return; }
        pin.setLatLng([point.lat, point.lng]);
        map.setView([point.lat, point.lng], map.getZoom());
        suggest(point);
      });
    }

    var locate = box.parentNode ? box.parentNode.querySelector('[data-meydan-picker-locate]') : null;
    if (locate) {
      locate.addEventListener('click', function(e){
        e.preventDefault();
        if (!navigator.geolocation) { say(box, 'مرورگر از موقعیت‌یابی پشتیبانی نمی‌کند.', true); return; }
        say(box, 'در حال گرفتن موقعیت فعلی...');
        navigator.geolocation.getCurrentPosition(function(pos){
          var point = {lat: pos.coords.latitude, lng: pos.coords.longitude};
          pin.setLatLng([point.lat, point.lng]);
          map.setView([point.lat, point.lng], 16);
          defer(point);
        }, function(){ say(box, 'دسترسی به موقعیت فعلی ممکن نشد.', true); });
      });
    }
  }

  var controllers = [];

  function boot(){
    if (!window.L) {
      // Leaflet itself failed to load (offline panel, or a content blocker
      // stopping the CDN). Keep the manual coordinate fields usable and explain
      // why the map is missing instead of leaving an empty box.
      document.querySelectorAll('[data-meydan-picker]').forEach(function(box){
        box.innerHTML = '<p style="padding:14px;margin:0">کتابخانه نقشه بارگذاری نشد. مختصات را دستی وارد کنید یا صفحه را دوباره باز کنید.</p>';
        say(box, 'نقشه در دسترس نیست؛ عرض و طول جغرافیایی را دستی وارد کنید.', true);
      });
      return;
    }
    document.querySelectorAll('[data-meydan-picker]').forEach(function(box){ controllers.push(box); start(box); });

    // Test hook: drives the same handler a map click runs, so a browser test can
    // exercise the picker without re-implementing its internals.
    window.meydanPickerProbe = {
      pick: function(index, lat, lng){
        var box = controllers[index || 0];
        if (!box || !box.__meydanPick) { return false; }
        box.__meydanPick({lat: lat, lng: lng});
        return true;
      }
    };

    var all = document.getElementById('meydan-admin-map');
    if (all) {
      var map = L.map(all).setView([32.4, 53.7], 5);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'© OpenStreetMap'}).addTo(map);
      (window.MEYDAN_MAP_POINTS || []).forEach(function(p){
        L.marker([parseFloat(p.latitude), parseFloat(p.longitude)]).addTo(map)
          .bindPopup('<b>' + String(p.post_title).replace(/[<>]/g, '') + '</b><br>ID ' + p.square_id);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(jQuery);
JS;
    }
}

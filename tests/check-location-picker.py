#!/usr/bin/env python3
"""Static contract for the wp-admin map location picker and the geocoder.

Picking a point on the map must persist the coordinates AND fill the address,
province and city, on both the manual square page and the square edit screen.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wp-content/plugins/meydan-core/src"

geocoder = (PLUGIN / "Support/Geocoder.php").read_text(encoding="utf-8")
picker = (PLUGIN / "Admin/LocationPicker.php").read_text(encoding="utf-8")
manual = (PLUGIN / "Admin/ManualSquare.php").read_text(encoding="utf-8")
admin = (PLUGIN / "Admin/Admin.php").read_text(encoding="utf-8")
plugin = (PLUGIN / "Plugin.php").read_text(encoding="utf-8")
misc = (PLUGIN / "Rest/MiscController.php").read_text(encoding="utf-8")

# --- shared geocoder --------------------------------------------------------
assert "final class Geocoder" in geocoder, "reverse geocoding must live in one shared class"
assert "nominatim.openstreetmap.org/reverse" in geocoder, "the geocoder must call Nominatim"
assert "set_transient" in geocoder and "get_transient" in geocoder, "lookups must be cached by coordinates"
assert "meydan_provinces" in geocoder and "meydan_cities" in geocoder, "the geocoder must resolve the panel rows"
assert "matchProvince" in geocoder and "matchCity" in geocoder, "both levels must resolve"
assert "array|WP_Error" in geocoder, "the geocoder must report failures as WP_Error"

# Nominatim does not always put the real city in `city`, so every plausible key
# has to be tried before a city is declared unknown.
for key in ["city", "town", "village", "municipality", "county", "district"]:
    assert f"'{key}'" in geocoder, f"the geocoder must consider the {key} address key"
assert "ORDER BY id ASC" in geocoder, "duplicate province rows must resolve deterministically"
assert "normalizePlace" in geocoder, "Persian/Arabic letter variants must be folded before matching"

# The public endpoint and the admin form must share the implementation.
assert "Geocoder::reverse" in misc, "the public /geo/reverse route must use the shared geocoder"
assert "Geocoder::reverse" in picker, "the admin picker must use the shared geocoder"

# --- picker asset -----------------------------------------------------------
assert "final class LocationPicker" in picker, "the picker must be a reusable asset"
assert "'meydan-location-picker'" in picker, "the picker script needs its own handle"
assert "data-meydan-picker" in picker, "maps must opt in through a data attribute"
assert "meydan_reverse_geocode" in picker, "the picker must call the admin-ajax action"
assert "check_ajax_referer('meydan_reverse_geocode', 'nonce')" in picker, "the ajax action must verify its nonce"
assert "manage_meydan_squares" in picker, "the ajax action must require the square capability"
assert "wp_send_json_error" in picker, "geocoding failures must reach the screen"
assert "'meydan:location-picked'" in picker, "other scripts must be able to observe a picked point"

# A map that is initialised but zero-height is invisible, which is exactly the
# regression this guards: the container must be given a real height from CSS and
# the script must re-measure before and after Leaflet reads the box.
css_path = ROOT / "wp-content/plugins/meydan-core/assets/location-picker.css"
assert css_path.is_file(), "the picker stylesheet must ship with the plugin"
css = css_path.read_text(encoding="utf-8")
assert "[data-meydan-picker]" in css, "the stylesheet must size the map container"
assert "height: 320px !important" in css, "wp-admin CSS can win over the inline height"
assert "min-height: 320px !important" in css, "the container must not collapse"
assert "plugins_url('assets/location-picker.css', MEYDAN_CORE_FILE)" in picker, (
    "the picker stylesheet must be enqueued"
)
assert "ensureSize" in picker, "the script must guarantee a usable height itself"
assert "invalidateSize" in picker, "Leaflet must re-measure after the layout settles"
assert "box.offsetHeight < 120" in picker, "a collapsed container must be detected"
# Leaflet failing to load must not leave a silent empty box.
assert "کتابخانه نقشه بارگذاری نشد" in picker, "a missing Leaflet must be reported to the operator"

# Clicking, dragging and manual coordinates all have to trigger the lookup.
assert "map.on('click'" in picker, "clicking the map must pick a point"
assert "pin.on('dragend'" in picker, "dragging the pin must pick a point"
assert "clearTimeout" in picker and "setTimeout" in picker, "lookups must be debounced"
assert "data-meydan-picker-lookup" in picker and "data-meydan-picker-locate" in picker, "both helper buttons must be wired"
assert "data-meydan-picker-status" in picker, "the operator needs feedback next to the map"
assert "fillCities" in picker and "province.value" in picker, "the picker must cascade province to city"
assert "addressTouched" in picker, "a hand-edited address must not be overwritten"

assert "LocationPicker::register()" in plugin, "the picker must be booted with the plugin"

# --- screens ----------------------------------------------------------------
assert 'id="meydan-square-map" data-meydan-picker' in admin, "the square edit map must use the shared picker"
for field, attr in [
    ("meydan_geo_lat", "lat"),
    ("meydan_geo_lng", "lng"),
    ("meydan_geo_address", "address"),
    ("meydan_geo_province", "province"),
    ("meydan_geo_city", "city"),
]:
    assert f'data-{attr}-field="[name={field}]"' in admin, f"the square edit picker must write {field}"
assert "data-meydan-picker-status" in admin, "the square edit screen needs picker feedback"
assert "data-meydan-picker-lookup" in admin, "the square edit screen needs the address lookup button"
assert "document.getElementById('meydan-square-map')" not in admin, (
    "the square edit map must not keep a private copy of the picker logic"
)

assert "data-meydan-picker data-lat" in manual, "the manual page map must use the shared picker"
for field, attr in [
    ("latitude", "lat"),
    ("longitude", "lng"),
    ("address", "address"),
    ("province_id", "province"),
    ("city_id", "city"),
]:
    assert f'data-{attr}-field="[name={field}]"' in manual, f"the manual picker must write {field}"
assert "data-meydan-picker-status" in manual, "the manual page needs picker feedback"
assert "data-meydan-picker-lookup" in manual, "the manual page needs the address lookup button"

# --- dropdown focus ---------------------------------------------------------
# Picking a province or city must move the map there, not just fill the fields.
assert "'meydan_place_center'" in picker, "the picker must ask for the centre of a chosen place"
assert "wp_ajax_meydan_place_center" in picker, "the centre lookup needs its own ajax action"
assert "function center(" in geocoder, "the shared geocoder must resolve a place centre"
assert "nominatim.openstreetmap.org/search" in geocoder, "place centres come from a Nominatim search"
assert "['city'] = $searchCity" in geocoder and "['state'] = $province" in geocoder, (
    "a centre lookup must be constrained to the chosen city and province"
)
assert "CAPITALS" in geocoder and "capitalOf" in geocoder, (
    "a province on its own must fall back to its capital"
)
assert "focusPlace" in picker, "the dropdowns must focus the map"
assert "province.addEventListener('change'" in picker, "changing the province must refocus the map"
assert "city.addEventListener('change'" in picker, "changing the city must refocus the map"
assert "map.setView([point.lat, point.lng]" in picker, "focusing must move the map viewport"
assert "pin.setLatLng([point.lat, point.lng])" in picker, "focusing must move the marker too"
assert "abort()" in picker, "a superseded centre lookup must be cancelled"
assert "focusPending" in picker, "a dropdown choice must own the province it selected"
assert "pin.getLatLng()" in picker, "a late answer for another place must be discarded"
assert "data-meydan-picker-province" in manual and "data-meydan-picker-city" in manual, (
    "the manual dropdowns must be marked for the picker"
)
assert "box.parentNode && box.parentNode.querySelector('[data-meydan-picker-status]')" in picker, (
    "the status line sits beside the map box, not inside it"
)

# The manual page keeps its address in a textarea so long Persian addresses fit.
assert 'id="meydan-manual-address"' in manual and "textarea" in manual, "the address field must be a textarea"
# The picker owns Leaflet and the ajax action now: no duplicates.
assert "wp_ajax_meydan_reverse_geocode" not in manual, "the ajax action must be registered exactly once"
assert "wp_ajax_meydan_place_center" not in manual, "the centre action must be registered exactly once"
assert "function ajaxReverse" not in manual, "the ajax handler must live in LocationPicker"
assert "function ajaxCenter" not in manual, "the centre handler must live in LocationPicker"

print("map location picker contract ok")

# Meydan Backend

Headless WordPress backend for **Meydan**, implemented with the custom `meydan-core` plugin.

## Quick start

```bash
cp .env.example .env
# Change passwords in .env
docker compose up -d db wordpress
docker compose run --rm wpcli
```

WordPress: `http://localhost:8080`  
REST API: `http://localhost:8080/wp-json/meydan/v1`

The `wpcli` initializer installs WordPress, configures pretty permalinks, activates `meydan-core`, runs database migrations, and prints plugin status.

## OTP

Public accounts do **not** use username/password login. WordPress usernames/passwords are internal random values only. Authentication is OTP-based and sessions use opaque hashed access/refresh tokens.

For local development set `MEYDAN_DEV_OTP_CODE` in `.env`. The code is never stored in plaintext in the database and is never returned by the API. In production, leave the dev code empty and configure `MEYDAN_SMS_ENDPOINT` and `MEYDAN_SMS_TOKEN` as deployment secrets. The endpoint receives JSON `{phone, code}` with a bearer token.

## Security notes

- Refresh tokens are stored only as hashes and sent through an HttpOnly cookie.
- Access tokens are stored only as hashes and supplied as `Authorization: Bearer ...`.
- OTPs are hashed.
- Public `user_login` values are never exposed through Meydan REST responses.
- Uploads block executable extensions, validate MIME/extension, use chunk directories protected from execution, and are finalized through WordPress media handling.
- Admin actions use capability checks and nonces; security secrets are not editable in wp-admin.

## WordPress admin

The plugin adds a top-level **میدان** menu for dashboard, narratives, content, creators, speakers, squares/verification/map, initiatives, campaigns, speaker invitations, media reflections, comments, notifications, stats, settings, geo data, sessions, and audit logs.

Speakers are **user accounts**, not a separate entity: a speaker is a WordPress user holding the `meydan_speaker` role (account type `speaker`). The «سخنرانان» menu opens the role-filtered users list, and the speaker profile (نمایشی role, expertise, handle, initials, categories, cities, social links, verified badge) is edited on the user edit screen under «پروفایل سخنران». Promoting an existing account is what makes it invitable; there is no public speaker registration.

### Manual square creation

**میدان → افزودن میدان و کاربر** creates both objects of a square in one form: the owner account (`meydan_square` role, with the phone stored exactly as OTP registration stores it) and the square post with its geo row, avatar, start date, contact details, and channels. Because the phone hash/ciphertext match the public registration path, the owner can immediately sign in with an OTP. A phone or email that already belongs to another account is rejected, the account is linked to the square before the square role is applied, and the whole operation is written to the audit log as `square_created_manually`.

### Picking the square location on the map

Both the manual page and the square edit screen show the same Leaflet picker: click anywhere on the map or drag the pin and the coordinates are stored immediately, then the address, province and city fields are filled from a reverse geocode of that point. «تشخیص نشانی از مختصات» resolves the coordinates already in the fields, and «موقعیت فعلی من» uses the browser location. Dragging and clicking are debounced, an address the operator has typed by hand is never overwritten, and the status line beside the map reports what was filled.

On the manual page the map follows the dropdowns: choosing a **province** or a **city** focuses the map (and moves the pin) onto that place, then fills the address from it. A province with no city yet resolves to its capital, so the map always lands inside the province that was picked. The province/city the operator selected is never rewritten by that lookup, and an answer that arrives for a place the pin has already left is discarded.

Reverse geocoding is shared by the app and the panel through `Support\Geocoder` (`/wp-json/meydan/v1/geo/reverse` for the app, an authenticated `admin-ajax` action for wp-admin), is cached per rounded coordinate, and resolves Nominatim names onto this site's own province/city rows — including the cases where Nominatim reports the real city only in `county`, and the duplicated province rows in the geo tables. The dropdown focus uses the same class (`Geocoder::center()` → `admin-ajax` action `meydan_place_center`, cached per place).

### Channels (Eitaa and Bale)

Both channel inputs are stored on the square owner account: `meydan_eitaa_channel` drives the Eitaa import pipeline, and `meydan_bale_channel` keeps the Bale channel id (only the id is stored for now). They are editable on the square account screen (user edit), on the square edit screen (square meta box), and while creating a square manually. Numeric ids, `@username`, and channel links (`eitaa.com`/`eitaa.ir`, `ble.ir`/`bale.ai`) are accepted; clearing a field removes that channel. `/wp-json/meydan/v1/squares/{id}` exposes both as `eitaa_channel` and `bale_channel`.

### Internal account emails

Public accounts are OTP-only, so registration never asks for an email. WordPress still refuses to save the user edit screen while `user_email` is empty, which surfaced as "please enter an email address" on every profile edit. The plugin now writes a unique, valid, never-deliverable placeholder (`<id>@<site host>`) for any account created without one, repairs accounts that predate the rule on boot, keeps a real address whenever an operator provides one, and fills the placeholder in-place when the profile form is submitted with the email field empty.


## Bale bot (ربات بله)

The plugin can report operational events to a Bale chat. Everything is configured in **میدان → تنظیمات** — no code or `.env` edit is required. Enter the bot token (from Bale's `@BotFather`) and the numeric `chat_id` of the destination group or channel, then press «ثبت وبهوک و ارسال پیام تست» to verify the token, register the webhook, and send a confirmation message. Optional deployment constants (`MEYDAN_BALE_BOT_TOKEN`, `MEYDAN_BALE_CHAT_ID`, `MEYDAN_BALE_BASE_URL`) act only as fallbacks until the panel sets a value; the panel always wins.

Three things are delivered to that chat:

1. **Eitaa sync failures** — any error returned by the Eitaa service client or the content import is forwarded with its method, path, and HTTP status.
2. **Project errors** — fatals, exceptions, warnings, and notices, each with its own on/off toggle, including file and line.
3. **Pending squares** — each square entering `pending_verification` is announced with its link and two inline buttons (✅ تأیید / ❌ لغو). Pressing one applies the same code path as the wp-admin approvals screen.

Other panel controls: request timeout, an alerts on/off master switch, per-severity reporting toggles, whether to stamp the site name on each message, the per-minute alert ceiling, and the de-duplication window for repeated identical alerts. The panel also manages the webhook directly — view its live status at Bale (`getWebhookInfo`, including pending updates and the last delivery error), remove it, or rotate the webhook secret.

The Eitaa connection is editable in the same screen: the shared sync secret and the service URL are stored as options and take precedence over `MEYDAN_EITAA_SYNC_SECRET` / `EITAA_SERVICE_URL`.

**Webhook requirements:** Bale must be able to reach this site, so the webhook needs a public HTTPS URL. It does not work on `localhost`, and Bale only allows webhook ports 443 and 88 behind TLS. The endpoint is public by necessity, so it verifies Bale's `X-Telegram-Bot-Api-Secret-Token` header, restricts updates to the configured `chat_id`, and requires a signed callback payload before it changes any square. Alerts other than the approval buttons work without a webhook.

## Source specification

The implementation target is preserved in [`SPEC.md`](./SPEC.md).

## Useful commands

```bash
docker compose run --rm wpcli wp meydan migrate
docker compose run --rm wpcli wp meydan status
docker compose run --rm wpcli wp route list --namespace=meydan/v1
```

## Production

Use strong secrets, TLS, an external SMS provider, a reverse proxy, persistent backups, object cache if needed, and disable the local OTP code. The Docker compose file is development/acceptance-test friendly; production orchestration should pin digests and externalize secrets/volumes.

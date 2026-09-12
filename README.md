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

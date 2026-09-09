# Meydan Backend & API Specification v1
## Headless WordPress + Custom Plugin
### سند نهایی پیاده‌سازی Backend پروژه «میدان»

> این سند باید به‌عنوان **پرامپت / Specification نهایی نسخه 1 Backend پروژه میدان** استفاده شود.  
> Backend باید با **Headless WordPress + افزونه اختصاصی `meydan-core`** پیاده‌سازی شود و Frontend فقط از REST API استفاده کند.

---

# 0. اصل غیرقابل مذاکره: همه‌چیز باید از پنل WordPress قابل مدیریت باشد

این اصل در تمام پروژه الزامی است:

> **هر داده، موجودیت، رابطه، وضعیت، تنظیم، شمارنده، محتوای مدیریتی یا رفتار قابل پیکربندی که در Frontend نمایش داده می‌شود یا روی رفتار سیستم اثر دارد، باید در Backend منبع مشخص داشته باشد و در صورت ماهیت مدیریتی، از `wp-admin` نیز قابل مشاهده، ایجاد، ویرایش، حذف، تأیید، رد، مرتب‌سازی، فعال/غیرفعال و مدیریت باشد.**

یعنی موارد زیر نباید فقط در API یا Frontend وجود داشته باشند:

- روایت‌ها
- فایل‌ها و ضمیمه‌های روایت
- محتوا
- فایل‌های Content
- تولیدکنندگان محتوا
- رابطه Content ↔ Creator
- میدان‌ها
- اطلاعات و موقعیت دقیق میدان
- برنامه میدان
- ابتکارها
- اعضای ابتکار
- بازنشرهای رسانه‌ای
- کاربران
- پروفایل‌های کاربران
- درخواست‌های ثبت میدان
- درخواست‌های سخنران
- سخنران‌ها
- Campaignها
- Trends قابل تنظیم
- Feature Flags
- Quick Actions
- Notificationها
- Notification Templateها
- کامنت‌ها
- Replyها
- وضعیت moderation
- آمار و counters
- تنظیمات API
- تنظیمات Timeline
- تنظیمات Ranking
- Audit Logs

### استثناهای امنیتی

داده‌های امنیتی خام نباید در wp-admin قابل مشاهده یا ویرایش مستقیم باشند:

- OTP plaintext
- Access Token plaintext
- Refresh Token plaintext
- token secrets
- private keys
- password داخلی WordPress

برای این موارد فقط عملیات امن مانند:

- revoke
- expire
- reset
- invalidate session

مجاز است.

---

# 1. معماری اصلی

Backend:

```text
Headless WordPress
+
Custom Plugin: meydan-core
```

Frontend:

```text
Next.js
```

Base API:

```http
/wp-json/meydan/v1
```

Chat / Direct Messaging فعلاً خارج از Scope نسخه 1 است.

---

# 2. انواع حساب

دو نوع حساب عمومی داریم:

```text
1. user
2. square
```

## user

کاربر عادی سایت.

در صفحه «هویت و پایگاه» باید فقط:

```text
رزومه شخصی من
```

نمایش داده شود.

## square

حساب میدان.

در صفحه «هویت و پایگاه» باید فقط:

```text
پایگاه میدان من
```

نمایش داده شود.

### نکته مهم

هیچ Tab بین «رزومه» و «پایگاه» وجود ندارد.

Frontend براساس:

```json
{
  "account_type": "user"
}
```

یا:

```json
{
  "account_type": "square"
}
```

تصمیم می‌گیرد کدام View نمایش داده شود.

---

# 3. نقش‌های مدیریتی

نقش‌های عمومی سایت با نقش‌های مدیریتی WordPress یکی نیستند.

نمونه نقش‌ها:

```text
Administrator
Meydan Content Editor
Meydan Moderator
Meydan Manager
Meydan Support
```

Capabilities باید granular باشند.

از `manage_options` برای همه‌چیز استفاده نشود.

---

# 4. Username و Password نداریم

کاربر عمومی:

- Username ندارد.
- Password ندارد.
- Login با Password ندارد.
- Email/Password login ندارد.

ورود فقط با OTP موبایل است.

WordPress برای `user_login` مقدار داخلی تصادفی ایجاد کند:

```text
meydan_internal_01J...
```

این مقدار:

- از API خارج نشود.
- در UI نمایش داده نشود.
- برای Login استفاده نشود.

Password داخلی نیز random و عملاً غیرقابل استفاده باشد.

---

# 5. قانون نهایی Guest و Authentication

## تمام صفحات عمومی بدون Login کار می‌کنند

Guest باید بتواند بدون ورود:

- Home / Timeline را ببیند.
- For You را ببیند.
- روایت‌ها را باز کند.
- Contentها را ببیند.
- فایل عمومی دانلود کند.
- Explore/Search را استفاده کند.
- Trends را ببیند.
- Map را ببیند.
- لیست میدان‌ها را ببیند.
- پروفایل عمومی User را ببیند.
- پروفایل عمومی Square را ببیند.
- Creatorها را ببیند.
- Speakerها را ببیند.
- Campaignها را ببیند.
- Scheduleها را ببیند.
- Initiativeها را ببیند.
- Share کند.
- Download کند.
- در Initiativeهای Guest-enabled عضو شود.
- در صورت تعریف Product، Speaker Request عمومی ثبت کند.

## اکشن‌هایی که Login اجباری دارند

Login برای موارد زیر لازم است:

```text
Like
Repost
Follow
Create Narrative
Publish Narrative
Create Comment
Reply to Comment
Edit own Comment
Delete own Comment
Notification Center
/me/*
Manage own Square
```

### کامنت ناشناس نداریم

**کامنت و Reply فقط برای کاربر لاگین‌شده مجاز است.**

---

# 6. Guest Session

برای Personalization حداقلی و View Attribution:

```text
guest_id = UUIDv4
```

Cookie:

```text
meydan_guest
```

کاربرد:

- Timeline served history
- View attribution
- Guest initiative join
- Search analytics
- Download analytics
- rate limiting
- cold start personalization

Guest ID هیچ هویت واقعی‌ای ندارد.

---

# 7. OTP API

## Request OTP

```http
POST /auth/otp/request
```

Payload:

```json
{
  "phone": "+989121234567"
}
```

Response:

```json
{
  "data": {
    "challenge_id": "otp_xxx",
    "expires_in": 120,
    "resend_after": 60
  }
}
```

API نباید افشا کند که شماره از قبل عضو است یا نه.

Rate Limit پیشنهادی:

```text
1 request / 60 sec / phone
5 requests / day / phone
20 requests / hour / IP
```

---

# 8. Verify OTP

```http
POST /auth/otp/verify
```

Payload:

```json
{
  "challenge_id": "otp_xxx",
  "code": "482193"
}
```

حساب موجود:

```json
{
  "data": {
    "authenticated": true,
    "access_token": "...",
    "expires_in": 900,
    "account": {
      "id": 812,
      "account_type": "user"
    }
  }
}
```

شماره جدید:

```json
{
  "data": {
    "authenticated": false,
    "registration_required": true,
    "registration_token": "reg_xxx"
  }
}
```

---

# 9. Session API

```http
POST /auth/refresh
POST /auth/logout
POST /auth/logout-all
```

Refresh token:

- HttpOnly
- Secure
- rotation enabled
- hash در DB

Access token:

- کوتاه‌عمر
- مثلاً 15 دقیقه

---

# 10. ثبت‌نام User

```http
POST /auth/register/user
```

Payload:

```json
{
  "registration_token": "...",
  "full_name": "محمد رضایی",
  "province_id": 8,
  "city_id": 301,
  "avatar_media_id": 712
}
```

Backend:

```text
account_type=user
role=meydan_user
```

---

# 11. ثبت‌نام Square

فرم جدا:

```http
POST /auth/register/square
```

Payload:

```json
{
  "registration_token": "...",
  "square_name": "میدان انقلاب تهران",
  "description": "...",
  "province_id": 8,
  "city_id": 301,
  "address": "...",
  "latitude": 35.7001000,
  "longitude": 51.3912000,
  "avatar_media_id": 801,
  "contact_name": "...",
  "contact_phone": "..."
}
```

وضعیت اولیه:

```text
pending_verification
```

## wp-admin

Admin باید بتواند:

- درخواست ثبت Square را ببیند.
- تمام فیلدها را ویرایش کند.
- مختصات را ویرایش کند.
- pin را روی mini-map جابه‌جا کند.
- approve کند.
- reject کند.
- suspend کند.
- restore کند.
- admin note بگذارد.

---

# 12. Actor Model

برای یکپارچه‌کردن User و Square:

```json
{
  "id": "usr_812",
  "type": "user",
  "display_name": "محمد رضایی",
  "avatar_url": "...",
  "verified": false
}
```

یا:

```json
{
  "id": "sq_33",
  "type": "square",
  "display_name": "میدان انقلاب تهران",
  "avatar_url": "...",
  "verified": true
}
```

هیچ public username یا handle نداریم.

---

# 13. Profile API

```http
GET /me
```

## User Response

```json
{
  "data": {
    "account_type": "user",
    "profile": {
      "id": 812,
      "full_name": "...",
      "avatar_url": "...",
      "headline": "...",
      "province_id": 8,
      "city_id": 301,
      "location_label": "...",
      "about": "...",
      "skills": [],
      "stats": {}
    }
  }
}
```

## Square Response

```json
{
  "data": {
    "account_type": "square",
    "square": {
      "id": 33,
      "name": "...",
      "description": "...",
      "avatar_url": "...",
      "verified": true,
      "location": {},
      "schedule": [],
      "stats": {}
    }
  }
}
```

---

# 14. User Public API

```http
GET /users/{id}
GET /users/{id}/narratives
```

Owner:

```http
PATCH /me/profile
```

---

# 15. Square Public API

```http
GET /squares
GET /squares/{id}
GET /squares/{id}/narratives
GET /squares/{id}/schedule
```

Filters:

```text
province_id
city_id
verified
q
```

---

# 16. Square Owner API

```http
PATCH /me/square
PUT /me/square/location
```

Location:

```json
{
  "province_id": 8,
  "city_id": 301,
  "address": "...",
  "latitude": 35.7001000,
  "longitude": 51.3912000
}
```

---

# 17. Schedule API

Public:

```http
GET /squares/{id}/schedule
```

Owner:

```http
GET    /me/square/schedule
POST   /me/square/schedule
PATCH  /me/square/schedule/{id}
DELETE /me/square/schedule/{id}
```

تمام Scheduleها از wp-admin نیز CRUD کامل داشته باشند.

---

# 18. Narrative Post Type

CPT:

```text
meydan_narrative
```

روایت همان Tweet پروژه است.

User و Square هر دو می‌توانند Narrative ایجاد کنند.

Fields:

```text
id
author_actor_type
author_actor_id
body
status
published_at
edited_at
attachments[]
tags[]
initiative_id?
is_echo
media_reflections[]
stats
```

---

# 19. Create Narrative API

Login Required.

```http
POST /narratives
```

Payload:

```json
{
  "body": "متن روایت...",
  "attachments": [
    {
      "media_id": 901,
      "order": 1,
      "caption": "توضیح تصویر"
    },
    {
      "media_id": 902,
      "order": 2,
      "caption": null
    }
  ],
  "tags": ["اصفهان", "میدان"],
  "initiative_id": null,
  "location": {
    "province_id": 4,
    "city_id": 141
  }
}
```

---

# 20. Narrative Attachments

روایت باید generic attachment داشته باشد.

Types:

```text
image
video
audio
document
file
link
```

نمونه:

```json
{
  "id": 901,
  "type": "audio",
  "mime_type": "audio/mpeg",
  "filename": "voice.mp3",
  "url": "...",
  "size": 8182212,
  "duration": 42,
  "caption": "...",
  "order": 1
}
```

## الزام wp-admin

Admin باید بتواند از پنل:

- attachment اضافه کند.
- attachment حذف کند.
- ترتیب را عوض کند.
- caption را ویرایش کند.
- label را ویرایش کند.
- فایل را جایگزین کند.
- metadata را ببیند.

---

# 21. Upload API

## Start

```http
POST /uploads
```

```json
{
  "filename": "voice.mp3",
  "mime_type": "audio/mpeg",
  "size": 8182212,
  "purpose": "narrative"
}
```

Response:

```json
{
  "data": {
    "upload_id": "upl_xxx",
    "mode": "chunked",
    "chunk_size": 5242880
  }
}
```

## Upload Chunk

```http
PUT /uploads/{upload_id}/chunks/{index}
```

## Complete

```http
POST /uploads/{upload_id}/complete
```

Response:

```json
{
  "data": {
    "media_id": 811,
    "type": "audio",
    "url": "...",
    "size": 8182212
  }
}
```

## Abort

```http
DELETE /uploads/{upload_id}
```

---

# 22. Upload Security

ممنوع:

```text
PHP
PHTML
EXE
SH
dangerous executable formats
```

Validation:

- extension
- MIME
- MIME sniffing
- maximum size
- allowed purpose
- antivirus hook optional
- file execution disabled

---

# 23. Narrative Read/Edit/Delete

```http
GET    /narratives/{id}
PATCH  /narratives/{id}
DELETE /narratives/{id}
```

GET عمومی.

PATCH/DELETE:

- owner
- moderator
- admin

Delete ترجیحاً Soft Delete.

---

# 24. Narrative Response

```json
{
  "data": {
    "id": 771,
    "author": {
      "id": "sq_33",
      "type": "square",
      "display_name": "میدان انقلاب تهران",
      "avatar_url": "...",
      "verified": true
    },
    "body": "...",
    "attachments": [],
    "initiative": null,
    "media_reflections": [],
    "stats": {
      "views": 55102,
      "likes": 901,
      "comments": 34,
      "reposts": 71,
      "shares": 44
    },
    "viewer_state": {
      "liked": false,
      "reposted": true
    }
  }
}
```

برای Guest:

```json
{
  "viewer_state": null
}
```

---

# 25. Like API

Login Required.

```http
PUT    /narratives/{id}/like
DELETE /narratives/{id}/like
```

Idempotent.

---

# 26. Repost API

Login Required.

```http
PUT    /narratives/{id}/repost
DELETE /narratives/{id}/repost
```

---

# 27. Follow API

Login Required.

```http
PUT    /actors/{actor_type}/{actor_id}/follow
DELETE /actors/{actor_type}/{actor_id}/follow
```

Personal:

```http
GET /me/following
```

Public optional:

```http
GET /actors/{type}/{id}/followers
GET /actors/{type}/{id}/following
```

---

# 28. Share API

Public.

```http
POST /narratives/{id}/share
```

Backend:

```text
shares++
event log
```

Frontend سپس:

```text
navigator.share()
```

یا Copy Link.

---

# 29. Comment System

## کامنت ناشناس نداریم

کامنت و Reply فقط Login Required.

از:

```text
wp_comments
```

استفاده شود.

## Threaded Replies

`comment_parent` برای relation.

ساختار:

```json
{
  "id": 501,
  "narrative_id": 771,
  "author": {},
  "body": "...",
  "parent_id": null,
  "reply_count": 3,
  "created_at": "...",
  "edited_at": null
}
```

Reply:

```json
{
  "id": 502,
  "narrative_id": 771,
  "author": {},
  "body": "پاسخ...",
  "parent_id": 501,
  "reply_count": 0
}
```

---

# 30. Comments API

## Top-level

```http
GET /narratives/{id}/comments?cursor=...
```

## Replies

```http
GET /comments/{id}/replies?cursor=...
```

## Create Comment

```http
POST /narratives/{id}/comments
```

```json
{
  "body": "...",
  "parent_id": null
}
```

## Reply

```http
POST /narratives/{id}/comments
```

```json
{
  "body": "...",
  "parent_id": 501
}
```

Backend باید validate کند parent متعلق به همان narrative باشد.

## Edit

```http
PATCH /comments/{id}
```

## Delete

```http
DELETE /comments/{id}
```

Owner یا Moderator/Admin.

---

# 31. Comment Thread Depth

در DB nesting می‌تواند کامل باشد.

اما API/UI v1 بهتر است:

```text
Level 1: Comment
Level 2: Replies
```

Reply به Reply نیز در همان thread نمایش داده شود.

---

# 32. Comment Admin

در wp-admin:

- List
- Search
- Filter by Narrative
- Filter by Author
- Parent relation
- Reply relation
- Edit
- Approve
- Unapprove
- Spam
- Trash
- Restore
- Delete
- Bulk moderation

همه لازم است.

---

# 33. Media Reflection

بازنشر رسانه‌ای یک Relation برای Narrative است.

Fields:

```text
id
narrative_id
outlet
title
summary
url
logo_media_id
published_at
status
```

Public:

```http
GET /narratives/{id}/media-reflections
```

Admin:

```http
POST   /admin/narratives/{id}/media-reflections
PATCH  /admin/media-reflections/{id}
DELETE /admin/media-reflections/{id}
```

## wp-admin

باید:

- Add
- Edit
- Delete
- Reorder
- link/unlink Narrative
- Outlet
- Title
- Summary
- URL
- Date
- Logo

کاملاً قابل مدیریت باشد.

---

# 34. Initiative

Entity:

```text
meydan_initiative
```

Fields:

```text
id
title
description
cta_label
starts_at
ends_at
status
allow_guest_join
participant_count
```

Public:

```http
GET /initiatives
GET /initiatives/{id}
```

Join:

```http
PUT /initiatives/{id}/join
```

Leave:

```http
DELETE /initiatives/{id}/join
```

User:

```text
user_id
```

Guest-enabled initiative:

```text
guest_id
```

Personal:

```http
GET /me/initiatives
```

---

# 35. Content Post Type

CPT:

```text
meydan_content
```

فقط Admin/Content Editor ایجاد و ویرایش کند.

Content می‌تواند شامل:

- text
- image
- gallery
- audio
- video
- PDF
- DOCX
- PPTX
- ZIP مجاز
- downloadable file
- external link
- mixed media

باشد.

---

# 36. Content Model

```json
{
  "id": 123,
  "title": "...",
  "excerpt": "...",
  "body": "...",
  "format": "mixed",
  "category": {},
  "attachments": [],
  "creators": [],
  "tags": [],
  "usage_note": "...",
  "published_at": "...",
  "stats": {
    "views": 12500,
    "downloads": 832,
    "shares": 21
  }
}
```

---

# 37. Creator Entity

```text
meydan_creator
```

Creator الزاماً User نیست.

Types:

```text
speaker
reciter
writer
journalist
designer
media_team
institution
studio
other
```

Fields:

```text
name
types[]
role
bio
avatar
verified
cities[]
social_links[]
```

---

# 38. Content ↔ Creator Relation

Many-to-Many.

```text
wp_meydan_content_creators
```

Fields:

```text
content_id
creator_id
position
role_label
```

یک Content می‌تواند چند Creator داشته باشد.

## wp-admin

در Content Editor:

- searchable multi-select
- add/remove creator
- reorder creator
- role label
- create new creator inline optional

---

# 39. Content API

```http
GET /content
GET /content/{id}
```

Filters:

```text
category
format
creator_id
tag
featured
cursor
```

Bookmark:

```http
PUT    /content/{id}/bookmark
DELETE /content/{id}/bookmark
```

Share:

```http
POST /content/{id}/share
```

Download:

```http
POST /content/{id}/files/{file_id}/download
```

---

# 40. Podcast

CPT جدا لازم نیست.

```text
meydan_content
format=audio
category=podcast
```

---

# 41. Speakers

Speaker نوعی Creator است.

```http
GET /speakers
GET /speakers/{id}
```

Filters:

```text
q
city_id
category
verified
```

---

# 42. Speaker Request

```http
POST /speaker-requests
```

Square:

```json
{
  "creator_id": 22,
  "venue": "میدان انقلاب",
  "requested_at": "2026-09-10T21:00:00+03:30",
  "note": "..."
}
```

Guest در صورت فعال بودن:

```json
{
  "creator_id": 22,
  "requester_name": "...",
  "requester_phone": "...",
  "venue": "...",
  "requested_at": "...",
  "note": "...",
  "captcha_token": "..."
}
```

Status:

```text
pending
accepted
rejected
cancelled
completed
```

APIs:

```http
GET /speaker-requests/{id}
DELETE /speaker-requests/{id}
GET /me/speaker-requests
```

---

# 43. Speaker Request wp-admin

Admin باید بتواند:

- list
- filter
- open
- edit
- change status
- add internal note
- assign manager
- contact requester
- mark completed
- reject
- cancel

کند.

---

# 44. Campaign

Entity:

```text
meydan_campaign
```

Public:

```http
GET /campaigns/current
GET /campaigns/{id}
GET /campaigns/{id}/schedule
```

wp-admin:

- CRUD
- schedule
- current flag
- dates
- labels
- linked content
- ordering

---

# 45. Geo API

```http
GET /geo/provinces
GET /geo/cities?province_id=8
```

---

# 46. Exact Square Location

هر Square:

```text
province_id
city_id
address
latitude DECIMAL(10,7)
longitude DECIMAL(10,7)
```

مختصات دقیق میدان لازم است.

مرکز تقریبی شهر کافی نیست.

---

# 47. Map API

```http
GET /squares/map
```

Filters:

```text
west
east
north
south
province_id
city_id
```

GeoJSON:

```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": {
        "type": "Point",
        "coordinates": [51.3912, 35.7001]
      },
      "properties": {
        "id": 34,
        "name": "میدان انقلاب تهران",
        "verified": true,
        "city": "تهران",
        "avatar_url": "..."
      }
    }
  ]
}
```

---

# 48. Square Map Admin

wp-admin باید:

- map editor
- draggable pin
- latitude input
- longitude input
- address input
- province
- city
- geocode helper optional

داشته باشد.

---

# 49. Explore Search

```http
GET /explore/search
```

Query:

```text
q
types=narrative,content,square,creator,user,topic
cursor
```

Response:

```json
{
  "data": {
    "sections": {
      "narratives": [],
      "squares": [],
      "users": [],
      "content": [],
      "creators": [],
      "topics": []
    }
  }
}
```

---

# 50. Trends

```http
GET /explore/trends
```

Windows:

```text
1h
6h
24h
```

MVP:

```text
trend_score =
  1.0 * likes
+ 2.0 * reposts
+ 2.5 * comments
+ 1.5 * shares
+ freshness_boost
```

---

# 51. Explore Suggestions

```http
GET /explore/suggestions
```

شامل:

- nearby squares
- creators
- topics
- content
- recommended actors

---

# 52. Timeline API

```http
GET /timeline
```

Query:

```text
mode=for_you|following
filter=all|echo|reflected|visual|audio|initiatives
cursor=...
```

Batch:

```text
100 Narrative
```

در صورت وجود داده کافی.

---

# 53. قانون View

اصل قطعی:

> هر Narrative که Backend در Timeline response تحویل می‌دهد، یک View می‌گیرد، حتی اگر کاربر آن را واقعاً روی صفحه ندیده باشد.

Flow:

```text
GET /timeline
↓
Select final 100
↓
Increment all 100 views
↓
Record served history
↓
Return response
```

هیچ:

- IntersectionObserver
- dwell time
- visibility percentage

برای Timeline View لازم نیست.

---

# 54. Bulk View

Custom table:

```text
wp_meydan_narrative_stats
```

Bulk SQL:

```sql
INSERT INTO wp_meydan_narrative_stats
(narrative_id, views, updated_at)
VALUES
(101, 1, NOW()),
(102, 1, NOW()),
(103, 1, NOW())
ON DUPLICATE KEY UPDATE
views = views + 1,
updated_at = NOW();
```

تا 100 row.

---

# 55. Timeline Architecture

X-inspired MVP:

```text
Candidate Generation
↓
Feature Hydration
↓
Scoring
↓
Filtering / Heuristics
↓
Mixing
↓
Diversity
↓
100 items
↓
Bulk Views
↓
Response
```

بدون ML سنگین.

---

# 56. Candidate Pools

Following:

```text
~400
```

Interaction Graph:

```text
~250
```

Local:

```text
~200
```

Trending / Out-of-network:

```text
~300
```

Exploration:

```text
~100
```

---

# 57. Affinity

Weights:

```text
Follow       +8
Comment      +5
Repost       +4
Share        +3
Like         +2
Detail Open  +1
```

Decay:

```text
half-life ≈ 30 days
```

Normalize:

```text
affinity = 1 - exp(-raw_score / 10)
```

---

# 58. Recency

```text
recency = exp(-age_hours / 18)
```

---

# 59. Engagement Quality

```text
weighted_engagement =
    likes
  + 2.0 * reposts
  + 2.5 * comments
  + 1.5 * shares
```

Normalize relative to exposure.

---

# 60. Location Score

```text
same city     = 1.0
same province = 0.5
other         = 0
```

---

# 61. Final Score

```text
score =
    3.0 * affinity
  + 2.1 * recency
  + 1.5 * engagement_quality
  + 0.9 * locality
  + 0.6 * media_affinity
  + 0.5 * media_reflection_boost
  + 0.6 * initiative_boost
  + 0.4 * exploration_boost
  - 2.0 * recently_served_penalty
```

---

# 62. Mixing

Target:

```text
50% following/in-network
25% interaction discovery
15% local/trending
10% exploration/initiative
```

Fallback بین poolها آزاد.

---

# 63. Diversity

Rules:

```text
max 3 posts / actor / 100
avoid same actor consecutively
remove duplicate
remove hidden
remove deleted
apply recently served penalty
```

---

# 64. Following Timeline

برای Login:

```text
followed actors
ORDER BY published_at DESC
LIMIT 100
```

Guest:

Following tab بهتر است مخفی باشد.

---

# 65. Guest Cold Start

```text
35% local
25% trending
20% verified squares
10% initiatives
10% exploration
```

---

# 66. Notification System

Notification Center فقط برای Login.

Table:

```text
wp_meydan_notifications
```

Fields:

```text
id
recipient_user_id
type
actor_type
actor_id
entity_type
entity_id
parent_entity_type
parent_entity_id
title
body
deep_link
group_key
payload_json
created_at
read_at
archived_at
```

---

# 67. Notification Types

حداقل:

```text
like
repost
follow
comment
comment_reply
initiative_update
initiative_join_confirmed
speaker_request_created
speaker_request_status_changed
media_reflection_added
square_verified
square_rejected
admin_notice
system
```

Optional:

```text
mention
content_published
```

---

# 68. Notification Events

## Like

Recipient:

```text
Narrative author
```

خودکار برای Like خود شخص Notification نساز.

## Repost

Recipient:

```text
Narrative author
```

## Follow

Recipient:

```text
Followed actor
```

## Comment

Recipient:

```text
Narrative author
```

## Reply

Recipient:

```text
Parent comment author
```

Duplicate notification نباید ساخته شود.

---

# 69. Notification API

List:

```http
GET /notifications?cursor=...&filter=all|unread
```

Unread count:

```http
GET /notifications/unread-count
```

Mark one read:

```http
PUT /notifications/{id}/read
```

Mark unread optional:

```http
DELETE /notifications/{id}/read
```

Mark all read:

```http
PUT /notifications/read-all
```

Archive:

```http
PUT /notifications/{id}/archive
```

Unarchive optional:

```http
DELETE /notifications/{id}/archive
```

Delete optional:

```http
DELETE /notifications/{id}
```

ترجیح:

```text
archive > hard delete
```

---

# 70. Notification Aggregation

مثال:

```text
علی و 11 نفر دیگر روایت شما را پسندیدند.
```

group key:

```text
like:narrative:771
```

Aggregation window:

```text
10–30 minutes
```

Reply معمولاً aggregate نشود.

---

# 71. Notification Deep Link

هر Notification:

```text
deep_link
```

نمونه:

```text
/posts/771
/posts/771#comment-501
/profile
/speakers/22
```

---

# 72. Notification Broadcast

Admin:

```http
POST /admin/notifications/broadcast
```

```json
{
  "title": "...",
  "body": "...",
  "audience": {
    "type": "all"
  },
  "deep_link": "/content/123"
}
```

Audience:

```text
all
users
squares
province
city
specific_ids
```

---

# 73. Notification wp-admin

Admin باید بتواند:

- list
- search
- filter by type
- filter by recipient
- filter read/unread
- inspect payload
- archive
- delete
- create system notification
- create admin notification
- broadcast
- manage templates
- filter by entity
- view actor/entity relation

کند.

---

# 74. Config API

```http
GET /config
```

Response:

```json
{
  "data": {
    "feature_flags": {
      "chat": false,
      "speaker_requests": true,
      "initiatives": true,
      "notifications": true
    },
    "quick_actions": []
  }
}
```

Config از wp-admin editable باشد.

---

# 75. Custom Tables

حداقل:

```text
wp_meydan_auth_challenges
wp_meydan_sessions
wp_meydan_interactions
wp_meydan_narrative_stats
wp_meydan_content_stats
wp_meydan_served_history
wp_meydan_actor_affinity
wp_meydan_content_creators
wp_meydan_initiative_members
wp_meydan_square_geo
wp_meydan_speaker_requests
wp_meydan_uploads
wp_meydan_events
wp_meydan_notifications
wp_meydan_audit_log
```

---

# 76. Auth Challenges

```text
id
challenge_id
phone_hash
code_hash
purpose
attempt_count
expires_at
consumed_at
ip_hash
created_at
```

OTP plaintext ممنوع.

---

# 77. Sessions

```text
id
user_id
access_token_hash
refresh_token_hash
access_expires_at
refresh_expires_at
device_name
last_used_at
created_at
revoked_at
```

---

# 78. Interactions

```text
id
user_id
object_type
object_id
action
created_at
```

Actions:

```text
like
repost
follow
bookmark
```

Unique:

```text
(user_id, object_type, object_id, action)
```

---

# 79. Narrative Stats

```text
narrative_id PK
views BIGINT
likes BIGINT
comments BIGINT
reposts BIGINT
shares BIGINT
updated_at
```

---

# 80. Content Stats

```text
content_id PK
views
downloads
shares
bookmarks
updated_at
```

---

# 81. Served History

```text
viewer_type
viewer_id
narrative_id
served_at
source
```

viewer_type:

```text
user
guest
```

---

# 82. Actor Affinity

```text
viewer_user_id
target_actor_type
target_actor_id
score
updated_at
```

---

# 83. Initiative Members

```text
initiative_id
member_type
user_id nullable
guest_id nullable
joined_at
status
```

---

# 84. Square Geo

```text
square_id PK
province_id
city_id
latitude DECIMAL(10,7)
longitude DECIMAL(10,7)
address
updated_at
```

Indexes:

```text
province_id
city_id
latitude
longitude
```

---

# 85. Events

```text
id
viewer_type
viewer_id
event_type
entity_type
entity_id
metadata_json
created_at
```

Events:

```text
share
detail_open
search
search_click
download
initiative_join
```

---

# 86. Audit Log

```text
admin_id
action
entity_type
entity_id
before_json
after_json
ip_hash
created_at
```

مثال:

```text
square_approved
content_updated
media_reflection_deleted
stats_adjusted
notification_broadcast
```

---

# 87. CPTها

پیشنهاد:

```text
meydan_narrative
meydan_content
meydan_creator
meydan_square
meydan_initiative
meydan_campaign
```

---

# 88. Taxonomies

پیشنهاد:

```text
meydan_content_category
meydan_narrative_tag
meydan_content_tag
meydan_creator_type
meydan_topic
```

در صورت مناسب بودن:

```text
show_in_rest=true
```

---

# 89. Capabilities

```text
read_meydan
publish_meydan_narratives
edit_own_meydan_narratives
moderate_meydan_narratives
manage_meydan_content
manage_meydan_creators
manage_meydan_squares
verify_meydan_squares
manage_meydan_initiatives
manage_meydan_campaigns
manage_meydan_media_reflections
manage_meydan_speaker_requests
manage_meydan_notifications
manage_meydan_stats
view_meydan_audit_log
```

---

# 90. WordPress Admin Menu

Top-level:

```text
میدان
```

Submenus:

```text
داشبورد
روایت‌ها
محتوا
تولیدکنندگان
میدان‌ها
درخواست‌های تأیید میدان
نقشه میدان‌ها
ابتکارها
کمپین‌ها
درخواست سخنران
بازنشر رسانه‌ای
کامنت‌ها
نوتیفیکیشن‌ها
آمار
تنظیمات
گزارش تغییرات
```

---

# 91. Narrative Admin

بخش‌ها:

```text
Author
Actor Type
Body
Status
Tags
Attachments
Attachment Order
Initiative
Echo Flag
Media Reflections
Comments
Replies
Stats
Publish Date
Edit Date
Moderation
```

---

# 92. Content Admin

```text
Title
Excerpt
Body
Format
Category
Tags
Usage Note
Featured
Creators
Creator Ordering
Media
Download Files
Publish Date
Stats
```

---

# 93. Creator Admin

```text
Name
Types
Role
Bio
Avatar
Verified
Cities
Links
Published Content
Speaker Requests
```

---

# 94. Square Admin

```text
Name
Owner Account
Approval Status
Description
Avatar
Province
City
Address
Latitude
Longitude
Verified
Schedule
Narratives
Stats
```

---

# 95. Initiative Admin

```text
Title
Description
CTA
Start
End
Status
Allow Guest Join
Participant Count
Participants
Linked Narratives
```

---

# 96. Stats Admin

Admin با capability خاص بتواند:

- مشاهده
- export
- correction

انجام دهد.

Correction باید audit شود.

---

# 97. API Error Contract

Success:

```json
{
  "data": {},
  "meta": {
    "request_id": "req_xxx",
    "next_cursor": null
  }
}
```

Error:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "اطلاعات واردشده معتبر نیست.",
    "fields": {
      "phone": "..."
    }
  }
}
```

HTTP:

```text
400 bad_request
401 unauthenticated
403 forbidden
404 not_found
409 conflict
422 validation_failed
429 rate_limited
500 internal_error
```

---

# 98. Idempotency

Idempotent:

```text
Like
Unlike
Repost
Undo Repost
Follow
Unfollow
Bookmark
Unbookmark
Join Initiative
Leave Initiative
Mark Notification Read
Archive Notification
```

در POSTهای حساس:

```http
Idempotency-Key
```

پشتیبانی شود.

---

# 99. Pagination

Cursor Pagination برای:

```text
Timeline
Comments
Replies
Search
Notifications
Followers
Following
```

---

# 100. Cache

Timeline:

```http
Cache-Control: private, no-store
```

Content/Squares/Creators:

```text
public, max-age=60, stale-while-revalidate=300
```

Me/Notifications:

```text
private, no-store
```

---

# 101. Rate Limits

OTP:

```text
strict
```

Narrative publish:

```text
10 / 10 min / actor
```

Comment:

```text
20 / 5 min / actor
```

Like/Repost:

```text
120 / min / actor
```

Search:

```text
60 / min / viewer
```

Speaker Request Guest:

```text
3 / hour / phone/IP
```

---

# 102. Moderation

حداقل:

- Narrative moderation
- Comment moderation
- Reply moderation
- Spam detection
- User suspension
- Square suspension
- Attachment validation
- Rate limiting
- Admin notes
- Audit trail

آینده:

```text
Report
Mute
Block
```

---

# 103. Security

الزامی:

- `$wpdb->prepare`
- capability checks
- REST permission callbacks
- nonce برای wp-admin
- input validation
- sanitization
- output escaping
- MIME validation
- token hashing
- OTP hashing
- CSRF strategy
- secure cookies
- no plaintext secrets
- upload execution disabled
- rate limiting
- audit logging

---

# 104. Endpoint Inventory

## Auth

```http
POST /auth/otp/request
POST /auth/otp/verify
POST /auth/refresh
POST /auth/logout
POST /auth/logout-all
POST /auth/register/user
POST /auth/register/square
```

## Me

```http
GET    /me
PATCH  /me/profile
GET    /me/narratives
GET    /me/following
GET    /me/initiatives
GET    /me/bookmarks
GET    /me/speaker-requests
PATCH  /me/square
PUT    /me/square/location
GET    /me/square/schedule
POST   /me/square/schedule
PATCH  /me/square/schedule/{id}
DELETE /me/square/schedule/{id}
```

## Narratives

```http
GET    /narratives/{id}
POST   /narratives
PATCH  /narratives/{id}
DELETE /narratives/{id}
```

## Narrative Interactions

```http
PUT    /narratives/{id}/like
DELETE /narratives/{id}/like
PUT    /narratives/{id}/repost
DELETE /narratives/{id}/repost
POST   /narratives/{id}/share
```

## Comments

```http
GET    /narratives/{id}/comments
POST   /narratives/{id}/comments
GET    /comments/{id}/replies
PATCH  /comments/{id}
DELETE /comments/{id}
```

## Media Reflection

```http
GET    /narratives/{id}/media-reflections
POST   /admin/narratives/{id}/media-reflections
PATCH  /admin/media-reflections/{id}
DELETE /admin/media-reflections/{id}
```

## Upload

```http
POST   /uploads
PUT    /uploads/{upload_id}/chunks/{index}
POST   /uploads/{upload_id}/complete
DELETE /uploads/{upload_id}
```

## Timeline

```http
GET /timeline
```

## Follow

```http
PUT    /actors/{type}/{id}/follow
DELETE /actors/{type}/{id}/follow
GET    /actors/{type}/{id}/followers
GET    /actors/{type}/{id}/following
```

## Content

```http
GET    /content
GET    /content/{id}
PUT    /content/{id}/bookmark
DELETE /content/{id}/bookmark
POST   /content/{id}/share
POST   /content/{id}/files/{file_id}/download
```

## Creators

```http
GET /creators
GET /creators/{id}
```

## Speakers

```http
GET /speakers
GET /speakers/{id}
```

## Speaker Requests

```http
POST   /speaker-requests
GET    /speaker-requests/{id}
DELETE /speaker-requests/{id}
```

## Squares

```http
GET /squares
GET /squares/{id}
GET /squares/{id}/narratives
GET /squares/{id}/schedule
GET /squares/map
```

## Geo

```http
GET /geo/provinces
GET /geo/cities
```

## Initiatives

```http
GET    /initiatives
GET    /initiatives/{id}
PUT    /initiatives/{id}/join
DELETE /initiatives/{id}/join
```

## Explore

```http
GET /explore/search
GET /explore/trends
GET /explore/suggestions
```

## Campaigns

```http
GET /campaigns/current
GET /campaigns/{id}
GET /campaigns/{id}/schedule
```

## Notifications

```http
GET    /notifications
GET    /notifications/unread-count
PUT    /notifications/{id}/read
DELETE /notifications/{id}/read
PUT    /notifications/read-all
PUT    /notifications/{id}/archive
DELETE /notifications/{id}/archive
DELETE /notifications/{id}
```

## Config

```http
GET /config
```

## Admin REST

```http
POST   /admin/content
PATCH  /admin/content/{id}
DELETE /admin/content/{id}

POST   /admin/creators
PATCH  /admin/creators/{id}
DELETE /admin/creators/{id}

POST   /admin/narratives/{id}/media-reflections
PATCH  /admin/media-reflections/{id}
DELETE /admin/media-reflections/{id}

POST   /admin/notifications/broadcast
```

---

# 105. Auth Matrix

| Capability | Guest | User | Square | Admin |
|---|---:|---:|---:|---:|
| Timeline | ✅ | ✅ | ✅ | ✅ |
| Narrative Read | ✅ | ✅ | ✅ | ✅ |
| Content | ✅ | ✅ | ✅ | ✅ |
| Download | ✅ | ✅ | ✅ | ✅ |
| Explore | ✅ | ✅ | ✅ | ✅ |
| Map | ✅ | ✅ | ✅ | ✅ |
| Public Profiles | ✅ | ✅ | ✅ | ✅ |
| Creators/Speakers | ✅ | ✅ | ✅ | ✅ |
| Initiative Read | ✅ | ✅ | ✅ | ✅ |
| Guest-enabled Initiative Join | ✅ | ✅ | ✅ | ✅ |
| Share | ✅ | ✅ | ✅ | ✅ |
| Like | ❌ | ✅ | ✅ | ✅ |
| Repost | ❌ | ✅ | ✅ | ✅ |
| Follow | ❌ | ✅ | ✅ | ✅ |
| Publish Narrative | ❌ | ✅ | ✅ | ✅ |
| Comment | ❌ | ✅ | ✅ | ✅ |
| Reply | ❌ | ✅ | ✅ | ✅ |
| Notification Center | ❌ | ✅ | ✅ | ✅ |
| Edit Own Profile | ❌ | Own | Own | ✅ |
| Manage Own Square | ❌ | ❌ | Own | ✅ |
| Content CRUD | ❌ | ❌ | ❌ | capability |
| Creator CRUD | ❌ | ❌ | ❌ | capability |
| Media Reflection CRUD | ❌ | ❌ | ❌ | capability |

---

# 106. Frontend Migration

## Feed

Local stateهای:

```text
Like
Repost
Follow
Join
```

باید API-driven شوند.

## Compose

LocalStorage فقط برای Draft.

Publish:

```http
POST /narratives
```

Attachments از Upload API.

## Post Detail

Comments و Replies از REST.

## Profile

Tabs حذف.

`GET /me.account_type` تعیین‌کننده UI.

## Content

`creator` باید تبدیل شود به:

```text
creators[]
```

## Speakers

Speaker = Creator Type.

## Map

City geocoding برای نمایش میدان‌ها حذف.

```http
GET /squares/map
```

## Explore

Hardcoded catalog حذف.

## Notifications

Notification Center کاملاً API-driven.

## Chat

خارج از Scope.

---

# 107. Plugin Folder Structure

```text
wp-content/plugins/meydan-core/
│
├── meydan-core.php
├── src/
│   ├── Plugin.php
│   ├── Auth/
│   │   ├── OtpService.php
│   │   ├── SessionService.php
│   │   └── GuestSessionService.php
│   ├── Domain/
│   │   ├── Narrative.php
│   │   ├── Content.php
│   │   ├── Square.php
│   │   ├── Creator.php
│   │   ├── Initiative.php
│   │   ├── Comment.php
│   │   └── Notification.php
│   ├── Rest/
│   │   ├── AuthController.php
│   │   ├── MeController.php
│   │   ├── TimelineController.php
│   │   ├── NarrativeController.php
│   │   ├── CommentController.php
│   │   ├── ContentController.php
│   │   ├── CreatorController.php
│   │   ├── SquareController.php
│   │   ├── ExploreController.php
│   │   ├── UploadController.php
│   │   ├── InitiativeController.php
│   │   ├── NotificationController.php
│   │   └── SpeakerRequestController.php
│   ├── Timeline/
│   │   ├── CandidateGenerator.php
│   │   ├── FeatureHydrator.php
│   │   ├── Ranker.php
│   │   └── Mixer.php
│   ├── Notifications/
│   │   ├── NotificationService.php
│   │   ├── Aggregator.php
│   │   └── EventSubscriber.php
│   ├── Repositories/
│   ├── Policies/
│   ├── Uploads/
│   ├── Admin/
│   │   ├── Menus/
│   │   ├── MetaBoxes/
│   │   ├── Lists/
│   │   └── Settings/
│   ├── Database/
│   │   └── Migrations.php
│   └── Audit/
│       └── AuditLogger.php
```

---

# 108. Timeline Pseudocode

```php
public function timeline($viewer, $mode)
{
    if ($mode === 'following' && $viewer->isAuthenticated()) {
        $items = $this->followingCandidates($viewer->userId());
    } else {
        $candidates = $this->candidateGenerator->generate($viewer);
        $features = $this->featureHydrator->hydrate($viewer, $candidates);
        $scored = $this->ranker->rank($features);
        $items = $this->mixer->mixAndDiversify($scored, 100);
    }

    $ids = array_column($items, 'id');

    $this->stats->incrementViewsBulk($ids);
    $this->servedHistory->record($viewer, $ids);

    return $this->hydrator->timelineResponse($items);
}
```

---

# 109. Notification Pseudocode

```php
public function onCommentCreated(Comment $comment)
{
    $narrative = $this->narratives->find($comment->narrativeId());

    if ($comment->parentId()) {
        $parent = $this->comments->find($comment->parentId());

        if ($parent->authorId() !== $comment->authorId()) {
            $this->notifications->createReplyNotification(
                recipient: $parent->authorId(),
                actor: $comment->authorActor(),
                comment: $comment
            );
        }
    }

    if ($narrative->authorUserId() !== $comment->authorId()) {
        $this->notifications->createCommentNotification(
            recipient: $narrative->authorUserId(),
            actor: $comment->authorActor(),
            comment: $comment
        );
    }
}
```

Dedup الزامی.

---

# 110. Acceptance Criteria

- [ ] OTP بدون Username/Password
- [ ] User Registration
- [ ] Square Registration جدا
- [ ] Square Approval در wp-admin
- [ ] User/Square Profile بدون Tab
- [ ] Narrative CRUD
- [ ] Narrative Attachments generic
- [ ] Image Upload
- [ ] Video Upload
- [ ] Audio Upload
- [ ] Document/File Upload
- [ ] Chunk Upload
- [ ] Attachment ordering
- [ ] Like
- [ ] Repost
- [ ] Follow
- [ ] Guest Like ممنوع
- [ ] Guest Repost ممنوع
- [ ] Guest Follow ممنوع
- [ ] Guest Publish ممنوع
- [ ] Guest Comment ممنوع
- [ ] Comment Login-required
- [ ] Comment Reply با parent_id
- [ ] Replies Pagination
- [ ] Comment moderation در wp-admin
- [ ] Timeline بدون Login
- [ ] 100 items per batch
- [ ] all 100 increment views
- [ ] Bulk View Query
- [ ] For You Ranking
- [ ] Following Chronological
- [ ] Guest Cold Start
- [ ] Initiative API
- [ ] Guest-enabled Initiative
- [ ] Media Reflection API
- [ ] Media Reflection wp-admin
- [ ] Content Multi-media
- [ ] Multiple Creators
- [ ] Creator Admin
- [ ] Speaker API
- [ ] Speaker Request
- [ ] Exact Square Geo
- [ ] GeoJSON Map
- [ ] Map Admin
- [ ] Explore API
- [ ] Trends API
- [ ] Notification List
- [ ] Notification Unread Count
- [ ] Notification Read/Unread
- [ ] Notification Archive
- [ ] Notification Aggregation
- [ ] Like Notification
- [ ] Repost Notification
- [ ] Follow Notification
- [ ] Comment Notification
- [ ] Reply Notification
- [ ] Speaker Request Notification
- [ ] Square Approval Notification
- [ ] Admin Broadcast Notification
- [ ] Notification wp-admin
- [ ] Feature Flags
- [ ] Full wp-admin coverage
- [ ] Audit Log
- [ ] Cursor Pagination
- [ ] Security checks
- [ ] Chat disabled

---

# 111. Definition of Done

هیچ Domainی Done نیست مگر اینکه هر سه لایه آن کامل باشند:

```text
1. Storage / Database
2. REST API
3. wp-admin Management UI
```

مثلاً Media Reflection فقط با API کامل نیست.

باید:

```text
DB
+
REST
+
wp-admin CRUD
+
Permission
+
Validation
+
Audit
```

داشته باشد.

همین قانون برای تمام موارد زیر برقرار است:

```text
Narrative
Narrative Attachment
Content
Creator
Square
Square Location
Schedule
Initiative
Campaign
Comment
Reply
Speaker Request
Media Reflection
Notification
Stats
Feature Flags
Quick Actions
```

---

# 112. اصل نهایی حذف Hardcode

هیچ داده مهم کسب‌وکاری نباید صرفاً hardcoded در Frontend باقی بماند.

موارد فعلی مانند:

```text
Explore catalog
Trends
Speaker data
Content creators
Square locations
Campaign schedule
Quick actions
Profile stats
Feed mock data
```

باید به Backend منتقل شوند و از wp-admin قابل مدیریت باشند.

---

# 113. خارج از Scope v1

```text
Direct Chat
Conversation
Message
Typing Indicator
Read Receipt
WebSocket Messaging
Chat Attachment
```

Feature flag:

```json
{
  "chat": false
}
```

---

# 114. ترتیب پیشنهادی پیاده‌سازی

1. Plugin Skeleton
2. DB Migrations
3. Capabilities/Roles
4. OTP/Auth
5. User Registration
6. Square Registration
7. Square Approval Admin
8. Profiles
9. Upload
10. Narrative
11. Like/Repost/Follow
12. Comment/Reply
13. Notification Event System
14. Timeline
15. Bulk Views
16. Content
17. Creator
18. Media Reflection
19. Initiative
20. Square Geo/Map
21. Explore/Search/Trends
22. Speaker Request
23. Campaign/Schedule
24. wp-admin screens
25. Audit Log
26. Integration Tests
27. Permission Tests
28. Frontend API migration

---

# 115. اصل نهایی اجرایی

این Requirement باید در تمام مراحل توسعه enforce شود:

> **هر قابلیت مهمی که از API قابل استفاده است و ماهیت مدیریتی دارد، باید معادل مدیریتی آن در پنل WordPress نیز وجود داشته باشد. Backend نباید مجموعه‌ای از APIهای بدون کنترل مدیریتی باشد. WordPress Admin Interface باید بتواند کل سیستم را مدیریت کند.**

---

# 116. خلاصه نهایی معماری

```text
Phone
↓
OTP
↓
Account
├── User → Personal Resume
└── Square → Square Base + Exact Geo

User / Square
↓
Narrative
├── Text
├── Image
├── Video
├── Audio
├── Document/File
├── Like
├── Repost
├── Comment
│   └── Replies
├── Share
├── Initiative
└── Media Reflection

Admin
↓
Content
├── Mixed Media
├── Multiple Creators
├── Download Files
├── Stats
└── Full wp-admin Editing

Timeline
↓
Candidate Generation
↓
Feature Hydration
↓
Heuristic Ranking
↓
Mixing/Diversity
↓
100 Narratives
↓
Bulk View Increment
↓
Client

Interactions
↓
Notification Service
↓
Aggregation
↓
Notification Center

All Business Data
↓
REST API
+
wp-admin Management
```

---

**این سند، Specification نهایی نسخه 1 Backend پروژه «میدان» است.**

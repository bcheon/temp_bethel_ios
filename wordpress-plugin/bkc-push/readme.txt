=== BKC Push ===
Contributors: bkcchurch
Tags: push notifications, fcm, firebase, church, korean
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

BKC 교회 iOS 앱을 위한 FCM 기반 푸쉬 공지 관리 플러그인. / FCM-based push notification management for BKC Church iOS app.

== Description ==

**한국어**

베델교회 어바인(bkc.org)의 iOS 앱에 푸쉬 알림을 발송하기 위한 WordPress 플러그인입니다. Firebase Cloud Messaging(FCM) HTTP v1 API를 사용하며, 그룹별(전체/청년부/새가족) 타겟 발송을 지원합니다.

주요 기능:
* FCM 토픽 기반 그룹 타겟 발송 (전체/청년부/새가족)
* 복수 그룹 발송 시 condition 합집합으로 단일 호출 처리 → 중복 수신 방지 (최대 5 토픽)
* 관리자 작성 UI — 제목, 본문, 딥링크, 그룹 선택, 발송 전 confirm 모달
* Action Scheduler 기반 비동기 발송 (PHP `max_execution_time` 30초 제약 회피)
* campaign UUID 기반 idempotency (더블클릭/페이지 새로고침으로 인한 중복 발송 차단)
* `queued` 상태 캠페인 즉시 취소 가능 (관리자 전용 엔드포인트)
* 클라이언트 텔레메트리 수집 (delivered/opened/deeplinked) — NSE 기반으로 terminated 상태 수신도 기록
* 1시간 단위 통계 롤업 + 관리자 대시보드 (전달률·열람률·딥링크 CTR)
* 레이트 리밋 (공개 엔드포인트 보호)
* FCM 서비스 계정 키를 웹 루트 바깥에 안전하게 보관 (UI에서 편집 불가)
* 6개월 이상된 원시 이벤트 자동 정리 cron

**English**

A WordPress plugin for sending FCM push notifications to BKC Church iOS app users. Uses Firebase Cloud Messaging HTTP v1 API with group-based topic targeting (all / youth / new family).

Features:
* FCM topic-based group targeting (all / youth / newfamily)
* Multi-group sends use a single condition call (OR union, max 5 topics) to prevent duplicate delivery
* Admin compose UI — title, body, deep link, group selection, confirm modal before send
* Async dispatch via Action Scheduler (avoids PHP timeout)
* Campaign UUID idempotency (prevents duplicate sends on double-click or retry)
* Cancel `queued` campaigns immediately (admin-only endpoint)
* Client telemetry collection (delivered / opened / deeplinked) — NSE-based recording covers terminated app
* Hourly stats rollup with admin dashboard (delivery rate, open rate, deeplink CTR)
* Rate limiting on public REST endpoints
* FCM service account key stored securely outside webroot (no UI edit path)
* Auto-prune raw events older than 6 months

== Installation ==

1. Upload the `bkc-push` folder to `/wp-content/plugins/`.
2. Place your FCM service account JSON file at `/var/www/bkc-secrets/fcm-service-account.json` (`chmod 600`, owner `www-data`). Override path via the `BKC_FCM_SERVICE_ACCOUNT_PATH` constant in `wp-config.php` or the `BKC_FCM_SVC_ACCT` environment variable.
3. Run `composer install` in the plugin directory to install dependencies (Action Scheduler 3.7+, firebase/php-jwt 6.x).
4. Activate the plugin from the WordPress Plugins screen — activation runs `dbDelta` to create 4 tables (`wp_bkc_campaigns`, `wp_bkc_subscriptions`, `wp_bkc_campaign_events`, `wp_bkc_campaign_stats`).
5. Navigate to **푸쉬 공지** in the admin menu to start sending.

== REST API ==

Namespace: `bkc/v1`

Public endpoints (rate-limited per IP):
* `POST /subscribe` — register FCM token + groups
* `PATCH /subscribe/{device_id}` — change group subscription
* `DELETE /subscribe/{device_id}` — unsubscribe
* `GET /campaigns?since=...&limit=N` — delta fetch for native notifications tab
* `POST /events` — telemetry batch (max 100 events/request)

Admin-only endpoints (`current_user_can('manage_options')`):
* `GET /stats/campaign/{uuid}` — per-campaign aggregated stats
* `GET /stats/subscribers` — group breakdown + weekly trend
* `POST /campaigns/{uuid}/cancel` — cancel a `queued` campaign

== Frequently Asked Questions ==

= Where is the FCM service account key stored? =

Outside the webroot, at `/var/www/bkc-secrets/fcm-service-account.json` by default (`chmod 600`, owner `www-data`). Override with the `BKC_FCM_SVC_ACCT` environment variable or the `BKC_FCM_SERVICE_ACCOUNT_PATH` PHP constant in `wp-config.php`. The plugin only reads the file — there is no admin UI for editing the key.

= What FCM topics are used? =

* `bkc_all` — all subscribers (mandatory, cannot be unsubscribed via the app)
* `bkc_youth` — 청년부 (youth group)
* `bkc_newfam` — 새가족 (new family group)

Adding a new group requires updating both `BKC_Groups::WHITELIST` (PHP) and `SubscriptionGroup` (Swift enum). The two must stay in sync.

= How are duplicate sends prevented? =

Each campaign has a server-generated UUID used as an idempotency key. The compose form embeds the UUID before display; if the same UUID is submitted twice (double-click, page reload), only one campaign row is created and only one Action Scheduler dispatch job is enqueued. This is verified by the `Test_Idempotency.php` IRON RULE test on every CI run.

= How is duplicate delivery prevented when a user is in multiple groups? =

For multi-group sends the dispatcher builds a single FCM condition string (e.g. `'bkc_youth' in topics || 'bkc_newfam' in topics`) and makes one HTTP v1 call. FCM evaluates the OR union server-side and delivers exactly once per matching device. Limited to 5 topics per condition (FCM hard limit). Verified by `Test_FCM_Client.php::test_condition_dedup_iron_rule`.

= What PHP version is required? =

PHP 8.0 or later. CI matrix verifies PHP 8.1, 8.2, and 8.3.

= What WordPress version is required? =

WordPress 6.0 or later (tested through 6.7).

= How do I run the test suite? =

From the plugin directory: `composer install && vendor/bin/phpunit`. The suite is 41 tests across 8 files and runs in under a second using offline WordPress stubs (`tests/stubs/wp-stubs.php`) — no live MySQL or WP install required.

= What does the hourly rollup do? =

`BKC_Stats_Rollup` runs every hour via Action Scheduler. It aggregates raw rows from `wp_bkc_campaign_events` into `wp_bkc_campaign_stats` (delivered/opened/deeplinked counts), and prunes raw events older than 6 months. The rollup is idempotent — re-running produces identical results (verified by the `Test_Stats_Rollup.php` IRON RULE test).

== Security Notes ==

* All `$wpdb` queries use `prepare()` with placeholders.
* Admin views escape every output with `esc_html` / `esc_attr` / `esc_url` / `wp_kses_post`.
* Public REST endpoints validate FCM token format (regex), group whitelist, batch size cap (100), and IP-based rate limit (`BKC_Rate_Limiter`).
* Admin-only endpoints (`/stats/*`, `/campaigns/{uuid}/cancel`) gate via `permission_callback` returning `WP_Error('rest_forbidden', 403)` for non-admins.

== Changelog ==

= 1.0.0 =
* Initial release: FCM topic dispatch (single + condition union), group targeting, admin UI with confirm modal, NSE-friendly telemetry collection (`delivered`/`opened`/`deeplinked`), 1-hour stats rollup, idempotency by campaign UUID, cancel endpoint for queued campaigns, IP rate limiting, secure off-webroot service account key handling.

== Upgrade Notice ==

= 1.0.0 =
First release.

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
* 관리자 작성 UI — 제목, 본문, 딥링크, 그룹 선택
* Action Scheduler 기반 비동기 발송 (PHP 타임아웃 회피)
* campaign UUID 기반 중복 발송 방지 (더블클릭/재시도 안전)
* 클라이언트 텔레메트리 수집 (delivered/opened/deeplinked)
* 1시간 단위 통계 롤업 + 관리자 대시보드
* 레이트 리밋 (공개 엔드포인트 보호)
* FCM 서비스 계정 키를 웹 루트 바깥에 안전하게 보관

**English**

A WordPress plugin for sending FCM push notifications to BKC Church iOS app users. Uses Firebase Cloud Messaging HTTP v1 API with group-based topic targeting (all / youth / new family).

Features:
* FCM topic-based group targeting (all / youth / newfamily)
* Admin compose UI — title, body, deep link, group selection
* Async dispatch via Action Scheduler (avoids PHP timeout)
* Campaign UUID idempotency (prevents duplicate sends on double-click or retry)
* Client telemetry collection (delivered / opened / deeplinked events)
* Hourly stats rollup with admin dashboard
* Rate limiting on public REST endpoints
* FCM service account key stored securely outside webroot

== Installation ==

1. Upload the `bkc-push` folder to `/wp-content/plugins/`.
2. Place your FCM service account JSON file at `/var/www/bkc-secrets/fcm-service-account.json` (or set the `BKC_FCM_SERVICE_ACCOUNT_PATH` constant in `wp-config.php`).
3. Run `composer install` in the plugin directory to install dependencies (Action Scheduler, firebase/php-jwt).
4. Activate the plugin from the WordPress Plugins screen.
5. Navigate to **푸쉬 공지** in the admin menu to start sending.

== Frequently Asked Questions ==

= Where is the FCM service account key stored? =

Outside the webroot, at `/var/www/bkc-secrets/fcm-service-account.json` by default. Override with the `BKC_FCM_SVC_ACCT` environment variable or the `BKC_FCM_SERVICE_ACCOUNT_PATH` PHP constant in `wp-config.php`.

= What FCM topics are used? =

* `bkc_all` — all subscribers (mandatory, cannot be unsubscribed)
* `bkc_youth` — 청년부 (youth group)
* `bkc_newfam` — 새가족 (new family group)

= How are duplicate sends prevented? =

Each campaign has a UUID used as an idempotency key. The compose form generates a UUID server-side before display; if the same UUID is submitted twice (double-click, page reload), only one campaign row is created and only one dispatch job is enqueued.

= What PHP version is required? =

PHP 8.0 or later.

= What WordPress version is required? =

WordPress 6.0 or later.

== Changelog ==

= 1.0.0 =
* Initial release: FCM topic dispatch, group targeting, admin UI, telemetry collection, stats rollup, idempotency, rate limiting.

== Upgrade Notice ==

= 1.0.0 =
First release.

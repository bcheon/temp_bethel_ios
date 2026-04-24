# BKC 교회 iOS 앱 — 구현 계획

## Context

베델교회 어바인(bkc.org, WordPress + AWS 기반)은 현재 카카오톡 채널을 통해 교인에게 푸쉬 공지를 발송하고 있으나, **유료 구독/발송 비용 부담 + 카카오톡 플랫폼 종속성**이 문제로 대두되었다. 이를 해결하기 위해 자체 iOS 앱을 구축하여 (1) 자체 푸쉬 노티피케이션 인프라로 카톡 비용을 제거하고, (2) 교회 웹사이트 콘텐츠를 모바일 경험으로 제공하고자 한다.

초기 버전(v1)은 **기존 bkc.org 웹사이트 콘텐츠를 그대로 활용**하면서, 네이티브 앱만이 제공할 수 있는 **푸쉬 노티피케이션**과 **딥링크**를 더하는 최소 생존 가능 제품(MVP)을 지향한다.

## 의사결정 요약

| 결정 항목 | 선택 | 이유 |
|----------|------|------|
| 앱 아키텍처 | 네이티브 셸 + WebView 하이브리드 | '웹사이트 그대로' 요구 만족, App Store Guideline 4.2 리젝 회피 |
| 푸쉬 인프라 | FCM Topic 기반 브로드캐스트 + **Condition 합집합** | 그룹별 fan-out을 Google 서버에 위임, 복수 구독자에게 중복 발송 방지 |
| 푸쉬 저장소 | 캠페인 로그 + 토픽 구독 매핑 | 서버 부하 감소, 개인정보 최소화 |
| 그룹 구독 정책 | 복수 그룹 구독 + `all`은 자동/필수 | 모든 교인이 기본 공지 수신 보장, 개별 관심사는 추가 선택 |
| 관리자 발송 | WP Cron + Action Scheduler 비동기 dispatch | PHP `max_execution_time` 30초 제약 회피 |
| 중복 방지 | campaign UUID + submit lock + confirm | 더블클릭/재시도로 인한 중복 발송 차단 |
| 개발 리소스 | 사용자 + 교회 기술팀/자원봉사 + Claude AI | 팀 협업 가능 |
| v1 스코프 | MVP + 그룹 타겟팅 | 수동 발송 + 그룹(청년부/새가족/전체). 자동화·멤버로그인은 v1.1+ |
| 성공 기준 | 100~300명 실사용 + 주일 공지/주요 이벤트 신뢰 발송 | 카톡 병행 운영 후 점진적 전환 |
| 타임라인 | 10~12주 | iOS 전용. Android는 v2 이후. 텔레메트리 + 관리자 대시보드 포함. |
| 관측·분석 | 클라이언트 사이드 텔레메트리 + WP 자체 집계 | FCM Topic은 per-device 수신 정보 미제공. 3rd party 금지(개인정보 최소화). |
| iOS 최소 버전 | iOS 16 | SwiftUI NavigationStack, 교인 연령층 커버 (iPhone 8 이상) |
| Privacy Manifest | `PrivacyInfo.xcprivacy` 필수 포함 | 2024-05-01부터 App Store 심사 강제 |

## 비범위 (Non-Goals) — v1에서 명시적 제외

- 자동화 트리거 (새 설교 업로드/주일 리마인더 자동 발송) → v1.1
- **예약 발송** (`scheduled_at` 컬럼은 DB에 있지만 v1에서는 즉시 발송만 UI 지원) → v1.1
- WordPress 셀 리포트 멤버 로그인 네이티브 연동 → v1.1 (v1에서는 WebView가 자체 처리)
- `dismissed` 이벤트 추적 → iOS에서 구조적으로 불가능, 영구 제외
- Silent push 기반 전달률 보정 → v1.1 이후, iOS throttle 영향 평가 후
- Android 동시 출시 → v2
- A/B 테스트 인프라, 상세 분석 이벤트 → 교회 규모 대비 과잉
- 사용자 간 상호작용, 댓글, 기도 요청 제출 기능 → v2

## 보안 아키텍처

### FCM 서비스 계정 키 관리

**안티패턴:** `wp_options` 테이블에 JSON 평문 저장. 모든 WP admin이 읽을 수 있고 DB 백업에 노출됨.

**채택 방식:**
1. Service Account JSON을 **웹 루트 바깥**에 배치: `/var/www/bkc-secrets/fcm-service-account.json`
2. 파일 권한: `600`, 소유자: `www-data`
3. `wp-config.php`에 경로 상수:
   ```php
   define( 'BKC_FCM_SERVICE_ACCOUNT_PATH', '/var/www/bkc-secrets/fcm-service-account.json' );
   ```
4. 플러그인은 파일 읽기 전용, UI에서 키 편집 불가
5. 키 로테이션 절차 문서화 (Google Cloud IAM 콘솔에서 교체)

### 구독 엔드포인트 스푸핑 방지

- `POST /wp-json/bkc/v1/subscribe` 요청은 **앱 서명 검증 없이 받지만** 토큰 형식 검증 필수:
  - FCM 토큰 정규식: `^[a-zA-Z0-9_:-]+$`, 길이 100~300자
  - 알 수 없는 그룹 태그 거부 (화이트리스트: `all`, `youth`, `newfamily`)
- 레이트 리밋: 같은 IP에서 1분당 10회 (공개 엔드포인트 보호)
- 앱 바인딩: `User-Agent`에 `BKC-iOS/<version>` 체크 (soft validation)

### Universal Links (Apple App Site Association)

- bkc.org의 `/.well-known/apple-app-site-association` 파일 배치 (Cloudflare/CDN도 이 경로 허용 확인)
- 형식:
  ```json
  {
    "applinks": {
      "apps": [],
      "details": [{
        "appID": "TEAMID.org.bkc.churchapp",
        "paths": ["/sermon/*", "/news/*", "/event/*"]
      }]
    }
  }
  ```
- iOS 앱 `Associated Domains` entitlement: `applinks:bkc.org`

### Privacy Manifest (`PrivacyInfo.xcprivacy`)

Apple 2024-05-01 이후 심사 필수. Firebase 10.25+ SDK는 자체 Privacy Manifest 포함. 앱 레벨에서 추가 선언할 API:
- `NSPrivacyAccessedAPICategoryUserDefaults` — reason `CA92.1` (앱 자체 설정 저장)
- `NSPrivacyAccessedAPICategoryFileTimestamp` — reason `C617.1` (문서 캐시 타임스탬프, 공지 델타 페칭용)

수집하는 데이터 선언 (NSPrivacyCollectedDataTypes):
- `Device ID` — FCM 토큰 (사용자 식별 불가능, 앱 기능)
- `Other Data Types` — 그룹 구독 태그 (앱 기능)

## 아키텍처

### 상위 구성도

```
┌──────────────────── iOS 앱 (SwiftUI) ────────────────────┐
│  [하단 탭 바 — 네이티브]                                  │
│   홈 · 공지 · 설교 · 더보기                                │
│                                                            │
│  홈/설교/더보기 탭 → WKWebView (공유 WKProcessPool)        │
│  공지 탭 → 네이티브 목록 (로컬 캐시 + 서버 델타)           │
│                                                            │
│  Push 수신 → DeepLinkRouter → 해당 WebView 탭 URL 주입    │
│  Universal Links → 앱이 실행 중이면 DeepLinkRouter         │
└────────────────────────────────────────────────────────────┘
              ▲                           │
              │ APNs                      │ FCM 토픽 구독
              │                           ▼
         ┌─────────┐              ┌──────────────────────────┐
         │  FCM    │ ◄── HTTPS ───│  WordPress 플러그인       │
         │ (Google)│              │  (bkc.org WP 관리자)      │
         │  Topics │              │                          │
         │  ─bkc_all│              │  [관리자 UI]              │
         │  ─bkc_youth│             │   - 캠페인 작성          │
         │  ─bkc_newfam│            │   - 그룹 선택            │
         └─────────┘              │   - 미리보기 + 확인 모달 │
              │                   │                          │
              │ fan-out            │  [비동기 처리]            │
              ▼                   │   - Action Scheduler     │
         iOS 단말 전체             │   - dispatch job         │
                                  │   - 진행률 폴링           │
                                  │                          │
                                  │  [저장소]                 │
                                  │   - wp_bkc_campaigns     │
                                  │   - wp_bkc_subscriptions │
                                  │     (topic 매핑만)        │
                                  └──────────────────────────┘
```

### 데이터 플로우: 발송 이벤트

```
관리자                 WP 플러그인           Action Scheduler    FCM            iOS 기기
  │                      │                      │                 │              │
  │ 발송 UI 작성          │                      │                 │              │
  │ 그룹=youth, 링크=X    │                      │                 │              │
  │─── "발송" 클릭 ──────►│                      │                 │              │
  │                      │ confirm 모달         │                 │              │
  │◄── 재확인 ───────────│                      │                 │              │
  │                      │                      │                 │              │
  │─── OK ──────────────►│                      │                 │              │
  │                      │ campaign UUID 생성    │                 │              │
  │                      │ 락 획득 (5분 TTL)     │                 │              │
  │                      │ wp_bkc_campaigns INSERT                 │              │
  │                      │ status=queued        │                 │              │
  │                      │                      │                 │              │
  │                      │─── schedule job ────►│                 │              │
  │◄── "발송 큐 등록" ───│  (즉시 반환, 200ms)   │                 │              │
  │                      │                      │                 │              │
  │                      │                      │ (10초 후 실행)  │              │
  │                      │                      │ status=sending  │              │
  │                      │                      │─── POST /send ─►│              │
  │                      │                      │ condition:      │              │
  │                      │                      │ "'bkc_youth' in │              │
  │                      │                      │  topics ||      │              │
  │                      │                      │  'bkc_all' in   │              │
  │                      │                      │  topics"        │              │
  │                      │                      │◄── 200 OK ──────│              │
  │                      │                      │ FCM 서버에서      │              │
  │                      │                      │ OR 합집합 계산,    │              │
  │                      │                      │ 중복 수신 제거    │              │
  │                      │                      │ status=sent     │              │
  │                      │                      │ sent_at=now     │              │
  │                      │                      │                 │──► APNs     │
  │                      │                      │                 │              │
  │ 관리자 페이지 새로고침                       │                 │              │
  │─── GET campaign ────►│                      │                 │              │
  │◄── status=sent ──────│                      │                 │              │
```

### 데이터 플로우: 앱 시작 · 구독 등록

```
iOS 앱 (최초 실행)       PushService           WP REST API        FCM
  │                        │                      │                 │
  │ 알림 권한 요청           │                      │                 │
  │◄── 사용자 "허용" ──────│                      │                 │
  │                        │                      │                 │
  │                        │ FCM 토큰 요청          │                 │
  │                        │─── getToken ──────────────────────────►│
  │                        │◄── token ─────────────────────────────│
  │                        │                      │                 │
  │ 온보딩: 그룹 선택 화면    │                      │                 │
  │◄── "청년부" 선택 ──────│                      │                 │
  │                        │                      │                 │
  │                        │ subscribeToTopic("bkc_all") ──────────►│
  │                        │  (자동·필수, 해제 불가)                    │
  │                        │◄── OK ────────────────────────────────│
  │                        │ subscribeToTopic("bkc_youth") ────────►│
  │                        │  (사용자 추가 선택)                        │
  │                        │◄── OK ────────────────────────────────│
  │                        │                      │                 │
  │                        │ POST /subscribe     │                 │
  │                        │   { token, groups: ["all","youth"] }  │
  │                        │─────────────────────►│                 │
  │                        │                      │ wp_bkc_subscriptions INSERT
  │                        │◄── 200 ──────────────│                 │
  │                        │                      │                 │
  │ UserDefaults.groups = ["all","youth"]         │                 │
```

**Single Source of Truth 규칙:**
- 서버(`wp_bkc_subscriptions`)가 진실. 앱 로컬(`UserDefaults.groups`)은 캐시.
- 그룹 변경 시: FCM topic sub/unsub → 성공 → WP PATCH → 성공 → 로컬 업데이트. 중간 실패 시 로컬 업데이트 중단, 다음 앱 실행 시 서버 기준으로 재동기화.

## 기술 스택

### iOS 앱
- **언어/플랫폼:** Swift 5.9+, SwiftUI, 최소 iOS 16
- **네비게이션:** `TabView` + `NavigationStack`
- **WebView:** `WKWebView` + 공유 `WKProcessPool` + 공유 `WKWebsiteDataStore.default()` (탭 간 쿠키 공유)
- **푸쉬:** `Firebase Messaging iOS SDK 10.25+` (Privacy Manifest 내장) + `UNUserNotificationCenter`
- **네트워킹:** `URLSession` + `protocol BKCAPI` 추상화 (테스트 시 mock URLProtocol)
- **로컬 저장:** `UserDefaults` (사용자 설정), `FileManager` JSON 파일 (공지 캐시 최대 50건), `Keychain` (향후 로그인 토큰)
- **관측:** `os_log` (`OSLog(subsystem: "org.bkc.app", category: "push")`)

### WordPress 플러그인 (신규)
- **언어:** PHP 8.0+ (WP 권장 최소)
- **비동기 처리:** Action Scheduler 라이브러리 (WooCommerce가 채택한 검증된 스케줄러). WP Cron만 쓰면 트래픽 없는 시간에 실행 안 되는 문제 존재.
- **관리자 UI:** WP admin menu "푸쉬 공지" + React 없이 기본 WP 컴포넌트
- **DB 스키마:**
  ```sql
  CREATE TABLE wp_bkc_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,            -- idempotency key
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    deep_link VARCHAR(2048),
    target_groups JSON NOT NULL,               -- ["all","youth"]
    status ENUM('draft','queued','sending','sent','failed','cancelled') DEFAULT 'draft',
    scheduled_at DATETIME NULL,
    sent_at DATETIME NULL,
    fcm_message_ids JSON NULL,                 -- {"bkc_all":"msg_123",...}
    error_message TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,       -- wp user id
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created (created_at DESC)
  );

  CREATE TABLE wp_bkc_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fcm_token VARCHAR(512) NOT NULL,
    device_id CHAR(36) NOT NULL,               -- 앱이 생성한 UUID, 토큰 갱신 대응
    platform ENUM('ios','android') DEFAULT 'ios',
    app_version VARCHAR(32),
    groups JSON NOT NULL,                      -- ["all","youth"]
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_device (device_id),
    INDEX idx_last_seen (last_seen)
  );

  -- 클라이언트 텔레메트리: 원시 이벤트 (6개월 보관)
  -- dismissed는 iOS가 추적 불가능(스와이프는 앱에 알려지지 않음)이라 제외.
  CREATE TABLE wp_bkc_campaign_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_uuid CHAR(36) NOT NULL,
    device_id CHAR(36) NOT NULL,
    event_type ENUM('delivered','opened','deeplinked') NOT NULL,
    occurred_at DATETIME NOT NULL,             -- 앱에서 발생 시각
    server_received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign (campaign_uuid),
    INDEX idx_device_time (device_id, occurred_at),
    UNIQUE KEY unique_event (device_id, campaign_uuid, event_type)  -- 서버 측 dedup
  );

  -- 집계 롤업 (1시간마다 재계산, 무기한 보관)
  CREATE TABLE wp_bkc_campaign_stats (
    campaign_uuid CHAR(36) PRIMARY KEY,
    subscribers_targeted INT,                   -- 발송 시점 대상 구독자 수
    delivered_count INT DEFAULT 0,
    opened_count INT DEFAULT 0,
    deeplinked_count INT DEFAULT 0,
    last_rolled_up DATETIME
  );
  ```
- **REST API 엔드포인트:**
  - `POST /wp-json/bkc/v1/subscribe` — 토큰 + 그룹 등록 (토큰 형식 검증, 화이트리스트 검증, 레이트 리밋)
  - `PATCH /wp-json/bkc/v1/subscribe/{device_id}` — 그룹 변경
  - `DELETE /wp-json/bkc/v1/subscribe/{device_id}` — 구독 해지
  - `GET /wp-json/bkc/v1/campaigns?since=<iso8601>&limit=50` — 공지 탭 델타 페칭
  - `POST /wp-json/bkc/v1/events` — 클라이언트 텔레메트리 배치 (`{events: [{campaign_uuid, device_id, event_type, occurred_at}, ...]}`). 배치당 최대 100건, 레이트 리밋 1분당 10회.
  - `GET /wp-json/bkc/v1/stats/campaign/{uuid}` — **관리자 전용** (`permission_callback: current_user_can('manage_options')` + `wp_rest` nonce), 해당 캠페인 집계 통계
  - `GET /wp-json/bkc/v1/stats/subscribers` — **관리자 전용**, 그룹별 활성 구독자 수 + 주간 추이
  - `POST /wp-json/bkc/v1/campaigns/{uuid}/cancel` — **관리자 전용**, `status=queued` 캠페인 취소 (Action Scheduler job 취소 + `status=cancelled`로 전이). 이미 sending/sent 상태는 취소 불가.
- **FCM 호출:** `kreait/firebase-php` 라이브러리 또는 직접 HTTP v1. **Topic Condition 기반 단일 호출.**
  - 단일 그룹: `"topic": "bkc_all"` → 해당 토픽 구독자에게 전달
  - 복수 그룹: `"condition": "'bkc_youth' in topics || 'bkc_newfam' in topics"` → FCM이 OR 합집합 계산, 복수 구독자 중복 제거
  - `all` 포함 시: `condition` 생략하고 `topic: bkc_all`로 단일 호출 (전체 발송)

### 인프라
- **iOS 빌드/배포:** Xcode 15+ + TestFlight (Apple Developer Program $99/년)
- **Firebase:** 무료 Spark 티어 충분 (Topic fan-out 무제한 무료)
- **WordPress:** bkc.org 기존 AWS 인프라 + stage 사이트 권장 (`stage.bkc.org`)
- **CI:** GitHub Actions로 iOS 테스트 자동 실행, TestFlight 자동 업로드 (Fastlane)
- **Secrets:** WP은 환경변수/파일, iOS는 Xcode Config + CI secrets

## 디렉터리 구조

```
fanatical-pruner/
├── doc/
│   └── ios-app-plan.md              # 이 문서
├── ios/
│   └── BKC/                        # Xcode 프로젝트
│       ├── BKC.xcodeproj
│       ├── BKC/
│       │   ├── App/
│       │   │   ├── BKCApp.swift                 # @main, Firebase 초기화
│       │   │   └── AppDelegate.swift            # APNs/FCM 등록, Universal Links
│       │   ├── Views/
│       │   │   ├── RootTabView.swift            # 하단 탭 바
│       │   │   ├── WebViewTab.swift             # WKWebView 래퍼 (UIViewRepresentable)
│       │   │   ├── WebViewStore.swift           # @StateObject, 재생성 방지
│       │   │   ├── NotificationListView.swift   # 공지 탭 (네이티브 목록)
│       │   │   ├── GroupOnboardingView.swift    # 최초 그룹 선택
│       │   │   └── MoreView.swift               # 설정·그룹 변경·버전 정보
│       │   ├── Services/
│       │   │   ├── BKCAPI.swift                 # protocol (테스트 가능성)
│       │   │   ├── BKCAPIClient.swift           # 실제 URLSession 구현
│       │   │   ├── PushService.swift            # FCM 토큰 + Topic 구독
│       │   │   ├── DeepLinkRouter.swift         # URL → Tab 매핑
│       │   │   ├── GroupStore.swift             # 서버 SoT, 로컬 캐시
│       │   │   ├── CampaignCache.swift          # 공지 로컬 50건 JSON
│       │   │   └── TelemetryService.swift       # 수신/열람 이벤트 배치 + 주기 flush
│       │   ├── Models/
│       │   │   ├── PushCampaign.swift
│       │   │   ├── SubscriptionGroup.swift
│       │   │   └── DeepLink.swift
│       │   └── Resources/
│       │       ├── Info.plist
│       │       ├── PrivacyInfo.xcprivacy        # 심사 필수
│       │       ├── BKC.entitlements             # Associated Domains (Universal Links)
│       │       └── GoogleService-Info.plist     # Firebase config (커밋 X, Xcode Config 변수)
│       └── BKCTests/
│           ├── PushServiceTests.swift
│           ├── DeepLinkRouterTests.swift
│           ├── GroupStoreTests.swift
│           ├── CampaignCacheTests.swift
│           ├── BKCAPIClientTests.swift
│           ├── TelemetryServiceTests.swift
│           └── Support/
│               └── MockURLProtocol.swift
└── wordpress-plugin/
    └── bkc-push/
        ├── bkc-push.php                 # 플러그인 엔트리, 활성화 hooks
        ├── admin/
        │   ├── class-bkc-admin.php      # 관리자 메뉴
        │   └── views/
        │       ├── compose.php          # 발송 작성 UI (idempotency token 포함)
        │       ├── campaign-list.php    # 발송 이력
        │       ├── campaign-stats.php   # 캠페인 상세 지표 (전달/열람/딥링크 CTR)
        │       └── dashboard.php        # 메인 대시보드 (구독자 추이, 캠페인 요약)
        ├── includes/
        │   ├── class-bkc-fcm-client.php       # FCM HTTP v1 (topic 기반)
        │   ├── class-bkc-dispatcher.php       # Action Scheduler 연결
        │   ├── class-bkc-rest-api.php         # REST endpoints + 검증
        │   ├── class-bkc-subscriptions.php    # CRUD
        │   ├── class-bkc-campaigns.php        # CRUD
        │   ├── class-bkc-events.php           # 텔레메트리 이벤트 수집 + 검증
        │   ├── class-bkc-stats-rollup.php     # 1시간 cron 집계 잡
        │   ├── class-bkc-groups.php           # 화이트리스트
        │   └── class-bkc-rate-limiter.php     # 공개 엔드포인트 보호
        ├── tests/
        │   ├── bootstrap.php
        │   ├── test-fcm-client.php
        │   ├── test-rest-api.php
        │   ├── test-dispatcher.php
        │   ├── test-subscriptions.php
        │   ├── test-idempotency.php
        │   ├── test-events-endpoint.php
        │   ├── test-stats-rollup.php
        │   └── test-stats-permissions.php
        ├── vendor/                            # Composer (Action Scheduler, kreait/firebase-php)
        └── assets/
            └── admin.js                       # 확인 모달 + submit 방지
```

## 구현 단계 (10~12주 로드맵)

**Week 1 — 프로젝트 기반 구축 + 심사 필수 요건**
- Apple Developer Program 등록 확인
- Xcode 프로젝트 생성, 번들 ID `org.bkc.churchapp`, Team ID 확보
- `PrivacyInfo.xcprivacy` 작성
- `Associated Domains` entitlement 설정 (`applinks:bkc.org`)
- Firebase 프로젝트 생성, iOS 앱 등록, APNs 인증 키 업로드
- bkc.org `.well-known/apple-app-site-association` 파일 업로드
- WordPress 플러그인 스캐폴드 + DB 마이그레이션 (campaigns/subscriptions 테이블)
- FCM 서비스 계정 키를 웹 루트 바깥 배치 + `wp-config.php` 상수 설정
- 테스트 환경: stage WP + 테스트용 Firebase 프로젝트 분리

**Week 2 — WebView 탭 + Universal Links**
- 각 탭 URL 매핑 (홈: `bkc.org/`, 공지는 네이티브, 설교: `bkc.org/sermon`, 더보기: `bkc.org/info`)
- `WKWebView` 공유 `WKProcessPool` + `WKWebsiteDataStore.default()` 적용
- `WebViewStore @StateObject`로 재생성 누수 방지
- Pull-to-refresh, 로딩 인디케이터, 네트워크 오류 처리, 다크모드 CSS 주입
- 외부 링크(YouTube, Instagram, Tithe.ly) Safari 핸드오프
- Universal Links 실기기 검증 (SMS로 `https://bkc.org/sermon/...` 발송 → 앱 열림 확인)

**Week 3 — FCM 등록 흐름 + Topic 구독**
- Firebase SDK 통합 (Swift Package Manager: `Firebase/Messaging` 10.25+)
- 알림 권한 요청 + 거부 시 "설정 앱" 유도 플로우
- FCM 토큰 획득 → WP `POST /subscribe` (device_id UUID 생성)
- 그룹 선택 온보딩 → `Messaging.messaging().subscribe(toTopic:)` + WP 동기화
  - `bkc_all`은 자동 구독·해제 불가 (UI에서 disabled checkbox로 체크 고정)
  - `bkc_youth`, `bkc_newfam` 등은 사용자 선택 (복수 선택 가능)
  - "더보기" 탭에서 언제든 그룹 추가/해제 가능 (단 `all`은 제외)
- 토큰 갱신(`messaging(_:didReceiveRegistrationToken:)`) → WP 재등록
- 앱 삭제/재설치 대응 (device_id Keychain 보관 고려)
- **TelemetryService 스켈레톤** + UNUserNotificationCenterDelegate에서 `delivered`/`opened` 이벤트 기록 (로컬 버퍼만, flush는 Week 5에서)
- 테스트: `PushServiceTests`, `GroupStoreTests`, `TelemetryServiceTests` 작성

**Week 4 — WordPress 발송 + 비동기 큐 + idempotency**
- 관리자 작성 UI: 제목/본문/딥링크/그룹 선택/미리보기
  - "전체(all)" 체크박스: 체크 시 다른 그룹 체크박스 disabled + 라벨을 "전체 발송"으로 변경
  - 다른 그룹 체크 시 `all`은 자동 uncheck (상호 배타)
- submit 시 JavaScript로 버튼 비활성화 + confirm 모달
- campaign UUID 생성 → DB insert(status=queued) → Action Scheduler에 dispatch
- Action Scheduler 작업: 대상 그룹에 따라 FCM v1 API 호출 → message_id 저장 → status=sent
  - `target_groups = ["all"]` → `POST /send` with `topic: "bkc_all"` (단일 호출)
  - `target_groups = ["youth"]` → `POST /send` with `topic: "bkc_youth"` (단일 호출)
  - `target_groups = ["youth", "newfam"]` → `POST /send` with `condition: "'bkc_youth' in topics || 'bkc_newfam' in topics"` (단일 호출, 중복 제거)
- 실패 시 `error_message` 기록, 관리자 페이지에서 "재시도" (같은 uuid 재사용, dedup)
- "재전송" 버튼은 새 uuid로 새 캠페인 생성
- **"취소" 버튼 (status=queued 한정)**: `POST /campaigns/{uuid}/cancel` 호출 → Action Scheduler job 취소 + `status=cancelled` 전이. 실수 발송 10초 내 대응 가능.
- **`POST /events` 엔드포인트** + `wp_bkc_campaign_events` 테이블 + 검증·레이트 리밋
- 발송 시점에 `subscribers_targeted` 값을 `wp_bkc_campaign_stats`에 기록 (나중에 전달률 계산 기준)
- 테스트: `test-fcm-client.php`, `test-dispatcher.php`, `test-idempotency.php`, `test-events-endpoint.php`

**Week 5 — 공지 탭 + 딥링크 + 로컬 캐시**
- 공지 탭: 앱 실행 시 `CampaignCache`에서 마지막 50건 즉시 표시 (오프라인에서도 보임)
- 백그라운드: `GET /campaigns?since=<last_synced_at>` 델타 페칭, 캐시 머지
- 탭 시 `DeepLinkRouter.route(url:)` → 해당 WebView 탭 활성화 + URL 주입
- 뱃지 카운트(미읽음) 관리, 읽음 상태 로컬 저장
- 푸쉬 수신 로깅 (UNUserNotificationCenterDelegate → CampaignCache 업데이트)
- **TelemetryService 배치 flush 구현**: 앱 백그라운드 진입 / 앱 시작 / 5분 타이머 트리거. 실패 시 UserDefaults에 최대 7일 보관.
- **DeepLinkRouter에서 `deeplinked` 이벤트 기록** (라우팅 성공 시 TelemetryService 호출)
- 테스트: `DeepLinkRouterTests`, `CampaignCacheTests`, `TelemetryServiceTests` (flush 시나리오)

**Week 6 — 통계 Rollup + CI 셋업**
- **`class-bkc-stats-rollup.php` 구현 + Action Scheduler 1시간 recurring**: 이벤트 → 집계 업데이트
- **`GET /stats/campaign/{uuid}`, `GET /stats/subscribers` 엔드포인트** (관리자 권한 체크)
- **원시 이벤트 6개월 이전 자동 삭제 cron**
- GitHub Actions 파이프라인: iOS 유닛 테스트 + WP PHPUnit 자동 실행
- Fastlane으로 TestFlight 자동 업로드
- `BKCAPIClientTests` (MockURLProtocol 활용)
- 실패 토큰 정리 cron: `unregistered` 에러 응답 받은 device_id를 subscriptions 테이블에서 제거
- Privacy/Security 체크: Privacy Manifest 검증 (텔레메트리 선언 포함), secret 누출 스캔
- 테스트: `test-stats-rollup.php` (이벤트 → stats 테이블 집계 정확성)

**Week 7 — 관리자 대시보드 UI**
- `dashboard.php`: 메인 대시보드 (구독자 현황 + 최근 캠페인 요약)
- `campaign-stats.php`: 캠페인별 상세 지표 (전달률, 열람률, 딥링크 CTR)
- Chart.js (CDN 사용 안 함, WP에 번들) 또는 순수 HTML 스파크라인
- CSV 내보내기 기능
- 관리자 권한 가드 테스트 (비권한 사용자는 대시보드 접근 불가)

**Week 8 — 내부 베타 (TestFlight)**
- 내부 테스터(교장/기술팀 5~10명) 배포
- 실기기 푸쉬 전달 테스트 매트릭스: iOS 16/17/18 × 포어그라운드/백그라운드/강제종료
- 그룹 타겟팅 검증 (youth 발송 시 newfamily만 구독자는 미수신)
- 한글 푸쉬 본문 인코딩 점검 (이모지 포함)
- Universal Links 실기기 수동 테스트

**Week 9 — 외부 베타 (100~300명)**
- TestFlight 외부 테스트 초대 (교회 게시판 + 카톡 채널로 초대 링크 공지)
- 주일 공지를 앱 푸쉬 + 카톡 채널 병행 발송 (4주 병행 운영)
- 실데이터로 대시보드 검증: 전달률·열람률이 예상 범위인지 (전달 > 90%, 열람 > 40% 기대)
- 버그 트래커 Google Form 링크 "더보기" 탭에 추가

**Week 10 — App Store 제출 & 심사 대응**
- 개인정보처리방침 페이지 (bkc.org에 추가) — 수집 항목 · 보관 기간 · 텔레메트리 설명 · 문의처
- 앱스토어 스크린샷(6.7" iPhone) + 메타데이터 한국어
- 심사 대응 노트: "native tab bar + push notifications with group targeting + Universal Links" + 테스트 계정 + 비디오 프리뷰
- Guideline 4.2/5.1.1/5.1.2 대비

**Week 11~12 — 공개 출시 + 카톡 단계적 중단 준비**
- 단계적 롤아웃 (TestFlight → App Store)
- 카톡 채널 공지에 "앱 전환 안내" 고정, 2주 후 카톡 단독 공지 중단 계획
- 출시 후 첫 주 지표 검토 + 이상 지표 대응 (전달률 90% 미만 시 근본 원인 조사)

## 테스트 전략

### 목표
- **신규 코드 100% 행 커버리지**. 플랜에 기재된 모든 함수는 구현과 동시에 테스트를 작성.
- 실제 네트워크·FCM 호출 없이 로컬에서 CI 가능.

### iOS (XCTest + Swift Testing)

| 대상 | 파일 | 핵심 테스트 케이스 |
|------|------|-----------------|
| `PushService` | `PushServiceTests.swift` | 토큰 획득 성공/실패, 재시도 로직, 토큰 갱신 → WP 재등록, 토픽 sub 성공/실패 |
| `DeepLinkRouter` | `DeepLinkRouterTests.swift` | bkc.org/news → 공지 탭, bkc.org/sermon → 설교 탭, 외부 URL → Safari, 잘못된 URL → 홈 |
| `GroupStore` | `GroupStoreTests.swift` | 그룹 추가 → FCM → WP → 로컬, **`all` 해제 시도 거부**, **신규 설치 시 `all` 자동 구독**, 중간 실패 시 롤백, 오프라인에서 그룹 변경 → 재연결 시 동기화 |
| `CampaignCache` | `CampaignCacheTests.swift` | 50건 초과 시 FIFO 정리, 델타 머지, 읽음 상태 유지, 손상된 JSON 복구 |
| `BKCAPIClient` | `BKCAPIClientTests.swift` (MockURLProtocol) | 200/401/500 응답 처리, 타임아웃, 재시도 백오프, 한글 인코딩 |
| `TelemetryService` | `TelemetryServiceTests.swift` | 이벤트 append → 영속 저장, 배치 flush 성공/실패, 7일 지난 이벤트 drop, 중복 이벤트 제거, 동시성 (queue) |

### WordPress (PHPUnit)

| 대상 | 파일 | 핵심 테스트 케이스 |
|------|------|-----------------|
| `BKC_FCM_Client` | `test-fcm-client.php` | 단일 topic payload 형식, **복수 그룹 시 condition 문자열 조립**, **`all` 포함 시 condition 생략**, 401(키 만료) 핸들링, 한글/이모지 |
| `BKC_Dispatcher` | `test-dispatcher.php` | queued → sending → sent 상태 전이, 실패 시 error 기록, Action Scheduler 연동, **queued 상태에서 cancel 요청 시 job 취소 + status=cancelled** |
| `BKC_Subscriptions` | `test-subscriptions.php` | 신규 등록, 중복 device_id 업서트, 그룹 화이트리스트 검증 |
| `BKC_REST_API` | `test-rest-api.php` | 토큰 형식 검증, 레이트 리밋, 잘못된 그룹 거부, CORS |
| Idempotency | `test-idempotency.php` | 같은 UUID로 재요청 시 1회만 발송 (더블클릭 시나리오) |
| `BKC_Events` | `test-events-endpoint.php` | 배치 수집, 100건 초과 거부, 알 수 없는 campaign_uuid 조용히 drop, 동일 (device, campaign, type) 중복 제거 |
| `BKC_Stats_Rollup` | `test-stats-rollup.php` | 이벤트 → stats 테이블 정확 집계, 재실행 시 멱등, 6개월 이전 이벤트 삭제 |
| Stats API 권한 | `test-stats-permissions.php` | 비관리자는 `/stats/*` 접근 불가, 관리자만 상세 데이터 확인 |

### E2E (수동 + 부분 자동화)

자동화:
- GitHub Actions에서 Xcode 시뮬레이터로 iOS 앱 빌드 + 유닛 테스트
- WP PHPUnit
- 딥링크 라우팅: `xcrun simctl openurl` 로 시뮬레이터에 URL 주입해 라우팅 확인

수동 (자동화 불가):
- 실기기 APNs 전달 (포어/백/종료)
- Universal Links (SMS 링크 클릭)
- 한글/이모지 푸쉬 가독성
- iOS 16/17/18 각 버전 전달

### 회귀 방지 (IRON RULE)

다음은 **반드시** 자동화 회귀 테스트 필요:
1. `test-idempotency.php` — 관리자 중복 제출 방지 (사용자 불만 직결)
2. `test-fcm-client.php::condition_dedup` — 복수 그룹 구독자에게 한 캠페인이 2번 전달되지 않는지 (condition 조립 검증)
3. `GroupStoreTests.cannot_unsubscribe_all` — `all` 토픽 해제 시도 시 거부 (공지 소실 방지)
4. `DeepLinkRouterTests.external URL → Safari` — WebView 안에서 YouTube 열리는 회귀
5. `GroupStoreTests.롤백` — 중간 실패 시 로컬/서버 불일치 회귀
6. `TelemetryServiceTests.offline_buffer` — 네트워크 장애 시 이벤트 유실 회귀 (대시보드 신뢰도 직결)
7. `test-stats-rollup.php::idempotent_rerun` — 집계 중복/누락 회귀

## 실패 모드 & 복구

| 실패 시나리오 | 발생 빈도 | 현재 대응 | 테스트 |
|------------|---------|---------|-------|
| FCM 서비스 계정 키 만료 | 낮음 (드물지만 재난급) | WP에서 FCM 401 감지 → 관리자 이메일 + 발송 로그에 명시. 수동 로테이션 | ✓ `test-fcm-client.php` |
| PHP 타임아웃 중 발송 | 중간 | Action Scheduler가 재시도 (최대 3회), campaign uuid dedup으로 중복 방지 | ✓ `test-dispatcher.php` |
| 사용자가 알림 권한 거부 | 흔함 | 앱에서 "설정으로 이동" 버튼 + 알림 없어도 WebView 콘텐츠는 사용 가능 | ✓ `PushServiceTests` |
| 네트워크 오프라인 중 앱 실행 | 흔함 | 공지 탭은 로컬 캐시 50건 표시, WebView 탭은 "인터넷 필요" 에러 + 재시도 | ✓ `CampaignCacheTests` |
| 관리자 더블클릭 발송 | 중간 (실수로 일어남) | JavaScript 버튼 비활성화 + 서버 uuid dedup + confirm 모달 | ✓ `test-idempotency.php` |
| 복수 그룹 구독자 중복 수신 | 흔함 (multi-topic + multi-target 발송 시) | FCM Condition OR 합집합으로 FCM 서버에서 중복 제거, 캠페인당 단일 API 호출 | ✓ `test-fcm-client.php::condition_dedup` |
| 사용자가 `all` 해제 시도 | 흔함 (실수 또는 "조용히 하고 싶음") | UI에서 disabled, API도 거부. 앱 알림 자체를 끄려면 iOS 시스템 설정 유도 | ✓ `GroupStoreTests.cannot_unsubscribe_all` |
| `/events` 엔드포인트 장애 | 중간 (WP 다운 / 네트워크) | 클라이언트가 UserDefaults에 최대 7일 버퍼링, 재시도. 7일 초과 이벤트만 drop | ✓ `TelemetryServiceTests.offline_buffer` |
| 텔레메트리 이벤트 중복 전송 | 흔함 (앱 재시작·flush 경합) | 서버 측 (device_id, campaign_uuid, event_type) 조합 UNIQUE 검증으로 dedup | ✓ `test-events-endpoint.php::dedup` |
| rollup cron 정지 | 낮음 (Action Scheduler 장애) | stats 테이블 `last_rolled_up` 모니터링, 2시간 이상 stale 시 관리자 알림 | ✓ `test-stats-rollup.php::idempotent_rerun` |
| 이벤트 테이블 폭증 | 낮음 (6개월 이상 운영 시) | 6개월 이전 자동 삭제 cron, 파티셔닝 불필요 (교회 규모) | 수동 용량 모니터링 |
| FCM 토큰 갱신 (iCloud 복원 등) | 흔함 | `didReceiveRegistrationToken` hook → WP 재등록, 기존 device_id 유지 | ✓ `PushServiceTests` |
| 잘못된 딥링크 URL | 낮음 | 알 수 없는 경로는 홈 탭으로 fallback, 로그에 기록 | ✓ `DeepLinkRouterTests` |
| WebView 메모리 누수 (탭 전환 반복) | 중간 | `@StateObject WebViewStore`로 인스턴스 고정, 공유 `WKProcessPool` | 수동 메모리 프로파일 |
| stage WP 설정이 프로덕션에 반영 | 낮음 | Xcode Config 분리 (Debug/Release), CI에서 환경 변수 주입 | CI 파이프라인 |
| 개인정보보호 심사 리젝 | 낮음 | Privacy Manifest 포함, 데이터 수집 설명 페이지 | Week 9 체크리스트 |

**Silent failure 방지:** 모든 실패 경로는 `os_log` (iOS) 또는 WP debug log에 남김. 관리자가 캠페인 목록에서 실패 메시지 확인 가능.

## 관측 & 분석 (Observability & Analytics)

### 왜 클라이언트 텔레메트리가 필요한가

FCM Topic 브로드캐스트는 Google 서버가 fan-out하므로 **per-device 전달 상태를 리턴하지 않는다.** "발송"만 알 수 있고 "누가 받았는지/열람했는지"는 앱이 자체 리포트해야 확인 가능하다. 이 설계는 업계 표준 (OneSignal, Airship 등도 동일 메커니즘 사용).

### 추적 이벤트 분류

| 이벤트 | 발생 시점 | 리포트 주체 | 신뢰도 |
|-------|---------|-----------|-------|
| `sent` | WP가 FCM으로 발송 완료 | 서버 (자동, `campaigns.status = sent`) | **100% 정확** |
| `delivered` | iOS가 푸쉬 payload 수신하고 **앱이 실행 가능한 상태** (포어그라운드·백그라운드) | 앱 (`UNUserNotificationCenterDelegate.willPresent`) | **하한치만** (아래 주의사항 참조) |
| `opened` | 사용자가 알림을 탭해서 앱 진입 | 앱 (`didReceive` response) | 정확 |
| `deeplinked` | 알림 탭 후 WebView 탭으로 라우팅 성공 | 앱 (`DeepLinkRouter` 완료 시) | 정확 |

> **`dismissed` 이벤트는 추적 불가능.** iOS는 사용자의 알림 스와이프/무시를 앱에 알려주지 않음 (`removeDeliveredNotifications`는 앱이 호출하는 API, 사용자 액션이 아님). OneSignal·Airship도 이 지표 추적 안 함. 테이블에서 제외.

### `delivered` 이벤트의 구조적 한계 (중요)

iOS는 **앱 완전 종료 상태**에서 알림을 받으면 앱을 깨우지 않음. 사용자가 알림을 탭해야 앱이 실행되고 그제서야 `delivered` + `opened` 함께 기록. 무시하고 알림을 내리면 **`delivered` 이벤트는 영원히 기록되지 않음.**

**결과:** 실측 `delivered` < 실제 수신 기기. 전달률 지표는 **하한치**로만 의미 있음.

**3가지 대응 전략 중 선택:**

1. **(권장, v1 채택) 하한치로 인정**: `delivered`는 "앱을 써본 활성 사용자 중 수신 확인된 수"로 정의. 전달률 목표를 낮추고 `opened`·`deeplinked`를 핵심 KPI로.
2. (v1.1 이후) Silent push(`content-available: 1`) 추가 발송 → 앱 깨워서 기록. iOS throttle(기기당 하루 3~5개 제한) 때문에 주 3~4회 주일 공지에만 허용.
3. (포기) `delivered` 추적 빼고 `opened`/`deeplinked`만 쓰기.

**v1에서는 전략 1 채택.** `delivered` 기록은 best-effort 하한치. 핵심 KPI는 `opened`.

### `subscribers_targeted` 정확성

`subscribers_targeted`는 캠페인 발송 시점의 WP `wp_bkc_subscriptions` 테이블에서 해당 그룹 조합을 구독한 row 수를 스냅샷으로 기록. 하지만 이 숫자와 **실제 FCM이 도달 가능한 기기 수는 일치하지 않는다:**
- 앱 삭제된 기기는 다음 발송까지 WP에 남아있음 (FCM unregistered 에러로만 감지)
- iCloud 복원 등으로 FCM 토큰 무효화된 경우도 WP는 모름

**완화 장치:**
1. `subscribers_targeted` 쿼리 시 `last_seen >= NOW() - INTERVAL 14 DAY` 조건 추가 (2주간 이벤트 없음 = 비활성)
2. `class-bkc-subscriptions.php`에 `prune_stale_subscriptions()` 메서드 + 주간 cron: FCM `UNREGISTERED` 응답 받은 device_id 즉시 삭제
3. 대시보드에 "활성 구독자(14일 이내 활동)" vs "총 등록" 둘 다 노출

이 완화 후에도 오차는 남음. **전달률은 절대값으로 해석하지 말고 주간 추이로 해석**.

### 텔레메트리 배치 전송 플로우

```
iOS 앱                        TelemetryService            WP /events
  │                              │                           │
  │ push 수신                     │                           │
  │─── logDelivered(cid) ───────►│ pendingEvents에 append    │
  │                              │ (UserDefaults 영속)        │
  │                              │                           │
  │ 사용자 탭                      │                           │
  │─── logOpened(cid) ──────────►│ pendingEvents에 append    │
  │                              │                           │
  │ 1) 앱 백그라운드 진입 2) 앱 시작 3) 5분 타이머 → flush()    │
  │                              │                           │
  │                              │─── POST /events ─────────►│ 검증
  │                              │    { events: [...] }      │ INSERT wp_bkc_campaign_events
  │                              │◄── 200 { accepted: N } ───│
  │                              │ 성공한 이벤트 pending에서 제거 │
  │                              │                           │
  │                              │ (1시간 후 cron)             │
  │                              │                           │ rollup job:
  │                              │                           │ SELECT COUNT(DISTINCT device_id) ...
  │                              │                           │ UPSERT wp_bkc_campaign_stats
```

**오프라인 대응:** flush 실패 시 pendingEvents는 UserDefaults에 영속. 다음 flush 시도까지 최대 7일 보관, 7일 초과 이벤트는 drop (개인정보 보관 기간 제한).

### 관리자 대시보드 (WP admin)

**메인 대시보드 (`dashboard.php`)**
```
┌─ 구독자 현황 ──────────────────────┐
│  전체     287명  ↑3 (이번주)        │
│  청년부    52명  ↑1                 │
│  새가족    18명  ↑0                 │
│                                     │
│  [간단한 스파크라인: 주간 추이]       │
└─────────────────────────────────────┘

┌─ 최근 캠페인 요약 ─────────────────┐
│ 2026-04-22  주일 설교 안내          │
│   발송 287  전달 271 (94%)          │
│   열람 145 (51%) 딥링크 89 (62%)   │
│                                     │
│ 2026-04-20  청년부 금요모임         │
│   발송 45   전달 43 (96%)           │
│   열람 28 (65%)  딥링크 19 (68%)   │
└─────────────────────────────────────┘
```

**캠페인 상세 (`campaign-stats.php`)**
- 전달률 = `delivered_count / subscribers_targeted`
- 열람률 = `opened_count / delivered_count`
- 딥링크 CTR = `deeplinked_count / opened_count`
- iOS 버전 분포, 플랫폼 분포 (v2에서 Android 대비)
- CSV 내보내기

### 시스템 관측

**iOS 측 (3rd party SDK 없이)**
- `os_log` 카테고리별 (push, network, deeplink) — Console.app에서 확인
- Apple MetricKit — 크래시/행 자동 수집, App Store Connect에서 확인 (Privacy Manifest 추가 선언 불필요)
- TestFlight 크래시 리포트

**WordPress 측**
- `debug.log` — FCM 에러, 스케줄러 실패
- `wp_bkc_campaigns.status = failed` 자동 알림 → 관리자 이메일

**도메인 레벨 (선택)**
- bkc.org 웹 트래픽은 기존 방식 유지 (Plausible/GA 교회가 이미 사용 중일 수 있음). 앱은 별도 추적 안 함.

### 개인정보 원칙

1. `device_id`는 앱이 로컬 생성한 UUID, **사용자 개인정보와 절대 연결 안 함** (ID/이메일/전화번호 없음)
2. 3rd party 분석 SDK 사용 금지 (Firebase Analytics, Mixpanel, Amplitude 전부 X)
3. 원시 이벤트 **6개월 보관** 후 자동 삭제 (cron), 집계 통계만 무기한 보관
4. 관리자 UI에 개별 device 단위 드릴다운 기능 없음 — 집계 숫자만 노출
5. Privacy Manifest 추가 선언:
   - `NSPrivacyCollectedDataTypes` → `Other Diagnostic Data` (앱 기능, 트래킹 아님)

## 성공 지표 (정량화)

모든 수치는 WP 관리자 대시보드에서 관찰 가능 (rollup cron 1시간 주기, **최대 1시간 지연**).

### 지표 정의 (오해 방지)

각 지표의 계산식과 한계를 명시한다. 특히 **하한치 지표**는 실제값이 더 높을 수 있음을 감안해서 해석.

| 지표 | 계산식 | 데이터 소스 | 유형 |
|------|--------|------------|------|
| 발송 성공률 | `COUNT(status='sent') / COUNT(status IN ('sent','failed'))` | `wp_bkc_campaigns` | 정확 |
| 전달률 | `delivered_count / subscribers_targeted` | `wp_bkc_campaign_stats` | **하한치** (앱 종료 상태 수신은 기록 X) |
| 열람률 | `opened_count / subscribers_targeted` | `wp_bkc_campaign_stats` | **분모를 delivered가 아닌 targeted로 함** — delivered 하한치 문제 회피 |
| 딥링크 CTR | `deeplinked_count / opened_count` | `wp_bkc_campaign_stats` | 정확 (조건부: 딥링크 있는 캠페인만) |
| 활성 구독자 | `COUNT(*) WHERE last_seen >= NOW() - INTERVAL 14 DAY` | `wp_bkc_subscriptions` | 정확 |
| 이탈률 | `COUNT(prune in week) / active_subscribers` (prune = FCM unregistered로 30일 내 삭제된 device) | `wp_bkc_subscriptions` 감사 로그 | 정확 |
| 전달 지연 p95 | `PERCENTILE(events.occurred_at - campaigns.sent_at, 0.95)` (opened 기반) | JOIN | 정확 |

### 출시 게이트

- App Store 승인
- iOS 16+ 설치 가능

### 도입률 (`/stats/subscribers`)

- 출시 후 4주 내 활성 구독자 100~300명 (14일 내 활동)
- 주간 신규 구독자 ≥ 10명 (베타 기간)
- 주간 이탈률 ≤ 5% (정의: FCM unregistered로 해당 주에 pruning된 device / 이전 주 활성 구독자)

### 푸쉬 신뢰성 (캠페인당, `/stats/campaign/{uuid}`)

- **발송 성공률 ≥ 99%** (FCM API 200 응답) — 실패 시 근본 원인 조사
- **열람률 ≥ 30%** (opened / subscribers_targeted) — 업계 모바일 푸쉬 open rate 평균이 20~30%인 점을 감안한 현실적 목표. 교회 engaged audience 가정.
  - ⚠ "delivered 기반 열람률(opened/delivered)"은 분모가 부정확해서 공식 KPI 아님
- **딥링크 CTR ≥ 50%** (deeplinked / opened) — 딥링크 포함 캠페인에 한정
- **전달 지연 p95 ≤ 5분** (opened 이벤트 기준, 앱 실행 상태 수신분)
- **주일 공지 4주 연속 정상 발송** — 정의: 발송 성공 + 열람률 20% 이상 달성한 주

### 하한치 지표 (참고용, 목표 없음)

다음 지표는 구조적 이유로 실제값보다 낮게 측정. 추세만 본다.
- `delivered_count / subscribers_targeted` — 이론상 100%여야 정상, 60~80% 구간이면 건강한 상태
- 이 지표가 하루아침에 30% 떨어지면 무언가 고장 신호 (APNs 키 만료 등)

### 비용

- 월 운영비 < $10 (Apple Developer $99/년 ÷ 12 ≈ $8.25, Firebase 무료 티어, FCM 무제한)
- 추가 인프라 비용 0 (이벤트/통계 DB는 bkc.org 기존 AWS MySQL 재활용)

### 카톡 대체

- 출시 후 8주 시점 카톡 채널 단독 의존 공지 0건
- 앱 열람률 ≥ 25% 유지되는 한 카톡 완전 중단 가능

### 코드 품질

- iOS + WP 신규 코드 행 커버리지 ≥ 85%
- 테스트 실패 시 CI 배포 블록

### 위험 신호 (대시보드 경보 임계값)

- 열람률 7일 평균 < 20% → 컨텐츠 품질 리뷰 + 배달 경로 점검
- 발송 성공률 7일 평균 < 95% → FCM/APNs 인프라 조사 (서비스 계정 키, 토픽 이름)
- 이벤트 전송 실패율 > 5% → 클라이언트 flush 로직 점검
- 활성 구독자 4주 연속 감소 → 구독 플로우 UX 검토, 카톡 전환 메시지 재조정
- `delivered / subscribers_targeted` 하한치가 7일 평균 < 40% → 구조 문제 가능성 (토큰 churn, FCM 오작동)

## 검증 (Verification)

### 자동화 (CI에서 매 PR)

1. iOS 유닛 테스트 (BKCTests 전체)
2. WP PHPUnit 테스트 (tests/ 전체)
3. `PrivacyInfo.xcprivacy` 존재 확인 + 검증 (lint script)
4. Swift Lint, PHP_CodeSniffer

### 수동 (Week 7-10)

1. **iOS 앱 기능 검증**
   - [ ] 신규 설치 → 알림 권한 허용 → Topic 구독 → WP `bkc_subscriptions` row 생성
   - [ ] 그룹 선택 변경 → FCM topic sub/unsub → WP 즉시 반영 → 로컬 업데이트
   - [ ] 각 탭 WebView가 bkc.org 해당 섹션을 정상 로드
   - [ ] 외부 링크(YouTube/Instagram/Tithe.ly)가 Safari로 핸드오프
   - [ ] 네트워크 오프라인 시 공지 탭 캐시 표시, WebView 탭 에러 + 재시도
   - [ ] Universal Links: SMS로 보낸 `bkc.org/sermon/X` 링크 → 앱에서 열림

2. **푸쉬 End-to-End**
   - [ ] WP 관리자에서 발송 → 10초 내 FCM → APNs → iPhone 수신
   - [ ] youth 그룹 발송 → youth 구독자만 수신, newfamily 구독자 미수신
   - [ ] youth + newfam 동시 발송 → 둘 다 구독한 유저는 **1번만** 수신 (중복 방지 검증)
   - [ ] 앱 포어/백/강제종료 각 상태에서 수신
   - [ ] 푸쉬 탭 → 지정 딥링크 URL의 WebView 탭 열림
   - [ ] 공지 탭에서 과거 수신 목록 확인 (오프라인 포함)
   - [ ] 앱 삭제 → 다음 발송에서 unregistered 감지 → subscriptions 정리
   - [ ] 푸쉬 수신 → `/events`로 `delivered` 이벤트 전송 → WP 대시보드에 카운트 증가
   - [ ] 알림 탭 → `opened` + `deeplinked` 이벤트 기록
   - [ ] 오프라인에서 푸쉬 3개 수신 → 앱 재접속 시 배치 flush 1회로 모두 전송

3. **WordPress 관리자 검증**
   - [ ] 발송 UI에서 제목/본문/딥링크/그룹 설정 → confirm 모달 → 발송
   - [ ] 큐 등록 즉시 반환 (200ms 이내), 백그라운드 처리
   - [ ] 캠페인 목록: queued → sending → sent 상태 전이 시각적 확인
   - [ ] 같은 캠페인 "재시도" → 재발송 X, 상태만 갱신
   - [ ] 새 캠페인 "복제" → 새 uuid로 별도 발송
   - [ ] **발송 직후 "취소" 클릭 (10초 내)** → job 취소 + status=cancelled, FCM 호출 안 됨
   - [ ] sending/sent 상태에서 취소 시도 → UI에서 버튼 disabled, API도 거부
   - [ ] 관리자 권한 없는 WP 사용자는 플러그인 메뉴 접근 불가
   - [ ] FCM 키 경로 잘못 설정 시 명확한 에러
   - [ ] 대시보드 구독자 수치가 실제 `wp_bkc_subscriptions` count와 일치
   - [ ] 캠페인 발송 후 1시간 내 rollup job이 stats 테이블 업데이트
   - [ ] 전달률/열람률이 예상 범위 내 (베타 기간 실데이터 검증)
   - [ ] CSV 내보내기 형식 확인, 한글 인코딩 깨지지 않음

4. **심사 대응**
   - [ ] TestFlight 내부 테스트로 최소 5명 2주 사용 확인
   - [ ] Privacy Manifest 선언 일치 (tracking 없음, 수집 데이터 매핑)
   - [ ] 앱스토어 리뷰어 노트: native 탭바 + 푸쉬 + Universal Links 구분 강조, 테스트 계정 제공
   - [ ] Guideline 4.2/5.1.1/5.1.2 대비

## 시작 전 확인 필요 (Open Items)

계획 승인 후 다음 항목을 최우선으로 확인/확보:

1. **Apple Developer Program 계정** — 교회 명의 / 개인 명의 결정, 기존 보유 여부. Organization 검증 1~2주 소요 가능.
2. **bkc.org WordPress 관리자 권한 + 기술팀 접근** — stage 사이트 확보 우선순위 최상.
3. **Firebase 프로젝트 소유권** — 어느 Google 계정으로 생성할지 (교회 공식 계정 권장). prod/stage 프로젝트 분리 예정.
4. **앱 아이콘/브랜드 에셋** — 교회 로고·색상·이모지 가이드.
5. **정확한 시작일** — 사용자 + 교회 기술팀 착수 가능 날짜.
6. **FCM 서비스 계정 JSON 보관 위치** — 웹 루트 외부 경로 확정, 백업 제외, 로테이션 주기.
7. **bkc.org `.well-known/` 경로 접근** — CDN/방화벽에서 해당 경로 허용 여부 확인.
8. **개인정보처리방침 페이지** — 법률 검토 필요 (수집 항목, 보관 기간).
9. **교회 Apple Team ID** — Universal Links AASA 파일 작성에 필요.
10. **정확한 그룹 목록 확정** — 초기 whitelist (`all`, `youth`, `newfamily` 외 추가?).

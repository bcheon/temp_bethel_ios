# BKC 교회 iOS + WordPress 플러그인

베델교회 어바인(bkc.org) 자체 푸쉬 노티피케이션을 위한 iOS 앱 + WP 플러그인. 카카오톡 채널 종속성을 제거하고 자체 인프라로 전환하는 것이 목표.

상태: **v1.0.0 구현 완료** — 78개 자동화 테스트(41 PHPUnit + 34 XCTest + 3 XCUITest) 통과. App Store 제출 직전.

전체 사양은 [`doc/ios-app-plan.md`](doc/ios-app-plan.md), 테스트 전략은 [`doc/testing.md`](doc/testing.md).

## 디렉터리 구조

```
.
├── ios/BKC/                         # SwiftUI 앱 (iOS 16+, Firebase Messaging 12.x)
│   ├── BKC/App/                     # BKCApp.swift (@main), AppDelegate.swift
│   ├── BKC/Views/                   # RootTabView, WebViewTab, WebViewStore,
│   │                                # NotificationListView, GroupOnboardingView, MoreView
│   ├── BKC/Services/                # BKCAPI(+Client), PushService, GroupStore,
│   │                                # CampaignCache, DeepLinkRouter, TelemetryService
│   ├── BKC/Models/                  # PushCampaign, SubscriptionGroup, DeepLink, TelemetryEvent
│   ├── BKC/Resources/               # Info.plist, BKC.entitlements, GoogleService-Info.plist (gitignore)
│   ├── BKCNotificationServiceExtension/   # NSE — terminated 상태 delivered 이벤트 기록
│   ├── BKCTests/                          # XCTest 유닛 (34개, 6개 파일 + Support/MockURLProtocol)
│   ├── BKCUITests/                        # XCUITest UI (3개, BKCAppLaunchTests)
│   └── project.yml                        # XcodeGen 설정 (커밋. .xcodeproj 는 gitignore)
├── wordpress-plugin/bkc-push/       # PHP 8.0+ WP 플러그인 (v1.0.0)
│   ├── bkc-push.php                       # 엔트리 + dbDelta 마이그레이션 (4개 테이블)
│   ├── includes/                          # 9개 클래스 (Groups/RateLimiter/Subscriptions/
│   │                                      #  Campaigns/Events/FCM_Client/Dispatcher/REST_API/Stats_Rollup)
│   ├── admin/{class-bkc-admin.php,views/} # 관리자 메뉴 + dashboard/compose/campaign-list/campaign-stats
│   ├── tests/                             # PHPUnit 8개 슈트 (41개) — Test_*.php (PascalCase)
│   ├── composer.json + composer.lock      # 둘 다 커밋 (재현 가능 빌드)
│   └── phpunit.xml.dist
├── well-known/apple-app-site-association  # Universal Links AASA (Team ID는 배포 시 교체)
├── .github/workflows/ci.yml         # GitHub Actions: actionlint + wp-test 매트릭스 + ios-test
├── fastlane/{Fastfile,Appfile}      # TestFlight 자동화 스켈레톤 (apple_id/team_id placeholder)
├── Makefile + bin/test.sh           # 로컬 테스트 진입점
└── doc/{ios-app-plan.md,testing.md}
```

## 자주 쓰는 명령

```bash
make install      # brew + composer로 모든 도구 설치 (php@8.5, composer, xcodegen, act)
make test         # 사용 가능한 모든 테스트 (WP + iOS Mac에선)
make test-wp      # WP PHPUnit (어디서나 동작, < 1초)
make test-ios     # iOS Xcode 시뮬레이터 (Mac 전용, ~30초)
make xcodeproj    # ios/BKC/project.yml → BKC.xcodeproj 재생성
make ci-local     # `act`로 GitHub Actions wp-test 잡을 로컬 Docker에서 실행
make clean        # vendor/, build/, .xcodeproj 제거
```

## 코딩 컨벤션

### Swift (iOS)
- iOS 16+ 최소, Swift 5.9, SwiftUI + UIKit (UIApplicationDelegateAdaptor)
- 싱글톤은 명시적 `static let shared` (PushService, GroupStore, CampaignCache, TelemetryService, DeepLinkRouter)
- 타입은 기본 `internal` — `public` 남발 금지
- 에러는 typed enum (`BKCAPIError`, `GroupStoreError`, `PushServiceError`)
- 테스트 가능성: `URLSession` 주입, `GroupSyncOps` 같은 ops struct 추출
- 강제 unwrap 금지(`!`) — `guard let ... else { fatalError("...") }` 사용
- 한국어 UI 라벨은 그대로 코드에 (Localizable.strings는 v1.1)

### PHP (WP 플러그인)
- WP 코딩 스타일: snake_case, `BKC_` 프리픽스, PSR-4 네임스페이스 안 씀
- 모든 `$wpdb` 호출은 `prepare()` + 플레이스홀더. 예외는 `phpcs:ignore` + 테이블명만 보간된 경우만.
- 모든 admin view 출력은 `esc_html` / `esc_attr` / `esc_url` / `wp_kses_post`
- 외부 데이터 입력은 `sanitize_text_field` 등으로 정규화

## 테스트 IRON RULE — 절대 회귀 금지

7개 항목은 100% 자동화 회귀 보호 대상. 한 줄이라도 깨지면 머지 차단:

| # | 위치 | 무엇을 보호 |
|---|---|---|
| 1 | `tests/Test_Idempotency.php` | 같은 UUID 더블 submit → 캠페인 1개만 생성, dispatcher 1번만 발사 |
| 2 | `tests/Test_FCM_Client.php::test_condition_dedup_iron_rule` | 멀티 그룹 condition 문자열에 모든 토픽이 `\|\|`로 결합됨 |
| 3 | `BKCTests/GroupStoreTests.swift::testSetGroups_withoutAll_throwsCannotUnsubscribeAll` | `all` 토픽 해제 시도 시 거부 |
| 4 | `BKCTests/DeepLinkRouterTests.swift::testExternalURL_isClassifiedAsExternal` | youtube.com 등은 WebView 안에서 열리지 않음 |
| 5 | `BKCTests/GroupStoreTests.swift::testGroupSync_rollsBackOnSecondSubscribeFailure` + `testGroupSync_rollsBackRemovesOnFailure` | 부분 실패 시 이전 FCM 작업이 롤백됨 |
| 6 | `BKCTests/TelemetryServiceTests.swift::testOfflineBuffer_survivesSimulatedRestart` | 텔레메트리 이벤트가 프로세스 재시작 후에도 살아남음 |
| 7 | `tests/Test_Stats_Rollup.php::test_idempotent_rerun_iron_rule` | rollup 두 번 실행해도 같은 숫자 (집계 멱등) |

상세 회귀 정책은 [`doc/testing.md`](doc/testing.md).

## REST API 요약 (`bkc/v1` namespace)

공개 (rate-limit 적용): `POST /subscribe`, `PATCH/DELETE /subscribe/{device_id}`, `GET /campaigns`, `POST /events`.
관리자 (`current_user_can('manage_options')`): `GET /stats/campaign/{uuid}`, `GET /stats/subscribers`, `POST /campaigns/{uuid}/cancel`.

## 보안 룰 (배포 전 필수 확인)

1. **FCM 서비스 계정 JSON은 절대 커밋 안 함.** 웹 루트 바깥(`/var/www/bkc-secrets/`)에 `chmod 600`. `wp-config.php` 상수 또는 `BKC_FCM_SVC_ACCT` 환경변수로 경로 주입.
2. **`GoogleService-Info.plist`도 커밋 안 함** (`.gitignore`). 현재 리포 안의 placeholder 값(`AIzaSyABCDEF...`)은 **CI/시뮬레이터 빌드 통과용**이지 프로덕션 키 아님.
3. **AASA `appID`** — 현재 `YOUR_TEAM_ID.org.bkc.churchapp` placeholder. App Store 제출 전 실제 Apple Team ID로 치환 필수. `ios/BKC/project.yml`의 `DEVELOPMENT_TEAM` + `fastlane/Appfile`도 동시 교체.
4. `permission_callback => current_user_can('manage_options')` 가드를 모든 `/stats/*`, `/campaigns/*/cancel` REST 라우트에 적용. 새 admin-only 라우트 추가 시 잊지 말 것.
5. 새 그룹을 추가하려면 PHP `BKC_Groups::WHITELIST` + Swift `SubscriptionGroup` enum 둘 다 동시 업데이트.

## 자주 빠지는 함정

- **PHPUnit 10 strict 파일명 매칭**: 테스트 파일명 basename은 PHP-valid 클래스명이어야 함. `test-foo.php`(하이픈 X) 안 됨, `Test_Foo.php` (PascalCase + 언더스코어 OK).
- **Composer classmap 자동 로드 충돌**: `tests/stubs/`는 반드시 `composer.json`의 `exclude-from-classmap`에 넣어야 stub 파일이 자가 자동 로드 재진입으로 redeclare 오류 안 냄.
- **xcodegen `bundleId:` 필드 무시 가능**: 명시적으로 `settings.base.PRODUCT_BUNDLE_IDENTIFIER` 쓰기. NSE bundle ID는 반드시 parent + `.` 접두사 (예: `org.bkc.churchapp.NotificationService`).
- **NSE Info.plist에 `CFBundleExecutable` 빠지면** 시뮬레이터가 install 거부함. 항상 `$(EXECUTABLE_NAME)` 명시.
- **iOS UI 테스트는 `BKC_UITEST=1`** 환경변수로 Firebase 초기화 우회 (`BKCApp.swift` `isUITest` 가드). 이 가드 없이는 placeholder plist에서 `FIRApp.configure()` 크래시. `project.yml` scheme.test.environmentVariables로 자동 주입됨.
- **NSE 폴더 안의 `*Tests.swift`는 빌드 제외** (`project.yml` NSE 타겟 `excludes: ["**/*Tests.swift"]`). NSE 로직 테스트는 별도 unit-test 번들 필요 — 현재는 메인 앱 통합 테스트로만 검증.

## CI 파이프라인 요약

`.github/workflows/ci.yml` (push to main + PR):

- **lint-workflows** — actionlint (ubuntu-latest)
- **wp-test (matrix: PHP 8.1 / 8.2 / 8.3, ubuntu-latest)** — composer install → phpunit. fail-fast: false.
- **ios-test (macos-15)** — Xcode latest-stable → xcodegen → SPM resolve → 동적 iPhone 시뮬레이터 UDID 픽 → `xcodebuild test`. `Test.xcresult` 항상 아티팩트 업로드 (실패 디버깅용, 14일 보관).
- 동시성: 같은 ref에서 진행 중인 잡 cancel-in-progress.

로컬에서 GitHub Actions를 흉내내려면 `make ci-local` (Docker + `act` 필요). Apple Silicon에서는 `shivammathur/setup-php`가 컨테이너 안에서 실패할 수 있음 — 실제 GitHub runner에선 정상.

## 외부 의존 (배포 전 확보 필요)

- Apple Developer Program 계정 + Team ID
- Firebase 프로젝트 (prod/stage 분리 권장) + iOS 앱 등록 + APNs 인증 키
- bkc.org WordPress 관리자 권한 + stage 사이트
- bkc.org `/.well-known/` 경로 CDN/WAF 화이트리스트 (Apple AASA bot)
- 개인정보처리방침 페이지 (수집 항목 · 보관 기간 · 텔레메트리 설명)

## 작업 시 참고

- 새 PHPUnit 테스트 추가 시: `tests/Test_<Name>.php` (PascalCase basename) + `class Test_<Name> extends TestCase`. `phpunit.xml.dist` 자동 발견. `setUp()`에서 `bkc_reset_stubs()` 호출 필수.
- 새 Swift 테스트 파일 추가: `BKCTests/<Name>Tests.swift` 만들면 `make xcodeproj` 한 번 실행 → 자동 포함.
- 새 IRON RULE 추가: 위 표 + `doc/ios-app-plan.md` §회귀 방지 + `doc/testing.md` 셋 다 업데이트.
- spec 변경: `doc/ios-app-plan.md` 먼저 수정 → 합의 후 코드 반영.
- 싱글톤 (`CampaignCache.shared` 등) 테스트 시 명시적 `clear()` / reset — 이전 테스트 잔여 상태가 다음 어서션 깨뜨림.

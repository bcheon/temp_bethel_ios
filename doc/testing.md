# 테스트 가이드

BKC iOS 앱 + WP 플러그인의 자동/수동 테스트 전략 + 실행 방법.

## 한 줄 요약

```bash
make test    # WP PHPUnit + iOS XCTest + XCUITest 전부 실행
```

성공하면 출력 마지막에 `** TEST SUCCEEDED **` (iOS) + `OK (Tests: 41, Assertions: 97)` (WP). 둘 중 하나라도 실패하면 `make test` 자체가 0이 아닌 종료 코드.

## 테스트 매트릭스

| 레이어 | 도구 | 위치 | 테스트 수 | 실행 시간 (Mac M4) |
|---|---|---|---|---|
| WP PHP 유닛 | PHPUnit 10 | `wordpress-plugin/bkc-push/tests/Test_*.php` | 41 | < 1초 |
| iOS Swift 유닛 | XCTest | `ios/BKC/BKCTests/*.swift` | 34 | ~1초 |
| iOS UI | XCUITest (iPhone 17 시뮬레이터) | `ios/BKC/BKCUITests/BKCAppLaunchTests.swift` | 3 | ~15초 |
| 워크플로 lint | actionlint | `.github/workflows/*.yml` | — | < 1초 |
| 로컬 GH Actions sim | act + Docker | — | wp-test 잡 | ~1분 (이미지 캐시 후) |

총 78개의 자동화된 어서션이 머지 게이트로 작동.

## 로컬 실행

### 사전 요구사항

```bash
make install
```

위 명령이 brew로 설치하는 것: `php@8.5`, `composer`, `xcodegen`, `act`. iOS 테스트는 Xcode 15+가 필요 (brew로 설치 안 됨, App Store에서).

### 단위별 실행

```bash
make test-wp     # WP만 (~1초)
make test-ios    # iOS만 (~30초, 시뮬레이터 부팅 포함)
```

특정 PHP 테스트 한 개만:
```bash
cd wordpress-plugin/bkc-push
vendor/bin/phpunit --filter test_condition_dedup_iron_rule
```

특정 Swift 테스트 한 개만:
```bash
cd ios/BKC
xcodebuild test -project BKC.xcodeproj -scheme BKC \
  -destination "platform=iOS Simulator,name=iPhone 17,OS=latest" \
  -only-testing:BKCTests/GroupStoreTests/testSetGroups_withoutAll_throwsCannotUnsubscribeAll \
  CODE_SIGNING_ALLOWED=NO
```

### CI를 로컬에서 시뮬레이트

```bash
make ci-local
```

`act` + Docker로 `.github/workflows/ci.yml`의 `wp-test` 잡을 PHP 8.2 매트릭스로 실행. **알려진 한계**: Apple Silicon에서 `shivammathur/setup-php` action이 amd64 에뮬레이션 안에서 실패할 수 있음 — 실제 GitHub Actions ubuntu-latest runner에선 정상 동작.

## CI 파이프라인 (`.github/workflows/ci.yml`)

푸시/PR 시 자동 실행. 3개 잡이 병렬:

### 1. `lint-workflows`
- actionlint로 모든 yaml 워크플로 검증
- 1초 안에 실패 / 통과

### 2. `wp-test` (PHP 8.1, 8.2, 8.3 매트릭스)
- `actions/checkout@v4`
- `shivammathur/setup-php@v2` (mbstring, json, openssl)
- composer 캐시 (`composer.lock` 해시 기반)
- `composer install --prefer-dist --no-progress --no-interaction`
- `vendor/bin/phpunit`
- 매트릭스 셀 하나라도 실패 → 잡 실패 (fail-fast: false라 다른 셀은 계속)

### 3. `ios-test` (macos-14)
- Xcode 15.4 선택
- `brew install xcodegen`
- `xcodegen generate`
- `xcodebuild -resolvePackageDependencies` (Firebase SPM)
- `xcodebuild test` (iPhone 17 시뮬레이터, `CODE_SIGNING_ALLOWED=NO`)
- 항상 `Test.xcresult` 아티팩트 업로드 (실패 디버깅용)

## WP 테스트 상세

### 스택
- PHPUnit 10.5.x (PHP 8.1+ 필수 — PHPUnit 10 최소)
- 오프라인 stub: `tests/stubs/wp-stubs.php` — 실제 WordPress 없이 plugin 클래스 단독 실행
- 픽스처: `tests/fixtures/fcm-service-account.json` — 가짜 RSA 키 (`PLACEHOLDER_TEST_KEY_NOT_FOR_PRODUCTION_USE` 라벨)

### 테스트 슈트별 책임

| 파일 | 무엇을 테스트 |
|---|---|
| `Test_FCM_Client.php` (9) | FCM HTTP v1 페이로드 형식, condition 문자열 조립, mutable-content 항상 포함, 401 처리, UTF-8/이모지 |
| `Test_Dispatcher.php` (5) | queued→sending→sent 상태 전이, 실패 시 error 기록, queued 캠페인 취소 |
| `Test_REST_API.php` (7) | 토큰 정규식, 그룹 화이트리스트, 레이트 리밋, 배치 크기, admin 권한 |
| `Test_Subscriptions.php` (5) | upsert (INSERT vs UPDATE), prune_stale, count_targeted 14일 윈도우 |
| `Test_Events_Endpoint.php` (5) | 배치 dedup, 알 수 없는 campaign_uuid 조용히 drop, 100개 cap |
| `Test_Stats_Rollup.php` (3) | 이벤트 → stats 정확 집계, **idempotent rerun (IRON RULE)**, 6개월 prune |
| `Test_Stats_Permissions.php` (6) | 비admin 403, admin 200, error_code = `rest_forbidden` |
| `Test_Idempotency.php` (1) | **이중 submit 같은 UUID → 1번만 dispatch (IRON RULE)** |

### 테스트 추가 시 주의

- **파일명**: `Test_<PascalCaseName>.php` (PHPUnit 10 strict 매칭). 하이픈 사용 금지.
- **클래스명**: 파일명 basename과 정확히 일치 (`Test_<PascalCaseName>`).
- **stubs 폴더 건드리지 말 것**: `tests/stubs/`는 `composer.json`의 `exclude-from-classmap`에 들어 있음. 새 stub 추가 시 거기에 넣을 것.
- **`bkc_reset_stubs()` 호출**: 모든 `setUp()`에서 호출해서 `$GLOBALS` 상태 리셋.

## iOS 테스트 상세

### 스택
- XCTest (유닛) + XCUITest (UI 스모크)
- iPhone 17 시뮬레이터 + iOS 26.4
- Firebase Messaging 12.x SPM 의존성

### XCTest 슈트 (BKCTests, 34개)

| 파일 | 핵심 케이스 | IRON RULE 보호 |
|---|---|---|
| `BKCAPIClientTests` | 200/401/500, 5xx 재시도, UTF-8 round-trip | — |
| `CampaignCacheTests` | 50-cap FIFO, dedup by UUID, 손상된 JSON 복구, 읽음 상태 보존 | — |
| `DeepLinkRouterTests` | bkc.org/news → 공지 탭, sermon → 설교 탭, **외부 URL → external (IRON RULE)** | #4 |
| `GroupStoreTests` | onboarding `all` 강제 추가, **`cannotUnsubscribeAll` 던짐 (IRON RULE)**, **subscribe + unsubscribe 양 방향 롤백 (IRON RULE)** | #3, #5 |
| `PushServiceTests` | 디바이스 ID Keychain 영속, UUID 형식, topic 매핑, isMandatory | — |
| `TelemetryServiceTests` | append → persist, 멀티 이벤트, **오프라인 버퍼 재시작 후 생존 (IRON RULE)**, 7일 cutoff | #6 |

### XCUITest 슈트 (BKCUITests, 3개)

```swift
testAppLaunches_withoutCrash         // 앱이 foreground에 도달
testRootTabView_showsAllFourTabs     // 홈/공지/설교/더보기 4개 탭 visible
testTabSwitching_changesSelection    // 탭 탭 시 선택 상태 변경
```

UI 테스트는 `app.launchEnvironment["BKC_UITEST"] = "1"`을 주입해서 Firebase 초기화를 우회. 이 가드 없으면 placeholder `GoogleService-Info.plist`로 인해 `FIRApp.configure()` 크래시.

### 테스트 추가 시 주의

- 새 Swift 테스트 파일 추가 → `make xcodeproj` 한 번 실행 → 자동 발견
- 싱글톤 (`CampaignCache.shared`, `TelemetryService.shared`) 테스트 시 명시적 `clear()` / `reset` 호출 필수. 이전 테스트가 남긴 상태가 다음 테스트 어서션을 깨뜨림 (실제로 발견된 버그)
- 강제 unwrap (`!`) 쓰지 말 것 — 테스트 죽어도 디버깅 어려움
- 비동기 테스트는 `async` 함수 + `try await` 우선. `XCTestExpectation`은 callback API에만 사용

## 회귀 방지 (IRON RULE) 정책

위 7개 테스트는 **머지 차단**. CI에서 실패 시 머지 button disabled (GitHub branch protection rule). 새 IRON RULE 추가 절차:

1. `doc/ios-app-plan.md` §회귀 방지 표에 추가
2. `CLAUDE.md` IRON RULE 표에 추가
3. 이 문서의 매트릭스에 추가
4. 테스트 파일에 `// IRON RULE:` 주석 + 의미 있는 어서션
5. PR 설명에 IRON RULE 추가 사실 명시

## E2E (수동, 자동화 안 됨)

iOS는 시뮬레이터 한계로 다음은 **반드시 실기기에서 수동 검증**:

- 실제 APNs 푸쉬 전달 (포어/백/강제종료 각 상태)
- Universal Links (SMS로 보낸 `bkc.org/sermon/X` 클릭 → 앱 열림)
- 한글/이모지 푸쉬 가독성
- iOS 16/17/18 각 버전 전달
- WebView 안 외부 링크(YouTube/Instagram) Safari 핸드오프 (XCUITest가 외부 앱 launch 검증 못함)

체크리스트는 `doc/ios-app-plan.md` §검증 → 수동 (Week 7-10) 참고.

## 성능 기대치

- WP PHPUnit 41개: < 1초 (offline stubs)
- iOS 유닛 34개: ~1초 + 시뮬레이터 부팅 ~30초 (첫 실행)
- iOS UI 3개: ~15초 (앱 launch 3번)
- act + Docker (이미지 사전 pull 후): ~1분
- 전체 GitHub Actions CI run (병렬): ~5분 (iOS macos-14 잡이 가장 김)

## 알려진 한계

- **`act` + `shivammathur/setup-php` Apple Silicon 호환성**: amd64 에뮬레이션 안에서 PHP install 실패 가능. 실제 ubuntu-latest runner에선 정상.
- **PHPUnit 4건 deprecation 경고**: PHPUnit 10.5.63 자체 내부의 `ReflectionProperty::setAccessible()` 사용 (PHP 8.5에서 deprecated). 우리 코드 아님. PHPUnit 11+ 업그레이드 시 사라질 것.
- **Firebase 실제 통합 테스트 없음**: placeholder `GoogleService-Info.plist`로는 `Messaging.subscribe(toTopic:)` 같은 라이브 호출 검증 안 됨. 단위 테스트는 `GroupSyncOps` 주입으로 우회. 실 발송 검증은 stage Firebase 프로젝트 + 실기기에서 수동.
- **Code coverage 미수집**: PHPUnit/xcodebuild 둘 다 coverage flag 없음. 측정해도 IRON RULE이 더 강력한 보장이라 우선순위 낮음. 필요 시 PHPUnit `--coverage-text`, xcodebuild `enableCodeCoverage`로 켤 수 있음.

## 디버깅 팁

테스트가 깨졌을 때:

1. **PHP redeclare 오류**: `tests/stubs/`가 classmap에서 빠졌는지 확인 → `composer dump-autoload` 재실행
2. **iOS 시뮬레이터 install 거부**: NSE bundle ID 접두사 확인 (parent + `.`), Info.plist에 `CFBundleExecutable` 있는지 확인
3. **iOS 앱 launch 크래시**: `xcrun simctl launch --console-pty <UDID> org.bkc.churchapp` 로 콘솔 로그 직접 확인 → Firebase init 에러는 `GoogleService-Info.plist` 형식 문제
4. **UI 테스트 element not found**: `app.debugDescription` 출력해서 실제 SwiftUI 렌더 계층 확인
5. **CI 실패하지만 로컬은 OK**: `make ci-local`로 act 돌려보기, 환경 변수 차이 의심

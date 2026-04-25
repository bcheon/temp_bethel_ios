# BKC 교회 iOS 앱

네이티브 iOS 앱 + WordPress 플러그인으로 구성된 BKC(베델교회 어바인) 푸쉬 공지 인프라.

상태: **v1.0.0 구현 완료**(스캐폴드 + 41개 PHPUnit + 34개 XCTest + 3개 XCUITest + NSE). App Store 제출 직전 단계.

## 문서

- [`doc/ios-app-plan.md`](doc/ios-app-plan.md) — 전체 사양 + 아키텍처 + 보안 + 12주 로드맵
- [`doc/testing.md`](doc/testing.md) — 자동/수동 테스트 가이드, IRON RULE, CI 파이프라인
- [`CLAUDE.md`](CLAUDE.md) — Claude/AI 협업 시 빠른 컨벤션 요약

## 구성 요소

| 경로 | 설명 |
|------|------|
| `ios/BKC/` | iOS 앱 (SwiftUI, iOS 16+, Firebase Messaging 12.x) |
| `ios/BKC/BKCNotificationServiceExtension/` | NSE — terminated 상태 `delivered` 이벤트 기록 |
| `wordpress-plugin/bkc-push/` | WP 플러그인 (발송 UI, REST API, Action Scheduler, 통계 롤업) |
| `well-known/` | Apple App Site Association(AASA) 배포 아티팩트 |
| `.github/workflows/ci.yml` | GitHub Actions: actionlint + WP PHPUnit 매트릭스 + iOS xcodebuild |
| `fastlane/` | Fastlane 스켈레톤 (TestFlight 자동 업로드 lane) |
| `Makefile` + `bin/test.sh` | 로컬 테스트 진입점 |

## 빠른 시작

### 도구 설치 (한 번)

```bash
make install   # brew + composer로 php@8.5, composer, xcodegen, act 설치
```

Xcode 자체는 brew로 안 깔리므로 App Store에서 별도 설치 필요(Xcode 16+ 권장 — Firebase 12.x SPM resolve용).

### 테스트

```bash
make test         # WP + iOS 전체 (Mac)
make test-wp      # WP PHPUnit만 (어디서나, < 1초)
make test-ios     # iOS XCTest + XCUITest (Mac만, ~30초)
make ci-local     # `act`로 GitHub Actions wp-test 잡을 로컬 Docker에서 실행
```

### iOS 앱 빌드

```bash
make xcodeproj                     # ios/BKC/project.yml → BKC.xcodeproj
open ios/BKC/BKC.xcodeproj
```

> `BKC.xcodeproj`는 `.gitignore` 대상입니다(소스는 `project.yml`). `GoogleService-Info.plist` 및 FCM 서비스 계정 JSON도 git에 포함되지 않습니다 — 보안 스펙([`doc/ios-app-plan.md` → "FCM 서비스 계정 키 관리"](doc/ios-app-plan.md))대로 별도 경로에 배치하세요. 리포에 들어 있는 placeholder plist는 CI/시뮬레이터 빌드 통과용입니다.

### WordPress 플러그인 (수동)

```bash
cd wordpress-plugin/bkc-push
composer install
vendor/bin/phpunit
```

### Universal Links (AASA)

`well-known/apple-app-site-association` 파일을 `https://bkc.org/.well-known/apple-app-site-association`에 배포합니다. 배포 요구사항·CDN 검증 절차는 [`well-known/README.md`](well-known/README.md) 참조. **배포 전 `YOUR_TEAM_ID` 치환 필수**.

### CI

GitHub Actions(`main` push + PR에서 자동 실행):

- **lint-workflows** — actionlint
- **wp-test** — PHP 8.1 / 8.2 / 8.3 매트릭스 (ubuntu-latest) → composer install → phpunit
- **ios-test** — macos-15 + Xcode latest-stable + xcodegen → 동적으로 사용 가능한 iPhone 시뮬레이터 UDID 선택 → `xcodebuild test`. `Test.xcresult` 아티팩트 항상 업로드 (실패 디버깅용).

같은 ref에서 진행 중인 잡은 자동 cancel-in-progress.

### Fastlane (스켈레톤)

```bash
bundle exec fastlane setup    # XcodeGen으로 .xcodeproj 생성
bundle exec fastlane test     # scan으로 유닛 테스트
bundle exec fastlane beta     # gym + pilot으로 TestFlight 업로드 (match 셋업 필요)
```

`fastlane/Appfile`의 `apple_id` / `team_id`는 placeholder. 실제 배포 시 교회 Apple Developer 계정 정보로 교체.

## 요구 사항

- **iOS:** Xcode 16+ 권장, iOS 16+ 타겟, Swift 5.9
- **WP 플러그인:** PHP 8.0+, Composer 2.x (CI는 PHP 8.1/8.2/8.3 매트릭스 검증)
- **로컬 도구:** XcodeGen (`brew install xcodegen`), `act` (선택, CI 로컬 시뮬레이션)
- **외부:** Apple Developer Program ($99/년), Firebase 프로젝트 + APNs 인증 키, bkc.org WP 관리자 권한

## 보안 체크리스트 (배포 전)

1. `well-known/apple-app-site-association`의 `YOUR_TEAM_ID` → 실제 Apple Team ID
2. `ios/BKC/project.yml`의 `DEVELOPMENT_TEAM: YOUR_TEAM_ID` → 실제 Team ID
3. `fastlane/Appfile`의 `apple_id` / `team_id` → 실제 값
4. FCM 서비스 계정 JSON을 웹 루트 바깥(`/var/www/bkc-secrets/`, `chmod 600`)에 배치 + `wp-config.php` 상수 또는 `BKC_FCM_SVC_ACCT` 환경변수 주입
5. 실제 `GoogleService-Info.plist`를 `ios/BKC/BKC/Resources/`에 배치 (placeholder 덮어쓰기)
6. bkc.org `/.well-known/` 경로가 Cloudflare/CDN/WAF에서 Apple AASA-bot에 허용되는지 확인

상세 보안 정책은 [`CLAUDE.md`](CLAUDE.md) "보안 룰" 섹션 + [`doc/ios-app-plan.md`](doc/ios-app-plan.md) "보안 아키텍처" 섹션 참조.

## 라이선스

[LICENSE](LICENSE) 참조.

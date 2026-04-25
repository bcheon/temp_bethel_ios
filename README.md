# BKC 교회 iOS 앱

네이티브 iOS 앱 + WordPress 플러그인으로 구성된 BKC(베델교회 어바인) 푸쉬 공지 인프라.

## 문서

- [`doc/ios-app-plan.md`](doc/ios-app-plan.md) — 전체 구현 계획 (아키텍처, 보안, 타임라인)

## 구성 요소

| 경로 | 설명 |
|------|------|
| `ios/BKC/` | iOS 앱 (SwiftUI + WebView + FCM 푸쉬) |
| `wordpress-plugin/bkc-push/` | WP 플러그인 (발송 UI, REST API, Action Scheduler) |
| `well-known/` | Apple App Site Association — AASA 배포 아티팩트 |
| `.github/workflows/ci.yml` | GitHub Actions CI (iOS 유닛 테스트 + WP PHPUnit) |
| `fastlane/` | Fastlane 자동화 (TestFlight 업로드) |

## 빠른 시작

### iOS 앱

```bash
# XcodeGen으로 .xcodeproj 생성 (git에는 커밋하지 않음)
cd ios/BKC
xcodegen generate
open BKC.xcodeproj
```

> **GoogleService-Info.plist** 및 FCM 서비스 계정 JSON은 보안상 git에 포함되지 않습니다.
> 보안 스펙(`doc/ios-app-plan.md` → "FCM 서비스 계정 키 관리") 에 따라 별도 경로에 직접 배치해야 합니다.

### WordPress 플러그인

```bash
cd wordpress-plugin/bkc-push
composer install
vendor/bin/phpunit
```

### Universal Links (AASA)

`well-known/apple-app-site-association` 파일을 `https://bkc.org/.well-known/apple-app-site-association`
에 배포합니다. 배포 요구사항은 [`well-known/README.md`](well-known/README.md)를 참조하세요.

### CI

GitHub Actions는 `main` 브랜치 push 및 PR에서 자동 실행됩니다.

- `ios-test` job: XcodeGen → xcodebuild test (iPhone 15 Simulator)
- `wp-test` job: PHP 8.0/8.1/8.2 매트릭스 → PHPUnit

### Fastlane

```bash
# Xcode 프로젝트 생성
bundle exec fastlane setup

# 유닛 테스트 실행
bundle exec fastlane test

# TestFlight 업로드 (배포 시)
bundle exec fastlane beta
```

## 요구 사항

- Xcode 15+, iOS 16+ 타겟
- Swift 5.9+
- XcodeGen (`brew install xcodegen`)
- PHP 8.0+, Composer (WP 플러그인)
- Apple Developer Program 등록 (App Store 배포 시)

## 라이선스

[LICENSE](LICENSE) 참조.

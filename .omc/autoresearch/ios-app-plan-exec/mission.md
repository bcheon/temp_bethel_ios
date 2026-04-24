# Mission: BKC iOS 앱 execution 플랜 기술적 검증

## Goal
`doc/ios-app-plan.md`의 execution 플랜에 담긴 **기술적 주장과 실행 가정**이 2026-04 시점 Apple/Firebase/WordPress 에코시스템에서 여전히 유효한지 검증하고, 빈틈·잘못된 가정·누락된 단계를 찾아 권고한다.

## Scope
v1 MVP 로드맵(Week 1 ~ Week 12)만 대상. v1.1+ 항목(자동화, 예약 발송, Silent push 기반 delivered 보정)은 비범위.

## Validation Targets (핵심 주장)
1. **FCM Topic Condition OR 합집합**이 복수 그룹 구독자에게 중복 발송을 방지하는 공식 메커니즘인가? (플랜 L14, L286, L420, L543)
2. **iOS `delivered` 이벤트의 구조적 한계** — 앱 강제 종료 상태 수신 미기록 주장이 현재도 사실인가? Silent push throttle 수치(하루 3~5)는 현재 Apple 가이드와 일치하는가? (플랜 L574~586)
3. **Firebase Messaging iOS SDK 10.25+ Privacy Manifest 내장** 주장이 현재 최신 SDK 대비 유효한가? 앱 레벨 추가 선언(`CA92.1`, `C617.1` reason code)이 여전히 유효한가? (플랜 L79~87)
4. **WKWebView 공유 `WKProcessPool` + `WKWebsiteDataStore.default()`** 전략이 iOS 16~18에서 탭 간 쿠키/세션 공유를 실제로 보장하는가? WKProcessPool deprecation 여부. (플랜 L209, L392~394)
5. **Universal Links AASA 파일 CDN/Cloudflare 경로 허용** — Apple이 요구하는 Content-Type, 서명 요구사항, 2026년 현재 주의사항. (플랜 L62~77, L810)
6. **App Store Guideline 4.2 리젝 회피** — 네이티브 셸 + WebView 하이브리드 앱이 "native tab bar + push + deeplink" 조합으로 심사 통과하는 현재 기준. (플랜 L13, L472~474, L797)
7. **WP Action Scheduler** 비동기 dispatch가 PHP 30초 제약과 WP Cron 트리거 한계를 실제로 해결하는 메커니즘인가? (플랜 L217)
8. **iOS 16 최소 버전**이 2026-04 시점 교인 연령층 + App Store 채택률 대비 적절한가? (플랜 L24, L207)
9. **10~12주 타임라인**이 제시된 기능 범위(네이티브 앱 + WP 플러그인 + 관리자 대시보드 + 텔레메트리 + 심사) 대비 현실적인가?
10. **보안 가정**: FCM 서비스 계정 키 파일 권한 `600`, 웹 루트 바깥 배치, wp-config 상수 패턴이 업계 표준과 일치하는가? (플랜 L40~52)

## Evaluator Contract
각 검증 영역에 대해 JSON 레코드:
```json
{
  "target_id": 1,
  "claim": "...",
  "verdict": "confirmed|partial|outdated|incorrect|unverified",
  "evidence": ["source1", "source2"],
  "risk_if_wrong": "low|medium|high",
  "recommendation": "...",
  "pass": true|false
}
```
- `pass=true` 기준: `verdict` in {"confirmed", "partial"} 이고 `risk_if_wrong`에 대응 권고가 있을 때.
- 전체 실행 `pass`: 10개 중 8개 이상 pass + high-risk 항목 모두 명시적 verdict.

## Stop Conditions
- 단일 run (run-0001) 완료. v1 autoresearch 확장 루프 없음.
- 이 세션에서 병렬 리서치 1회 수행 후 decision log 종결.

# Run 0001 — Decision Log

**Mission:** BKC iOS 앱 execution 플랜 기술적 검증
**Date:** 2026-04-24 PDT
**Source:** `doc/ios-app-plan.md` (baseline commit `c323739`)

## 실행 요약

- 10개 검증 대상 중 **7개 pass / 3개 fail** → 미션 임계(8/10) 미달로 run 전체 `pass=false`
- high-risk fail 3건(target 2, 3, 9) 전부 구체 권고 포함
- 플랜의 **전체 구조는 건전**. 기술 선택과 아키텍처 수준의 결함은 없고, **SDK 버전·reason code·iOS delivered NSE 대안·타임라인 완충** 등 실행 세부 항목 수정 필요.

## 주요 Findings (P0 수정 필요)

### P0-1. iOS `delivered` 이벤트 — NSE 대안 누락 (target 2, risk=high)
플랜은 앱 강제 종료 상태에서 `delivered`를 기록할 수 없다고 단정하고 이를 "구조적 한계"로 수용했다. 그러나 **`UNNotificationServiceExtension` + `mutable-content: 1`** 조합은 알림이 보이는 상태라면 메인 앱이 terminated여도 독립 프로세스로 실행되어 서버에 delivery를 기록할 수 있다.

**Apple 공식(forums/thread/744901, UNNotificationServiceExtension docs) 교차 확인.** Silent push와 별도 경로.

**권고:**
- v1에서 `UNNotificationServiceExtension` 추가 (iOS 타겟에 새 Extension target, 수명 30초 내 `/events`에 `delivered` POST).
- 전달률이 기존 플랜의 "하한치"에서 "거의 정확한 값"으로 상향 가능 → 핵심 KPI 정의 단순화.
- 플랜 L574-586 섹션 및 표 L565-570 개정.

### P0-2. "Silent push throttle 3~5/일" 수치 (target 2, risk=medium)
Apple 공식 문서(TN2265)는 **"a few notifications per hour"** 수준의 권고만 있고 정확한 숫자는 **미공개/동적**. "3~5/일"은 커뮤니티 관찰치.

**권고:** 플랜 L583 수치 삭제 → `"실제 한도는 Apple이 공개하지 않으며 동적. 'a few per hour' 수준 권고. 하루 1회 에너지 예산 리셋"`으로 교체.

### P0-3. Firebase iOS SDK 버전 + Privacy Manifest (target 3, risk=high)
2026-04 기준 최신은 **12.12.1** (플랜의 10.25는 약 2 major 낙후). Firebase SDK Privacy Manifest가 10.22+ 이후 추가됐으나 Messaging/Installations는 초기 누락 이력 있음 (issue #12768, #12741). 12.x에서도 ITMS-91061 간헐 보고 존재.

또한 플랜이 지정한 **`CA92.1`**은 "App Group 공유 데이터" 용도이며 "앱 자체 설정 저장"에는 `1C8F.1`이 더 정확.

**권고:**
- 플랜 L210의 `Firebase Messaging iOS SDK 10.25+`를 `12.x (2026-04 시점 최신 확인)`로 갱신.
- Week 1 체크리스트에 **Xcode Privacy Report 검증** 단계 추가.
- `PrivacyInfo.xcprivacy`의 UserDefaults reason code를 `CA92.1` → `1C8F.1`로 재검토 (UserDefaults 사용 목적 명확화).

### P0-4. 타임라인 현실성 (target 9, risk=high)
- **Week 10 심사 1주**: Apple 심사 24-48h + 리젝 재제출 사이클 고려 시 1주 부족. → **Week 10을 "제출", Week 11-12를 "심사 대응 + 출시"로 분리**.
- **"신규 코드 100% 행 커버리지"** (L483) 목표는 자원봉사 팀 구조와 충돌. → Iron Rule 7개 항목(L525-532)만 100%, 나머지 **80% 목표**로 완화.
- 경험 iOS 풀타임 개발자 부재 시 **14-16주** 권장.

## 권장 수정 (P1, 위험도 낮음)

### P1-1. WKProcessPool 제거 (target 4, risk=low)
iOS 15+부터 `WKProcessPool`은 사실상 no-op. `WKWebsiteDataStore.default()` 공유만으로 쿠키/세션 공유 목적 달성.

**권고:** 플랜 L209, L393에서 `WKProcessPool` 언급 삭제, `WebViewStore`에서 `processPool` 필드 제거.

### P1-2. AASA CDN/Cloudflare 검증 절차 (target 5, risk=high)
Apple은 리다이렉트 미허용, Content-Type=`application/json` 필수, 서명 불필요. iOS 14+는 Apple CDN이 주기 수집 → Cloudflare Bot Fight Mode / WAF 차단 시 Universal Links 먹통.

**권고:** Week 1 체크리스트에 다음 명령 추가:
```bash
curl -v https://bkc.org/.well-known/apple-app-site-association
curl -v https://app-site-association.cdn-apple.com/a/v1/bkc.org
```
Cloudflare Bot Fight Mode / Rate Limit 경로별 완화 확인.

### P1-3. FCM Condition 표현 완화 (target 1, risk=medium)
Firebase 공식이 "중복 제거 보장"을 명문화하지 않음. 단일 condition 호출이므로 사실상 1회 전달. 플랜 L14, L543의 표현 완화. **5-topic 상한선** 명시.

### P1-4. 보안 강화 (target 10, risk=medium)
파일 기반 secret 저장은 업계 표준이나 AWS 인프라라면:
- AWS Secrets Manager / SSM Parameter Store(SecureString) 이전 고려
- 환경변수 fallback 패턴 (`getenv('BKC_FCM_SVC_ACCT') ?: '/var/www/...'`)
- 키 로테이션 주기(연 1회 이상) 문서화

### P1-5. iOS 16 선택 재검토 (target 8, risk=medium)
iOS 16+ 94.4% 커버. iPhone 8 지원 위해 iOS 16 유지 합리적. NavigationStack 초기 이슈 모니터링 필요. iOS 17 상향 시 ~5.9% 손실 → 교회 결정 사항.

## Pass 항목 (확인 완료)

- **target 7 (Action Scheduler)**: WP Cron 대비 loopback spawn + 30초 제약 우회 메커니즘 공식 확인.
- **target 6 (4.2 리젝 회피)**: 네이티브 탭바 + 푸쉬 + Universal Links 조합은 2025-2026년 통과 패턴. 대응 노트 방향 정확.
- **target 10 (secret 관리)**: 패턴 자체는 OWASP/Google 권장 수준.

## Next Steps (플랜 반영 제안)

1. `doc/ios-app-plan.md`에 **개정 PR** 생성:
   - Firebase SDK 10.25 → 12.x
   - WKProcessPool 관련 문단 제거
   - Silent push throttle 수치 삭제
   - `UNNotificationServiceExtension` 기반 delivered 추적 섹션 추가
   - Week 10 → Week 10(제출) + Week 11-12(심사+출시) 재구성
   - 커버리지 목표 L483 수정
   - AASA Cloudflare 검증 체크리스트 Week 1 추가
2. `/stats/campaign/{uuid}`의 `delivered_count` 분모 재정의(NSE 적용 시 "하한치"→"정확값"으로 승격 가능)
3. 후속 iteration은 **실제 stage 환경 셋업 후** 각 위험 항목 실측 (Universal Links CDN 경유, Privacy Report 등) → 별도 `run-0002` 또는 `deep-interview --autoresearch`로 mission 재설정.

## 종결

- 이 run은 **문서 기반 검증**까지. 실행 환경 검증은 별도 run 필요.
- `run_pass=false`이나 이는 "플랜이 나쁘다"가 아니라 "3개 항목 구체 수정 후 재평가 필요"를 의미.
- v1 autoresearch 계약(단일 run, 단일 mission) 준수, 추가 자동 iteration 없음.

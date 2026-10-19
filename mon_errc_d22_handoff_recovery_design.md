# MON ERR-C D2.2 — 후보 수신·상세 수집·원자 게시·최종 판정 개선 설계

**설계일** 2026-10-14 KST · **상태** DESIGN_ONLY / NOT_IMPLEMENTED · **범위** `mon.php 1.8.7` + `mon_chatgpt_bridge.php (MON-CHATGPT-BRIDGE-1.0)`; `monitor.md v4.15`와 MON-P3.1은 변경하지 않음.

기본 기준: [CHANGE_GATE.md](CHANGE_GATE.md), [AGENTS.md](AGENTS.md), [ERR-C D2.0](mon_errc_upgrade_design.md), [복구·감속 D2.1](mon_recovery_upgrade_design.md), [핸드오프 규격](mon_chatgpt_handoff.md). 새 계산기·새 데몬·Cron·자동 시작·자동 주문을 추가하지 않는다.

## 1. 변경 게이트·기준 신원

- 설계 기준 `stock/main` commit: `9b26e654e57900441065a2a03f10ccb8ac9b9e57`. 저장소 운영 결과는 데몬에 의해 계속 바뀔 수 있으므로 실제 코드 구현을 시작할 때 HEAD·파일 해시를 다시 확인한다.
- GitHub 코드의 blob SHA: `mon.php` = `93df960040538fc580cf20c15246f5171cc0727e`, `mon_chatgpt_bridge.php` = `f02d021d913f3f9d2713daec7279802a220b403e`, `monitor.md` = `f0cf0c150b986616c46e5b1e0406d340c0d72075`.
- 최종 1.8.7 코드에 대한 이전 CI SHA-256 검증 기록: PHP = `5928e61aaee056f9d199a9076ae8ed86596257d5c03341ac9f219199b165c4f3`, bridge = `a1235e0a9f28d7262b494be80a0b4d6a415d2d20197699c565c51cd7ba130030`. 이는 코드 파일의 기존 CI 증거이지 NAS 설치 파일 SHA 검증은 아니다.
- NAS 실제 GET 검증: 2026-10-14 08:34 KST에 `processor_version=1.8.7`, `chatgpt_bridge=MON-CHATGPT-BRIDGE-1.0`, `running=true`, heartbeat 정상, `readiness=error`. **NAS 설치된 실제 바이트 해시: UNKNOWN**. 코드 구현/배포 후 별도 비교해야 한다.
- 운영 작업 `mon-20261014-0816-kst-001`: ChatGPT의 후보 21개 전송·PHP의 후보 등록·PHP의 `details` 응답·ChatGPT 최종 선정 및 금융 파일 쌍 별도 PHP 복구 경로 저장까지 관측. 기존 서류 `mon_runtime_result.json`의 `processor_version=1.8.8`은 과거 보고서 값으로 현행 배포 버전 판정에서 **STALE**.
- 2026-10-14 약 09:00 KST의 GitHub 핸드오프 응답은 `done=6`, `pending=11`, `blocked=2`, `conflict=2` / 21개. 세부: `HTTP_404`, `DETAIL_RS_HISTORY_MISSING`, `DETAIL_PRICE_BASIS_CONFLICT`. 변동하는 운영 관측일 뿐 모든 시점의 고정값이 아니다.
- NAS 읽기 전용 실행 증거: [부트스트랩](https://github.com/wskimgit/stock/actions/runs/38485072080)·[오류/자원](https://github.com/wskimgit/stock/actions/runs/38485188617); [21건 등록 원격 저장](https://github.com/wskimgit/stock/actions/runs/38484737480); [최종 파일쌍 저장](https://github.com/wskimgit/stock/actions/runs/38486030395).
- 본 문서는 **설계만 승인**한다. 테스트/배포/수정 완료 주장은 하지 않으며 정상 데이터·기존 선정 기록·NAS 데몬에 변경을 가하지 않는다.

## 2. ERR-C 문제 분류 및 변경 매트릭스

| ID | 등급 | 근거·재현 수준 | 기존 책임 구역 | ERR-C 수정 |
|---|---|---|---|---|
| P0-A | BLOCKER | NAS `HTTP_TRANSPORT_28` 실제 관측. GitHub PUT 시간 상한 코드상 3초. 원인 기여는 강하지만 3초만이 유일 원인인지는 미확정 | `MonHttp`, `MonData::settings`, `MonGitHub::publish` | R2/R3/C1 |
| P0-B | BLOCKER | 실등록은 저장됐지만 NAS의 이전 `MonPublication` journal은 `MON_ANALYSIS_SOURCE_CONFLICT` 상태. 실제 재현 | `MonChatGPTBridge::resumeCompacted`, `MonPublication` | E1/R3/C2 |
| P1-C | MATERIAL | `mon_data.json` 게시와 후보 응답의 잦은 갱신. 코드상 `source_blob_sha` 변경만으로도 응답 커밋이 달라질 가능성; 별도 계측 후 확정 | `MonChatGPTBridge::report/export`, `MonGitHub::publish` | E2/R1 |
| P1-D | MATERIAL | WMT `DETAIL_RS_HISTORY_MISSING`, 기타 `HTTP_404` 실제 관측 | `MonDaily`, `MonDetailWorker`, `MonDetails` | R2/R3/C3 |
| P1-E | MATERIAL | 일본·한국 일부 `DETAIL_PRICE_BASIS_CONFLICT` 실제 관측 | `MonBarConflict`, `MonDaily`, `MonDetails` | R3/C3 |
| P1-F | MATERIAL | 미국 10/13 목표 완료봉과 10/12 이전 검증봉 불일치; 과거값을 현행 매입 후보로 승격 불가 | `MonDetails::target`, `MonSwing::prepare`, `MonCompletion` | R3/C4 |
| P1-G | MATERIAL | PHP `decision`이 원본 SHA와 응답 SHA를 고정하나 장중 quote mirror로 source SHA가 자주 바뀌면 재준비 발생 가능. 코드상 위험, 실 시나리오 추가 시험 필요 | `MonChatGPTBridge::decision`, `MonRun::rebase`, `MonDetails::finalize` | R1/R3/C2 |
| P2-H | DISPLAY | `saved`와 `overall_complete`를 혼동하면 실제 미완료가 숨겨짐 | `MonProgress`, `MonCompletion`, 출력 문서 | E3/C4 |
| STALE | STALE | 과거 CI/기록의 버전 1.8.8, 이미 정상 PASS했던 경로 | 이력 전용 | 재개발·반복 검사 금지 |

## 3. ERR-C: E — Eliminate / 제거

**E1. 중복 후보 재등록·이전 journal 재시도 제거**
1. `resumeCompacted()` 시작 시 기존 journal 상태보다 **최신 GitHub `mon_data.json`에 실재하는 요청**을 먼저 확인한다.
2. 요청의 `request_id`, `symbol_id`, 기존 `request_hash`, `criteria_version`, 후보 신원·target 일치 여부를 `MonDetails::validRequest/matching`로 확인한다. 동일 21개가 이미 등록되었다면 기존 journal이 `conflict`여도 새 금융 게시를 하지 않는다. 다만 과거 충돌 사실은 감추지 않고 `registration_confirmed_existing`와 `superseded_job_id`의 작은 영수증에 보존한다.
3. 일부만 존재하면 정확히 누락된 후보만 등록하고 기존 완료 후보·분석·수집 자료는 손대지 않는다. 목표 거래일이 바뀐 경우 기존 요청을 현재 요청으로 조용히 위장하지 않고 별도 갱신 사유와 출처를 기록한다.
4. `MonPublication::source`의 일반 소유권 보호나 `MonRun::rebase`의 `MON_ANALYSIS_SOURCE_CONFLICT` 판단 자체는 해제하지 않는다. **동일 작업이 실제 저장되었는지 증명하는 전용 readback**만 추가한다.

**E2. 실질 변화 없는 응답 커밋·전체 재수집 제거**
- `MonChatGPTBridge::report/export`는 종목 상태·검증된 완료봉·핵심 사실·오류·출처가 바뀐 경우에만 `mon_chatgpt_response.json`을 갱신한다. GitHub quote mirror만 바뀐 경우의 `source_blob_sha` 갱신은 내부 보호 상태에 보관하고 재검증 요청 시 제공한다. 최종 결정에는 반드시 최신 원문 SHA를 다시 대조한다.
- 동일 유효 일봉/지수, 검증 해시, 공급자 원본이면 다시 fetch·snapshot·pack하지 않는다. 새봉/실제 데이터 정정은 정상 무효화 사건이다.

**E3. 완료 표현과 운영 완료의 중복 판정 제거**
- 별도 `saved=true` 플래그를 운영 전체 정상으로 해석하는 코드·지시문 문구를 금지한다. `candidate_registered`, `detail_usable`, `analysis_complete`, `pair_saved`, `overall_complete`를 서로 다른 판정으로 유지한다.

## 4. ERR-C: R — Reduce / 축소

**R1. GitHub 왕복·대용량 자료 재생성 최소화**
- `mon_data.json` 조회는 이미 읽은 SHA/ETag가 유효하면 재사용하고, 동일 직렬화 결과는 재생성하지 않는다. 신호/결정 본문은 필수 증명 필드만 반환하며 원시 OHLCV/개인정보를 공용 응답에 반복 싣지 않는다.
- 보고 JSON은 21개 상태에 대한 작은 `rows` + `status_revision`(의미 변화의 안정 해시)로 만든다. 변화 없으면 PUT하지 않는다. 사용자의 진행 단계는 NAS 읽기 전용 GET으로 계속 제공한다.
- 브리지 요청30개는 **활성 슬롯**이지 전체 탐색 후보 상한이 아니다. 성공·실패 참조를 보존한 뒤 차기 묶음으로 진행한다.

**R2. 네트워크 예산을 단계별로 분리**
- 현재 `github_http_timeout_seconds`는 `MonData::settings`에서 **최대 3초로 캡**한다. NAS 원격 POST/PUT 크기·실측 시간을 기반으로 **GitHub 읽기와 쓰기를 구분**한다. 설계 시험 초기 탐색 범위: 읽기 3→5→8초, 대용량 쓰기 5→8→12초 (실측·NAS 부하에 따른 가변값; 임의의 새 고정 전체 실행 마감 아님).
- 단일 웹 데몬 회차의 기존 25초(감속 20초) 및 publish reserve를 먼저 존중한다. 남은 시간이 요청 예상시간보다 작으면 통신을 시작하지 않고 보호 journal에 다음 단계/다음 시도시각을 저장한다. 대기 중 다른 종목을 진행한다.
- 실제 timeout, bytes, readback seconds를 표본으로 다음 호출 정책을 갱신하고 Retry-After/429·회로 차단·CPU 부하 보호를 그대로 적용한다. 인증 401/403에는 무한 빠른 재시도가 아닌 `blocked`와 원인 표시.

**R3. 실패 종목의 재작업 범위 축소**
- 유효한 종목 OHLCV를 이미 checkpoint했으면 다음 시도는 벤치마크 21개 거래일/지표 검증에서 재개한다. 날짜나 조정 기준이 바뀌면 정확한 체크포인트만 폐기한다. 120초 request wait은 실제 데이터 신선도와 혼동하지 않는다.
- 20거래일/21개 비교일·6개월 원천 확보 목표·MACD(12,26,9) 수식·RS20 기준은 낮추지 않는다. 정확도 미달이면 `previous_verified` 참고로만 반환한다.

## 5. ERR-C: R — Reinforce / 강화

**R3-1. PUT의 모호한 결과를 분리**
1. GitHub PUT 요청 → 성공 응답이면 새 blob SHA 확인.
2. `HTTP_TRANSPORT_28`, `HTTP_LATE_RESPONSE`, ACK 유실이면 `write_unknown` 보호 상태를 남기고 먼저 원격 최신 HEAD/tree와 해당 파일 SHA(필요 시 내용을 SHA 재계산) 조회.
3. 요청한 원자 파일쌍(+ 아카이브)이 실제 존재하면 `saved`로 확정하고 누락 ACK만 복원. 전혀 없다는 증거가 있을 때만 재시도, 현재 source에서 기존 `MonRun::rebase` 재검증을 수행. 동시 분석이 실제 바뀌었다면 `conflict`를 유지한다.
4. GitHub GET 자체가 timeout이면 `unknown`으로 보존, **무조건 같은 대용량 PUT을 반복하지 않음**.
5. 기존 `MonPublication`의 journal·원자 tree commit·readback을 재사용하며 제2 게시 엔진은 만들지 않는다. NAS 내부의 두 작성 경로(quote mirror와 원자 publication)는 짧은 공통 소유권 경계로 조율하되 외부 커밋은 반드시 GitHub SHA-CAS로 검증한다.

**R3-2. 종목별 오류를 정확하게 분류**
- `HTTP_404`: provider URL/종목 코드/거래소 매핑의 **원천별 실패**로 분리. 특정 provider 404가 전체 종목의 영구 차단을 뜻하지 않는다. 확인된 대체 provider로 진행; 실제 코드·신원 자체가 잘못되었다면 `blocked` 보존.
- `DETAIL_RS_HISTORY_MISSING`: 종목 일봉·지수 양쪽의 동일 21거래일을 검사해 부족 범위만 확보한다. 연속 거래일/지수 비교 검증에 실패한 자료는 RS20·현행 골든 신호로 승격하지 않는다.
- `DETAIL_PRICE_BASIS_CONFLICT`: 기업행사·분할조정·통화·시장·날짜·호가 단위 및 과거 겹치는 구간을 분리 검증. 기존 `MonBarConflict`, `priorWindowProof`, KR 독립 KRX/KIS 증명, 기존 whole-window 정밀 근접치 정책을 **그대로 재사용**한다. 화면 표시 단위의 반올림 허용만 적용하며 조정 미확인 가격을 임의 근삿값으로 합치지 않는다. 공식 근거가 없으면 conflict + 이전 정상 reference.
- `DETAIL_KR_REFERENCE_PENDING`: 기존 독립 참조 원천 확인 단계에서 resume. 이미 받은 유효 종목 봉은 다시 조회하지 않는다.

**R3-3. 종목별 격리·공정성**
- 각 종목을 독립적인 `queued → stock_saved → benchmark_saved → verified → published` 단계로 관리. 기존 `MonDetailWorker/MonDaily` 체크포인트만 확장하고 종목/지수 원본·원천·시각·SHA를 기록한다.
- 블로킹·충돌·시간 초과 종목 하나가 다른 20개 처리나 검증 결과 저장을 막지 않는다. 원본·저장 journal 실패는 별도 운영 상태로 전달하고 성공으로 은폐하지 않는다. 신규·과거 강한 종목은 선정 경쟁에서 차별하지 않는다.

**R3-4. source SHA 변경 시 판단 무결성**
- `mon_chatgpt_handoff.json`의 `decision`에는 ChatGPT 후보 요청 SHA, 실제 읽은 `details_receipt_sha`, `source_blob_sha`, 선정 이유를 유지한다.
- 최종 결정 직전에 latest source·각 선택 종목의 request_hash/완료봉·선정 자격·market target·현재 기업/거래 관측을 재검증한다.
- 변경이 결과와 무관한 quote collection뿐일 때 **공식 `MonRun::rebase`와 fingerprint 재검증**으로 현재 컬렉션을 보존하고 불필요한 전체 후보 재선정을 줄인다. 선택 신호·벤치마크·가격 기준·거래일 또는 기업 위험이 바뀌었다면 `refresh_source`로 돌려 ChatGPT 판단을 다시 받는다. `rebase`의 소유권 보안 조건을 완화하지 않는다.
- 확인할 현재가가 없거나 10/13 미국 완료봉 부족이면 참고 후보/conditional로만 저장하고 `ready`나 즉시 매입 신호를 생성하지 않는다.

## 6. ERR-C: C — Create / 추가 (기존 구조 내 작은 기능)

**C1. 저장 증명 영수증**
`request_id, stage, candidate_ids_digest, source_blob_sha, remote_commit_sha, file_sha_map, state, error_code, retry_after, verified_at`의 작은 보호 레코드. 공개 outbox에는 비밀·원시 봉·계좌·주문이 없어야 한다. `registered_existing`은 실제 21개 요청의 검증된 readback 결과이며 이전 journal의 conflict 이력을 지우지 않는다.

**C2. 동기화 상태 전이 명세**
- 후보: `received → registered/registration_publishing → registered_existing 또는 registered_partial`.
- 상세: `pending → stock_saved → benchmark_saved → done_latest/done_previous`, 오류 시 `retry_wait/blocked/conflict`.
- 결정: `details_read_by_ChatGPT → decision_received → prepared → publication_pending → pair_saved`. 종목별 실패가 있으면 정직한 `partial` 결과를 저장하고 `overall_complete=false`.
- 이미 확인한 `pair_saved` 결과와 원래 판단시각은 동일 결정 재실행으로 덮어쓰지 않는다. ChatGPT가 스스로 다음 대화를 자동 시작하는 능력은 없으므로 후속 ChatGPT 최종 결정은 사용자 요청/지원되는 명시 실행에서만 한다.

**C3. 실패 분류별 다음 작업**
`error_code → affected_symbol → origin → original_checked_at → retry_after/provider_fallback → checkpoint_sha → needed_evidence`를 작은 메타로 제공. 원천 404/429/5xx, 일봉 부족, split conflict, 지수 부족을 분리하며 새 증거 없는 오류 반복은 하지 않는다.

**C4. 운영·선정 완료 계약의 분리**
- `candidate_registered`: 원격 request map 확인.
- `detail_usable_count`: 원천·봉·지수 기준 검증된 종목 수.
- `analysis_complete`: ChatGPT 판단의 정식 함수 검증 여부.
- `pair_saved`: `mon_data.json`·`mon_result.md` (+ 참조 의존 파일)의 SHA 일치.
- `overall_complete`: 기존 `MON-OPERATION-COMPLETION-1.0` 기준, NAS·미완료·통계 영수증 모두 확인 후에만 true. 일부 미완료를 가진 유용한 결과 저장은 가능하지만 전체 완료로 표현하지 않는다.
- 단계·개수·지연·오류·최근 변경을 한국어 간단 카드/화면에서 보여주며 매번 전체 자료를 다시 그리지 않는다.

## 7. 중복 없는 수정 책임과 구현 단계

| 순서 | 단일 수정 책임자 | 최소 범위 | 유지·제외 |
|---|---|---|---|
| S1 | `MonGitHub::publish`, `MonHttp`, `MonData::settings` | GitHub 전송 타입별 timeout, write-unknown/readback, journal 우선 | 시세 계산·브로커·RS/MACD 미수정 |
| S2 | `MonChatGPTBridge::resumeCompacted/candidates/report/export` | 원격 기등록 판정, 충돌 journal supersede, 변경 없는 outbox 갱신 제거 | 별도 게시 엔진/데몬 금지 |
| S3 | `MonDaily::fetch/checkpoint`, `MonDetailWorker`, `MonDetails::workDue` | 공급자별 404, 21개 비교일, 종목-지수 단계 재개, conflict 증명 | 임의 근사 가격·가짜 봉 금지 |
| S4 | `MonChatGPTBridge::decision`, 기존 `MonDetails::prepare/finalize`, `MonPublication` | ChatGPT 결정을 표준 루트로 최종 저장·readback, 원천 갱신 시 무결 재검증 | PHP 자체 추천 생성 금지 |
| S5 | `MonProgress`, `MonCompletion` / 최소 문서 보완 | 진척·이전자료·최종 영수증 구분 | 정상 신호 규칙/동결 지시문 변경 최소 |

운영 `mon_data.json`, `mon_result.md`, 시장 결과, 통계 데이터는 개발 커밋 대상으로 삼지 않는다. 문서도 기존 `monitor.md`의 명령 블록을 복제하지 말고 최종 구현 때 이 설계를 참조하는 짧은 링크/상태 표기만 갱신한다.

## 8. ERR-C 변경 범위 시험/인수 조건

| ID | 시험 시나리오 | PASS 단언 |
|---|---|---|
| T01 | 1.5MB PUT 응답이 3초 지연되고 실제 Git 저장은 성공 | remote file SHA 조회 후 단 한 번 저장으로 `saved`; 중복 PUT 0 |
| T02 | PUT 이전 timeout/원격 미저장 | 다음 허용 회차에 재개, 최신 SHA-CAS 적용; 후속 종목 비차단 |
| T03 | 후보 21개 모두 실제 저장되어 있고 오래된 intake journal `conflict` | 전체 21개의 유효 요청 일치 확인→`registered_existing`; 분석 덮어쓰기/재등록 0 |
| T04 | 21개 중 18개 저장·3개 누락, Git 병렬 quote 업데이트 | 누락 3개만 추가, 동시 분석/컬렉션·의존성 모두 보존 |
| T05 | 원격 파일쌍+아카이브 3파일 게시 중 ACK 유실 | 전체 tree/file SHA 일치 시 `pair_saved`, 일부만 저장됐으면 미완료 보존 |
| T06 | 21개 후보에 404/timeout/차트 기록 부족/가격 기준 충돌 혼합 | 정상 종목 계속 처리, 오류별 checkpoint/원천/다음 재개 방법 기록 |
| T07 | 유효 종목 봉은 있는데 benchmark 날짜 21개 부족 | 기존 종목 원문 재수집 0, 지수 보완 전 usable/latest=false |
| T08 | split-adjusted vs unadjusted, 통화·시장 부정확, 유사 종가 | 조정 근거 미충족은 conflict; 화면 반올림만 허용, 생 자료 미변경 |
| T09 | decision 수신 중 quote-only 병행 변경 | `MonRun::rebase`로 보존; 선정 사실 변경 시 `refresh_source`·ChatGPT 재판단 |
| T10 | 최신 목표 10/13 US 봉 없음, 한국·일본 완료봉 유효 | 미국 reference-only, KR/JP 개별 적격, 실제 pair 저장, `overall_complete=false` |
| T11 | 공개 outbox 동일 상태 반복 · source commit만 변화 | 상태 의미 불변 시 불필요한 outbox PUT 0, 실제 바뀌면 명시 업데이트 |
| T12 | NAS 중단/재시작·CPU/메모리 보호 대기 | 수동 중단 즉시 존중, 기존 journal 동일 요청 재개, 96MiB·저부하 정책 유지 |

테스트 계층은 ① PHP 7.4 lint ② synthetic MonHttp/MonGitHub 실패 주입 ③ 96MiB 현재 크기 read-only fixture ④ 기존 `tests/mon_chatgpt_bridge_test.php`, `tests/mon_chatgpt_bridge_compaction_test.php`, `tests/mon_recovery_upgrade_test.php`, `tests/mon_progress_test.php`, `tests/mon_web_test.py` 중 **변경된 의존 구역만 재실행** ⑤ 실제 NAS 배포 승인 후 GET 상태+비주문 실운영 확인 순서다. 목표는 해당 변경 구역 **PASS 100%, 기존 정상 의미 불변, 운영 부하/원격 커밋 수 개선**이다. 실행시간 개선을 수치로 주장하려면 같은 데이터 크기·부하 조건의 p50/p90 전후 실측이 필요하며 추정 절감률을 확정 수치로 쓰지 않는다.

이전 `MON-ERRC-D2.0`·`D2.1` PASS는 의존 파일/해시가 안 바뀐 범위만 `REUSED`하고, 변경 후 영향받는 테스트만 무효화·재실행한다. 1.8.1을 가정한 과거 HTTP fixture 등 STALE 테스트 때문에 현행 정상 코드를 역수정하지 않는다.

## 9. 완료 판정·롤백·배포 경계

1. **DESIGN_COMPLETE**: 이 문서의 변경표·책임·실패 주입·인수 조건·원본 SHA가 확정되고 문서 readback이 확인됨. **코드 개선 완료를 뜻하지 않는다**.
2. **CODE_COMPLETE / DEPLOYMENT_PENDING**: 실제 최소 수정 후 PHP 7.4/메모리/ERR-C 변경 경계 최종 SHA 검증과 검토 가능한 PR, 파일쌍 시뮬레이션 통과.
3. **LIVE_VERIFIED**: 사용자가 허용한 NAS 웹 수동 배포·시작 상태 및 **배포 코드 SHA**가 확인되고, 사용자 후보→NAS 상세 수집→ChatGPT 최종 결정→원자 파일쌍 원격 저장·운영 영수증까지 실관측한 경우에만 선언.
4. 롤백: 현재 `mon.php` blob `93df960040538fc580cf20c15246f5171cc0727e`, bridge blob `f02d021d913f3f9d2713daec7279802a220b403e`, 동결 `monitor.md v4.15` 원본을 유지한다. 출처·비밀·기존 NAS 보호 journal·데이터를 덮어쓰지 않는다.
5. **설계 단계에서 하지 않는 일**: 코드를 변경·배포·재시작·자동 거래·Cron/새 데몬 설정·실데이터 변조·새 토큰 노출·정상 항목의 전면 재시험.

**한 줄 결론:** ERR-C D2.2는 원격 저장 확인과 기등록 충돌 해소(S1/S2)를 우선 고치고, 실패 종목만 단계별 복구(S3)한 뒤 ChatGPT 최종 저장(S4)의 자동화된 PHP 경로를 정교화하는 **기존 MON 구조의 최소 변경 설계**다.

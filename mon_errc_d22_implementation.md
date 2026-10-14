# MON ERR-C D2.2 — 구현·시뮬레이션·수락 기록

**날짜:** 2026-10-14 KST  
**범위:** MON PHP `1.8.7 → 1.8.8`, MON 지시문 `v4.15 → v4.16`, 기존 bridge contract `MON-CHATGPT-BRIDGE-1.0` 유지  
**변경 ID:** MON-ERRC-D2.2-20261014  
**관련 설계:** [mon_errc_d22_handoff_recovery_design.md](mon_errc_d22_handoff_recovery_design.md)  
**필수 개발 게이트:** [CHANGE_GATE.md](CHANGE_GATE.md) · [AGENTS.md](AGENTS.md)

## 1. 원본·설치·코드 신원

- 작업 시작 `wskimgit/stock/main` base commit: `21f53e6754689a5d96bb31ecd218dd2a1c583c85`.
- 변경 전 개발 원본: `mon.php` Git blob `93df960040538fc580cf20c15246f5171cc0727e`; `mon_chatgpt_bridge.php` Git blob `f02d021d913f3f9d2713daec7279802a220b403e`.
- 변경 전 SHA-256 (`main` 및 기존 코드 실행 검증): `mon.php` = `5928e61aaee056f9d199a9076ae8ed86596257d5c03341ac9f219199b165c4f3`; bridge = `a1235e0a9f28d7262b494be80a0b4d6a415d2d20197699c565c51cd7ba130030`.
- **NAS 관측**: 2026-10-14 08:34 KST 읽기 전용 `?view=bootstrap`에서 `processor_version=1.8.7`, `chatgpt_bridge=MON-CHATGPT-BRIDGE-1.0`, heartbeat 및 수동 시작 데몬 동작 확인. **NAS 배포된 파일 SHA-256: UNKNOWN**; 새 1.8.8 배포 여부: **NOT_DEPLOYED_BY_THIS_TASK**, 실제 NAS 교체·재시작·비밀 설정 변경 없음.
- 최종 코드 SHA-256 (PHP 7.4 CI [run 38490829362](https://github.com/wskimgit/stock/actions/runs/38490829362)):
  - `mon.php` **`bfcccd0c5c21eddc6dba6405099381fa7080e9dfd00e4ef7aeb707bb8e3df5ff`**
  - `mon_chatgpt_bridge.php` **`685e3b48484c1b758158bfed385ea9b7f222e99c97487b44e601600e9a6a638b`**
  - `monitor.md` **`7a26f8a61c10b78e6bf1c668f5384592de50fe9a8c6bd2d5c4c0f7f9b23737b7`**
  - `mon_wiki.md` **`83730f72968ea284c9d3f9a01e0346cbfb4f83e06f09fced30a8bd502dd8ad02`**
  - `mon_chatgpt_handoff.md` **`94a35026e8a534e0c0966a414f1555e18c75e3d485b5ebb7da17b73914400857`**
  - `tests/mon_errc_d22_test.php` **`4e549f47dc89b0e5c9601fbb3a11dbe0e50cbf3eab10f52b069624be84bb1d6b`**

## 2. 오류별 근본 원인·최소 변경

| ID/등급 | 기대/관측 | 수정된 경계 | 보존한 항목 |
|---|---|---|---|
| S1 BLOCKER | 대형 GitHub PUT 3초 제한, timeout/응답 유실 뒤 원격 저장이 모호함 | `MonData::settings`에서 GitHub GET 기본5/상한8초, 대용량 PUT 기본12/상한12초 분리. `MonGitHub::publish`에서 ACK 유실 시 GitHub raw blob SHA 재조회 후 한 번만 성공 인정 | MonPublication의 파일쌍 트랜잭션, SHA-CAS, 개별 회차 상한, no-order |
| S2 BLOCKER | 기등록 후보와 오래된 실패 journal 충돌, 이미 성공한 종목 재등록 | `MonChatGPTBridge::registered/confirmedExisting/resumeCompacted`에서 후보 ID·request_hash·원격 현재 저장을 검증한 뒤 `registered_existing`로 인정, 이전 실패 journal은 변경 없이 보관 | 이전 실행 문맥/자료/분석/후보 신원 보존 |
| S3 MATERIAL | 최종 provider의 HTTP_404 또는 21일 자료 부족이 즉시 영구 차단 | `MonDaily::fetch`에서 종목별 alternative route 체크포인트, 300초 대기→대체 원천 전환(최대 2차), 3회 연속 실패 시 `DETAIL_PROVIDER_UNAVAILABLE`로 보존 | 21봉 RS20·완료봉·가격 조정 증명·시장 상대강도 기준 불변 |
| S4 MATERIAL | quote-only 컬렉션 변경으로 outbox 중복 갱신·최종 판단 무의미 재시작 | `MonChatGPTBridge::detailRows/stableSource/export/decision`에서 전체 반환 종목/스냅샷 해시와 비컬렉션 입력 해시를 비교, quote-only 변경은 응답 재게시 생략하고 정식 PHP 최종화; 의미 변화는 반드시 `refresh_source` | ChatGPT 자율 최종 선정·정확한 source SHA·기존 원문 검산·조건부 readiness |

실제 고정 provider/stock 데이터가 부정확할 때는 `blocked/conflict/previous_verified`로 분리하며 올바른 현재 매입 가능 상태라고 허위 표시하지 않는다. 원천 HTTP/종목 오류는 개별적으로 격리해 다음 종목을 계속한다. 스윙 기준 `MON-P3.1`, MACD(12,26,9), 국가별 최대3종목, watchlist 신원, 원본 데이터·가격 유효성 및 주문금지 원칙은 그대로다.

## 3. 실패 주입·회귀 시뮬레이션 증거

**최종 검증**: GitHub Actions PHP 7.4.33 + zlib/curl/json, read-only fake GitHub/로컬 PHP HTTP, [run 38490829362](https://github.com/wskimgit/stock/actions/runs/38490829362), 결과 **SUCCESS**.

| 세트 | 명령 및 시험 범위 | 결과 |
|---|---|---|
| PHP 7.4 문법 | `php -l mon.php`; `php -l mon_chatgpt_bridge.php`; `php -l tests/mon_errc_d22_test.php` | PASS |
| ERR-C D2.2 | `php -d memory_limit=96M tests/mon_errc_d22_test.php`: GitHub GET/PUT 시간, PUT ACK 유실/사전 실패, 오래된 journal, quote-only 반복 억제, mailbox ACK 유실, provider 404/이력 부족 대체 전환, quote-only decision 통과, 실제 의미 변화 거절 | **9 PASS / 0 FAIL** |
| 기존 ChatGPT 연결 | `php -d memory_limit=96M tests/mon_chatgpt_bridge_test.php`: 후보 수신, 중복, 상세자료, 원자 저장/readback | **7 PASS / 0 FAIL** |
| 대형 파일 원자 저장 | 과거 실제 `mon_data.json` frozen commit `c6ba07dad278da46cda6be6df7c257ecedd4fb88`; `MON_D22_OBSERVED_SOURCE=... php -d memory_limit=96M tests/mon_chatgpt_bridge_compaction_test.php`; 3종목·복구 아카이브 포함 금융 파일쌍 | **PASS**, 입력 1,641,689바이트, 최고 메모리 91,553,792바이트 ≈87.3MiB, 단일 원자 게시 commit readback |
| 기존 웹 데몬 기능 | `python3 tests/mon_web_test.py`: 브라우저 수동 시작/중지·권한/정지·웹 분리 | **26 PASS / 0 FAIL** |
| 로컬 부트스트랩 | `python3 tests/mon_chatgpt_bootstrap_test.py`: bridge 켜짐/없음, read-only GET 비기동 | **3 PASS / 0 FAIL** |

**시뮬레이션 46 PASS / 0 FAIL**, 별도 PHP 문법 PASS. 시장 주문, NAS 데몬 재시작, 브로커 비밀/API 실제 요청은 0건.

**초기 실패→보강 기록 유지**:
1. 최초 lost-ACK 테스트에서 모의 GitHub가 저장 JSON을 정렬·다시 직렬화해 *실제 GitHub의 원본 byte SHA 저장*과 달라짐 → fixture가 요청 원문 바이트를 그대로 보존하도록 정정.
2. 운영 `mon_data.json`이 이미 compaction된 뒤 세 파일이 필요 없는 실행에서 기존 test가 강제 세 파일을 기대 → 역사적으로 고정된 실제 원본 snapshot을 이용, 같은 3파일 인수 범위 회복.
3. `registered_existing`의 정확한 기등록 결과를 기존 시험이 `registered`/ `registered_partial`만 수용해 거절 → 새로운 증명 영수증도 동일 저장 성공으로 검증.

위 문제는 최종 코드의 재수정 없이 **변경된 fixture의 재현 가정**을 고쳐 해결한 사례다. 처음 실패한 CI 결과를 PASS 이력에 합산하지 않는다.

## 4. 변경 경계·잔여 검증·롤백

- 변경 경로: `mon.php`, `mon_chatgpt_bridge.php`, `monitor.md`, `mon_wiki.md`, `mon_chatgpt_handoff.md`, `tests/mon_errc_d22_test.php`, `tests/mon_chatgpt_bridge_compaction_test.php`, `tests/mon_chatgpt_bootstrap_test.py`, `.github/workflows/mon_errc_d22.yml`, 본 설계·릴리스 기록.
- 기존 ERR-C D2.0/D2.1·ChatGPT 연결의 **수정되지 않은 부분**만 이전 PASS를 REUSED로 취급. final 1.8.8 SHA에 의존하는 새로운 변경 경계는 이번에 모두 새로 실행.
- **NOT_RUN/배포 대기**: 실제 NAS `/web` 코드 교체, 실제 설치 파일 SHA 확인, 운영 실트래픽에서 3초 이전 대비 p50/p90 통계, 실제 GitHub timeout의 재발 여부, 실시간 주식 원천·분할 자료의 시장 독립 증명, 전체 21개 종목이 최신 `done` 상태가 되는지, 전체 `MON-OPERATION-COMPLETION-1.0` 영수증. 46/0은 고정 오류 주입/회귀 시험만 입증한다.
- 저장: 별도 작업 브랜치 `fix/mon-errc-d22-20261014`와 검토 PR. 사용자 운영 `mon_data.json`, `mon_result.md`, 브로커 설정, 통계 데이터는 편집하지 않음.
- 롤백 소스: 변경 전 `main`의 `mon.php` blob `93df960040538fc580cf20c15246f5171cc0727e` 및 bridge blob `f02d021d913f3f9d2713daec7279802a220b403e`. 배포 승인 시 두 PHP 파일을 동일 최종 source commit에서 설치하며 원래 설정을 변경하지 않는다.
- **수락 수준**: **CODE_COMPLETE / DEPLOYMENT_PENDING**, 실제 NAS 1.8.8이 실행·문제 복구에 성공했다는 증거가 없으므로 **LIVE_VERIFIED 아님**. GitHub branch publication/merge, NAS deployment, live verification을 각각 구분한다.

**최종 결론:** ERR-C D2.2 S1–S4에 대해 최소 변경 코드·실패 주입·시뮬레이션이 완료됐으며, 운영 NAS는 별도 교체·SHA 검증·실거래 없는 상태 조회가 끝난 뒤에만 최종 정상화 완료로 판정한다.

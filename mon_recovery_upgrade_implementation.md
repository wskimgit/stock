# MON 복구·감속 개선 구현과 시뮬레이션 — D2.1 / 1.8.6

2026-10-13 KST. 지시문v4.14. 기준 Git6237ef215d3553291ceef83fa444ceca15cd989a의 mon.php1.8.5에서 문제를 확인하고 [설계 D2.1](mon_recovery_upgrade_design.md)·[설계 검토](tests/mon_recovery_design_review.json)를 먼저 작성한 뒤 코드를 변경했다. 새 기능·실자료·관련 회귀 **114 PASS / 0 FAIL**을 확인했다. 코드 개선 완료와 NAS 운영 정상화는 구분한다.

## 1. 확정 문제와 변경

| ID | 확인된 문제 | 최종 구현 | 검증 |
|---|---|---|---|
| M01 | NAS load1=2.7, 가용63,721,472바이트(하한33,554,432)만으로 전체 tick 보류 | MonDaemonLoad가 /proc/stat CPU 차분·가용 메모리를 구분. load만 높으면 throttled | 실제 관측값 재생, CPU 첫/누락/리셋/포화, 메모리 하한, native tick |
| M02 | load0.78·가용73,166,848인데 recovering120초; 보호 중 publisher 호출 | 첫 안전 표본 뒤5초 회복 확인. 메모리 부족 후 계측 누락은 보호 유지. hard tick은 publisher 호출하지 않음 | 30→60→120→5초·두 안전 표본, native tick/웹 상태 |
| M03 | 이전 종가 증명 실패 후 국내 독립 전체 구간 증명 경로 없음 | MonDaily의 KIS KRX(J)/수정주가(0) 독립 검증. 100행씩 체크포인트·재개 | 130행 두 회차, 전체 날짜/종가, 실제100원 차이 보류, 시장/신원/기간/손상/timeout |
| M04 | 기업·거래가 같은 checked_at을 사용; 거래 갱신에 기업 근거 재작성 | trading_review와 MonRiskState. 기업 status/checked_at/scope/evidence_ids 보존 | patch·finalize·recover·현재 표시·completion·실제 HTTP·모의 Git 저장 |
| D01 | 실자료: SKI152800/152700, LGES398000/396500 | 실제 불일치와 원인 미확정을 유지. 독립 증명이 실패하면 충돌/재시도 보존 | 합성 성공을 실제 NAS 충돌 해소로 보고하지 않음 |

ERRC: 전체 정지와 기업 시각 덮어쓰기를 제거(E), 감속 회차·독립 조회 페이지를 감소(R), 보호·원문 보존·필수 미완료 판정을 강화(R), 감속 정책·KRX 증명·거래 부분 갱신을 추가(C)했다. 수정 지점은 기존 단일 데몬/고정 함수이며 새 운영 프로그램이나 자동 시작을 만들지 않았다.

## 2. 최종 동작

### 자원 정책 MON-RESOURCE-1.1

| 조건 | 처리 | 한 회차 |
|---|---|---|
| 정상 | normal | 기존25초·상세1/8초·시세2/6초 |
| load>CPU수×1.25 또는 측정CPU≥80% | throttled | 전체≤20초·시세1/3초·상세1/8초·게시 초기 단계4초·원격 확인60초 기준 |
| 가용 메모리≥하한이지만<하한×1.25 | throttled | 위 감속과 같고 새 상세 수집0건 |
| 가용<max(32MiB, 전체10%) 또는 측정CPU≥95% | deferred | 새 수집/큰 게시 없음. heartbeat와 대기 작업 유지 |
| 보호 상태 회복 | 안전 표본2회 | 첫 안전 표본 뒤5초. 가용 메모리가 계측되지 않으면 메모리 회복 아님 |

CPU 최초 표본·누락·카운터 역행은 null이다. loadavg를 CPU 실사용률로 환산하지 않는다. CPU 미계측 상태에서 메모리로 시작한 대기는 메모리 회복으로, CPU로 시작한 대기는 추가 load≤CPU수×0.9로 확인한다. 실제 CPU 시간×9·최소5초 유휴, nice+10·96MiB·작업별 소켓 timeout은 유지한다. 이 값은 보호 정책이며 실물 NAS 전체 자원 사용률을 보장하지 않는다.

화면은 감속 처리/시스템 보호 대기/회복 확인/자료 복구 필요를 구분한다. 감속 중 fresh quote가 있어야 monitoring_active다. NAS 운영 완료는 감속도 허용하지만 최신 heartbeat·코드/부팅·재시작 불필요·미해결0·요청 전부done·실제 반영SHA를 함께 확인한다. 기존 operation_policy.reader_min_version=1.8.5를 바꾸지 않아 이전 보호 영수증을 소급 무효화하지 않았다.

### 국내 가격 충돌 복구

이전 verified 종가 증명을 먼저 적용하고 실패한 KR/KRW/KOSPI·KOSDAQ만 독립 경로로 전환한다. KIS 공식 API의 KRX(J)·수정주가(0)를 **새 StockAnalysis 원천의 첫 날짜부터 목표 완료일**까지 조회한다. 최대100행씩 회차당1페이지를 보호 저장하고 다음 회차는 누락 페이지부터 시작한다. 페이지가 남으면 DETAIL_KR_REFERENCE_PENDING/retry_wait로 보존한다.

체크포인트는 종목/거래소/통화/전체 새 봉SHA/기간/시장/옵션에 묶고 내용 해시·형태를 확인한다. 입력 변경은 이전 페이지를 승인에 사용하지 않고 다시 시작한다. 날짜·종가의 전체 집합과 기존 반 표시단위 비교를 통과해야 새 원천 전체를 쓴다. KRW100원 차이·부분 구간·다른 시장·신원·통화·손상은 거절한다. 이전 snapshot/새 원천/독립 bars·응답 해시/해결 proof를 보존한다. 행별 혼합·가격 원값 반올림·이전봉 절단을 하지 않는다. 기존 정상 KIS 원주가 및 무분할 필드 보호, 미국 Nasdaq 증명은 유지했다.

### 기업·거래 확인 분리

price_observation과 trading_review={status,checked_at,applies_as_of,evidence_ids}를 같은 변경에 전달한다. status=verified는 같은 종목의 유효한 독립 정규장 가격·연결 근거가 필요하다. stale/미연결 가격은 MON_TRADING_OBSERVATION_REQUIRED다. status=needs_check는 거래 미확인으로 남긴다.

risk_review의 기업 시각/상태/범위/근거를 보존하고 trading_checked_at/trading_evidence_ids만 갱신한다. 이전 파일은 checked_at을 거래 시각 기본값으로 읽는다. 기업 검토 당일·거래300초를 patch/finalize/표시/completion의 공통 MonRiskState가 계산한다. 기업 미확인·새 시장 날짜·악재·거래정지·새 완료봉·신원 목표·원격 미저장은 거래 갱신으로 해소되지 않는다. 변경된 확정은 같은 실행 ID/접수시각에서 새 artifact를 실제 게시·읽기 확인해야 완료된다.

## 3. 시뮬레이션으로 보강한 항목

기본42건과 저장 연계7건 통과 후 경계 사례6건을 추가했다. [초기 경계 시뮬레이션](tests/mon_recovery_simulation_initial.json)은55건 중52 PASS/3 FAIL이었다. 다음3건을 실제 코드에 보강하고 [최종55건](tests/mon_recovery_upgrade_tests.json)은 모두 통과했다.

| 초기 실패 | 보강 | 재검증 |
|---|---|---|
| 메모리 부족 뒤 MemAvailable가 사라져도 회복 표본으로 인정 | memorySafe가 실제 원인에 맞는 계측을 요구 | 누락 상태에서도 deferred/work_allowed=false |
| CPU만 실제 계측됐는데 measurement_available=false | 공통 계측 플래그에 CPU 포함 | CPU 실측/가용 플래그 일치 |
| recovering 상태를 심한 포화/메모리 부족으로 표시 | 회복 확인 전용 label/message | native 웹 모델의 회복 상태 구분 |

시험 입력 오류도 분리했다. 첫6 FAIL은 strict_types 시험 helper의 null run ID/float→int 변환 문제였으며 제품 코드 오류로 집계하지 않았다. HTTP fixture의 과거 분석 시각보다 미래인 달력 확인시각을 바로잡았다. 과거 분석보다 늦게 등록된 신원의 목표 보류는 올바른 동작이므로 가격·거래 복구가 별도 신원 미완료를 제거하지 않는 검증으로 남겼다. 실자료 시험의 첫 실행은 로컬 의존 원문을 주입하지 않아 PHP_CURL_REQUIRED로 중단됐다. 정확한 의존SHA를 로컬에서 검증하도록 시험을 수정했고 외부 전송은 발생하지 않았다.

## 4. 최종 검증 성적과 범위

| 구분 | 건수 | PASS / FAIL | 자료 |
|---|---:|---|---|
| 새 자원/KRX/거래/전체 저장 시뮬레이션 |55|55 / 0|[함수·연계](tests/mon_recovery_upgrade_tests.json)|
| 새 실제 로컬 HTTP 거래 부분 복구 |10|10 / 0|[HTTP](tests/mon_recovery_http_tests.json)|
| 실제 고정 원문 동일성·96MiB |5|5 / 0|[실자료 비교](tests/mon_recovery_observed_tests.json)|
| 관련 기존 완료 강제 회귀 |28|28 / 0|[함수 회귀](tests/mon_recovery_related_gate_tests.json)|
| 관련 기존 완료 HTTP 회귀 |16|16 / 0|[HTTP 회귀](tests/mon_recovery_related_http_tests.json)|
| 합계 |114|114 / 0|새 검증70·관련 회귀44|

실제 PHP7.4.33·memory_limit=96M로 실행했다. 함수/연계 최대12MiB, 고정 실자료의 읽기·검증·5개 snapshot 확인·native prepare 최대82.63MiB(86,638,592바이트)였다. 실제 준비 시간은 각 결과 JSON의 seconds이며 두 버전의 근소한 차이는 속도 개선으로 주장하지 않는다. 실자료 원문 blob7bdae7c1df1ff20123db158b30088b61840eae8f/1,505,517바이트의 collection·선정·전시계열·지표·표본5개와 준비 fingerprint가1.8.5/1.8.6에서 정확히 같았다. 원문 파일을 수정하지 않았다.

KIS/최종 Git 게시 연계는 합성 transport다. HTTP는 실제 localhost 서버다. 외부 시장 전송/주문/NAS 배포0이며 시험을 시장 운영 통계에 넣지 않았다. 이번 코드·문서·시험 저장에는 GitHub 커넥터를 사용하지만 mon_data.json/mon_result.md/mon_execution_stats.json은 변경하지 않는다. 과거 전체 PASS를 반복하거나 합산하지 않았다.

재현은 PHP7.4+zlib로 다음을 실행한다. 실자료 비교는 고정 mon_data 원문과 같은 폴더의 SHA가 일치하는 mon_recovery_inputs.json을 인자로 사용한다. 출력에는 인증이나 원시 일봉을 넣지 않는다.

```bash
php -d memory_limit=96M tests/mon_recovery_upgrade_test.php
MON_TEST_PHP=/path/to/php74 python tests/mon_recovery_http_test.py
MON_TEST_PHP=/path/to/php74 python tests/mon_recovery_observed_test.py /path/to/frozen/mon_data.json /path/to/baseline/mon.php
php -d memory_limit=96M tests/mon_completion_gate_test.php
MON_TEST_PHP=/path/to/php74 python tests/mon_completion_gate_http_test.py
```

## 5. 실제 적용 범위

다운로드/Git 코드 버전은1.8.6, 지시문은v4.14다. 실제 NAS 관측은10/13 00:41 KST의1.8.5다. `/web/mon.php` 교체 후 웹 수동 중지→시작과 버전/부팅/heartbeat 확인이 별도로 필요하다. 자동 시작·자동 재시작·cron·주문을 수행하지 않았다.

실제 SK이노베이션/LG에너지솔루션의 어느 종가가 맞는지와 가격 차이 원인은 아직 확정하지 않았다. 코드 교체 후 KRX 독립 실제 전 구간을 확보·검증해야 승인할 수 있다. 공급자가 계속 불일치하면 미완료/충돌을 보존한다. 82.63MiB는 이 고정 입력의 로컬 측정이며 NAS 실부하/모든 데이터 규모/전체 MON 소요시간 보장은 아니다.

공식 근거: [Linux /proc](https://docs.kernel.org/filesystems/proc.html), [KIS 국내 기간별 일봉](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/domestic_stock/inquire_daily_itemchartprice/inquire_daily_itemchartprice.py). 코드/설계/시험 파일의 최종SHA는 [릴리스 매니페스트](mon_recovery_release_manifest.json)에 기록한다.

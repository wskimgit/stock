# MON 1.8.5 — ERR-C 구현·시뮬레이션 보강

2026-10-12 KST · mon.php **1.8.5** · monitor.md **v4.13** · 설계 **MON-ERRC-D2.0** · PHP7.4.33

설계 D2.0에 따라 코드와 지시문을 수정했다. 새 실패 입력·오류 주입·로컬 HTTP·변경 관련 회귀 **101건 PASS / 0 FAIL**이다. 최초 시뮬레이션의 제품 결함 3개와 HTTP bootstrap 결함 1개를 해소하고 추가 경계 사례를 검증했다. NAS에 배포하거나 실제 시장 실행을 새로 수행한 성적은 아니다.

## 구현과 인수 조건

| 설계 | 구현 | 확인 |
|---|---|---|
| D01/E | 같은 원문 두 위치를 제거하고 v2 위치 marker·원문/전체 SHA로 정확히 복원. 기존 marker와 marker 없는 입력 지원 | A01–A04, 실제 5개 입력, 실제 1.8.4 reader의 안전 거절 |
| D02/E·R | 종목 전체 보호 경계, cache/응답 pack 1회, 정상 cache 보존, serialization_pending/blocked/conflict, 오류별 단계 재개 | A05–A07, 다른 종목 진행·동일 오류의 HTTP 반복 없음·디스크 실패 전달 |
| D03/R·C | 기존 manifest 뒤에도 고유 이력 분리, 불변 segment·감소 generation·순차 페이지, 실제 예약 확인, 참조 의존성의 원자 게시/이어 검증 | A08–A10, SHA/순환/누락/이전 segment/중복 힌트 위조·최대30 응답 공간 |
| D04/R | 보호된 수치 캐시 최대256개, snapshot 최대2개, 정확한 봉·live·수식/코드/신원 키, 공통 지수의 회차 간 재사용 | A11–A12, 값·날짜·해시 무효화와 원래 checked_at 보존 |
| D05/R | 커넥터 실제 저장 확인 우선, 응답 유실 GET 확인, collection 병합·분석 충돌 보존, 의존 파일까지 확인 | A09·A13–A14, 중복 ref 없음 |
| D06/E·C | finish owner 보호, begin/end ACK, external/source 실제 units, 미측정 시간 null, 업무 종료 뒤 별도 후처리 report와 원격 통계 증명 | A15–A16, 늦은 보고·중복·긴 ID·닫힌 통계 보존·순환 의존성 없음 |
| D07/R·C | required scope 보호, 실제 NAS 건강 관측·현재 완료일·원격 보존·최신 heartbeat, 시장/운영 완료 분리 | A17–A19, 오류/부분/구형·오래된 상태 거절, 정상 장외 인정 |
| D08/E | 실행 블록 한 곳, wiki 참조, 올바른 finish/status 경로·새 API | A20, 로컬 HTTP·문서 정합성 |

기존 수식·MACD(12,26,9)·ChatGPT 선정·당일 신규 목표·20,480바이트 운반·주 파일2MiB·NAS96MiB·수동 시작·주문 금지를 유지했다. 굴러가는 6개월 원천 창은 실제 겹치는 종가 최소60개를 확인한다. 원래 전체 SHA와 제외 날짜를 보존하고 중간 누락·부족을 거절하며 예전 봉을 새 창에 합치지 않는다.

## 시뮬레이션에서 발견하고 고친 문제

| 최초 실패 | 원인 기반 보강 | 최종 검증 |
|---|---|---|
| 커넥터가 파일 쌍만 저장했는데 참조 이력까지 saved로 표시 | 직접·이전 segment 의존성 검증을 보호 게시 journal에서 순차 재개; 누락 참조와 파일 쌍 같은 tree/ref | A09 및 이전 segment 복구 PASS |
| 변경된 봉이 수치 cache에 적중 | 실제 함수 인자 input/live로 정확한 키 구성, 작은 값 변경도 무효화 | A11 및 기존 1.8.4 수치 SHA 비교 PASS |
| NAS 오류가 별도 계측 scope도 실패로 표시 | NAS·외부 ACK·통계 저장 판정을 독립 구분 | A17·A18 PASS |
| 실제 GET bootstrap에서 새 건강 관측 초기화 실패 | 해당 GET 경로에서 기존 설정을 명시 로드, 동일 progress 관측으로 상태 표시 | 실제 PHP HTTP 9 PASS |

초기 결과는 [25개 초기 시뮬레이션](tests/mon_errc_simulation_initial.json)과 [HTTP 초기 결과](tests/mon_errc_http_initial.json)에 보존했다. 최초 시험 도구의 없는 unpack 함수/잘못된 WMT 신원은 시험 도구를 바로잡았으며 제품 실패 성적으로 집계하지 않았다. WMT의 실제 저장 ID는 `US|NASDAQ|WMT`다.

## 최종 검증

| 시험 | 결과 | 범위 |
|---|---:|---|
| [포장·종목 복구·실제 입력](tests/mon_errc_upgrade_tests.json) | 13/13 | 실제 PHP, 새/구형 reader, 고정 실제 입력 |
| [오류 주입·복구 시뮬레이션](tests/mon_errc_simulation_tests.json) | 35/35 | 실제 함수와 mock Git/NAS, 보호 파일·가상 시계 |
| [새 HTTP 연계](tests/mon_errc_http_tests.json) | 9/9 | 실제 로컬 PHP7.4 서버, 인증·scope·계측·공간·bootstrap |
| [완료 관련 함수 회귀](tests/mon_completion_gate_v185_tests.json) | 28/28 | 수정한 완료/게시 경로의 기존 호환 |
| [완료 관련 HTTP 회귀](tests/mon_completion_gate_v185_http_tests.json) | 16/16 | 기존 완료·복구 API의 관련 연계 |
| **합계** | **101/101** | 새 검증57 + 변경 관련 회귀44 |

과거 45/141 PASS나 가상3,500회는 합산하지 않았다. 실제 외부 API 호출·주문·NAS 데몬 시작은 0이다. 운영 `mon_execution_stats.json`과 시장 결과 파일 쌍은 이번 개선에서 변경하지 않는다.

### 실제 실패 입력의 무손실 확인

검증 원본 `mon_data.json` blob은 `10afad012bd3da6b0bfbbe1bbadd374afd7326aa`다. [기존 1.8.4 snapshot SHA](tests/mon_errc_observed_fixture.json)를 독립 기준으로 사용했다. 전체 원문·지표·출처·시각의 canonical JSON이 일치한다.

| 종목 ID | 새 Base64 바이트 | 기존 결과와 복원 SHA |
|---|---:|---|
| JP\|TSE\|6098 | 16,372 | 동일 |
| KR\|KOSPI\|006400 | 17,068 | 동일 |
| KR\|KOSPI\|051910 | 16,760 | 동일 |
| KR\|KOSPI\|096770 | 17,068 | 동일 |
| US\|NASDAQ\|WMT | 17,948 | 동일 |

실제 데이터의 저장 예약을 524,288→655,360바이트로 복원했고 예약 후 여유는 **2,640→56,140바이트**다. 기존 판단·자료 시각·지표·collection을 보존했다. 이 값은 시뮬레이션 작업 복사본의 결과이며 현재 Git 시장 데이터를 수정한 것이 아니다.

최대 PHP 메모리는 **73,768,960바이트=70.35MiB**로96MiB 이내다. 같은 입력의 중복 pack·계산·지수 조회를 줄였으나 전체 ChatGPT 실행 시간이나 실제 NAS 부하의 개선율은 아직 측정하지 않았다. 5–10분 완료나 미래 무고장을 보장하지 않는다.

## 실제 인터페이스와 적용

새 실행의 `operation_policy`는 start 때 고정한다. `scope=analysis_and_nas_normalization`, `finish_owner=execution`, `require_statistics_publication=true`가 현재 MON 지시문의 기본이다. 정책 없는 구형 실행은 `publication_legacy`의 이전 동작을 유지하며 기존 접수시각과 영수증을 바꾸지 않는다.

| API | 역할 |
|---|---|
| mon_operation_nas_check | 고정 읽기 클라이언트의 실제 NAS 건강 관측; 시작/재시작 없음 |
| mon_storage_compact | 실제 source/context를 검증해 이력·예약 공간 분리 |
| mon_archive_page / mon_archive_lookup | 한 segment·페이지씩 검증 조회/복원 |
| mon_run_report | 업무 종료 전 external/source/nas/search·connector의 begin/end ACK |
| mon_operation_report | 업무 종료 후 statistics_publication 등의 begin/end·실측/미측정 보고; 금융 통계는 보존 |
| mon_operation_verify_statistics | 실제 고정 head/tree/파일 SHA와 동일 종료 revision 확인, 읽기 checkpoint 재개 |

모두 기존 operator 인증과 보호 실행 문맥을 사용한다. 영수증 없는 호출자 성공 플래그로 전체 완료를 만들지 않는다. 상태 API는 `operation`, finish는 `run.operation`을 읽고 `overall_complete`와 운영 영수증을 확인한다. 금융 업무 종료·운영 전체 종료·최종 채팅 전달은 별도 시각이다.

Git 코드·문서 저장 뒤에도 NAS는 사용자가 기존 `/web/mon.php`를 교체하고 웹 중지→수동 시작해야 한다. 기존 broker/키 설정을 재사용한다. 실제 설치/실행 버전1.8.5, 실패 종목의 실제 응답·원격 보존과 정상 상태를 확인한 뒤에만 NAS 정상화 완료라고 표시한다.

재현에는 `MON_ERRC_OBSERVED_SOURCE`에 고정 원본 JSON, `MON_ERRC_BASELINE_CODE`에 해당1.8.4 PHP를 지정한다. PHP 함수 시험은96MiB 제한으로 실행하고 HTTP 시험은 `MON_PHP_BINARY`(새 시험)/`MON_TEST_PHP`(관련 회귀)에 PHP7.4 실행 경로를 지정한다. 시험 원천은 합성/고정 캡처이며 실제 계좌·시장 실행과 분리한다.

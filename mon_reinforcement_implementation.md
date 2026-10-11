# MON D1.5 — 완료 우선 구현·검증

2026-10-11 KST · mon.php **1.8.0** · monitor.md **v4.8** · MON-STATS-1.0 / MON-ADAPTIVE-1.1

사용자의 최신 요청에 따라 시간·횟수 초과의 강제 종료를 제거했다. 기존 비교 기준과 접수/문맥은 유지해 실제 초과를 측정하고 다음 기준에 반영한다. 아래 과거 D1.0~D1.4의 마감/최대 호출/최대 시도/만료 설계는 역사적 기록이며 현재 규칙이 아니다. 현재 운영 규칙은 v4.8 실행 블록과 이 절을 따른다. 선정·자료 유효성·인증·원자 저장·NAS 수동 시작·부하 제어는 유지한다.

| 경로 | 변경과 근거 | 검증 관점 |
|---|---|---|
| MonBudget.guard / prepare / finalize | 기준 초과를 기록하되 새 검증/판단/확정 허용 | 가상700초 경과 후 새 결론/원자 파일 생성·저장 |
| consumeHttp / 외부 report | 횟수 초과를 계측하며 전송/보고 계속 | 90회 전송·105건 보고·중복 ID 방지·640.5초 외부 시간 보존 |
| MonDetailWorker / 성공 원문 | done 전환을 없애고 retry_wait·원천/지수 체크포인트·대기 강화 | 상세4번째 복구·옛 DETAIL_ATTEMPT_LIMIT 재개·0 회차는 시도하지 않고 보존 |
| MonPublication | 기한/실패2회/병합1회 종료 제거·같은 journal·원격 확인 재개 | 4회 실패 후 저장·유실 확인 재개·중복 ref 없음·collection 병합2회 |
| MonExecution.finish / 시작 인덱스 | 미완료는 nonterminal checkpoint·finished_at=null·revision upsert |300초 뒤 강제 만료 없음·명시 interrupted는 존중·실제 완료만 terminal |
| MonRunStatistics | 초과 초/횟수·진행 하한/종료 실측 분리·참조 상한 제거 | 완료 초과×1.25·미완료 보수 증가·현재 기준 고정·다음만 조정 |
| MonProgress / 웹 | collecting 유지·시간만 바뀌어도 페이지 토큰 유지 | 늦은 요청/준비/확정·manifest 청크·자율 결정 불변·성공 위조 방지 |

시간/횟수는 종료 조건이 아닌 계획 기준이다. 단일 소켓과 데몬 회차는 제한해 NAS 부하를 낮추고 대기 작업을 다음 회차로 이월한다. 외부 장애가 영구 지속하거나 인증/데이터가 손상되면 실제 미해결을 표시하며 성공으로 위장하지 않는다. 정상 HTTP가 항상 성공하거나 NAS 전체 부하가 항상 일정하다고 보장하지 않는다.

운영 이력2회(183초 하한/290.10초 미완료)와 시험 표본은 분리했다. 현재 다음350/360/370초는 통계 계획 기준이며300초 강제 종료 상한이 아니다. 현재 가상700초 완전 완료처럼 종료 실측이 확보되면25% 여유의 기준을 적용하고, 미완료 하한은 보수적으로 반영한다. protected rolling snapshot은60회/256KiB이고 같은 ID는 revision으로 갱신한다.

이번 변경 검증은 함수/복구22 PASS + 로컬 웹 HTTP25 PASS = **47 PASS / 0 FAIL**이다. 기존 시험 전체는 반복하거나 합산하지 않았다.

신규 검증: [함수·복구](tests/mon_completion_tests.json), [실제 로컬 웹 HTTP](tests/mon_completion_http_tests.json). 합성 시장자료와 가상 시간이며 실제 NAS/외부 공급자/주문 호출은 없다. 기존 PASS를 이번 성적에 합산하지 않는다. GitHub 반영과 NAS /web/mon.php 실제 교체·수동 재시작은 별도다.

---

## 과거 구현 이력 — 현재 종료 규칙과 구분

# MON 보강 구현·검증 보고

## v1.7.0 / 지시문 v4.7 — 실행 통계와 다음 한도 자동 조정

2026-10-11 KST. 전체 처리 시간·횟수 통계를 다음 실행 한도에 반영하라는 요청을 구현했다. 현재 실행의 마감은 유지하며 NAS 부팅 자동 실행/데몬 상한은 바꾸지 않았다.

| 구현 | 검증 결과 |
|---|---|
| MonRunStatistics·보호 rolling snapshot·workload | p50/p90·완료/미완료/초과율·단계/HTTP/시도/표시, 최근30일·최대60회·256KiB, SHA 검증·원자 저장·중복/충돌 거절 |
| MonExecution·MonBudget | auto120~300초·현재 한도 immutable·다음 한도만 변경, 수동 normal/extended 호환, 미측정과 중단 하한 분리 |
| MonHttp·상세 요청/작업자 | 통계 HTTP 시간/20~60회·게시12회 예약, 실제 전송만 카운트, 상세1~3시도/최대8초·30초/Retry-After와 체크포인트 유지 |
| 외부 보고·종료/중단 | 실제 외부 작업/표시 ID 중복 제거, 초과 호출도 실제 횟수 기록, 미완료 원인 보존, 다음 시작의 최대8건 만료 점검 |
| 게시/지연 완료 | 실제 모의 Git 파일 쌍/ref 확인 후 동일 통계 행 revision 갱신·작업 시간/호출 수 보존, 저장 잠금 실패 저널 재시도 |
| 기존 운영 경계 | 통계/HTTP API만으로 데몬 권한·상주 작업·주문·실제 mon_data.json/mon_result.md 변경 없음 |

[함수·연속 시뮬레이션](tests/mon_adaptive_test.php)의 [원시 결과](tests/mon_adaptive_tests.json)는 실제 PHP7.4.33 **35 PASS/0 FAIL**, [로컬 HTTP 검증](tests/mon_adaptive_http_test.py)의 [원시 결과](tests/mon_adaptive_http_tests.json)는 **20 PASS/0 FAIL**이다. 합계55 PASS/0 FAIL이고 시험의 외부 요청/주문은0이다. 이전57/48/105 시험은 재실행하거나 합산하지 않았다. 시험 서버는 종료했다.

초기 통계 [mon_execution_stats.json](mon_execution_stats.json)은 직전 실행의 확인된183초 하한1회, NAS1.001464초/1회 시간 초과, 검색1묶음/3검색어·기존 준비1회·실제 표시 보고2회다. 첫 표시 시간은 미측정이고 새 분석은 미완료다. 이력의 censored=true와 operator_reported를 유지하며 다음210/220/230초 제안의 근거로만 사용한다. NAS 실물 배포·실부하 감소·외부 시장 자료 성공·최종 채팅 전달까지의 정확한 시간은 별도다. 통계 저장 실패는 결과 검증/게시 영수증과 분리하여 진단하며 journal에서 재시도한다.

코드·지시문·wiki·설계/보고·새 시험4개·운영 통계1개를 GitHub에 저장한다. NAS `/web/mon.php`의 코드 교체와 기존 웹 중지→시작 적용은 별도이며 자동으로 배포/시작하지 않았다. NAS의 보호 통계가 있으면 이를 우선하고, 없는 경우 같은 상태 폴더의 portable snapshot을 읽는다. NAS 불가 시 기존 커넥터로 snapshot을 검증 병합/CAS 저장한다. 통계가 없는 수동 호출에는 기존 고정 상한이 적용된다.

## v1.6.2 / 지시문 v4.6 — NAS 수동 시작과 데몬 부하 제어

2026-10-11 KST. 사용자 요청은 NAS 부팅 때 자동 재동작 금지와 다른 NAS 작업에 무리한 부하 방지다. 코드의 옵션 없는 CLI는 기존 daemon 시작이었고 데몬은 대기에도10초 원문 수신·5초 heartbeat/설정 해시를 반복했다. NAS의 실제 시작 작업/부하 측정은 이번 환경에서 확인하지 않았다.

| 구현 | 확인한 결과 |
|---|---|
| MonManualStart·CLI·웹 Start/Stop | 부팅/인스턴스/15초/해시·한 번 소비·Stop 취소; 이전 부팅/기한/재사용/잘못된 토큰·경합 거절 |
| 기본 CLI 상태 조회, --daemon/--once/무권한 --run 거절 | 실제 CLI와 GET/설정/게시 경로에서 자동 실행 권한 없음; 기존 PID만으로 실행 판단 안 함 |
| MonDaemonLoad·run의 우선순위/메모리/유휴 | 로컬 PHP7.4에서 nice 적용·96M 확인; CPU 단위 부하/여유 RAM·30/60/120 대기·2회 회복·측정 불가 표시 |
| 데몬 전용 작업 상한·quote 커서 | 전체25초·상세1개/8초·시세2개/6초·종목3초; 5종목 커서2→4→1, 반복 시간 초과 뒤에도 다음 종목 진행 |
| MonGitHub 조건부 GET·cacheRemote·detailWorkDue | 304 원문0바이트·같은 SHA cache 쓰기0·완료/미도래 요청 원문 준비 생략 |
| heartbeat·설정 확인·웹 polling | 대기 쓰기 생략과 종료 즉시 기록,20초 설정 확인·10초 웹/숨긴 탭 생략 |
| 시스템 부하 대기와 게시 마감 | 과부하 회차 외부 호출0·기존 체크포인트 보존·artifact 해제 생략·원래 expires_at 보존·expired/unknown 구분 |

[함수·한 회차 실행 모의](tests/mon_lifecycle_load_test.php)의 [원시 결과](tests/mon_lifecycle_load_tests.json)는 **PHP7.4.33에서41 PASS / 0 FAIL**이다. [HTTP/CLI 검증](tests/mon_lifecycle_load_http_test.py)의 [원시 결과](tests/mon_lifecycle_load_http_tests.json)는 **같은 PHP7.4.33의 실제 로컬 서버·CLI에서16 PASS / 0 FAIL**이다. 합계57 PASS/0 FAIL이며 외부 API/주문0이다. foreground 한 회차 모의 실행은 종료했고 별도 상주 MON 작업자를 남기지 않았다. 이전48/105 PASS 전체를 반복하거나 합산하지 않았다.

검증한 재부팅은 boot ID 교체 모의이며 NAS를 실제로 재부팅하지 않았다. 시스템 부하/메모리 수치는 합성이며 실제 CPU/RAM 감소율·NAS PHP 권한·Git ETag 공급·외부 수집 성공·ChatGPT120초 완료를 보장하지 않는다. 직접 분석/상세 함수 설정과 MON-P3.1·MACD(12,26,9)·mon_data.json/mon_result.md는 유지했다. 큰 관찰목록과 긴 과부하 대기는 가격/상세 응답의 갱신을 늦출 수 있고 원래 마감 후에는 자료 부족·이전자료·게시 만료로 표시한다.

현재 저장 대상은 코드·지시문·wiki·설계/보고와 새 검증 파일4개다. NAS `/web/mon.php` 교체·실가동은 별도다. 교체 후 웹 중지→시작으로 적용하고 이후 재부팅/예외 종료마다 웹에서 다시 시작한다. 새 폴더·cron·watchdog를 설치하지 않는다.

## v1.6.1 / 지시문 v4.5 — 세 장애의 복구 보강

2026-10-11 KST. 직전 실행은 접수 후259.2초에 기존 결과를 표시했고 NAS 건강 조회1회가 Proxy CONNECT 시간 초과였다. 미국10/9 완료봉·S&P500 비교와 가격 기준 충돌은 해결되지 않았다. 새 분석 실패를 PHP/NAS가 실제로 모두 해결했다고 선언하지 않는다.

| 코드 변경 | 해결하는 경로 |
|---|---|
| MonBootstrap, GET view=bootstrap, POST mon_bootstrap, start.initial_result | 큰 원문·지표 계산 전에 저장된 결과·원래 시각·부족 상태를 작은 페이지로 표시 |
| MonDaily의 종목/지수 예산과 각 KIS 구간45% | 첫 원천 시간 초과 뒤 대체 원천/지수 기회 확보 |
| MonHttp의 원천별30초/Retry-After 차단 | 공통 장애 반복을 줄이고 다른 원천·종목 진행; 단일 시간 초과/5xx는 주소별, 서로 다른 주소2건 때 공통 차단; 개별404/Git 확인 분리 |
| 보호 stock checkpoint와 request/hash/date/expiry 검증 | 종목 원문 성공·지수 실패 뒤 같은 요청의 지수부터 재개, 원래 checked_at 보존 |
| 같은21거래일·정확한 저장 지수 활용 | 종목의 이전 완료일만으로 충분한 지수까지 다시 받던 경로 제거 |
| 일시 오류 재시도 분류·Retry-After/원래 마감 적용 | 매핑/날짜/조정 충돌의 반복 대기 제거, 이전자료 보존 |
| 고정 prepare/finalize의 마감 검사 | 웹 경로 밖 고정 함수 호출로 늦은 새 분석을 확정하던 경로 차단 |
| new_analysis_complete/analysis_origin와 표시 보고 필터 | 기존 결과 재표시와 실제 새 판단·원격 저장 완료 구분 |

bootstrap은 스키마만 검사한 이전 기록의 잠정 표시다. 전체 원문/신호/자격 검증을 대체하지 않으며 현재 ready를 반환하지 않는다. 최대8KiB 카드 페이지, 일반 JSON 응답12KiB 한도다. 페이지 at은60초 이내·미래5초 이내이며 같은 snapshot만 연결한다. 내부 보호 체크포인트는 기존 상태 폴더에 종목당 한 파일을 덮어쓴다. 새로운 요청과 충돌 원문을 섞지 않으며 실제 NAS 상태·미국 누락 봉을 만들어 채우지 않는다.

변경 경로 실제 **PHP7.4.33 함수31건＋실제 로컬 HTTP17건=48 PASS / 0 FAIL**. 원시 결과는 [함수](tests/mon_recovery_tests.json), [HTTP](tests/mon_recovery_http_tests.json)다. 지연/응답 모의는 해당 실패를 주입한 실제 수정 코드 실행이며 원천 호출·주문·새 데몬0이다.30개 과거 카드4페이지,259초 늦은 시작의 마감 보존, 공급자 간 장애 격리, 종목 체크포인트와 지수 재개, 해시/요청/기한 변조 거절, 전체 후보 준비·통합 확정·표시만으로 완료를 올릴 수 없음을 확인했다. 초기 실패는 시험의30행 구성/기본3후보 기대값/HTTP400 계약을 바로잡은 뒤 다시 검증했다.

초기 projection 순수 PHP의 측정과 HTTP 응답 크기는 원시 결과에 기록한다. 외부 원천·NAS·전체 ChatGPT 실행시간을 측정한 값이 아니다. 원래120초 전체 완료와 실제 자료 보충 성공은 보장하지 않는다. 기존105 PASS 전체는 반복하거나 이번48건에 합산하지 않았다.

GitHub의 코드·지시문·wiki를 갱신하고 실제 mon_data.json·mon_result.md는 보존한다. NAS 교체/재시작·외부 원천 성공은 아직 확인하지 않았다. 기존 웹에서 새 mon.php로 교체 후 중지→시작하여1.6.1을 확인한다.

## v1.6.0 / D1.1 당시 구현·검증 이력

2026-10-11 KST · mon.php **1.6.0** · 지시문 **v4.4** · 보강 설계 **D1.1**

보강 설계의 P0~P3를 코드·지시문·wiki에 반영했다. 같은 수치의 중복 준비, 큰 최종 JSON 전달, 불명확한 마감·저장 완료 상태를 개선했다. 실제 PHP7.4 함수·게시 모의75건과 로컬 HTTP·DOM 모의30건, **총105건 PASS / 0 FAIL**이다. NAS에 교체·재시작하거나 실제 추천자료를 변경하지 않았다.

기준은 설계 저장 커밋 [ebb5c3b](https://github.com/wskimgit/stock/commit/ebb5c3b3ec13673723179aad22e97f12c32d6249)이다. 기존 mon.php blob은 `76f19d970a65c6e7ac6f09d2bf4ea79061409005`다. 원래 MON-P3.1·MACD(12,26,9)·MON-SWING-1.1·schema3·ChatGPT 자율 선정·보유 판단·웹 시작/중지를 유지한다.

## 반영한 동작

| 설계 | 코드·문서 | 변경 후 동작 |
|---|---|---|
| P0 시간·문맥 | MonBudget, MonExecution, MonRun.context/token, 실행 블록 | 접수·첫 제어·모드 고정. 일반100/110/120초, extended280/290/300초. 단조 시계와 원래300초 복구 마감. 재개로 시작 재설정 금지 |
| P0 작은 전달 | MonPaging, MonProgress, MonArtifact, 웹 진행 페이지 | 준비12KiB·진행4KiB. 초대형 카드/헤더는 무손실 원문 참조. 최종 파일명·크기·SHA256/Git SHA만 전달, 최대64KiB 청크 연결 |
| P1 준비·확정 | MonPreparation, MonDetails.selectData/finalize, MonConfirmation | 수치 사실 기반 캐시. 같은 준비를 선정/참고/근거·가격·위험 변경에 재사용하고 최종 파일 쌍 한 번 생성 |
| P1 현재 판단 | MonRun.current/render, MonMeaning, 실제 표시 요약 | 원래 가격·위험시각을 보존해 진입 조건 재검사. 확인된 악재/미확인 위험 차단. 동일 업무 사실은 no_change. 표시시각만으로 재커밋 없음 |
| P1 자료 충돌 | MonDaily, MonDetails.checkedSnapshot, MonBarConflict | 기술 신호·가격 계획·이력까지 원자료 재계산 대조. 다른 레코드의 작은 값 차이도 충돌. 동일 불변 원문의 증명된 표시 반올림만 허용. 전체 충돌 진단 보존 |
| P2 게시·복구 | MonOperator, MonPublication, 기존 MonDaemon.tick | 인증된 고정 파일 쌍의 영속 접수. blob2개→tree→commit→force=false ref→파일 쌍 확인. 같은 작업 재사용·중단 재개·응답 유실1회 확인 |
| P2 동시 변경 | MonRun.rebase, pending_analysis, 보호 영수증 | collection만 한 번 병합하고 판단시각 유지. 늦은 상세 수치는 후속 판단 필요. 다른 판단/설정은 conflict. unknown 재게시 금지 |
| P3 탐색·오류 | MonDiscovery, 진행 화면, 실행 블록·wiki | 국가/신규/이전 교차 배정. 최초 발견일과 독립 신원 매핑이 확인된 고유 ID만 일별 신규 집계. 발견·확보·적격 수 분리. 실패해도 이전 판단/자료 부족 표시 |

PHP는 기업 신원/사건을 독립적으로 검색하거나 자율 종목을 선정하지 않는다. 실제 신규 탐색·누락 자료 보완·최종 결정은 실행 지시문에 따라 ChatGPT가 수행한다. 요청30개·상세 관찰10개는 관리 한도이며 전체 후보를 잘라내지 않는다. 목표20개에 못 미치면 실제 미달을 보고한다.

## 실제 변경 검증

| 구분 | 결과 | 환경·범위 |
|---|---:|---|
| 실제 수정 PHP 함수·파일·게시·데몬 연계 | 75/75 | PHP7.4.33, native gzip/hrtime, 합성 이력·시세·캘린더, 가짜 Git DAG/HTTP transport |
| 실제 로컬 HTTP·렌더된 JavaScript | 30/30 | PHP7.4 내장 서버, loopback HTTP, Node 문법·DOM 모의 |
| 외부 API·주문 | 0 | NAS/KIS/Yahoo/GitHub 운영 호출·주문 없음 |
| 같은 입력 수치 준비 | 1회 | 준비 후 최종 선정·근거 확정, 시세 collection만 변경된 재조회 |
| 진행30종목 | 4페이지 | 모든 ID를 정확히1회 전달, 각 실제 HTTP JSON 본문4KiB 이하 |
| 초대형 카드 | 168,053바이트 보존 | UTF-8 원문과 SHA256 유지, 각 카드 페이지12,288바이트 이하 |

원시 성적은 [함수·게시 결과](tests/mon_reinforcement_implementation_tests.json)와 [HTTP·UI 결과](tests/mon_reinforcement_http_tests.json)다. 함수 시험은 실제 변경 코드를 직접 실행한다. 게시 원격은 가짜 Git DAG로 모의하며 실제 ref 의미와 고정 파일 쌍을 검사한다. HTTP는 실제 로컬 서버이며 UI는 실제 브라우저 대신 렌더된 스크립트와 DOM 모의다. 정상 지표의 과거 전체 PASS를 반복하거나 이번 성적으로 합산하지 않았다.

검증한 주요 경계는 같은 문맥 정규화/실제 source 변경, 최초 접수·모드 불변, wall-clock 후퇴와 단조 시간, 캐시 무효화/재개, 오래된 가격·거래·기업 검증, 가격 구간 밖/미확인 위험/악재, 국가 범위·3개 합산 제한, 검증되지 않은 참고자료, 무변경/새 근거, 초대형 헤더/카드·중첩 원문 참조, 진행 snapshot 혼합, 신원·최초 발견일, 운영자 인증, 청크·경로·해시 변조, 영속 접수 실패, 원자적 쌍 게시, ref 응답 유실·unknown, blob 이후 중단 재개, collection/판단 동시 변경, 늦은 상세 자료,300초 만료, 손상 journal 격리, 기존 데몬의 파일 쌍 재개다.

시험 자료 구성 오류는 기술/신원 메타정보와 원문 참조 규격에 맞춰 수정했다. PHP74 표준 함수가 빠진 초기 임시 런타임은 standard·zlib를 포함해 다시 빌드한 뒤 검증했다. 최종 성적은 수정된 시험과 PHP7.4.33 native 실행의 결과다.

## 중단·실패 시 결과

| 상태 | 판단·저장 의미 |
|---|---|
| final_available=true / not_requested | 최종 파일이 보호 저장돼 있고 원격 저장은 요청하지 않음 |
| queued / publishing | 파일은 있으며 게시가 미완료. 전체 완료로 표시하지 않음 |
| saved | 실제 ref와 정확한 파일 쌍 확인, published_commit_sha·completed_at 영수증 있음 |
| no_change | 같은 검증 업무 사실의 이전 파일·판단시각 유지. 새 커밋 불필요 |
| conflict | 다른 판단/설정 또는 병합 한도 충돌. 원격 판단을 덮어쓰지 않음 |
| failed / expired | 권한·무결성·명시적 오류 또는 원래 복구 기한 종료 |
| unknown | ref 시도 후 실제 저장 여부 확인 불가. 재커밋/force push하지 않음 |
| pending_analysis | 늦게 들어온 상세자료는 저장했으나 현재 확정 판단에는 미반영 |

최종 파일에는 판단시각과 당시 pending 상태만 들어간다. 실제 저장 완료시각·commit SHA·경과는 별도 보호 영수증/채팅에 남겨 자기 커밋 SHA 때문에 두 번 커밋하지 않는다. 새 작업 오류는 검증된 이전 판단과 원래 시각 또는 자료 부족을 반환하며 자동 상위3개 대체 판단을 만들지 않는다. NAS 접근 실패는 access_unconfirmed로 기록하며 실제 NAS 정지는 별도 근거가 필요하다.

## 재현과 적용

아래는 개발 검증 재현용이며 일반 MON 실행에 사용자 CLI 조작을 요구하지 않는다.

```bash
php74 -n tests/mon_reinforcement_test.php
MON_TEST_PHP=/path/to/php74 python3 tests/mon_reinforcement_http_test.py
```

시험 런타임은 PHP7.4+의 standard·zlib·hrtime와 HTTP 시험용 Python3·Node가 필요하다. 실제 NAS 수집/게시의 cURL·연결 권한은 기존 운영 환경을 따른다. 합성 자료와 임시 보호 상태는 시험 종료에 제거하며 실제 mon_data.json·mon_result.md를 쓰지 않는다.

사용자 적용은 새 mon.php를 NAS `/web/mon.php`에 교체하고 기존 웹 화면에서 중지→시작해 버전1.6.0을 확인하는 순서다. 새 데몬·cron·폴더는 필요 없다. 새 보호 API는 서버 검증 REMOTE_USER/MON_OPERATOR_USER 또는 private MON_OPERATOR_TOKEN을 사용한다. 인증 미설정은503이며 일반 MON은 기존 고정 입력/커넥터 경로로 진행한다. 인증을 위해 사용자에게 별도 암호·CLI를 요구하지 않는다.

원래 [D1.0 시뮬레이션](mon_reinforcement_simulation.md)의66사례·가상 지연2,500회·가상 복구1,000회는 설계 모델의 이력으로 보존했다. 이번105건은 실제 수정 코드의 로컬 변경 검증이다. 두 성적 모두 실제 ChatGPT/NAS의120초 완료·실제 원천 성공·시장 대표성·투자 성과를 입증하지 않는다. NAS 인증·지속 저장·교체/재시작·외부 원천/Git·운영 전체시간은 적용 후 별도 확인 대상으로 남는다.

# mon 지시문 — 한·미·일 스윙 종목 선정·매입·매도 판단

- 지시문 **v4.9**, 실행 규격 **MON-RUN-1.1**, 초기 표시 **MON-BOOTSTRAP-1.0**, 선정 기준 **MON-P3.1**, 수치 규격 **MON-SWING-1.1**, 진행 규격 **MON-PROGRESS-1.0**, 저장 규격 **schema_version 3**.
- 개정일: **2026-10-11 KST**. MACD는 사용자 정정에 따라 **12·26·9**를 적용한다.
- 저장소 **wskimgit/stock**, 브랜치 **main**. GitHub 관리 문서는 저장소 루트에 둔다. 별도 Wiki 저장소나 `wiki/` 폴더를 만들지 않는다.
- 파일명 유지: **mon.php**=PHP, **monitor.md**=지시문·설계, **mon_data.json**=공유 데이터, **mon_result.md**=사용자 결과.
- 최상위 원칙: **ChatGPT가 자율 판단하고 자율적으로 종목을 선정한다. 투자형태는 swing이다. PHP 수치와 참고 순위는 보조자료다.**
- 추천 자격: **시장보다 강함 AND (MA5 재돌파 OR MACD 골든 OR MACD 골든 전후)**. 기존 추천·신규 후보는 같은 자격으로 경쟁한다.
- 구현: **mon.php 1.8.1**. 시간·조회·재시도·검색·게시 횟수로 전체 처리를 중단하지 않는다. 기준값은 통계/다음 계획에만 사용하며 초과 후에도 복구·판단·실제 저장까지 진행한다. 보호 통계에 초과량과 미완료를 기록한다. NAS 수동 시작·부하 제어·선정 기준·주문 금지는 유지한다.
- 배포 구분: GitHub 코드와 지시문을 바꾸는 것과 NAS `/web/mon.php` 교체·실가동은 별개다. NAS 교체 확인 없이 새 데몬이 가동 중이라고 쓰지 않는다.

## mon 실행 지시문

일반 실행은 아래 블록만 읽는다. 상세 규격은 새 오류나 기준 변경이 있을 때 필요한 항목만 조회한다.

<!-- MON:INSTRUCTION:BEGIN -->
```text
# mon 지시문 v4.9 — 완료 우선·NAS 지속 복구·접속 대기 자동 증가
기준 MON-P3.1 / MACD(12,26,9) / MON-SWING-1.1 / MON-RUN-1.1 / MON-DETAIL-1.0 / MON-PROGRESS-1.0 / schema_version 3.
stock/main 루트 monitor.md·mon.php·mon_data.json·mon_result.md를 사용한다. 처리/표시 wiki는 mon_wiki.md다.
최상위: ChatGPT가 후보 발굴·최종 판단·종목 선정을 자율 수행한다. 투자형태 swing. PHP는 자료와 수치 보조만 담당한다.
사용자 최신 원칙: 시간·조회·재시도·게시·검색 횟수의 초과만으로 전체 처리를 중단하지 않는다. 초과해도 복구·판단·실제 저장 확인까지 계속한다. 기준값은 다음 실행의 계획과 진단에 쓰며 현재 실행의 강제 종료 조건이 아니다. 자료·권한·충돌 미해결을 정상 성공으로 꾸미지 않는다.

1. 실행 문맥과 초기 표시
- received_at·first_control_at·같은 run_id를 기록하고 mode=auto로 MonExecution::start/mon_run_start가 반환하는 통계 기준을 고정한다. limit_behavior=observe_and_continue, stop_on_threshold=false다. 접수시각과 비교 기준을 재개 때 바꾸지 않으며 실제 시간·횟수와 초과량을 계속 누적한다. 정확한 접수가 없으면 partial_timing=true다.
- limits/remaining_seconds/deadline_at/expires_at는 이전 API와 호환하는 통계 기준 필드다. 0이 되거나 기준시각이 지나도 수집·준비·판단·확정·게시를 허용한다. MON 전체에 120/230/300초 또는 HTTP/검색/상세 시도 횟수의 종료 상한을 적용하지 않는다. normal/extended도 비교 기준만 정한다.
- 첫 제어에서 작은 검증된 이전 결과부터 확보·표시한다. 최신 실행 블록, Git head/mon_result.md, NAS GET ?view=bootstrap 건강 조회를 독립 묶음으로 확인한다. NAS에는 Accept: application/json을 보내고 처음 연결10초·전체 응답20초를 허용한다. 일시 실패하면 연결20→40→60초·응답40→80→120초로 늘리고 실패 후30→60→120→240→300초 간격으로 계속 재시도한다. 60/120/300초는 개별 접속/간격의 부하 제어이며 전체 종료 상한이 아니다. GitHub·기존 고정 PHP 경로로 결과 작업을 이어가면서 같은 NAS 실행 ID와 대기 상태를 보존한다. 첫 실패·시간/횟수 기준 초과로 NAS 확인을 포기하지 않는다. 새 데몬·사용자 암호/CLI 요구는 하지 않는다.
- NAS 대기/인증 미설정/응답 processor_version이1.8.0 미만이면 GitHub의 mon_execution_stats.json과 검증된 mon.php를 사용해 MonRunStatistics::validate/save, MonExecution::start를 로컬 보호 상태에 적용한다. HTTP200의 MON 구버전 JSON/화면은 connected_legacy(연결 성공·처리 버전 미충족)다. 이를 NAS 정지나 계속되는 접속 timeout으로 기록하지 않는다. 로그인 HTML·엉뚱한 JSON·버전/계약 불일치는 정상 API가 아니다. 일반 MON 실행에서 별도 계산 프로그램·코드 개선·과거 PASS 재시험을 하지 않는다. 모든 고정 함수는 같은 실행 문맥으로 호출하며 도중 연결 복구로 이미 시작한 실행 문맥을 바꾸지 않는다. access_unconfirmed는 NAS 정지를 뜻하지 않는다.
- NAS 접속은 고정 MonNasConnection::poll 또는 --nas-check --run-id=같은ID를 처리 환경에서 사용한다. poll은 읽기 GET 최대1회 후 상태·실측 시간·retry_in_seconds를 반환한다. 대기 중에는 다른 작업을 진행하고 다음 허용 시각에 같은 ID를 다시 호출한다. 동일 상태 디렉터리의 중복 요청은 잠금으로 건너뛰며 시도 수를 늘리지 않는다. 원래 접수시각·시도/timeout/실패/복구·최근20회 메타데이터를 PHP 보호 journal에 남긴다. 실제 새 시도만 고유 operation_id로 운영 통계에 한 번 보고한다. 성공 응답의 실제 전체 소요 p90×1.5를 다음 실행의 첫 연결10~60초·응답20~120초에 보수 반영한다. 코드 시험은 시장 실행 표본에 넣지 않는다.
- NAS DNS/연결/TLS/408/425/429/5xx·일시 timeout은 횟수와 무관하게 대기 후 복구한다. Retry-After의 초/HTTP 날짜는300초보다 길어도 존중한다. 401/403·잘못된 응답·클라이언트 설정 문제는 blocked로 보존하고 권한/입력/환경을 정정한 뒤 resume한다. 확인 없이 성공 처리하거나 동일 권한 오류를 빠르게 반복하지 않는다. 사용자의 명시 중단은 존중한다. 웹 상태/상세 조회는 한 번에 하나만 전송하고 요청 완료 뒤 다음 조회를 예약한다. 실패 때 응답 대기와 간격을 늘리고 새로고침/숨김 해제에도 남은 대기를 보존한다. 응답이 유실된 시작/중지/설정 POST를 자동 재전송하지 않고 GET 상태로 확인한다.
- 초기 결과는 원래 판단시각·자료 기준일·조건부/과거 상태로 표시한다. 큰 mon_data.json 해제·전체 wiki·외부 조사·전체 후보 경쟁을 첫 표시의 선행 조건으로 만들지 않는다. 이후 정확한 Git blob SHA의 최신 원문을 한 번 확보한다. 원시 JSON/Base64/일봉/전체 wiki를 모델 문맥에 출력하지 않는다.
- 초기 10~20초는 목표다. 취소 불가 도구/모델/커넥터 대기가 초과하면 실제 지연을 기록하고 제어가 돌아오는 즉시 원래 작업을 계속한다. 초기→변경 중간→최종을 기본으로 하며 변화 없는 표 반복·빈 중간 보고를 피한다. 오래 걸리면 유의미한 새 완료·복구·저장 상태를 추가 표시한다. 표시 횟수로 처리를 끊지 않는다.

2. 독립 수집과 부하를 낮춘 지속 처리
- 현재 목록·최근20거래일 후보·새 근거가 있는 과거 강한 종목·신규 후보를 동등하게 경쟁시킨다. 신원·국가·거래소·통화·공급자 코드를 검증하고 종목마다 독립 request_id를 부여한다. 같은 요청은 같은 ID, 새 완료일/정정/강제 재수집은 새 ID다.
- mon_detail_request/mon_detail_requests를 사용해 유효 요청과 오류를 종목별로 보존한다. 활성 요청30개는 한 전송의 크기/동시 보관 범위이며 전체 처리 횟수 제한이 아니다. 완료한 항목만 비우고 남은 후보를 다음 묶음으로 이어 처리한다. 기준시간이 지났다는 이유로 미완료 요청을 버리지 않는다.
- NAS 데몬은 상세 회차1종목·최대8초, 전체 회차25초, 시세2종목·전체6초, 최대96MiB/nice+10·과부하 대기를 사용한다. 한 회차가 끝나면 성공 원문/진행/대기 작업을 저장하고 다음 회차에서 이어 처리한다. 회차 종료·과부하 대기는 전체 요청 종료가 아니다. 웹 시작 수동 원칙과 재부팅 자동 시작 금지는 유지한다.
- 개별 HTTP는 소켓 timeout을 유지한다. DNS/연결/TLS/429/5xx·일시 timeout은 대체 원천/성공 원문 체크포인트/동일 지수 저장자료를 활용한다. 공급자와 주소별 circuit을 존중하고 그 사이 다른 종목을 진행한다. 일시 장애의 재시도는 최소30초·Retry-After, 시도 기준을 넘으면 30~300초 지수 대기와 원천 전환을 사용한다. 횟수를 다 썼다는 이유로 done/no_data로 바꾸지 않는다.
- execution_limits.attempts는 통계로 정한 원천 전환/대기 강화 기준(기본2, 복구율에 따라1~3)이며 최대 시도 수가 아니다. 요청 기준시각이 지나도 같은 목표 완료일·request_hash·원문 해시의 성공 체크포인트를 재사용하고 누락 지수부터 재개한다. 시간 초과 종목의 검증된 성공 자료를 폐기하지 않는다.
- 매핑 누락·권한 거절·가격 기준 충돌·봉/지수 날짜 불일치처럼 같은 입력 재시도로 해결되지 않는 문제는 확인 경로/입력 정정/기존 검증자료로 전환한다. 실패·영구 부족 필드·다음 조치를 명시하고 확인 가능한 결론을 도출한다. 검증되지 않은 봉·시세·신호를 생성해 성공 수를 채우지 않는다.
- GET ?view=progress는4KiB 이하 페이지로 진행만 조회한다. next_cursor는 같은 snapshot_token으로 이어 읽으며 전체 소스를 반복 검증하지 않는다. 기준시간 초과는 threshold_exceeded와 초과량으로 표시하고 진행은 collecting/retry_wait를 유지한다. 시각만 바뀌었다고 토큰/전체 후보 계산을 바꾸지 않는다. 새 응답·원문·판단 조건이 실제 변할 때만 반영한다.

3. 동일 기준 경쟁과 자율 최종 판단
- 요청/응답의 request_id·request_hash·symbol_id·목표일·가격 기준·원문 해시를 검증한다. 진행의 usable_reported/collection_complete는 최종 자격 검증이 아니다. 성공 가격과 이전 검증 지표를 구분해 활용하며 마지막 검증시각을 현재시각으로 위장하지 않는다.
- 고정 mon_detail_prepare로 한 번 병합·계산한다. 모든 카드 페이지와 reference_cards를 읽고 빠른/느린/이전/신규 후보를 같은 기준으로 경쟁시킨다. 실제 입력·완료일·수치/판단이 달라진 경우만 다시 준비한다.
- 추천 자격=시장보다 강함 AND (MA5 재돌파 OR MACD 골든 OR 골든 직전/후). 동일21완료 종가·거래일·분할 조정의 RS20_pp>0; KR=해당 KOSPI/KOSDAQ, US=S&P500, JP=TOPIX. 다른 지수/가짜 누락 봉으로 대체하지 않는다.
- MA5는 직전10거래일 이상 그날 SMA5 미만 후 최신 완료 종가가 SMA5 초과다. 검증된 현재가의 잠정 SMA5=(직전4완료 종가 합+현재가)/5도 가능하나 장중 잠정으로 표시한다. 등호는 재돌파가 아니다.
- MACD=EMA12−EMA26, Signal=EMA9(MACD). 직전 MACD≤Signal·현재 MACD>Signal이면 완료봉 골든. 이후1~3거래일 양의 히스토그램 유지와 최근3봉 H가 음수/0에서 엄격 상승·abs(H)/ATR14≤0.10인 직전 근접도 대상이다. 근접을 확정 교차로 쓰지 않는다.
- MACD 구간이 부족해도 검증된 MA5 OR 자격은 인정한다. ChatGPT가 상대강도·유동성·이격·추격 위험·기업 근거를 비교한다. PHP RS20/R20/ADV20 정렬은 참고다. 국가별 비보유 추천/이전자료 참고 합계 최대3개이며 보유는 별도다.
- 최신 자격은 selected, 과거 강도/신호만 확인한 종목은 이유를 밝힌 references다. 가격 계획은 검증 지지·저항·ATR·호가 단위로 계산하고 현재 가격·거래·기업 위험이 부족하면 조건부다. 보유 매도는 등록 손절/추적선, MA20와 직전10일 저점 동시 붕괴, 확인된 중대한 기업 훼손을 따른다.
- 독립 검색은 중요 후보·사건·누락 자료·신규 탐색을 묶어 진행한다. 검색 기준(기본2묶음)을 넘겨도 필요한 확인을 계속하고 실제 횟수/작업 수/복구를 기록한다. 국가당 신규20개/거래일 목표는 신원·최초 발견일이 확인된 고유 ID로만 집계한다. 오래된 new_in_mon·중복·신원 추정으로 목표를 채우지 않는다. 무익한 동일 검색은 반복하지 않는다.

4. 완료·저장·복구
- 통계 기준 도달은 중간 진행 보고 시점이다. 이를 이유로 새 분석을 포기하거나 이전 표 재표시만으로 작업을 끝내지 않는다. 복구 가능한 작업을 이어 처리해 자율 판단·검증된 결론·실제 저장 확인까지 진행한다. 영구 자료 부족은 조건부/참고/매입 없음 등 사실에 맞는 결론과 미해결 항목으로 표시한다.
- mon_detail_finalize에 작은 decision(selected, references, 이유, confirmations의 독립 근거·가격·위험 변경)을 전달한다. 같은 준비·source SHA/token·prepared fingerprint를 검증해 두 파일을 확정한다. 늦었다는 이유로 함수 호출을 거절하지 않는다. 확정한 판단을 같은 ID에서 바꾸거나 토큰을 우회하지 않는다.
- mon_data.json과 mon_result.md는 같은 원자 커밋으로 저장한다. 큰 파일은 검증 manifest/청크를 모델 밖에서 연결한다. 원격 ref/두 파일 SHA 확인 전 saved라고 쓰지 않는다. 변경이 실제 없으면 자율 재검증된 no_change다. 기존 자료 재표시는 새 분석 완료가 아니다.
- 게시의 expires_at는 비교 기준일 뿐이다. transient 실패가 반복돼도 queued/publishing을 유지하고 대기 후 같은 journal을 재개한다. 응답 유실은 원격 확인을 계속하며 확인 전 무조건 ref 재갱신하지 않는다. 최신 head/파일 쌍의 읽기로 미저장이 확인되면 최신 source에서 재개한다. collection만의 동시 변경은 횟수와 무관하게 검증 병합한다. 다른 판단/설정 변경은 실제 conflict로 보호한다.
- 최종 표는 기준시각·상태, 국가/종목/판단/자료 기준/가격 조건/이유, 주요사항으로 간결하게 표시한다. 원래 가격시각·지연·정규장·진입 구간·거래 확인300초·기업 검증일·미확인 위험·악재는 표시시각에 재검사한다. 자료 유효기간은 작업시간 통계 기준과 다르며 오래된 가격을 ready로 표시하지 않는다.
- fully_complete는 새 판단 검증과 saved/no_change가 모두 실제 확인된 경우에만 true다. 시간 초과여도 둘 다 확인되면 완료다. 아직 수집/판단/저장이 남으면 nonterminal checkpoint로 기록하고 계속한다. 사용자 명시 중단은 존중한다. 권한/손상/충돌 등 실제 해결 불가 오류를 성공으로 숨기지 않는다. NAS 자동 시작·자동 재시작·cron·주문은 하지 않는다.

5. 초과 통계와 다음 기준 자동 조정
- 전체 received_at→실제 종료, 단계/HTTP/상세 시도/검색/표시 수, 성공·timeout·실패·재시도·복구와 미완료를 기록한다. 외부 도구는 실제 종료 후 mon_run_report로 operator_reported, 표시 후 mon_run_observe로 보고한다. 시각을 모르면 null이며 서버 종료와 최종 채팅 전달 시간을 구분한다.
- mon_run_finish(reason=deadline 또는 아직 미완료)은 종료/만료가 아닌 통계 checkpoint다. terminal=false, finished_at=null, checkpoint_at, 같은 run ID/revision을 유지한다. 완료/명시 중단/실제 실패만 terminal이다. 이전 실행의 기준 초과도 censored 하한 checkpoint로 기록하고 강제 만료하지 않는다.
- 최근30일·최대60회/256KiB 보호 통계에서 workload별 p50/p90·초과 초·초과 횟수·복구율을 분석한다. 종료 실측과 진행 중/중단 하한을 분리한다. 완료 초과 실측은25% 여유·10초 올림을 다음 기준에 반영하고 미완료 하한의 증가는 보수적으로 적용한다. 충분한 안정 완료5회 뒤 감소는10초씩이다.
- 전체300초/HTTP60회/검색4회 상한은 없다. 호출 수 p90×1.25+게시 계획12회, 검색 횟수·초과 관측·상세 복구율을 다음 참조에 반영한다. 단일 HTTP timeout과 NAS 회차 부하 제어는 유지한다. 비교 기준은 접수 때 고정하고 실제 초과를 정확히 남긴다.
- mon_execution_stats.json은 운영 이력 전용이다. 코드 개선/시험은 MON 시장 실행 표본에 섞지 않는다. 최신 관측3회는 미완료2회(183초 하한·290.10초)와 완료1회(878.32초)다. 이를 충분한 성공 표본의 p90로 쓰지 않는다. 다음 시간 기준1080/1090/1100초·검색9묶음은 계획 기준이며 종료 상한이 아니다. 실제 실행 때 검증된 최신 통계로 다시 산출한다. 통계는 결과 파일 쌍과 별도로 보호 저장/검증 병합하고 실제 원격 저장을 확인한다.
```
<!-- MON:INSTRUCTION:END -->


## NAS 연결을 완료할 때까지 복구 — v4.9 / 1.8.1

| 제어 | 첫 시도 | 실패 후 증가 | 적용 범위 |
|---|---|---|---|
| NAS 연결 대기 | 10초 | 20→40→60초, 이후60초 | 각 읽기 접속, TLS 검증 유지 |
| NAS 전체 응답 대기 | 20초 | 40→80→120초, 이후120초 | 한 요청, 전체 작업은 계속 |
| 재시도 간격 | 첫 실패 뒤30초 | 60→120→240→300초, 이후300초 | Retry-After가 더 길면 그 값 |
| 다음 실행 첫 대기 | 최소10/20초 | 성공 전체 소요 p90×1.5로 연결10~60초·응답20~120초 | 최근60회 성공 관측, 하한 유지 |
| 웹 조회 | 정상10초 | 응답 대기·재시도 간격 증가 | 상태/상세 단일 진행 요청, 숨김 때 예약 중지 |

전체 재시도 수와 총 경과시간으로 종료하지 않는다. 보호 상태와 통계는 `mon_nas-<run ID 해시>.php`·`mon_nas-connection-stats.php`에 메타데이터만 남긴다. 원문·키·로그인 입력을 넣지 않는다. `poll`은 긴 sleep을 하지 않으며 호출자는 `retry_in_seconds` 동안 다른 작업을 진행한다. 읽기 도중 처리 환경이 종료되면 원래 시도는 응답 미확인으로 보존하고 대기 뒤 새 읽기로 복구한다. 초기 연결 성공은 이번 실행의 접속 관측이며 후속 API 장애를 정상 상태로 숨기지 않는다.

실제 NAS는 2026-10-11 KST 확인에서 연결10/응답20초를 허용하자6.42초 후 HTTP200을 반환했다. Accept: application/json으로6.09초, 새 v1.8.1 PHP 클라이언트로7.71초 후에도 HTTP200/v1.5.0을 확인했다. 앞선1초 실패는 당시 timeout 관측으로 유지한다. 구버전의 실행 중·정상 heartbeat·장외 대기 응답은 새 시장 분석 완료나 v1.8.1 배포 증거가 아니다. 새 [mon.php](mon.php)를 NAS에 교체하고 웹 중지→수동 시작 후 버전을 확인해야 한다. 부팅 자동 시작 금지는 유지한다. [연결 설계·검증](mon_nas_connection_design.md)을 참조한다.

## 완료 우선 실행과 통계 기준 — v4.9 / 1.8.1

| 항목 | 처리 | 다음 실행의 기준 |
|---|---|---|
| 전체/단계 시간 초과 | 중단 없이 계속, time_excess_seconds에 초 기록 | 실측 p90·완료 초과×1.25, 미완료 하한은 보수 증가; 전체300초 종료 상한 없음 |
| HTTP/검색 횟수 초과 | 실제 전송/외부 보고를 계속 기록, count_excess에 초과 수 | 호출 p90×1.25+게시 계획12회, 실제 초과·검색 수 반영; HTTP60/검색4 종료 상한 없음 |
| 상세 시도 기준 초과 | retry_wait 유지·성공 체크포인트 재사용·원천 전환·대기 강화 | 복구율에 따라1~3은 대기 강화 기준이며 최대 시도 수가 아님 |
| 게시 timeout/응답 유실 | 영속 journal 재개·원격 확인, 실제 저장 전 성공 표시 금지 | expires_at는 참조; 반복 실패/병합 횟수로 만료시키지 않음 |
| 미완료 통계 종료 요청 | terminal=false·finished_at=null·checkpoint_at 기록 | 같은 run ID/revision, 진행 하한과 종료 실측 분리 |
| NAS 부하/개별 소켓 | 짧은 회차 후 대기 작업 보존·다음 회차 재개 | 상세1/8초·전체25초·시세2/6초·HTTP3/5초·수동 시작 유지 |

MON-ADAPTIVE-1.1의 limit_behavior=observe_and_continue다. 기존 limits/deadline_at/expires_at 필드는 호환용 비교 기준이며 강제 종료하지 않는다. mon_run_statistics는 보호 통계의 조회/검증 병합/내보내기, mon_run_report는 실제 외부 시간/횟수, mon_run_observe는 실제 표시 보고, mon_run_finish는 완료 또는 미완료 checkpoint를 제공한다. 통계는 workload별 최근30일·60회/256KiB이고 키/시세/원문은 넣지 않는다.

[운영 이력](mon_execution_stats.json)은 미완료2회·완료1회(878.32초)의3개 표본이다. 다음1080/1090/1100초·검색9묶음은 계획 기준이며 충분한 성공 표본의 p90나 종료 제한이 아니다. 이전 v1.8.0 [함수·복구 검증](tests/mon_completion_tests.json), [웹 HTTP 검증](tests/mon_completion_http_tests.json)은 합성 자료/가상 경과·로컬 PHP7.4 검증으로 이번에 반복·합산하지 않는다. 이번 NAS 복구 변경은 [연결20건](tests/mon_nas_connection_tests.json)·[웹 조회18건](tests/mon_nas_browser_tests.json)·[로컬 HTTP/CLI10건](tests/mon_nas_http_tests.json)의48 PASS/0 FAIL로 검증했다. [실제 NAS 접속 관측](tests/mon_nas_connection_observed.json)도 별도로 기록했다. NAS 실물 코드 교체·실부하·외부 시장 자료 수집·최종 채팅 전달까지의 정확한 시간은 이번 검증 범위 밖이다.

**세부 계약에서 과거 버전의 시간/횟수 종료 표현은 이 절과 v4.9 실행 블록으로 대체한다.** 데이터/신호/인증/원자 저장/수동 시작 규칙은 계속 적용한다.

## 1. 역할과 저장 위치

| 담당·파일 | 역할 |
|---|---|
| ChatGPT / mon 지시문 | 독립 후보 발굴·근거 확인·스윙 자격 판단·최종 자율 선정·매입/매도 판단 |
| mon.php | 기존 최소 시세 수집, 스윙 수치 계산, 작은 입력/변경 처리, 저장 파일 반환 |
| monitor.md | 최신 실행 지시문·기준·인터페이스·예외 규격 |
| mon_data.json | 관찰·보유·설정·PHP 시세·전체 후보·스윙 수치·결정 근거·압축 이력 |
| mon_result.md | 최신 결과 표 하나와 주요사항 최대3개 |
| [mon_wiki.md](mon_wiki.md) | 지연 원인·개선 대응·단계별 표시·운영 및 검증 기록. 선정 기준 권위는 monitor.md |

PHP는 KIS API를 우선하고 기존 Naver/Yahoo 대체 경로를 유지한다. `/web/broker_config.local.php`의 기존 KIS 키와 `sis_private_sync_config.php`의 GitHub 설정을 직접 참조한다. 키를 JSON·GitHub·결과에 복사하지 않는다. 별도 암호·폴더·CLI 조작을 사용자에게 요구하지 않는다.

수집 데몬의 자체 수집은 **collection만** 갱신하며 종목별 상세 응답도 collection에 쓴다. ChatGPT가 결정한 검증 파일 쌍의 게시를 명시적으로 요청하면 같은 데몬이 그 파일 쌍을 원자적으로 저장할 수 있다. PHP가 새 판단·추천·주문을 생성하지 않는다. 운영자 인증이 없거나 NAS에 접근할 수 없으면 기존 함수/커넥터의 검증 파일 저장 경로를 사용한다.

## 2. 스윙 자격과 수식

### 2.1 시장 대비 강도

\[
R20=C_t/C_{t-20}-1,\qquad RS20_{pp}=100(R20-R20_{market})
\]

같은 기간의 **21개 완료 종가**가 필요하다. 종목과 지수의 실제 거래일 배열이 일치해야 한다. 필수 날짜·종가가 부족하면 0이나 이전 값으로 채우지 않는다. 진행 중 일봉은 독립 완료봉 비교에 포함하지 않는다.

**RS20_pp>0**가 추천 자격이다. 시장이 하락한 경우 덜 하락한 종목도 이 조건을 만족할 수 있으므로 시장 상태를 설명한다. 종목의 R20 양수·RS20 +3%p·MA60 상승·RSI/MACD의 다른 설정을 추가 필수 문턱으로 합산하지 않는다.

### 2.2 MA5 장기 하회 후 재돌파

\[
MA5_t=\frac{C_t+C_{t-1}+C_{t-2}+C_{t-3}+C_{t-4}}{5}
\]

- 최신 완료봉 직전 **최소10거래일 연속** 각 종가가 **그날의 MA5보다 낮아야** 한다. 최신 MA5 한 값에 과거10종가를 비교하지 않는다.
- 최신 완료 종가가 최신 MA5보다 높으면 `MA5_RECLAIM_CLOSE`다. 15개 완료 종가로 최소 패턴을 계산할 수 있다. 시장 비교에는 별도로21개가 필요하다.
- 직전 완료봉까지10일 이상 연속 하회한 상태에서 검증된 당일 현재가가 `(직전4완료 종가 합+현재가)/5`보다 높으면 `MA5_RECLAIM_LIVE`다. 최소14완료 종가＋현재가가 필요하다.
- 등호는 하회도 재돌파도 아니다. 날짜 누락·종목 식별 불일치·정정·분할 단위 불일치는 미검증으로 남긴다.
- 장중 신호는 **잠정**이다. 종가 확정 신호와 구분하고 현재가가 다시 이평선 아래로 내려가면 진입 가능으로 표시하지 않는다.
- 최근 완료봉의 신호는 다음 완료봉을 준비할 때 다시 계산한다. 과거 재돌파를 무기한 현재 신호로 유지하지 않는다.

### 2.3 MACD(12,26,9)

\[
EMA_N(t)=\frac{2}{N+1}C_t+\left(1-\frac{2}{N+1}\right)EMA_N(t-1)
\]
\[
MACD_t=EMA12_t-EMA26_t,\quad Signal_t=EMA9(MACD)_t,\quad H_t=MACD_t-Signal_t
\]

EMA 초기값은 첫 N개 유효 입력의 SMA다. MACD의 EMA9는 유효한 MACD 값9개로 초기화한다. 자료의 최초 날짜·조정 방식·전체 값이 계산 입력 해시에 포함되므로 데이터 제공자·초기화 기간이 다르면 같은 수치라고 단정하지 않는다. 독립 원천에서 확보한 전체 이력을 동일하게 보존한다(기본 요청 6개월). 추천 종목만60개, 다른 후보는21개로 자르지 않는다. 최초 일자·봉 개수·가격 조정 방식·원문 해시를 `payload.history_metadata`에 저장한다. 수학적 계산 최소량과 원천 이력 검증은 별개다. 전체 원천 구간이 확인되지 않은 이력은 `needs_full_history`로 표시하고 MACD만으로 추천하지 않는다. 검증된 MA5 재돌파의 OR 자격은 유지한다. 배당 미조정/조정, KRX/NXT, 완료일이 다른 앱 수치와 같다고 단정하지 않는다.

| 신호 | 고정 조건 | 표시 |
|---|---|---|
| MACD_GOLD | 직전 H≤0, 최신 H>0 | 골든크로스 확정 |
| MACD_AFTER | 확인된 골든 이후1~3거래일, 그동안 H>0 유지 | 골든 후 N거래일 |
| MACD_BEFORE | 유효 H 3개가 엄격히 증가, 최신 H≤0, 최신 abs(H)/ATR14≤0.10 | 골든 직전(근접), 미확정 |

첫 시그널 값은34번째 완료 종가부터, 첫 교차 판정은35번째부터 가능하다. 최근3개 유효 히스토그램 근접 판정은36개 완료 종가부터 가능하다. 교차 후 기간에는 추가 유효 값이 필요하다. 시그널 EMA9의 갱신 계수는2/(9+1)=0.2이며 장중 보조 계산에도 동일하게 적용한다. 부족한 MACD는 null/needs_history로 표시한다. **MA5 조건이 검증되면 MACD 부족 때문에 OR 자격을 지우지 않는다.**

‘골든 전후’의 구체적인 범위는 이번 구현의 기본값이다. **전: 최근3개 히스토그램의 수렴＋ATR 대비10% 이내 / 후: 3거래일 이내**. 이 값은 미래 교차 예측이나 수익 보장이 아니다. 이후 변경하면 기준 버전과 프로필을 함께 바꾼다.

ATR14는 최근 TR14개의 단순평균이다. TR=max(고가−저가, abs(고가−직전종가), abs(저가−직전종가)). ATR 부족 시 골든 직전 근접 조건을 확정하지 않는다. 지수는 종가만으로 시장 비교가 가능하며 지수의 거래량·OHLC 결측을 종목 MACD 결측으로 오인하지 않는다.

### 2.4 최종 자율 선정

```text
추천 자격 = RS20_pp>0
          AND (MA5_RECLAIM_CLOSE OR MA5_RECLAIM_LIVE
               OR MACD_GOLD OR MACD_AFTER OR MACD_BEFORE)
          AND 동일 기간·신원·자료 검증
          AND 확인된 중대한 위험/거래불가 없음
최종 추천 = ChatGPT가 위 자격 집합에서 근거를 기록하고 자율적으로 선정
```

PHP의 `rank_hint`는 **RS20_pp↓→R20↓→ADV20↓→symbol_id↑** 참고 정렬이다. 자동 상위3추천이나 최종 종목 지정이 아니다. ChatGPT는 거래대금·업종 주도성·이격·추격 위험·신호의 질·기업 근거를 함께 보고 자격 종목 중 최대3개/국가를 선정할 수 있다. 추천하지 않은 적격 후보도 전체 후보 자료에 남긴다.

| 상태 | 의미 |
|---|---|
| pass | 최신 비교와 시장 강도 및 MA5/MACD 자격 충족. 최종 추천은 별도 |
| near | 확인된 자료에서 시장 강도 또는 스윙 신호 미충족. 관찰 가능, 추천 불가 |
| unverified | 필수 자료·최신 지수 비교·필요 신호 이력 부족. 과거 자격이 확인되면 자율 판단의 이전자료 참고로 표시 가능 |
| fail | 확인된 거래 불가·중대한 부정 사건. 신규 진입 제외, 보유 감시는 유지 |

추천 대상이 부족하면 확보된 수만 제시한다. 이전 추천·신규성·수집 순서·ready에 가산점을 주지 않는다. 모든 추천에는 `autonomous_decision.selector=ChatGPT`와 구체적 선택 이유를 저장한다.

## 3. 매입 계획·현재 가능 여부·매도

스윙 신호는 **추천 자격**, 가격 계획은 **진입 검토 범위**다. 두 역할을 혼동하지 않는다. 최신 신호가 없는 종목에 기존 ATR 가격 구간만 붙여 추천하지 않는다.

지지 S는 최신 완료 종가 이하의 MA5·MA10·MA20·최근5일 저점 중 최댓값이다. 저항 R은 최신 완료일을 제외한 직전20일 고가 최댓값이다.

| 계획 | 가격 구간 | 무효화 참고값 |
|---|---|---|
| 눌림 | S−ATR14 ~ S＋ATR14 | min(최근5일 저점,S)−0.5×ATR14 |
| 돌파 이후 검토 | R ~ R＋ATR14 | R−ATR14 |

실제 호가단위로 하단은 올리고 상단·무효화는 내린다. 값은 양수이며 무효화<하단≤상단이어야 한다. 필요한 값이 부족하면 계획은 null이다. 계획의 무효화는 등록된 실제 손절가를 자동 변경하지 않는다. 이미 R을 넘은 종목은 ‘돌파 이후’로 설명한다.

`ready`에는 스윙 자격 유지, 정규장, 같은 종목/통화/거래소/현지 날짜, 가격 나이≤300초, 확인된 지연≤300초, 미래 오차≤5초, 거래 가능·기업 위험 확인, 유효한 계획 구간 안의 가격을 모두 요구한다. 현재 가격이 없고 완료 종가만 있는 경우는 **조건부**다. 오래된 위험 확인과 과거 ready를 현재 확인 완료로 바꾸지 않는다.

보유 매도는 기존 유효 initial_stop/trailing_stop 중 높은 기준, MA20와 직전10완료일 저점 동시 붕괴, 확인된 중대한 기업 훼손에 따른다. 보유 수량·매입가·손절가를 추정 생성하지 않는다. 신규 신호 미충족만으로 자동 매도하지 않는다. 주문은 하지 않는다.

## 4. PHP 웹 운영과 지원 범위

`/web/mon.php`를 브라우저로 열어 **시작·중지·상태**를 사용한다. NAS 부팅·예기치 않은 종료 뒤에는 자동 재동작하지 않는다. 사용자가 웹 시작 버튼을 누른 때만 현재 부팅 ID·인스턴스에 묶인15초 일회용 권한을 발급하며, 실행 잠금을 얻은 작업자가 한 번 소비한다. 부팅 변경·기한 경과·재사용·중지로 취소된 권한은 거절한다. 부팅 ID를 확인할 수 없으면 시작을 보류한다. 옵션 없는 CLI는 상태 조회이고 기존 --daemon/--once 및 권한 없는 --run은 시작을 거절한다. GET·설정 저장·상태 갱신·게시 작업 등록은 시작 권한을 만들지 않는다. 예외 종료의 자동 재시작·새 watchdog/cron을 추가하지 않는다.

코드 교체 후 웹에서 중지→시작하면 새 버전이 적용된다. 중지는 실행 전의 시작 권한도 취소한다. PID만으로 실행을 판단하거나 다른 프로세스를 종료하지 않고 기존 단일 실행 잠금을 사용한다. 실제 상태 확인 없이 가동 여부를 선언하지 않는다.

기본 시세60초·미러180초·순환 커서·KIS 토큰 캐시·Naver/Yahoo 대체·충돌 재병합·중지 응답은 유지한다. 데몬 시세는 회차 최대2종목·전체6초·종목3초로 분할하며, 느린 한 종목도 다음 커서를 진행한다. 관찰목록을 잘라내지 않고 방문하지 않은 가격은 원래 시각으로 보존한다. 큰 목록은 전체 갱신 간격이 길어진다. 오래된 가격을 현재 ready로 올리지 않는다.

데몬에 nice +10과 PHP 메모리 최대96MiB를 적용한다. 더 작은 기존 PHP 한도는 올리지 않고, 적용 실패는 resources에서 공개한다. CPU 수당1분 load가1.25를 넘거나 여유 메모리가 max(32MiB, 전체10%) 미만이면 새 외부 조회·수집을30→60→최대120초 쉬며, 회복 기준(load≤CPU 수×0.9, 여유 메모리≥기준×1.25)을2회 연속 확인한 뒤 재개한다. 구형 커널은 MemFree+Buffers+Cached+SReclaimable−Shmem으로 여유 메모리를 근사한다. 측정 불가이면 이를 공개하고 고정 분할 한도를 유지한다. 각 회차 뒤 최소5초와 실제 CPU 사용시간×9의 유휴 시간을 확보한다. 10%는 목표이며 NAS 전체 CPU 사용률 보장이 아니다.

대기 중 GitHub 확인은 최소30초 간격이며 ETag를 받은 경우304로 원문 재수신을 생략한다. 같은 blob SHA의 큰 캐시를 다시 쓰지 않는다. 할 상세 요청이나 재시도가 없으면 원문 해제·상세 준비를 건너뛰고 장외 시세 체크포인트도 만들지 않는다. 대기 heartbeat·설정 변경 확인은20초 간격, 브라우저 상태 확인은10초 간격이며 숨긴 탭은 조회하지 않는다. 중지 확인은 짧은 대기 단위를 유지한다. 매분 ChatGPT를 호출하거나 새 추천을 생성하지 않는다.

과부하는 웹의 resource_paused/시스템 부하 대기로 표시한다. 진행 중 체크포인트·원래 자료시각·수집/게시 절대 마감을 보존한다. 새 자료 부족·시세 노후화·게시 만료가 발생할 수 있으며, 과부하 대기를 성공이나 저장 완료로 표시하지 않는다. 과부하 때 작은 게시 작업 상태의 만료만 확인하고 큰 artifact를 읽거나 외부 게시하지 않는다. ref 쓰기 가능성이 있는 만료 작업은 unknown으로 보존한다.

스윙 함수는 **저장된 검증 완료봉을 한 번의 함수 호출로 계산**한다. 내부 배열 순회는 필요하며 O(1)이라고 주장하지 않는다. 종목별 요청에 한해 KIS 우선, 검증 실패 시 Yahoo의 6개월 완료 일봉을 수집하고 대응 지수를 보충한다. 분할 기준 확인이 안 되는 KIS 해외 일봉은 추천 계산에 쓰지 않고 검증 가능한 대체자료를 사용한다. 일본 TOPIX 등 지수 대체 경로가 실패하면 이전 검증자료와 실패 상태를 반환한다. Naver는 기존 한국 시세 대체 경로이며 이 버전의 상세 일봉 자동 보충 경로로 구현되지 않았다. 모든 공급자 지원을 완료했다고 표시하지 않는다.

MON 분석 슬롯은 오전09:40 KST, 오후14:00 KST, 미국 개장30분 후10:00 America/New_York가 설계 기준이다. 예약 등록 상태와 슬롯 설계는 별개이며 이번 개정은 예약을 생성하지 않는다.

## 5. 저장·데이터 규격

`schema_version=3`를 유지하고 watchlist·analysis의 `criteria_version`을 모두 **MON-P3.1**으로 맞춘다. 새 PHP는 P2.0과 이전 P3.0(12·26·19) 데이터를 전환 준비 목적으로 읽지만 이전 추천을 P3.1 추천으로 표시하지 않는다. P3.0은 과거 프로필 검증·이력 보존만을 위한 호환 경로다. P2 전용 이전 데몬은 P3 자료를 처리할 수 없으므로 NAS PHP 교체가 필요하다.

```json
{
  "investment_style": "swing",
  "swing_profile": {
    "formula_version": "MON-SWING-1.1",
    "ma_period": 5,
    "min_below_days": 10,
    "macd_fast": 12,
    "macd_slow": 26,
    "macd_signal": 9,
    "macd_after_days": 3,
    "macd_before_hist_bars": 3,
    "macd_before_gap_atr": 0.10,
    "rs20_min_pp": 0,
    "rs20_operator": ">",
    "final_selector": "ChatGPT"
  }
}
```

위 표는 주요 설정 발췌다. 실제 프로필은 `MonSwing::rules()`의 전체 객체와 일치해야 한다. 다른 프로필을 같은 기준 버전으로 슬쩍 사용하면 MON_SWING_PROFILE_INVALID다.

| 객체·필드 | 저장 내용·작성 권한 |
|---|---|
| watchlist | 기존 종목 신원·수집 코드·실제 보유·설정·달력. MON 작성 |
| collection | 공급자·시세·시세시각·지연·거래 상태·실패 사유. PHP 작성 |
| analysis.candidate_audit | 전체 후보의 자격·실패 사유·수치/신호 참조. MON 작성 |
| 압축 payload.swing_signals | 모든 후보의 MA5 연속 하회 수·재돌파·MACD/시그널/히스토그램·골든 일자/경과·RS20·입력 해시 |
| analysis.results | ChatGPT가 최종 선정한 BUY_REVIEW/SELL_REVIEW/REFERENCE_REVIEW 및 보유 판단. 신호·자율 결정 이유 포함 |
| payload.strategy_archive | 이전 P2/P3.0 분석·추천·선정 사실·스윙 신호·프로필·해시. 과거 기준시각 유지 |
| payload.history_metadata | 전체 원천 구간 여부·최초/최종 완료일·봉 개수·입력 해시·가격 조정 방식·원문 출처·원래 확인시각 |
| analysis.swing_preparation | 수치 계산 완료 범위와 최종 자율 선정 대기/완료 상태 |

스윙 신호 값에는 `formula_version, bars_as_of, qualifying_signals, eligible, market_strong, ma5_below_days_before_last, ma5_reclaim_close, ma5_reclaim_live, macd_kind, macd_cross_date, macd_cross_age_days, histogram, macd_gap_atr, bar_input_sha256`를 보존한다. `source_kind=independent`의 검증된 실시간 관측만 장중 재돌파의 독립 입력으로 사용한다. PHP 시세를 독립 조사 사실로 재표기하지 않는다.

현재 거래·기업 위험에 직접 확인하지 않은 사실은 verified로 저장하지 않는다. 종목별 응답의 latest/reused_current는 완료 일봉·지수 자료 상태이며 현재가·현재 기업 위험의 확인 완료를 뜻하지 않는다. 지연·실패·미검증·자료 준비 대기는 별도 상태다. `preparation_running=false`는 백그라운드 작업이 실행되고 있지 않음을 뜻한다.

숫자는 소수6자리 ROUND_HALF_UP, 해시용 숫자는 소수6자리 문자열, 객체 키·식별자는 정렬, 시간은 UTC 마이크로초로 정규화한다. `selection_fingerprint`에는 자격 사실·기준·ChatGPT의 최종 선정 결정을 포함한다. `input_fingerprint`에는 고정 as_of·실제 사용 시세·자료 상태를 더한다. `result_fingerprint`에는 행동·가격 계획·스윙 신호·자율 결정 기록을 포함한다. 같은 고정 사실과 **같은 선정 결정**은 같은 업무 결과다. 자유로운 AI 서술이 매번 같은 판단을 내린다고 보장하지 않는다.

전체 JSON 상한은 **2,097,152바이트(2MiB)**다. 종목별 응답 최대30개·개별 압축20KiB를 위한 collection 예약공간을 **655,360바이트**로 설정한다. 웹 입력 상한은6MiB, 압축 해제 원문은 전체8MiB/개별256KiB다. 전체 일봉과 이전 신호·추천을 무손실 보존하기 위해 기존768KiB 상한을 조정했다. 크기 제한 때문에 특정 후보의 이력을 잘라 자격을 왜곡하지 않는다. 압축 원문은 SHA256/바이트 길이를 검증한다. P3 저장은 PHP 기본 gzip을 사용해 NAS 웹에서 xz 명령 실행에 의존하지 않는다. P2의 기존 XZ는 전환 때 가용 고정 처리기로 한 번 해제한다. 크기 부족 때만 근거 전문을 압축 인덱스로 무손실 이동한다. 이전 근거와 과거 추천을 지우지 않는다.

활성 가격 계획이 정해지지 않았으면 `active_plan=null, entry_zone=null, invalidation=null`이다. 눌림·돌파별 계획은 `entry_plans`에서 각각 표시한다. 서로 다른 계획의 진입·무효 가격을 혼합하지 않는다.

GitHub 파일 읽기는 크기에 관계없이 Contents API의 `Accept: application/vnd.github.raw+json`으로 원문을 한 번 받아 처리한다. 1MiB 초과 파일의 기본 응답은 Base64 내용을 제공하지 않을 수 있으므로 `encoding=base64`를 요구하지 않는다. 정확한 원문 바이트로 Git blob SHA를 계산해 수집 미러링 PUT의 충돌 검증에 사용한다. 쓰기 요청은 기존 JSON 미디어 형식·인증·브랜치를 유지한다. 크기 제한·JSON/스키마·HTTP 오류 처리는 유지한다.

## 6. 고정 인터페이스

계산·변경 API는 **POST JSON**이며 기존 고정 입력/파일 반환 경로와 호환된다. 진행 조회는 GET이다. 새 실행·artifact·게시 API는 인증된 보호 상태를 사용한다. `publish_mode=queue/inline`로 요청한 파일 쌍만 원격 게시한다. source_json은 원문 문자열이며 Git blob SHA가 실제 바이트와 일치해야 한다. 응답의 큰 파일 문자열을 모델 문맥에 출력하지 않고 저장 도구로 전달한다.

| 웹 API / 고정 함수 | 목적 |
|---|---|
| `?api=mon_detail_request` / MonDetails::register | 개별 요청 한 종목 등록, mon_data.json만 반환; 같은 ID 재전송은 reuse |
| `?api=mon_detail_requests` / MonDetails::registerMany | 독립 items 최대30개를 한 번 전송, 종목별 queued/reuse/rejected와 mon_data.json 한 파일 반환; 일부 오류가 성공 종목을 막지 않음 |
| GET `?view=progress&since=<snapshot_token>` / MonProgress | 로컬 요청/응답의 작은 진행 카드. 같은 토큰이면 cards=[]; 전체 원자료 해제·수집·저장·선정 없음 |
| `?api=mon_detail_view` / MonDetails::view | 요청별 완료/실패/이전자료·짧은 지표 카드, 먼저 완료된 종목 즉시 검토 |
| `?api=mon_detail_prepare` / MonDetails::prepare | 일치하는 개별 응답과 기존 자료 병합, 전체 자격/과거 참고 카드; 자율 선정 없음 |
| `?api=mon_detail_select` / MonDetails::select | ChatGPT의 selected+references 검증과 최종 두 파일 반환 |
| `?api=mon_detail_finalize` / MonDetails::finalize | selected/references와 독립 근거·현재가·위험을 한 번 확정; 인증된 매니페스트·선택적 게시 또는 기존 inline 파일 반환 |
| `?api=mon_run_start/status/observe/report/finish/statistics` / MonExecution·MonRunStatistics | 원래 접수·정책 고정, 실제 시간/횟수·오류·미완료·통계와 다음 정책 |
| `?api=mon_discovery_plan` / MonDiscovery | 요청 국가·신규/이전 기회 배정, 활성30개와 남은 후보를 구분; 자동 선정 없음 |
| `?api=mon_artifact_read`, `?api=mon_card_read` / MonArtifact | 고정 파일 청크·초대형 카드의 인증된 원문 읽기와 해시 검증 |
| `?api=mon_publish_queue`, `?api=mon_publish_process` / MonPublication | 영속 파일 쌍 접수, 원자적 Git 저장과 확인된 실제 ref 영수증 |
| `?api=mon_view` / mon_project_for_mon | 저장된 선택·가격 계획·현재 상태·중요 사건의 작은 투영. 전체 후속 페이지 확인 |
| `?api=mon_swing_prepare` / MonSwing::prepare | 전체 후보의 새 스윙 자격·수치·참고 순위 계산. 최종 추천 없음 |
| `?api=mon_swing_migrate` / MonSwing::migrate | P2/P3.0→P3.1 전환 또는 검증 이력 보충, 이전 추천·신호 보존, 새 자격 계산, 선정 대기 상태와 파일 반환 |
| `?api=mon_swing_select` / MonSwing::select | ChatGPT의 작은 최종 선정 결정을 검증하고 두 저장 파일 반환 |
| `?api=mon_patch` / mon_apply_mon_patch | 선정·계획을 바꾸지 않는 가격/위험 변경 또는 실제 변화 없는 reuse |

전환 요청은 `history_updates`를 선택적으로 받는다. 형태는 `{symbol_id:{bars:[[date,open,high,low,close,volume],...],source_url,checked_at,price_basis,provider_window:"6mo",source_record_sha256}}`다. 독립 원천의 전체 구간을 준비 단계에서 전달하며 확인시각을 현재시각으로 고쳐 쓰지 않는다. 기존 날짜의 모든 가격·거래량과 마지막 완료일이 일치해야 한다. 충돌·누락·이력 축소·미래시각은 예외로 중단하고 기존 자료를 보존한다. 정상 MON 실행에서 이 보충을 반복하지 않는다. `history_metadata` 해시·개수·시작/종료일·가격 기준이 불일치하면 MACD 자격으로 사용할 수 없다.

### 6.1 준비 요청·응답

```text
요청: {source_json, context:{source_blob_sha,rendered_at,countries,important_ids},
       live_observations?:[독립 검증 시세 행]}
반환: criteria_version,formula_version,state_token,prepared_fingerprint,
      candidate_count,counts,rank_hint,cards,additional_card_pages,
      card_pages,artifact_cards,preparation,prepare_reused,
      auto_selected:false,final_selector:ChatGPT
```

짧은 카드와 모든 후속 카드 페이지를 함께 읽는다. `card_pages`를 우선하며 호환용 `cards/additional_card_pages`와 중복해서 읽지 않는다. 참고 카드는 `reference_card_pages`, 요청 상태는 `detail_status.card_pages`다. 각 페이지는 같은 state_token/prepared_fingerprint의 일부이며 실제 UTF-8 12,288바이트 이하다. 초대형 카드/헤더는 원문 해시 참조로 넘기고 절단하지 않는다. `response_mode=manifest`는 인증 후 중첩된 artifact 본문까지 보호 파일 참조로 바꾼다. 모든 후보의 실제 계산 결과는 내부 full_signals에 유지하며 모델에 원문 전체를 출력하지 않는다.

실시간 관측 행은 symbol_id·country·currency·venue·price·price_as_of·price_type(last/minute_close)·price_checks(지연, 현지 날짜, trade/bar_end 기준, 정규장)·source_kind=independent·evidence_ids를 포함한다. 종목 국가·거래소·통화가 일치하고 근거 참조가 있어야 하며, 나이·지연·정규장 검증을 통과하지 못하면 장중 재돌파에 사용하지 않는다. 장중 신호로 선정하면 사용한 현재가와 시각을 결과와 입력 해시에 보존한다.

### 6.2 자율 선정 결정

```text
{source_json,
 decision:{view_context,state_token,prepared_fingerprint,fixed_as_of,
           selected:[{symbol_id,reason,watch_symbol?:검증된신원·수집코드}],
           live_observations?:[준비시와 동일한 관측]}}
```

준비 입력 토큰·전체 자격 해시가 일치해야 하며 selected에는 적격 종목만 최대3개/국가를 넣는다. 모든 적격 후보를 카드와 후속 페이지로 제공하며 관찰 수집 한도10개 때문에 후보를 숨기지 않는다. 참고 정렬 상위3개를 자동 생성하지 않는다. 이유가 없거나 중복·보유분 중복·미검증·신호 없음은 거절한다. 새 관찰 종목이면 검증된 watch_symbol 신원·수집 코드를 함께 제공한다. 부족하면 자료 준비 필요를 반환한다.

선정 함수는 현재 기업 위험·현재 거래 상태를 자동 verified로 만들지 않는다. 반환된 추천은 기본적으로 조건부이며 새 `mon_detail_finalize.confirmations`에 독립 근거·현재가·위험을 함께 전달해 한 번 보강한다. 기존 `mon_patch`도 호환된다. 보유분은 새 진입 자격과 무관하게 보존한다. 준비 이후 시장 완료일이 바뀌면 새 준비가 필요하다.

### 6.3 파일 반환·저장

`files=[{path:mon_data.json,content,blob_sha},{path:mon_result.md,content,blob_sha}]`, validation에는 기준·세 해시·압축 검증·collection 보존·저장 크기를 넣는다. 두 파일을 같은 Git 커밋으로 반영한다. 원문 SHA·토큰·해시·자료 기간·소유권·크기 오류이면 쓰기하지 않는다.

동시 수집 충돌은 최신 collection만 병합하고 watchlist/analysis가 바뀌면 다시 읽어 판단한다. 수집 데몬의 GitHub 충돌 재시도는 기존 최대2회를 유지한다. ChatGPT 최종 저장의 새 소스 재병합은 필요한 경우1회로 제한한다. 완료된 독립 확인·선정은 같은 고정 입력에 대해 반복하지 않는다. 실제 변화 없는 재표시는 기존 as_of를 유지하고 저장하지 않는다.

## 6.4 종목별 요청·응답 계약 — MON-DETAIL-1.0

실행 순서는 **ChatGPT 자율 후보 → 개별 요청 저장 → PHP 개별 자료 응답 저장 → 성공 자료부터 검토·제한 대기 → ChatGPT 최종 자율 선정 → 결과 저장**이다. 전체 세트 완료 상태나 batch_id가 없다. 같은 파일에 여러 개별 요청을 저장해도 요청·실패·재시도·자료 사용은 독립이다.

| 위치/필드 | 작성자·의미 |
|---|---|
| watchlist.detail_requests[symbol_id] | MON. contract, request_id, request_hash, symbol_id, symbol, criteria_version, requested_at, deadline_at, target_completed_date, target_verified, force_refresh |
| collection.detail_responses[symbol_id] | PHP. 위 연결키, state, data_status, attempts, completed_at, retry_after, error, conflict, usable, bars_as_of, source_checked_at, partial_fields, payload |
| payload.detail_imports / detail_plans | MON 고정 처리기가 실제 사용한 PHP 자료의 연결키·출처·자료 날짜와 가격 계획을 보존 |
| analysis.results[].data_status / signal_as_of | 결과의 자료 상태와 신호 계산 완료봉 날짜. 과거/현재를 구분 |

요청 웹 폼은 `{source_json,context:{source_blob_sha,rendered_at,countries,important_ids},item:{request_id,symbol,wait_seconds:120,force_refresh:false}}`다. symbol은 기존 watchlist의 검증된 신원·source_codes·tick_size 형태이며 is_held=false다. 요청 해시는 고정 함수가 만든다. 동일 ID의 종목/내용 변경은 DETAIL_REQUEST_ID_REUSED다. 새 ID는 이전 응답을 무효화한다. 완료/만료된 가장 오래된 요청부터 교체할 수 있어30개 한도가 계속 누적되지 않는다.

v4.7의 권장 등록은 `?api=mon_detail_requests`에 같은 source_json/context와 `items:[개별 item,...]`를 한 번 전송하는 방식이다. wait_seconds는 시작에 고정한 adaptive_policy.limits.collection의 원래 접수 기준 잔여시간 이하로 설정한다(명시 normal은+100초). 인증된 run_context_id가 있으면 서버도 요청 deadline을 이 마감으로 제한한다. 기본 API 호환값120초와 실행 전체 마감120초를 혼동하지 않는다. 동일 전송 안의 중복 symbol_id는 DETAIL_SYMBOL_DUPLICATE로 격리한다. 요청 국가 밖의 종목은 거절한다. 반환 파일은 ChatGPT가 한 번 저장하며 PHP 등록 함수는 원격 쓰기를 하지 않는다.

개별 조회/준비 폼은 `{source_json,context,live_observations?:[독립 검증 시세]}`다. 조회는 작은 상태·지표 카드만 반환한다. 준비는 기존 준비 응답에 `reference_cards, detail_status`를 더한다. **PHP 자료는 source_kind=php_auxiliary로 유지**하며 기업 확인·독립 판단을 대신하지 않는다.

최종 저장 폼은 `{source_json,decision:{view_context,state_token,prepared_fingerprint,fixed_as_of,selected:[{symbol_id,reason}],references:[{symbol_id,reason}],live_observations?:[...]}}`다. current selected에는 최신 자격만 허용한다. references에는 과거 동일기간 RS20>0과 MA5/MACD 신호가 검증됐으나 최신 비교가 부족한 종목만 허용한다. 두 목록을 합쳐 국가당3개이며 중복/보유분 중복을 거절한다. 참고 행동은 REFERENCE_REVIEW, readiness=reference, 현재 진입 확인=false다.

| 응답 자료 상태 | 사용 방법 |
|---|---|
| latest | 확인된 최신 완료 거래일의 종목·지수 자료. 현재 진입은 별도 확인 |
| reused_current | 같은 최신 완료 거래일의 이전 검증자료 재사용. 휴일 경과만으로 낡은 신호라고 하지 않음 |
| previous_verified | 최신 완료봉/지수가 부족하거나 조회 실패. 원래 검증시각과 일봉을 보존해 재검토 |
| no_data | 활용할 검증자료가 없음. 값·신호를 만들지 않음 |
| previous_conflict_reference | 새 공급자 가격/거래량과 저장 기준 충돌. 현재 추천 자격 보류, 과거 기록으로만 참고 |

`state=retry_wait/done`와 `data_status`는 별개다. 첫 실패 뒤에도 usable 이전자료를 즉시 활용할 수 있다. 자료 신선도는 원래 source_checked_at와 bars_as_of, 거래달력의 마지막 완료일로 판정한다. collection.completed_at나 응답 completed_at를 일봉의 새 검증시각으로 사용하지 않는다. target_verified=false면 현재 자격 확인으로 승격하지 않는다.

partial_fields.stock은 개별 가격 수집 성공과 지수 실패를 함께 표시한다. 최신 가격으로 오래된 MACD/RS20를 재계산한 것처럼 표시하지 않는다. 날짜·기준이 맞는 기존 지수는 재사용할 수 있다. 분할/가격 기준 충돌은 마지막 정상 캐시를 덮어쓰지 않는다. 해시·압축 오류도 그 종목의 실패로 처리하고 다른 종목을 계속 확인한다.

NAS 데몬은 상세 처리 최대8초·회차당1종목·전체25초로 제한한다. 종목당 최대8초이며 KIS 인증·원천 대체·지수 조회도 그 종목 예산을 공유한다. 직접 상세 함수의 기본3종목·25초 설정은 데몬의 상한과 구분한다. 응답마다 로컬 체크포인트를 기록하며 첫 응답을 우선 미러하고 후속 빠른 응답은 최대3개씩 묶어 저장한다. 묶음 시간 기준은 기본3초이나 작업 사이에서 확인하므로 다음 작업의 최대8초가 추가될 수 있다. 회차 종료 전 남은 응답도 반영한다. 일반180초 미러 주기는 기다리지 않는다.

현재 응답이 없는 대기 요청이 있으면 다음 회차 기준을5초로 단축하되, 회차 종료 뒤 최소5초 유휴·CPU 사용시간×9 대기·과부하 대기가 우선한다. 새 원격 요청을 찾는 대기 중 조회는 최소30초 간격이다. 장중 시세 수집60초 기준은 유지하며 매회 최대2종목을 순환 수집한다. 검증된 원문 blob SHA가 있으면 첫 PUT에 재사용하며, 409/422 충돌 때만 최신 원문을 재조회한다. 충돌 재시도에서도 최초 제안의 상세 전용 의미를 보존해 최신 시세·요청·ChatGPT 판단을 덮어쓰지 않는다. 변화 없는 쓰기는 생략한다.

반영 실패 자료는 로컬 대기 파일과 정상 캐시에 보존하고 새 요청 연결키가 일치할 때만 재반영한다. 그 회차의 저장 실패를 반복 호출하지 않으며 다른 종목의 로컬 자료 처리는 계속한다. 고정 소스의 전체 이력은 처리 회차/조회당 한 번만 JSON 해제해 읽기 전용으로 재사용한다. 동일 회차의 지수 조회 메모와 기존 KIS 인증·시세 캐시는 유지한다. 정상 완료봉은 불필요하게 재수집하지 않는다.

### 6.5 단계별 진행 규격 — MON-PROGRESS-1.0

진행 응답에는 phase, counts, snapshot_token, changed, cards, source_blob_sha/source_fetched_at, publication을 포함한다. phase는 idle/collecting/deadline/collection_complete이며 ChatGPT의 최종 판단 완료를 뜻하지 않는다. cards는 원문 이력 없이 개별 요청 연결키·완료/재시도/마감·자료 날짜·원래 검증시각·오류·저장 대기를 표시한다. quote collection_id와 조회시각만 바뀌면 토큰은 변하지 않는다. usable_reported는 생산자 표시이며 최종 상세 검증을 대신하지 않는다.

웹 화면은 완료/전체·대기·재시도·이전자료 수와 종목별 상태를 표시한다. 로컬 응답의 publication=pending은 아직 GitHub source에 들어오지 않은 자료이므로 그 source SHA로 새 수치를 검증 완료한 것으로 쓰지 않는다. 기존 mon_detail_view/prepare/select의 해시·신원·자료·자격 검증은 유지한다. 상태 파일 stage_seconds(source_read/detail_work/detail_publish/quotes/quote_publish)는 PHP 회차 측정이며 ChatGPT 전체 처리시간과 별개다. 초기/중간/최종 표시와 실측 기록의 운영 규칙은 [mon_wiki.md](mon_wiki.md)를 따른다.

자동 생성 상태 파일은 mon.php와 같은 폴더에 두며 별도 폴더 설정을 요구하지 않는다. 새 코드 다운로드·GitHub 반영과 NAS 교체·재시작은 구분한다. NAS 실가동 및 실제 KIS 수집은 운영 상태로 따로 확인한다.

### 6.6 보강 실행·통합 확정·게시 계약

`GET ?view=bootstrap`은 운영자 인증 없이 기존 로컬 캐시의 잠정 결과만 반환한다. 최대8KiB 카드 페이지로 반환하므로 일반 JSON 응답은12KiB 안에 들어간다. 다음 페이지는 첫 응답 header.rendered_at을 at으로, snapshot_token을 since로, next_cursor를 cursor로 전달한다. at은 현재보다 최대5초 미래/60초 이전이며 다른 snapshot의 페이지를 섞지 않는다. 지표 원문·현재 ready·새 선정 검증을 제공하지 않는다. source_validation=schema_only_previous_record다. `POST ?api=mon_bootstrap`은 기존 source_json/context의 정확한 blob SHA를 확인한 같은 작은 표시이며 NAS 운영자 인증 없이 기존 커넥터 입력 경로를 지원한다.

시작 응답의 initial_result·remaining_seconds·next_action을 사용한다. 원래 판단 마감이 지났으면 display_secured_result이며 전체 원문 준비나 신규 선정으로 다시 시작하지 않는다. 초기 정보 확보와 신호/자격 전체 검증을 분리한다. PHP 고정 함수 MonDetails::prepare/finalize도 활성 실행의 동일 판단 마감을 호출 시작/종료에서 검사한다.

종목당 기본8초에서 종목 원천 약5.2초·지수 약2.8초를 배정한다. 첫 KIS 종목 원천은 약2.34초까지만 사용해 Yahoo 시간을 남긴다. 실제 응답이 빠르거나 검증 저장 지수가 있으면 남은 원래 예산을 활용한다. 회차 전체 마감과 spacing을 포함하며 취소 불가 원천의 실제 초과를 감추지 않는다. 원천 장애 차단은 기존 MonHttp 인스턴스의 단조 시계로 동작한다. 데몬 재시작 때 재설정되며 NAS 자체의 접속 장애를 해결했다고 주장하지 않는다.

성공한 종목 원문은 기존 상태 폴더의 보호 PHP 체크포인트 한 개/종목에 저장한다. 원래 request_hash·신원·목표 완료일·source digest·원래 deadline과 가격 조정 기준이 일치해야 재사용한다. 새 요청/해시 손상/기한 만료이면 다시 검증한다. 지수만 부족하면 partial_fields.recovery에 missing_fields/resume_stage와 원천 오류를 남기며 가격을 과거 RS/MACD에 합성하지 않는다. 같은21일의 검증 저장 지수는 원래 검증시각을 유지한다.

new_analysis_complete는 서버가 실제 통합 확정/검증 무변경을 처리한 경우만 true다. analysis_origin은 previous_saved_result/secured_data_decision/revalidated_unchanged_decision을 구분한다. 표시 보고로 분석·게시 완료를 올릴 수 없다. 기존 결과 재표시·원격 파일 미수정만으로 fully_complete나 검증된 no_change를 선언하지 않는다.

`mon_run_start` 입력은 `{run_id,received_at?,mode:"auto"|"normal"|"extended",workload?,extension_reason?}`다. 기본 auto/workload=mon이며 현재 통계 SHA/세대/한도도 원래 접수에 고정한다. 같은 run_id의 접수시각·모드·사유는 불변이다. received_at가 빠지면 first_control_at 기준으로 시작하되 partial_timing=true다. `mon_run_status/observe`는 run_context_id를 사용한다. observe는 실제 표시 보고, 접근 미확인, 마감 초과만 받으며 완료를 꾸며내지 않는다.

run_context_id가 있는 계산·요청은 같은 보호 실행 상태를 재개한다. 문맥은 source SHA·UTC 마이크로초·정렬된 국가·중복 제거한 중요 ID로 발급/검증을 통일한다. 수치 캐시는 이력·지수·동일 비교 날짜·규칙·신원·가격 조정·호가·캘린더·목표 완료일·검증된 장중 관측을 키로 사용한다. 시세 collection ID/표시시각만 변경되면 전체 수치를 다시 준비하지 않는다. live 시세의 유효기간과 현재 진입 조건은 매번 다시 검사한다.

```text
POST ?api=mon_detail_finalize
{source_json,
 decision:{view_context:{source_blob_sha,rendered_at,countries,important_ids,run_context_id?},
   state_token,prepared_fingerprint,fixed_as_of,selected:[{symbol_id,reason,watch_symbol?}],
   references:[{symbol_id,reason}],live_observations:[],
   confirmations:{new_evidence:[{id,source_kind:"independent",source_url,claim,checked_at,...}],
     changes:[{symbol_id,price_observation?,risk_review?,reason?,next_check?}]}},
 response_mode:"manifest"|"inline",publish_mode:"manual"|"queue"|"inline",expected_head?}
```

manifest는 운영자 인증과 run_context_id가 필요하다. manual은 검증 파일을 보호 저장하고 원격 쓰기를 하지 않는다. queue는 영속 접수만 반환하고 기존 데몬이 회차 안에서 게시한다. inline은 단일 웹 작업 회차에서 시도하고 미완료 작업은 journal에 남겨 다음 회차/고정 process로 계속 재개한다. adaptive_policy.limits.publication/recovery가 지나도 작업을 만료시키지 않는다. response_mode=inline은 기존 두 파일 본문 반환이며 새 게시 작업을 만들지 않는다. `expected_head`는 대상 stock/main의 실제40자리 SHA이며 queue/inline 게시에 필수다.

price_observation은 symbol_id·통화·거래소·가격·quote_at·price_type·timestamp_basis·delay_seconds·market_date·session·source_kind=independent·evidence_ids를 포함한다. risk_review는 status·trading_status·checked_at·applies_as_of·scope·pending_items·confirmed_material_adverse·evidence_ids를 포함한다. 확인되지 않은 기업/거래 위험을 verified로 전환하지 않는다. 근거 ID·원문 URL·확인시각과 관측의 연결을 검사한다. 동일한 확정 decision/source의 재전송은 artifact를 재사용하며 변경된 decision은 MON_FINAL_DECISION_IMMUTABLE이다.

manifest에는 정확한 두 파일의 path·bytes·sha256·blob_sha와 source_blob_sha·prepared_fingerprint·artifact_id가 있다. `mon_artifact_read` 입력은 `{artifact_id,path,offset:0,length:65536}`다. path는 mon_data.json/mon_result.md만 허용한다. 반환 base64의 청크 hash를 확인하고 바이트를 먼저 연결한 뒤 UTF-8로 해석한다. 전체 bytes/SHA256/Git SHA를 다시 확인한다. `mon_card_read`는 카드 SHA256만 받는다. 청크·카드 원문을 모델 문맥으로 출력하지 않는다.

`mon_publish_queue`는 `{run_context_id,artifact_id,expected_head}`, process는 `{run_context_id,job_id}`다. 자기 실행의 artifact만 접수하며 같은 파일 쌍·head·decision은 같은 job_id다. blob2개→tree→commit→현재 head 확인→force=false ref→최종 ref/파일 쌍 확인을 수행한다. 게시 전에 collection만 달라지면 실제 원문/소유권을 검증하며 계속 병합하고 늦은 상세 응답은 pending_analysis로 남긴다. 판단/설정 변경은 conflict다. ref 응답 유실은 원격 확인을 계속하고 확인되지 않은 상태에서 무조건 재게시하지 않는다. ref 갱신 전에 명시적401/403/404 거절이면 failed다.

새 상태/청크/게시 API 인증은 서버가 검증한 `REMOTE_USER`와 MON_OPERATOR_USER 일치 또는 길이24자 이상 MON_OPERATOR_TOKEN을 `X-Mon-Operator-Token`으로 전달하는 방식이다. PHP_AUTH_USER만으로 권한을 인정하지 않는다. 미설정은503 MON_OPERATOR_AUTH_UNCONFIGURED, 불일치는403이다. 인증값은 응답·로그·GitHub에 저장하지 않는다. 기존 운영자 인증을 사용할 수 없으면 기존 stateless/커넥터 경로를 사용하며 일반 실행을 인증 설정 요청으로 중단하지 않는다.

보호 run/artifact/card/publish/receipt/prepare 상태는 같은 폴더의 `mon_*.php`에 실행 차단 접두부와0600 권한으로 저장한다. 한 작업의 journal 손상은 격리해 다른 작업/수집을 막지 않는다. 프로세스 내 경과는 단조 시계로 측정하며 재개 때 저장된 마지막 시각보다 시계가 크게 후퇴하면 게시를 중단하고 MON_CLOCK_REWIND를 남긴다.

진행 페이지는 실제 JSON 본문4KiB 이하, cursor는 해당 snapshot_token과 함께 사용한다. 초대형 상태는 기존 source/pending의 해시 참조로 보존한다. 상세 원문 검증은 지표뿐 아니라 기술 신호·진입 계획·전체 이력 메타정보·자료 날짜를 재계산해 대조한다. 서로 다른 원문/가격 조정/실제 OHLCV 값은 작은 차이도 충돌이다. 같은 불변 원문 레코드·종목·날짜·SHA와 명시적 반올림 유도식이 모두 증명된 표시 차이만 허용하며 계산에는 원래 값을 쓴다. 전체 충돌 진단은 보호 artifact로 보존한다.

신규 집계는 서버가 최초 등록에 고정한 first_seen_at·candidate_group, 해당 시장 거래일, 독립 신원 근거의 name/country/symbol/venue/currency 매핑으로 확인한다. 오래된 new_in_mon이나 일별 중복으로 목표20개를 채우지 않는다. 최초 발견의 신원 확인·상세 확보·수치 적격은 각각 고유 ID 누적 집합으로 기록한다. 확인 범위 밖 국가와 미달 목표는 그대로 표시한다.

## 7. 오류·예외와 결과 표시

| 상황 | 처리 |
|---|---|
| 10일 연속 조건이9일 또는 중간에 MA5 이상 종가 존재 | MA5 자격 미충족. MACD 조건은 별도 계산 |
| MACD 계산 이력 부족/전체 원천 이력 미검증 | needs_history/needs_full_history. 확인된 MA5 OR 신호만 사용 가능 |
| 골든 이후4거래일 이상·중간 데드크로스 | MACD_AFTER 미충족. 다른 신호가 없으면 추천 불가 |
| 골든 전 히스토그램 감소·간격 과대·ATR 없음 | 골든 직전 자격 미충족/검증 필요 |
| 같은 기간 시장 지수 없음·거래일 불일치 | 최신 비교 미검증, 이전 추천 자동 승계 금지 |
| 휴장·장외·오래된 시세·지연 불명 | 완료봉 자격과 가격 계획은 설명, 현재 ready는 금지 |
| 확인된 악재·거래 불가 | 신규 진입 제외, 보유의 별도 판단 유지 |
| 소스/토큰/압축/업무 해시 오류 | 쓰기 보류와 실제 오류 코드 |
| MON_COLLECTION/DECISION/PUBLICATION_DEADLINE | 새 단계 호출 중단, 원래 판단 또는 자료 부족·실제 저장 상태 표시 |
| MON_PERIOD_CHANGED_PREPARATION_REQUIRED | 시장 완료일 변경, 같은 준비/토큰으로 현재 선정 금지 |
| MON_CLOCK_REWIND / MON_PUBLICATION_JOURNAL_INVALID | 게시 중단·손상 작업 격리, 원문 보존과 오류·다음 확인 표시 |
| ref 응답 유실·확인 실패 | unknown; 저장 완료·자동 재게시 금지 |
| 새 기준에 적격 후보 없음 | 추천 없음과 실제 부족/미충족 사유를 표에 표시 |
| NAS 이전 버전 가동 | 코드 교체·웹 재시작 필요. 새 기능 실가동으로 오인 금지 |

mon_result.md는 기준시각·상태 한 줄, **국가/종목/판단/자료 기준/가격 조건/핵심 이유** 표 하나, 주요사항 최대3개다. `MACD_BEFORE`는 골든 확정으로 쓰지 않는다. 실시간 MA5는 잠정으로 쓴다. 추천 확정 대기와 추천 없음, 데이터 준비 필요를 구분한다.

## 8. 검증·개정 이력

- **v4.4 / mon.php 1.6.0 / 보강 D1.1 (2026-10-11)**: 원래 접수 마감·단조 시계, 정규화 토큰, 보호 실행/수치 캐시, 12KiB 카드·4KiB 진행 페이지와 원문 artifact, 선정/근거 통합 확정, 최초 발견·신원 기반 신규 집계, 원문 충돌 진단, 기존 데몬의 원자적 파일 쌍 게시·재개·실제 영수증을 구현했다. 현재 표시 재검증과 no_change, pending_analysis, failed/expired/unknown을 구분한다. 변경 검증 **실제 PHP7.4.33 함수·게시 모의75건＋실제 로컬 HTTP·DOM 모의30건=105 PASS / 0 FAIL**. 외부 API/주문0. 정상 지표의 기존 전체 PASS는 반복하지 않았다. [구현 보고](mon_reinforcement_implementation.md)와 원시 결과에 범위를 남긴다. NAS 교체·재시작·실제 KIS/Git 접속·ChatGPT120초 완료는 아직 확인하지 않았다.

- **v4.3 / mon.php 1.5.0 (2026-10-11)**: 독립 요청 묶음 등록, 회차당3종목·종목당8초, 고정 payload/지수 메모 재사용, 매 종목 로컬 보존과 발행 묶음, 정확한 SHA의 첫 PUT·충돌 시 최초 제안 재병합·무변경 쓰기 생략, 만료 재시도 정리, 작은 진행 토큰·웹 종목별 표시·PHP 단계 시간 측정. 지시문은 초기/변경 중간/최종 기본3회와100초 수집＋20초 마무리를 적용한다. 선정 MON-P3.1·MACD(12,26,9)·schema3은 유지했다.
- 변경 검증 **함수/연계41＋웹 HTTP16＋UI6 = 63 PASS / 0 FAIL**, PHP8.3.6 실제 로컬 HTTP 및 PHP7.4.33 문법 검사. 외부 API/주문 없음. NAS 새 버전 배포·KIS 신규 수집·MON 전체 처리시간은 미검증. 범위·모의 측정·적용은 [mon_wiki.md](mon_wiki.md)에 기록한다. 아래 기존 성적과 운영 확인은 당시 버전의 이력이며 이번 성적으로 재사용하지 않는다.

- v3.9 / MON-P2.0은 이전 강도 중심 기준이며 과거 커밋과 strategy_archive에 보존한다. 이전52건·93건 PASS는 새 스윙 기준의 검증 성적으로 재사용하지 않는다.
- v4.0 / MON-P3.0: 사용자의 스윙·MA5 장기 하회 후 재돌파·MACD 골든 전후 요청 반영. MACD 정정 **12·26·19** 적용. 최종 선정 권한은 ChatGPT에 유지한다.
- 새 기준 검증은 10일/9일 경계·등호·장중 MA5·MACD 기간/초기화/골든 전후·OR·시장 강도·최신 비교·잘못된 추천 거절·이력/보유/collection 보존·웹 입력/토큰/해시/크기만 대상으로 한다. 정상 수집 기능을 불필요하게 재개발하거나 모든 과거 시험을 반복하지 않는다.
- 이전 v4.0 구현 검증(12·26·19, 새 버전 성적 아님): **함수66건＋웹 HTTP19건, 총85건 PASS / 0 FAIL**. EMA12·26·시그널19는 독립적인 유리수 가중합 결과와 비교했다. 잘못된 통화·거래소·근거 없는 장중 시세, 시장과 동등/약세인 종목, 관찰 수집 한도 밖 후보 제공도 확인했다. 이 성적은 NAS 교체·운영 확인을 뜻하지 않는다.
- 배포와 전체 MON 처리시간은 실제 실행 확인 후 별도로 기록한다. 로컬 함수 처리시간을 전체 ChatGPT 실행시간이라고 쓰지 않는다.


- v4.1 / MON-P3.1 / mon.php 1.3.1: 사용자 최종 정정 **12·26·9** 적용. 완료봉 및 장중 시그널 계수0.2, 첫 시그널34봉·첫 교차35봉·최근3히스토그램36봉. 이전19일 기준 추천·신호는 이력으로 보존하고 새 자율 선정 전에는 대기 표시한다.
- 기존 원자료의 전체 구간210개 종목 복원(한국61·미국80·일본69). 기존 완료봉 값을 변경하지 않았다. 221개 전체 후보를 같은 기준으로 재계산하되 원천/최신 비교가 부족한 후보는 미검증으로 남긴다. 자료 보충 시간과 추천 판단 시간을 구분한다.
- v4.1 변경 검증: **함수30건＋웹 HTTP6건, 총36건 PASS / 0 FAIL**. 독립 유리수 가중합 EMA12·26·시그널9, 최소봉 경계, 장중 계수0.2, P3.0 전환·추천/신호 이력 보존, 이력 충돌 거절·60봉 절단 검출·collection 보존·새 프로필 웹 입출력을 확인했다. NAS 실배포 또는 신규 추천 판단 완료를 뜻하지 않는다.

- v4.1.1 / mon.php 1.3.2: 이력 복원 후1MiB를 넘은 mon_data.json에서 발생한 GITHUB_CONTENT_INVALID의 원인을 수정했다. GitHub 원문 읽기와 정확한 blob SHA 계산을 사용한다. 현재1,187,731바이트 자료·1MiB 전후·저장 상한·오류 거절·409 충돌 재읽기·collection만 갱신을 포함한 변경 검증 **19건 PASS / 0 FAIL**. 이전 오류도 크기 의존 응답으로 재현했다. NAS 교체 후 실제 접속 상태는 별도로 확인한다. MACD(12,26,9)와 자율 선정 기준은 MON-P3.1을 유지한다.

- v4.2 / mon.php 1.4.0: 종목별 요청·응답, 장외 상세 수집, 실패 시 이전 검증자료·성공 부분값 보존, 원래 자료시각 유지, 낡은 응답 배제, 자율 과거자료 참고 선택과 결과 자료 날짜를 구현했다. 변경 검증 성적과 NAS 구분은 이번 반영 기록에 남긴다.

- v4.2 최종 변경 검증: **함수47건＋데몬·응답 연계16건＋웹 HTTP10건, 총73건 PASS / 0 FAIL**. 종목별 독립 처리·재시도·마감·주말 완료봉 재사용·이전시각 보존·부분 가격 보존·KIS 100봉 페이지 제한 해소·가격 기준 충돌·잘못된 연결키/압축/수치의 개별 격리·동시 요청 교체·직전 정상자료·보조자료 자율 선정/과거 참고 저장·collection 보존을 모의 검증했다. PHP8.3에서 검사했으며 코드 문법은 PHP7.4 호환 범위를 유지한다. 실제 NAS/KIS 접속 검증은 수행하지 않았다.
- v4.2 NAS 운영 확인(2026-10-10 23:25 KST): 사용자의 재시작 후 6종목의 개별 응답과 GitHub 즉시 반영, 요청 연결키·압축자료 해시·저장 수치의 일치를 확인했다. 첫 한국·일본 5종목은 마지막 완료봉 재사용으로 응답했고, 이미 마감된 미국 요청은 이전 검증자료를 반환했다. 이어 한국 LG에너지솔루션 강제 새 조회와 미국 팔란티어 누락 완료봉 새 요청을 보내 두 종목 모두 `DETAIL_PRICE_BASIS_CONFLICT`가 반환됨을 확인했다. 최신 수집 성공으로 처리하지 않고 현재 조건부 추천을 보류해 과거자료 참고로 반영했다. 보존된 팔란티어 원본에는 저장 가격 139.40과 원본 139.39500427246094의 정밀도 차이가 있으나 NAS 응답에 새 일봉이 없어 최신 충돌의 전체 원인과 LG에너지솔루션의 불일치 필드는 미확인이다. 직접 NAS 상태/PID·실제 KIS 일봉 공급자 성공은 확인하지 못했다. 코드 1.4.0과 기존73건 PASS는 재작성·재시험하지 않았다.

- 공식 KIS API 정의: [국내 기간별 일봉](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/domestic_stock/inquire_daily_itemchartprice/inquire_daily_itemchartprice.py), [해외 일봉](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/overseas_stock/dailyprice/dailyprice.py), [국내 지수 일봉](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/domestic_stock/inquire_daily_indexchartprice/inquire_daily_indexchartprice.py).

- **v4.5 / mon.php 1.6.1 / 보강 D1.2 (2026-10-11)**: 259.2초 마감 초과·NAS 접근 미확인·미국 최신 비교 부족을 계기로 작은 첫 결과, KIS/대체/지수 예산 예약, 원천 공통 장애 차단, 성공 종목 원문 체크포인트와 지수 재개, 일시 오류만 재시도, 동일 기간 저장 지수 활용, 고정 함수의 실제 마감과 새 분석/재표시 완료 구분을 보강했다. 새 변경 경로 실제 PHP7.4.33 함수31건＋로컬 HTTP17건=48 PASS / 0 FAIL. 기존105 PASS 전체는 반복하지 않았다. 실제 NAS 배포·외부 원천 성공·미국 누락 자료·전체 ChatGPT120초 완료는 별도 미확인이다.

- **v4.6 / mon.php 1.6.2 / 보강 D1.3 (2026-10-11)**: NAS 부팅·예외 종료 뒤 자동 재동작을 금지하고 웹의 부팅별15초 일회용 시작 권한, 중지 시 권한 취소, CLI 자동 실행 거절, 데몬 nice +10·최대96MiB·과부하 대기·분할 수집·ETag/캐시 재사용·대기 쓰기 축소를 반영했다. 새 변경 경로 실제 PHP7.4.33 함수·실행 모의41건＋로컬 HTTP/CLI16건=57 PASS / 0 FAIL. 기존48/105 PASS 전체는 반복하지 않았다. 실물 NAS 재부팅·배포·실제 부하/원천 성공은 미확인이다.

- **v4.7 / mon.php 1.7.0 / 보강 D1.4 (2026-10-11)**: 보호 실행 통계·현재 한도 고정·다음 실행 자동 조정·HTTP 횟수/시간·상세 시도·실제 외부 보고·만료 중단 복구·게시 후 동일 통계 행 갱신을 구현했다. 새 변경만 PHP7.4.33 함수/연속 모의35건＋실제 로컬 HTTP20건=55 PASS/0 FAIL. NAS 배포/실부하/외부 원천 성공은 별도다.

- **v4.8 / mon.php1.8.0 / D1.5 (2026-10-11)**: 사용자의 완료 우선 요청에 따라 전체/HTTP/검색/상세/게시의 시간·횟수 종료를 제거했다. 기준 초과는 기록하고 다음 기준에 반영한다. 미완료 checkpoint·지속 대기/재개·다회 collection 병합·게시 유실 확인을 보강했다. 이전 성적과 실제 NAS 가동을 이번 변경의 완료 증거로 사용하지 않는다.

- **v4.9 / mon.php1.8.1 / NAS 지속 복구 (2026-10-11)**: 최초1초 실패 후 접속 확인을 포기하던 지시를 대체했다. 실제6.42/6.09초 접속과 새 PHP 클라이언트7.71초 접속에서 HTTP200/v1.5.0을 확인했다. 영속 읽기 재시도·대기 증가·Retry-After·다음 첫 대기의 통계 반영·구버전 구분·웹 단일 요청과 POST 유실 확인을 구현했다. 신규48 PASS/0 FAIL이며 시장 운영 표본/이전 PASS에 합산하지 않는다. NAS v1.8.1 실물 배포와 재시작은 미확인이다.

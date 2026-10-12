# MON 완료 강제·복구 보강 — 2026-10-12

mon.php **1.8.4** · 지시문 **v4.12** · MON-COMPLETION-1.0 · PHP7.4 호환

이 계약의 완료는 분석·필수 시장 확인·원격 파일 저장 범위다. NAS 수집 정상화와 운영 후처리의 완료 scope는 [차기 ERR-C 설계 D2.0](mon_errc_upgrade_design.md)에 정의한 구현 예정 사항이며 기존 영수증의 의미를 소급 변경하지 않는다.

## 문제와 결과

기존 실행은 new_analysis_complete/final_available와 saved/no_change만으로 완료를 판정했다. 장중 가격·기업 확인이 미완료 목록에 남아 있어도 종료·통계·저장 응답에서 완료가 될 수 있었다. 새 구현은 서버가 검증한 파일 쌍의 보호 scope와 실제 원격 저장 증명을 확인하고 필수 작업0건일 때만 완료한다. ChatGPT도 영수증을 확인하고 남은 실제 작업을 수행해야 한다.

## 완료 검증

| 확인 대상 | 서버 확인 | 미완료 처리 |
|---|---|---|
| 분석·파일 쌍 | native 사실 검증, source/파일 SHA, run_id·received_at·artifact binding | analysis/verified_artifact/verification_binding |
| 대상 국가·최신 완료봉 | 실제 달력과 현재 완료일 비교, 늦은 상세의 pending_analysis | analysis_reprepare |
| 당일 신규 목표 | 검증된 discovery_state의 국가별 shortfall | new_identity_quota |
| 기업 확인 | 같은 현지 날짜의 bounded review, pending_items 없음 | issuer_review |
| 정규장 진입 | 원래 시세시각·지연·통화/시장·현재 가격/계획·거래 확인300초 재검사 | current_entry |
| 원격 저장 | 보호 게시 journal의 실제 ref/정확한 두 파일 SHA 확인 | publication |

snapshot은 native 검증 뒤 서버가 만들며 최대196,608바이트의 보호 PHP 파일이다. 상태 조회는 큰 일봉을 재계산하지 않는다. 작업 task_id와 게시 publication_job_id를 구분한다. 게시 작업에는 다음 API와 artifact_id를 반환한다. 가격 재조회로 해결할 수 없는 계획/신호 오류는 analysis_reprepare로 연결한다. 복구 큐 전체는 run journal에 보존하고 기존 통계의 미완료 표시는 최대30개로만 투영한다. 호출자의 성공 플래그·unfinished=[]·구버전 통계는 필수 작업을 제거하지 않는다. 원래 지표 수치/선정 기준을 바꾸지 않는다.

## 복구 순서

1. issuer_review와 실제 누락 입력을 먼저 확인한다. 이미 검증한 자료를 다시 조사하지 않는다.
2. 현재가·거래 확인을 마지막에 수행해 게시와 최종 확인까지 유효성을 유지한다.
3. 첫 finalize의 confirmations에 근거를 넣는다. 중간 파일을 저장한 뒤 보충이 필요하면 mon_run_recover(action=confirm)에 정확한 source/context와 기존 evaluate decision_patch를 보낸다. 변경은 가격·위험·근거/설명이며 원래 선정과 지표는 유지한다.
4. 완료일/계획/신규 입력/중대한 사건 등 새 분석이 필요하면 action=reprepare로 이전 artifact 이력을 보존하고 같은 run_id에서 prepare/finalize를 진행한다. 과거 확정 decision은 덮어쓰지 않는다.
5. 직전 게시가 불명확하면 먼저 확인한다. 권한/설정을 정정한 job은 mon_publish_process(resume=true)로 재개하며 ref intent가 있으면 GET 확인부터 한다. 다른 분석/설정 충돌은 덮어쓰지 않는다.
6. 커넥터로 저장했어도 native 게시 경로가 SHA를 읽어 확인한다. 이미 반영된 결과에는 새 POST/ref를 만들지 않는다. collection만 나중에 바뀌면 그 원문을 보존한 읽기 검증을 한다. 공개 저장소 GET은 쓰기 토큰 없이 허용하고 실제 POST/PATCH는 기존 토큰이 필수다.
7. mon_run_finish 응답의 finish_accepted=true, run.fully_complete=true, run.completion.required_checks_pending=0과 올바른 완료 영수증을 함께 확인해야 분석 범위 완료 보고가 가능하다. false이면 checkpoint를 보존하고 run.next_required_job을 처리한다. mon_run_status는 직접 completion/next_required_job 구조다.

## 판단과 미완료 구분

ENTRY_CONFIRMED는 현재 진입 확인, WAIT_ENTRY는 실제 자료로 확인한 가격/신호 유지 대기, WAIT_SESSION은 검증된 장외/휴장과 다음 거래일, EXCLUDE는 확인된 악재/거래정지, REFERENCE는 이전 검증 신호 참고다. 확인되지 않은 가격/기업 자료를 WAIT로 바꾸어 완료를 채우지 않는다. 휴장에도 기업 확인은 수행한다. MACD_BEFORE는 허용 자격이며 미확정 교차만으로 미완료가 되지 않는다. 실제 유효한 매입 후보를 강제로 만들지 않는다.

## 영수증·상태·통계

완료 영수증은 contract/run_context_id/artifact_id/published_commit_sha/snapshot_sha256/두 파일 SHA/종목별 결과/필수0건/확인시각/receipt_id를 담는다. 새 finish, run status, publication receipt와 웹 status가 같은 assess를 사용한다. cached publication 성공도 현재 재검사하며 새 보충 revision을 추적한다. final_display_reported는 미완료일 때409 MON_COMPLETION_PENDING으로 거부한다. 정상 완료 후 실제 표시 보고는 허용한다.

새 통계는 completion_contract/required_checks_pending/publication_verified/completion_receipt_id를 보존하고 필수 작업이 남은 complete를 거부한다. 과거 행은 삭제/수정하지 않고 legacy_completed로, 새 계약 확인은 verified_completed로 구분한다. 안정 완료5회에 따른 시간 기준 축소에는 새 영수증의 완료만 사용한다. 시간/횟수 초과는 관측이며 처리를 중단시키지 않는다.

신규 후보를 이어받는 signal map은 ID순으로만 정렬해 JSON 직렬화 전후 candidate_facts_sha256이 같도록 한다. 종가·수치·신호·순위·선정값은 변경하지 않는다.

## 검증

- 새 함수·게시 모의 **28 PASS / 0 FAIL**, 실제 localhost PHP7.4 웹 API **16 PASS / 0 FAIL**, 원래10/12 자료의 고정 시각 재생 **1 PASS / 0 FAIL**. 합계 **45 PASS / 0 FAIL**.
- 원래7개 조건부 결과의 자료를 그대로 재생했을 때 파일 저장이 확인되어도 필수 확인을 남긴 전체 완료가 거부됨을 확인했다. 원격 응답은 모의이며 새 시장 분석이 아니다. 원본 파일을 바꾸지 않았다.
- 수집·일봉·worker·수동 시작/부하·스윙 지표·현재가/fact 검증의 기존 메서드 **65개 본문 SHA256 동일**. 정상 경로의 과거 PASS 전체는 반복하지 않았다.
- PHP7.4.33 / memory_limit96M. 관측 자료 재생 최고 메모리 **55.55 MiB**. 외부 원천 요청·주문·NAS 시작/중지0. 코드 시험을 mon_execution_stats.json의 시장 운영 표본에 넣지 않았다.
- 원시 결과: [함수 검증](tests/mon_completion_gate_tests.json), [웹 API 검증](tests/mon_completion_gate_http_tests.json), [관측 자료 재생](tests/mon_completion_observed_replay.json), [정상 메서드 보존](tests/mon_completion_preserved_methods.json).

## 적용 범위

GitHub 코드/지시문 반영과 NAS 실물 적용은 별개다. 기존 데몬을 중지하고 /web/mon.php를1.8.4로 교체한 뒤 웹에서 직접 시작한다. sis_private_sync_config.php/broker_config.local.php는 그대로 재사용한다. cron/부팅/예외 자동 시작·주문은 추가하지 않았다. 외부 정보 확보나 ChatGPT의 조사 수행을 PHP가 대신하지 않는다. 서버는 미완료 완료 판정을 차단하고 정확한 다음 작업을 제공하며, 실행 지시문은 이를 실제로 수행하도록 강제한다.

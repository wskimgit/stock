# MON 보강 구현·검증 보고

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

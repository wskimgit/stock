# MON Wiki — 2.2.3 Lite 운영 요약

> **비권위 요약 문서**입니다. 실행 규칙의 유일한 권위는 같은 저장소의 `monitor.md`입니다.
> Policy: `MON-LITE-OPS-2.2.3` · Build: `MON-LITE-2.2.3-20261019-r14` · Code: `mon.php` · Repository: `wskimgit/stock`

## 최종 판단과 저장 책임

ChatGPT가 매 실행의 최신 시장·업종·완료봉·현재 거래가능 가격·뉴스·상대강도·추격위험을 독립적으로 검토한다. PHP/MON의 순위·참고 MS7·이전 추천은 보조자료다. 최종 권한은 **ChatGPT final decision**에만 있다. PHP 후보군 밖 종목도 선정할 수 있다.

기본 목표는 한국·미국·일본 각 3종목이며, 검증된 종목만 나라별 0~3개 선정한다. 자료가 부족하면 **이번 실행의 새 대기 결론**과 이유를 저장한다. PHP의 미확인 fallback을 추천으로 승격하거나 과거 결론에 새 시각을 붙이지 않는다. MS7은 `M0/M1/M2/W/S1/S2/S3`이며 ChatGPT만 최종 결정한다. 종목명·종목코드·근거·위험을 모두 기록한다.

**최종 결정과 저장의 1차 책임은 ChatGPT 지시문**이다. PHP는 보조분석·mailbox·영속 복구를 제공한다. 결과 파일만 만들거나 handoff만 접수한 상태는 완료가 아니다.

## 저장 완료 조건

1. 같은 `decision_id`·`request_id`·`evaluation_id`·`analysis_at`으로 `mon_data.json`과 `mon_result.md`를 만든다. `outcome`과 비어 있지 않은 `conclusion`을 기록한다.
2. ChatGPT는 두 결과를 **하나의 Git commit**으로 직접 저장하는 경로를 우선한다. NAS가 꺼졌거나 로컬 request proof가 없어도 독립 판단과 직접 저장은 진행한다.
3. 정확한 게시 ref에서 두 파일을 강제 재조회해 내용·식별자·종목·MS7·실제 blob SHA를 검증한다.
4. 두 파일의 실제 commit/blob SHA를 담은 receipt를 `mon_chatgpt_response.json`에 쓰고 receipt 자체도 정확한 ref에서 다시 읽는다.
5. 모두 검증되어야 `SAVED_VERIFIED`다. 실제 확인한 GitHub 링크와 분석시각을 보고한다. 일부 성공은 `pending_remote` 또는 `pending_receipt`로 남기고 해당 단계만 복구한다.

receipt는 `status=saved` 및 실제 두 결과의 commit/blob SHA와 결정 식별자를 기록한다. 불변 JSON 스냅샷의 `chatgpt_final.save_status=receipt_required`·`pair_readback_verified=false`는 그 필드만으로 성공/실패를 판정하지 않는다. 현재 두 결과와 일치하는 receipt 및 세 파일의 실제 재조회가 완료 증명이다. PHP 로컬 outbox만 이 검증 후 `save_status=saved`로 바뀐다.

직접 저장이 불가하고 NAS가 동작하면 같은 decision을 실제 request/details SHA와 함께 handoff한다. PHP는 원격 쓰기 전에 기존 `result.php`에 최종 결정과 정확한 게시 원문을 기록하고 결과 쌍·receipt를 재조회한 뒤에만 `saved`로 확정한다. proof 소실은 가짜 SHA로 우회하지 않는다. 자세한 순서·형식은 `monitor.md` 8~9절을 따른다.

`outcome`은 `completed / limited_evidence / no_candidates`다. 선정이 있으면 실제 HTTP(S) 독립 근거 2개 이상과 확인시각을 기록한다. 자료에 접근할 수 없으면 `selected=[]`, `outcome=limited_evidence`와 빈 출처 목록으로 제한을 정직하게 기록할 수 있다. KR/US/JP 시장판단과 현재 분석시각은 어느 경우든 남긴다.

## 장애 복구

- GitHub 게시 실패: 시장 재스캔 없이 같은 결정·같은 시각·같은 게시 원문으로 실패 단계만 복구
- 응답/receipt 실패: 저장된 결과 쌍을 확인하고 receipt만 복구; 성공으로 미리 표시하지 않음
- 중단·재시작: pending outbox 먼저 복구; 새 스캔/후보 요청으로 덮어쓰지 않음
- SHA 충돌: 최신 원격 상태 확인; 더 최신 결정을 과거 pending 결과로 덮어쓰지 않음
- GitHub 완전 불가: 영속 복구 원문이 있으면 `PENDING_REMOTE`, 보존도 불가하면 `UNSAVED_REMOTE`; 원격 저장 완료라고 하지 않음
- 시도별 timeout·제한 재시도 사용; 무한 대기하지 않으며 다음 운영 주기로 넘김
- NAS 정지/재부팅: 수동 웹 시작 전까지 PHP 복구는 실행되지 않음; cron/자동시작 없음

새 스캔의 PHP 결과는 `analysis_reference`다. 새 독립 판단이 필요하면 과거 최종 결론은 원래 시각과 함께 `previous_chatgpt_final`에 보존하고 활성 `recommendations`는 비운다. `awaiting_chatgpt` / `independent_chatgpt_required`를 새 추천 완료로 읽지 않는다. Markdown에 마지막 결론을 유지해도 JSON의 scan 상태·`decision_id`를 대조해 과거 판단으로 표시한다.

## 수집·계산·주기

- 완료 일봉: Yahoo 우선, 증분수집 기본, 7일마다 220봉 전체 재동기화
- MA5/10/20, MACD(12,26,9), ATR14, R20, RS20, ADV20, S/R
- `RS20 > 0` + 스윙 기술신호는 참고값이며 ChatGPT 최종 추천 gate가 아님
- Mailbox: 대기 상태 5분; 스캔 중이면 종료 직후
- 경량 확인: 30분; 정밀 스캔: 평일 16:40 KST
- 일본 benchmark: `^TOPX → 1306.T → ^N225`의 제한된 fallback
- 과거 고립 OHLC 오류는 제한적으로 제외하되 최신 완료봉 오류는 경고 유지
- 구형 NAS의 가용 메모리는 reclaimable cache/buffer 포함 추정
- 약 20 MiB 이상 메모리 압박: 3/2/1종목 배치·종목별 체크포인트, 미처리 시 2분 후 계속
- NAS 약 20 MiB/PHP 여유 8 MiB 안전 하한 미만 또는 CPU load 압박: 정밀 작업 2분 연기 후 재확인
- 국가/거래소 ID 오류는 `rejected_ids`로 격리, 중복은 1회 처리, 빈 후보는 `ready_empty`

## NAS와 파일

`/web/mon.php`가 `/web/mon_runtime`을 자동 생성한다. `?view=health`에서 PHP 7.4+, cURL, GitHub token, 실제 PHP CLI 절대경로와 버전/SAPI/cURL, exec, runtime 쓰기/용량, CPU/메모리 및 읽기 통신을 확인한다. 필수 blocker는 시작을 거부하고 일시적 외부 통신 실패는 warning으로 남긴다.

Runtime은 최대 11개 파일·64 MiB·단일 16 MiB다. 로그도 PHP guard가 있는 `.php` 파일로 보관한다. 별도 bridge나 이벤트별 파일을 늘리지 않는다. 자동주문·자동매매는 없다.

## 권위와 검증

1. `monitor.md`: 유일한 운영 권위
2. `mon.php`: 구현체, policy/build 일치 필요
3. `MON-Wiki.md`: 설명용 요약
4. `mon_data.json`, `mon_result.md`, handoff/response: 현재 실행 상태·결과·저장 증명

코드/문서 변경은 `CHANGE_GATE.md`를 적용한다. 정책은 `MON-LITE-OPS-2.2.3`을 유지한다. 코드/지시문 r14와 기존 시장 결과의 생성 당시 build(r10 등)는 구분한다. 코드 정비만으로 시장 JSON을 새 결과처럼 고치지 않는다. 코드 검증, GitHub 반영, NAS 배포, NAS 실동작은 각각 보고하며 NAS evidence가 없으면 `LIVE_VERIFIED`라고 하지 않는다.

## r14 메모리 관리

스캔 캐시·전송 본문을 대기 단계에 보존하지 않고 PHP 한도와 NAS 여유 메모리를 함께 반영한다. 큰 본문의 중복 복사와 캐시 중복 디코딩을 줄인다. 결과 저장·복구와 매매 판단 기준은 그대로 유지한다. 세부 한도·검증 기준은 `monitor.md`와 현행 코드가 우선한다. 배포 전후 성능 수치는 같은 입력·PHP 버전에서 비교하며 로컬 시뮬레이션을 NAS 실측으로 표시하지 않는다.

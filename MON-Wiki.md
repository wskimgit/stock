# MON Wiki — 2.2.3 Lite 운영 요약

> **비권위 요약 문서**입니다. 실행 규칙의 유일한 권위는 같은 저장소의 `monitor.md`입니다.
> Policy: `MON-LITE-OPS-2.2.3` · Build: `MON-LITE-2.2.3-20261020-r15` · Code: `mon.php` · Repository: `wskimgit/stock`

## 최종 판단과 효율적인 실행

최종 권한은 **ChatGPT final decision**에만 있다. PHP/MON 순위·참고 MS7·과거 추천은 보조자료다. ChatGPT가 최신 시장·업종·완료봉·거래가능 가격·뉴스·상대강도·추격위험을 독립적으로 검토하며 PHP 후보 밖에서도 선정할 수 있다. 검증된 종목만 나라별 0~3개 선정하고 수를 채우지 않는다. 선정 없음도 이번 실행의 새 결론으로 저장한다.

1. 최신 지시문·운영 ref·NAS 상태·기존 결과는 한 번 확인하고 바뀐 범위만 검토한다.
2. KR/US/JP를 병렬 조사한다. 필요한 완료 OHLCV는 가능한 배치로 한 번 수집하고 같은 자료의 지표·상대강도 계산을 공유한다.
3. 공통 시장 근거는 한 번 확인하고 관련 원문 출처만 짧게 남긴다. 같은 뉴스 재전송을 독립 출처로 세지 않는다.
4. PHP의 `PARTIAL`·자료 부족·NAS 지연은 해당 범위에만 표시하고 독립 조사를 계속한다. PHP 신호·참고순위를 최종 선정의 필수 gate로 사용하지 않는다.
5. ChatGPT가 국가별 판단과 새 결론을 확정하고 저장·재조회를 끝낸다. 과거 답변을 새 판단처럼 반복하지 않는다.

최종 종목에는 종목명·종목코드·MS7·근거·위험을 기록한다. MS7은 `M0/M1/M2/W/S1/S2/S3`다. 가격에는 실제 확인 시각·통화를 붙이고 완료 종가와 실시간 가격을 구분한다. Markdown 표 안의 ID는 `KR\|KOSPI\|005930`처럼 `|`를 이스케이프하며 JSON의 실제 ID는 바꾸지 않는다.

## 저장 완료 조건

**최종 결정과 저장의 1차 책임은 ChatGPT 지시문**이다. 한 번의 MON 수행은 새 판단·결론·저장·재조회까지 포함한다.

- 같은 `decision_id`·`request_id`·`evaluation_id`·`analysis_at`, `outcome`·`conclusion`으로 `mon_data.json`과 `mon_result.md`를 만든다.
- ChatGPT 직접 경로는 두 결과를 **하나의 Git commit**으로 원자 저장하고 정확한 ref에서 실제 내용·blob SHA를 재조회한다. NAS 정지나 request proof 소실도 이 경로를 막지 않는다.
- 검증된 결과 쌍을 가리키는 receipt를 `mon_chatgpt_response.json`에 기록하고 receipt 자체도 재조회한다. 세 파일의 증명이 맞을 때만 `SAVED_VERIFIED`다.
- JSON 스냅샷의 `chatgpt_final.save_status=receipt_required`만으로 성공/실패를 판정하지 않는다. 현재 결과 쌍과 일치하는 receipt 및 실제 재조회가 증명이다.
- 직접 저장이 불가하고 NAS가 동작하면 실제 request/details 증명을 가진 같은 decision을 handoff한다. PHP는 기존 `result.php`에 정확한 게시 원문을 먼저 보존하고 `pending_remote → pending_receipt → saved`로 복구한다. 가짜 SHA로 proof를 대체하지 않는다.
- 실패는 해당 저장 단계만 복구한다. 시장 재스캔·새 시각 생성·과거 판단 재사용으로 대신하지 않는다. 더 최신 결정을 덮어쓰지 않는다.

`outcome`은 `completed / limited_evidence / no_candidates`다. 선정이 있으면 독립 근거 2개 이상과 실제 확인 시각을 기록한다. 자료에 접근하지 못하면 `selected=[]`, `outcome=limited_evidence`와 정직한 한계를 남긴다. 영속 복구 원문만 있으면 `PENDING_REMOTE`, 보존도 못 했으면 `UNSAVED_REMOTE`다. 자세한 계약·정확한 ref·충돌 처리는 `monitor.md` 8~9절을 따른다.

## 기준지수와 분석 재사용

- 최초·전체·증분에 공통 기준지수 수집 경로를 사용한다. 일본은 `^TOPX → 1306.T → ^N225`의 제한된 fallback이며 마지막 정상 출처를 보존해 증분에서 먼저 확인한다.
- 같은 출처만 증분 병합한다. 출처가 없거나 바뀌면 새 출처의 220봉으로 교체하고 서로 다른 지수·ETF 가격을 이어 붙이지 않는다.
- 분석 재사용은 종목 봉뿐 아니라 기준지수 출처·자료·계산 버전 fingerprint가 같아야 한다. 기준지수가 바뀌거나 기존 fingerprint가 없으면 한 번 다시 계산한다.
- 기준지수 실패는 마지막 정상 봉을 보존하되 해당 시장의 현재 행을 pending으로 표시하고 큐를 남겨 중복 종목 요청을 줄인다. 다른 국가와 ChatGPT 독립 조사·최종 결론 저장은 계속한다.
- 미국 최신 완료 일봉의 0.1% 이하 H/L 범위 위반은 같은 정규장 5분봉 78개 전체의 정렬·O/C/H/L 각각 0.1% 이내 일치로 검증한 경우 위반 H/L만 작게 복구한다. 원 일봉 O/C/V는 보존하며, 단순 clamp·누락 분봉 보충·조기 폐장 추정은 하지 않는다. 검증 실패는 pending이다.

## 자원과 운영 주기

- 완료 일봉: Yahoo 우선, 증분 기본, 7일마다 220봉 전체 재동기화
- 계산: MA5/10/20, MACD(12,26,9), ATR14, R20/RS20, ADV20, S/R; 기술 적격은 참고값
- Mailbox: 대기 상태 5분, 스캔 중이면 종료 직후; 경량 30분, 정밀 평일 16:40 KST
- 메모리: NAS 가용량과 PHP 한도 여유를 함께 확인, 낮은 한도를 올리지 않음; 1~3종목 배치와 `scan_queue` 체크포인트
- 하한: NAS 약 20 MiB/PHP 여유 8 MiB 미만은 2분 대기
- CPU: 정상 load 기준은 코어당 1.5 유지; 코어당 1.5 초과~3 미만은 90초 이내 busy 85% 이하 확인 시에만 1종목 배치; 코어당 3 이상·busy 95% 이상·높은 load에서 busy 미확인이면 대기
- 종목 사이에도 자원 재확인, 나머지 큐는 2분 후 계속; 무한 기다림·후보 삭제 없음
- 대기 전 전체 캐시·임시 전송 본문 해제; GitHub 256 KiB/본문·512 KiB/총량·4파일, Yahoo 48종목×40봉 캐시
- 수신은 압축 해제 후 24 MiB와 PHP 여유의 ¼ 중 작은 한도; 로컬 파일 검증은 스트림 해시
- 메모리 관측은 데몬 시각·SAPI와 함께 표시하며 로컬 시뮬레이션을 NAS 실측으로 부르지 않음

## 상태를 읽는 방법

프로세스 생존, 자료 처리 범위, 참고분석 게시, 최종 결정 저장을 각각 확인한다.

- `daemon_liveness`의 실행 lock·heartbeat·실행 identity를 함께 확인한다. 과거 `running=true`만으로 생존을 단정하지 않는다.
- 설치 파일 build/SHA와 `run_identity`의 현재 CLI 데몬 시작 시 build/SHA·PID·시각을 분리한다. 구버전 실행 증명이 없으면 `UNKNOWN`이다. 다르면 수동 재시작이 필요하다.
- `WAIT`·`SCAN`·`RESULT`·`SAVE_PENDING` 및 `progress.state=partial`을 구분하고 국가별 정상/요청/미확인/큐 잔여를 표시한다. 25/35 정상은 전체 완료가 아니다.
- 현재 `resource_wait`와 원래 시각의 `historical_error`를 구분한다. mailbox 성공이 시장 자료 회복을 뜻하지 않는다.
- PHP 참고분석 게시 성공은 ChatGPT 최종 저장 성공이 아니다. `SAVED_VERIFIED`에는 같은 결정 쌍과 receipt 재조회가 필요하다.
- 새 스캔의 이전 결론은 `previous_chatgpt_final`에 보존하고 원래 시각의 과거 판단으로 표시한다. Markdown에 남은 마지막 결론도 JSON scan 상태·결정 ID와 대조한다.

## NAS·파일·권위

`/web/mon.php`는 PHP 7.4+에서 웹 수동 시작하며 `/web/mon_runtime`을 자동 생성한다. `?view=health`에서 cURL·GitHub token·실제 PHP CLI 경로/버전/SAPI/cURL·exec·runtime 쓰기/용량·자원·읽기 통신을 확인한다. 필수 blocker는 시작을 거부하고 일시적 외부 통신 실패는 warning으로 남긴다.

Runtime은 최대 11파일·64 MiB·단일 16 MiB다. 별도 bridge·이벤트별 파일·cron·부팅 자동시작·자동주문·자동매매를 추가하지 않는다. NAS가 꺼지면 수동 시작 전까지 PHP 복구도 실행되지 않는다.

운영 권위는 `monitor.md`, 구현은 `mon.php`, 이 문서는 비권위 요약이다. 정책 `MON-LITE-OPS-2.2.3`은 유지하며 r15 코드/지시문과 과거 시장 결과의 생성 당시 build는 구분한다. 코드 정비로 시장 JSON을 새 결과처럼 고치지 않는다. 코드 검증·GitHub 반영·NAS 배포·NAS 실동작을 각각 보고하고 실제 새 빌드 lifecycle을 확인해야 `LIVE_VERIFIED`다.

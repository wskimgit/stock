# MON Wiki — 2.2.3 Lite 운영 요약

> **비권위 요약 문서**입니다. 실행 규칙의 유일한 권위는 같은 저장소의 `monitor.md`입니다.  
> Policy: `MON-LITE-OPS-2.2.3` · Build: `MON-LITE-2.2.3-20261017-r9` · Code: `mon.php` · Repository: `wskimgit/stock`

## 정상 시나리오
`IDLE → candidates 접수 → PHP 보조분석 3×3 → ChatGPT 독립 재분석 → ChatGPT 최종 3×3+MS7 → 저장 → RESULT/WAIT`

후보가 없어도 내장 fallback으로 KR/US/JP 각 3개를 구성해 결과를 만든다. 새 후보 요청은 기존 활성 후보군을 교체하며 기존 cache가 있어도 최신 완료봉을 다시 확인한다.

## 주기
- Mailbox: 대기 상태 5분; 스캔 중이면 종료 직후
- 경량 확인: 30분
- 정밀 스캔: 평일 16:40 KST
- 전체 220봉 재동기화: 7일

## 결과 신뢰도
- MA5/10/20, MACD(12,26,9), ATR14, R20, RS20, ADV20, S/R
- 정밀 적격: `RS20 > 0` + 스윙 기술신호는 참고값일 뿐 추천 gate가 아님
- PHP/MON은 KR/US/JP 각 3개의 **보조 참고후보**와 참고 MS7을 제공
- 최종 추천은 ChatGPT가 최신 시장·업종·가격·뉴스·리스크를 독립 재분석해 KR/US/JP 각 3종목을 확정
- ChatGPT는 PHP 후보군 밖 종목도 최종 선정 가능
- 최종 MS7 `M0/M1/M2/W/S1/S2/S3`은 ChatGPT가 직접 결정하며 PHP가 덮어쓰지 않음
- PHP 참고분석은 최종 추천 권한이 없음

## 최종 권한
- MON 시스템 내부 최종 추천 권한은 **ChatGPT final decision**에만 있다.
- PHP/MON의 점수·순위·적격·참고 MS7은 보조증거다.
- 최종 decision은 나라별 3종목, **종목명과 종목코드**, ChatGPT MS7, 선정 근거, 위험요인, 국가별 시장판단을 포함한다.
- PHP는 형식·무결성만 확인하고 최종 종목·MS7을 재판정하지 않는다. 최종 저장 화면은 **`종목명 (종목코드)`** 형식으로 표시한다.

## 장애 시나리오
- GitHub 결과 게시 실패: 시장 재스캔 없이 게시만 재시도
- 후보 재확인 실패: PHP 보조분석에 경고만 남기며 ChatGPT 최종 권한을 제한하지 않음
- bad handoff/policy: mailbox 오류로 격리, 시장 스캔은 계속
- 일본 benchmark: `^TOPX`가 불가하면 `1306.T`, 다시 불가하면 `^N225` 사용
- Yahoo 과거 고립 OHLC 오류: 제한적으로 제외하되 최신 완료봉 오류는 차단
- 구형 NAS 메모리: `MemAvailable`이 없으면 reclaimable cache/buffer를 포함해 추정
- 메모리 압박: 약 20 MiB 이상이면 3/2/1종목씩 처리하고 2분 후 다음 배치
- 메모리 안전 하한 미만 또는 CPU load 압박: 정밀 작업 30분 연기
- 재시작 후 cache: 저장 객체를 배열로 정규화해 기존 분석을 정상 복원
- 잘못된 국가/거래소 후보 ID: `rejected_ids`로 분리, 정상 후보는 계속 처리
- 중복 후보 ID: 한 번만 처리
- 빈 후보 목록: `ready_empty` 정상 응답

## NAS
`/web/mon.php`가 `/web/mon_runtime`을 자동 생성한다. `?view=health`에서 웹 PHP 7.4+, cURL, GitHub token, PHP CLI 절대경로, **CLI PHP 버전/SAPI/cURL**, exec, runtime 쓰기/용량, CPU/메모리와 GitHub/Yahoo read-only 통신을 확인한다. CLI/runtime 필수 blocker가 있으면 시작을 거부하며 일시적 외부 통신 실패는 warning으로 남긴다.

Runtime은 최대 11개 파일, 64 MiB, 단일 16 MiB이며 로그도 PHP guard가 있는 `.php` 파일로 보관한다.

## 권위 규칙
1. `monitor.md` — 유일한 운영/지시문 권위
2. `mon.php` — 구현체, policy/버전이 monitor와 같아야 함
3. `MON-Wiki.md` — 설명용 요약, 충돌 시 수정 대상
4. `mon_data.json`, `mon_result.md`, handoff/response — 현재 실행 상태/결과

버전 변경은 `mon.php + monitor.md + MON-Wiki.md + 활성 JSON policy`를 같은 변경 묶음에서 맞춘다.

## 최종 결론 보존 (2026-10-17)
새 스캔은 마지막 ChatGPT 최종 결론을 삭제하지 않습니다. 새 결정이 없으면 `previous_final_preserved`로 표시하며, 이는 **과거 판단의 보존**이지 현재 매입 추천이 아닙니다. 두 원격 결과 파일의 readback이 성공해야 저장 완료로 판단합니다. 자세한 규칙은 `monitor.md` 15절을 따릅니다.

# MON Wiki — 2.2.3 Lite 운영 요약

> **비권위 요약 문서**입니다. 실행 규칙의 유일한 권위는 같은 저장소의 `monitor.md`입니다.  
> Policy: `MON-LITE-OPS-2.2.3` · Code: `mon.php` · Repository: `wskimgit/stock`

## 정상 시나리오
`IDLE → candidates 접수 → 후보 최신화 → SCAN → details → ChatGPT decision → mon_result.md + mon_data.json readback → RESULT/WAIT`

후보가 없으면 `WAITING_FOR_CANDIDATES`로 대기한다. 새 후보 요청은 기존 활성 후보군을 교체하며 기존 cache가 있어도 최신 완료봉을 다시 확인한다.

## 주기
- Mailbox: 5분
- 경량 확인: 30분
- 정밀 스캔: 평일 16:40 KST
- 전체 220봉 재동기화: 7일

## 결과 신뢰도
- MA5/10/20, MACD(12,26,9), ATR14, R20, RS20, ADV20, S/R
- 기본 자격: `RS20 > 0` + 스윙 기술신호
- 국가별 상위 3개 최종 재확인
- `final_check.status=confirmed`인 후보만 최종 selected 가능
- 추천 0개도 정상 결과

## 장애 시나리오
- GitHub 결과 게시 실패: 시장 재스캔 없이 게시만 재시도
- 후보 재확인 실패: 해당 후보만 pending 후 30분 뒤 재확인
- bad handoff/policy: mailbox 오류로 격리, 시장 스캔은 계속
- 자원 압박: 정밀 작업 연기, 30분 뒤 재확인
- 재시작 후 cache: 저장 객체를 배열로 정규화해 기존 분석을 정상 복원

## NAS
`/web/mon.php`가 `/web/mon_runtime`을 자동 생성한다. `?view=health`에서 PHP 7.4+, cURL, GitHub token, PHP CLI, exec, runtime 쓰기/용량, CPU/메모리를 확인한다. 필수 blocker가 있으면 시작을 거부한다.

Runtime은 최대 11개 파일, 64 MiB, 단일 16 MiB이며 로그도 PHP guard가 있는 `.php` 파일로 보관한다.

## 권위 규칙
1. `monitor.md` — 유일한 운영/지시문 권위
2. `mon.php` — 구현체, policy/버전이 monitor와 같아야 함
3. `MON-Wiki.md` — 설명용 요약, 충돌 시 수정 대상
4. `mon_data.json`, `mon_result.md`, handoff/response — 현재 실행 상태/결과

버전 변경은 `mon.php + monitor.md + MON-Wiki.md + 활성 JSON policy`를 같은 변경 묶음에서 맞춘다.

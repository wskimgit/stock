# MON Wiki — 2.2.3 Lite 운영 요약

> **비권위 요약 문서**입니다. 실행 규칙의 유일한 권위는 같은 저장소의 `monitor.md`입니다.  
> Policy: `MON-LITE-OPS-2.2.3` · Build: `MON-LITE-2.2.3-20261017-r8` · Code: `mon.php` · Repository: `wskimgit/stock`

## 정상 시나리오
`IDLE → ChatGPT 후보 발굴 → MON 분석_reference → ChatGPT 독립 최종 3×3+MS7 → mon_result.md + mon_data.json → RESULT/WAIT`

후보가 없어도 MON은 fallback 3×3 참고자료를 만들 수 있다. 그러나 이것은 최종추천이 아니다. 최종추천은 ChatGPT가 별도 판단해 저장하며, 새 후보 요청은 기존 활성 후보군을 교체하고 최신 완료봉을 확인한다.

## 주기
- Mailbox: 대기 상태 5분; 스캔 중이면 종료 직후
- 경량 확인: 30분
- 정밀 스캔: 평일 16:40 KST
- 전체 220봉 재동기화: 7일

## 권위와 결과
- MON/PHP: 데이터 수집, 기술·상대강도 계산, MS7 계산, `analysis_reference` 3×3 제공
- `analysis_reference`는 **보조 참고자료이며 최종추천이 아님**
- ChatGPT: 시장·업종·기업·가격·리스크를 독립적으로 다시 검토
- ChatGPT는 MON 상위 3개·적격·final_check에 구속되지 않음
- 필요하면 MON 후보군 밖 종목도 최종선정 가능
- 최종 KR/US/JP 각 3종목과 최종 MS7은 ChatGPT가 직접 결정
- PHP는 형식과 요청 최신성만 검증하고 종목/순위/MS7을 덮어쓰지 않음
- 최종 저장 필드: `chatgpt_final`, `authority=CHATGPT_FINAL`
- `mon_result.md`는 ChatGPT 최종결정 저장 전에는 '최종 아님'을 표시
## 장애 시나리오
- GitHub 결과 게시 실패: 시장 재스캔 없이 게시만 재시도
- 후보 재확인 실패: 추천에서 배제하지 않고 경고만 표시; 자료 부족 시 W PROVISIONAL
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
2. **ChatGPT final decision — 종목 추천과 최종 MS7의 유일한 의사결정 권위**
3. `mon.php` — 수집·분석·형식검증 구현체; ChatGPT 최종내용을 덮어쓰지 않음
4. `MON-Wiki.md` — 설명용 비권위 요약
5. `mon_data.json`, `mon_result.md`, handoff/response — 현재 실행 상태/참고분석/최종결과

버전 변경은 `mon.php + monitor.md + MON-Wiki.md + 활성 JSON policy`를 같은 변경 묶음에서 맞춘다.

# mon 지시문 — MON 2.2.2 Lite Clean Start

> 운영 규격: **MON-LITE-OPS-2.2.2**  
> 기준일: **2026-10-17 KST**  
> 코드: `wskimgit/stock/mon.php`  
> 실행: NAS `/web/mon.php`, PHP 7.4+, 웹에서 수동 시작, cron/자동주문 없음

## 1. 목적
한·미·일 스윙 후보를 **완료 일봉** 기준으로 감시한다. PHP는 수집·검증·지표계산·참고순위를 제공하고, **ChatGPT가 최종 후보를 자율 선정**한다. 주문은 실행하지 않는다.

## 2. Clean Start 원칙
- NAS에는 `/web/mon.php`와 기존 공용 `sis_private_sync_config.php`만 필요하다.
- `/web/mon_runtime`은 코드가 자동 생성한다.
- 로컬 과거 runtime 파일을 요구하지 않는다.
- GitHub 활성파일은 모두 `policy = MON-LITE-OPS-2.2.2`를 사용한다.
- `mon_chatgpt_handoff.json`이 `stage=idle`이면 아무 판단도 재실행하지 않는다.
- 로컬 request 증명이 없는 과거 `decision`은 stale로 무시한다.
- 초기 후보가 없으면 오류로 종료하지 않고 **WAITING_FOR_CANDIDATES**로 대기한다.
- 과거 1.8.x result/handoff/bridge 상태를 새로운 실행의 근거로 사용하지 않는다.

## 3. 활성 파일
| 파일 | 역할 |
|---|---|
| `mon.php` | 유일한 PHP 실행 코드 |
| `monitor.md` | 이 지시문, 운영 계약의 권위 문서 |
| `mon_data.json` | 현재 스캔·후보·결과 공유 데이터 |
| `mon_result.md` | 사람이 읽는 최신 결과 |
| `mon_chatgpt_handoff.json` | ChatGPT→MON 후보/판단 mailbox |
| `mon_chatgpt_response.json` | MON→ChatGPT 응답 mailbox |

`mon_chatgpt_bridge.php`는 **2.x에서 사용하지 않는다.**

## 4. 운영 주기
- 경량 확인: **30분**.
- 정밀 스캔: 평일 **16:40 KST** 기본.
- 초기 후보가 생기거나 미완료 후보가 있으면 다음 경량 주기에서 재수집한다.
- 정상 운용은 최근 구간 증분수집을 우선한다.
- **7일마다 220봉 전체 재동기화**한다.
- 자원 압박 시 새 정밀처리를 시작하지 않고 30분 뒤 다시 확인한다.
- NAS 재부팅 후 자동 시작하지 않는다. 웹에서 다시 시작한다.

## 5. 데이터와 계산
주 원천은 Yahoo 완료 일봉이다. 정상 자료는 한 번 사용하고, 이상이 있을 때만 해당 종목을 전체 재수집한다.

핵심 계산은 MA5/10/20, MACD **12·26·9**, ATR14, R20, RS20, ADV20, 지지 S/저항 R, 눌림·돌파 참고구간이다.

시장 기준은 KOSPI `^KS11`, KOSDAQ `^KQ11`, 미국 `^GSPC`, 일본 `^TOPX`이다.

PHP 기본 추천 자격은 **RS20 > 0 AND (MA5 재돌파 OR MACD GOLD/AFTER/BEFORE)** 이다. `rank_hint`는 RS20 → R20 → ADV20 순 참고값이며 **최종 선정 권한은 ChatGPT에 있다.**

## 6. ChatGPT 후보 제출
사용자가 `mon 지시문을 수행하라`고 하면 최신 시장·기존 후보·과거 강한 후보를 동등 경쟁시켜 후보군을 만든다. NAS 부하를 위해 KR/US/JP 합계 약 30개 이내를 권장하되 억지로 채우지 않는다.

후보 요청 필수 필드:
```json
{"contract":"MON-LITE-BRIDGE-1.0","policy":"MON-LITE-OPS-2.2.2","stage":"candidates","request_id":"고유 실행 ID","candidate_ids":["KR|KOSPI|005930","US|NASDAQ|NVDA","JP|TSE|6758"]}
```

## 7. ChatGPT 최종 판단
- `evaluated_ids`의 검증된 범위에서만 선정한다.
- 국가별 최대 3종목.
- 억지 채우기 금지. 적격 후보가 없으면 `selected: []`가 정상 결과다.
- `unresolved_ids`는 탈락으로 간주하지 않는다.
- 현재 검증 범위와 전체 후보 확정 여부를 구분한다.
- decision에는 MON 응답의 `candidate_request_sha`, `details_receipt_sha`, `evaluation_id`, 그리고 동일 `policy`를 그대로 사용한다.

## 8. 결과 저장
정밀 스캔 종료 시 `mon_data.json`과 `mon_result.md`를 현재 스캔으로 갱신하여 과거 결과가 최신처럼 남지 않게 한다. ChatGPT decision 후 두 파일에 판단 범위를 저장하고 실제 재조회가 일치해야 `saved`로 처리한다. 일부 후보가 미완료여도 확인 가능한 범위의 결과는 저장하고 `unresolved_ids`를 명시한다.

## 9. 파일 운영
모든 로컬 부속파일은 `/web/mon_runtime` 안의 소수 고정파일만 사용한다.
- 최대 파일 수 11
- 총량 64 MiB
- 단일 파일 16 MiB
- 이벤트별 신규파일 무한생성 금지
- `/web` 루트에 `mon_*.php` 상태파일 생성 금지

## 10. 통신 원칙
GitHub ETag/304, 동일 회차 메모리 캐시, gzip/deflate, cURL keepalive를 사용한다. GET은 일시 오류만 제한 재시도하고 PUT은 자동 재시도하지 않는다. 동일 결과는 fingerprint로 재게시하지 않으며 중요한 결과 쓰기는 readback으로 검증한다.

## 11. 금지사항
자동 주문/자동매매, cron/부팅 자동시작, 미확인 가격의 현재가 표시, 과거 decision 재사용, 정상 PASS 자료 반복 재검증을 금지한다. 오류 하나 때문에 정상 후보 전체 결과를 폐기하지 않는다.

## 12. 한 줄 운영 원칙
**파일은 적게 만들고, 통신은 덜 하고, 계산은 한 번에 하고, 같은 것은 다시 하지 않으며, 이상이 있을 때만 복구하고, 확인된 범위의 결과는 반드시 저장한다.**

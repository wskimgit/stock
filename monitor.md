# mon 지시문 — MON 2.2.3 Lite Clean Start

> 운영 규격: **MON-LITE-OPS-2.2.3**  
> Build: **`MON-LITE-2.2.3-20261017-r8`**  
> 단일 권위 저장소: **`wskimgit/stock`**  
> 실행 권위 문서: **`monitor.md`**  
> 참고 Wiki: **`MON-Wiki.md`** — 비권위 요약이며 충돌 시 `monitor.md`를 따른다.  
> 실행 코드: **`mon.php`**  
> NAS: `/web/mon.php`, PHP 7.4+, 웹 수동 시작, cron/자동주문 없음

## 1. 목적
한·미·일 스윙 후보를 **완료 일봉** 기준으로 감시한다. PHP/MON은 수집·계산·MS7·3×3 참고순위를 제공하지만 **이는 보조자료일 뿐 최종 추천이 아니다.** 최종 추천은 ChatGPT가 시장과 종목을 독립적으로 다시 분석하여 한국·미국·일본 각 3종목과 최종 MS7을 결정한다. MON 시스템 내부에서는 **ChatGPT 최종결정만 최종 권위**로 취급한다. 주문은 실행하지 않는다.

## 2. Clean Start와 단일 권위
- NAS 로컬 MON 자료가 없어도 `/web/mon_runtime`을 자동 생성한다.
- MON의 현재 코드·지시문·상태·결과는 `wskimgit/stock`만 사용한다. SIS에는 MON CURRENT 사본을 두지 않는다.
- 활성 파일의 `policy`는 모두 `MON-LITE-OPS-2.2.3`이어야 한다.
- 초기 handoff는 `stage=idle`이다. 후보가 없어도 대기만 하지 않고 내장 fallback 후보군(KR/US/JP 각 3개)으로 결과를 만든다.
- 로컬 request 증명이 없는 과거 `decision`은 재실행하지 않는다.
- 새 candidates 요청은 **현재 활성 후보군 전체를 교체**한다. 과거 강한 후보를 유지하려면 새 후보군에 다시 포함한다.

## 3. 활성 파일
| 파일 | 역할 |
|---|---|
| `mon.php` | 유일한 PHP 실행 코드 |
| `monitor.md` | 유일한 실행 권위 지시문 |
| `MON-Wiki.md` | 운영 설명용 비권위 Wiki 요약 |
| `mon_data.json` | 최신 스캔·후보·결과 공유 데이터 |
| `mon_result.md` | 사람이 읽는 최신 결과 |
| `mon_chatgpt_handoff.json` | ChatGPT→MON mailbox |
| `mon_chatgpt_response.json` | MON→ChatGPT mailbox |

`mon_chatgpt_bridge.php` 등 1.x 보조코드는 사용하지 않는다.

## 4. 운영 주기
- Mailbox 확인: **5분**. ETag/304를 사용해 내용이 같으면 본문 다운로드를 생략한다.
- 경량 확인: **30분**.
- 정밀 스캔: 평일 **16:40 KST** 기본.
- 새 candidates 요청은 **대기 상태에서는 최대 5분 안에** 접수한다. 정밀 스캔 중이면 현재 스캔 종료 직후 접수하고, 접수 후 약 30초 뒤 새 후보를 최신 완료봉으로 재확인한다.
- 정상 운용은 최근 구간 증분수집을 우선한다.
- **7일마다 220봉 전체 재동기화**한다.
- 메모리 압박 시 전체를 미루지 않는다. 구형 커널에서 `MemAvailable`이 없으면 reclaimable cache/buffer까지 포함해 가용 메모리를 추정한다.
- 계산된 가용 메모리가 약 20 MiB 이상이면 **3/2/1종목 저메모리 배치**로 처리하고 매 종목 체크포인트 후 메모리를 정리한다. 미처리 후보가 있으면 **2분 후 다음 배치**를 이어간다.
- 약 20 MiB 미만 또는 CPU load 압박처럼 실제 위험 구간에서만 정밀 처리를 30분 연기한다.
- NAS 재부팅 후 자동 시작하지 않는다. 웹에서 다시 시작한다.

## 5. NAS 사전점검
웹 상태와 `?view=health`에서 다음을 점검한다.
- PHP >= 7.4
- cURL 확장
- GitHub token (`sis_private_sync_config.php` 우선)
- 실제 실행 가능한 PHP CLI 절대경로
- **CLI PHP 버전·SAPI·cURL 확장** (`?view=health` 및 시작 직전에 실제 CLI 프로브)
- 웹 `exec()` 사용 가능 여부
- `/web/mon_runtime` 생성·쓰기·용량 제한
- CPU load / 가용 메모리
- GitHub handoff 읽기와 Yahoo 소규모 read-only 통신 (`?view=health` deep 검사; 일시적 통신 실패는 warning)

필수 BLOCKER가 있으면 `시작`을 거부한다. 메모리 압박은 시작 자체를 막지 않고 적응형 소량 배치로 처리하며, 안전 하한 미만 또는 CPU load 압박일 때만 정밀 작업을 연기한다.

## 6. 데이터와 계산
주 원천은 Yahoo 완료 일봉이다. 정상 자료는 재사용하고, 중대한 중복구간 차이가 있는 종목만 220봉 전체 재수집한다. Yahoo의 고립된 과거 OHLC 이상봉은 전체 자료의 약 1% 범위에서만 제외하여 한 개의 공급원 오류가 220봉 전체를 폐기하지 않게 한다. **최신 완료봉 이상은 제외로 우회하지 않고 오류로 유지**한다.

핵심 계산:
- MA5/10/20
- MACD **12·26·9**
- ATR14
- R20 / RS20
- ADV20
- 지지 S / 저항 R
- 눌림·돌파 참고구간

시장 기준:
- KOSPI `^KS11`
- KOSDAQ `^KQ11`
- 미국 `^GSPC`
- 일본 `^TOPX` 우선, 불가 시 TOPIX 추종 `1306.T`, 마지막 대안 `^N225`

`RS20 > 0 AND (MA5 재돌파 OR MACD GOLD/AFTER/BEFORE)`는 **정밀 적격 참고신호**로만 유지하며 추천의 필수조건으로 사용하지 않는다. 추천은 가능한 평가결과를 우선 사용하고, 자료가 부족해도 나라별 3종목을 채운다.

## 7. ChatGPT 1차 후보 발굴
- 사용자가 `mon 지시문을 수행하라`고 하면 **ChatGPT가 먼저 독립적으로 후보를 발굴**한다.
- 신규 후보, 최근 강한 종목, 과거 강한 후보를 참고할 수 있으나 과거 선정 이력에 구속되지 않는다.
- 기본은 나라별 약 10개, 총 30개 내외이며 각 나라는 최소 3개 후보를 넣는다.
- 후보 선택은 단순 기술필터가 아니라 최신 시장 레짐, 업종 주도력, 기업 촉매, 실적·기초체력, 유동성, 가격 위치를 함께 본다.
- 후보가 없거나 handoff가 없으면 MON은 fallback 3×3을 만들 수 있지만 이는 **분석 참고자료**일 뿐 최종추천이 아니다.

```json
{"contract":"MON-LITE-BRIDGE-1.0","policy":"MON-LITE-OPS-2.2.3","stage":"candidates","request_id":"고유 실행 ID","candidate_ids":["KR|KOSPI|005930","US|NASDAQ|NVDA","JP|TSE|6758"]}
```

새 request SHA가 들어오면 해당 후보를 최신 완료봉으로 갱신한다. 후보 ID는 KR=KOSPI/KOSDAQ, US=NASDAQ/NYSE/AMEX, JP=TSE만 허용한다. 잘못된 ID는 `rejected_ids`로 분리한다.

## 8. MON/PHP 분석은 보조 참고
- MON은 MA5/10/20, MACD, ATR14, R20/RS20, ADV20, S/R, MS7과 나라별 3×3 `analysis_reference`를 만든다.
- `RS20 > 0 AND (MA5 재돌파 OR MACD GOLD/AFTER/BEFORE)`는 참고신호일 뿐 ChatGPT 최종결정을 구속하지 않는다.
- pending, benchmark fallback, final recheck 실패, 자료 일부 미확인은 모두 **참고 신뢰도 정보**로만 취급한다.
- MON이 제시한 상위 3종목·MS7을 ChatGPT가 그대로 따를 의무는 없다.
- MON이 분석하지 못한 종목도 ChatGPT가 최신 자료를 별도로 확인해 최종 선정할 수 있다.
- `mon_data.json`에는 `analysis_reference`, `mon_result.md`에는 **최종 아님 / ChatGPT 최종결정 대기**를 명시한다.

## 9. ChatGPT 독립 최종결정 — 유일한 최종 권위
- ChatGPT는 MON 분석결과를 받은 뒤 **처음부터 다시 판단**한다. MON 순위를 단순 복사해서는 안 된다.
- 최종 판단은 최소 다음 요소를 종합한다:
  1. 국가별 시장 레짐·지수 방향·금리·환율 등 큰 흐름
  2. 업종·테마 주도력과 상대강도 변화
  3. 기업 실적·FCF·재무체력·수급·최근 촉매 및 악재
  4. 완료 일봉 가격구조·MA·MACD·거래량·지지/저항
  5. 과열·추격 위험, 손실 위험, 현재 거래가능 가격
  6. MON의 `analysis_reference`와 오류/미확인 정보
- ChatGPT는 위 요소를 종합하여 **KR/US/JP 각각 정확히 3종목**을 최종 선택한다.
- 각 최종 종목의 **최종 MS7도 ChatGPT가 직접 결정**한다. PHP가 계산한 MS7은 참고값이다.
- ChatGPT는 MON 평가완료·적격·final_check 여부에 구속되지 않는다. 필요하면 MON 후보군 밖 종목도 최종 선정할 수 있다.
- 각 최종 종목에는 `reason`과 MS7을 반드시 제공한다.
- PHP는 최종결정의 형식(유효 symbol, 중복 없음, 나라별 3개, 유효 MS7)과 요청 최신성만 검증하며 **종목 내용·순위·MS7을 거부하거나 덮어쓰지 않는다.**
- 저장된 최종결정은 `chatgpt_final`과 `authority=CHATGPT_FINAL`로 기록한다.
- `mon_result.md`의 최종 화면도 ChatGPT 최종 3×3만 권위 있는 추천으로 표시한다.
- 여기서 '최종 권위'는 MON 내부 의사결정 규칙을 뜻하며 투자성과나 예측 정확성을 보장한다는 의미가 아니다.

Decision 예:
```json
{
  "contract":"MON-LITE-BRIDGE-1.0",
  "policy":"MON-LITE-OPS-2.2.3",
  "stage":"decision",
  "request_id":"동일 request_id",
  "candidate_request_sha":"동일 SHA",
  "details_receipt_sha":"동일 details SHA",
  "evaluation_id":"동일 evaluation_id",
  "selected":[
    {"symbol_id":"KR|KOSPI|005930","ms7":"M1","reason":"ChatGPT 독립 종합판단 근거"}
  ]
}
```
## 10. 파일 운영
모든 로컬 부속파일은 `/web/mon_runtime` 안의 소수 고정파일만 사용한다.
- 최대 파일 수: 11
- 총량: 64 MiB
- 단일 파일: 16 MiB
- 이벤트별 신규파일 무한생성 금지
- `/web` 루트에 상태용 `mon_*.php` 생성 금지
- 로그는 `mon.log.php` / `mon.log.old.php`로 PHP guard를 붙여 직접 노출을 줄인다.

## 11. 통신 원칙
- GitHub ETag/304
- 동일 회차 메모리 cache
- gzip/deflate
- cURL handle/keepalive 재사용
- GET의 일시 오류만 제한 재시도
- PUT 자동 재시도 금지
- 동일 결과 fingerprint면 재게시 생략
- 일본 benchmark는 `^TOPX → 1306.T → ^N225` 순의 제한된 fallback만 사용
- Yahoo 과거 이상봉 제외·미세 범위 보정 횟수는 network stats에 기록
- 중요한 결과는 readback 검증

## 12. 상태·오류 분리
운영 상태는 `IDLE / SCAN / WAIT / RESULT / ERROR` 중심으로 표시한다. Mailbox 오류와 결과 게시 오류는 각각 `last_mailbox_error`, `last_publication_error`로 분리해 **시장 스캔 전체 오류로 확대하지 않는다.**

## 13. 금지사항
자동 주문/자동매매, cron/부팅 자동시작, 과거 decision 재사용, 미확인 가격의 현재가 표시, 정상 PASS 자료 반복 재검증, 운영상 필요 없는 파일·통신 확대를 금지한다.

## 14. 한 줄 운영 원칙
**MON은 가볍게 분석하고, ChatGPT는 독립적으로 깊게 판단한다. PHP 분석은 참고자료이고 최종 추천 권한은 ChatGPT에만 있으며, 파일·통신·계산은 최소화한다.**

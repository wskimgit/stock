# mon 지시문 — MON 2.2.3 Lite Clean Start

> 운영 규격: **MON-LITE-OPS-2.2.3**
> Build: **`MON-LITE-2.2.3-20261020-r17`**
> 단일 권위 저장소: **`wskimgit/stock`**
> 실행 권위 문서: **`monitor.md`**
> 참고 Wiki: **`MON-Wiki.md`** — 비권위 요약이며 충돌 시 `monitor.md`를 따른다.
> 실행 코드: **`mon.php`**
> NAS: `/web/mon.php`, PHP 7.4+, 웹 수동 시작, cron/자동주문 없음

## 1. 목적

한·미·일 스윙 후보를 **완료 일봉** 기준으로 가볍게 감시한다. PHP/MON은 수집·계산·3×3 참고후보·참고 MS7을 제공하지만 **최종 추천권은 없다.** 최종 결정은 ChatGPT가 MON 결과를 보조자료로만 사용하고 최신 시장·업종·가격·뉴스·상대강도·추격위험·리스크를 독립적으로 재검토한 뒤 **한국·미국·일본별 최대 3종목과 최종 MS7을 직접 결정**한다. 선정 수를 채우는 목표는 두지 않으며, 근거가 부족하면 일부 또는 전부를 대기로 결론 내린다. **매 실행의 새 결론을 저장하는 책임은 이 ChatGPT 지시문에 있다.** MON 시스템 내부의 최종 추천 권한은 ChatGPT decision에만 있다. 주문은 실행하지 않는다.

## 2. Clean Start와 단일 권위

- NAS 로컬 MON 자료가 없어도 `/web/mon_runtime`을 자동 생성한다.
- MON의 현재 코드·지시문·상태·결과는 `wskimgit/stock`만 사용한다. SIS에는 MON CURRENT 사본을 두지 않는다.
- 활성 파일의 `policy`는 모두 `MON-LITE-OPS-2.2.3`이어야 한다. 코드/지시문은 r17이며 기존 시장 결과에 남은 r10 등의 `build`는 그 결과를 생성한 당시 identity다. 코드 정비만으로 시장 JSON의 시각·build·결과를 새 실행처럼 수정하지 않는다.
- 초기 handoff는 `stage=idle`이다. PHP는 내장 fallback 후보군(KR/US/JP 각 3개)으로 보조자료를 만들 수 있다. 미검증 fallback은 최종 추천이 아니다.
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
- 자원 압박 시 5.1~5.2절의 제한 배치·안전 하한을 적용하고, 미처리 큐와 기준지수 미완료는 **2분 후** 이어서 확인한다.
- NAS 재부팅 후 자동 시작하지 않는다. 웹에서 다시 시작한다.

## 5. NAS 사전점검

웹 상태와 `?view=health`에서 다음을 점검한다.

- PHP >= 7.4
- cURL 확장
- GitHub token (`sis_private_sync_config.php` 및 runtime settings의 유효 설정)
- 실제 실행 가능한 PHP CLI 절대경로
- **CLI PHP 버전·SAPI·cURL 확장** (`?view=health` 및 시작 직전에 실제 CLI 프로브)
- 웹 `exec()` 사용 가능 여부
- `/web/mon_runtime` 생성·쓰기·용량 제한
- CPU load / 가용 메모리
- GitHub handoff 읽기와 Yahoo 소규모 read-only 통신 (`?view=health` deep 검사; 일시적 통신 실패는 warning)

필수 BLOCKER가 있으면 `시작`을 거부한다. 메모리·CPU 압박은 사전점검 결과와 실제 실행 중 자원 관측을 구분하여 5.1~5.2절의 제한 배치·대기를 적용한다.

### 5.1 메모리 운영

- NAS 가용 메모리와 PHP 프로세스의 `memory_limit` 여유를 함께 확인한다. K/M/G 및 바이트 표기를 해석하고 기존의 더 낮은 한도를 임의로 높이지 않는다. 구형 커널에서 `MemAvailable`이 없으면 reclaimable cache/buffer를 포함해 추정한다. NAS 약 20 MiB/PHP 여유 8 MiB 미만이면 2분 대기한다.
- PHP 여유 메모리 24 MiB 미만이면 1~3종목 배치로 처리한다. 잔여 종목을 `cache.scan_queue`에 저장하고 종목별 체크포인트 후 다음 배치에서 이어간다. CPU 부하는 별도의 관측·배치 기준을 적용하며 메모리 하한을 완화하지 않는다.
- 스캔이 끝나면 전체 종목 캐시와 임시 배열을 해제한 뒤 mailbox·결과 게시·대기 단계로 넘어간다. 대기 중 전체 캐시를 붙들고 있지 않는다.
- GitHub 본문 캐시는 개별 256 KiB·총 512 KiB·4파일, Yahoo 임시 캐시는 48종목·종목당 최근 40봉으로 제한한다. 큰 응답을 오래 보존하지 않고, 정상 연결은 재사용하되 전송 본문과 callback 참조는 요청 종료 시 정리한다.
- 수신 본문은 압축 해제 후 최대 24 MiB와 현재 PHP 여유 메모리의 ¼ 중 작은 값으로 제한한다. 초과 응답은 실패로 기록하고 기존 결과·pending 결정을 보존한다.
- 로컬 원문 검증은 전체 파일을 여러 문자열로 읽지 않고 스트림 해시로 비교한다. 원자 교체·정확한 결과 재조회·pending 결정 보존 규칙은 유지한다.
- `state.last_memory`에 데몬의 사용량·최대 사용량·여유량·관측 시각·SAPI를 기록하고 화면에 표시한다. 웹 요청의 메모리와 데몬의 메모리를 구분하며, 실제 NAS 메모리 개선은 새 빌드 배포 후 데몬 관측값으로 확인한다.

### 5.2 CPU 관측과 제한 실행

- 1분 load와 코어 수만으로 CPU가 실제로 포화되었다고 단정하지 않는다. `/proc/stat`의 서로 다른 시각 관측으로 실제 CPU busy와 I/O wait를 구분하고, 측정 시각·신뢰 여부를 함께 기록한다. CPU busy는 90초 이내 관측만 제한 배치 허용의 근거로 사용한다.
- 기존 정상 기준인 코어당 load 1.5 이하는 유지한다. 측정 구간과 측정 후 경과가 모두 1초 이하인 유효 `CPU_BUSY` 표본은 캐시 디코딩 등 MON 자체 작업 직후의 순간 부하일 수 있으므로 **500ms 조용한 구간에서 한 번 재확인**한 뒤 판단한다. 같은 관측에서 이미 100ms 초기 확인을 했다면 중복 대기하지 않는다. 재확인 때 load도 다시 읽으며, 표본이 유효하지 않거나 계속 95% 이상이면 대기를 유지한다. 확인 결과는 `cpu_confirmation`에 남긴다.
- 코어당 load가 1.5 초과~3 미만일 때는 **유효한 최신 busy 측정이 85% 이하이고 메모리 하한을 만족하는 경우에만 1종목 제한 배치**를 허용한다. 정상 스캔의 load 한도를 일괄 상향하지 않는다.
- 코어당 load 3 이상, busy 95% 이상, 또는 높은 load에서 busy를 확인하지 못하면 2분 뒤 다시 확인한다. 중간 busy 구간을 임의로 안전하다고 처리하지 않는다.
- 종목 사이에서도 자원 상태를 재확인하고, 진행한 종목만 체크포인트한 뒤 나머지는 `scan_queue`에 남긴다. 종목을 끝내지 못해도 후보를 삭제하거나 전체 회차를 성공으로 표시하지 않는다.
- CPU 측정·상태 표시를 위해 외부 통신이나 별도 파일을 늘리지 않는다. 메모리·CPU 지연 때문에 ChatGPT 독립 조사와 최종 결론 저장을 막지 않는다.

## 6. 데이터와 계산

주 원천은 Yahoo 완료 일봉이며 한국 기준지수의 확인된 대체 경로는 6.1절을 따른다. 정상 자료는 재사용하고, 중대한 중복구간 차이가 있는 종목만 220봉 전체 재수집한다. Yahoo의 고립된 과거 OHLC 이상봉은 전체 자료의 약 1% 범위에서만 제외하여 한 개의 공급원 오류가 220봉 전체를 폐기하지 않게 한다. **최신 완료봉 이상은 제외로 우회하지 않는다.** 6.2절의 제한된 검증 복구를 통과한 경우만 보정하며, 확인하지 못한 이상봉은 오류로 유지한다.

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

### 6.1 기준지수의 출처·날짜 범위와 분석 재사용

- 최초·전체·증분 수집은 같은 기준지수 선택 경로를 사용한다. 일본은 `^TOPX → 1306.T → ^N225`의 제한된 fallback이며 증분은 마지막 정상 `source_ticker`를 먼저 확인한다. 지수·ETF 신원이 없거나 바뀌면 새 시계열의 220봉으로 **전체 교체**하고 서로 다른 가격 수준을 이어 붙이지 않는다.
- 기준지수의 정상 조건은 HTTP 성공·60봉·최근 조회 시각만이 아니다. 해당 종목 계산에 필요한 **완료 일봉 날짜 전체와 각 날짜의 유효한 실제 종가**가 있어야 한다. 최신 날짜가 누락되거나 종가가 `null`인 응답은 공급원 자료 미완료이며 정상 성공으로 캐시하지 않는다.
- 현재 coverage 검증 버전이 없거나 필요한 날짜를 충족하지 못하면, 4시간 재사용 TTL이 남았어도 해당 기준지수 캐시의 정상 판정을 해제한다. 같은 기준지수는 **한 처리 회차에 한 번의 공통 갱신 작업**을 공유한다. 이 작업은 Yahoo→NAVER 전환 시 두 공급원 HTTP를 포함할 수 있다. 정상 NAVER 갱신은 한 요청, coverage를 충족한 TTL 캐시 재사용은 기준지수 요청 없이 처리한다. GET의 제한된 일시 재시도는 별도다. 최신 공통 지수가 없는데 종목 11개를 각각 다시 다운로드하며 같은 오류를 만들지 않는다.
- 필요한 날짜를 끝내 확보하지 못하면 마지막 정상 봉은 보존하되 해당 시장의 현재 행·남은 큐를 pending으로 유지하고 **2분 후** 재확인한다. 다른 시장·정상 범위·ChatGPT 독립 조사와 새 결론 저장은 계속한다.
- **한국 기준지수는 Yahoo를 1차, NAVER fchart의 같은 지수를 2차로 사용**한다. Yahoo 공급원 통신·파싱 실패 또는 필요 날짜 미충족 시 `timeframe=day`로 **완료 220봉**을 확보해 전체 교체한다. 당일 미완료 봉을 제외할 수 있도록 요청은 221봉이다. HTTP 429/503·전송 오류·잘못된 JSON 등의 제한된 공급원 오류만 대안 경로로 처리하며, 프로그래밍·runtime 오류를 대체 공급원 성공으로 감추지 않는다. KOSPI는 `^KS11 ↔ KOSPI`, KOSDAQ은 `^KQ11 ↔ KOSDAQ`으로만 대응하며 ETF나 다른 시장으로 바꾸지 않는다.
- NAVER로 전환한 뒤에는 `source_provider=NAVER`와 `provider_symbol`을 보존해 정상 캐시와 갱신 경로를 안정적으로 사용한다. Yahoo와 NAVER의 행을 혼합하지 않는다. 공급원이 바뀌면 provider를 포함한 fingerprint로 분석을 다시 계산한다.
- 대체 경로도 **같은 실제 지수의 해당 거래일 값**만 사용하고 공급원·날짜·확인 시각을 남긴다. 미래 일자와 한국 당일 16:00 전의 미완료 봉은 계산에서 제외하며, NAVER의 신원·날짜 순서·OHLCV가 잘못된 응답은 거부한다. 과거 종가를 새 날짜로 복사하거나 휴장일·결측일 값을 임의 생성하지 않는다.
- `benchmark_meta`에 시계열 신원·`source_provider`·`provider_symbol`·마지막 성공/확인 시각·최종 유효 거래일·`required_date`/`required_dates`·`coverage_version`·`coverage_status`·`missing_dates`·`map_fingerprint`·현재 상태/오류를 기록한다. `provider_incomplete`와 `status=unavailable`을 정상으로 읽지 않는다. 정상 분석 재사용은 종목 봉과 `benchmark_source_ticker`, `benchmark_fingerprint`, `calculation_version`이 모두 같을 때만 허용한다. 바뀌거나 기존 fingerprint가 없으면 RS20 등을 한 번 다시 계산한다. 게시 결과의 `network_stats.benchmark_details`에는 provider/symbol·`as_of`·`required_date`·coverage/status를 함께 제공하며 기존 `benchmark_sources` 문자열도 유지한다.
- `RS20 > 0 AND (MA5 재돌파 OR MACD GOLD/AFTER/BEFORE)`는 정밀 **참고신호**다. 후보 수·참고순위·기술 적격을 ChatGPT 최종 추천의 필수조건으로 사용하지 않는다.

### 6.2 완료 미국 일봉의 작은 공급원 오류 복구

- 미국의 **최신 완료 일봉**에서 O/C/V가 유효하고 H/L의 가격 범위 위반이 0.1% 이하인 경우에만 추가 검증을 시도한다. 정상 일봉에는 분봉 요청을 추가하지 않는다.
- 이상봉 하나에 같은 거래일의 정규장 5분봉을 한 번 요청한다. 시간대·날짜·5분 간격이 맞는 유효한 **정규장 78봉 전체**가 있어야 하며, 첫 시가·마지막 종가와 분봉 최고 H·최저 L이 원 일봉의 해당 O/C/H/L에 **각각 상대오차 0.1% 이내**인지 교차 확인한다. 장전·장후 봉, 누락·중복·잘못 정렬된 봉으로 수를 채우지 않는다.
- 위 조건을 통과한 **위반 H/L 항목만** 보정하고 정상 항목과 **원 일봉 O/C/V는 그대로 보존**한다. 단순히 H를 O/C까지 올리거나 L을 내려서 통과시키는 blind clamp와 구분한다.
- 같은 완료 평일·미국 뉴욕 시간 09:30~15:55·통화·전체 봉 정렬·가격 일치·보정 한도를 검증하지 못하면 `YAHOO_LATEST_BAR_INVALID`와 pending을 유지한다. 조기 폐장 등으로 78봉을 확보할 수 없는 날을 정상 전체 세션으로 추정하지 않는다. 실패가 다른 정상 종목이나 ChatGPT의 새 결론 저장을 막지 않는다. 검증 성공 시 `provider_recovery`에 방법·거래일·검증 시각·78봉·출처 URL·보존 여부를 기록하고 집계 횟수를 남긴다.

## 7. 후보 요청

사용자가 `mon 지시문을 수행하라`고 하면 다음 순서로 실행한다. 미확인 PHP 자료 때문에 독립 조사·결론·저장을 기다리게 하지 않는다.

1. 최신 지시문·운영 ref·기존 결과·NAS 상태를 한 번 확인한다. 완료한 이전 작업의 긴 경과를 다시 읽고 재현하지 않으며, 바뀐 identity·자료·미해결 범위만 확인한다.
2. **KR/US/JP의 최신 시장·업종·종목 조사를 독립 작업으로 병렬 진행**한다. 신규 후보와 최근·과거 강한 후보는 같은 현재 기준으로 비교하며, 조사 수나 선정 수에 고정 할당을 두지 않는다. 병렬 작업을 사용할 수 없으면 같은 범위로 순차 수행한다.
3. 종목별 필요한 완료 OHLCV는 가능한 배치 요청으로 **이번 실행에 한 벌만 수집**한다. 완료 거래일·거래소 시간대를 맞춘 같은 자료에서 지표·상대강도·가격 구간을 한 번 계산하고 공유한다. 이미 정상 확인된 봉과 지표를 출처마다 다시 계산하지 않는다. 시각 불일치·가격 충돌·자료 수정이 확인된 부분만 보완한다.
4. PHP details의 정상 종목은 보조자료로 즉시 활용한다. `PARTIAL`, 미확인 국가, NAS 지연·중지, 자료 부족은 해당 범위에만 표시하고, 독립 조사는 가능한 최신 외부 경로로 계속한다. PHP가 만들어 둔 순위·기술신호를 최종 추천의 필수 gate로 삼지 않는다.
5. 출처는 판단에 직접 필요한 원문 중심으로 짧게 기록한다. 같은 뉴스의 재전송·중복 URL을 독립 근거 수로 부풀리지 않으며, 여러 종목에 공통인 시장 근거는 한 번만 조사·참조한다. 종목별 촉매·위험은 필요한 부분만 추가 확인한다.
6. 국가별 판단을 합쳐 ChatGPT가 새 결론을 확정하고 **9절의 원자 결과 저장→쌍 재조회→receipt 재조회**까지 수행한다. 선정이 없거나 일부 자료가 부족해도 이번 실행 시각의 정직한 결론을 저장한다.

PHP에 추가 상세분석을 요청할 후보군은 위 조사 중 필요한 종목으로 구성한다. 내장 fallback의 3×3은 보조자료의 모양일 뿐 조사·추천 할당이 아니다. **최종 추천은 반드시 ChatGPT가 별도 독립 분석으로 다시 결정**한다.

```json
{"contract":"MON-LITE-BRIDGE-1.0","policy":"MON-LITE-OPS-2.2.3","stage":"candidates","request_id":"고유 실행 ID","candidate_ids":["KR|KOSPI|005930","US|NASDAQ|NVDA","JP|TSE|6758"]}
```

새 request SHA가 들어오면 기존 cache가 있더라도 해당 후보는 `CANDIDATE_REFRESH_REQUIRED`로 전환하여 최신 완료봉을 확인한 뒤 details를 만든다. 후보 ID는 중복 제거하며 거래소 조합은 **KR=KOSPI/KOSDAQ, US=NASDAQ/NYSE/AMEX, JP=TSE**만 허용한다. 잘못된 ID는 `rejected_ids`로 분리해 정상 후보 처리를 막지 않는다. 빈 후보군도 `ready_for_chatgpt`로 응답하고 `evaluated_ids`·`unresolved_ids`·`rejected_ids`의 실제 내용으로 확인 범위를 구분한다. 후보 수나 이 상태 문자열을 최종 판단의 필수 gate로 삼지 않는다. 따라서 오래된 캐시나 잘못된 신원을 새 판단 근거로 즉시 사용하지 않는다.

## 8. 최종 결정 — ChatGPT 단독 권한

- PHP/MON이 만드는 `analysis_reference` 3×3과 참고 MS7은 **보조 참고자료**다. 그대로 복사·추종하지 않는다.
- ChatGPT는 최종 결정 전에 MON 자료와 별도로 최신 시장 레짐, 업종·테마 주도성, 현재 거래가능 가격, 추세·상대강도, 거래량, 과열·추격위험, 주요 뉴스·촉매, 하방위험을 독립적으로 확인한다.
- ChatGPT는 필요하면 **PHP 후보군 밖 종목도 새로 발굴하여 선정**할 수 있다. PHP 후보 이력·적격·final_check는 최종 권한을 제한하지 않는다.
- 검증된 종목만 **나라별 0~3종목** 선정하며 수를 채우지 않는다. 선정 없음·근거 제한도 이번 실행의 새 결론으로 반드시 저장한다. 대상국 KR/US/JP의 시장판단은 모두 기록한다.
- 각 최종 종목에는 **종목명 `name`과 종목코드 `symbol_id`를 모두 필수로 포함**하고, ChatGPT가 직접 결정한 MS7, 선정 근거 `reason`, 위험요인 `risk_note`를 포함한다. 현재가·참고가는 실제 확인 시각과 통화를 붙이며, 완료 종가·과거 가격·실시간 가격을 섞어 현재가로 표시하지 않는다.
- **MS7 고정 체계:** `M0 관찰 → M1 매입 검토 → M2 분할매입 → W 대기 → S1 매도 준비 → S2 분할매도 → S3 대부분 매도`.
- ChatGPT final MS7은 PHP 참고 MS7에 의해 자동 변경되거나 덮어써지지 않는다.
- 최종 decision에는 `authority: chatgpt_final`, `independent_analysis: true`, KR/US/JP별 `market_view`가 필수다.
- PHP는 최종 decision에서 종목 ID·국가·중복, 나라별 최대 3개, MS7 코드, 필수 설명, 독립 근거와 저장 무결성을 검증하고 **추천 내용 자체를 재판정하지 않는다**.

최종 decision 필드 예시(시각·출처·ID는 실행 시 확인한 실제 값으로 치환하며 예시 그대로 제출하지 않는다):

```json
{
  "contract":"MON-LITE-BRIDGE-1.0",
  "policy":"MON-LITE-OPS-2.2.3",
  "stage":"decision",
  "authority":"chatgpt_final",
  "independent_analysis":true,
  "decision_id":"고유 결정 ID",
  "analysis_at":"실제 분석 시각 ISO 8601",
  "outcome":"completed",
  "conclusion":"이번 실행의 독립 최종 결론",
  "independent_sources":[{"url":"실제 HTTP(S) 출처 1","checked_at":"실제 확인 시각"},{"url":"실제 HTTP(S) 출처 2","checked_at":"실제 확인 시각"}],
  "request_id":"...",
  "candidate_request_sha":"...",
  "details_receipt_sha":"...",
  "evaluation_id":"...",
  "market_view":{"KR":"...","US":"...","JP":"..."},
  "selected":[
    {"symbol_id":"KR|KOSPI|005930","name":"삼성전자","ms7":{"code":"M1"},"reason":"독립 분석 근거","risk_note":"핵심 위험"}
  ]
}
```

## 9. 결과 저장 — ChatGPT가 끝까지 책임진다

`mon 지시문을 수행하라`는 조사·판단·최종 결론 작성·저장·재조회까지 포함한다. PHP 응답을 기다리다 결론 없이 종료하거나, 화면 답변만 남기거나, handoff 접수만으로 완료하지 않는다. 최종 선정이 없어도 현재 시각·조사 범위·미확인 사유·대기 결론을 저장한다. 실제 시장 판단은 실행 시 최신 자료로 다시 한다.

### 9.1 결정과 저장 상태를 분리한다

- 판단 필드: `authority=chatgpt_final`, `independent_analysis=true`, 고유 `decision_id`·`request_id`·`evaluation_id`, `analysis_at`, `market_view`(KR/US/JP), `selected`, `independent_sources`, `outcome`, 비어 있지 않은 `conclusion`을 기록한다. `outcome`은 `completed`(판단 완료), `limited_evidence`(근거 제한), `no_candidates`(선정 없음) 중 하나다. 종목이 없으면 `selected=[]`, `limited_evidence` 또는 `no_candidates`와 대기 이유를 기록한다.
- 한 종목이라도 선정하면 서로 다른 독립 근거 최소 2개의 실제 HTTP(S) URL과 `checked_at`을 포함한다. `checked_at`은 제출 시 24시간 이내의 실제 확인 시각이며 미래 시각을 만들지 않는다. `analysis_at`은 새 판단 시각이며 완료봉 일자·현재가 확인 시각과 구분한다. 휴장일에는 최신 완료 거래일을 명시한다.
- 새 추천의 독립 분석 시각은 제출 시 24시간 이내다. 네트워크 장애로 이미 검증된 동일 결정을 재게시할 때는 원래 `analysis_at`·근거를 보존하며 날짜를 새로 꾸미지 않는다. 지연된 결과는 과거 판단으로 표시한다.
- 자료 접근 자체가 실패하면 확인하지 못한 URL·가격·추천을 만들지 않는다. `selected=[]`, `outcome=limited_evidence`, 필요 시 `independent_sources=[]`로 하고 확인 범위와 한계를 적은 이번 실행의 새 대기 결론을 저장한다. 근거 최소 수 규칙 때문에 대기 결론 저장까지 막지 않는다.

### 9.2 기본 경로: ChatGPT 직접 GitHub 저장

1. `wskimgit/stock` 운영 ref의 최신 HEAD와 `mon_data.json`, `mon_result.md`, `mon_chatgpt_response.json`을 읽는다. 현재 최신 결정과 충돌하는 과거 작업을 재게시하지 않는다.
2. 동일 `decision_id`·`request_id`·`evaluation_id`·`analysis_at`을 가진 JSON과 Markdown을 작성한다. JSON에는 `chatgpt_final` 및 같은 종목·MS7의 `recommendations`를 기록하고, Markdown에도 식별자·분석시각·국가별 판단·결론·근거·위험·부족 범위를 표시한다. 최종 종목 표시는 **`종목명 (종목코드)`**로 통일한다.
   JSON 최상위는 `contract=MON-LITE-DATA-2.2`, 현재 policy/version/build를 사용한다. `chatgpt_final`은 `contract=MON-LITE-RESULT-2.1`, 현재 policy, `authority=chatgpt_final`, `independent_analysis=true` 및 9.1절 판단 필드를 포함한다. receipt는 `contract=MON-LITE-BRIDGE-1.0`, 현재 policy, `stage=decision`, `authority=chatgpt_final`을 사용한다.
3. **두 결과 파일을 하나의 Git commit으로 원자적으로 갱신**한다. 다중 파일 commit 기능 또는 현재 HEAD를 부모로 한 Git tree/commit과 non-force ref 갱신을 사용한다. 파일별 연속 PUT을 원자 저장이라고 부르지 않는다. 저장 중 HEAD가 바뀌면 최신 파일을 읽고 충돌을 판단한 후 같은 결정을 재적용한다.
4. 게시 commit의 **정확한 ref**에서 두 파일을 cache 없이 다시 읽는다. 예상 바이트/내용 해시와 반환 blob SHA를 확인하고, 결정 식별자·시각·국가별 종목·MS7·미달 사유가 서로 같은지 검사한다. API가 200을 반환한 사실만으로 저장을 확정하지 않는다.
5. 검증된 두 파일의 실제 commit/blob SHA와 결정 식별자를 담은 저장 receipt를 `mon_chatgpt_response.json`에 기록하고 **receipt 자체도 정확한 ref에서 다시 읽는다**. receipt 쓰기/재조회가 실패하면 `pending_receipt`이며 아직 `SAVED_VERIFIED`가 아니다. 이미 맞게 저장된 결과를 다시 계산하지 말고 receipt만 복구한다.
6. 두 파일과 receipt의 재조회가 모두 끝난 뒤에만 **`SAVED_VERIFIED`**를 보고한다. 실제 확인한 commit 및 세 파일의 GitHub 링크와 결정 시각을 제시한다. receipt가 가리키는 결과 쌍과 운영 ref의 활성 결정이 다르면 과거 저장 증명으로만 표시한다.

저장 receipt는 `status=saved`, `decision_id`, `request_id`, `evaluation_id`, `result_blob_sha`, `data_blob_sha`, `result_commit_sha`, `data_commit_sha`, `pair_readback_verified=true`, `saved_at`, `outcome`을 포함한다. 직접 원자 commit 경로에서는 두 결과의 commit SHA가 같다. JSON의 불변 게시 스냅샷에는 `chatgpt_final.save_status=receipt_required`와 `pair_readback_verified=false`가 남을 수 있다. 자기 자신의 미래 blob SHA를 JSON에 넣는 순환을 피하기 위한 상태이며, 이 필드만으로 실패 또는 성공을 판단하지 않는다. **실제 저장 증명은 현재 결과 쌍과 일치하는 receipt 및 세 파일의 재조회**다. PHP 로컬 outbox의 `save_status=saved`도 이 검증 후에만 기록한다.
NAS 경로에서 PUT 응답만 유실되었다면 재조회로 정확한 blob SHA와 바이트는 확인해도 해당 쓰기의 commit SHA를 얻지 못할 수 있다. 이때 해당 commit 필드는 `null`로 보존하고 실제 blob 링크와 운영 ref 재조회 증거를 제시한다. 모르는 commit SHA를 만들거나 다른 commit을 해당 쓰기의 commit으로 표시하지 않는다. 직접 원자 commit 경로의 정확한 commit 재조회 규칙은 유지한다.

### 9.3 NAS 장애·증명 소실 대안

- NAS 연결 실패, PHP 정지, 로컬 request/details 증명 소실은 **ChatGPT 최종 판단·직접 저장의 차단 조건이 아니다.** 독립 조사 후 9.2절의 GitHub 직접 경로를 사용하며 `decision_only` 성격과 MON 자료 미확인 범위를 명시한다. 이 경로의 `evaluation_id`는 ChatGPT 독립 조사 실행을 식별하며 PHP 평가 증명인 것처럼 표시하지 않는다. 없는 `candidate_request_sha`·`details_receipt_sha`를 만들어 NAS proof 검증을 우회하지 않는다.
- 직접 GitHub commit을 사용할 수 없고 NAS mailbox가 동작하면 동일 결정 원문을 `stage=decision` handoff에 전달한다. 이때는 실제 접수된 candidate request SHA·details blob SHA·evaluation ID를 그대로 사용한다. PHP의 `DECISION_PROOF_MISMATCH`는 증명 복구 또는 직접 저장으로 해결하고 가짜 SHA로 대체하지 않는다.
- PHP 경로도 원격 결과 쌍과 receipt가 재조회될 때까지 저장 완료가 아니다. ChatGPT는 응답의 결정 식별자·실제 blob SHA를 다시 대조한다. `pending_remote` 응답은 저장 성공이 아니다.

### 9.4 유한 시도와 영속 복구

- 통신 한 번의 timeout과 제한된 재시도를 사용한다. 같은 장애를 무한 반복하거나 시장 전체를 재스캔하며 대화 처리를 붙잡지 않는다.
- NAS 경로는 **원격 쓰기 전에** 최종 결정 원문·원래 분석시각/근거·JSON/Markdown/receipt의 정확한 게시 내용을 기존 `result.php`에 보존한다. `pending_remote → pending_receipt → saved`로 복구하며, 새 스캔·후보 요청이 미완료 결정을 덮어쓰지 못한다.
- 프로세스가 계속 실행 중이면 다음 주기의 게시 복구에서 동일 내용을 이어간다. 재시작 후에도 먼저 pending 결정을 복구한다. NAS가 꺼지면 수동 재시작 전까지 PHP 복구가 실행되지 않는다.
- 한쪽 파일만 반영되면 readback으로 현재 상태를 판별한 뒤 같은 결정으로 쌍을 맞춘다. readback 실패·receipt 실패 때문에 새 시각/새 결정을 만들거나 정상 파일을 반복해서 덮어쓰지 않는다.
- GitHub가 완전히 불가하면 저장 가능한 로컬 복구 원문을 보존하고 **`PENDING_REMOTE`**와 원인·복구 위치를 보고한다. 로컬 저장도 불가능하면 **`UNSAVED_REMOTE`**와 실제 실패 범위를 보고한다. 이 상태를 완료로 바꾸어 말하지 않는다. 외부 저장소와 디스크가 모두 불가한데 물리적 저장 성공을 보장할 수는 없다.
- 더 최신 결정이 운영 ref에 있으면 오래된 pending 결정을 그 위에 덮어쓰지 않는다. 최신 결과 쌍과 receipt까지 검증되면 이전 복구 원문을 그대로 보존한 채 `superseded`와 대체 결정 식별자를 기록하고 계속한다. 최신 저장 증명이 불완전하면 `CONCURRENT_DECISION_CONFLICT`로 보존하며 직접 저장 경로에서 충돌을 해결한다. `superseded`를 이전 결정의 저장 성공으로 보고하지 않는다. 동일 decision의 반복 접수·재시도는 멱등 처리한다.

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
- PUT의 무조건 자동 재시도 금지: readback 후 실패 단계만 멱등 복구
- 동일 결과 fingerprint면 불필요한 쓰기를 생략하되, 최종 결정의 pending 복구는 실제 원격 readback과 receipt 검증을 생략하지 않는다
- Yahoo 과거 이상봉 제외·미세 범위 보정 횟수는 network stats에 기록
- 중요한 결과는 readback 검증

## 12. 생존·처리·저장 상태의 분리

- 시작 즉시 대기 상태와 새 heartbeat를 기록한다. 상태 응답의 `daemon_liveness`는 `running`이라는 저장 값만으로 생존을 확정하지 않고 실행 lock·heartbeat 시각·실행 identity를 함께 보여 준다. 멈춘 상태의 과거 `running=true`를 정상 실행으로 표시하지 않는다.
- 설치된 `/web/mon.php`의 build/SHA256과 **현재 CLI 데몬이 시작할 때 `state.run_identity`에 기록한 build/SHA256·PID·시작 시각**을 별도로 표시한다. 파일 교체만으로 기존 데몬이 새 코드로 바뀌지 않는다. 불일치는 재시작 필요 상태이며, 웹 상태 조회로 실행 identity를 새 값으로 덮어쓰지 않는다. 구버전 데몬에 실행 identity 증명이 없으면 설치 값으로 채우지 않고 `UNKNOWN`으로 남긴다.
- phase는 `IDLE`(중지), `WAIT`(대기/자원 지연), `SCAN`(처리 중), `RESULT`(현재 참고분석 완료), `SAVE_PENDING`(최종 저장 복구), `ERROR`를 구분한다. 일부 미확인은 `WAIT`와 `progress.state=partial`로 표시한다. phase와 프로세스 생존은 다른 정보다.
- 처리 표시는 국가별·전체 `정상 / 요청 / 미확인 / 큐 잔여`와 자료 시각을 포함한다. 예를 들어 **25/35 정상·일본 10개 미확인**을 전체 정상 완료로 표시하지 않는다. 참고분석 `RESULT`도 ChatGPT 최종 결정 완료를 뜻하지 않는다.
- 현재 지연은 `resource_wait`의 이유·시작/확인 시각·관측 자원과 다음 재시도 시각으로 표시하며, 과거 오류는 `historical_error`의 원래 시각과 구분한다. 이전 CPU 경고를 현재도 차단 중인 것처럼 표시하지 않으며, mailbox 정상 응답을 시장 자료 복구의 증거로 삼지 않는다.
- `last_mailbox_error`, `last_publication_error`와 시장별 오류를 각각 기록해 **시장 스캔 전체 오류로 확대하지 않는다**. 부분 실패를 전체 처리 중단의 근거로 사용하지 않는다.
- **참고분석 게시 상태와 ChatGPT 최종 저장 상태를 별도로 표시**한다. PHP의 `mon_data.json` 게시 성공·참고분석 결과만으로 `SAVED_VERIFIED`를 표시하지 않는다. 최종 저장 증명은 9절의 같은 decision 쌍과 receipt 재조회다. 화면 조회는 마지막 검증 증거·시각을 표시하며, 조회 때마다 정상 결과를 재게시하거나 전체 원격 자료를 반복 조사하지 않는다.

## 13. 금지사항

자동 주문/자동매매, cron/부팅 자동시작, 과거 decision을 새 판단으로 재사용, 미확인 가격의 현재가 표시, 정상 PASS 자료 반복 재검증, 운영상 필요 없는 파일·통신 확대를 금지한다. 또한 **PHP/MON 참고순위나 참고 MS7을 ChatGPT 최종 결정으로 자동 승격하는 것을 금지**한다.

## 14. 새 스캔과 과거 결정

PHP 스캔은 `analysis_reference`와 참고 MS7만 만들며 최종 추천으로 저장하지 않는다. 새 독립 결정이 필요한 스캔에서는 과거 최종 결론을 `previous_chatgpt_final`에 보존하고 활성 `recommendations`를 비운다. `independent_chatgpt_required` 또는 `awaiting_chatgpt`는 새 ChatGPT 결론이 아직 없다는 뜻이다. 과거 결론은 원래 분석시각을 붙인 과거 판단으로 표시한다. `mon_result.md`에 마지막 최종 결론을 보존하더라도 새 스캔의 활성 추천으로 읽지 않는다. 소비자는 JSON의 scan 상태와 `decision_id`를 함께 대조하여 과거 결과·보조분석·현재 최종 결과를 구분한다.

새 스캔은 미완료 최종 결정의 outbox를 덮어쓰지 않는다. GitHub 게시 실패만으로 시장 전체를 다시 스캔하지 않는다. 저메모리 중간 배치는 로컬 체크포인트와 details 응답을 우선하고, pending 후보 큐 한 차례를 시도한 시점에 참고 결과를 게시하여 통신을 줄인다.

## 15. 실행 완료 보고

- ChatGPT는 이번 실행의 새 분석시각, 국가별 결론, 저장 상태를 짧게 보고한다. 검증 부족으로 선정하지 않은 국가도 이유와 대기 결론을 남긴다. 완료한 과거 작업을 반복 나열하지 않는다.
- Markdown 표에 `symbol_id`를 넣을 때 구분자 `|`는 `\|`로 이스케이프한다. 예: `KR\|KOSPI\|005930`. JSON의 실제 ID와 표 밖 일반 텍스트는 원래 `KR|KOSPI|005930`를 유지한다.
- `SAVED_VERIFIED`: 결과 JSON·Markdown·receipt의 실제 재조회 증명이 모두 있다. 결정 식별자와 확인한 GitHub commit/file 링크를 제시한다.
- `PENDING_REMOTE` / `pending_receipt`: 복구 원문은 보존되어 있고 실패 단계의 복구가 남았다. 저장 완료라고 하지 않는다.
- `UNSAVED_REMOTE`: 원격 저장이 검증되지 않았고 영속 복구도 확보하지 못했다. 원인과 마지막으로 성공한 단계를 정확히 알린다.
- 코드 검증 완료, GitHub 반영, NAS 배포, NAS 실동작은 별도 상태다. NAS의 실제 배포 identity와 lifecycle을 관찰하지 못했으면 `DEPLOYMENT_PENDING` 또는 `UNKNOWN`으로 보고한다.

## 16. 한 줄 운영 원칙

**ChatGPT가 최신 자료로 독립 판단하고 매 실행의 새 결론을 끝까지 저장·재조회한다. PHP는 보조자료와 영속 복구를 맡으며, 검증되지 않은 저장이나 과거 판단을 현재 완료로 표시하지 않는다.**

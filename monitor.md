# mon 지시문 — 한·미·일 종목 선정·매매시점 판단

- 설계 버전: **3.0** / 판단 기준 버전: **MON-P2.0** / 인터페이스 `schema_version`: **2** / 결정일: **2026-10-08**.
- 저장소: **wskimgit/stock** / 브랜치: **main** / 파일: 저장소 루트 **monitor.md**.
- 구조: **분석 지시문 1개 + PHP 수집기 1개 + 공유 문서 1개**. 사용자가 지정한 이미지의 역할과 흐름을 유지한다.
- 이번 결정은 구현에 사용할 설계 기준이다. 합성 데이터 시뮬레이션으로 흐름·조건·인터페이스를 확인했으며, 실계좌 API 연결 시험이나 투자 성과 백테스트를 실시한 것은 아니다.
- 현재 운영 상태: PHP 미구현·미배포, 수집·추천 미실행, 예약 미등록. 아래 운영 구역은 빈 양식이며 `enabled=false`다.
- 기존 코드와 Eagle·SIS·MS7 등은 참고 자료다. 기존의 서로 다른 조건을 필수 조건으로 자동 합산하지 않는다.
- 공식 명칭: **mon 지시문** / PHP 수집 코드 파일명: **mon.php** / 공유 저장 파일: 저장소 루트 **monitor.md**. 실행 문구: **“mon 지시문을 수행하라.”**

## mon 지시문 — 실행용

<!-- MON:INSTRUCTION:BEGIN -->
```text
# mon 지시문 v3.0

명칭: mon 지시문. 판단 기준: MON-P2.0. 인터페이스: schema_version 2.
저장소: wskimgit/stock, 브랜치 main, 저장소 루트 monitor.md.
연계 코드: mon.php. 실행 문구: “mon 지시문을 수행하라.”

너는 한국·미국·일본 종목의 독립 선정과 매입·매도 시점 판단을 담당한다. 사용자가 실행을 요청하면 아래 절차를 수행하고 결과를 monitor.md에 저장한 뒤 핵심 결과를 보고한다. 사용자가 지정한 대상국·종목·범위가 있으면 그 범위를 우선하고, 기본 대상은 한국·미국·일본이다.

1. 기본 원칙
- 독립 조사와 판단을 우선한다. mon.php가 모은 시세는 가격·시각·자료 상태를 보강하는 보조자료다.
- 기존 Eagle·SIS·MS7 및 다른 지시문은 참고한다. 그 지시문의 숫자 문턱을 새 필수 조건으로 합산하지 않는다.
- 기존·신규 후보를 같은 최신 기준으로 경쟁시킨다. 신규성·과거 선정 이력·조회 순서·이전 순위에 가산점이나 감점을 주지 않는다.
- 같은 고정 입력과 같은 기준 버전에서는 같은 종목·순위·행동·가격 계획을 출력한다. 새 자료와 다른 판단시점에서는 결과를 갱신한다.
- 수집·판단·기록이라는 세 역할을 유지한다. 이 지시문의 실행은 mon.php 배포·활성화 또는 예약 등록을 포함하지 않는다.

2. 문서 읽기와 독립 자료 확보
- GitHub에서 main/monitor.md의 최신 본문과 blob SHA를 읽는다. WATCHLIST·COLLECTION·ANALYSIS의 JSON, 기준 버전, 설정, 이전 결과, 보유 등록을 확인한다.
- schema_version 또는 기준 버전이 맞지 않거나 구역이 손상되었으면 임의 초기화·덮어쓰기를 하지 않는다. 독립 분석이 가능한 범위는 수행하고 저장 문제를 따로 보고한다.
- 현재 목록, 최근 20거래일의 관찰·추천·제외 후보, 최신 근거를 확인한 과거 강한 후보, 이번 신규 후보를 국가|거래소|코드인 symbol_id로 병합한다.
- 신규 후보는 국가별 20종목 탐색을 목표로 한다. 적게 확보되면 탐색 범위와 부족 사유를 기록하고 확보한 자료로 계속한다.
- 최신 시장·업종 흐름, 기업 공시·주요 사건, 거래 가능 여부, 완료 일봉 및 비교 지수를 독립 조회한다. 각 근거의 출처 URL·조회시각·자료 기준시각을 기록한다.
- 완료 일봉 60개 확보를 목표로 하되 R20·RS20 계산에는 종목과 지수의 비교 가능한 완료 종가 21개를 확보한다. 진행 중 일봉, 중복 봉, 잘못된 날짜·조정 단위는 계산에서 제외한다.
- 한국은 해당 시장의 KOSPI/KOSDAQ, 미국은 S&P 500, 일본은 TOPIX와 같은 기간을 비교한다. 종목과 지수의 기준일·가격 조정 방식을 맞춘다.
- mon.php 미실행, enabled=false, 일부 자료 실패만으로 전체 독립 분석을 중단하지 않는다. 없는 가격·봉·거래량·보유정보를 만들지 않는다.
- 조회를 마친 자료를 입력 스냅샷으로 묶고 평가시점 as_of를 고정한다. 그 뒤 도착한 새 자료는 다음 평가에 사용한다.

3. 계산과 동등 경쟁
- R20 = 최신 완료 종가 / 20거래일 전 완료 종가 − 1.
- RS20_pp = 100 × (종목 R20 − 같은 기간 지수 R20). 단위는 퍼센트포인트다.
- MA5·MA10·MA20은 완료 종가의 단순평균이다.
- ATR14는 최근 14개 TR의 단순평균이다. TR = max(고가−저가, |고가−직전종가|, |저가−직전종가|).
- ADV20은 최근 20일 원시 거래대금 평균이다. 공식 거래대금 또는 같은 날 원시 종가×실제 거래량을 사용한다.
- MA60·RS60·기울기·거래량·시장 정렬·주도 업종·과열은 확보된 범위에서 참고 설명에 사용한다. 국가별 시장 상태와 근거를 짧게 표시하되 시장 약세만으로 모든 후보를 막지 않는다.
- 국가별 순위는 RS20_pp 내림차순 → R20 내림차순 → ADV20 내림차순 → symbol_id 오름차순으로 고정한다. ADV20 결측은 0으로 계산한 값으로 기록하지 않고 null을 유지하며, 동점 정렬에서는 확인된 거래대금 뒤에 둔다.
- R20≥0이고 RS20_pp≥0이면 pass, 비교 가능하지만 이 표시 조건에 못 미치면 near다. pass와 near를 같은 순위에서 경쟁시키며 pass부터 채우지 않는다.
- 식별 또는 필수 비교 자료가 부족하면 unverified다. RS20을 임의의 0으로 만들어 순위에 넣지 않는다. 확인된 거래 불가·중대한 부정 사건은 fail로 신규 매입 검토에서 제외하고 보유 감시는 계속한다.
- RS20 +3pp, RS60 양수, MA20>MA60, MA60 상승, 국가별 거래대금 하한, 65봉 최소, 지수 상승 정렬을 강제 탈락 조건으로 사용하지 않는다.
- 비교 가능한 후보에서 국가별 관찰 상위 최대 10개를 정한다. 그 목록의 비보유 상위 최대 3개를 BUY_REVIEW로 정한다. 보유분은 관찰 상한 밖에서도 추가로 유지한다. 정원을 채우려고 미검증 종목을 끼워 넣지 않는다.
- OHLC·ATR·시세 일부 부족으로 비교 가능한 상위 종목을 다른 종목으로 교체하지 않는다. 부족한 가격 계획이나 확인 상태만 표시한다.

4. 매입 계획과 현재 가격 확인
- 계획은 독립 완료 일봉 사실로 계산하고, 가격 확인은 독립 최신 시세 및 유효한 COLLECTION으로 보강한다.
- S = 최신 완료 종가 이하에 있는 MA5·MA10·MA20·최근 5완료일 저점 중 가장 높은 값.
- R = 최신 완료일을 제외한 직전 20완료일 고가의 최댓값.
- 눌림 계획: 진입 구간 [S−ATR14, S+ATR14], 무효화 참고값 min(최근 5일 저점,S)−0.5×ATR14.
- 돌파 계획: 진입 구간 [R,R+ATR14], 무효화 참고값 R−ATR14.
- 실제 호가단위로 구간 하단은 올림, 상단과 무효화는 내림한다. 0<무효화<하단≤상단을 만족하는 계획만 기록한다. 필요한 지지·OHLC·ATR·호가단위가 부족하면 해당 계획은 null이다.
- 계획상 위험 비율 = 100×(구간 상단−무효화)/구간 상단을 표시한다. 일률적 5% 위험 상한으로 선정·추천을 삭제하지 않는다.
- 현재 가격은 동일 종목·거래소·통화이고 양수이며, 유효 시세시각·거래일·정규장 상태를 확인한 자료를 사용한다. 시세 나이≤300초, 확인된 공급자 지연≤300초, 미래 시각 오차≤5초를 적용한다.
- market_date는 quote_at의 거래소 현지 날짜 및 as_of의 현지 거래일과 일치해야 한다. 시세시각을 수집시각으로 대체하지 않는다.
- 유효 가격 1개가 계획 구간 안에 있고 거래 가능·위험 확인이 충족되면 readiness=ready다. 두 구간이 겹치면 눌림 계획을 먼저 활성화한다.
- 가격이 구간 밖이거나 지연·시각·세션·기업 위험 확인이 부족하면 BUY_REVIEW를 유지하고 readiness=conditional로 기록한다. 두 가격점 반등이나 거래량 증가는 보강 근거이며 필수 문턱이 아니다.
- 장중 계획은 해당 정규장 마감, 장외 계획은 다음 정규장 마감까지 유효하다. 독립 근거가 바뀌면 재계산한다. 현재 가격 확인은 price_as_of+300초에 만료된다.

5. 보유·매도 판단
- 실제 등록된 보유분만 HOLD 또는 SELL_REVIEW로 평가한다. 보유 등록이 없으면 매입가·수량·손익을 만들지 않는다.
- 유효하게 등록된 initial_stop·trailing_stop 중 더 높은 기준에 최신 확인 가격이 도달하면 SELL_REVIEW다. 등록 기준을 사후로 낮추지 않는다.
- 최신 완료 종가가 MA20 아래이면서 최신 완료일을 제외한 직전 10완료일 저점보다 낮으면 추세 붕괴로 SELL_REVIEW다.
- 확인된 중대한 기업 근거 훼손이면 SELL_REVIEW다. 실행 가능한 최신 가격이나 거래 가능 여부가 불명확하면 conditional을 함께 표시한다.
- 그 밖의 등록 보유분은 HOLD다. 단순 MA20·MA60 이탈 하나만으로 매도를 확정하지 않는다. 부족한 근거는 확인 필요 사유로 기록한다.

6. mon.php 보조자료 사용
- mon.php는 WATCHLIST를 읽어 한국투자증권 API 우선으로 최소 시세를 수집하고 COLLECTION만 갱신한다. 한국은 지원·매핑 확인 후 네이버→야후, 미국·일본은 야후로 보완한다.
- COLLECTION의 목록 버전·symbol_id·거래소·통화·시각·가격 종류·지연 상태를 검증한다. 신규 종목의 PHP 자료가 아직 없으면 수집대기로 표시하고 독립 근거가 충분한 분석은 계속한다.
- 보조자료는 시세 나이≤900초 범위에서 참고할 수 있으나 ready의 가격 확인에는 300초 조건을 별도로 적용한다. 지연된 가격을 실시간 가격이라고 표시하지 않는다.
- 같은 종목·통화·거래소·세션·거래일에서 시각 차이≤120초인 가격들이 2% 넘게 다르면 가격 확인 충돌이다. 변동과 가격 종류를 확인하고 최대 1회 독립 재조회한다.
- 해결되지 않은 충돌은 conditional과 사유로 남긴다. 가격을 평균내거나 후보 순위를 바꾸지 않는다.
- independent_results를 먼저 확정하고 results에 보조 확인 상태를 기록한다. 선정 순위는 독립 평가 사실이 바뀔 때만 갱신한다.

7. 동일 입력·동일 결과와 저장
- 계산은 코드 도구로 확인한다. 비교값은 소수 6자리 ROUND_HALF_UP, −0은 0으로 정규화한다. 호가 계산은 십진 연산을 사용한다.
- 후보·키를 정렬하고 해시용 숫자는 소수 6자리 문자열, 시각은 UTC의 YYYY-MM-DDTHH:mm:ss.ffffffZ로 정규화한다.
- selection_fingerprint는 기준 버전과 판단에 쓰인 독립 사실, input_fingerprint는 이 값과 고정 as_of·사용 가격·자료 상태, result_fingerprint는 종목·순위·행동·readiness·가격 계획·무효화에 대한 SHA-256이다.
- 같은 input_fingerprint와 기준 버전의 저장 결과가 있으면 재사용한다. 재계산이 다르면 기존 정상 결과를 덮지 않고 일관성 오류를 보고한다. 해시를 계산하지 못했으면 추측하지 말고 미확인으로 기록한다.
- 현재 monitor.md의 데이터 폼에 따라 WATCHLIST·ANALYSIS 두 JSON 구역을 한 커밋으로 갱신한다. COLLECTION과 정적 지시문·설계 본문을 보존한다.
- WATCHLIST에 관찰종목·보유분·국가·거래소·코드·공급자 매핑·확인된 호가단위를 기록한다. 목록 또는 수집 정의가 바뀌면 watchlist_version을 올린다. 가격·시각 표시만 바뀌면 불필요하게 올리지 않는다.
- ANALYSIS에 기준 버전·as_of·실행 및 입력 식별값·국가별 coverage·독립 결과·최종 결과·전체 후보 audit·최근 후보 이력·근거와 변경 이유를 기록한다.
- 사용자가 특정 국가만 실행한 경우 다른 국가의 기존 기록은 이전 기준시각을 유지해 보존하고, 이번에 재평가한 것처럼 표시하지 않는다.
- 최신 blob SHA로 저장한다. 충돌하면 최신 본문을 읽고 자기 두 구역만 재병합하며 최대 2회 재시도한다.
- 일부 실패면 partial과 사유를 남기고 가능한 결과를 저장한다. GitHub 저장 실패는 분석 완료와 구분해서 보고하며 저장 성공으로 표시하지 않는다. 이전 정상 문서를 초기화하지 않는다.

8. 실행 시각과 최종 보고
- 수동 요청 때 실행한다. 예약이 별도로 등록된 경우 한국·일본은 한국시간 09:40·14:00, 미국은 뉴욕시간 10:00에 수행한다. 휴장·점심 휴장·조기 폐장·서머타임을 구분한다.
- mon.php의 계획 주기는 수집 60초·미러 180초다. 이 지시문을 매분 실행하는 뜻이 아니다. enabled=false 설정을 임의 활성화하지 않는다.
- 사용자에게 국가별 매입 검토·보유/매도 검토 핵심 결과를 표로 보여 준다. 기본 열은 국가·종목(코드)·판단/확인 상태·관측가격/시각·매입 구간/무효화·이유/다음 확인이다.
- 각국 매입 검토는 최대 3개이며, 전체 관찰·미검증·제외 사유와 계산 근거는 monitor.md에 보존한다. 결과가 없으면 휴장·자료 미확인·후보 부족 중 실제 이유를 표시한다.
- 끝에 분석 기준시각, 확인된 범위의 신규/전체 후보 수, 최종 상태, monitor.md 저장 성공 여부와 링크를 짧게 표시한다.
- 기존 합성 규칙 시험 52 PASS와 이번 실제 조사·시세 확인·실연결·성과 검증 여부를 구분한다. 검증 및 운영 상태는 최신 기록과 실제 수행 근거로 표시한다. 수행하지 않은 조사·수집·검증·예약을 완료했다고 쓰지 않는다.
```
<!-- MON:INSTRUCTION:END -->

## 1. 모듈별 입력·처리·출력

| 모듈 | 입력 | 처리 | 출력·작성 권한 |
|---|---|---|---|
| mon 지시문 | 독립 조회한 시장·기업·완료 일봉·가격 근거, 기존 후보, 보유 등록, 유효한 PHP 자료 | 입력 고정 → 독립 상대 순위 → 동일 입력 재사용 → 보조 가격 확인 | WATCHLIST와 ANALYSIS 두 구역을 한 커밋으로 갱신 |
| mon.php | WATCHLIST의 설정·달력·국가·거래소·종목 매핑 | 최소 시세 수집·형식 검증·시각 정규화·자료 상태 판정 | COLLECTION 구역만 갱신 |
| monitor.md | 위 두 작성자의 기록 | 최신 정보 교환과 이력 보존 | 세 쌍의 마커 안 JSON이 기계 입력의 기준 |

지시문은 PHP의 평가 결과를 받아 판단하는 구조가 아니다. 후보 평가와 판단 근거를 독립적으로 산출하고, PHP 시세로 가격·시간 조건을 보강하거나 오류를 재확인한다. PHP는 평가·매매 추천·주문을 수행하지 않는다.
새 관찰종목은 수집 완료 전에는 수집대기다. 필수 근거를 독립 조회로 충분히 확보한 경우 PHP 수집대기만을 이유로 독립 분석 전체를 중단하지 않는다.

## 2. 운영 기준 재조정

최우선 원칙은 **같은 후보·자료·판단시점·기준 버전이면 같은 종목·순위·행동·가격 조건을 출력**하는 것이다. 독립 선정 결과를 우선하고 보조자료의 결측·지연으로 선정 목록을 조용히 바꾸지 않는다.
새로운 후보·완료봉·검증된 기업 근거·시세 또는 판단시점이 들어오면 입력이 달라진 것이므로 결과를 갱신한다. 서로 다른 시장 상황에서도 같은 종목을 고정하거나 기존의 다른 지시문과 무조건 같은 결과가 나온다고 선언하지 않는다.

| 항목 | 재조정된 결정 |
|---|---|
| 관찰·추천 수 | 국가별 관찰 최대 10개, 신규 매입 검토 최대 3개. 부족하면 확보한 수만 표시. 보유종목은 별도 계속 관찰 |
| 선정·가격 확인 | 상위 후보의 BUY_REVIEW 계획과 ready/conditional을 분리한다. 가격 미확인·지연·위험 확인 부족은 조건부 상태이며 선정 탈락이 아니다 |
| 후보 범위 | 현재 목록 + 최근 20거래일 후보 + 최신 근거가 있는 과거 강한 후보 + 신규 후보. 기존·신규 가산점 없음 |
| 신규 탐색 | 각국 20종목 탐색 목표. 부족하면 범위를 기록하며 분석을 중단하지 않음 |
| 완료 일봉 | 60개 확보 목표. 20일 상대강도 비교에는 21개 필요. MA60·RS60은 확보될 때 참고하며 선정 필수 아님 |
| 부분 자료 | 종가·벤치마크로 순위가 계산되면 OHLC·ATR·가격 일부 부족으로 종목을 탈락시키지 않음. 해당 가격 조건만 미확인 |
| 시세 유효시간 | 보조 확인 900초, 가격 확인 300초, 알려진 지연 300초. 더 늦은 자료는 종목 추천을 유지하고 현재 가격 조건부로 표시 |
| 가격점 수 | 유효 가격점 1개로 가격 구간을 확인할 수 있음. 두 가격점과 거래량은 보강 자료 |
| 시장 약세 | 시장 레짐을 표시하고 지지·돌파 계획을 구분한다. 지수 MA20<MA60만으로 모든 후보를 막지 않음 |
| 가격 위험 | 임의의 5% 상한으로 종목 삭제하지 않음. 계획상 위험 비율·무효화 근거를 표시. 이미 등록된 실제 손절·추적 기준은 낮추지 않음 |
| 수집·미러·분석 | 60초 수집, 180초 미러, 분석 60초 전 미러, 09:40·14:00·미국 현지 10:00 유지 |
| 기술적 처리 예산 | 수집 45초 + 게시 10초, 전체 최대 55초. 연결 2초·시세 요청 5초·GitHub 요청 3초, 요청 시작 간격 1.25초, SHA 충돌 최대 2회 재병합 |

개별 종목의 거래 불가 또는 중대한 부정 사건이 실제로 확인되면 신규 매입 검토에서 제외하고 보유분은 계속 관찰한다. 미확인 상태를 확인된 문제로 취급하지 않는다.
코드·통화·거래소·시세 날짜가 맞지 않는 자료, 손상 JSON, 만들어낸 가격·시각은 사용하지 않는다. 숫자 문턱 완화와 데이터 오류 허용을 혼동하지 않는다.
달력이 없거나 만료되어도 모든 분석과 수집을 중단하지 않는다. 표준 거래시간 창에서 수집할 수 있으나 세션은 unknown으로 두고, 공식 달력 또는 공급자의 검증된 장 상태가 확인될 때 가격 확인 완료로 표시한다.
60초 수집은 매분 AI 판단 생성의 의미가 아니다. 미처리 항목은 순환 커서로 다음 실행을 계속하고, 게시 실패 자료는 실행 환경의 캐시에 보존한다.

### 분석 시각

| 슬롯 | 대상 | 시간 |
|---|---|---|
| 오전 | 한국·일본 장중, 미국 직전 완료장 요약 | Asia/Seoul 09:40 |
| 오후 | 한국·일본 재평가 | Asia/Seoul 14:00 |
| 미국 | 미국 개장 30분 후 | America/New_York 10:00; 한국시간 서머타임 23:00 / 표준시 다음날 00:00 |

국가별 휴장·점심 휴장·조기 폐장·서머타임을 구분한다. 장외에도 최신 완료봉으로 조건부 가격 계획을 출력할 수 있으며 장중 현재가 확인과 구분한다.

## 3. 후보 선정 MON-P2.0 — 탈락 문턱보다 상대 경쟁

### 동일 후보 집합과 계산

현재 관찰목록·최근 20거래일 후보·과거 강한 후보·이번 신규 후보를 symbol_id로 병합한다. 같은 종목의 최신 사실이 서로 다르면 재확인하고 높은 점수만 골라 취하지 않는다.
기존·신규 출신, 조회 순서, 이전 순위, HTTP 수집시각은 선정 순위에 가산점·감점으로 넣지 않는다.

- `R20 = C_t/C_(t-20)−1`, `RS20_pp = 100×(종목 R20−같은 기간 시장 R20)`. 21개 완료 종가가 필요하다.
- 벤치마크는 KOSPI/KOSDAQ 해당 시장, 미국 S&P 500, 일본 TOPIX. 해당 종목과 비교 지수의 완료 거래일·조정 방식을 맞춘다.
- MA5·MA10·MA20은 단순평균. MA60, RS60, 기울기는 참고 자료이며 선정의 필수 조건이 아니다.
- ATR14는 최근 TR 14개 단순평균. TR은 max(고가−저가, abs(고가−직전종가), abs(저가−직전종가)). 가격 계획에 필요한 OHLC가 부족하면 ATR과 계획은 null로 남긴다.
- ADV20은 최근 20일 원시 거래대금 평균. 공식 거래대금 또는 같은 날 원시 종가×실제 거래량을 사용한다. 조정 가격×원시 거래량은 섞지 않는다.
- 추세·가격 계획은 현재 가격 단위에 맞춘 일봉을 사용하며 진행 중 일봉은 포함하지 않는다. 결측을 가짜 봉으로 채우지 않는다.

### 선정과 표시 규칙

| 구분 | 새 규칙 |
|---|---|
| 순위 | **RS20_pp 내림차순 → R20 내림차순 → ADV20 내림차순 → symbol_id 오름차순**, 국가별 계산 |
| PASS | 비교 가능한 자료에서 R20≥0 및 RS20_pp≥0. 강도 상태 표시이며 강제 선정 문턱 아님 |
| NEAR | 비교 가능한 자료가 있고 PASS 표시 조건에 못 미침. PASS와 함께 같은 순위표에서 경쟁 |
| UNVERIFIED | 종목 식별 또는 20일 계산에 필요한 자료가 부족. 실패로 단정하지 않고 추가 관찰·확인 필요로 표시 |
| FAIL | 확인된 거래 불가·중대한 부정 사건. 신규 진입 계획에서 제외하되 보유 감시는 유지 |
| 관찰 선정 | 비교 가능한 후보 전체의 상대 순위 상위 10개. PASS 먼저·NEAR 나중 채우기 규칙 제거 |
| 매입 검토 | 관찰 상위에서 보유분을 제외한 3개에 조건부 가격 계획을 제공. ready인 종목만 골라 후보를 교체하지 않음 |

**삭제한 강제 문턱:** RS20 +3%p, RS60 양수, 종목 20일 수익 양수, MA20>MA60, MA60 상승, 국가별 거래대금 하한, 65봉 일괄 최소, 지수 상승 정렬.
시장과 업종 주도성·과열·이격·유동성·기업 위험은 설명과 가격 확인 상태에 반영한다. 확인되지 않은 주관적 등급을 임의로 새 순위 가산점으로 만들지 않는다.
자료가 부족한 후보는 UNVERIFIED로 따로 남긴다. 이전 추천을 최신 비교에서 이긴 것처럼 표시하거나 RS20을 0으로 만들어 경쟁시키지 않는다.

### 동일 입력·동일 결과를 최우선으로 하는 규격

1. 평가 시작 때 후보 집합·완료봉 기준일·기업 사실·보유 등록·가격점·고정 판단시점 `as_of`를 묶어 입력 스냅샷을 만든다. 평가 중 새 자료는 다음 스냅샷으로 넘긴다.
2. 가격·지표 비교값은 소수 6자리로 ROUND_HALF_UP 정규화하며 −0은 0으로 통일한다. 호가·가격 구간 계산은 십진 연산을 사용해 이진 부동소수점 오차로 한 호가가 달라지지 않게 한다. JSON 키와 후보 symbol_id를 정렬한다. 해시용 숫자는 소수 6자리 문자열, 시각은 UTC의 `YYYY-MM-DDTHH:mm:ss.ffffffZ`로 직렬화한다. 거래일 YYYY-MM-DD는 거래소 현지 날짜를 유지한다.
3. `selection_fingerprint`는 기준 버전과 독립 평가 사실에 대한 SHA-256이다. PHP 가격·수집 순서·후보 출신·설명 문장·실행 ID를 넣지 않는다.
4. `input_fingerprint`는 selection_fingerprint + as_of + 판단에 실제 사용한 가격·자료 상태를 정규화한 SHA-256이다. 무관한 조회 메타데이터를 넣지 않는다.
5. `result_fingerprint`는 종목·순위·행동·readiness·가격 계획·무효화의 정규 JSON SHA-256이다. 생성형 설명 문구는 비교 대상에서 제외한다.
6. 같은 input_fingerprint·criteria_version으로 재실행하면 저장한 업무 결과를 재사용한다. 재계산한 결과가 다르면 기존 정상 결과를 덮지 않고 일관성 오류를 기록한다.
7. 가격 보조자료만 바뀌면 독립 선정 목록과 순위는 유지하며 가격 확인 상태·활성 가격 계획만 갱신한다. 새로운 완료봉·후보·검증된 기업 사실은 독립 입력 갱신으로 기록한다.
8. 실시간 조회를 다시 실행하여 입력이 달라진 경우를 동일 입력 재실행이라고 부르지 않는다. 데이터·기준이 달라졌는데 결과를 억지로 고정하지 않는다.

기준 버전은 판단 수식·순위·정원·반올림·상태 전환을 포함한다. 이를 바꾸면 버전을 올린다. 생성형 AI의 자유 서술 자체가 매번 동일하다고 보장하는 규격이 아니다.

## 4. 매입·매도 재조정 — 추천과 현재 가격 확인 분리

### 매입 계획

상위 3개는 `BUY_REVIEW`로 유지하고, 확인이 부족하면 `readiness=conditional`로 기록한다. 이를 즉시 매수 지시로 표시하지 않는다.
MA20 한 구간만 허용하던 규칙을 없애고 독립 완료봉에서 확인한 가까운 지지와 돌파 가격을 함께 사용한다.

1. `S`는 최근 완료 종가 이하에 있는 MA5·MA10·MA20·최근 5일 저점 중 가장 높은 값이다.
2. `R`은 최신 완료일을 제외한 직전 20일 고가의 최댓값이다. 최신일을 포함하여 돌파할 수 없는 저항을 만들지 않는다.
3. 눌림 계획 구간은 **[S−ATR14, S+ATR14]**. 무효화 참고값은 min(최근 5일 저점,S)−0.5×ATR14.
4. 돌파 계획 구간은 **[R,R+ATR14]**. 무효화 참고값은 R−ATR14.
5. 실제 호가단위로 하단은 올리고 상단·무효화는 내린다. 양수 무효화<구간하단≤구간상단을 확인한다. 지지·ATR·호가단위가 없으면 계획은 null이며 후보 추천을 삭제하지 않는다.
6. 같은 종목·통화·거래소의 유효 가격 1개가 어느 계획 구간에 있고 거래 가능·위험 확인이 충족되면 ready다. market_date가 quote_at의 거래소 현지 날짜 및 as_of의 현지 거래일과 같아야 한다. 오프셋 없는 시각·손상 시각·음수 지연은 현재 가격 확인에 쓰지 않는다. 두 구간에 동시에 있으면 눌림 계획을 먼저 선택한다.
7. 두 가격점 반등, 최신 일봉 양봉, 10일 고점에서 정해진 폭 하락, 거래량 증가, 시장 MA 정렬을 모두 필수로 요구하지 않는다. 확보되면 보강 근거로 표시한다.
8. 가격이 구간 밖·300초 초과·지연 300초 초과·시세시각 미확인·거래세션 미확인이면 조건부다. 가격 계획과 상위 종목은 유지한다.
9. 계획상 가격 위험 비율은 표시하되 임의의 5% 제한으로 선정·추천을 탈락시키지 않는다. 실제 진입 후 등록된 손절·추적 기준은 이 완화의 대상이 아니다.

가격 계획은 장중 분석이면 해당 정규장 마감까지, 장외 분석이면 다음 정규장 마감까지 유효하며 독립 근거가 갱신되면 다시 계산한다. 가격 확인의 만료는 `price_as_of+300초`다. 실제 진입 전에는 최신 거래가능 가격과 해당 조건을 확인한다.
가격 계획은 독립 일봉 사실로 만들고 PHP 가격은 계획의 충족 상태를 보강한다. 늦은 가격 때문에 더 낮은 순위의 종목으로 추천을 교체하지 않는다.

### 보유·매도

- 실제 등록된 initial_stop/trailing_stop 중 유효한 더 높은 기준에 최신 확인 가격이 도달하면 SELL_REVIEW. 등록된 기준을 사후로 낮추거나 과거 매입가를 만들지 않는다.
- 단순 MA20·MA60 이탈만으로 매도 확정하지 않는다. **최신 완료 종가가 MA20 아래이면서 직전 10완료일 저점보다 낮을 때** 추세 붕괴로 SELL_REVIEW.
- 확인된 중대한 기업 근거 훼손이면 SELL_REVIEW. 거래정지·가격 미확인 등 실행 가능성이 불분명하면 조건부로 표시하고 보유 감시를 유지한다.
- 이외에는 HOLD. 최신 가격·OHLC가 부족하면 확인 필요 상태를 함께 표시한다.

### 독립 판단과 PHP 보조자료

지시문의 independent_results를 먼저 기록하고 results에 가격 확인 상태를 보강한다. 선정과 순위는 독립 평가 입력이 변하지 않는 동안 유지한다.
같은 종목·통화·거래소·세션·거래일에서 120초 이내 시각 차이의 두 가격이 2% 넘게 다르면 가격 확인 충돌이다. 변동·시각 차이를 먼저 확인하고 최대 1회 독립 재조회한다.
충돌·불명·지연으로 종목을 탈락시키지 않고 conditional과 사유를 남긴다. 오류 가격을 평균내거나 수집시각을 실제 시세시각으로 바꾸지 않는다.

## 5. 시세 원천과 실제 API 연결 폼

### 최소 수집 방식 결정

PHP는 **타임스탬프를 가진 최근 완료 1분봉 2개**를 기본으로 정규화한다. 날짜·시각·가격·거래량과 출처만 저장하고 분봉 전체를 누적하지 않는다.
실제 체결가격과 체결시각이 확보된 원천은 `price_type=last`로 저장할 수 있다. 분봉 종가는 `price_type=minute_close`, 시각 기준은 `timestamp_basis=bar_end`로 표시한다.
분봉에 표시된 시각이 시작시각인지 종료시각인지 공급자별로 검증해야 한다. 시작시각으로 확인된 경우에만 60초를 더해 봉 종료시각을 계산한다. 이를 실제 체결시각이라고 쓰지 않는다.
시각 의미가 미검증이면 `quote_at=null` 또는 `quality_status=unknown`으로 남긴다. 수집시각으로 시세시각을 채우지 않는다.
진행 중 봉, 미래 시각, 미체결을 임의로 채운 봉, 잘못된 거래일은 지지 확인에서 제외한다. 국내 API의 첫 분봉 체결량에 이전 봉 체결량이 잠시 보일 수 있으므로 완료봉 기준을 지킨다.

| 국가 | KIS 기본 호출 | 요청 폼·원시 필드 |
|---|---|---|
| KR | GET /uapi/domestic-stock/v1/quotations/inquire-time-itemchartprice / TR FHKST03010200 | FID_COND_MRKT_DIV_CODE=J, FID_INPUT_ISCD=매핑 코드, FID_INPUT_HOUR_1=현재 현지시각, FID_PW_DATA_INCU_YN=Y, FID_ETC_CLS_CODE 빈 문자열. output2의 stck_bsop_date·stck_cntg_hour·stck_prpr·cntg_vol을 정규화 |
| US·JP | GET /uapi/overseas-price/v1/quotations/inquire-time-itemchartprice / TR HHDFS76950200 | AUTH 빈 문자열, EXCD=검증된 NAS/NYS/AMS/TSE, SYMB=매핑 코드, NMIN=1, PINC=0, NEXT 빈 문자열, NREC=3, FILL/KEYB 빈 문자열. output2의 xymd·xhms·last·evol을 정규화 |

NREC=3으로 가져온 해외 봉 중 진행 중 봉을 제외하고 최신 두 완료봉을 남긴다. 일반 수집에서는 연속 페이지를 재귀적으로 따라가지 않는다.
분봉의 거래량은 minute, 당일 누적 거래량이 따로 확인되면 session_cumulative다. 둘을 같은 수치로 교체하거나 직접 비교하지 않는다. 등락률이 없으면 null이며 분석에 필요한 경우 지시문이 기준 종가를 확인하여 계산한다.
인증은 PHP 실행 환경의 APP Key/Secret과 유효 토큰을 사용한다. 응답 `rt_cd`와 오류를 확인하고 인증 실패를 정상 빈 데이터로 바꾸지 않는다.

### 보완 원천

- KR: KIS → 지원·매핑을 확인한 Naver → 지원·매핑을 확인한 Yahoo.
- US·JP: KIS → 지원·매핑을 확인한 Yahoo.
- 일본을 포함한 해외 시세는 서비스 권한과 실제 지연을 확인한다. REST 또는 무료 원천을 사용한다는 이유만으로 realtime이라고 표시하지 않는다.
- 각 원천은 같은 point 폼으로 변환한다. 날짜·시각·시각 의미를 확인할 수 없으면 unknown이다.
- 여러 원천이 모두 실패해도 해당 종목의 마지막 확인 가격은 원래 시세시각으로 보존한다. `fetch_status=error`와 마지막 시도 시각을 갱신하며 그 가격을 새 확인 가격으로 사용하지 않는다.
- 신규 종목은 point=null, fetch_status=pending으로 시작한다. 가격·시각·거래량의 결측을 0 또는 현재 시각으로 채우지 않는다.

## 6. 인터페이스 데이터 폼 v2

파일은 UTF-8 Markdown이다. 세 구역에 각각 JSON 객체 하나를 둔다. JSON이 기계 기준이며 사람이 보는 표는 해당 JSON으로 생성한다.
시간은 UTC 오프셋이 있는 ISO 8601, 거래일은 거래소 현지 YYYY-MM-DD다. 가격·비율은 유한 JSON number, 거래량은 0 이상 number 또는 null, 종목코드는 string, 보유 여부는 boolean이다. null은 확인되지 않음을 뜻한다.
`symbol_id=국가|거래소|코드`이며 공급자 매핑을 별도로 둔다. 한국 코드 앞자리 0과 일본 영문자 코드를 보존한다. 같은 코드가 여러 거래소에 있으면 별개 식별자다.
구역 마커 누락·중복, JSON 오류, 알려지지 않은 schema_version, 종목 중복 또는 필수 필드 형식 오류가 있으면 자동 쓰기를 멈추고 오류를 남긴다. 문서를 초기화하지 않는다.

| 구역 | 필수 최상위 필드 |
|---|---|
| WATCHLIST | schema_version, criteria_version, watchlist_version, updated_at, run_id, settings, calendar, symbols |
| COLLECTION | schema_version, collection_id, watchlist_version, started_at, completed_at, status, next_cursor, quotes |
| ANALYSIS | schema_version, criteria_version, run_id, as_of, analyzed_at, watchlist_version, collection_id, status, selection_fingerprint, input_fingerprint, result_fingerprint, coverage, independent_results, results, candidate_audit, candidate_history, changes, evidence |

### 공통 상태와 항목 폼

| 항목 | 값·필수 내용 |
|---|---|
| 실행 status | not_started / complete / partial / failed. 전체 호출 완료와 전체 데이터 정상 여부를 구분하여 실패·미처리 항목이 있으면 partial |
| fetch_status | ok / error / pending. point가 남아 있어도 이번 조회 실패이면 error |
| quality_status | normal / delayed / stale / unknown / conflict / pending / failed. 읽는 시점에 경과시간을 다시 계산 |
| session | regular / break / closed / premarket / afterhours / unknown |
| price_type | last / minute_close / close |
| timestamp_basis | trade / bar_end / close / unknown |
| volume_basis | minute / trade / session_cumulative / daily / unknown |
| delay_kind | realtime / delayed / unknown. 공급자가 확인한 지연초는 delay_seconds, 없으면 null |
| eligibility | pass / near / fail / unverified |
| action | BUY_REVIEW=매입 검토 / HOLD=보유 유지·관찰 / SELL_REVIEW=매도 검토 / WATCH=관찰 / EXCLUDE=이번 후보 제외 |
| readiness | ready / conditional / needs_data / expired |
| verification_status | verified / needs_check. 수집 성공과 투자 판단 확인을 구분 |
| wiki_effect | unused / confirmed / rechecked / changed |

관찰종목의 필수 필드는 symbol_id, country, exchange, symbol, name, currency, purpose, candidate_origin, is_held, position, tick_size, source_codes다.
position은 null 또는 entry_price·initial_stop·trailing_stop·registered_at을 가진 객체다. 등록이 확인된 보유분에만 is_held=true를 사용한다.
calendar는 valid_until과 markets 배열이다. 국가별 항목에는 country, timezone, source_url, checked_at, sessions를 넣는다. 각 세션은 market_date, open_at, close_at이며 일본 점심 휴장은 두 세션으로 표현한다. 유효기간은 다음 14일을 넘기지 않는다.
수집 행은 symbol_id, fetch_status, attempted_at, quality_status, error, point, previous_point다. point/previous_point는 아래 예시와 같은 필드를 갖거나 null이다. error는 null 또는 code·message·source 객체다.
분석 행은 아래 예시와 같은 필드다. BUY_REVIEW는 등록 보유분을 중복 신규 매입 대상으로 출력하지 않으며, 독립 관찰 순위 상위의 비보유 최대 3개에 적용한다. ready 여부로 추천 종목을 다시 고르지 않는다. entry_plans는 mode·entry_zone·invalidation·planned_risk_pct 객체의 배열 또는 null이다. active_plan은 pullback/breakout/null이다. 기존 entry_zone·invalidation은 활성 계획 또는 첫 조건부 계획의 요약값이며 확인되지 않으면 null이다. 보유 상태와 주식 식별이 맞지 않는 가격을 다른 종목의 행동 판단에 쓰지 않는다.
candidate_audit에는 평가한 모든 후보의 symbol_id, origin, metrics, eligibility, rank, failed_checks, evidence_ids를 남긴다. 참고 지표 미달은 failed_checks가 아닌 notes에 기록한다. metrics에는 위 계산값과 bars_count·bars_as_of·benchmark·adjustment를 기록한다. 필수 비교값과 참고값·null을 구분한다.
evidence는 ANALYSIS 최상위 배열이다. 각 항목은 id, source_url, source_kind, checked_at, data_as_of, claim을 가지며 결과·audit의 evidence_ids로 연결한다. source_kind는 independent 또는 php다. 합성 예시는 운영 근거로 사용하지 않는다.
coverage에는 국가별 신규 탐색 수·평가 수·PASS/NEAR/미검증 수·실제 추천 수와 complete/partial 사유를 남긴다.
candidate_history에는 symbol_id·country·last_review_market_date·last_eligibility·last_action·reason·origin을 둔다. 중복 종목은 최신값 하나만 유지한다. changes는 최근 20개 변경과 변경 이유를 유지하며 상세 이력은 커밋으로 보존한다.

### 입력·출력 예시 — 합성 자료, 운영 대상 아님

`example_only=true` 또는 SIM 식별자를 가진 항목은 실제 수집 목록에 넣지 않는다. 아래 예시는 데이터 폼 설명용이다.

지시문 → PHP, symbols 배열의 한 항목:
```json
{
  "example_only": true,
  "symbol_id": "KR|KRX|SIM_A",
  "country": "KR",
  "exchange": "KRX",
  "symbol": "SIM_A",
  "name": "합성 예시 A",
  "currency": "KRW",
  "purpose": "상대강도·눌림 관찰",
  "candidate_origin": [
    "new"
  ],
  "is_held": false,
  "position": null,
  "tick_size": 10,
  "source_codes": {
    "kis": {
      "market_code": "J",
      "symbol": "SIM_A"
    },
    "naver": null,
    "yahoo": null
  }
}
```

PHP → 지시문, quotes 배열의 한 항목:
```json
{
  "example_only": true,
  "symbol_id": "KR|KRX|SIM_A",
  "fetch_status": "ok",
  "attempted_at": "2026-10-08T09:39:05+09:00",
  "quality_status": "normal",
  "error": null,
  "point": {
    "price": 10080,
    "change_pct": null,
    "volume": 1000,
    "volume_basis": "minute",
    "currency": "KRW",
    "venue": "KRX",
    "source": "KIS",
    "provider_symbol": "SIM_A",
    "price_type": "minute_close",
    "timestamp_basis": "bar_end",
    "quote_at": "2026-10-08T09:39:00+09:00",
    "fetched_at": "2026-10-08T09:39:05+09:00",
    "market_date": "2026-10-08",
    "session": "regular",
    "delay_kind": "realtime",
    "delay_seconds": 0,
    "bar_time_basis_verified": true
  },
  "previous_point": {
    "price": 10050,
    "change_pct": null,
    "volume": 1100,
    "volume_basis": "minute",
    "currency": "KRW",
    "venue": "KRX",
    "source": "KIS",
    "provider_symbol": "SIM_A",
    "price_type": "minute_close",
    "timestamp_basis": "bar_end",
    "quote_at": "2026-10-08T09:38:00+09:00",
    "fetched_at": "2026-10-08T09:39:05+09:00",
    "market_date": "2026-10-08",
    "session": "regular",
    "delay_kind": "realtime",
    "delay_seconds": 0,
    "bar_time_basis_verified": true
  }
}
```

지시문 → 사용자·문서, results 배열의 한 항목:
```json
{
  "example_only": true,
  "symbol_id": "KR|KRX|SIM_A",
  "eligibility": "pass",
  "rank": 1,
  "base_action": "BUY_REVIEW",
  "action": "BUY_REVIEW",
  "readiness": "ready",
  "verification_status": "verified",
  "wiki_effect": "confirmed",
  "price": 10080,
  "price_type": "minute_close",
  "price_as_of": "2026-10-08T09:39:00+09:00",
  "entry_plans": [
    {
      "mode": "pullback",
      "entry_zone": [
        9800,
        10200
      ],
      "invalidation": 9400,
      "planned_risk_pct": 7.843137
    },
    {
      "mode": "breakout",
      "entry_zone": [
        10400,
        10600
      ],
      "invalidation": 10200,
      "planned_risk_pct": 3.773585
    }
  ],
  "active_plan": "pullback",
  "entry_zone": [
    9800,
    10200
  ],
  "invalidation": 9400,
  "exit_condition": [
    "등록된 실제 무효화 가격 도달",
    "MA20 아래이면서 직전10일저점 붕괴",
    "확인된 기업 근거 훼손"
  ],
  "plan_valid_until": "2026-10-08T15:30:00+09:00",
  "quote_valid_until": "2026-10-08T09:44:00+09:00",
  "next_check": "실제 진입 전 최신 가격 확인; 다음 정기 분석 14:00",
  "reason": "합성 예시: 독립 상위 후보, 유효 가격이 눌림 계획 안에 있음",
  "evidence_ids": [
    "SIM-INDEPENDENT-01",
    "SIM-COLLECTION-01"
  ]
}
```

사람이 보는 출력은 **국가·종목·판단·관측가격과 가격 종류·매입 구간·무효화·판단 이유·다음 확인·시세시각·자료 상태**만 표로 표시한다.
출력이 비어도 해당국 휴장, 통과 없음, 자료 미확인 중 어느 경우인지 분명히 남긴다.

## 7. 동시 갱신·목록 버전·부분 실패 처리

1. 매번 GitHub Contents API로 main/monitor.md의 최신 내용과 blob SHA를 읽는다. 공개 raw URL의 오래된 캐시를 갱신 기준으로 삼지 않는다.
2. 지시문은 WATCHLIST·ANALYSIS만, PHP는 COLLECTION만 교체하고 나머지 본문·구역은 보존한다.
3. 최신 SHA로 파일을 저장한다. SHA 충돌이면 최신 내용 재조회·자기 구역 재병합을 최대 2회 한다. 오래된 전체 문서를 반복 전송하지 않는다.
4. 목록 또는 공급자 코드·수집 정의가 바뀌면 watchlist_version을 증가시킨다. 점수·표시 시각만 바뀌어 동일 수집 대상이면 불필요하게 증가시키지 않는다.
5. PHP는 시작한 목록 버전을 기록한다. 저장 직전 버전이 달라지면 그 배치를 새 목록의 완료 결과로 게시하지 않고 다음 실행에서 새 목록을 수집한다.
6. 지시문은 같은 버전·식별자에 연결된 정상 자료만 보조 확인에 사용한다. 목록 변경 직후 새 종목에는 이전 종목 가격을 연결하지 않는다.
7. 45초 예산·연결 실패 시 완료 항목, 미처리·실패 항목과 next_cursor를 저장한다. 목록 버전이 바뀌면 커서는 새 목록에 맞춰 다시 정한다.
8. PHP 실행 잠금을 두고 중복 실행을 생략한다. enabled=false 또는 목록이 비었으면 시세 API를 호출하지 않는다. 상태파일·잠금·토큰 캐시는 PHP 실행 환경의 보조 저장이며 공유 업무 문서는 계속 이 파일 한 개다.

최근 시세를 GitHub에 모두 한 번씩 쌓지 않고 최신 두 가격점과 후보의 최신 상태만 유지한다. 문서가 512KiB를 넘으면 중복 설명·중복 이력을 압축하고, 필요한 최근 후보 식별자를 조용히 삭제하지 않는다. 여전히 한도를 넘으면 쓰기를 보류하고 크기 문제를 명시한다.

## 8. 재조정 시뮬레이션

**MON-P2.0 합성 시나리오 52건: 52 PASS / 0 FAIL.** 같은 입력 100회 반복과 후보 순서·기존신규 표기·조회 메타데이터 변경 100회에서 동일 업무 결과를 확인했다.
이는 결정 규칙과 재현성의 검증이다. 기존보다 투자 수익률이 높거나 어떤 시장에서도 같은 종목이 나온다는 검증은 아니다.
이전 MON-P1.0의 82건은 이전 강제 문턱에 대한 검증이었다. 해당 문턱을 없앤 이번 기준의 통과 근거로 그대로 재사용하지 않는다. 이전 상세 기록은 GitHub 커밋에 보존되어 있다.

| 번호 | 시나리오 | 결과 |
|---|---|---|
| 1 | 구기준 탈락 후보도 상대 경쟁 가능 | PASS |
| 2 | 65봉 대신 21봉이면 20일 상대강도 경쟁 가능 | PASS |
| 3 | 20일 계산을 못 하는 20봉은 미확인 | PASS |
| 4 | RS20 1pp와 음수 RS60으로 강제 제외하지 않음 | PASS |
| 5 | MA20 아래 MA60 관계로 전환 후보를 제외하지 않음 | PASS |
| 6 | 낮은 거래대금이 선정 탈락 조건이 아님 | PASS |
| 7 | 음수 시장·종목 수익도 관찰 경쟁 유지 | PASS |
| 8 | 미확인 위험은 추천 유지·실행 조건부 | PASS |
| 9 | 단일 최신 가격점으로 가격 확인 가능 | PASS |
| 10 | 5분 허용 경계 가격 확인 | PASS |
| 11 | 301초 가격은 추천 삭제 대신 조건부 | PASS |
| 12 | 15분 지연이어도 추천 종목 유지 | PASS |
| 13 | 시세시각 누락 시 추천 유지·확인 조건부 | PASS |
| 14 | 가격 자료가 없으면 매입 검토 계획 유지 | PASS |
| 15 | 급등 가격을 현재 진입으로 승인하지 않음 | PASS |
| 16 | 돌파 구간도 같은 지시문에서 검토 | PASS |
| 17 | 임의 5% 손실컷으로 종목 추천을 삭제하지 않음 | PASS |
| 18 | 등록한 무효화 가격은 완화하지 않음 | PASS |
| 19 | 단순 MA20 이탈만으로 매도하지 않음 | PASS |
| 20 | MA20와 직전10일저점 붕괴를 매도 검토 | PASS |
| 21 | 확인된 거래정지는 신규 후보 제외 | PASS |
| 22 | 거래정지 보유종목 관찰 유지 | PASS |
| 23 | 잘못된 종목 시세로 가격 확인하지 않음 | PASS |
| 24 | 미래 시세시각 오류 유지 | PASS |
| 25 | 등록 보유분 신규 추천 중복 없음 | PASS |
| 26 | 기존·신규 이력은 순위에 영향 없음 | PASS |
| 27 | 후보 중복 제거 | PASS |
| 28 | 관찰10·추천3 상한 | PASS |
| 29 | 후보 순서·기존신규·수집메타 변경 100회 동일 | PASS |
| 30 | 고정 입력 100회 동일 | PASS |
| 31 | 다른 숫자 표현을 동일 입력으로 정규화 | PASS |
| 32 | PHP 가격 변경은 독립 선정 순위를 바꾸지 않음 | PASS |
| 33 | PHP 가격 변경은 가격 확인 입력 버전 변경 | PASS |
| 34 | 새 완료봉으로 순위 근거가 바뀌면 결과 갱신 | PASS |
| 35 | 가격 충돌은 선정 유지·확인 보류 | PASS |
| 36 | 동점은 식별자 순서로 고정 | PASS |
| 37 | 보유종목은 10개 관찰 상한 밖에서도 유지 | PASS |
| 38 | 정수·실수 표기 차이는 같은 해시 | PASS |
| 39 | 가격 미세 표기 차이는 같은 판단 | PASS |
| 40 | ATR 부족은 종목 추천 유지·가격 조건부 | PASS |
| 41 | OHLC 일부 부족은 종목 추천 유지·가격 계획 미확인 | PASS |
| 42 | UTC 오프셋만 다른 동일 시각은 같은 입력 해시 | PASS |
| 43 | 시세 거래일 불일치는 현재 가격 확인 불가 | PASS |
| 44 | 오프셋 없는 시세시각은 현재 가격 확인 불가 | PASS |
| 45 | 손상된 시세시각은 현재 가격 확인 불가 | PASS |
| 46 | 호가 경계는 십진 계산으로 손실 없이 반올림 | PASS |
| 47 | 호가 경계의 미세 숫자 표기는 같은 가격 계획 | PASS |
| 48 | 같은 종목의 서로 다른 지지 사실은 재확인 | PASS |
| 49 | 통화 변경은 확인 입력 해시를 바꿈 | PASS |
| 50 | 판단에 쓰지 않은 다른 종목 시세는 입력 해시 제외 | PASS |
| 51 | 음수 지연 값은 오류 가격 | PASS |
| 52 | 음의 영은 같은 숫자로 정규화 | PASS |

기존의 원천 매핑·시세 날짜·JSON·SHA·구역 소유권·실행 예산 규격은 계속 적용한다. 실제 계좌 연결과 실제 시장 백테스트는 아직 수행하지 않았다.

## 9. 구현·실연결에서 확인할 조건

설계 수치와 인터페이스는 위와 같이 결정했다. 다음은 미결정 규격이 아니라 실제 구현으로 확인해야 할 조건이다.

- 동일 PHP 안의 나라별 어댑터가 실제 응답의 날짜·봉 시각 의미·코드·권한·지연·거래량 종류를 정확히 정규화하는지 확인.
- 관찰 기록 → PHP 읽기 → 실제 시세 수집 → 같은 파일 쓰기 → 지시문 재조회까지 한 번 연결하고, 동시 갱신 후 다른 구역이 보존되는지 확인.
- 실제 계좌의 호출 한도·응답 속도와 관찰 수에 따라 partial 빈도를 측정. 60초 주기에서 가능한 범위를 확인한 뒤 활성화.
- 합성 시뮬레이션과 별도로 실제 과거 자료에서 공통 조건의 추천 빈도·후보 편중·무효화 조건을 평가. 검증 없이 수익률 또는 정확도 개선을 주장하지 않음.

## 관찰종목 — 지시문 작성

<!-- MONITOR:WATCHLIST:BEGIN -->
```json
{
  "schema_version": 2,
  "criteria_version": "MON-P2.0",
  "watchlist_version": 0,
  "updated_at": null,
  "run_id": null,
  "settings": {
    "enabled": false,
    "watch_per_country": 10,
    "buy_review_per_country": 3,
    "new_candidate_search_target_per_country": 20,
    "candidate_memory_trading_days": 20,
    "daily_bars_target": 60,
    "daily_bars_min": 21,
    "poll_seconds": 60,
    "mirror_seconds": 180,
    "force_mirror_before_analysis_seconds": 60,
    "auxiliary_max_age_seconds": 900,
    "action_price_max_age_seconds": 300,
    "action_known_delay_max_seconds": 300,
    "future_clock_tolerance_seconds": 5,
    "http_connect_timeout_seconds": 2,
    "http_timeout_seconds": 5,
    "tick_budget_seconds": 55,
    "request_spacing_seconds": 1.25,
    "github_conflict_retries": 2,
    "max_independent_price_rechecks": 1,
    "analysis_slots": [
      {
        "countries": [
          "KR",
          "JP"
        ],
        "timezone": "Asia/Seoul",
        "time": "09:40"
      },
      {
        "countries": [
          "KR",
          "JP"
        ],
        "timezone": "Asia/Seoul",
        "time": "14:00"
      },
      {
        "countries": [
          "US"
        ],
        "timezone": "America/New_York",
        "time": "10:00"
      }
    ],
    "collection_budget_seconds": 45,
    "publish_reserve_seconds": 10,
    "github_http_timeout_seconds": 3,
    "consistency_priority": "same_input_same_business_result",
    "independent_selection_priority": true,
    "conditional_recommendations": true,
    "require_two_price_points": false,
    "hard_liquidity_floor": false,
    "price_conflict_fraction": 0.02,
    "comparison_price_time_gap_seconds": 120
  },
  "calendar": {
    "valid_until": null,
    "markets": []
  },
  "symbols": []
}
```
<!-- MONITOR:WATCHLIST:END -->

## 수집정보 — PHP 작성

<!-- MONITOR:COLLECTION:BEGIN -->
```json
{
  "schema_version": 2,
  "collection_id": null,
  "watchlist_version": null,
  "started_at": null,
  "completed_at": null,
  "status": "not_started",
  "next_cursor": 0,
  "quotes": []
}
```
<!-- MONITOR:COLLECTION:END -->

## 분석결과 — 지시문 작성

<!-- MONITOR:ANALYSIS:BEGIN -->
```json
{
  "schema_version": 2,
  "criteria_version": "MON-P2.0",
  "run_id": null,
  "analyzed_at": null,
  "watchlist_version": null,
  "collection_id": null,
  "status": "not_started",
  "coverage": [],
  "independent_results": [],
  "results": [],
  "candidate_audit": [],
  "candidate_history": [],
  "changes": [],
  "evidence": [],
  "selection_fingerprint": null,
  "input_fingerprint": null,
  "result_fingerprint": null,
  "as_of": null
}
```
<!-- MONITOR:ANALYSIS:END -->

## 공식 참고

- [KRX 정규장·시간외·휴장 규칙](https://global.krx.co.kr/contents/GLB/06/0602/0602010201/GLB0602010201T1.jsp)
- [JPX 정규장·점심 휴장](https://www.jpx.co.jp/english/equities/trading/domestic/01.html)
- [NYSE 정규장·휴장·조기 폐장](https://www.nyse.com/trade/hours-calendars)
- [KIS 국내 1분봉 API와 첫 봉 체결량 주의](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/domestic_stock/inquire_time_itemchartprice/inquire_time_itemchartprice.py)
- [KIS 국내 분봉 원시 필드](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/domestic_stock/inquire_time_itemchartprice/chk_inquire_time_itemchartprice.py)
- [KIS 해외 1분봉 API 요청 폼](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/overseas_stock/inquire_time_itemchartprice/inquire_time_itemchartprice.py)
- [KIS 해외 분봉 날짜·시각·가격·체결량 필드](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/overseas_stock/inquire_time_itemchartprice/chk_inquire_time_itemchartprice.py)
- [GitHub Contents API와 SHA 갱신](https://docs.github.com/en/rest/repos/contents#create-or-update-file-contents)

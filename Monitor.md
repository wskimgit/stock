# 종목 모니터링 — 결정된 기준·입출력·인터페이스

- 설계 버전: **2.0** / 판단 기준 버전: **MON-P1.0** / 인터페이스 `schema_version`: **2** / 결정일: **2026-10-08**.
- 저장소: **wskimgit/stock** / 브랜치: **main** / 파일: 저장소 루트 **Monitor.md**.
- 구조: **분석 지시문 1개 + PHP 수집기 1개 + 공유 문서 1개**. 사용자가 지정한 이미지의 역할과 흐름을 유지한다.
- 이번 결정은 구현에 사용할 설계 기준이다. 합성 데이터 시뮬레이션으로 흐름·조건·인터페이스를 확인했으며, 실계좌 API 연결 시험이나 투자 성과 백테스트를 실시한 것은 아니다.
- 현재 운영 상태: PHP 미구현·미배포, 수집·추천 미실행, 예약 미등록. 아래 운영 구역은 빈 양식이며 `enabled=false`다.
- 기존 코드와 Eagle·SIS·MS7 등은 참고 자료다. 기존의 서로 다른 조건을 필수 조건으로 자동 합산하지 않는다.

## 1. 모듈별 입력·처리·출력

| 모듈 | 입력 | 처리 | 출력·작성 권한 |
|---|---|---|---|
| 분석 지시문 | 독립 조회한 시장·기업·완료 일봉·가격 근거, 기존 후보, 보유 등록, 유효한 PHP 자료 | 독립 평가를 먼저 기록 → 동등 경쟁 → 보조 자료 확인 → 최종 판단 | WATCHLIST와 ANALYSIS 두 구역을 한 커밋으로 갱신 |
| PHP 수집기 | WATCHLIST의 설정·달력·국가·거래소·종목 매핑 | 최소 시세 수집·형식 검증·시각 정규화·자료 상태 판정 | COLLECTION 구역만 갱신 |
| Monitor.md | 위 두 작성자의 기록 | 최신 정보 교환과 이력 보존 | 세 쌍의 마커 안 JSON이 기계 입력의 기준 |

지시문은 PHP의 평가 결과를 받아 판단하는 구조가 아니다. 후보 평가와 판단 근거를 독립적으로 산출하고, PHP 시세로 가격·시간 조건을 보강하거나 오류를 재확인한다. PHP는 평가·매매 추천·주문을 수행하지 않는다.
새 관찰종목은 수집 완료 전에는 수집대기다. 필수 근거를 독립 조회로 충분히 확보한 경우 PHP 수집대기만을 이유로 독립 분석 전체를 중단하지 않는다.

## 2. 결정된 운영 기본값

| 항목 | 결정 |
|---|---|
| 대상 | 한국·미국·일본 개별 주식, 국가별 경쟁·표시 |
| 관찰목록 | 국가별 최대 10종목. 등록된 보유종목은 순위와 상관없이 추가 관찰 |
| 매입 검토 | 국가별 최대 3종목. 조건 부족 시 0개도 정상 결과 |
| 신규 탐색 | 매 실행 국가별 20종목을 탐색 목표로 삼는다. 미달하면 범위·미달 사유를 기록하고 전체 시장 최상위라고 표현하지 않는다 |
| 기존 후보 기억 | 국가별 최근 20거래일. 이번 설계에서 운영 기본값으로 채택 |
| 과거 강한 후보 | 20거래일 밖이라도 이번에 최신 근거가 확인되면 재경쟁. 옛 점수·옛 가격을 이번 근거로 사용하지 않는다 |
| 완료 일봉 | 120개 확보 목표, 필요한 계산을 모두 충족하는 최소 65개. 자료 부족은 미검증 |
| PHP 조회 | 해당 시장 정규장에 60초 간격. 마감 후 종가는 새로 확보할 필요가 있을 때 1회 확인 |
| GitHub 미러 | 180초 간격, 목록 변경 후 첫 수집 및 정기 분석 60초 전에는 앞당겨 게시 |
| 보조 시세 허용 경과 | 300초 이하. 장 마감 자료는 별도 종가 참고로 표시 |
| 매입·가격 기반 매도 확인 | 가격 기준시각 경과 120초 이하, 알려진 공급 지연 60초 이하, 현재 정규장 |
| 미래 시각 오차 | 최대 5초. 이를 넘으면 시간 오류로 처리 |
| HTTP | 연결 2초 / 시세 요청 총 5초 / 수집 45초 + 게시 10초 / 전체 PHP 실행 최대 55초 |
| 호출 간격 | 외부 요청 시작 사이 최소 1.25초. 실제 계좌의 제한이 더 낮으면 더 천천히 적용 |
| 재시도 | 일반 원천 오류는 같은 실행에서 같은 원천 재시도 없이 보완 원천 1회. GitHub 충돌은 최초 쓰기 이후 최대 2회 재병합 |
| 가격 충돌 재확인 | 지시문이 최대 1회 최신 가격을 독립 재조회. 해결되지 않으면 가격 확인 보류 |

조회·미러·판단 주기는 서로 다르다. 60초 수집이 60초마다 AI 매매 판단이 생성된다는 뜻은 아니다.
수집은 45초에서 끝내고 게시·최신 SHA 확인에 10초를 남긴다. GitHub 요청은 최대 3초이며 재시도도 전체 55초 마감 안에서만 한다. 게시 실패 자료와 순환 커서는 실행 환경의 캐시에 보존하고 다음 주기에서 최신 문서에 다시 병합한다.
한 배치 전체가 기한 내 완료되지 않으면 부분 결과를 게시하고 `next_cursor`부터 다음 실행을 계속한다. 보유종목을 포함한 전체 대상의 순환을 보장하며 첫 종목부터 반복하여 뒤쪽 종목을 굶기지 않는다.
마감 후의 전일 종가·지연자료는 참고 정보로 표시할 수 있으나 장중 최신 가격 확인을 대신하지 않는다.

### 정기 분석 시각

| 슬롯 | 대상·용도 | 시간 |
|---|---|---|
| 오전 | 한국·일본 장중 판단, 미국 직전 완료장 요약 | 한국시간 09:40 |
| 오후 | 한국·일본 장중 재평가 | 한국시간 14:00 |
| 미국 | 미국 정규장 개장 30분 후 판단 | America/New_York 10:00: 한국시간 서머타임 23:00 / 표준시 다음날 00:00 |

미국 슬롯은 한국 날짜가 아니라 미국 현지 거래일을 기준으로 한다. 휴장일에는 해당국 장중 슬롯을 생략하고 조기 폐장·개장 변경은 검증된 시장 달력으로 보정한다.
기본 정규장은 KRX 09:00–15:30, 도쿄 09:00–11:30 및 12:30–15:30, 미국 09:30–16:00 ET다. 시간외 시세는 별도 세션으로 표시한다.
지시문은 공식 달력에서 향후 14일의 세션을 WATCHLIST의 `calendar`에 기록한다. 달력이 없거나 만료되면 PHP는 장 상태를 unknown으로 두고 정규장으로 추정하지 않는다. 세션·서머타임은 IANA 시간대로 변환한다.

## 3. 후보 경쟁·선정 기준 MON-P1.0

### 후보를 합치는 범위

현재 관찰목록 + 최근 20거래일 관찰·추천·제외 후보 + 최신 재편입 근거를 확인한 과거 강한 후보 + 이번 신규 탐색 후보를 합친다.
`symbol_id`별로 한 번만 평가하고, 출신은 `candidate_origin` 배열에 함께 남긴다. 과거 선정과 신규성은 순위 가산점·감점이 아니다.
각 후보의 최신 완료 거래일·자료 조정 방식·벤치마크 거래일을 맞춘다. 가격·지표가 충돌하면 최신 독립 근거를 다시 확인하고 오래된 쪽을 점수에 섞지 않는다.
최근 이력은 종목별 최신 검토 정보만 본문에 유지한다. 상세 과거 결과는 GitHub 커밋으로 조회한다. 20거래일 경과는 이번 기본 재평가 목록에서 제외하는 기준이며 과거 강한 후보의 재편입 금지는 아니다.

### 계산 정의

- `C_t`: 가장 최근 완료 일봉 종가, `O_t/L_t/H_t`: 같은 봉의 시가·저가·고가. 진행 중 일봉은 계산에 넣지 않는다.
- `MA20`, `MA60`: 완료 종가의 단순 이동평균. `MA20_lag5`, `MA60_lag5`: 5거래일 전의 각 이동평균. `previous_ma20`: 직전 거래일의 MA20.
- `R20 = C_t / C_(t-20) - 1`, `R60 = C_t / C_(t-60) - 1`.
- `RS20_pp = 100 × (종목 R20 − 시장 R20)`, `RS60_pp`도 동일. 값의 단위는 퍼센트포인트이며 가격비율이 아니다.
- 벤치마크: 한국 KOSPI 종목은 KOSPI, KOSDAQ 종목은 KOSDAQ; 미국 S&P 500; 일본 TOPIX. 해당 지수와 자료 기간을 명시한다.
- `TR_t = max(H_t−L_t, abs(H_t−C_(t-1)), abs(L_t−C_(t-1)))`; **ATR14는 최근 TR 14개의 단순평균**이다. Wilder 평활 ATR과 혼용하지 않는다.
- `ADV20`: 최근 20완료일의 원시 거래대금 평균. 거래대금이 없으면 같은 날의 원시 종가×실제 거래량으로 계산하며 분할 조정 가격×원시 거래량을 혼합하지 않는다.
- 추세·ATR·상대강도 계산용 OHLC는 현재 가격 단위에 맞춘 분할 조정 방식으로 통일한다. 공급자 방식이 확인되지 않거나 분할 경계가 맞지 않으면 미검증이다.

### PASS·NEAR·미검증

| 구분 | 결정된 조건 |
|---|---|
| 공통 자료 | 완료 일봉 최소 65개, 거래일 정렬·중복·누락·조정 방식 검증, 거래 가능 여부 및 중대한 위험 근거 확인 |
| 유동성 하한 | KR ADV20 ≥ 50억원 / US ≥ 500만 USD / JP ≥ 5억 JPY |
| PASS 추세 | C_t ≥ MA60, MA20 > MA60, MA20 ≥ MA20_lag5, MA60 ≥ MA60_lag5 |
| PASS 강도 | R20 > 0, RS20_pp ≥ 3, RS60_pp ≥ 0 |
| NEAR | 공통 자료·유동성·위험 확인 통과 후 R20 > 0, RS20_pp ≥ 0, RS60_pp ≥ −2, C_t와 MA20이 각각 MA60의 98% 이상, MA20 비하락, MA60이 5일 전 값의 99.5% 이상 |
| FAIL | 확인된 거래 불가·중대한 부정 사건·유동성 부족 또는 위 조건 미달 |
| UNVERIFIED | 필요한 자료 또는 위험 확인이 부족하여 평가를 확정할 수 없음 |

순위는 **RS20_pp 내림차순 → RS60_pp 내림차순 → ADV20/국가별 하한 내림차순 → symbol_id 오름차순**으로 결정한다. 별도의 임의 가중 점수를 사용하지 않는다.
PASS를 먼저 관찰하고 남은 자리만 NEAR로 채운다. 데이터 미검증 후보는 별도 미검증 목록에 남기며 확인된 추천처럼 표시하지 않는다. 통과 종목이 적으면 숫자를 강제로 채우지 않는다.
업종 주도성·기업·사건 근거는 독립 분석에 기록한다. 이 첫 기준에서는 출처마다 다른 업종 점수를 순위 가산점으로 넣지 않는다.
시장 벤치마크 MA20 < MA60이면 후보 선정·보유 관찰은 계속하되 새로운 매입 확인은 WATCH로 둔다.

## 4. 매입·매도 시점 결정

### 매입: 강한 종목의 MA20 눌림 지지

다음 조건을 모두 충족해야 `BUY_REVIEW / ready`다.

1. 해당국 PASS 관찰종목이고, 시장 벤치마크 MA20 ≥ MA60이며 중대한 부정 사건이 확인되지 않았다.
2. 최신 완료 일봉 L_t ≤ MA20+0.5×ATR14, C_t ≥ MA20, C_t ≥ O_t.
3. 최근 10완료봉 최고가 − C_t ≥ 0.5×ATR14. 눌림 없이 높이 떠 있는 가격은 진입 확인으로 처리하지 않는다.
4. 진입 구간은 **[MA20, MA20+0.5×ATR14]**. 구간 하단은 호가단위로 올리고 상단은 내린다.
5. 같은 종목·통화·거래소·현지 거래일의 실제 가격점 두 개가 60–300초 간격으로 구간 안에 있고, 최신 가격이 이전 가격 이상이다. 완료 1분봉 종가 두 개를 쓸 수 있으나 실제 체결가와 구분한다.
6. 최신 가격점의 경과시간 ≤120초, 공급자 지연이 확인되어 ≤60초, 현재 정규장. 이전 가격점의 경과시간은 최대 420초다.
7. 무효화 가격은 **min(L_t, MA20)−0.5×ATR14**를 호가단위로 내린 값. 양수이고 진입 하단보다 낮아야 한다.
8. **(진입 상단−무효화 가격)/진입 상단 ≤5%**. 이는 초기 설계의 가격 위험 제한이며 실제 손실을 보장하거나 계좌 주문 수량을 결정하지 않는다.

최소 호가단위는 지시문이 해당 가격 구간의 실제 규칙 또는 공급자 메타데이터로 확인한다. 가격대 경계에서 단위가 달라지면 해당 구간별 단위로 정규화한다. 확인되지 않으면 구간은 참고값이고 ready로 표시하지 않는다.
조건부 계획은 다음 정규장 마감까지 유지하되 새 완료 일봉·기업 사건·계산 근거 변경 시 재평가한다. 가격 확인의 유효시간은 별개로 `price_as_of+120초`이며, 지났으면 매입 전에 최신 가격을 다시 확인한다.

### 매도: 등록된 보유종목

보유종목은 관찰 순위 밖이어도 다음을 먼저 확인한다.

- 최신 유효 가격이 보유 등록의 `initial_stop` 또는 이미 기록된 더 높은 `trailing_stop` 이하: SELL_REVIEW.
- 최신 완료 종가 < MA60, 또는 **각 날짜의 종가가 각 날짜의 MA20 아래인 상태가 2거래일 연속**: SELL_REVIEW.
- 독립 확인된 중대한 부정 사건으로 보유 근거가 훼손됨: SELL_REVIEW. 가격이 부족하면 매도 가격 확인은 needs_check로 남긴다.
- 위 조건이 없으면 HOLD. 시세가 없으면 확인된 보유 등록에 대한 계속 관찰이며 최신 가격 확인 완료라고 표시하지 않는다.

매도 판단에 직전 종가를 오늘의 MA20과 비교하지 않는다. 날짜별 기준을 맞춘다.
최초 무효화 가격과 추적 기준은 사람이 실제 진입 후 등록한 값을 사용한다. 기존 보유분에 사후로 임의의 최초 손절가를 만들지 않는다.
추적 기준은 독립 분석에서 이전 값보다 낮추지 않는 방향으로 기록할 수 있다. 별도 이익 목표 가격이나 보유 수량을 만들어 자동 처분하지 않는다.

### 독립 판단과 보조 확인

독립 결과를 `independent_results`에 먼저 남기고 최종 결과를 `results`에 기록한다. `wiki_effect`는 unused / confirmed / rechecked / changed 중 하나다.
오래된·지연된 Wiki 자료는 유효한 독립 가격 판단을 차단하지 않는다. 반대로 독립 평가가 충분하고 PHP 가격이 유효하면 그 가격으로 진입 조건을 보조 확인할 수 있다.
같은 종목·통화·거래소·세션·거래일의 유효한 가격이 60초 이내 시각 차이인데 1% 넘게 다르면 충돌로 표시한다. 평균내어 채택하지 않는다.
지시문은 최대 1회 더 최신의 검증된 가격을 독립 재조회하고 조건을 다시 계산한다. 해결되지 않은 가격 충돌은 WATCH / needs_check다. 출처 개수로 투표하거나 같은 원천을 독립 증거 두 개로 세지 않는다.

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
| ANALYSIS | schema_version, criteria_version, run_id, analyzed_at, watchlist_version, collection_id, status, coverage, independent_results, results, candidate_audit, candidate_history, changes, evidence |

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
분석 행은 아래 예시와 같은 필드다. BUY_REVIEW는 등록 보유분을 중복 신규 매입 대상으로 출력하지 않으며, 관찰된 PASS 후보 중 ready인 최대 3개에만 적용한다. entry_zone은 [하단,상단] 또는 null, 무효화 가격도 확인되지 않으면 null이다. 보유 상태와 주식 식별이 맞지 않는 가격을 다른 종목의 행동 판단에 쓰지 않는다.
candidate_audit에는 평가한 모든 후보의 symbol_id, origin, metrics, eligibility, rank, failed_checks, evidence_ids를 남긴다. metrics에는 위 계산값과 bars_count·bars_as_of·benchmark·adjustment를 기록한다.
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
  "entry_zone": [
    10000,
    10100
  ],
  "invalidation": 9880,
  "exit_condition": [
    "무효화 가격 도달",
    "완료 일봉 MA60 이탈",
    "각 날짜 MA20 이틀 연속 이탈"
  ],
  "plan_valid_until": "2026-10-08T15:30:00+09:00",
  "quote_valid_until": "2026-10-08T09:41:00+09:00",
  "next_check": "진입 전 최신 가격 재확인; 다음 정기 분석 14:00",
  "reason": "합성 예시: 독립 평가 PASS, 눌림 지지와 두 가격점 조건 충족",
  "evidence_ids": [
    "SIM-INDEPENDENT-01",
    "SIM-COLLECTION-01"
  ]
}
```

사람이 보는 출력은 **국가·종목·판단·관측가격과 가격 종류·매입 구간·무효화·판단 이유·다음 확인·시세시각·자료 상태**만 표로 표시한다.
출력이 비어도 해당국 휴장, 통과 없음, 자료 미확인 중 어느 경우인지 분명히 남긴다.

## 7. 동시 갱신·목록 버전·부분 실패 처리

1. 매번 GitHub Contents API로 main/Monitor.md의 최신 내용과 blob SHA를 읽는다. 공개 raw URL의 오래된 캐시를 갱신 기준으로 삼지 않는다.
2. 지시문은 WATCHLIST·ANALYSIS만, PHP는 COLLECTION만 교체하고 나머지 본문·구역은 보존한다.
3. 최신 SHA로 파일을 저장한다. SHA 충돌이면 최신 내용 재조회·자기 구역 재병합을 최대 2회 한다. 오래된 전체 문서를 반복 전송하지 않는다.
4. 목록 또는 공급자 코드·수집 정의가 바뀌면 watchlist_version을 증가시킨다. 점수·표시 시각만 바뀌어 동일 수집 대상이면 불필요하게 증가시키지 않는다.
5. PHP는 시작한 목록 버전을 기록한다. 저장 직전 버전이 달라지면 그 배치를 새 목록의 완료 결과로 게시하지 않고 다음 실행에서 새 목록을 수집한다.
6. 지시문은 같은 버전·식별자에 연결된 정상 자료만 보조 확인에 사용한다. 목록 변경 직후 새 종목에는 이전 종목 가격을 연결하지 않는다.
7. 45초 예산·연결 실패 시 완료 항목, 미처리·실패 항목과 next_cursor를 저장한다. 목록 버전이 바뀌면 커서는 새 목록에 맞춰 다시 정한다.
8. PHP 실행 잠금을 두고 중복 실행을 생략한다. enabled=false 또는 목록이 비었으면 시세 API를 호출하지 않는다. 상태파일·잠금·토큰 캐시는 PHP 실행 환경의 보조 저장이며 공유 업무 문서는 계속 이 파일 한 개다.

최근 시세를 GitHub에 모두 한 번씩 쌓지 않고 최신 두 가격점과 후보의 최신 상태만 유지한다. 문서가 512KiB를 넘으면 중복 설명·중복 이력을 압축하고, 필요한 최근 후보 식별자를 조용히 삭제하지 않는다. 여전히 한도를 넘으면 쓰기를 보류하고 크기 문제를 명시한다.

## 8. 시뮬레이션 결과와 보완 결정

**합성 입력 82개 시나리오: 82 PASS / 0 FAIL.** 시장 수익률·승률 검증이 아니라 계산·판단 흐름·시각·소유권·데이터 형식에 대한 시뮬레이션이다.

| 발견한 문제 | 결정한 보완 |
|---|---|
| 3분 미러 자료를 즉시 진입 가격처럼 사용할 수 있음 | 보조 300초·행동 120초를 분리하고 분석 전 미러를 앞당김 |
| 일반 현재가 응답에 실제 날짜·시각이 없을 수 있음 | 날짜·시각이 있는 1분봉 기본 수집, actual trade와 minute_close 구분 |
| 국내 첫 분봉의 거래량이 아직 이전 봉 값일 수 있음 | 진행 중 봉 제외, minute 거래량과 당일 누적 거래량 분리 |
| 원천 장애로 배치 전체가 시간 예산 초과 | partial 결과와 순환 커서로 다음 주기 계속 처리 |
| 기존·신규에 같은 종목 중복 또는 선정 이력 우선권 | 식별자 병합과 같은 상대강도 순위 적용 |
| 보유종목이 경쟁에서 밀려 감시 대상에서 사라짐 | 관찰 10개 제한 밖에서 보유종목 계속 확인 |
| 두 작성자가 서로의 결과를 덮어쓸 수 있음 | 구역 소유권·최신 SHA·충돌 재병합 |
| 수집 중 관찰목록이 변경됨 | 목록 버전 불일치 배치를 새 목록의 완료 자료로 쓰지 않음 |
| 직전 종가를 오늘 MA20과 비교하여 조기 매도할 수 있음 | 날짜별 MA20과 비교하는 2완료봉 조건 |
| 시세의 종목·거래일·통화·거래소가 맞지 않을 수 있음 | 식별·세션 일치 검사 후에만 가격 사용 |

합성 부하 가정은 정상 요청 0.25초, 요청 시작 간격 1.25초다. 30종목 1회 조회는 **36.5초**로 45초 수집 예산 안이었다. 게시를 위한 10초를 따로 남겨 전체 실행은 55초로 제한한다. 이는 실제 네트워크 측정값이 아니다.
KIS와 보완 원천이 각각 5초씩 실패하는 종목은 **한 주기에 4개 / 40초**만 처리하는 모델이므로 전체 완료로 표시하지 않는다. 8주기 순환 모델에서 30개 전체에 접근함을 확인했다.
이 계산은 API 호출 1회로 해당 종목의 필요한 두 가격점을 얻는 기본 경로를 가정한다. 추가 요청·권한·지연·보유종목 증가·실제 계좌 유량 제한은 완료 범위에 반영한다.

| 번호 | 시뮬레이션 항목 | 결과 |
|---|---|---|
| 1 | 65봉에서 MA20 계산 | PASS |
| 2 | 65봉에서 MA60 5거래일 전 계산 | PASS |
| 3 | ATR14 단순평균 계산 | PASS |
| 4 | RS20 퍼센트포인트 계산 | PASS |
| 5 | 64봉 부족 검출 | PASS |
| 6 | 중복 완료봉 검출 | PASS |
| 7 | 벤치마크 거래일 불일치 검출 | PASS |
| 8 | 정상 강한 후보 PASS | PASS |
| 9 | RS20 3pp 경계값 PASS | PASS |
| 10 | RS20 1pp 후보 NEAR | PASS |
| 11 | 유동성 부족 탈락 | PASS |
| 12 | 위험 확인 누락은 미검증 | PASS |
| 13 | 신규·기존 변경은 순위에 영향 없음 | PASS |
| 14 | 상대강도가 높은 기존 후보 우선 | PASS |
| 15 | 상대강도가 높은 신규 후보 우선 | PASS |
| 16 | 가격 구간·무효화 계산 | PASS |
| 17 | 호가단위 미확인은 가격 확정 보류 | PASS |
| 18 | 허용 손실 5% 초과 진입 보류 | PASS |
| 19 | 정상 눌림·두 시세 지지 확인 | PASS |
| 20 | 급등 추격 방지 | PASS |
| 21 | 하락 중 눌림은 확인 보류 | PASS |
| 22 | 단일 시세는 지지 확인 부족 | PASS |
| 23 | 두 시세 간격 60초 미만 보류 | PASS |
| 24 | 두 시세 간격 300초 초과 보류 | PASS |
| 25 | 시장 약세 신규 진입 보류 | PASS |
| 26 | 시세 120초 경계 허용 | PASS |
| 27 | 시세 121초 초과 보류 | PASS |
| 28 | 시세시각 없는 경우 보류 | PASS |
| 29 | 15분 지연 가격 보류 | PASS |
| 30 | 미래 시각 6초 오류 | PASS |
| 31 | 실패 후 남은 정상 가격을 신규 확인으로 사용 금지 | PASS |
| 32 | 실제 거래량 0을 결측으로 오인하지 않음 | PASS |
| 33 | 지연 Wiki는 유효한 독립 판단을 차단하지 않음 | PASS |
| 34 | 동일 시점 1% 초과 가격 충돌 검출 | PASS |
| 35 | 서로 다른 통화 가격은 비교하지 않음 | PASS |
| 36 | 시각 차이 60초 초과는 충돌 확정 안 함 | PASS |
| 37 | 등록 보유종목 초기 무효화 도달 | PASS |
| 38 | 보유 추세 붕괴 시 순위와 별도 매도 검토 | PASS |
| 39 | 정상 등록 보유종목 유지 | PASS |
| 40 | 미등록 종목 보유 유지 표시 방지 | PASS |
| 41 | 일본 점심 휴장 | PASS |
| 42 | 국가별 휴장 적용 | PASS |
| 43 | 미국 서머타임 분석시각 23시 | PASS |
| 44 | 미국 표준시 분석시각 다음날 0시 | PASS |
| 45 | 미국 조기 폐장 반영 | PASS |
| 46 | 오래된 SHA 쓰기 차단 | PASS |
| 47 | 충돌 후 최신 문서에 PHP 구역 병합 | PASS |
| 48 | 병합 후 AI 결과 보존 | PASS |
| 49 | 병합 후 PHP 시세 보존 | PASS |
| 50 | 수집 중 목록 변경 검출 | PASS |
| 51 | 목록 변경 후 새 종목 수집대기 보존 | PASS |
| 52 | 중복 마커 손상 검출 | PASS |
| 53 | JSON 손상 검출 | PASS |
| 54 | 한국 코드 앞자리 0 보존 | PASS |
| 55 | 일본 영문자 종목코드 보존 | PASS |
| 56 | 30종목 정상 조회 45초 예산 내 | PASS |
| 57 | 원천 동시 실패는 예산 내 부분 완료 | PASS |
| 58 | 미러 최악 경과 225초는 보조 유효시간 안 | PASS |
| 59 | 3분 전 미러는 즉시 진입 확인에 부족 | PASS |
| 60 | 이전 종가는 이전 MA20과 비교 | PASS |
| 61 | 완료봉 이틀 연속 MA20 이탈 | PASS |
| 62 | 잘못된 종목 시세로 매입 확인 금지 | PASS |
| 63 | 전일 시세와 당일 시세를 지지로 결합 금지 | PASS |
| 64 | 지시문의 수집구역 쓰기 차단 | PASS |
| 65 | PHP의 분석구역 쓰기 차단 | PASS |
| 66 | 직접 가격 미확인 시 유효한 PHP 자료로 보조 확인 | PASS |
| 67 | 유효 출처의 가격 충돌은 확인 보류 | PASS |
| 68 | 가격 충돌 후 한 번의 더 최신 재확인 | PASS |
| 69 | 국가별 관찰 10개와 보유 별도 | PASS |
| 70 | 국가별 매입검토 최대 3개 | PASS |
| 71 | 통과 종목 부족 시 강제 충원 안 함 | PASS |
| 72 | 8주기 제한 예산 안에서 실패 대상 전체 순환 | PASS |
| 73 | 부분 수집 커서가 첫 종목으로 매번 리셋되지 않음 | PASS |
| 74 | 중복 기존·신규 후보는 한 종목으로 병합 | PASS |
| 75 | 진행 중 1분봉 제외 후 최근 두 완료봉 선택 | PASS |
| 76 | 분봉 가격을 실제 체결가로 표기하지 않음 | PASS |
| 77 | 분봉 체결량을 당일 누적으로 표기하지 않음 | PASS |
| 78 | 공급자 분봉 시각의 기준 미확인은 판단 보류 | PASS |
| 79 | 미체결 채움 분봉을 지지 근거로 사용 금지 | PASS |
| 80 | 기존 보유종목을 신규 매입 추천으로 중복 출력하지 않음 | PASS |
| 81 | 수집 45초와 게시 10초를 60초 주기 안에 분리 | PASS |
| 82 | 게시 시간 포함 최악 235초도 보조 유효시간 안 | PASS |

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
  "criteria_version": "MON-P1.0",
  "watchlist_version": 0,
  "updated_at": null,
  "run_id": null,
  "settings": {
    "enabled": false,
    "watch_per_country": 10,
    "buy_review_per_country": 3,
    "new_candidate_search_target_per_country": 20,
    "candidate_memory_trading_days": 20,
    "daily_bars_target": 120,
    "daily_bars_min": 65,
    "poll_seconds": 60,
    "mirror_seconds": 180,
    "force_mirror_before_analysis_seconds": 60,
    "auxiliary_max_age_seconds": 300,
    "action_price_max_age_seconds": 120,
    "action_known_delay_max_seconds": 60,
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
    "github_http_timeout_seconds": 3
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
  "criteria_version": "MON-P1.0",
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
  "evidence": []
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

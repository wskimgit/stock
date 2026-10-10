# mon 지시문 — 한·미·일 스윙 종목 선정·매입·매도 판단

- 지시문 **v4.2**, 선정 기준 **MON-P3.1**, 수치 규격 **MON-SWING-1.1**, 저장 규격 **schema_version 3**.
- 개정일: **2026-10-10 KST**. MACD는 사용자 정정에 따라 **12·26·9**를 적용한다.
- 저장소 **wskimgit/stock**, 브랜치 **main**. GitHub 관리 문서는 저장소 루트에 둔다. 별도 Wiki 저장소나 `wiki/` 폴더를 만들지 않는다.
- 파일명 유지: **mon.php**=PHP, **monitor.md**=지시문·설계, **mon_data.json**=공유 데이터, **mon_result.md**=사용자 결과.
- 최상위 원칙: **ChatGPT가 자율 판단하고 자율적으로 종목을 선정한다. 투자형태는 swing이다. PHP 수치와 참고 순위는 보조자료다.**
- 추천 자격: **시장보다 강함 AND (MA5 재돌파 OR MACD 골든 OR MACD 골든 전후)**. 기존 추천·신규 후보는 같은 자격으로 경쟁한다.
- 구현: **mon.php 1.4.0**. 기존 웹 수집·중지·시작·설정 파일 참조를 유지하고 스윙 계산·전환·자율선정 저장 인터페이스를 추가한다. 주문은 실행하지 않는다.
- 배포 구분: GitHub 코드와 지시문을 바꾸는 것과 NAS `/web/mon.php` 교체·실가동은 별개다. NAS 교체 확인 없이 새 데몬이 가동 중이라고 쓰지 않는다.

## mon 실행 지시문

일반 실행은 아래 블록만 읽는다. 상세 규격은 새 오류나 기준 변경이 있을 때 필요한 항목만 조회한다.

<!-- MON:INSTRUCTION:BEGIN -->
```text
# mon 지시문 v4.2 — 종목별 보조자료와 자율 스윙 선정
기준 MON-P3.1 / MACD(12,26,9) / MON-SWING-1.1 / MON-DETAIL-1.0 / schema_version 3.
stock/main 루트 monitor.md·mon.php·mon_data.json·mon_result.md를 사용한다.
최상위: ChatGPT가 후보 발굴과 최종 판단·종목 선정을 자율 수행한다. 투자형태 swing. PHP는 정보 수집·수치 보조만 담당한다.

1. 자율 후보 → 종목별 요청
- 시작시각과 이번 실행의 전체 대기 마감(기본120초, 최대300초)을 먼저 고정한다. 지시문 블록과 최신 mon_data.json·정확한 Git blob SHA를 읽고 큰 JSON·Base64·일봉은 모델 문맥에 출력하지 않는다.
- ChatGPT가 현재 목록·최근20거래일 후보·새 근거가 있는 과거 강한 종목·신규 후보에서 상세 확인할 종목을 자율 선정한다. 기존·신규·직전 추천을 동등하게 판단한다. 단순 PHP 점수 상위 종목을 자동 채택하지 않는다.
- 신원·국가·거래소·통화·공급자 코드가 확인된 종목부터 mon_detail_request로 개별 request_id를 등록해 mon_data.json의 watchlist.detail_requests에 저장한다. 종목별 독립 요청이며 하나의 배치 성공을 요구하지 않는다. 물리적 Git 커밋은 여러 독립 요청을 함께 저장해도 된다.
- 같은 요청의 재전송은 같은 ID를 사용한다. 새 종목 확인/새 완료봉/정정/강제 재수집이면 새 ID다. 활성 요청 최대30개, 국가별 상세 관찰 기본10개다. 전체 후보의 판단 범위를 요청 수집 목록30개로 제한하지 않는다.

2. 성공 자료부터 확인; 제한된 대기
- mon.php 1.4.0은 약10초마다 요청을 확인해 종목 하나씩 처리하고 성공/실패/이전자료 응답을 즉시 GitHub collection.detail_responses에 반영한다. 장외에도 완료 일봉·지수·기존 검증자료를 처리한다. 정상 시세 수집60초·일반 미러180초와 개별 응답의 즉시 미러를 구분한다.
- Git의 변경을 확인할 때만 새 소스를 읽고 mon_detail_view의 짧은 종목 카드를 확인한다. 이미 완료한 자료·외부 확인·계산을 매번 반복하지 않는다. 완료 종목부터 이유를 검토하며 아직 대기인 종목 때문에 멈추지 않는다.
- 응답의 request_id·request_hash·symbol_id·기준·기간·원문 해시가 요청과 일치해야 한다. 교체 전 요청의 응답과 다른 종목 자료를 사용하지 않는다.
- 최신 완료 거래일 자료는 주말/휴일에도 현재 완료봉 자료다. 마지막 검증시각을 새 조회시각으로 바꾸지 않는다. 새 완료 거래일이 누락되면 이전 검증자료와 과거 신호임을 표시한다. 수집 실패만으로 자료가 있던 종목을 자동 탈락시키지 않는다.
- 성공한 가격 필드와 이전 검증된 지표를 각각 활용한다. 날짜·분할 기준·지수 기간이 다르면 현재 신호나 RS20를 합성하지 않는다. 부분 가격은 배경 참고이고 신호 확정은 별도다. 가격 기준 충돌은 현재 추천 자격을 보류하고 과거자료 참고로 구분한다.
- 전체 마감이나 요청의 마감에 이르면 현재 확보된 자료로 결론을 작성한다. 실패는 종목별 최대2회, 30초 후 재시도이며 다른 종목을 먼저 처리한다. NAS 미배포/중지이면 기다리기를 반복하지 않고 기존 검증자료·필요한 독립 확인으로 진행하며 실제 제한을 밝힌다.

3. 후보 자격과 자율 판단
- 확보한 종목별 응답과 기존 자료를 mon_detail_prepare로 한 번 병합·계산한다. 준비 완료 뒤 추가 응답이 판단 입력을 실제 바꿀 때만 그 변경을 반영한다. 모든 카드 페이지와 reference_cards를 읽는다.
- 추천 자격은 시장보다 강함 AND (MA5 재돌파 OR MACD 골든 OR 골든 직전/후)다. 동일21완료 종가·거래일·분할 조정 기준의 RS20_pp>0; KR=해당 KOSPI/KOSDAQ, US=S&P500, JP=TOPIX. 다른 지수를 임의 대체하지 않는다.
- MA5: 직전10거래일 이상 연속으로 각 일자 종가가 그날 SMA5 미만이고 최신 완료 종가가 SMA5 초과; 또는 같은 하회 조건 뒤 독립 검증된 현재가가 당일 잠정 SMA5 초과. 잠정 SMA5=(직전4완료 종가 합+현재가)/5. 등호는 재돌파가 아니다.
- MACD=EMA12−EMA26, Signal=EMA9(MACD). 최신 완료봉에서 직전 MACD≤Signal이고 현재 MACD>Signal이면 골든. 골든 후1~3거래일 양의 히스토그램 유지도 대상이다.
- 골든 직전은 최근3개 유효 완료봉 히스토그램이 음수/0 영역에서 엄격하게 상승하며 최신 |히스토그램|/ATR14≤0.10인 근접 상태다. 미래 교차를 확정하지 않는다. 전체 원천 구간이 부족해 MACD가 미검증이어도 검증된 MA5 OR 신호는 인정한다.
- ChatGPT가 시장 강도·신호·유동성·이격·추격 위험·기업 근거를 비교해 최종 선정한다. RS20↓→R20↓→ADV20↓는 참고 정렬이다. 응답 속도·신규 여부·전회 추천에 가산점을 주지 않는다. 국가별 비보유 추천/과거자료 참고 합계 최대3개, 관찰10개, 보유분 별도다.
- 최신 자격 확인은 selected(매입 검토), 과거 시장 강도와 신호만 확인한 종목은 ChatGPT가 이유를 명시해 references(이전자료 참고·재확인)로 선택할 수 있다. 과거 신호를 오늘 골든/현재 매입 가능으로 표시하지 않는다. 이전 기록의 무조건 승계는 금지한다.
- 외부 독립 확인은 필요한 후보·중요 사건·보유 변화만 묶음1회, 중요한 충돌 해결만 추가1회다. 국가당 신규20개/거래일 탐색 목표는 유지하되 이번 실행의 실제 확인 범위를 기록한다. 동일 원천 실패를 새 근거 없이 반복하지 않는다.
- 가격 계획은 검증 지지·저항·ATR과 실제 호가단위로 계산한다. 현재 시세·신호 유지·거래·기업 위험이 부족하면 조건부/자료 필요다. 보유 매도는 등록 손절·추적선, MA20와 직전10일 저점 동시 붕괴, 확인된 중대한 기업 훼손을 따르며 신규 진입 신호 부족만으로 자동 매도하지 않는다.

4. 최종 결과 저장
- mon_detail_select에 작은 decision(selected, references, 구체적인 자율 선택 이유)을 전달한다. PHP는 자격·소유권·해시·크기를 검증하고 두 파일을 반환한다. 최종 선정·추천·주문을 자동 생성하지 않는다.
- ChatGPT는 최신 PHP collection을 보존해 mon_data.json·mon_result.md를 같은 Git 커밋으로 저장한다. 수집만 추가되고 판단이 같으면 source를 병합해 기존 판단시각을 보존한다. 변화 없는 결과를 새 판단으로 재커밋하지 않는다.
- 결과는 기준시각·상태 한 줄, 국가/종목/판단/자료 기준/가격 조건/핵심 이유 표 하나, 주요사항 최대3개다. 실제 데이터 날짜와 최신 완료봉/재사용/과거 신호를 표시한다. 저장 여부·실측 총시간을 보고한다.

시간 목표: 자료 준비된 판단60초, 종목별 상세 대기 포함 일반 실행120초, 추가 준비 최대300초. 실제 경과시간을 보장하거나 숨기지 않는다.
금지: 실행 중 계산 프로그램 생성, 정상 기능 재작성, 기존 PASS 재시험, 끝난 조회 반복, NAS 배포/재시작, 예약 임의 등록, 주문. 불확실성과 실패는 실제 상태로 남긴다.
```
<!-- MON:INSTRUCTION:END -->

## 1. 역할과 저장 위치

| 담당·파일 | 역할 |
|---|---|
| ChatGPT / mon 지시문 | 독립 후보 발굴·근거 확인·스윙 자격 판단·최종 자율 선정·매입/매도 판단 |
| mon.php | 기존 최소 시세 수집, 스윙 수치 계산, 작은 입력/변경 처리, 저장 파일 반환 |
| monitor.md | 최신 실행 지시문·기준·인터페이스·예외 규격 |
| mon_data.json | 관찰·보유·설정·PHP 시세·전체 후보·스윙 수치·결정 근거·압축 이력 |
| mon_result.md | 최신 결과 표 하나와 주요사항 최대3개 |

PHP는 KIS API를 우선하고 기존 Naver/Yahoo 대체 경로를 유지한다. `/web/broker_config.local.php`의 기존 KIS 키와 `sis_private_sync_config.php`의 GitHub 설정을 직접 참조한다. 키를 JSON·GitHub·결과에 복사하지 않는다. 별도 암호·폴더·CLI 조작을 사용자에게 요구하지 않는다.

수집 데몬은 **collection만** 갱신하며 종목별 상세 응답도 collection에 쓴다. MON용 함수는 전체 소스를 고정 입력으로 받아 계산하거나 새 파일을 반환하며 독자적인 GitHub 저장·추천·주문을 하지 않는다. ChatGPT가 반환 파일을 확인하고 저장한다.

## 2. 스윙 자격과 수식

### 2.1 시장 대비 강도

\[
R20=C_t/C_{t-20}-1,\qquad RS20_{pp}=100(R20-R20_{market})
\]

같은 기간의 **21개 완료 종가**가 필요하다. 종목과 지수의 실제 거래일 배열이 일치해야 한다. 필수 날짜·종가가 부족하면 0이나 이전 값으로 채우지 않는다. 진행 중 일봉은 독립 완료봉 비교에 포함하지 않는다.

**RS20_pp>0**가 추천 자격이다. 시장이 하락한 경우 덜 하락한 종목도 이 조건을 만족할 수 있으므로 시장 상태를 설명한다. 종목의 R20 양수·RS20 +3%p·MA60 상승·RSI/MACD의 다른 설정을 추가 필수 문턱으로 합산하지 않는다.

### 2.2 MA5 장기 하회 후 재돌파

\[
MA5_t=\frac{C_t+C_{t-1}+C_{t-2}+C_{t-3}+C_{t-4}}{5}
\]

- 최신 완료봉 직전 **최소10거래일 연속** 각 종가가 **그날의 MA5보다 낮아야** 한다. 최신 MA5 한 값에 과거10종가를 비교하지 않는다.
- 최신 완료 종가가 최신 MA5보다 높으면 `MA5_RECLAIM_CLOSE`다. 15개 완료 종가로 최소 패턴을 계산할 수 있다. 시장 비교에는 별도로21개가 필요하다.
- 직전 완료봉까지10일 이상 연속 하회한 상태에서 검증된 당일 현재가가 `(직전4완료 종가 합+현재가)/5`보다 높으면 `MA5_RECLAIM_LIVE`다. 최소14완료 종가＋현재가가 필요하다.
- 등호는 하회도 재돌파도 아니다. 날짜 누락·종목 식별 불일치·정정·분할 단위 불일치는 미검증으로 남긴다.
- 장중 신호는 **잠정**이다. 종가 확정 신호와 구분하고 현재가가 다시 이평선 아래로 내려가면 진입 가능으로 표시하지 않는다.
- 최근 완료봉의 신호는 다음 완료봉을 준비할 때 다시 계산한다. 과거 재돌파를 무기한 현재 신호로 유지하지 않는다.

### 2.3 MACD(12,26,9)

\[
EMA_N(t)=\frac{2}{N+1}C_t+\left(1-\frac{2}{N+1}\right)EMA_N(t-1)
\]
\[
MACD_t=EMA12_t-EMA26_t,\quad Signal_t=EMA9(MACD)_t,\quad H_t=MACD_t-Signal_t
\]

EMA 초기값은 첫 N개 유효 입력의 SMA다. MACD의 EMA9는 유효한 MACD 값9개로 초기화한다. 자료의 최초 날짜·조정 방식·전체 값이 계산 입력 해시에 포함되므로 데이터 제공자·초기화 기간이 다르면 같은 수치라고 단정하지 않는다. 독립 원천에서 확보한 전체 이력을 동일하게 보존한다(기본 요청 6개월). 추천 종목만60개, 다른 후보는21개로 자르지 않는다. 최초 일자·봉 개수·가격 조정 방식·원문 해시를 `payload.history_metadata`에 저장한다. 수학적 계산 최소량과 원천 이력 검증은 별개다. 전체 원천 구간이 확인되지 않은 이력은 `needs_full_history`로 표시하고 MACD만으로 추천하지 않는다. 검증된 MA5 재돌파의 OR 자격은 유지한다. 배당 미조정/조정, KRX/NXT, 완료일이 다른 앱 수치와 같다고 단정하지 않는다.

| 신호 | 고정 조건 | 표시 |
|---|---|---|
| MACD_GOLD | 직전 H≤0, 최신 H>0 | 골든크로스 확정 |
| MACD_AFTER | 확인된 골든 이후1~3거래일, 그동안 H>0 유지 | 골든 후 N거래일 |
| MACD_BEFORE | 유효 H 3개가 엄격히 증가, 최신 H≤0, 최신 abs(H)/ATR14≤0.10 | 골든 직전(근접), 미확정 |

첫 시그널 값은34번째 완료 종가부터, 첫 교차 판정은35번째부터 가능하다. 최근3개 유효 히스토그램 근접 판정은36개 완료 종가부터 가능하다. 교차 후 기간에는 추가 유효 값이 필요하다. 시그널 EMA9의 갱신 계수는2/(9+1)=0.2이며 장중 보조 계산에도 동일하게 적용한다. 부족한 MACD는 null/needs_history로 표시한다. **MA5 조건이 검증되면 MACD 부족 때문에 OR 자격을 지우지 않는다.**

‘골든 전후’의 구체적인 범위는 이번 구현의 기본값이다. **전: 최근3개 히스토그램의 수렴＋ATR 대비10% 이내 / 후: 3거래일 이내**. 이 값은 미래 교차 예측이나 수익 보장이 아니다. 이후 변경하면 기준 버전과 프로필을 함께 바꾼다.

ATR14는 최근 TR14개의 단순평균이다. TR=max(고가−저가, abs(고가−직전종가), abs(저가−직전종가)). ATR 부족 시 골든 직전 근접 조건을 확정하지 않는다. 지수는 종가만으로 시장 비교가 가능하며 지수의 거래량·OHLC 결측을 종목 MACD 결측으로 오인하지 않는다.

### 2.4 최종 자율 선정

```text
추천 자격 = RS20_pp>0
          AND (MA5_RECLAIM_CLOSE OR MA5_RECLAIM_LIVE
               OR MACD_GOLD OR MACD_AFTER OR MACD_BEFORE)
          AND 동일 기간·신원·자료 검증
          AND 확인된 중대한 위험/거래불가 없음
최종 추천 = ChatGPT가 위 자격 집합에서 근거를 기록하고 자율적으로 선정
```

PHP의 `rank_hint`는 **RS20_pp↓→R20↓→ADV20↓→symbol_id↑** 참고 정렬이다. 자동 상위3추천이나 최종 종목 지정이 아니다. ChatGPT는 거래대금·업종 주도성·이격·추격 위험·신호의 질·기업 근거를 함께 보고 자격 종목 중 최대3개/국가를 선정할 수 있다. 추천하지 않은 적격 후보도 전체 후보 자료에 남긴다.

| 상태 | 의미 |
|---|---|
| pass | 최신 비교와 시장 강도 및 MA5/MACD 자격 충족. 최종 추천은 별도 |
| near | 확인된 자료에서 시장 강도 또는 스윙 신호 미충족. 관찰 가능, 추천 불가 |
| unverified | 필수 자료·최신 지수 비교·필요 신호 이력 부족. 과거 자격이 확인되면 자율 판단의 이전자료 참고로 표시 가능 |
| fail | 확인된 거래 불가·중대한 부정 사건. 신규 진입 제외, 보유 감시는 유지 |

추천 대상이 부족하면 확보된 수만 제시한다. 이전 추천·신규성·수집 순서·ready에 가산점을 주지 않는다. 모든 추천에는 `autonomous_decision.selector=ChatGPT`와 구체적 선택 이유를 저장한다.

## 3. 매입 계획·현재 가능 여부·매도

스윙 신호는 **추천 자격**, 가격 계획은 **진입 검토 범위**다. 두 역할을 혼동하지 않는다. 최신 신호가 없는 종목에 기존 ATR 가격 구간만 붙여 추천하지 않는다.

지지 S는 최신 완료 종가 이하의 MA5·MA10·MA20·최근5일 저점 중 최댓값이다. 저항 R은 최신 완료일을 제외한 직전20일 고가 최댓값이다.

| 계획 | 가격 구간 | 무효화 참고값 |
|---|---|---|
| 눌림 | S−ATR14 ~ S＋ATR14 | min(최근5일 저점,S)−0.5×ATR14 |
| 돌파 이후 검토 | R ~ R＋ATR14 | R−ATR14 |

실제 호가단위로 하단은 올리고 상단·무효화는 내린다. 값은 양수이며 무효화<하단≤상단이어야 한다. 필요한 값이 부족하면 계획은 null이다. 계획의 무효화는 등록된 실제 손절가를 자동 변경하지 않는다. 이미 R을 넘은 종목은 ‘돌파 이후’로 설명한다.

`ready`에는 스윙 자격 유지, 정규장, 같은 종목/통화/거래소/현지 날짜, 가격 나이≤300초, 확인된 지연≤300초, 미래 오차≤5초, 거래 가능·기업 위험 확인, 유효한 계획 구간 안의 가격을 모두 요구한다. 현재 가격이 없고 완료 종가만 있는 경우는 **조건부**다. 오래된 위험 확인과 과거 ready를 현재 확인 완료로 바꾸지 않는다.

보유 매도는 기존 유효 initial_stop/trailing_stop 중 높은 기준, MA20와 직전10완료일 저점 동시 붕괴, 확인된 중대한 기업 훼손에 따른다. 보유 수량·매입가·손절가를 추정 생성하지 않는다. 신규 신호 미충족만으로 자동 매도하지 않는다. 주문은 하지 않는다.

## 4. PHP 웹 운영과 지원 범위

`/web/mon.php`를 브라우저로 열어 **시작·중지·상태**를 사용한다. 데몬은 최소 시세를 주기적으로 수집하고 GitHub에 collection을 미러링한다. 코드 교체 후 웹에서 중지→시작하면 새 버전이 적용된다. 실제 상태 확인 없이 가동 여부를 선언하지 않는다.

기존 기본 수집 간격60초·미러180초·회차 예산·순환 커서·KIS 토큰 캐시·Naver/Yahoo 대체·충돌 재병합·중지 응답을 유지한다. 매분 ChatGPT를 호출하거나 새 추천을 생성하지 않는다.

스윙 함수는 **저장된 검증 완료봉을 한 번의 함수 호출로 계산**한다. 내부 배열 순회는 필요하며 O(1)이라고 주장하지 않는다. 종목별 요청에 한해 KIS 우선, 검증 실패 시 Yahoo의 6개월 완료 일봉을 수집하고 대응 지수를 보충한다. 분할 기준 확인이 안 되는 KIS 해외 일봉은 추천 계산에 쓰지 않고 검증 가능한 대체자료를 사용한다. 일본 TOPIX 등 지수 대체 경로가 실패하면 이전 검증자료와 실패 상태를 반환한다. Naver는 기존 한국 시세 대체 경로이며 이 버전의 상세 일봉 자동 보충 경로로 구현되지 않았다. 모든 공급자 지원을 완료했다고 표시하지 않는다.

MON 분석 슬롯은 오전09:40 KST, 오후14:00 KST, 미국 개장30분 후10:00 America/New_York가 설계 기준이다. 예약 등록 상태와 슬롯 설계는 별개이며 이번 개정은 예약을 생성하지 않는다.

## 5. 저장·데이터 규격

`schema_version=3`를 유지하고 watchlist·analysis의 `criteria_version`을 모두 **MON-P3.1**으로 맞춘다. 새 PHP는 P2.0과 이전 P3.0(12·26·19) 데이터를 전환 준비 목적으로 읽지만 이전 추천을 P3.1 추천으로 표시하지 않는다. P3.0은 과거 프로필 검증·이력 보존만을 위한 호환 경로다. P2 전용 이전 데몬은 P3 자료를 처리할 수 없으므로 NAS PHP 교체가 필요하다.

```json
{
  "investment_style": "swing",
  "swing_profile": {
    "formula_version": "MON-SWING-1.1",
    "ma_period": 5,
    "min_below_days": 10,
    "macd_fast": 12,
    "macd_slow": 26,
    "macd_signal": 9,
    "macd_after_days": 3,
    "macd_before_hist_bars": 3,
    "macd_before_gap_atr": 0.10,
    "rs20_min_pp": 0,
    "rs20_operator": ">",
    "final_selector": "ChatGPT"
  }
}
```

위 표는 주요 설정 발췌다. 실제 프로필은 `MonSwing::rules()`의 전체 객체와 일치해야 한다. 다른 프로필을 같은 기준 버전으로 슬쩍 사용하면 MON_SWING_PROFILE_INVALID다.

| 객체·필드 | 저장 내용·작성 권한 |
|---|---|
| watchlist | 기존 종목 신원·수집 코드·실제 보유·설정·달력. MON 작성 |
| collection | 공급자·시세·시세시각·지연·거래 상태·실패 사유. PHP 작성 |
| analysis.candidate_audit | 전체 후보의 자격·실패 사유·수치/신호 참조. MON 작성 |
| 압축 payload.swing_signals | 모든 후보의 MA5 연속 하회 수·재돌파·MACD/시그널/히스토그램·골든 일자/경과·RS20·입력 해시 |
| analysis.results | ChatGPT가 최종 선정한 BUY_REVIEW/SELL_REVIEW/REFERENCE_REVIEW 및 보유 판단. 신호·자율 결정 이유 포함 |
| payload.strategy_archive | 이전 P2/P3.0 분석·추천·선정 사실·스윙 신호·프로필·해시. 과거 기준시각 유지 |
| payload.history_metadata | 전체 원천 구간 여부·최초/최종 완료일·봉 개수·입력 해시·가격 조정 방식·원문 출처·원래 확인시각 |
| analysis.swing_preparation | 수치 계산 완료 범위와 최종 자율 선정 대기/완료 상태 |

스윙 신호 값에는 `formula_version, bars_as_of, qualifying_signals, eligible, market_strong, ma5_below_days_before_last, ma5_reclaim_close, ma5_reclaim_live, macd_kind, macd_cross_date, macd_cross_age_days, histogram, macd_gap_atr, bar_input_sha256`를 보존한다. `source_kind=independent`의 검증된 실시간 관측만 장중 재돌파의 독립 입력으로 사용한다. PHP 시세를 독립 조사 사실로 재표기하지 않는다.

현재 거래·기업 위험에 직접 확인하지 않은 사실은 verified로 저장하지 않는다. 종목별 응답의 latest/reused_current는 완료 일봉·지수 자료 상태이며 현재가·현재 기업 위험의 확인 완료를 뜻하지 않는다. 지연·실패·미검증·자료 준비 대기는 별도 상태다. `preparation_running=false`는 백그라운드 작업이 실행되고 있지 않음을 뜻한다.

숫자는 소수6자리 ROUND_HALF_UP, 해시용 숫자는 소수6자리 문자열, 객체 키·식별자는 정렬, 시간은 UTC 마이크로초로 정규화한다. `selection_fingerprint`에는 자격 사실·기준·ChatGPT의 최종 선정 결정을 포함한다. `input_fingerprint`에는 고정 as_of·실제 사용 시세·자료 상태를 더한다. `result_fingerprint`에는 행동·가격 계획·스윙 신호·자율 결정 기록을 포함한다. 같은 고정 사실과 **같은 선정 결정**은 같은 업무 결과다. 자유로운 AI 서술이 매번 같은 판단을 내린다고 보장하지 않는다.

전체 JSON 상한은 **2,097,152바이트(2MiB)**다. 종목별 응답 최대30개·개별 압축20KiB를 위한 collection 예약공간을 **655,360바이트**로 설정한다. 웹 입력 상한은6MiB, 압축 해제 원문은 전체8MiB/개별256KiB다. 전체 일봉과 이전 신호·추천을 무손실 보존하기 위해 기존768KiB 상한을 조정했다. 크기 제한 때문에 특정 후보의 이력을 잘라 자격을 왜곡하지 않는다. 압축 원문은 SHA256/바이트 길이를 검증한다. P3 저장은 PHP 기본 gzip을 사용해 NAS 웹에서 xz 명령 실행에 의존하지 않는다. P2의 기존 XZ는 전환 때 가용 고정 처리기로 한 번 해제한다. 크기 부족 때만 근거 전문을 압축 인덱스로 무손실 이동한다. 이전 근거와 과거 추천을 지우지 않는다.

활성 가격 계획이 정해지지 않았으면 `active_plan=null, entry_zone=null, invalidation=null`이다. 눌림·돌파별 계획은 `entry_plans`에서 각각 표시한다. 서로 다른 계획의 진입·무효 가격을 혼합하지 않는다.

GitHub 파일 읽기는 크기에 관계없이 Contents API의 `Accept: application/vnd.github.raw+json`으로 원문을 한 번 받아 처리한다. 1MiB 초과 파일의 기본 응답은 Base64 내용을 제공하지 않을 수 있으므로 `encoding=base64`를 요구하지 않는다. 정확한 원문 바이트로 Git blob SHA를 계산해 수집 미러링 PUT의 충돌 검증에 사용한다. 쓰기 요청은 기존 JSON 미디어 형식·인증·브랜치를 유지한다. 크기 제한·JSON/스키마·HTTP 오류 처리는 유지한다.

## 6. 고정 인터페이스

아래 웹 API는 모두 **POST JSON**, stateless다. 반환 파일은 GitHub에 자동으로 쓰지 않는다. source_json은 원문 문자열이며 Git blob SHA가 실제 바이트와 일치해야 한다. 응답의 큰 파일 문자열을 모델 문맥에 출력하지 않고 저장 도구로 전달한다.

| 웹 API / 고정 함수 | 목적 |
|---|---|
| `?api=mon_detail_request` / MonDetails::register | 개별 요청 한 종목 등록, mon_data.json만 반환; 같은 ID 재전송은 reuse |
| `?api=mon_detail_view` / MonDetails::view | 요청별 완료/실패/이전자료·짧은 지표 카드, 먼저 완료된 종목 즉시 검토 |
| `?api=mon_detail_prepare` / MonDetails::prepare | 일치하는 개별 응답과 기존 자료 병합, 전체 자격/과거 참고 카드; 자율 선정 없음 |
| `?api=mon_detail_select` / MonDetails::select | ChatGPT의 selected+references 검증과 최종 두 파일 반환 |
| `?api=mon_view` / mon_project_for_mon | 저장된 선택·가격 계획·현재 상태·중요 사건의 작은 투영. 전체 후속 페이지 확인 |
| `?api=mon_swing_prepare` / MonSwing::prepare | 전체 후보의 새 스윙 자격·수치·참고 순위 계산. 최종 추천 없음 |
| `?api=mon_swing_migrate` / MonSwing::migrate | P2/P3.0→P3.1 전환 또는 검증 이력 보충, 이전 추천·신호 보존, 새 자격 계산, 선정 대기 상태와 파일 반환 |
| `?api=mon_swing_select` / MonSwing::select | ChatGPT의 작은 최종 선정 결정을 검증하고 두 저장 파일 반환 |
| `?api=mon_patch` / mon_apply_mon_patch | 선정·계획을 바꾸지 않는 가격/위험 변경 또는 실제 변화 없는 reuse |

전환 요청은 `history_updates`를 선택적으로 받는다. 형태는 `{symbol_id:{bars:[[date,open,high,low,close,volume],...],source_url,checked_at,price_basis,provider_window:"6mo",source_record_sha256}}`다. 독립 원천의 전체 구간을 준비 단계에서 전달하며 확인시각을 현재시각으로 고쳐 쓰지 않는다. 기존 날짜의 모든 가격·거래량과 마지막 완료일이 일치해야 한다. 충돌·누락·이력 축소·미래시각은 예외로 중단하고 기존 자료를 보존한다. 정상 MON 실행에서 이 보충을 반복하지 않는다. `history_metadata` 해시·개수·시작/종료일·가격 기준이 불일치하면 MACD 자격으로 사용할 수 없다.

### 6.1 준비 요청·응답

```text
요청: {source_json, context:{source_blob_sha,rendered_at,countries,important_ids},
       live_observations?:[독립 검증 시세 행]}
반환: criteria_version,formula_version,state_token,prepared_fingerprint,
      candidate_count,counts,rank_hint,cards,additional_card_pages,
      preparation,auto_selected:false,final_selector:ChatGPT
```

짧은 카드와 모든 후속 카드 페이지를 함께 읽는다. 각 페이지는 같은 state_token/prepared_fingerprint의 일부다. 모든 후보의 실제 계산 결과는 내부 full_signals에 유지하며 모델에 원문 전체를 출력하지 않는다.

실시간 관측 행은 symbol_id·country·currency·venue·price·price_as_of·price_type(last/minute_close)·price_checks(지연, 현지 날짜, trade/bar_end 기준, 정규장)·source_kind=independent·evidence_ids를 포함한다. 종목 국가·거래소·통화가 일치하고 근거 참조가 있어야 하며, 나이·지연·정규장 검증을 통과하지 못하면 장중 재돌파에 사용하지 않는다. 장중 신호로 선정하면 사용한 현재가와 시각을 결과와 입력 해시에 보존한다.

### 6.2 자율 선정 결정

```text
{source_json,
 decision:{view_context,state_token,prepared_fingerprint,fixed_as_of,
           selected:[{symbol_id,reason,watch_symbol?:검증된신원·수집코드}],
           live_observations?:[준비시와 동일한 관측]}}
```

준비 입력 토큰·전체 자격 해시가 일치해야 하며 selected에는 적격 종목만 최대3개/국가를 넣는다. 모든 적격 후보를 카드와 후속 페이지로 제공하며 관찰 수집 한도10개 때문에 후보를 숨기지 않는다. 참고 정렬 상위3개를 자동 생성하지 않는다. 이유가 없거나 중복·보유분 중복·미검증·신호 없음은 거절한다. 새 관찰 종목이면 검증된 watch_symbol 신원·수집 코드를 함께 제공한다. 부족하면 자료 준비 필요를 반환한다.

선정 함수는 현재 기업 위험·현재 거래 상태를 자동 verified로 만들지 않는다. 반환된 추천은 기본적으로 조건부이며 기존 mon_patch의 독립 시세/위험 검증으로 보강한다. 보유분은 새 진입 자격과 무관하게 보존한다.

### 6.3 파일 반환·저장

`files=[{path:mon_data.json,content,blob_sha},{path:mon_result.md,content,blob_sha}]`, validation에는 기준·세 해시·압축 검증·collection 보존·저장 크기를 넣는다. 두 파일을 같은 Git 커밋으로 반영한다. 원문 SHA·토큰·해시·자료 기간·소유권·크기 오류이면 쓰기하지 않는다.

동시 수집 충돌은 최신 collection만 병합하고 watchlist/analysis가 바뀌면 다시 읽어 판단한다. GitHub lease 충돌 재시도는 최대2회다. 완료된 독립 확인·선정은 같은 고정 입력에 대해 반복하지 않는다. 실제 변화 없는 재표시는 기존 as_of를 유지하고 저장하지 않는다.

## 6.4 종목별 요청·응답 계약 — MON-DETAIL-1.0

실행 순서는 **ChatGPT 자율 후보 → 개별 요청 저장 → PHP 개별 자료 응답 저장 → 성공 자료부터 검토·제한 대기 → ChatGPT 최종 자율 선정 → 결과 저장**이다. 전체 세트 완료 상태나 batch_id가 없다. 같은 파일에 여러 개별 요청을 저장해도 요청·실패·재시도·자료 사용은 독립이다.

| 위치/필드 | 작성자·의미 |
|---|---|
| watchlist.detail_requests[symbol_id] | MON. contract, request_id, request_hash, symbol_id, symbol, criteria_version, requested_at, deadline_at, target_completed_date, target_verified, force_refresh |
| collection.detail_responses[symbol_id] | PHP. 위 연결키, state, data_status, attempts, completed_at, retry_after, error, conflict, usable, bars_as_of, source_checked_at, partial_fields, payload |
| payload.detail_imports / detail_plans | MON 고정 처리기가 실제 사용한 PHP 자료의 연결키·출처·자료 날짜와 가격 계획을 보존 |
| analysis.results[].data_status / signal_as_of | 결과의 자료 상태와 신호 계산 완료봉 날짜. 과거/현재를 구분 |

요청 웹 폼은 `{source_json,context:{source_blob_sha,rendered_at,countries,important_ids},item:{request_id,symbol,wait_seconds:120,force_refresh:false}}`다. symbol은 기존 watchlist의 검증된 신원·source_codes·tick_size 형태이며 is_held=false다. 요청 해시는 고정 함수가 만든다. 동일 ID의 종목/내용 변경은 DETAIL_REQUEST_ID_REUSED다. 새 ID는 이전 응답을 무효화한다. 완료/만료된 가장 오래된 요청부터 교체할 수 있어30개 한도가 계속 누적되지 않는다.

개별 조회/준비 폼은 `{source_json,context,live_observations?:[독립 검증 시세]}`다. 조회는 작은 상태·지표 카드만 반환한다. 준비는 기존 준비 응답에 `reference_cards, detail_status`를 더한다. **PHP 자료는 source_kind=php_auxiliary로 유지**하며 기업 확인·독립 판단을 대신하지 않는다.

최종 저장 폼은 `{source_json,decision:{view_context,state_token,prepared_fingerprint,fixed_as_of,selected:[{symbol_id,reason}],references:[{symbol_id,reason}],live_observations?:[...]}}`다. current selected에는 최신 자격만 허용한다. references에는 과거 동일기간 RS20>0과 MA5/MACD 신호가 검증됐으나 최신 비교가 부족한 종목만 허용한다. 두 목록을 합쳐 국가당3개이며 중복/보유분 중복을 거절한다. 참고 행동은 REFERENCE_REVIEW, readiness=reference, 현재 진입 확인=false다.

| 응답 자료 상태 | 사용 방법 |
|---|---|
| latest | 확인된 최신 완료 거래일의 종목·지수 자료. 현재 진입은 별도 확인 |
| reused_current | 같은 최신 완료 거래일의 이전 검증자료 재사용. 휴일 경과만으로 낡은 신호라고 하지 않음 |
| previous_verified | 최신 완료봉/지수가 부족하거나 조회 실패. 원래 검증시각과 일봉을 보존해 재검토 |
| no_data | 활용할 검증자료가 없음. 값·신호를 만들지 않음 |
| previous_conflict_reference | 새 공급자 가격/거래량과 저장 기준 충돌. 현재 추천 자격 보류, 과거 기록으로만 참고 |

`state=retry_wait/done`와 `data_status`는 별개다. 첫 실패 뒤에도 usable 이전자료를 즉시 활용할 수 있다. 자료 신선도는 원래 source_checked_at와 bars_as_of, 거래달력의 마지막 완료일로 판정한다. collection.completed_at나 응답 completed_at를 일봉의 새 검증시각으로 사용하지 않는다. target_verified=false면 현재 자격 확인으로 승격하지 않는다.

partial_fields.stock은 개별 가격 수집 성공과 지수 실패를 함께 표시한다. 최신 가격으로 오래된 MACD/RS20를 재계산한 것처럼 표시하지 않는다. 날짜·기준이 맞는 기존 지수는 재사용할 수 있다. 분할/가격 기준 충돌은 마지막 정상 캐시를 덮어쓰지 않는다. 해시·압축 오류도 그 종목의 실패로 처리하고 다른 종목을 계속 확인한다.

데몬은 최대25초 상세 수집 예산과55초 회차 예산을 유지한다. 응답은 일반180초 주기를 기다리지 않고 개별 반영한다. 반영 실패 자료는 로컬 대기 파일과 정상 캐시에 보존하고 새 요청 연결키가 일치할 때만 재반영한다. 읽기/쓰기 충돌 시 최신 MON 요청·판단 및 다른 최신 응답을 보존한다. 정상 완료봉·일치하는 벤치마크는 중복 수집하지 않는다. KIS 인증·시세 캐시도 유지한다.

자동 생성 상태 파일은 mon.php와 같은 폴더에 두며 별도 폴더 설정을 요구하지 않는다. 새 코드 다운로드·GitHub 반영과 NAS 교체·재시작은 구분한다. NAS 실가동 및 실제 KIS 수집은 운영 상태로 따로 확인한다.

## 7. 오류·예외와 결과 표시

| 상황 | 처리 |
|---|---|
| 10일 연속 조건이9일 또는 중간에 MA5 이상 종가 존재 | MA5 자격 미충족. MACD 조건은 별도 계산 |
| MACD 계산 이력 부족/전체 원천 이력 미검증 | needs_history/needs_full_history. 확인된 MA5 OR 신호만 사용 가능 |
| 골든 이후4거래일 이상·중간 데드크로스 | MACD_AFTER 미충족. 다른 신호가 없으면 추천 불가 |
| 골든 전 히스토그램 감소·간격 과대·ATR 없음 | 골든 직전 자격 미충족/검증 필요 |
| 같은 기간 시장 지수 없음·거래일 불일치 | 최신 비교 미검증, 이전 추천 자동 승계 금지 |
| 휴장·장외·오래된 시세·지연 불명 | 완료봉 자격과 가격 계획은 설명, 현재 ready는 금지 |
| 확인된 악재·거래 불가 | 신규 진입 제외, 보유의 별도 판단 유지 |
| 소스/토큰/압축/업무 해시 오류 | 쓰기 보류와 실제 오류 코드 |
| 새 기준에 적격 후보 없음 | 추천 없음과 실제 부족/미충족 사유를 표에 표시 |
| NAS 이전 버전 가동 | 코드 교체·웹 재시작 필요. 새 기능 실가동으로 오인 금지 |

mon_result.md는 기준시각·상태 한 줄, **국가/종목/판단/자료 기준/가격 조건/핵심 이유** 표 하나, 주요사항 최대3개다. `MACD_BEFORE`는 골든 확정으로 쓰지 않는다. 실시간 MA5는 잠정으로 쓴다. 추천 확정 대기와 추천 없음, 데이터 준비 필요를 구분한다.

## 8. 검증·개정 이력

- v3.9 / MON-P2.0은 이전 강도 중심 기준이며 과거 커밋과 strategy_archive에 보존한다. 이전52건·93건 PASS는 새 스윙 기준의 검증 성적으로 재사용하지 않는다.
- v4.0 / MON-P3.0: 사용자의 스윙·MA5 장기 하회 후 재돌파·MACD 골든 전후 요청 반영. MACD 정정 **12·26·19** 적용. 최종 선정 권한은 ChatGPT에 유지한다.
- 새 기준 검증은 10일/9일 경계·등호·장중 MA5·MACD 기간/초기화/골든 전후·OR·시장 강도·최신 비교·잘못된 추천 거절·이력/보유/collection 보존·웹 입력/토큰/해시/크기만 대상으로 한다. 정상 수집 기능을 불필요하게 재개발하거나 모든 과거 시험을 반복하지 않는다.
- 이전 v4.0 구현 검증(12·26·19, 새 버전 성적 아님): **함수66건＋웹 HTTP19건, 총85건 PASS / 0 FAIL**. EMA12·26·시그널19는 독립적인 유리수 가중합 결과와 비교했다. 잘못된 통화·거래소·근거 없는 장중 시세, 시장과 동등/약세인 종목, 관찰 수집 한도 밖 후보 제공도 확인했다. 이 성적은 NAS 교체·운영 확인을 뜻하지 않는다.
- 배포와 전체 MON 처리시간은 실제 실행 확인 후 별도로 기록한다. 로컬 함수 처리시간을 전체 ChatGPT 실행시간이라고 쓰지 않는다.


- v4.1 / MON-P3.1 / mon.php 1.3.1: 사용자 최종 정정 **12·26·9** 적용. 완료봉 및 장중 시그널 계수0.2, 첫 시그널34봉·첫 교차35봉·최근3히스토그램36봉. 이전19일 기준 추천·신호는 이력으로 보존하고 새 자율 선정 전에는 대기 표시한다.
- 기존 원자료의 전체 구간210개 종목 복원(한국61·미국80·일본69). 기존 완료봉 값을 변경하지 않았다. 221개 전체 후보를 같은 기준으로 재계산하되 원천/최신 비교가 부족한 후보는 미검증으로 남긴다. 자료 보충 시간과 추천 판단 시간을 구분한다.
- v4.1 변경 검증: **함수30건＋웹 HTTP6건, 총36건 PASS / 0 FAIL**. 독립 유리수 가중합 EMA12·26·시그널9, 최소봉 경계, 장중 계수0.2, P3.0 전환·추천/신호 이력 보존, 이력 충돌 거절·60봉 절단 검출·collection 보존·새 프로필 웹 입출력을 확인했다. NAS 실배포 또는 신규 추천 판단 완료를 뜻하지 않는다.

- v4.1.1 / mon.php 1.3.2: 이력 복원 후1MiB를 넘은 mon_data.json에서 발생한 GITHUB_CONTENT_INVALID의 원인을 수정했다. GitHub 원문 읽기와 정확한 blob SHA 계산을 사용한다. 현재1,187,731바이트 자료·1MiB 전후·저장 상한·오류 거절·409 충돌 재읽기·collection만 갱신을 포함한 변경 검증 **19건 PASS / 0 FAIL**. 이전 오류도 크기 의존 응답으로 재현했다. NAS 교체 후 실제 접속 상태는 별도로 확인한다. MACD(12,26,9)와 자율 선정 기준은 MON-P3.1을 유지한다.

- v4.2 / mon.php 1.4.0: 종목별 요청·응답, 장외 상세 수집, 실패 시 이전 검증자료·성공 부분값 보존, 원래 자료시각 유지, 낡은 응답 배제, 자율 과거자료 참고 선택과 결과 자료 날짜를 구현했다. 변경 검증 성적과 NAS 구분은 이번 반영 기록에 남긴다.

- v4.2 최종 변경 검증: **함수47건＋데몬·응답 연계16건＋웹 HTTP10건, 총73건 PASS / 0 FAIL**. 종목별 독립 처리·재시도·마감·주말 완료봉 재사용·이전시각 보존·부분 가격 보존·KIS 100봉 페이지 제한 해소·가격 기준 충돌·잘못된 연결키/압축/수치의 개별 격리·동시 요청 교체·직전 정상자료·보조자료 자율 선정/과거 참고 저장·collection 보존을 모의 검증했다. PHP8.3에서 검사했으며 코드 문법은 PHP7.4 호환 범위를 유지한다. 실제 NAS/KIS 접속 검증은 수행하지 않았다.

- 공식 KIS API 정의: [국내 기간별 일봉](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/domestic_stock/inquire_daily_itemchartprice/inquire_daily_itemchartprice.py), [해외 일봉](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/overseas_stock/dailyprice/dailyprice.py), [국내 지수 일봉](https://github.com/koreainvestment/open-trading-api/blob/main/examples_llm/domestic_stock/inquire_daily_indexchartprice/inquire_daily_indexchartprice.py).

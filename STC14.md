# STC14 Scanner

<!-- STC14_CRON:BEGIN -->
## STC14 자동 스캔

> `stc14cron.php`가 자동 관리하는 영역입니다. Scanner 상태와 MS7 상태는 서로 다른 판단 축입니다.

- 갱신: `2026-10-01T07:14:37+09:00`
- Cron Runner: `v1.4.3` / `stc14cron-v143-stock-root-hardlock-20260928-r1`
- Scanner: `v2.4.0` / `stc14-v240-sm7-matcher-state-engine-20260910-r1`
- Scan ID: `20261001070007-c55121d0`
- 자료 품질: `LIMITED`
- 기준일: KR `2026-09-30` · US `2026-09-30` · JP `2026-09-30`
- 최종 후보: **43종목**
- Result fingerprint: `0ca7548275127106`

### 후보

| 시장 | 종목 | STC14 | D1 K/D | H60 K/D | H60 상태 | MS7 | 신뢰 | 행동 |
|---|---|---|---:|---:|---|---|---:|---|
| US | Finance ETF `XLF` | CROSS | 3.94/7.21 | 8.55/5.86 | CROSS | **M1** | 60% | 매입 검토 |
| US | JPMorgan `JPM` | CROSS | 4.48/11.66 | 9.01/6.96 | CROSS | **M0** | 64% | 관찰 |
| US | Russell2000 `IWM` | CROSS | 9.12/12.00 | 17.21/13.66 | CROSS | **M0** | 70% | 관찰 |
| KR | LG씨엔에스 `064400` | CROSS | 9.71/11.60 | 16.25/15.98 | CROSS | **M2** | 77% | 분할매입 |
| KR | CJ대한통운 `000120` | CROSS | 18.61/18.10 | 27.27/26.26 | CROSS | **M1** | 79% | 매입 검토 |
| US | UnitedHealth `UNH` | CROSS | 22.84/26.57 | 18.48/18.30 | CROSS | **M1** | 84% | 매입 검토 |
| US | Visa `V` | CROSS | 27.22/37.95 | 5.40/4.97 | CROSS | **M1** | 64% | 매입 검토 |
| KR | 파트론 `091700` | CROSS | 29.82/31.58 | 20.87/13.66 | CROSS | **W** | 60% | 대기 |
| KR | 현대해상 `001450` | CROSS | 41.53/33.08 | 18.52/17.49 | CROSS | **M2** | 60% | 분할매입 |
| US | Bank of America `BAC` | MATCH | 1.52/4.11 | 12.88/11.13 | POST_CROSS | **M0** | 65% | 관찰 |
| US | Wells Fargo `WFC` | MATCH | 2.25/6.86 | 16.81/19.13 | PRE_CROSS | **M0** | 61% | 관찰 |
| US | Home Depot `HD` | MATCH | 2.41/3.60 | 8.61/7.60 | POST_CROSS | **M2** | 65% | 분할매입 |
| US | McDonalds `MCD` | MATCH | 3.41/5.88 | 10.27/8.44 | POST_CROSS | **M2** | 69% | 분할매입 |
| JP | SMT SELECTED J-REIT ACTIVE `258A` | MATCH | 3.45/1.26 | 5.56/7.49 | PRE_CROSS | **M2** | 84% | 분할매입 |
| US | Morgan Stanley `MS` | MATCH | 3.59/5.04 | 8.58/5.72 | POST_CROSS | **M0** | 70% | 관찰 |
| KR | 에이비엘바이오 `298380` | MATCH | 3.73/4.24 | 17.95/17.38 | POST_CROSS | **W** | 60% | 대기 |
| US | Goldman Sachs `GS` | MATCH | 4.01/6.95 | 10.44/8.03 | POST_CROSS | **M0** | 71% | 관찰 |
| KR | 휴젤 `145020` | MATCH | 5.01/4.61 | 7.84/7.84 | PRE_CROSS | **W** | 60% | 대기 |
| US | Red Cat Holdings `RCAT` | MATCH | 5.29/7.85 | 26.44/23.26 | POST_CROSS | **M2** | 61% | 분할매입 |
| KR | 한국전력 `015760` | MATCH | 5.92/6.32 | 16.67/19.09 | PRE_CROSS | **M1** | 65% | 매입 검토 |
| US | Schwab `SCHW` | MATCH | 6.21/7.82 | 15.30/14.85 | POST_CROSS | **M0** | 65% | 관찰 |
| US | Schlumberger `SLB` | MATCH | 6.47/9.34 | 1.12/1.59 | PRE_CROSS | **M1** | 71% | 매입 검토 |
| US | Citigroup `C` | MATCH | 6.83/14.89 | 19.69/14.40 | POST_CROSS | **M0** | 64% | 관찰 |
| KR | 카카오페이 `377300` | MATCH | 7.25/10.21 | 7.21/8.32 | PRE_CROSS | **M0** | 86% | 관찰 |
| KR | NAVER `035420` | MATCH | 8.16/9.56 | 21.62/21.62 | PRE_CROSS | **W** | 60% | 대기 |
| KR | 한화에어로스페이스 `012450` | MATCH | 10.19/12.83 | 28.40/27.45 | POST_CROSS | **W** | 64% | 대기 |
| KR | 기아 `000270` | MATCH | 10.69/9.82 | 20.33/20.60 | PRE_CROSS | **W** | 60% | 대기 |
| KR | 삼표시멘트 `038500` | MATCH | 12.31/11.54 | 25.81/28.50 | PRE_CROSS | **W** | 79% | 대기 |
| US | Pepsi `PEP` | MATCH | 12.83/12.93 | 13.22/8.92 | POST_CROSS | **M2** | 60% | 분할매입 |
| KR | 키움증권 `039490` | MATCH | 13.80/18.80 | 17.95/18.80 | PRE_CROSS | **M2** | 60% | 분할매입 |
| KR | 클래시스 `214150` | MATCH | 15.04/18.08 | 15.87/14.81 | POST_CROSS | **M0** | 66% | 관찰 |
| US | Dow ETF `DIA` | MATCH | 15.08/21.25 | 13.60/8.88 | POST_CROSS | **M0** | 69% | 관찰 |
| KR | 하이브 `352820` | MATCH | 16.05/14.31 | 15.20/15.01 | POST_CROSS | **M2** | 75% | 분할매입 |
| KR | SK텔레콤 `017670` | MATCH | 16.70/15.94 | 11.67/13.89 | PRE_CROSS | **M1** | 78% | 매입 검토 |
| US | Uber `UBER` | MATCH | 17.70/14.97 | 12.84/12.88 | PRE_CROSS | **M0** | 79% | 관찰 |
| KR | LG `003550` | MATCH | 18.34/20.96 | 26.67/29.15 | PRE_CROSS | **M1** | 73% | 매입 검토 |
| KR | JYP Ent. `035900` | MATCH | 18.96/17.25 | 7.84/9.15 | PRE_CROSS | **W** | 62% | 대기 |
| KR | 셀트리온제약 `068760` | MATCH | 19.86/21.61 | 13.33/13.33 | PRE_CROSS | **W** | 60% | 대기 |
| KR | 신한지주 `055550` | MATCH | 20.27/23.88 | 19.50/20.13 | PRE_CROSS | **M1** | 60% | 매입 검토 |
| KR | 하나금융지주 `086790` | MATCH | 25.51/25.45 | 27.70/27.86 | PRE_CROSS | **M1** | 60% | 매입 검토 |
| US | Starbucks `SBUX` | MATCH | 25.52/22.72 | 13.68/9.05 | POST_CROSS | **M2** | 81% | 분할매입 |
| KR | 한화생명 `088350` | MATCH | 26.18/25.28 | 2.60/3.07 | PRE_CROSS | **M0** | 60% | 관찰 |
| KR | 셀트리온 `068270` | MATCH | 28.87/24.99 | 5.80/6.24 | PRE_CROSS | **M1** | 69% | 매입 검토 |

### 시장별 요약

| 시장 | Signals | Candidates | WATCH | CROSS | MATCH |
|---|---:|---:|---:|---:|---:|
| KR | 109 | 23 | 78 | 4 | 19 |
| US | 40 | 19 | 21 | 5 | 14 |
| JP | 18 | 1 | 14 | 0 | 1 |

### MS7

`M0 관찰 → M1 매입 검토 → M2 분할매입 → W 대기 → S1 매도 준비 → S2 분할매도 → S3 대부분/전량매도 검토`

MS7은 이 Scanner에서는 `SCANNER_TECHNICAL_PROXY` 근거이며, 실제 투자자별 수급을 직접 측정한 값과 동일하지 않습니다.

<!-- STC14_CRON:END -->


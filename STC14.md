# STC14 Scanner

<!-- STC14_CRON:BEGIN -->
## STC14 자동 스캔

> `stc14cron.php`가 자동 관리하는 영역입니다. Scanner 상태와 MS7 상태는 서로 다른 판단 축입니다.

- 갱신: `2026-09-29T07:14:32+09:00`
- Cron Runner: `v1.4.3` / `stc14cron-v143-stock-root-hardlock-20260928-r1`
- Scanner: `v2.4.0` / `stc14-v240-sm7-matcher-state-engine-20260910-r1`
- Scan ID: `20260929070007-cb68774f`
- 자료 품질: `LIMITED`
- 기준일: KR `2026-09-28` · US `2026-09-28` · JP `2026-09-28`
- 최종 후보: **23종목**
- Result fingerprint: `5a77cdac94b516f1`

### 후보

| 시장 | 종목 | STC14 | D1 K/D | H60 K/D | H60 상태 | MS7 | 신뢰 | 행동 |
|---|---|---|---:|---:|---|---|---:|---|
| US | Home Depot `HD` | CROSS | 5.14/8.60 | 22.67/21.13 | CROSS | **W** | 60% | 대기 |
| US | Goldman Sachs `GS` | CROSS | 8.04/11.08 | 9.82/9.81 | CROSS | **M0** | 70% | 관찰 |
| US | Uber `UBER` | CROSS | 10.52/9.75 | 23.11/22.50 | CROSS | **M0** | 69% | 관찰 |
| US | Red Cat Holdings `RCAT` | CROSS | 11.50/10.27 | 19.07/18.11 | CROSS | **W** | 68% | 대기 |
| KR | 아이티센글로벌 `124500` | CROSS | 18.89/20.78 | 3.66/2.88 | CROSS | **M1** | 82% | 매입 검토 |
| US | Energy ETF `XLE` | CROSS | 18.97/19.68 | 23.15/22.92 | CROSS | **M1** | 82% | 매입 검토 |
| JP | SMT SELECTED J-REIT ACTIVE `258A` | MATCH | 0.00/1.32 | 0.00/0.00 | PRE_CROSS | **W** | 60% | 대기 |
| KR | 파마리서치 `214450` | MATCH | 3.23/4.01 | 8.89/11.85 | PRE_CROSS | **M0** | 74% | 관찰 |
| KR | 에이비엘바이오 `298380` | MATCH | 3.84/4.13 | 3.63/5.85 | PRE_CROSS | **W** | 60% | 대기 |
| KR | 신세계 `004170` | MATCH | 4.38/6.62 | 6.94/7.87 | PRE_CROSS | **M2** | 63% | 분할매입 |
| US | Bank of America `BAC` | MATCH | 5.99/5.05 | 12.73/9.65 | POST_CROSS | **M0** | 67% | 관찰 |
| US | Morgan Stanley `MS` | MATCH | 6.12/6.02 | 25.62/22.97 | POST_CROSS | **M0** | 75% | 관찰 |
| US | McDonalds `MCD` | MATCH | 8.03/12.53 | 9.08/10.02 | PRE_CROSS | **W** | 68% | 대기 |
| US | Finance ETF `XLF` | MATCH | 9.34/7.82 | 13.68/11.32 | POST_CROSS | **M0** | 71% | 관찰 |
| KR | LG씨엔에스 `064400` | MATCH | 16.05/15.78 | 24.70/25.03 | PRE_CROSS | **M2** | 81% | 분할매입 |
| KR | HD현대일렉트릭 `267260` | MATCH | 16.19/24.36 | 14.67/14.67 | PRE_CROSS | **W** | 60% | 대기 |
| US | JPMorgan `JPM` | MATCH | 16.60/13.31 | 9.74/8.56 | POST_CROSS | **M1** | 77% | 매입 검토 |
| KR | 미래에셋증권 `006800` | MATCH | 18.18/17.40 | 10.57/11.65 | PRE_CROSS | **M1** | 79% | 매입 검토 |
| KR | 키움증권 `039490` | MATCH | 20.18/18.93 | 23.61/23.86 | PRE_CROSS | **M1** | 60% | 매입 검토 |
| US | Citigroup `C` | MATCH | 20.89/20.09 | 10.39/11.18 | PRE_CROSS | **M1** | 67% | 매입 검토 |
| KR | 한국금융지주 `071050` | MATCH | 22.88/24.47 | 5.77/7.26 | PRE_CROSS | **W** | 60% | 대기 |
| JP | INPEX `1605` | MATCH | 27.26/31.10 | 25.30/27.74 | PRE_CROSS | **W** | 60% | 대기 |
| KR | 삼성물산 `028260` | MATCH | 30.23/35.50 | 4.94/5.14 | PRE_CROSS | **M1** | 60% | 매입 검토 |

### 시장별 요약

| 시장 | Signals | Candidates | WATCH | CROSS | MATCH |
|---|---:|---:|---:|---:|---:|
| KR | 93 | 10 | 74 | 1 | 9 |
| US | 37 | 11 | 23 | 5 | 6 |
| JP | 20 | 2 | 14 | 0 | 2 |

### MS7

`M0 관찰 → M1 매입 검토 → M2 분할매입 → W 대기 → S1 매도 준비 → S2 분할매도 → S3 대부분/전량매도 검토`

MS7은 이 Scanner에서는 `SCANNER_TECHNICAL_PROXY` 근거이며, 실제 투자자별 수급을 직접 측정한 값과 동일하지 않습니다.

<!-- STC14_CRON:END -->


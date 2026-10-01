# STC14 Scanner

<!-- STC14_CRON:BEGIN -->
## STC14 자동 스캔

> `stc14cron.php`가 자동 관리하는 영역입니다. Scanner 상태와 MS7 상태는 서로 다른 판단 축입니다.

- 갱신: `2026-10-02T07:15:15+09:00`
- Cron Runner: `v1.4.3` / `stc14cron-v143-stock-root-hardlock-20260928-r1`
- Scanner: `v2.4.0` / `stc14-v240-sm7-matcher-state-engine-20260910-r1`
- Scan ID: `20261002070008-5bd8d0cb`
- 자료 품질: `LIMITED`
- 기준일: KR `2026-10-01` · US `2026-10-01` · JP `2026-10-01`
- 최종 후보: **13종목**
- Result fingerprint: `21533165a92bfbb9`

### 후보

| 시장 | 종목 | STC14 | D1 K/D | H60 K/D | H60 상태 | MS7 | 신뢰 | 행동 |
|---|---|---|---:|---:|---|---|---:|---|
| KR | 한국전력 `015760` | CROSS | 3.67/5.28 | 29.63/24.69 | CROSS | **M0** | 67% | 관찰 |
| KR | NAVER `035420` | CROSS | 3.67/6.54 | 15.32/14.11 | CROSS | **W** | 60% | 대기 |
| US | Tesla `TSLA` | CROSS | 15.91/16.89 | 28.55/26.68 | CROSS | **M0** | 63% | 관찰 |
| KR | 두산에너빌리티 `034020` | CROSS | 17.42/15.78 | 20.16/19.24 | CROSS | **M1** | 73% | 매입 검토 |
| KR | 비에이치아이 `083650` | CROSS | 18.80/14.17 | 11.85/11.40 | CROSS | **M2** | 77% | 분할매입 |
| US | Red Cat Holdings `RCAT` | MATCH | 3.48/5.05 | 19.14/17.49 | POST_CROSS | **M1** | 67% | 매입 검토 |
| US | Pepsi `PEP` | MATCH | 7.72/11.78 | 12.67/10.30 | POST_CROSS | **M2** | 59% | 분할매입 |
| JP | 동일본여객철도 `9020` | MATCH | 7.86/8.27 | 26.09/22.58 | POST_CROSS | **W** | 60% | 대기 |
| KR | BGF리테일 `282330` | MATCH | 8.46/10.14 | 14.30/15.15 | PRE_CROSS | **M0** | 62% | 관찰 |
| US | Baidu `BIDU` | MATCH | 8.87/9.73 | 11.00/11.44 | PRE_CROSS | **M0** | 66% | 관찰 |
| US | PDD `PDD` | MATCH | 14.12/19.03 | 10.01/10.06 | PRE_CROSS | **M1** | 62% | 매입 검토 |
| KR | 한화생명 `088350` | MATCH | 15.74/23.99 | 7.65/7.65 | PRE_CROSS | **M0** | 68% | 관찰 |
| KR | 현대해상 `001450` | MATCH | 32.24/36.88 | 22.22/18.86 | POST_CROSS | **M2** | 60% | 분할매입 |

### 시장별 요약

| 시장 | Signals | Candidates | WATCH | CROSS | MATCH |
|---|---:|---:|---:|---:|---:|
| KR | 107 | 7 | 92 | 4 | 3 |
| US | 45 | 5 | 39 | 1 | 4 |
| JP | 17 | 1 | 14 | 0 | 1 |

### MS7

`M0 관찰 → M1 매입 검토 → M2 분할매입 → W 대기 → S1 매도 준비 → S2 분할매도 → S3 대부분/전량매도 검토`

MS7은 이 Scanner에서는 `SCANNER_TECHNICAL_PROXY` 근거이며, 실제 투자자별 수급을 직접 측정한 값과 동일하지 않습니다.

<!-- STC14_CRON:END -->


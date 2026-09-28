# STC14 Scanner

<!-- STC14_CRON:BEGIN -->
## STC14 자동 스캔

> `stc14cron.php`가 자동 관리하는 영역입니다. Scanner 상태와 MS7 상태는 서로 다른 판단 축입니다.

- 갱신: `2026-09-28T11:32:02+09:00`
- Cron Runner: `v1.4.3` / `stc14cron-v143-stock-root-hardlock-20260928-r1`
- Scanner: `v2.4.0` / `stc14-v240-sm7-matcher-state-engine-20260910-r1`
- Scan ID: `20260928110104-ae6942d6`
- 자료 품질: `LIMITED`
- 기준일: KR `2026-09-23` · US `2026-09-25` · JP `2026-09-25`
- 최종 후보: **9종목**
- Result fingerprint: `f8890c521c0d9068`

### 후보

| 시장 | 종목 | STC14 | D1 K/D | H60 K/D | H60 상태 | MS7 | 신뢰 | 행동 |
|---|---|---|---:|---:|---|---|---:|---|
| JP | SMT SELECTED J-REIT ACTIVE `258A` | MATCH | 0.53/3.55 | 0.00/0.00 | PRE_CROSS | **M2** | 60% | 분할매입 |
| KR | 신세계 `004170` | MATCH | 6.25/7.49 | 26.33/22.47 | POST_CROSS | **M2** | 60% | 분할매입 |
| US | Red Cat Holdings `RCAT` | MATCH | 9.75/9.00 | 13.21/11.56 | POST_CROSS | **W** | 72% | 대기 |
| KR | LG씨엔에스 `064400` | MATCH | 13.17/13.03 | 26.02/15.37 | POST_CROSS | **M2** | 60% | 분할매입 |
| KR | LG디스플레이 `034220` | MATCH | 17.26/12.83 | 29.13/24.48 | POST_CROSS | **M1** | 85% | 매입 검토 |
| US | Draganfly `DPRO` | MATCH | 20.13/24.22 | 10.10/10.21 | PRE_CROSS | **M0** | 67% | 관찰 |
| KR | 포스코퓨처엠 `003670` | MATCH | 23.57/27.90 | 19.44/14.58 | POST_CROSS | **M0** | 60% | 관찰 |
| KR | 현대무벡스 `319400` | MATCH | 25.64/35.61 | 26.71/17.22 | POST_CROSS | **W** | 79% | 대기 |
| US | CoreWeave `CRWV` | MATCH | 37.75/35.07 | 15.15/15.58 | PRE_CROSS | **W** | 60% | 대기 |

### 시장별 요약

| 시장 | Signals | Candidates | WATCH | CROSS | MATCH |
|---|---:|---:|---:|---:|---:|
| KR | 100 | 5 | 83 | 0 | 5 |
| US | 38 | 3 | 33 | 0 | 3 |
| JP | 19 | 1 | 14 | 0 | 1 |

### MS7

`M0 관찰 → M1 매입 검토 → M2 분할매입 → W 대기 → S1 매도 준비 → S2 분할매도 → S3 대부분/전량매도 검토`

MS7은 이 Scanner에서는 `SCANNER_TECHNICAL_PROXY` 근거이며, 실제 투자자별 수급을 직접 측정한 값과 동일하지 않습니다.

<!-- STC14_CRON:END -->

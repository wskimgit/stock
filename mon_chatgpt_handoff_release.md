# MON ChatGPT handoff — Change and acceptance record

**Change ID:** MON-CHATGPT-BRIDGE-1.0 / 2026-10-14 KST  
**Component:** MON PHP manual-start daemon plus GitHub candidate/decision mailbox  
**Finding class:** BLOCKER (ChatGPT→PHP→ChatGPT→GitHub not wired); MATERIAL (large real input triggers multi-file compaction; unable to discard archives); DISPLAY (optional bootstrap feature marker).  
**Scope:** Bridge only. Keep MON-P3.1 swing criteria, thresholds, statistics/completion contracts, per-symbol data provenance, PHP 7.4, resource throttling, manual web start, no orders, no cron/auto-restart. No generated market files intentionally changed.

## 1. Provenance and limitations

- Inspected base source repository `wskimgit/stock`, initial review branch ancestor commit `831831a0d49fef3e7ffe661c9c3605b6ce0b1063`.
- Source before change: `mon.php` version **1.8.6**, git blob `0155e009fce92a0d2080c14c7e03389e5eb32712`, released SHA-256 `1b5ba40d41c6aa52615b3dbb8b2ada7c07908f7abe805a009045b3cb3f0e4688` (historical release manifest). `monitor.md` v4.14, blob `ddfd8dbd6af8787acacc7f9a2e00d0b8fe14c168`. Compared with current `main`: original MON blobs are unchanged despite independent Mgdaemon commits.
- Development source target: `fix/mon-chatgpt-handoff-20261013` / [PR #4](https://github.com/wskimgit/stock/pull/4).
- **Deployed NAS source version/hash: UNKNOWN.** No NAS installed file, daemon process, private GitHub token, live broker feed or read-only `?view=bootstrap` response was verified by this development work. Historical observation v1.8.5 on October 13 does not establish present state. No NAS deploy, start, stop, permission/secret change or order authorized or performed.

## 2. Expected, observed and minimal fix

Expected: ChatGPT independently discovers candidates; PHP receives authorized candidate request, fetches validated per-symbol series/index/indicator/quote evidence, returns a source-bound facts report; ChatGPT independently makes final selected/references decision; PHP verifies and stores one atomic `mon_data.json`/`mon_result.md` result with proper receipt. Actual before fix: available `mon_detail_request(s)`, prepare, finalize and publication existed in PHP, but there was no connected ChatGPT candidate/decision transport, and ChatGPT could only read historical files; the saved runtime snapshot recorded `analysis_complete=false` and `overall_complete=false`.

Root cause: missing bounded connector boundary and preserved, verifiable handoff receipt, **not** missing financial indicator formulas. Scoped bridge adds GitHub `mon_chatgpt_handoff.json` candidates/decision input and `mon_chatgpt_response.json` receipts; adds two small optional calls within the existing manually launched daemon. No ChatGPT automaton is embedded in PHP; ChatGPT acts on actual retrieved details during a user-directed turn.

Major detected defect: full actual `mon_data.json` (~1.64 MB) compaction adds a 3-file write (`mon_data.json`, `mon_result.md`, `mon_recovery_archive_*.json`). Initial bridge deliberately blocked `MON_BRIDGE_COMPACTION_REQUIRED` to prevent unsafe single-file overwrite. Correction delegates to existing `MonArtifact` + `MonPublication` journal, atomic Git tree commit and post-write hash readback. This preserves prior analysis and archive. It also releases unpacked caches before large publication, reducing observed peak memory in the exact fixture.

## 3. Exact changed files and artifact identities

SHA-256 values below are **actual final tested CI bytes** from run `38430383391`:

| File | SHA-256 |
|---|---|
| `mon.php` v1.8.7 | `5928e61aaee056f9d199a9076ae8ed86596257d5c03341ac9f219199b165c4f3` |
| `mon_chatgpt_bridge.php` | `a1235e0a9f28d7262b494be80a0b4d6a415d2d20197699c565c51cd7ba130030` |
| `monitor.md` v4.15 | `cec8588fb39d71e425bb6d798effba714f1591480713dc1f1f0ee2ee1ce59084` |
| `mon_wiki.md` | `93bf7f94fc5fa6347c13479d7e0260548ce4735572959b8fbe3580765f40d900` |
| `tests/mon_chatgpt_bridge_test.php` | `1d0891eac6fbe85ae9c8cb9e1492a0d3d841517c4c9532c846dcfe9c305f3a2a` |
| `tests/mon_chatgpt_bridge_compaction_test.php` | `59f96503d8c9889defad0441c2a73f74c43a89aad4226a506fc94380e1d13ed2` |

Also added `mon_chatgpt_handoff.md`, `tests/mon_chatgpt_bridge_observed.php`, `tests/mon_chatgpt_bootstrap_test.py`, `.github/workflows/mon_chatgpt_handoff.yml`; each tied to exact branch commit and inspected by GitHub Actions checkout.

## 4. Changed-path verification results

**Final PASS (GitHub Actions, PHP 7.4.33, Ubuntu Linux, local fake Git, no real provider calls):**
[CI run 38430383391](https://github.com/wskimgit/stock/actions/runs/38430383391) at branch head `34f660b953973e8289dd6a456e2c20749a888ac1`. The job finished SUCCESS and recorded:

| Check | Command | Result |
|---|---|---|
| Syntax | `php -l mon.php && php -l mon_chatgpt_bridge.php && php -l tests/mon_chatgpt_bridge_test.php` plus two companion files | PASS |
| ChatGPT candidate → pending/done details → final decision → two-file saved | `php -d memory_limit=96M tests/mon_chatgpt_bridge_test.php` | **7 PASS / 0 FAIL** |
| Full-sized actual repository source candidate registration (read-only) | `php -d memory_limit=96M tests/mon_chatgpt_bridge_observed.php` | PASS, 3 requests, no registration errors |
| Actual-sized synthetic Git archive atomic storage | `php -d memory_limit=96M tests/mon_chatgpt_bridge_compaction_test.php` | PASS, 3 registered, financial pair+dependent recovery archives, readback; peak 91,553,792 bytes (~87.3 MiB) |
| Existing manual web daemon lifecycle | `python3 tests/mon_web_test.py` | **26 PASS / 0 FAIL** |
| Real localhost HTTP GET bootstrap with and without optional module | `python3 tests/mon_chatgpt_bootstrap_test.py` | **3 PASS / 0 FAIL**, no automatic daemon start |
| Source provenance | `sha256sum` on exact listed code/docs/tests | PASS evidence recorded in run logs |

**Intermediate failed/repair evidence retained:**
- Initial synthetic suite **6 PASS/1 FAIL**: test mistakenly required financial publication commit SHA to equal the *later* outbox status commit HEAD; corrected test checks actual financial tree and hashes, not unrelated later Git HEAD.
- Initial full-sized capacity test proved 3-file compaction, exposing missing archive atomic path. Implemented journaled recovery and reran test PASS.
- Historical `tests/mon_nas_http_test.py` hardcoded **v1.8.1**, a STALE fixture; fails when run against newer 1.8.7, unrelated to changed bridge functionality. Did not modify production behavior to conform to outdated fixture; replaced this unnecessary test with v1.8.7 localhost HTTP bootstrap validation. Existing `tests/mon_web_test.py` separately passes.
- Initial new bootstrap test used `php -n`, unintentionally disabled required JSON runtime module and caused HTTP 500. Fixed test environment, actual HTTP PASS.

**REUSED (unchanged dependency):** existing 1.8.6 source validation and 114/0 D2.1 change-area tests from `mon_recovery_upgrade_implementation.md` apply only to their original fixed source. No claim they were rerun on 1.8.7 or count toward above new tests.

**NOT_RUN / BLOCKED:** live NAS install, deployed file hash/readback, real manual restart, actual complete candidate discovery and exchange confirmation, real KIS/Naver/Yahoo/JPX per-symbol series, actual ChatGPT final selection on the new live reports, end-to-end remote market report save, all `MON-OPERATION-COMPLETION-1.0` receipts. A synthetic success is not production proof. No other unrelated market-algorithm tests rerun.

## 5. Rollback and acceptance

- Rollback Git source: unchanged `main` original code `mon.php` v1.8.6 blob `0155e009fce92a0d2080c14c7e03389e5eb32712`, instruction v4.14 blob `ddfd8dbd6af8787acacc7f9a2e00d0b8fe14c168`.
- Package: **not requested**; Git source + reviewable PR published, deployment copy NOT_RUN, live NAS hash check NOT_RUN. Do not state a successful `main` merge until confirmed separately.
- **Acceptance level: CODE_COMPLETE / DEPLOYMENT_PENDING** on the exact branch source and final successful CI. **LIVE_VERIFIED is BLOCKED** on actual NAS installation and full real source-to-selection-to-SHA receipt.
- Next live step (separately controlled): review and merge tested Git PR, install both PHP files in NAS `/web`, use existing WEB stop/start (never cron/auto-start), observe version+capability via `?view=bootstrap`, then submit a real candidate inbox, read validated details, have ChatGPT select, and confirm the financial file-pair remote commit.

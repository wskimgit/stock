# MON change acceptance gate — PHP-CHANGE-GATE-2.0

This repository is a **clean-start MON-only repository**. The gate applies to changes to `mon.php`, `monitor.md`, `MON-Wiki.md`, active MON JSON/result files and MON CI.

## Before changing
1. Record the current main commit, MON version/build/policy and exact source identity.
2. Classify findings as **BLOCKER**, **MATERIAL**, **DISPLAY** or **STALE**.
3. Separate repository correctness from NAS deployment/live behavior. Unknown deployed identity must remain **UNKNOWN**.
4. State expected vs observed behavior and the affected boundary.
5. Make the smallest cause-based correction. Do not change strategy thresholds as incidental cleanup.

## Required invariants
- PHP 7.4 compatible.
- Manual web start only.
- No cron/autostart/auto-restart.
- No broker orders or automatic trading.
- Runtime data stays in `/web/mon_runtime` with bounded file count/size.
- `monitor.md` is the only operating authority; `MON-Wiki.md` is explanatory only.
- Code/instruction/Wiki/active JSON use the same current policy.
- Candidate identity must not silently map across the wrong country/exchange.
- Interrupted/restarted runtime must restore persisted JSON objects correctly.
- GitHub publication must use readback for important results; publication-only failure must not force unnecessary market rescans.
- A received ChatGPT decision is checkpointed locally before remote writes. Pending decision/receipt publication survives process restarts and takes priority over reference scan publication.
- `saved` means the exact Markdown/JSON pair and matching receipt were read back successfully. Partial writes, timeouts and mismatched readback remain pending; retry the same decision bytes and identifiers.
- Saving a conclusion does not require filling a country quota. A current, honest no-candidate or limited-evidence conclusion is valid; PHP reference rankings and historical decisions never become a new ChatGPT final automatically.

## Verification for code changes
Run against the **exact final artifact**:
- PHP 7.4 syntax.
- Policy/version/build consistency.
- persisted JSON normalization.
- candidate identity validation and duplicate handling when those paths change.
- CLI preflight behavior when NAS/start paths change.
- relevant candidate → scan → details → decision → result persistence scenario when those paths change.
- failure injection at result, data and receipt boundaries, restart recovery, unchanged-content readback, duplicate delivery and scan-overwrite prevention when persistence paths change.
- calculation regression when numerical code changes.
- repository minimal-file allowlist.

A source change invalidates tests that claimed to test the previous exact bytes. Unaffected historical evidence may be reused only with an explicit reason.

## NAS acceptance
Repository/CI success is **CODE_COMPLETE / DEPLOYMENT_PENDING**, not live verification.  
**LIVE_VERIFIED** requires separately observed NAS evidence:
- deployed `/web/mon.php` identity matches the accepted artifact;
- `?view=health` reports no local blockers;
- CLI PHP version/SAPI/cURL probe passes;
- runtime directory is writable and bounded;
- the relevant real lifecycle is observed.

Transient GitHub/Yahoo read failures may be warnings and may recover later; missing token, missing/invalid CLI, disabled exec or unwritable runtime are startup blockers.

## Repository cleanliness
The current tree is intentionally minimal. Legacy MON 1.x files, old snapshots, old tests and other removed systems are history only and must not be restored as current files without explicit user request.

## Final report
State separately:
- repository/CI result;
- package/publication identity;
- NAS deployment status;
- remaining blocker or **none**.

Do not claim LIVE_VERIFIED without live NAS evidence.


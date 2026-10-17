# AGENTS.md

These instructions apply to **MON only** in this repository.

## Current authority
- Runtime code: `mon.php`
- Authoritative operating instruction: `monitor.md`
- Non-authoritative summary: `MON-Wiki.md`
- Current policy: `MON-LITE-OPS-2.2.3`
- Manual NAS start only; no cron, autostart, auto-restart or orders.

## Mandatory change acceptance
Read and follow `CHANGE_GATE.md` before editing and before reporting completion. Inspect the exact current source first. Do not reconstruct current behavior from Git history or deleted legacy files.

## Operating boundaries
- Preserve the selection/numerical contract unless the user explicitly requests a strategy change.
- Keep PHP 7.4 compatibility and bounded CPU/memory/network usage.
- Do not add automatic trading or broker order execution.
- Do not mutate current market/result JSON during code maintenance unless the user explicitly requests initialization/reset or the runtime itself is performing its normal publication.
- Preserve pending ChatGPT decisions through restart and prioritize their exact-byte publication over new reference scans. Never report `saved` before result/data/receipt readback succeeds.
- Keep credentials and private NAS/account data out of GitHub.
- Treat Git publication, NAS deployment and live NAS verification as separate states.

## Repository shape
The current tree is intentionally minimal. Do not reintroduce old MON 1.x reports, archives, bridge code, legacy tests, generated market snapshots, ORDER data, STC14 output or Mgdaemon output unless the user explicitly restores those systems.

Use a reviewable branch/PR for code or contract changes and bind verification to the exact final artifact.


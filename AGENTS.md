# AGENTS.md

These instructions apply to coding agents maintaining MON and Mgdaemon in this repository.

## Mandatory change acceptance

Read and follow `CHANGE_GATE.md` before editing and before reporting completion. Required evidence missing or failing means the claimed acceptance level is blocked. Inspect the exact current source and reconcile repository/deployed identity first; do not infer code from generated reports.

## Operating boundaries

- Existing MON selection, numerical, completion and operating contracts in `monitor.md` remain unchanged.
- Preserve no-orders behavior, manual NAS start and existing no-auto-start/no-auto-restart constraints.
- Do not mutate operational data or generated market reports during a code-maintenance task.
- Keep PHP 7.4 compatibility and bounded CPU/memory use.
- No deployment, daemon control, secrets, permission changes or real orders are authorized by this file.
- Keep public evidence synthetic/redacted; never commit private logs, account state or credentials.

## Source availability

`mgdaemon.md` is generated output, not Mgdaemon source or a persistent developer instruction. Verify the actual Mgdaemon source location/version/hash before changing that component. If source is absent, report the source/provenance blocker and do not claim its code was checked or patched.

For changes, use a reviewable branch/PR and include the concise evidence record required by `CHANGE_GATE.md`. Git publication, NAS deployment and live verification are separate states.

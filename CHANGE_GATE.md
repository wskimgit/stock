# Mandatory change acceptance gate — PHP-CHANGE-GATE-1.0

Applies to developer changes to Trade, MON and Mgdaemon code, instructions, tests and release packaging. Routine generated market reports/data and existing daemon execution loops are outside this development gate; do not insert it into those paths. This is a development/acceptance contract; it does not authorize deployment, daemon control, orders, credentials or permission changes.

## Before changing anything

1. Record the inspected repository/commit, component version/revision, exact source-file SHA-256, and intended target. Record deployed version/hash separately from development source. If deployed identity cannot be observed, write UNKNOWN with the blocker; never substitute a Git version or generated report for deployed-file evidence.
2. Treat historical frozen baselines as history, not authority to overwrite newer work. Reconcile repository, working-copy and target differences before a fix intended for that target. Do not reconstruct missing source from a report.
3. Classify each finding: BLOCKER (prevents safe acceptance), MATERIAL (changes correctness or operation), DISPLAY (presentation only), or STALE (old evidence/state). Include scope and consequence. Do not promote stale data or a cosmetic warning into a runtime defect without evidence.
4. State expected versus observed behavior, reproduction inputs/steps and a failing assertion when available. If not reproduced, mark UNREPRODUCED and state what is missing. Source-level risk may justify a scoped preventive change, but is not a reproduced or live-fixed defect.
5. Identify the cause and affected boundary. Make the smallest cause-based correction; redesign only the necessary boundary when a surface patch cannot repair the cause. Do not change strategies, thresholds or execution behavior as an incidental cleanup.

## Before accepting a change

- For documentation-only changes, verify scope, links and preservation of existing instructions; do not rerun unrelated code tests. For code changes, run syntax/compatibility checks for modified code and changed-path regression tests against the final artifact. Preserve PHP 7.4 compatibility and low-load operation where required.
- Test the relevant integration/lifecycle boundary, including applicable repeated calls, duplicate handling, delayed/stale responses, partial failure, restart/resume, persistence and readback. Explain non-applicable boundaries rather than claiming they passed.
- Keep existing safety contracts: Trade SINGLE_FILE_PAPER/REAL=false, one Runner authority and position/runtime preservation; MON/Mgdaemon no orders; existing manual-start/no-auto-restart constraints. Never use live orders or unauthorized NAS actions as tests.
- Do not repeat previously PASS work without a changed dependency, invalidated evidence or a stated new verification purpose. Reference reusable evidence with its exact artifact/dependency identity and why it still applies. A changed source hash invalidates a claim that an old run tested that exact new artifact, even if unaffected checks can be reused.
- Record individual PASS, FAIL, NOT_RUN and REUSED outcomes, commands, environment, time and evidence references. Test counts alone cannot establish completion. Keep failed intermediate results in the change history.
- Bind verification to exact final source and test/fixture hashes. After an edit or conflict resolution, invalidate affected results and rerun affected checks.
- If packaging, compare packaged file hashes with the tested files. If publishing, read back the exact remote commit/file identities. A local file, successful upload response or old bundle is not remote verification.
- Keep private logs, credentials, account/order/position state and identifying source data out of public repositories. Use synthetic/redacted fixtures; store only safe evidence references and summaries.

## Component-specific review areas

Select only areas affected by the change and their dependencies:
- Trade: Engine/Broker/Runner ordering, idempotency and terminal fills, scheduling authority, position/state preservation, ambiguous transport, and dashboard/execution separation.
- MON: input/artifact identity, prepare/finalize/recovery/publication/receipt boundaries, interrupted/resumed work, manual daemon lifecycle, resource protection and current-versus-stale evidence.
- Mgdaemon: actual source availability, completed-versus-provisional data, eligibility/freshness, partial scans, persistence/report generation, daemon lifecycle and no-order behavior.

## Minimum evidence record

Keep one concise change record (Markdown or JSON) with:
- change ID, component, finding class and scope;
- inspected baseline commit/version/revision and file SHA-256;
- deployed version/hash and observation time, or UNKNOWN and reason;
- reproduction status, expected/observed behavior, cause and minimal-fix rationale;
- changed paths, final artifact file SHA-256, test/fixture identities;
- each check's command/environment/time/result/evidence, relevant boundary coverage, and reason for reuse or non-execution;
- execution-semantic change and preserved safety/compatibility contracts;
- package identity/hash comparison and remote commit/readback, or NOT_REQUESTED/NOT_RUN;
- remaining blockers, rollback source, deployment status and final acceptance level.

No cryptographic signature is required. A hash binds bytes; a record or validator alone does not prove that a test ran or a claim is true.

## Acceptance and reporting

- BLOCKED: missing required provenance, unresolved safety/correctness blocker, or required checks failed/not run. Preserve the work and report the exact next step.
- CODE_COMPLETE / DEPLOYMENT_PENDING: required code checks passed for the named exact artifact; deployed replacement and live behavior remain unverified. If a defect was unreproduced, retain that qualification and do not say it is fixed in operation.
- LIVE_VERIFIED: only after separately authorized deployment, observed deployed-file hashes match the tested artifact, and the relevant real scenario was observed with timestamped evidence. Observing an old version or an unrelated healthy screen is insufficient.

Report package/publication status separately from code and deployment status. Never collapse these into generic all-PASS or production-complete claims.

Missing evidence means the change is not accepted at that level. These instructions and review criteria do not technically lock an agent, GitHub merge or a NAS deployment. Existing CI must still run; do not claim a hard gate unless an actual blocking mechanism was configured and verified within the authorized scope.

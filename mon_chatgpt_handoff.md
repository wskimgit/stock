# MON ChatGPT ↔ PHP GitHub Handoff

- Contract: `MON-CHATGPT-BRIDGE-1.0` / MON PHP **1.8.8** / mon instruction **v4.16**
- Strategy: MON-P3.1 swing; RSI/MA/RS/MACD numeric logic unchanged; no orders, cron or automatic NAS start.
- Transport: connected GitHub `wskimgit/stock` **main** branch for mailbox JSON; existing manually started Synology `/web/mon.php` and `/web/mon_chatgpt_bridge.php` for processing.
- **Release state:** v1.8.7 bridge was observed running on NAS on 2026-10-14; v1.8.8 ERR-C D2.2 changes require separately verified NAS deployment and exact deployed source hash. Git merge and CI success alone do not prove v1.8.8 live operation.

## A. Actual request protocol

1. Upon a user `mon` instruction, ChatGPT first *independently* evaluates Korean, US and Japanese candidates. Include established, previous strong and genuine new candidates under equal criteria; prove exchange/currency/identity for each. A candidate must already have a complete symbol record in `mon_data.json`'s watchlist or verified discovery pool, otherwise the bridge returns `unknown_ids`. Use `mon_discovery_acquire` and official exchange evidence before a new symbol enters the pool.
2. ChatGPT writes the complete candidate request below to **`mon_chatgpt_handoff.json`**, using connected GitHub file creation or SHA-checked update. `request_id` stays fixed across retries; use a new ID for a new user MON run. This is a public repository: **never include account, quantity, secrets or orders**.
3. The existing manual-start PHP daemon checks the inbox between normal ticks (60-second comparison interval); registers independent symbol requests through `MonDetails::registerMany`. If full-data compaction produces `mon_data.json`, `mon_result.md` and archives, `MonArtifact` / `MonPublication` publishes all files *atomically* and verifies remote Git SHA; otherwise an optimistic single-file write is verified. On retry, same request ID does not mint different stock requests.
4. PHP fetches the per-symbol completed OHLCV, exchange benchmark, split basis, signal metrics and risk-related fields using existing providers and fallback logic. It saves validated per-symbol collection results to `mon_data.json`, then writes `mon_chatgpt_response.json` stage `details`. A row includes `state`, `error`, `usable`, `bars_as_of`, `data_status`, and only if validated its `facts` (MA, RS20, MACD, ATR and entry plans). `collection_pending` is not success.
5. ChatGPT **reads the actual detail response and its Git blob SHA**, independently compares all usable/new/previous contenders and chooses at most three non-held names per country, plus explicitly justified references. Old validated data may inform a reference, but it cannot be called a new verified quote or an eligible latest signal.
6. ChatGPT updates the inbox to stage `decision`, echoing exact original `candidate_ids`, `request_id`, original candidate inbox blob `candidate_request_sha`, complete detail response blob `details_receipt_sha`, and `source_blob_sha` returned in the detail response. A duplicate, stale, unreceived or changed source is blocked with a recoverable status.
7. PHP validates exact response provenance, then runs the pre-existing `MonDetails::prepare` + `MonDetails::finalize`, not a PHP heuristic selector. `MonPublication` saves `mon_data.json` + `mon_result.md` in the same Git tree transaction with dependency archives and independently reads the remote commit back. When response `stage=decision` says `status=saved`, only **the financial pair's remote storage** is proved. `operation.overall_complete` additionally requires live NAS health, fresh trade/issuer review and statistics/receipts.

No component of PHP invokes ChatGPT by itself. ChatGPT initiates candidate and decision messages in response to user requests through a GitHub connector. The daemon's processing continues independently after its manual launch, but a ChatGPT follow-up/active tool turn is needed to read the response and make the autonomous final decision. **Do not promise background ChatGPT turns.**

## B. Inbox examples (illustrative only — not yet submitted)

Initial **candidate stage**:

```json
{
  "contract": "MON-CHATGPT-BRIDGE-1.0",
  "stage": "candidates",
  "request_id": "mon-20261014-manual-001",
  "candidate_ids": [
    "KR|KOSPI|032640",
    "US|NYSE|SNOW",
    "JP|TSE|6098"
  ]
}
```

Decision stage **after actually reading** the returned detail report, replacing all 40-character placeholders with exact blob SHAs from the respective GitHub file versions:

```json
{
  "contract": "MON-CHATGPT-BRIDGE-1.0",
  "stage": "decision",
  "request_id": "mon-20261014-manual-001",
  "candidate_ids": [
    "KR|KOSPI|032640",
    "US|NYSE|SNOW",
    "JP|TSE|6098"
  ],
  "candidate_request_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "details_receipt_sha": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
  "source_blob_sha": "cccccccccccccccccccccccccccccccccccccccc",
  "selected": [
    {
      "symbol_id": "KR|KOSPI|032640",
      "reason": "Replace with independently compared recent signal, risk and actual data evidence"
    }
  ],
  "references": []
}
```

Do not submit either example automatically. Selected IDs must actually appear in validated, eligible PHP data; otherwise finalization rejects them. Complete issuer/trading confirmations are separate; missing current trading facts cannot be converted into a ready-to-buy recommendation.

## C. Responses and recovery

| `stage` / `status` | Meaning / ChatGPT action |
|---|---|
| `candidates/registered` | Incoming identifiers registered; await actual detail collection |
| `candidates/registered_existing` | Exact request already registered on GitHub; old conflicting intake journal left intact for diagnosis, not replayed |
| `candidates/registered_partial` | Some identities or slots failed; continue good names and repair others |
| `candidates/identity_registration_required` | No known official identities; verify via exchange and register first |
| `candidates/registration_publishing` | Multi-file compacted intake queued; wait for GitHub file-pair readback, do not infer registration |
| `candidates/registration_blocked` | Journaled storage/permission/conflict error; use unchanged job/context for repair |
| `details/collection_pending` | Individual unfinished, stale or conflicting rows; do not treat them as completed |
| `details/collected` | Each requested row has a hash-checked usable snapshot. This does not prove market-current trade readiness |
| `decision/publication_pending` | ChatGPT choice accepted; original financial artifact queued |
| `decision/saved` | Financial pair read back, `published_commit_sha` returned (may differ from later mailbox-status HEAD) |
| `refresh_source` | New quote/collection file changed exact source SHA; inspect latest details and reselect/retry |
| `recovery_pending` | Determine returned `error_code`. Authentication, source, slot or permission must not be called successful |

GitHub `contents` writes use optimistic blob SHA; source conflicts are never force-updated. If a status response is lost, reread the same inbox/outbox and unchanged publication journal; do not blindly submit a new decision. Explicit user stop always takes priority. Keep existing alert/order/NAS boundaries unchanged.

### ERR-C D2.2 supplementary checks

- Quote-only collection updates with unchanged verified candidate facts do not overwrite the prior detail report. Its exact SHA remains part of the ChatGPT decision; the PHP finalizer compares stable watchlist/analysis facts and per-candidate snapshot SHA with the latest source before proceeding.
- Source facts changed, a new market day completed, the response was replaced, or selected eligibility changed: refresh and reselect. Historical current-date snapshots may only participate under original MACD/RS thresholds and conditional readiness; do not label them newly collected.
- A full GitHub source file already containing the same exact candidate requests is `registered_existing`, regardless of a local obsolete conflicting registration journal.
- `HTTP_404` / insufficient 21-bar history at a final provider: retry independent providers with protected per-symbol route state; after repeated failure, mark blocked, preserving prior verified reference data.

## D. Verification and deployment

Source files: `mon.php`, `mon_chatgpt_bridge.php`, `monitor.md`, `mon_wiki.md`. Tests:
```bash
php -l mon.php
php -l mon_chatgpt_bridge.php
php -d memory_limit=96M tests/mon_chatgpt_bridge_test.php
php -d memory_limit=96M tests/mon_chatgpt_bridge_observed.php
php -d memory_limit=96M tests/mon_chatgpt_bridge_compaction_test.php
```

CI is in `.github/workflows/mon_chatgpt_handoff.yml`, synthetic local Git only; it does not call broker or NAS and executes no orders. Before live use, review/merge tested branch, install **both PHP files** together into NAS `/web`, stop and manually start using the existing Web button (no cron/no daemon auto-start), and verify real `?view=bootstrap` responds `processor_version=1.8.8` with `chatgpt_bridge` capability plus a new boot/heartbeat. Observe a real first-stage candidate mailbox, real detailed rows, ChatGPT final selection and saved Git file-pair SHA. Without this, classify status `CODE_COMPLETE / DEPLOYMENT_PENDING` at most.

Live access, installed hash, NAS credential health, source conflicts and new market-bar validity are separate unknowns until actually observed. Never copy synthetic test results into market execution statistics.

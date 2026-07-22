# REL-4E - Technical Service Workflow and Messaging Closure

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Close PR #88 with a secure, complete Technical Service/Locksmith workflow whose messages, job cards, uploads, assignments, appointments, payments and earnings are accepted on the current exact head before staging/production promotion.

## Dependencies

Current PR #88 product chain, environment foundation, partner/technician identity, public HTTPS for live links, provider readiness, and independent exact-SHA acceptance.

## Included scope

- Preserve accepted copy/upload/job-card/assignment/appointment/payment/earning behavior.
- Preserve the independently accepted claim/permit/HTTP/finalize guarantees and guarded messaging execution-mode lineage at the current product head; current-head PostgreSQL/RBAC/browser acceptance remains a separate gate.
- Run one separately authorized, allowlisted assignment-offer WhatsApp + SMS pair with exact reconciliation.
- Complete current-head browser, clean-migration and staging acceptance before PR #88 readiness is decided.

## Excluded scope

New CRM, root MRN redesign, general RBAC expansion, collaboration, repair, Mikro cutover, production deployment, and any new REL implementation beyond PR #88 closure.

## Source of truth

Laravel Technical Service request/assignment/dispatch/settlement records are authoritative. Provider ACK is transport acceptance, not delivery. The status ledger is authoritative for release state.

## Entry criteria

Exact remote product head `29546a546bccf4575d3c9fd9c6c2587355c81aef` and tree `b2b140a3b40758ed519853eeb0d0db110ba2217e` on open Draft PR #88; guarded messaging implementation `63e8e9febe96dbfc64b666aa9c82adf054d36d1f`, fixture alignment `78a3a82a4d734875aec9ad85bd2bca0251b342f2`, deterministic repair at the current head; exact-SHA quality and PHP 8.3/8.4/8.5 CI green with 1681 tests, 15315 assertions and 18 skipped; external send zero. Current-head disposable PostgreSQL, targeted RBAC/browser, public HTTPS/provider readiness and separate send authorization remain gates.

## Exit/acceptance criteria

Independent exact-head security acceptance, deterministic PostgreSQL concurrency/crash proof, exact-SHA CI green, bounded real assignment-offer acceptance, full current-head browser regression, clean migration/staging smoke, and explicit PR #88 Ready/merge decision. Acceptance does not itself merge or deploy.

## Exact evidence requirements

Record SHA/tree, changed files, PostgreSQL version/connection class without secrets, controlled interleavings, transaction level at HTTP, direct-client denials, provider invocation count, CI URLs, browser hashes, cleanup, runtime freeze and exact data reconciliation.

## RBAC/tenant isolation

OPS/admin lifecycle operations require dedicated server authorization. Technician job-card scope is derived server-side from active assignment and membership; wrong/reassigned actors and query tampering fail without PII.

## Audit/event contract

Record actor, run/window/dispatch, request and offer-cycle references, state transition, redacted recipient fingerprint, result semantics and timestamps. Never log full phones, credentials or tokens.

## Migration/schema

The accepted lifecycle implementation uses existing persisted structures. Any schema need discovered by independent review requires a separate scoped REL and PostgreSQL fresh/upgrade proof.

## Backfill/import

None. Historical dispatch/audit records remain immutable and are not rewritten to make tests pass.

## DEV/UAT/PROD env

Manual E2E is local/UAT-only and off by default. Production ignores LAN origin overrides and requires canonical public HTTPS. Provider send gates remain disabled outside separately authorized windows.

## Public/internal URL and callback

Technician `/pj/{job}` is authenticated and server-scoped. Customer approval/payment links use their own public HTTPS resolver. Internal support/preview URLs never enter outgoing messages.

## Secret/credential

Provider credentials remain external to Git/evidence. Readiness may verify presence without exposing values; credential save never authorizes a send.

## Feature flag and safe default

Default is frozen: Manual E2E off, real send off, queue paused, OPS channels off, no active run/window/lease.

## Inherited Local/Live control-plane

REL-4E inherits the global contract in [MASTER_REL_ROADMAP.md](../MASTER_REL_ROADMAP.md); status remains only in the ledger.

| Capability | Class | Activation | Readiness |
| --- | --- | --- | --- |
| `messaging.evolution.send` | `OUTBOUND_COMMUNICATION` | `REQUIRED` | Environment-bound Evo profile, queue/claim/permit, consent/allowlist, public URL and reconciliation |
| `messaging.nac.send` | `OUTBOUND_COMMUNICATION` | `REQUIRED` | Environment-bound NAC profile, queue/claim/permit, consent/allowlist and reconciliation |

`LOCAL` preserves suppressed intent/audit and produces provider HTTP zero, including in PROD; it never substitutes a localhost endpoint. `LIVE` requires the current global epoch, capability revision and profile fingerprint. Non-production provider access additionally requires the exact run/window/claim/one-time permit. Stale queue/retry/DLQ work stays blocked. Close/freeze preserves accepted or ambiguous provider truth and never blindly retries.

## Queue/worker/scheduler/cron

Only exact-dispatch bounded workers are allowed during authorized Manual E2E. Generic workers, replay, retry, fallback and stale requeue of attempted work are prohibited.

## Build/restart/post-deploy command

Run exact PHP tests and frontend lint/build, deploy immutable artifact with sends disabled, clear/cache using the approved runbook, restart only declared workers, attest frozen runtime, then run no-send smoke.

## External provider contract

At most one application-authorized attempt per exact dispatch. Durable claim precedes HTTP; a one-time permit is consumed at the final client boundary. Timeout/ambiguous ACK is no-retry. `accepted`, `sent` and `delivered` remain distinct.

## Health check

Verify settings readiness, no impossible lifecycle state, queue/lease health, public URL readiness, provider configuration, pending/unsafe counts and direct-client guard integrity.

## Log/metric/alert

Track lifecycle phase, open/expired/consumed windows, claim/HTTP/finalize transitions, attempts, ambiguity, direct bypass denial, pending age, duplicate blocks and provider result, all redacted.

## Backup/restore

Create verified Git/DB checkpoints before staging mutation. Restore rehearsal must not replay provider attempts or reset consumed windows.

## Cutover

Verify exact current head, green CI and frozen runtime -> confirm public HTTPS/provider readiness -> obtain separate explicit authorization for one allowlisted WhatsApp + SMS pair -> execute once -> reconcile dispatch/provider/attempt/result and refreeze -> complete current-head browser, clean-migration and staging acceptance -> separately decide PR Ready/merge. Production remains disabled throughout REL-4E acceptance.

## Rollback/disable

Freeze lifecycle, stop bounded workers, keep attempts/audit truthful, disable provider flags and roll back application artifact separately. Never delete provider evidence or retry ambiguity.

## Production smoke

Deferred to REL-15. Any live message needs separate recipient, channel, attempt and rollback authorization.

## Data reconciliation

Reconcile exact dispatch count, target fingerprint, attempt/result/provider identifiers, no unrelated recipient/channel, workflow state, pending/unsafe zero and frozen runtime.

## S0/S1/S2 blockers

S0: unexpected external recipient/send, cross-tenant access, duplicate provider attempt. S1: replay path, transaction-open HTTP, missing public URL/security guard, stale mapping. S2: non-blocking UX/maintenance with owner.

## Go/No-Go owner

Burhan owns product priority; independent security/release verifier owns exact acceptance; operations/provider owner approves any real send; merge/deploy require separate authorization.

## Open decisions

- Recipient, time window and separate authorization for the controlled assignment-offer WhatsApp + SMS pair.
- Public HTTPS and provider-readiness result for that acceptance.
- Current-head browser, clean-migration and staging result.
- PR #88 Ready/merge decision after all gates.

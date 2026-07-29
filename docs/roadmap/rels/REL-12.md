# REL-12 - Repair, Fault and Parts Lifecycle

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Manage diagnosis, repair and parts from request through reservation, movement, repeated visit, final serial and settlement while preventing completion when ERP or part state is unresolved.

## Dependencies

REL-4H, REL-4G, REL-7, REL-9, REL-10A and INT-MIKRO preparation.

## Included scope

Fault/diagnosis/repair, part request/decision, reservation, shipment/delivery/use/return, child SRV/repeat visit, part cost/fee/earning, final serial, warranty/service completion blockers and Mikro outbox/reconcile.

## Excluded scope

Rebuilding already accepted basic part request/OPS decision, generic audit UI (REL-10B), warranty CRM presentation (REL-13), warranty KPI formula (REL-14) and final Mikro credential activation.

## Source of truth

Laravel owns service/repair/part operation and settlement context; Mikro owns stock/reservation/movement and ERP documents; outbox/reconcile bridges them.

## Entry criteria

State/final-serial contracts, event backbone, settlement ownership and Mikro operation catalog accepted; existing part flows inventoried.

## Exit/acceptance criteria

Every part has an auditable lifecycle; stock movement is idempotent and reconciled; repeat visit/child SRV and final-serial links are correct; costs/earnings agree; service or warranty completion cannot close while repair, part or ERP state is unresolved/ambiguous.

## Exact evidence requirements

Exact SHA, part/state matrix, fake/shadow Mikro tests, outbox/replay/ambiguity proof, parent/child settlement reconciliation, browser field/OPS acceptance and event coverage.

## RBAC/tenant isolation

Technician, partner, OPS and finance actions are separately authorized to exact assignment/partner. Part inventory/cost and other-tenant jobs are hidden.

## Audit/event contract

Emit diagnosis, request, approval/rejection, reserve, ship, deliver, use, return, repeat visit, cost/fee, ERP result/reconcile and completion block.

## Migration/schema

Use additive part lifecycle, movement, outbox/idempotency and linkage constraints. Preserve accepted historical part requests.

## Backfill/import

Preview/classify current requests/movements; ambiguous stock/document state enters review. Do not fabricate ERP movement.

## DEV/UAT/PROD env

Fake/shadow ERP in tests/UAT. PROD write flags remain off until final Mikro activation.

## Public/internal URL and callback

Internal actions are authenticated/scoped. Customer-facing part status reveals no stock/cost and uses canonical HTTPS.

## Secret/credential

ERP/provider secrets remain external; part files and PII follow storage/redaction policy.

## Feature flag and safe default

ERP reserve/movement and completion transition default off/fail-closed independently.

## Inherited Local/Live control-plane

REL-12 inherits [the global contract](../MASTER_REL_ROADMAP.md). It owns `parts.movement.intent` (`INTERNAL_ONLY`, `REQUIRED`); INT-MIKRO owns the external ERP adapter. Readiness requires domain authorization, exact quantities/warehouse, outbox identity and completion blocking. `LOCAL` may retain a suppressed intent but performs no ERP write. `LIVE` rechecks epoch/revision/profile before gateway handoff. Stale/retry/DLQ work remains blocked. Disable stops new movement claims and reconciles physical/Mikro truth before compensation.

## Queue/worker/scheduler/cron

Outbox/reconcile jobs are operation-specific, idempotent, leased and ambiguity-safe. No automatic write fallback.

## Build/restart/post-deploy command

Deploy disabled -> migrate -> backfill preview/apply -> shadow reads -> fake write/reconcile -> browser UAT -> enable accepted operations only at REL-15.

## External provider contract

Mikro movement/document references and line/quantity/warehouse reconcile exactly; ambiguous write is no blind retry.

## Health check

Pending/ambiguous outbox, reservation age, unresolved return, completion blockers, stock parity and repeat-visit consistency.

## Log/metric/alert

Alert on duplicate movement, negative/inconsistent stock, completion bypass, orphan part/SRV, settlement mismatch or stale ambiguity.

## Backup/restore

Restore does not replay ERP movement. Reconcile external stock/documents before reopening part writes.

## Cutover

Shadow read/parity -> controlled reserve/movement canary -> reconcile -> enable workflow steps -> full repair acceptance.

## Rollback/disable

Disable new ERP/part mutations, preserve outbox/audit and physical truth, reconcile before compensating action; never blind DB restore over external side effects.

## Production smoke

One synthetic part request through reserve/use/return or accepted subset with exact ERP reconciliation and completion-block negative case.

## Data reconciliation

Request/decision, quantities, reservation/movement, warehouse/document, child SRV/repeat visit, final serial, warranty/service completion blocker, fees and earning totals must agree.

## S0/S1/S2 blockers

S0: duplicate/wrong ERP movement, cross-tenant part access. S1: completion bypass, lost part, settlement mismatch, unreconciled ambiguity. S2: non-critical UX.

## Go/No-Go owner

Service/parts operations, ERP/inventory, finance, security and release owners.

## Open decisions

Reservation expiry, return/quarantine policy, repeat-visit charging and exact Mikro movement operations.

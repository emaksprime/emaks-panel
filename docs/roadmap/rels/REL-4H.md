# REL-4H - Immutable Root MRN

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Provide one global, immutable, non-resetting root MRN that is never reused and cleanly owns one or more child SRV lifecycles.

## Dependencies

Environment/database foundation, accepted source-of-truth ownership, REL-10A event design, and a maintenance window for backfill.

## Included scope

Root MRN sequence/allocation, child SRV identity separation, uniqueness constraints, collision-free backfill, restore/deletion/deploy non-reuse, and orphan/duplicate reconciliation.

## Excluded scope

SRV business state machine (REL-7), customer deduplication (REL-5C), QR self-service (REL-6), and ERP document numbering.

## Source of truth

Laravel PostgreSQL is authoritative for root MRN and child SRV identity. Mikro document numbers are references, never replacement MRNs.

## Entry criteria

Approved identifier format, retention policy, production-like data inventory, duplicate/orphan report, and restore-safe sequence design.

## Exit/acceptance criteria

Concurrent creation is collision-free; deletion, restore, rollback and deploy cannot reuse any issued root MRN; every SRV has exactly one valid root; backfill is idempotent; duplicates/orphans are zero.

## Exact evidence requirements

Exact schema SHA, PostgreSQL concurrency tests, before/after counts, max/sequence values, collision report, restore rehearsal and sampled root-to-SRV drill-down.

## RBAC/tenant isolation

Identifier lookup must not reveal another tenant's customer/service data. Allocation is server-only; clients cannot choose or overwrite root MRN.

## Audit/event contract

Emit root allocation, child creation/link, attempted collision, corrective backfill and admin override events with correlation and reason.

## Migration/schema

Use additive constraints/indexes and a deploy-safe allocation mechanism. Fresh install and current-production upgrade paths must both pass.

## Backfill/import

Preview all mappings, use stable source keys, block collisions, apply in resumable batches and reconcile every row. Never reset sequences to hide gaps.

## DEV/UAT/PROD env

Use the same allocation algorithm and constraints. Fixture/test namespaces must never enter production identity space.

## Public/internal URL and callback

MRN in URLs remains authorization-scoped. Public lookup must resist enumeration and return PII-safe denials.

## Secret/credential

No new secret is expected. Database credentials remain externally managed.

## Feature flag and safe default

New allocation remains off until schema/backfill/reconcile pass; legacy writes are frozen during cutover.

## Queue/worker/scheduler/cron

Backfill jobs are bounded, idempotent, leased and restartable. No background job may allocate a duplicate or rewrite an issued MRN.

## Build/restart/post-deploy command

Backup -> deploy additive schema -> verify constraints -> run dry-run -> controlled backfill -> reconcile -> switch allocator -> restart workers -> no-write/read smoke.

## External provider contract

None. MRN may be rendered in messages only after canonical allocation and authorization.

## Health check

Allocation probe, sequence/maximum monotonicity, duplicate/orphan counts and failed-allocation rate.

## Log/metric/alert

Alert on collision, reuse attempt, missing root, multiple roots, sequence regression or allocation latency threshold.

## Backup/restore

Restore rehearsal must prove issued identifiers are not reused after point-in-time recovery and cutover continuation.

## Cutover

Freeze creates -> backup -> migrate -> dry-run/backfill -> reconcile -> enable new allocator -> resume creates -> monitor.

## Rollback/disable

Stop new creates and application writes; do not drop new identity data or roll sequence backward. Roll application forward or repair mappings under audit.

## Production smoke

Create one authorized synthetic root with multiple child SRVs, verify uniqueness/drill-down, then clean only permitted fixture records while retaining allocation gap.

## Data reconciliation

Issued count, unique count, rootless SRV, multi-root SRV, duplicate ID, sequence/max and historical link counts must match approved expectations.

## S0/S1/S2 blockers

S0: collision/reuse/wrong customer link. S1: orphan, non-idempotent backfill, restore regression. S2: display-only inconsistency.

## Go/No-Go owner

Data owner, Technical Service product owner, DBA/release owner and independent verifier.

## Open decisions

Final format/allocation strategy, retention behavior for deleted business records, and production backfill batch size.

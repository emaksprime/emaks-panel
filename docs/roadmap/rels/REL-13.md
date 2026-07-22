# REL-13 - CRM 360

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Give authorized staff one source-labelled customer view spanning identity, assets, service, finance, interactions and immutable history without creating a second business truth.

## Dependencies

REL-5C, REL-4H, REL-10A/B, REL-11, REL-12, REL-9 and INT-MIKRO read projection.

## Included scope

Identity/contacts/masked cari, products/serials, order/invoice/dispatch dates, Laravel installation, warranty/activation/QR, root MRN/child SRVs, appointments/technicians/parts, payments/earnings, messages/calls/comments/files/surveys/admin decisions, unified timeline, source/last-sync and exact drill-down. Search by phone/customer/MRN/SRV/all serials/cari/order/invoice/dispatch.

## Excluded scope

Owning or mutating ERP truth, generic interaction engine (REL-11), Happy Call/survey domain (REL-14), or silent customer merge.

## Source of truth

Mikro owns ERP stock/cari/order/proforma/invoice/dispatch/sale/serial movement. Laravel owns customer identity/link decisions, MRN/SRV, installation, service, warranty, parts/payment operations, calls, audit, Happy Call and surveys. CRM 360 is a sourced projection.

## Entry criteria

Identity/duplicate rules, event backbone, interactions/repair/payment domains and Mikro read parity accepted; source ownership matrix has no conflict.

## Exit/acceptance criteria

Every field/timeline row shows source and freshness; all required searches are server-side, authorized and complete; drill-down reaches exact source; no competing edits or hidden partner/customer leakage; projection reconciles to sources.

## Exact evidence requirements

Exact SHA, source-field matrix, projection parity report, multi-key search/security/performance tests, timeline ordering/drill-down browser evidence and freshness/lag metrics.

## RBAC/tenant isolation

Field-level masking/reveal and entity scope apply to projection and search totals. Hidden records/names/counts never reach the client.

## Audit/event contract

CRM read/reveal/export and link/unlink actions emit events. Timeline displays source events but cannot rewrite them.

## Migration/schema

Projection/index schema is rebuildable from source, versioned and indexed for production search. No source record is migrated into an uncontrolled duplicate truth.

## Backfill/import

Build projection in bounded resumable batches, report conflicts/staleness and reconcile counts/checksums per source.

## DEV/UAT/PROD env

Production-like anonymized data is required for search/performance acceptance. Environment source endpoints/indexes are isolated.

## Public/internal URL and callback

CRM 360 is internal/authenticated. Drill-down reauthorizes exact source; file/media URLs are short-lived/scoped.

## Secret/credential

Source credentials remain external; search/log/evidence redact PII and identifiers according to permission.

## Feature flag and safe default

Projection and UI default read-only/off; stale source is shown as stale, never silently treated current.

## Inherited Local/Live control-plane

REL-13 inherits [the global contract](../MASTER_REL_ROADMAP.md). It owns `crm.projection.refresh` (`BACKGROUND_AUTOMATION`, `REQUIRED`). Readiness requires source-labelled checkpoints, freshness thresholds, idempotency and dead-letter visibility. `LOCAL` permits internal projection only from already accepted sources and performs no hidden external read/write. `LIVE` rechecks source capability epoch/revision/profile before refresh. Stale jobs remain quarantined. Disable stops refresh/search workers while preserving authoritative source data and visible freshness.

## Queue/worker/scheduler/cron

Projection workers consume accepted events/source checkpoints idempotently with lag/dead-letter monitoring.

## Build/restart/post-deploy command

Deploy read-only schema -> migrate -> initial projection -> parity/reconcile -> start incremental workers -> browser/search UAT -> enable internal cohort.

## External provider contract

Mikro/Voibot/provider data is read through accepted gateways/events. CRM never performs hidden external writes.

## Health check

Projection freshness, source availability, lag/DLQ, search index health, drill-down availability and parity.

## Log/metric/alert

Alert on stale/failed source, parity mismatch, hidden-data leak, indexing lag, orphan timeline or broken drill-down.

## Backup/restore

Projection backup is secondary; rebuild and reconcile from authoritative sources after restore without replaying external effects.

## Cutover

Backfill/reconcile -> incremental shadow -> internal UAT -> read-only enable -> performance/security observation -> broader staff enable.

## Rollback/disable

Disable CRM UI/search/workers; sources remain authoritative and operational. Drop/rebuild projection only under a safe data runbook.

## Production smoke

Synthetic customer with multiple contacts/assets/serials/root/SRVs/interactions proves search, source labels, masking and drill-down.

## Data reconciliation

Per-source row counts/checkpoints, customer/entity links, timeline events, financial totals and last-sync values must agree.

## S0/S1/S2 blockers

S0: cross-customer/tenant/PII leak or wrong source truth. S1: stale data shown current, missing history/search, broken drill-down. S2: non-critical layout issue.

## Go/No-Go owner

CRM/product, data, security/privacy, ERP/service operations and release owners.

## Open decisions

Projection freshness SLA, field-level reveal matrix, search ranking and timeline retention/display density.

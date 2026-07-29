# REL-10 - Event Backbone and Common Audit UI

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md). REL-10A is the event backbone; REL-10B is the shared audit/activity UI.

## Business outcome

Create one immutable, queryable operational history that every module and KPI can trust, then expose it through authorized, redacted search and drill-down.

## Dependencies

REL-4H identity and REL-5 actor/permission model. REL-10A must precede new downstream modules; REL-10B follows event adoption.

## Included scope

Append-only versioned events with actor/system actor, acting role/membership, entity, root MRN/SRV, correlation/causation/request ID, state transition, redacted before/after, reason, source and time; critical-event coverage; common activity search/filter/drill-down/export/provenance UI.

## Excluded scope

Domain state ownership, mutable business snapshots as audit replacement, generic call domain (REL-11), CRM projection (REL-13), and KPI formulas (REL-14).

## Source of truth

Domain tables remain current-state truth; immutable REL-10A events are history/provenance truth. REL-10B is a read projection, never a competing mutable history.

## Entry criteria

Actor/entity identifiers approved, redaction policy defined, event schema/versioning accepted and critical mutation inventory complete.

## Exit/acceptance criteria

All critical event classes emit exactly once with required fields; events cannot be edited/deleted through product APIs; missing-event metric is zero; UI search/filter/drill-down/export obey RBAC and source provenance.

## Exact evidence requirements

Exact SHA, schema/version docs, event coverage matrix, idempotency/concurrency tests, redaction snapshots, migration/backfill reconciliation, query performance and browser evidence.

## RBAC/tenant isolation

Event visibility follows current and historical authorized scope without leaking hidden entity names/counts. Export and PII reveal are separate audited permissions.

## Audit/event contract

The event itself is append-only. Corrections are new linked events. Schema version, occurred/recorded times and correlation/causation are mandatory.

## Migration/schema

Partition/index strategy, immutable constraints and versioned payload schema must support production volume and retention without destructive history edits.

## Backfill/import

Backfill only derivable historical facts with `source=backfill`, stable keys and confidence/exception reporting. Do not fabricate actor/reason.

## DEV/UAT/PROD env

Environment and test flags are explicit; test events are excluded from production KPI by source, not hidden ad hoc.

## Public/internal URL and callback

Audit UI is internal/authenticated. Drill-down reauthorizes destination entity. Export URLs are short-lived and scoped.

## Secret/credential

No secrets or raw credentials enter event payloads. Structured redaction covers nested headers, tokens and PII.

## Feature flag and safe default

Event enforcement can shadow-read initially, but critical mutation must fail closed once required. Audit export defaults off.

## Inherited Local/Live control-plane

REL-10A inherits [the global contract](../MASTER_REL_ROADMAP.md) and owns `audit.event.append` (`INTERNAL_ONLY`, `REQUIRED`). Readiness requires append-only schema, complete critical-event coverage, actor/correlation/reason and redaction. Audit remains active in LOCAL and LIVE; it cannot be disabled to conceal blocked or ambiguous external effects. Async projections snapshot epoch/revision and reject stale writes. Rollback disables UI/export/projection separately but never deletes accepted events.

## Queue/worker/scheduler/cron

If async projection is used, source event commit is transactional/outbox-safe; projection retries are idempotent and lag is monitored.

## Build/restart/post-deploy command

Deploy schema -> migrate -> backfill preview/apply -> reconcile -> enable event emission shadow -> enforce critical coverage -> build projection -> enable read-only UI/export separately.

## External provider contract

Provider request/result/webhook events record redacted identities and accepted/sent/delivered distinctions without storing secrets.

## Health check

Event write success, missing-event detector, projection lag, schema-version compatibility, dead-letter count and search availability.

## Log/metric/alert

Alert on missing/duplicate event, invalid schema, redaction failure, projection lag, unauthorized export or retention failure.

## Backup/restore

Restore preserves ordering and immutability; projection may rebuild from events without duplicating external effects.

## Cutover

REL-10A schema/emission -> critical coverage enforcement -> downstream adoption -> REL-10B projection/UI -> authorized export.

## Rollback/disable

Never delete accepted events. Disable UI/export/projection separately; stop critical mutations if required events cannot be recorded.

## Production smoke

Generate synthetic allowed/denied critical actions, verify exactly one redacted event and authorized drill-down/export behavior.

## Data reconciliation

Domain mutation count, required event count, unique idempotency/correlation, projection rows and export rows must reconcile.

## S0/S1/S2 blockers

S0: missing critical event, secret/PII leak, cross-tenant audit leak. S1: duplicate/out-of-order event, unrebuildable projection. S2: search/display issue.

## Go/No-Go owner

Architecture, security/privacy, data, operations and release owners.

## Open decisions

Event storage/partition/retention, historical-scope visibility, backfill confidence policy and export format/limits.

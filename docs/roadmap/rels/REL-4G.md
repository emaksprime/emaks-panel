# REL-4G - Cancellation, Compatibility, Exchange and Final Serial

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Make cancellation, compatibility checks, additional-information requests and product exchange safe while preserving serial history and allowing completion only after authoritative ERP reconciliation.

## Dependencies

REL-4H, REL-5, REL-10A and INT-MIKRO preparation.

## Included scope

Cancellation, compatibility, extra information, exchange, sales task/mail, authoritative replacement product/serial lookup, old-serial retention, quarantine/return, root-MRN linkage, final installed serial, Mikro outbox/reconcile and replay protection.

## Excluded scope

General state-machine closure (REL-7), public QR/OTP (REL-6), repair lifecycle (REL-12), and direct production credential activation.

## Source of truth

Mikro owns ERP stock/serial/documents; Laravel owns root MRN, SRV, installation, appointment, service and operational exchange state. The mounted final serial is the warranty/activation/QR source.

## Entry criteria

Root MRN accepted, event contract available, Mikro operations mapped with `unmapped=0`, exchange business rules approved and existing serial records inventoried.

## Exit/acceptance criteria

Old serial is immutable; uninstalled serial cannot start warranty/activation/QR; replacement links to the same root; ERP failure/ambiguity blocks completion; replay creates no second ERP document; return/quarantine and sales follow-up are auditable.

## Exact evidence requirements

Exact SHA, state/serial diagrams, operation catalog entry, outbox/idempotency keys, fake ERP timeout/duplicate/reconcile tests, browser flow, audit events and data reconciliation.

## RBAC/tenant isolation

Only authorized OPS/sales actors can cancel/exchange; partner users see only assigned service scope. Direct IDs and query tampering cannot expose inventory/customer data.

## Audit/event contract

Emit request, decision, old/new serial, product, reason, actor, ERP request/result, ambiguity/reconcile, quarantine/return and completion-block events with redacted diffs.

## Migration/schema

Additive serial lineage/outbox constraints may be required. Preserve historical serial rows; use unique idempotency/document references.

## Backfill/import

Preview and backfill existing serial lineage without deleting old serials. Ambiguous records enter review, never silent auto-selection.

## DEV/UAT/PROD env

DEV/UAT use fake/shadow Mikro. PROD write stays off until REL-15 guarded activation.

## Public/internal URL and callback

Internal exchange actions are authenticated. Customer-facing status links expose no stock/cari details and follow canonical HTTPS rules.

## Secret/credential

Mikro/mail credentials remain external and disabled during implementation acceptance.

## Feature flag and safe default

Exchange ERP write and completion transition default off. Ambiguous result fails closed.

## Queue/worker/scheduler/cron

Outbox workers are bounded, idempotent and reconciliation-aware. Ambiguous writes never auto-retry without provider-safe identity.

## Build/restart/post-deploy command

Deploy schema/code with write flag off -> migrate -> backfill preview/apply -> reconcile -> restart outbox in shadow -> no-write smoke -> stage canary under separate gate.

## External provider contract

Mikro writes use operation-specific idempotency and exact document reconciliation. Sales mail is separately queued and duplicate-safe.

## Health check

Outbox age, ambiguous result count, serial-lineage consistency, quarantine state and completion blockers.

## Log/metric/alert

Alert on duplicate ERP document, missing old/new serial, unresolved ambiguity, completion with blocked ERP state or wrong root link.

## Backup/restore

Checkpoint database and outbox. Restore does not replay committed ERP writes; reconcile external truth first.

## Cutover

Enable read/shadow -> validate parity -> controlled write canary -> reconcile document/serial/amount -> enable accepted operations only.

## Rollback/disable

Disable new exchange/completion writes, preserve outbox/audit, reconcile external ERP, and roll application separately. Never erase old serial history.

## Production smoke

One authorized synthetic exchange with exact old/new serial and ERP reconciliation; no second document on replay.

## Data reconciliation

Root-to-old/new serial, installed flag, warranty/QR source, ERP document, return/quarantine and outbox terminal state must match.

## S0/S1/S2 blockers

S0: wrong serial/customer/root, duplicate ERP write. S1: lost serial history, completion despite ambiguity, non-idempotent replay. S2: display-only issue.

## Go/No-Go owner

Service operations, sales, ERP integration, data/security and release owners.

## Open decisions

Compatibility rule ownership, quarantine disposition, sales escalation SLA and exact Mikro operations/document types.

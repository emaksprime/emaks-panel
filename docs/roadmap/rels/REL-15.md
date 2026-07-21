# REL-15 - Pre-Cutover and Production Cutover

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md). REL-15 starts only after every non-AI pre-live contract is accepted.

## Business outcome

Release one immutable, fully reconciled EMAKS Panel artifact to production with proven restore, security, capacity, controlled external canaries, guarded Mikro activation, explicit Go/No-Go and actionable rollback.

## Dependencies

All non-AI ledger rows accepted, launch-critical decisions zero, environment foundation complete, named owners available and approved maintenance/communication windows.

## Included scope

Exact artifact, fresh/upgrade migrations, backup/restore rehearsal, anonymized staging, full test/build/browser/security/performance, outbound-disabled deploy, controlled queue/scheduler, real locksmith import, provider canaries, final Mikro credential/activation, ERP n8n zero, canonical final flow, alerts, Go/No-Go, public traffic and intensive monitoring.

## Excluded scope

Unaccepted feature work, AI recommendation track, implicit provider retry, automatic n8n Mikro fallback, merge without authorization and blind DB restore over external side effects.

## Source of truth

Immutable Git/artifact defines application release; PostgreSQL and each declared external system retain their domain truth; the ledger and signed Go/No-Go evidence define release readiness.

## Entry criteria

All non-AI implementation/live gates meet their declared pre-production level, S0/S1 zero, rollback/restore rehearsed, exact artifact built, change window and canary authorizations approved.

## Exit/acceptance criteria

All 22 ordered cutover steps in [Production Definition of Done](../PRODUCTION_DEFINITION_OF_DONE.md) pass; Mikro activates atomically only after all checks; ERP n8n traffic is zero; one canonical end-to-end flow reconciles; alerts remain below thresholds; public traffic is stable through intensive monitoring.

## Exact evidence requirements

Release SHA/tree/artifact hash, CI/full-suite/build, migration logs, backup/restore proof, staging/browser/security/performance reports, import/canary/Mikro/n8n reconciliation, alert snapshots, Go/No-Go signatures and post-open monitoring.

## RBAC/tenant isolation

Production role/tenant matrix and privileged operations are revalidated on exact artifact. Emergency access is time-bound, logged and reviewed.

## Audit/event contract

Record deployment, migration/backfill, setting/flag/credential changes, import, canaries, cutover/rollback, queue/system actions and Go/No-Go decisions.

## Migration/schema

Fresh install and current-production upgrade pass on exact migrations. Lock time, compatibility, backfill and rollback/forward strategy are measured.

## Backfill/import

Every backfill/import has preview hash, stable key, idempotency, row results, reconciliation and rollback/deactivation. Real locksmith import occurs before public traffic; bulk invites remain off.

## DEV/UAT/PROD env

Production values are distinct and validated without exposing secrets. Exact runtime versions and build artifact match accepted staging.

## Public/internal URL and callback

Canonical HTTPS, DNS, certificates, trusted proxy, callbacks, CORS/CSRF/session/cookies and internal origins are verified before canaries.

## Secret/credential

Secrets are entered/rotated through external management. Mikro production credential is entered last and remains inert until guarded activation passes.

## Feature flag and safe default

Outbound messaging, invitations, payment creation, Voibot and Mikro start off. Each capability has an independent kill switch and named enable owner.

## Queue/worker/scheduler/cron

Document drain/stop/migrate/start order, worker version parity, lease health, pending limits and stale recovery. No old worker processes new schema.

## Build/restart/post-deploy command

Build immutable artifact -> verify hash -> backup -> stop/drain -> deploy -> migrate/backfill -> cache/build -> restart declared processes -> health/no-send smoke -> separately open canary flags.

## External provider contract

Every canary has exact recipient/account/operation, maximum attempt, no-retry ambiguity rule, reconciliation and immediate kill switch. Provider acceptance is not delivery.

## Health check

Application/database/cache/queue/storage/mail/public URLs/callbacks/providers/Mikro/projections and scheduled jobs must meet numeric thresholds.

## Log/metric/alert

Dashboards and paging cover errors, latency, capacity, queue age, duplicates, missing events, tenant denials, provider ambiguity, payment mismatch, Mikro writes and reconciliation.

## Backup/restore

Backup verification and actual restore rehearsal complete before cutover. External side effects use provider disable/reconcile and data recovery, not blind restore.

## Cutover

Follow the exact 22-step order in the Production Definition of Done. Any failed Mikro activation check is `NO-GO`; public traffic stays closed.

## Rollback/disable

Trigger on cross-tenant leak, duplicate payment/message/Mikro write, MRN/serial collision, missing audit event or wrong customer link. Separate app rollback, feature/provider disable, data recovery and reconciliation.

## Production smoke

After bounded provider/import checks, run one new canonical customer + serial + root MRN full flow under explicit authorization and reconcile every domain source.

## Data reconciliation

Compare pre/post counts, hashes and external references for customers, MRN/SRV, serials, imports, messages, payments, Mikro documents, events, queue/outbox and projections.

## S0/S1/S2 blockers

S0 triggers immediate stop/rollback. S1 prevents Go. S2 requires documented owner/date and may proceed only with explicit Go/No-Go approval.

## Go/No-Go owner

Burhan/business owner, release commander, application owner, DBA/data owner, security/privacy, operations, finance/provider and Mikro integration owners.

## Open decisions

Production window, numeric thresholds, canary identities/amounts, rollback time objectives, monitoring duration and final named owners. All launch-critical decisions must be zero before Go.

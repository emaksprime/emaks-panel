# LIVE CONTRACT Template

Copy this structure into every new REL contract. Do not add status here; link to the canonical ledger.

## Business outcome

State the measurable user/business result.

## Dependencies

List exact prerequisite RELs, data contracts, environments, and external readiness.

## Included scope

List only accepted deliverables.

## Excluded scope

List adjacent work that this REL does not authorize.

## Source of truth

Name the authoritative system for every critical entity and state.

## Entry criteria

State exact gates required before implementation starts.

## Exit/acceptance criteria

Use measurable fail-closed outcomes; implementation alone is insufficient.

## Exact evidence requirements

Require exact SHA/tree, test commands, environment, hashes, reconciliation, cleanup and independent review.

## RBAC/tenant isolation

Define actors, permissions, scopes, tamper denials and PII-safe failures.

## Audit/event contract

Define actor, entity, root MRN/SRV, correlation, transition, redacted before/after, reason, source, time and schema version.

## Migration/schema

Define fresh-install, upgrade, rollback compatibility and constraints.

## Backfill/import

Define preview, idempotency, stable keys, error output, reconciliation and rollback/deactivation.

## DEV/UAT/PROD env

List environment-specific values, safe defaults and forbidden cross-environment behavior.

## Public/internal URL and callback

Define canonical HTTPS/public and internal origins, proxy behavior and callback verification.

## Secret/credential

Define external secret storage, rotation and activation separation.

## Feature flag and safe default

Every risky capability starts disabled and fails closed.

## Inherited Local/Live control-plane

Link to the global contract in `MASTER_REL_ROADMAP.md`; do not copy status from the ledger. List for this REL:

- Stable capability key and classification
- Sole owner and `REQUIRED`/`OPTIONAL` activation class
- Capability-specific readiness and environment-bound provider profile
- `LOCAL` behavior, including external-hit zero and truthful intent/audit
- `LIVE` behavior and the authoritative server-side guard
- Queue/retry/DLQ and stale epoch/revision behavior
- Callback receive/verify/journal/reconcile/automation behavior, if applicable
- Disable, rollback and ambiguous-result reconciliation

Unknown capabilities and new providers/endpoints default `LOCAL/OFF`. A REL cannot exit without proving global epoch, capability revision and environment/profile fingerprint checks at every applicable enqueue, claim and transport boundary.

## Queue/worker/scheduler/cron

Define processes, leases, retries, idempotency, drain/start order and stale recovery.

## Build/restart/post-deploy command

List deterministic commands and verification in exact order; do not say only "configure env".

## CI and dependency determinism

Name changed-file contract tests, shared invariant tests, any required disposable PostgreSQL/browser gate and exact-head CI. Preserve all applicable PHP versions and tests. Dependency lockfile changes require a separate reviewed stabilization scope; the final release SHA also requires the monolithic full matrix defined by the Production Definition of Done.

## External provider contract

Define request identity, timeout, retry, idempotency, accepted/sent/delivered semantics, webhook verification and reconciliation.

## Health check

Define endpoints/probes and pass criteria.

## Log/metric/alert

Define structured logs, redaction, numeric thresholds, owners and retention.

## Backup/restore

Define checkpoint, restore rehearsal and post-restore reconciliation.

## Cutover

List the exact guarded activation sequence.

## Rollback/disable

Separate application rollback, feature disable, provider stop, data recovery and reconciliation.

## Production smoke

Define the smallest separately authorized bounded live proof.

## Data reconciliation

Define exact before/after counts, invariants, source parity and orphan/duplicate checks.

## S0/S1/S2 blockers

Define severity and mandatory stop behavior.

## Go/No-Go owner

Name business, product, technical, security/data and operations acceptance roles.

## Open decisions

List unresolved decisions. Launch-critical count must be zero before Go.

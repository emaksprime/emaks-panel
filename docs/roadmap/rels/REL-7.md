# REL-7 - Root MRN and Child SRV State Machines

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Provide explicit, reachable and dead-end-free root MRN and child SRV lifecycles with clear actors, actions, preconditions, SLAs, escalation, reopen/cancel and audited override.

## Dependencies

REL-4H, REL-4G, REL-5 and REL-10A.

## Included scope

Separate root/SRV state machines, transition matrix, actor/action/precondition definitions, terminal/non-terminal rules, reopen/cancel, SLA/escalation and reason-required admin override.

## Excluded scope

QR public UI, generic collaboration/chat, repair domain internals and KPI visualization.

## Source of truth

Laravel state records and append-only transition events are authoritative. UI labels and provider/ERP status cannot directly mutate lifecycle state.

## Entry criteria

Root/child identity accepted, exchange/final-serial contract defined, actor permissions approved and event schema available.

## Exit/acceptance criteria

Every reachable non-terminal state has a valid next action or timed escalation; invalid transitions fail server-side; reopen/cancel/override preserve history; property/reachability tests find zero dead ends.

## Exact evidence requirements

Versioned diagrams/tables, exact SHA, generated reachability report, role/state/action tests, concurrent transition tests, browser action matrix and event reconciliation.

## RBAC/tenant isolation

Transitions are authorized by current actor/membership and exact entity scope. Query/body state spoofing is ignored; denial leaks no customer/service data.

## Audit/event contract

Every transition records prior/next state, actor, role/membership, reason, correlation, root/SRV, source and timestamp. Admin override has mandatory reason.

## Migration/schema

Add versioned state/transition support without rewriting historical events. Backward-compatible readers are required during deploy.

## Backfill/import

Classify existing rows with deterministic rules and preview exceptions. Ambiguous rows enter review; no silent terminal-state guess.

## DEV/UAT/PROD env

Same state definition in all environments. Scheduler clocks/time zones and SLA calendars are explicit.

## Public/internal URL and callback

Public actions map to allowed server transitions through scoped tokens; internal routes require RBAC and CSRF protection.

## Secret/credential

No domain secret. External escalation channels use separately managed provider credentials.

## Feature flag and safe default

New transition engine defaults read-only/off until backfill and parity pass; unknown state/action fails closed.

## Queue/worker/scheduler/cron

SLA/escalation jobs are idempotent, leased and time-zone deterministic. Repeated jobs create no duplicate action/notification.

## Build/restart/post-deploy command

Deploy definitions -> migrate -> dry-run classification -> reconcile -> enable shadow validation -> switch transitions -> start SLA scheduler -> monitor.

## External provider contract

Notifications follow state events but never define state. Provider failure cannot silently advance lifecycle.

## Health check

Unknown-state count, dead-end count, overdue SLA, failed transition rate and scheduler heartbeat.

## Log/metric/alert

Alert on invalid/unknown transition, dead-end, duplicate event, overdue escalation and override anomaly.

## Backup/restore

Restore preserves state/event ordering and prevents replayed commands from duplicating transitions.

## Cutover

Backfill/parity -> internal shadow -> controlled state mutation -> full browser acceptance -> production flag under REL-15.

## Rollback/disable

Disable new engine mutations, stop SLA jobs, preserve event history and reconcile in-flight states before any legacy fallback.

## Production smoke

One synthetic root with multiple child SRVs traverses permitted states; invalid/reopen/cancel/override cases are verified.

## Data reconciliation

Current state equals the latest accepted event; roots/children, terminal flags, SLA and escalation records have no orphan or contradiction.

## S0/S1/S2 blockers

S0: cross-tenant transition or lost state. S1: reachable dead end, duplicate transition, missing audit. S2: label/order usability issue.

## Go/No-Go owner

Technical Service operations, product, security, data and release owners.

## Open decisions

Final state vocabulary, SLA calendar/owners, reopen limits and admin override escalation policy.

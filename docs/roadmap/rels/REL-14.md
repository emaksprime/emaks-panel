# REL-14 - Happy Call, Survey, Scorecards and Management KPI

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Close the service quality loop with auditable Happy Call/surveys and deterministic, explainable management KPIs and scorecards that drill to source records.

## Dependencies

REL-10A, REL-11, REL-12, REL-13, REL-5 and accepted lifecycle event coverage.

## Included scope

Happy Call eligibility/delay/re-version/dedup/outcomes/escalation/owner/SLA; versioned immutable survey, manual phone survey, non-response, negative follow-up; operational KPIs, latency distributions, first response/visit/reopen/parts/no-show/document/customer/Happy Call/survey/complaint metrics, person/technician/partner/product scorecards, filters, drill-down, test exclusion, freshness, complete-data-since, sample warnings and versioned formulas.

## Excluded scope

Generic call engine (REL-11), CRM ownership (REL-13), AI-generated scores/recommendations and automatic earning deductions.

## Source of truth

REL-10A domain events and accepted domain records are metric inputs. REL-11 owns call interactions; REL-14 owns Happy Call/survey tasks, responses and formula versions.

## Entry criteria

Complete stage events, call engine, CRM drill-down, test-data classification and business formula definitions approved with hand-calculated fixture expectations.

## Exit/acceptance criteria

Happy Call duplicates are prevented/re-versioned on reopen; survey answers are immutable/versioned; negative responses escalate; every metric is deterministic, sourced and drillable; fixed fixture outputs exactly equal hand calculations; minimum sample/freshness are visible.

## Exact evidence requirements

Exact SHA, formula/version catalog, fixture inputs and hand calculations, query outputs, event completeness window, browser filters/drill-down, duplicate/reopen tests and audit reconciliation.

## RBAC/tenant isolation

Managers see only authorized teams/partners/regions. Raw responses/calls and exclusions require explicit permission; aggregates cannot leak hidden small groups.

## Audit/event contract

Emit eligibility, attempt, outcome, response, escalation, exclusion/reason, formula publication and report export events.

## Migration/schema

Version question sets/formulas and immutable answers. Store derived snapshots only with source range/version and rebuildability.

## Backfill/import

Compute eligibility/metrics only from complete-data-since; label historical incomplete periods rather than invent missing events.

## DEV/UAT/PROD env

Fixtures and test records are explicitly tagged/excluded. Time zone, calendar and percentile implementation are consistent.

## Public/internal URL and callback

Survey links, if public, are HTTPS, scoped, expiring and one-response. KPI/admin views are internal and permissioned.

## Secret/credential

Call/message/survey credentials remain external. Survey free text and PII are redacted in logs/evidence.

## Feature flag and safe default

Happy Call task generation, survey send and KPI publication default off independently.

## Queue/worker/scheduler/cron

Eligibility/delay/retry/escalation jobs are idempotent and re-version on reopen. KPI refresh is versioned, checkpointed and monitored.

## Build/restart/post-deploy command

Deploy disabled -> migrate -> load versioned definitions -> fixed-fixture acceptance -> backfill complete period -> reconcile -> enable internal KPI -> enable Happy Call/survey cohorts separately.

## External provider contract

Survey/Happy Call channel attempts use accepted interaction/message contracts. Attempt, accepted and delivered are distinct; no blind retry.

## Health check

Eligibility backlog, task duplication, delivery/response lag, escalation age, KPI freshness, source completeness and refresh failures.

## Log/metric/alert

Alert on duplicate task, missing escalation, mutable answer, formula mismatch, stale KPI, incomplete data or hidden-group exposure.

## Backup/restore

Restore preserves immutable answers/formula versions and does not resend surveys or duplicate Happy Call tasks.

## Cutover

Formula/fixture acceptance -> internal KPI read-only -> Happy Call controlled cohort -> survey cohort -> management Go/No-Go -> broader enable.

## Rollback/disable

Stop task/survey generation and KPI publication, preserve answers/audit, retain last accepted version labelled with freshness.

## Production smoke

Synthetic completion/reopen/negative survey plus fixed KPI fixture validates dedup, escalation, formula, filters and drill-down.

## Data reconciliation

Eligible/completed/attempt/response/escalation counts and every KPI aggregate reconcile to exact source events and formula version.

## S0/S1/S2 blockers

S0: private response/data leak or financial action from score. S1: wrong formula, duplicate task/send, missing escalation, stale data shown current. S2: chart/layout issue.

## Go/No-Go owner

Business operations, customer experience, analytics/data, security/privacy and release owners.

## Open decisions

CSAT/NPS definition, formula thresholds/weights, minimum sample, complete-data-since date, exclusion authority and survey channel/cadence.

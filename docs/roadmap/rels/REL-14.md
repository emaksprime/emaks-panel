# REL-14 - Happy Call, Survey, Scorecards and Management KPI

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Graduate the current Pilot Dashboard into an accepted management surface only after auditable Happy Call/surveys and deterministic, explainable operations/warranty KPIs and scorecards drill to source records.

## Dependencies

REL-10A, REL-11, REL-12, REL-13, REL-5 and accepted lifecycle event coverage.

## Included scope

Pilot Dashboard shell/graduation, Happy Call eligibility/delay/re-version/dedup/outcomes/escalation/owner/SLA; versioned immutable survey, manual phone survey, non-response, negative follow-up; operational and warranty KPIs, latency distributions, first response/visit/reopen/parts/no-show/document/customer/Happy Call/survey/complaint metrics, person/technician/partner/product scorecards, filters, raw-record drill-down, test exclusion, freshness, complete-data-since, sample warnings and versioned formulas.

## Excluded scope

Generic call engine (REL-11), CRM ownership (REL-13), Operations Center state mutation engine (REL-7), AI-generated scores/recommendations and automatic earning deductions.

## Source of truth

REL-10A domain events and accepted domain records are metric inputs. REL-11 owns call interactions; REL-14 owns Happy Call/survey tasks, responses and formula versions.

## Entry criteria

Complete stage events, call engine, CRM drill-down, test-data classification and business formula definitions approved with hand-calculated fixture expectations.

## Exit/acceptance criteria

Happy Call duplicates are prevented/re-versioned on reopen; survey answers are immutable/versioned; negative responses escalate; every operations/warranty metric is deterministic, sourced and drillable; fixed fixture outputs exactly equal hand calculations; minimum sample/freshness are visible; Pilot labelling is removed only after all graduation gates pass.

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

## Pilot Dashboard graduation contract

`/technical-service/dashboard` remains `Pilot / Geliştiriliyor` until accepted event completeness, deterministic/versioned formulas, hand-calculated fixture parity, filters, raw-record drill-down, source/freshness, complete-data-since, minimum-sample warning, test-data exclusion, role visibility, desktop/mobile browser acceptance, console error zero and misleading/dead action zero all pass. Warranty KPI uses only authoritative operational events/records from REL-4G/7/12 and sourced projection parity from REL-13. REL-14 never becomes the Operations Center mutation engine.

## Inherited Local/Live control-plane

REL-14 inherits [the global contract](../MASTER_REL_ROADMAP.md). It owns `survey.followup.plan` (`BACKGROUND_AUTOMATION`, `REQUIRED`); actual message/mail/call transport remains with its adapter owner. Readiness requires versioned eligibility, consent, dedupe, current authorization and accepted channel handoff. `LOCAL` may calculate internal eligibility but sends nothing. `LIVE` rechecks epoch/revision at planning and adapter claim. Stale retries cannot duplicate a survey. Disable stops generation/delivery, preserves answers/audit and labels retained KPI data with freshness.

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

Eligible/completed/attempt/response/escalation counts and every operations/warranty KPI aggregate reconcile to exact source events/records, exclusions, completeness window and formula version, with raw-record drill-down parity.

## S0/S1/S2 blockers

S0: private response/data leak or financial action from score. S1: wrong formula, duplicate task/send, missing escalation, stale data shown current. S2: chart/layout issue.

## Go/No-Go owner

Business operations, customer experience, analytics/data, security/privacy and release owners.

## Open decisions

Pilot graduation evidence window, CSAT/NPS definition, warranty/operations formula thresholds and weights, minimum sample, complete-data-since date, exclusion authority and survey channel/cadence.

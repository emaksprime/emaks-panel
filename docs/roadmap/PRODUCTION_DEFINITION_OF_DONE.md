# Production Definition of Done

No REL, integration, or feature is production-ready because implementation or CI alone passed. Production readiness requires every applicable gate below with exact-SHA evidence.

## Universal Gates

1. Scope, owner, source of truth, dependencies, exclusions, and open decisions are explicit.
2. Authorization, tenant isolation, PII redaction, and denial responses are tested server-side.
3. State transitions, idempotency, concurrency, retries, ambiguous outcomes, and recovery are deterministic.
4. Every critical mutation emits the accepted append-only event/audit contract.
5. Fresh-install and upgrade migrations pass on PostgreSQL; backfill is idempotent and reconciled.
6. DEV, UAT, and PROD configuration is separated. Feature flags and kill switches default safe.
7. Public/internal URLs, callbacks, proxies, CORS, CSRF, sessions, and cookies are environment-correct.
8. Secrets are externally managed, scanned, rotatable, and absent from Git/evidence/logs.
9. Queue, worker, scheduler, storage, mail, webhooks, and provider runtime have health, metrics, alerts, and bounded recovery.
10. Unit, feature, security, integration, full regression, build, and exact-head CI gates pass without hidden skips.
11. Desktop/mobile browser UAT passes with no console, accessibility, network, or overflow blocker.
12. Performance and capacity thresholds are numeric and pass on production-like data.
13. Backup and actual restore rehearsal pass before destructive migration or cutover.
14. Cutover, disable, rollback, data recovery, provider reconciliation, and communication runbooks are rehearsed.
15. Production smoke uses bounded, allowlisted, auditable operations with separate authorization.
16. Data reconciliation proves no duplicate, orphan, collision, cross-tenant leak, missing event, or hidden ambiguous result.
17. S0 and S1 blockers are zero; accepted S2 items have owners and dates.
18. Go/No-Go owners sign exact artifact, environment, evidence, and rollback readiness.

## REL-15 Execution Order

1. Freeze exact release SHA and immutable artifact.
2. Run clean PostgreSQL fresh-install migrations.
3. Run current-production upgrade migrations.
4. Create backup and complete a real restore rehearsal.
5. Prepare anonymized production-like staging.
6. Match production PHP/runtime and pass CI, full tests, lint and build.
7. Complete desktop/mobile browser UAT.
8. Pass security, performance and capacity gates.
9. Deploy with outbound messaging, payment-link creation, invitations and Mikro disabled.
10. Stop queue/scheduler in the documented controlled order.
11. Apply migration/backfill/cache/build and restart workers deterministically.
12. Run no-send internal smoke.
13. Run real locksmith dry-run/import and verify sampled isolation.
14. Keep bulk invitation disabled.
15. Run separately authorized messaging, Voibot and Iyzico canaries.
16. Enter Mikro production credentials last.
17. Run Mikro health/license/tenant/period/capability/parity/write/replay/reconcile checks and atomically activate only on all-pass.
18. Observe ERP-path n8n traffic at zero.
19. Complete one new canonical customer + serial + root MRN end-to-end flow.
20. Evaluate numeric alerts and issue Go/No-Go.
21. Open public traffic only after Go.
22. Enter intensive monitoring with named owners.

## Mandatory Rollback Triggers

Any of these is an immediate No-Go or rollback/disable trigger:

- Cross-tenant data exposure
- Duplicate payment or message
- Duplicate Mikro write
- Root MRN or serial collision
- Missing required audit event
- Wrong customer linkage

External provider or ERP side effects make blind database restore unsafe. Application rollback, data recovery, provider disable, and reconciliation are separate controlled runbooks.

## Mikro Activation

Credential save does not activate traffic. The guarded final activation must pass health, license, tenant/company, period/database, capability/permission, read parity, controlled write canary, replay/idempotency, and GUID/document/line/amount/warehouse/tax reconciliation in one decision. A failed check returns `NO-GO` and leaves all capabilities off.

## Evidence Package

Each accepted gate records exact SHA/tree/artifact, command and exit status, environment fingerprint without secrets, test counts, migration state, screenshot/log hashes where applicable, actor, timestamp, before/after reconciliation, cleanup, runtime flags, and explicit accepted/not-delivered distinctions for external providers.

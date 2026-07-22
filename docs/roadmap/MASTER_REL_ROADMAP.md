# EMAKS Panel Canonical Pre-Live REL Roadmap

This directory is the canonical execution plan for all non-AI work required before public production traffic is enabled. It is a planning and acceptance contract, not implementation authorization.

## Current Product Boundary

Snapshot verified on 2026-07-22:

- Product branch: `burhan/technical-service-b2b-integration-local`
- Remote product SHA: `29546a546bccf4575d3c9fd9c6c2587355c81aef`
- Remote product tree: `b2b140a3b40758ed519853eeb0d0db110ba2217e`
- Guarded messaging execution mode: implementation commit `63e8e9febe96dbfc64b666aa9c82adf054d36d1f`, fixture alignment `78a3a82a4d734875aec9ad85bd2bca0251b342f2`, deterministic fixture repair/current head `29546a546bccf4575d3c9fd9c6c2587355c81aef`
- Exact-SHA CI: quality and PHP 8.3/8.4/8.5 passed; the suite reported 1681 tests, 15315 assertions and 18 skipped tests
- External send delta: `0`
- Messaging execution-mode implementation remains subject to current-head disposable PostgreSQL, targeted RBAC and browser acceptance; it is not production-ready or live-verified
- Product PR: [#88](https://github.com/emaksprime/emaks-panel/pull/88), open and Draft; merge and deploy were not performed

The roadmap branch is based on `origin/main` and contains documentation only. It does not contain product commits. Product SHA, roadmap SHA, PR Ready, merge, and deploy are separate gates.

## Canonical Sources

Conflicts are resolved in this order:

1. Exact Git/code/test evidence
2. Independent exact-SHA acceptance record
3. [REL status ledger](REL_STATUS_LEDGER.md)
4. Sanitized [Issue #90](https://github.com/emaksprime/emaks-panel/issues/90) summary
5. Historical PR comments, handoff records, and chat
6. Legacy RFP references

Legacy Odoo RFP material is not scope authority by itself. Each requirement is classified in [requirements traceability](REQUIREMENTS_TRACEABILITY.md) as `ACCEPTED_PRE_LIVE`, `SUPERSEDED`, `OUT_OF_SCOPE_WITH_EVIDENCE`, or `OPEN_BUSINESS_DECISION`.

## Governance

- [REL_STATUS_LEDGER.md](REL_STATUS_LEDGER.md) is the only authoritative status source.
- Per-REL files own scope, dependency, and acceptance contracts; they do not own status.
- Implementation status and live readiness are independent axes.
- A prior accepted feature is not placed in a rebuild backlog. Only current-head acceptance, live-readiness, or proven gaps may remain.
- Every implementation REL requires explicit authorization, a dedicated small branch/PR, exact-SHA evidence, and a separate merge decision.
- PR #88 remains the product integration boundary until REL-4E.17 closes.
- A roadmap merge cannot authorize a product merge or deployment.
- Launch-critical `OPEN_BUSINESS_DECISION` items must be zero before REL-15 Go/No-Go.

## Pre-Live Boundary

All accepted non-AI product scope is pre-live. This includes Technical Service and Locksmith workflows, CRM 360, customer identity and duplicate handling, call/Voibot records, Happy Call, surveys, scorecards, management KPIs, collaboration, repair/parts, QR Passport and OTP self-service, accepted field/PWA capabilities, RBAC/audit, Iyzico readiness, real locksmith import, Mikro API cutover, monitoring, rollback, and production cutover.

Voibot call summaries and transcripts are operational records, not post-live AI recommendations. Only AI-generated management or business-development recommendations may remain post-live.

## Global Local/Live Control-Plane Contract

The messaging execution-mode vertical slice and the future global control plane are separate deliverables. Their authoritative status is recorded only in the [ledger](REL_STATUS_LEDGER.md):

| Scope | Implementation status | Live readiness | Mandatory next gate |
| --- | --- | --- | --- |
| Guarded messaging Local/Live mode | `IMPLEMENTED_CURRENT_HEAD_ACCEPTANCE_PENDING` | `CI_ACCEPTED` | Disposable PostgreSQL plus targeted RBAC/browser exact-head acceptance |
| `FOUNDATION-CONTROL-PLANE` | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | Separate small foundation branch/PR before any adapter is globally activated |

`FOUNDATION-CONTROL-PLANE` is a named cross-cutting owner track, not a fabricated REL suffix. Every historical and future REL inherits this contract:

1. **Immutable environment:** server-side environment identity is `DEV`, `UAT` or `PROD` and is never mutable from the panel or a database setting.
2. **Desired execution mode:** the operator sees `Lokal` or `Canlı`; authoritative internal transition states are `LOCAL`, `ACTIVATING`, `LIVE`, `FREEZING` and `BLOCKED`.
3. **Lokal:** external effects are frozen. Intent and audit may persist, but provider HTTP, payment mutation, ERP write, mail/send and invitation execution are denied. `PROD + Lokal` never redirects traffic to localhost or a development endpoint.
4. **Canlı in PROD:** only environment-bound approved provider profiles are eligible. Every versioned `REQUIRED` capability readiness check must pass in one atomic all-or-nothing activation. `OPTIONAL` capabilities remain off until separately accepted.
5. **Canlı in non-production:** this is not broad LIVE. External I/O requires an allowlisted target, bounded run/window, durable claim, one-time permit, maximum-attempt policy and redacted audit. A development profile cannot silently become a production profile.
6. **Future default deny:** a new capability, provider, endpoint or profile is `LOCAL/OFF` until registered, owned, classified, tested and included in an accepted readiness manifest. A new REL cannot exit without explicitly inheriting this contract.
7. **Fencing:** each intent/job/outbox record snapshots immutable environment, monotonic global epoch, capability revision, provider-profile fingerprint and any authorization/window identity. Enqueue, claim and final transport boundaries revalidate the snapshot.
8. **Stale work:** mode/profile/credential/kill-switch changes fence old jobs. Retry, replay, force-resend and DLQ release cannot turn stale work into LIVE work; explicit review creates a new intent.
9. **LIVE to LOCAL:** stop new claims, expose and reconcile in-flight/ambiguous effects, disable providers, place queues in a safe state and audit the result. Never claim an external request was undone, blindly replay it or blindly restore the database.
10. **Control security:** dedicated RBAC, no person hard-coding, server-authoritative responses and actor/reason/request-or-correlation/before/after/timestamp/revision audit are mandatory. Credentials are managed separately from mode; UI and evidence never reveal secrets.
11. **Callbacks:** inbound processing is `receive -> verify signature/timestamp/replay -> durable journal -> correlate/idempotency -> reconcile -> domain processing -> downstream automation`. LOCAL may safely journal/reconcile prior real effects but cannot start new outbound automation.
12. **Single control:** one operator action may request the global desired mode, but it cannot bypass guards. If any `REQUIRED` capability is not ready, no required capability is left partially LIVE and exact blockers remain visible in the panel.

### Capability Classification and Ownership

Classification values are `INTERNAL_ONLY`, `EXTERNAL_READ`, `EXTERNAL_MUTATION`, `OUTBOUND_COMMUNICATION`, `FINANCIAL_MUTATION`, `INBOUND_CALLBACK`, `BACKGROUND_AUTOMATION`, `BULK_APPLY_OR_INVITATION` and `PROVIDER_OR_CREDENTIAL_CONTROL`. Each stable key has one owner; dependencies do not transfer ownership.

| Capability key | Class | Sole owner track | Activation class | Minimum readiness |
| --- | --- | --- | --- | --- |
| `provider.profile.control` | `PROVIDER_OR_CREDENTIAL_CONTROL` | `FOUNDATION-CONTROL-PLANE` | `REQUIRED` | Environment binding, secret separation, RBAC/audit and atomic revision |
| `messaging.evolution.send` | `OUTBOUND_COMMUNICATION` | REL-4E | `REQUIRED` | Evo profile, queue/claim/permit, consent/allowlist and reconciliation |
| `messaging.nac.send` | `OUTBOUND_COMMUNICATION` | REL-4E | `REQUIRED` | NAC profile, queue/claim/permit, consent/allowlist and reconciliation |
| `serial.exchange.apply` | `INTERNAL_ONLY` | REL-4G | `REQUIRED` | Authoritative old/new serial, root MRN and ERP-intent reconciliation |
| `identity.root_mrn.allocate` | `INTERNAL_ONLY` | REL-4H | `REQUIRED` | Concurrency-safe uniqueness, non-reuse and restore proof |
| `bulk.support.apply` | `BULK_APPLY_OR_INVITATION` | REL-5 | `REQUIRED` | Preview hash, approval, idempotent apply and rollback/deactivate |
| `bulk.b2b.apply` | `BULK_APPLY_OR_INVITATION` | REL-5 | `REQUIRED` | Tenant/capability validation, approval and reconciliation |
| `bulk.technician_locksmith.apply` | `BULK_APPLY_OR_INVITATION` | REL-5 | `REQUIRED` | Membership/technician isolation, approval and rollback/deactivate |
| `invitation.send` | `BULK_APPLY_OR_INVITATION` | REL-5 | `OPTIONAL` | Separate approval, delivery profile, dedupe and no implicit import send |
| `maps.google.geocode` | `EXTERNAL_READ` | REL-5 | `OPTIONAL` | Approved fixed profile, rate/timeout policy and deterministic fallback-to-review |
| `otp.send` | `OUTBOUND_COMMUNICATION` | REL-6 | `REQUIRED` | One-time challenge, expiry/rate limits and accepted-versus-delivered semantics |
| `state.sla.tick` | `BACKGROUND_AUTOMATION` | REL-7 | `REQUIRED` | Versioned state/calendar, lease, idempotency and stale-revision guard |
| `mail.smtp.send` | `OUTBOUND_COMMUNICATION` | REL-8 | `OPTIONAL` | Environment-bound SMTP profile, current authorization and dedupe |
| `payment.iyzico.mutate` | `FINANCIAL_MUTATION` | REL-9 | `REQUIRED` | Merchant/profile, amount/currency/reference, idempotency and kill switch |
| `payment.iyzico.reconcile` | `EXTERNAL_READ` | REL-9 | `REQUIRED` | Authoritative provider query, correlation and ambiguity handling |
| `payment.iyzico.callback` | `INBOUND_CALLBACK` | REL-9 | `REQUIRED` | Signature/replay verification, durable journal and exact reconcile |
| `audit.event.append` | `INTERNAL_ONLY` | REL-10A | `REQUIRED` | Append-only schema, actor/correlation and redaction coverage |
| `mail.incoming.health` | `EXTERNAL_READ` | REL-11 | `OPTIONAL` | Environment-bound mailbox health probe with no message mutation |
| `voibot.call` | `OUTBOUND_COMMUNICATION` | REL-11 | `OPTIONAL` | Consent, target scope, one intent/call identity and no blind retry |
| `voibot.webhook` | `INBOUND_CALLBACK` | REL-11 | `OPTIONAL` | Signature/replay verification, journal, correlation and retention |
| `parts.movement.intent` | `INTERNAL_ONLY` | REL-12 | `REQUIRED` | Domain authorization, outbox identity and completion blocker |
| `crm.projection.refresh` | `BACKGROUND_AUTOMATION` | REL-13 | `REQUIRED` | Source checkpoint, freshness, idempotency and dead-letter visibility |
| `survey.followup.plan` | `BACKGROUND_AUTOMATION` | REL-14 | `REQUIRED` | Eligibility/version/dedupe, consent and channel handoff guard |
| `release.cutover.execute` | `INTERNAL_ONLY` | REL-15 | `REQUIRED` | Immutable artifact, all gates, named Go/No-Go and rollback readiness |
| `gateway.n8n.execute` | `EXTERNAL_MUTATION` | INT-MIKRO | `OPTIONAL` | Registered non-ERP operation only; ERP fallback prohibited |
| `erp.mikro.read` | `EXTERNAL_READ` | INT-MIKRO | `REQUIRED` | Typed operation, tenant/period binding, parity and rate policy |
| `erp.mikro.write` | `EXTERNAL_MUTATION` | INT-MIKRO | `REQUIRED` | Outbox/idempotency, ambiguity quarantine and exact reconciliation |
| `maps.google.routes` | `EXTERNAL_READ` | Accepted field track (`REL suffix UNASSIGNED`) | `OPTIONAL` | Approved fixed profile, scoped route input, timeout/rate and no hidden mutation |

### CI Performance Contract (Pre-Live, Not Implemented)

Current cache usage, `coverage: none` and cancel-in-progress behavior remain. The measured bottleneck is PHPUnit: the last exact green suite reported 1681 tests and 15315 assertions, and the slowest PHP matrix job was approximately 19 minutes wall-clock. Neither `composer.lock` nor `package-lock.json` is tracked.

1. Emit JUnit duration artifacts before changing execution topology.
2. Measure the slowest tests, classes and seeder/setup costs.
3. Build two duration-balanced shards from measured data.
4. Require every shard on PHP 8.3, 8.4 and 8.5; remove no test or PHP version.
5. Run one monolithic full PHP matrix on the final release SHA in addition to sharded PR checks.
6. Run fail-fast control-plane/security contract smokes before the full suite.
7. Add a path-aware lightweight docs-only gate without weakening product gates.
8. Create and verify `composer.lock` and `package-lock.json` in a separate dependency-stabilization PR, then use locked Composer install and `npm ci`.
9. Do not enable ParaTest until isolation and global-state safety are proven.
10. Record CI timing and shard balance as release evidence; this roadmap change does not modify workflows.

## Dependency-Ordered Execution

Numeric REL order does not override this dependency order.

| Order | Track | Required result before advancing |
| ---: | --- | --- |
| 0 | [REL-4E / PR #88 closure](rels/REL-4E.md) | Preserve green exact-SHA CI at `29546a5...`; complete disposable PostgreSQL plus targeted RBAC/browser acceptance for guarded messaging, then separately authorize any controlled provider canary and decide PR Ready/merge. |
| 1 | `FOUNDATION-CONTROL-PLANE` plus environment/runtime foundation | Implement the global inherited Local/Live contract, DEV/UAT/PROD isolation, capability registry, atomic readiness, epoch/revision fencing, callback journal/reconcile, public HTTPS, egress guards, health, kill switches, observability, deterministic dependencies, backup/restore and rollback. |
| 2 | [REL-4H](rels/REL-4H.md) | Immutable non-resetting root MRN separated from child SRV identity, collision-free migration/backfill, orphan/duplicate zero. |
| 3 | [REL-5 and REL-5C](rels/REL-5.md) | Full RBAC, PII controls, customer identity, reversible duplicate management, partner/technician isolation, and final locksmith onboarding/import acceptance. |
| 4 | [REL-10A](rels/REL-10.md) | Append-only actor/entity/correlation/state audit events usable by every later module and KPI. |
| 5 | Locksmith onboarding/import | Finalize the existing CSV/XLSX importer with preview hash, row errors, idempotency, controlled apply, audit, and rollback/deactivation; do not rebuild it. |
| 6 | [INT-MIKRO preparation](integrations/INT-MIKRO.md) | Typed gateway, operation catalog, projection, outbox, idempotency, reconcile, DLQ, cockpit, flags and parity while credentials and traffic remain off. |
| 7 | [REL-4G](rels/REL-4G.md) -> [REL-7](rels/REL-7.md) -> [REL-6](rels/REL-6.md) | Correct cancellation/exchange/final-serial behavior, dead-end-free MRN/SRV state machines, then secure QR Passport/OTP/self-service. |
| 8 | Accepted field operations | Dynamic checklist, offline sync, GPS, OTP/signature fallback, agenda, accessory checklist and conflict handling. Canonical REL suffix remains unassigned until evidence-backed governance assigns it. |
| 9 | [REL-9](rels/REL-9.md) | Final settlement/payment/cari audit and controlled Iyzico production readiness; existing payment flows are accepted/gap-audited, not rebuilt. |
| 10 | [REL-8A-E](rels/REL-8.md) | Ownership, comments, mentions, notifications, chat, visibility, reassignment revocation, timeline and cross-partner isolation. |
| 11 | [REL-11](rels/REL-11.md) | Generic call/interaction/follow-up engine and real Voibot webhook acceptance. |
| 12 | [REL-12](rels/REL-12.md) and [REL-10B](rels/REL-10.md) | Repair/fault/parts lifecycle plus shared searchable, redacted audit UI. |
| 13 | [REL-13](rels/REL-13.md) | Source-labelled CRM 360 projection and immutable unified timeline with drill-down. |
| 14 | [REL-14](rels/REL-14.md) | Happy Call, versioned survey, deterministic scorecards and management KPI acceptance against a hand-calculated fixture. |
| 15 | [REL-15](rels/REL-15.md) | Fresh-install and upgrade staging, restore rehearsal, full UAT/security/performance, controlled provider canaries, final Mikro activation, Go/No-Go, public traffic and monitored cutover. |

## Existing Capability Rule

The following capability families have implementation and/or acceptance evidence and must be treated as acceptance/live-readiness work unless a new exact defect is proven:

- Clipboard/copy feedback and payment-link open/copy/send actions
- Iyzico sandbox payment and trusted reconciliation, duplicate-payment protection, and post-payment notifications
- Part request and OPS decision flows
- Before/after/warranty uploads
- Canonical technician job card and `/pj/{job}` authorization
- Partner/technician isolation and reassignment revocation
- Core earning and appointment flows
- Manual E2E guard infrastructure
- Compact Admin Users editor, effective permissions, filters, memberships, and technician mapping
- Existing locksmith CSV/XLSX preview/apply infrastructure

Their exact status and next gate are recorded only in the [ledger](REL_STATUS_LEDGER.md).

## Mikro Final Activation Rule

Production Mikro credentials are entered last. Credential persistence alone must not enable traffic. A single guarded activation checks health, license, tenant/company, period/database, capabilities, read parity, controlled write canary, replay/idempotency, and GUID/document/line/amount/warehouse/tax reconciliation. Any failed check is `NO-GO` and public launch remains blocked.

After cutover, critical ERP operations go directly from Laravel to Mikro API. ERP-path n8n flows are disabled and monitored at zero. Automatic n8n fallback for Mikro writes is prohibited; n8n may remain only for independent non-ERP automation.

## Roadmap Files

- [REL status ledger](REL_STATUS_LEDGER.md)
- [Requirements traceability](REQUIREMENTS_TRACEABILITY.md)
- [Production definition of done](PRODUCTION_DEFINITION_OF_DONE.md)
- [Live contract template](LIVE_CONTRACT_TEMPLATE.md)
- [Mikro integration contract](integrations/INT-MIKRO.md)
- Per-REL contracts under [`rels/`](rels/)

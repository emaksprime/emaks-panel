# EMAKS Panel Canonical Pre-Live REL Roadmap

This directory is the canonical execution plan for all non-AI work required before public production traffic is enabled. It is a planning and acceptance contract, not implementation authorization.

## Current Product Boundary

Snapshot verified on 2026-07-21:

- Product branch: `burhan/technical-service-b2b-integration-local`
- Remote product SHA: `98fb1937fd2dc302870c992bf864108bc7acba7d`
- Remote product tree: `e15a6c2323c9d74bf958241d4c93b026f3136efe`
- One-time Manual E2E lifecycle: no-send accepted at the current head; exact-SHA quality and PHP 8.3/8.4/8.5 CI are green
- PostgreSQL lifecycle evidence: accepted through `18ee8ce8ee92b3052d4156de115ee2c4a8d2db77`; the current head changes only the appointment planner and six tests, leaving lifecycle-critical blobs unchanged
- Product PR: [#88](https://github.com/emaksprime/emaks-panel/pull/88), open and Draft

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

## Dependency-Ordered Execution

Numeric REL order does not override this dependency order.

| Order | Track | Required result before advancing |
| ---: | --- | --- |
| 0 | [REL-4E / PR #88 closure](rels/REL-4E.md) | Preserve the accepted PostgreSQL lifecycle guarantees and green exact-SHA CI at `98fb1937...`; separately authorize and reconcile one controlled assignment-offer WhatsApp + SMS pair, complete current-head browser, clean-migration and staging acceptance, then decide PR Ready/merge separately. |
| 1 | Environment and runtime foundation | DEV/UAT/PROD isolation, public HTTPS, egress guards, trusted proxy/session policy, queue/storage/mail health, kill switches, observability, backup/restore, deterministic deploy and rollback. |
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

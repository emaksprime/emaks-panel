# Canonical REL Status Ledger

This file is the single status source for the EMAKS Panel pre-live roadmap. Per-REL contracts define scope and acceptance but must not copy or override these status decisions.

Last reconciled: 2026-07-21

## Status Model

Implementation status is exactly one of:

- `COMPLETED_REAL_E2E`
- `COMPLETED_NO_SEND_ACCEPTED`
- `IMPLEMENTED_CURRENT_HEAD_ACCEPTANCE_PENDING`
- `KNOWN_FAILURE_OR_BLOCKER`
- `NOT_IMPLEMENTED`

Live readiness is independently exactly one of:

- `LOCAL_ONLY`
- `CI_ACCEPTED`
- `STAGING_ACCEPTED`
- `PRODUCTION_READY`
- `LIVE_VERIFIED`
- `BLOCKED`
- `NOT_ASSESSED`

## Product Reference

| Reference | Exact SHA | State |
| --- | --- | --- |
| Remote PR #88 product head | `98fb1937fd2dc302870c992bf864108bc7acba7d` | One-time lifecycle is no-send accepted; exact-SHA quality and PHP 8.3/8.4/8.5 CI are green; PR remains Draft. |
| PostgreSQL lifecycle core | `18ee8ce8ee92b3052d4156de115ee2c4a8d2db77` | PG-1 through PG-5 were rerun after the outer-transaction fix; PG-6 was inherited by exact unchanged guard blobs. The current product head preserves the lifecycle-critical blobs. |
| Roadmap base | `111c7d8a825631be5547650c79f46261be61f7a9` | `origin/main` at roadmap branch creation. |

## Ledger

| REL / capability | Implementation status | Live readiness | Evidence SHA | Evidence date | Evidence type | Last verified environment | Next exact gate | Blocker | Owner / acceptance owner |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| REL-4E / consolidated workflow baseline | `COMPLETED_NO_SEND_ACCEPTED` | `CI_ACCEPTED` | `3881880b41bda9ecb6c6f305886cac8b89e3e18e` | 2026-07-17 | Independent browser/security/full-suite acceptance; later included in green remote head | Local browser + isolated tests + remote CI descendant | Current-head browser/staging acceptance after the controlled assignment-offer gate | PR #88 remains Draft | Product: Burhan; acceptance: independent verifier |
| REL-4E / technician link, templates and assignment-offer preparation | `COMPLETED_NO_SEND_ACCEPTED` | `CI_ACCEPTED` | `db42da222731674fcd75b25ef1b44021b2db8a38` | 2026-07-19 | Independent exact-commit no-send/browser acceptance | Local browser + isolated tests + remote CI descendant | Controlled real assignment-offer acceptance under separate authorization | Public HTTPS, provider readiness and explicit send authorization pending | Product: Burhan; acceptance: independent verifier |
| REL-4E / one-time Manual E2E send lifecycle | `COMPLETED_NO_SEND_ACCEPTED` | `CI_ACCEPTED` | `98fb1937fd2dc302870c992bf864108bc7acba7d` | 2026-07-21 | Independent disposable-PostgreSQL acceptance: PG-1 through PG-5 rerun after the outer-transaction fix, PG-6 inherited by exact unchanged guard blobs; current-head planner delta accepted by targeted no-send tests and green exact-SHA CI | Disposable PostgreSQL 16.14 + local isolated tests + GitHub CI | Separately authorized controlled assignment-offer WhatsApp + SMS | No real provider send performed; public HTTPS/provider acceptance remains pending | Product: Burhan; acceptance: independent verifier |
| REL-4E / controlled assignment-offer WhatsApp + SMS | `IMPLEMENTED_CURRENT_HEAD_ACCEPTANCE_PENDING` | `BLOCKED` | `98fb1937fd2dc302870c992bf864108bc7acba7d` | 2026-07-21 | Current-head planner/guard targeted no-send acceptance and four green exact-SHA CI checks; no external send | Local isolated tests + GitHub CI; runtime frozen | One separately authorized allowlisted assignment-offer WhatsApp + SMS pair with exact reconciliation | Explicit send authorization, provider readiness and reachable public HTTPS required | Product: Burhan; acceptance: provider + independent verifier |
| REL-4E / copy, upload, job-card scope, reassignment and basic earning/appointment | `COMPLETED_NO_SEND_ACCEPTED` | `CI_ACCEPTED` | `3881880b41bda9ecb6c6f305886cac8b89e3e18e` | 2026-07-17 | 26-scenario independent browser/security acceptance and full TechnicalService evidence | Local browser + isolated tests + remote CI descendant | Current-head browser/staging regression before PR #88 closure | Production environment not accepted | Product: Burhan; acceptance: independent verifier |
| REL-5 / compact Admin Users editor | `COMPLETED_NO_SEND_ACCEPTED` | `CI_ACCEPTED` | `ae66af51e76f5771142f0c05d85dfac1404e1d6f` | 2026-07-20 | Independent responsive browser acceptance | Local browser + remote CI descendant | Current-head staging regression | Not production accepted | Product: Burhan; acceptance: independent verifier |
| REL-5 / user status, partner memberships, filters and technician mapping | `COMPLETED_NO_SEND_ACCEPTED` | `CI_ACCEPTED` | `8333543b757d41ff8968ffb45009cffae367ee60` | 2026-07-20 | Exact-head fixture/browser evidence reconciliation | Local browser + local DB fixture + remote CI descendant | Staging authorization/tenant regression | Full live RBAC matrix remains open | Product: Burhan; acceptance: independent verifier |
| REL-5 / superadmin management boundary | `COMPLETED_NO_SEND_ACCEPTED` | `CI_ACCEPTED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Source/test security acceptance plus four exact-SHA CI checks | Isolated tests + GitHub CI | Preserve in full RBAC acceptance | Broader non-super explicit privilege delegation remains open | Product: Burhan; acceptance: security reviewer |
| REL-5 / full RBAC, PII and delegated privilege envelope | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Approve exact permission matrix and denial tests | Adjacent privilege-delegation finding open | Product: Burhan; acceptance: security owner |
| REL-5C / customer identity and duplicate management | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Approve identity/merge/split data contract | Business identity rules and migration not accepted | Product: Burhan; acceptance: data owner |
| REL-5 / locksmith CSV/XLSX importer foundation | `IMPLEMENTED_CURRENT_HEAD_ACCEPTANCE_PENDING` | `LOCAL_ONLY` | `e532b0412ac6aabb1e7e7c1cdb67a79474178216` | 2026-06-18 | Historical implementation commits and focused tests | Local isolated tests | Current-head dry-run/idempotency/security acceptance, then controlled staging import | Real sanitized source and rollback acceptance pending | Product: Burhan; acceptance: operations/data owner |
| REL-9 / payment link, Iyzico sandbox and trusted reconcile | `COMPLETED_REAL_E2E` | `LOCAL_ONLY` | `fdabd0175dd29b3d7382b0a909b39e4fe10d916c` | 2026-07-01 | Historical controlled sandbox provider acceptance and reconciliation tests | Local sandbox | Current-head staging callback/replay/reconcile acceptance | Production merchant/callback canary not accepted | Product: Burhan; acceptance: finance owner |
| REL-9 / settlement, earning and payment foundation | `COMPLETED_NO_SEND_ACCEPTED` | `CI_ACCEPTED` | `3881880b41bda9ecb6c6f305886cac8b89e3e18e` | 2026-07-17 | Exact-head full-suite and browser scope evidence | Local browser + tests + remote CI descendant | Final parent/child/refund/cari audit | Production Iyzico and Mikro gates pending | Product: Burhan; acceptance: finance owner |
| REL-4H / immutable root MRN | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Approve schema/backfill/concurrency contract | Root MRN invariant not implemented | Product: Burhan; acceptance: data owner |
| REL-10A / event backbone | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Approve append-only event schema before downstream modules | Shared immutable event contract absent | Product: Burhan; acceptance: architecture owner |
| INT-MIKRO / preparation | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Complete operation inventory and `unmapped=0` | Typed direct API/outbox/reconcile foundation absent | Product: Burhan; acceptance: integration owner |
| REL-4G / cancel, compatibility, exchange, final serial | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Approve authoritative serial and ERP-write contract | Depends on MRN, audit and Mikro prep | Product: Burhan; acceptance: operations owner |
| REL-7 / MRN and SRV state machines | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Prove reachable-state matrix and no dead ends | Depends on REL-4G contracts | Product: Burhan; acceptance: operations owner |
| REL-6 / QR Passport, OTP and self-service | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Approve secure serial/customer/public contract | Depends on final serial and state machines | Product: Burhan; acceptance: security/product owner |
| Field operations / canonical suffix unassigned | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Accepted scope inventory | Planning | Assign evidence-backed REL owner and acceptance contract | Canonical suffix is deliberately unassigned | Product: Burhan; acceptance: field operations owner |
| REL-8A-E / collaboration | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Approve event/visibility model after REL-5/4H/10A | Shared actor/event foundation pending | Product: Burhan; acceptance: product/security owner |
| REL-11 / interaction, call and Voibot | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Approve generic interaction model and webhook contract | Real Voibot acceptance absent | Product: Burhan; acceptance: CRM/operations owner |
| REL-12 / repair, fault and parts lifecycle | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Approve inventory/outbox/completion-block contract | Depends on audit and Mikro prep | Product: Burhan; acceptance: service/ERP owner |
| REL-10B / common audit UI | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Accept search/redaction/export/provenance contract | Depends on REL-10A events | Product: Burhan; acceptance: security/operations owner |
| REL-13 / CRM 360 | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Accept source-labelled projection and drill-down | Depends on identity, events, calls and repair data | Product: Burhan; acceptance: CRM/data owner |
| REL-14 / Happy Call, survey, scorecards and KPI | `NOT_IMPLEMENTED` | `NOT_ASSESSED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gap inventory | Source review | Validate deterministic formulas against hand-calculated fixture | Depends on complete event/interaction data | Product: Burhan; acceptance: business analytics owner |
| REL-15 / pre-cutover and production cutover | `NOT_IMPLEMENTED` | `BLOCKED` | `3ab04283114169c29f9c9faa055326b982845149` | 2026-07-20 | Gate inventory | Not run | All non-AI rows accepted and launch decisions zero | Upstream RELs, staging, credentials and canaries incomplete | Product: Burhan; acceptance: Go/No-Go owners |

## Non-Authoritative Derived Views

`REQUIREMENTS_TRACEABILITY.md` repeats status values only as a point-in-time derived view required for traceability. If it differs from this file, this ledger wins and the derived view must be corrected.

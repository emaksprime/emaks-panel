# REL-6 - QR Product Passport, OTP and Customer Self-Service

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Let an authorized customer securely inspect and act on the correct installed product/service through QR Passport, real one-time OTP and self-service SRV flows.

## Dependencies

REL-4H, REL-4G, REL-7, REL-5C, environment public HTTPS and REL-10A.

## Included scope

QR Product Passport, final-serial resolution, customer/SRV authorization, OTP issue/verify, expiry/rate/attempt/one-use controls, self-service request actions, changed/cancelled/old serial behavior and enumeration protection.

## Excluded scope

ERP serial ownership changes, general CRM 360, field technician PWA and payment-provider implementation.

## Source of truth

Laravel owns customer authorization, root MRN/SRV and service/warranty projection; Mikro owns ERP serial movement; the REL-4G installed final serial selects the passport.

## Entry criteria

Final serial and state machines accepted, customer identity unambiguous, public HTTPS and rate-limiting infrastructure ready, OTP provider contract approved.

## Exit/acceptance criteria

Only the authorized customer can access the current serial/SRV; old/exchanged/cancelled serials behave explicitly; OTP is expiring, bounded and single-use; enumeration and replay fail PII-safe; payment/mount/warranty data comes from authoritative sources.

## Exact evidence requirements

Exact SHA, token/OTP threat model, rate/replay tests, multi-serial exchange matrix, public mobile browser evidence, no-PII denials, provider fake/network blocker and cleanup.

## RBAC/tenant isolation

Public access uses opaque, scoped proof rather than sequential IDs. Internal admin/partner views retain server-side tenant authorization.

## Audit/event contract

Emit QR resolution, OTP issue/verify/failure/rate limit, public action, serial mismatch and self-service state transition without storing OTP or excess PII.

## Migration/schema

Store hashed one-time challenges, expiry, attempts and consumed state with unique constraints. Fresh/upgrade migrations and retention cleanup must pass.

## Backfill/import

Backfill passport eligibility only for unambiguous installed final serials; unresolved assets remain blocked for review.

## DEV/UAT/PROD env

Fake OTP in isolated tests only. UAT/PROD use separate sender/config, HTTPS origins and rate-limit namespaces.

## Public/internal URL and callback

All public routes are canonical HTTPS behind trusted proxy rules. Callback/action tokens are scoped, expiring and non-enumerable.

## Secret/credential

OTP/provider secrets are external, rotatable and absent from client responses/logs.

## Feature flag and safe default

Public passport, OTP issue and self-service mutation default off independently.

## Inherited Local/Live control-plane

REL-6 inherits [the global contract](../MASTER_REL_ROADMAP.md). It owns `otp.send` (`OUTBOUND_COMMUNICATION`, `REQUIRED`). Readiness requires an environment-bound sender, canonical HTTPS, one-time challenge, expiry/rate/attempt limits and delivery reconciliation. `LOCAL` may create no externally delivered OTP and reports suppression truthfully. `LIVE` rechecks epoch/revision/profile at issue, claim and transport. Stale/replayed challenges are denied. Disable revokes outstanding challenges and stops delivery while preserving service/audit state.

## Queue/worker/scheduler/cron

OTP delivery is idempotent/bounded; expiry cleanup never resurrects consumed tokens. Self-service events use accepted queue safeguards.

## Build/restart/post-deploy command

Deploy disabled -> migrate -> verify public origin/proxy -> run no-send public smoke -> enable read-only passport -> authorized OTP canary -> enable accepted actions.

## External provider contract

OTP provider response distinguishes accepted from delivered. Retry and duplicate issuance are bounded by challenge identity.

## Health check

Public route, token store, rate limiter, final-serial resolver, OTP provider readiness and queue health.

## Log/metric/alert

Track issue/verify success, replay, rate limits, enumeration attempts, provider failures, unresolved serial and self-service errors with PII redaction.

## Backup/restore

Restore must not make expired/consumed OTP reusable or regress final-serial mapping.

## Cutover

Read-only passport -> OTP canary -> self-service actions, each behind separate Go/No-Go and monitoring.

## Rollback/disable

Disable public mutations/OTP, preserve audit and service requests, revoke outstanding challenges and keep internal operations available.

## Production smoke

One synthetic authorized current serial plus negative old/wrong/consumed/rate-limited cases; no real PII in evidence.

## Data reconciliation

Passport serial, customer, root MRN, SRV, installation, payment, warranty and activation sources must agree.

## S0/S1/S2 blockers

S0: unauthorized PII/service access or wrong serial. S1: OTP replay/brute-force, stale serial, source mismatch. S2: non-security presentation issue.

## Go/No-Go owner

Product, security/privacy, Technical Service, customer operations and release owners.

## Open decisions

OTP channel/provider, challenge lifetime/limits, self-service action set and customer consent/retention text.

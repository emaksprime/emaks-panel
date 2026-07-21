# REL-11 - Interaction, Calls, Follow-Up and Voibot

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Capture every customer interaction and call as an idempotent, searchable, consent-aware operational record linked to the right customer/root MRN/SRV with clear follow-up ownership and SLA.

## Dependencies

REL-5C, REL-10A, environment webhook/storage readiness and approved retention/consent policy.

## Included scope

Incoming/outgoing calls, external call ID, phone candidates/unresolved queue, customer/MRN/SRV link/unlink, agent/times/duration/disposition, retry/follow-up owner/due/SLA/escalation, recording/transcript/summary access, unified WhatsApp/SMS/email/call interaction, OPS/CRM timeline, monthly archive and Voibot webhook signature/replay/retry acceptance.

## Excluded scope

Happy Call/survey rules (REL-14), CRM 360 presentation ownership (REL-13), AI-generated recommendations and telephony provider replacement.

## Source of truth

Voibot is source for call event/media; Laravel interaction domain owns linkage, disposition, follow-up and unified operational projection; customer identity comes from REL-5C.

## Entry criteria

Identity matching, event schema, consent/retention, webhook authentication, storage and unresolved-caller workflow are approved.

## Exit/acceptance criteria

External call events are idempotent; ambiguity enters unresolved review; authorized link/unlink is audited; recordings/transcripts are protected; follow-up SLA works; real signed webhook/API acceptance passes before production-ready.

## Exact evidence requirements

Exact SHA, signed webhook fixtures and one controlled real acceptance, replay/idempotency results, access/redaction tests, storage/retention proof, browser timeline/search and cleanup.

## RBAC/tenant isolation

Call media/transcripts and PII require explicit permissions and entity scope. Candidate matching never auto-links ambiguous callers. Denials reveal no recording/customer details.

## Audit/event contract

Emit ingest, match candidate, link/unlink, disposition, follow-up, access/reveal/export, retention/delete and provider retry events.

## Migration/schema

Use unique external call ID/provider scope, normalized participant references, media metadata, consent/retention and follow-up indexes.

## Backfill/import

Import historical calls by stable provider ID, preview duplicates/ambiguous customers and keep unresolved rather than guessing.

## DEV/UAT/PROD env

Separate webhook secrets, callback origins, storage buckets and retention. Fake media/transcripts only in tests/evidence.

## Public/internal URL and callback

Voibot callback is canonical HTTPS with signature/timestamp/replay defense. Media links are short-lived, authorized and non-public.

## Secret/credential

Webhook/API/storage credentials are external and rotatable; signatures/tokens never enter logs/evidence.

## Feature flag and safe default

Webhook ingest, outbound calls and media access default off independently. Unverified events fail closed/quarantine.

## Queue/worker/scheduler/cron

Ingest/media/follow-up jobs are idempotent, leased and bounded. Provider retries cannot duplicate calls or follow-ups.

## Build/restart/post-deploy command

Deploy disabled -> migrate -> webhook signature tests -> archive/storage smoke -> internal fake ingest -> authorized real webhook canary -> enable follow-up scheduler.

## External provider contract

Voibot request IDs and signatures are authoritative. Retries map to one interaction. Provider summary/transcript is labelled by source and not treated as AI recommendation scope.

## Health check

Webhook reachability/signature, ingest lag, unresolved queue, media access/storage, follow-up scheduler and archive health.

## Log/metric/alert

Alert on invalid signature/replay, duplicate call, ingest lag, inaccessible media, retention failure or overdue follow-up.

## Backup/restore

Restore preserves external IDs/linkage and does not re-ingest/replay provider actions; media restore follows retention policy.

## Cutover

Internal fake ingest -> signed UAT -> one controlled real webhook -> reconcile -> enable accepted direction/features -> monitor.

## Rollback/disable

Disable webhook/outbound/follow-up separately, preserve interactions/audit, quarantine late callbacks and reconcile provider state.

## Production smoke

One authorized synthetic inbound/outbound case, replay, unresolved caller and media-denial proof; real provider canary is separately authorized.

## Data reconciliation

Provider call ID, interaction, customer/MRN/SRV links, agent/time/duration, disposition, media and follow-up must match.

## S0/S1/S2 blockers

S0: media/PII or cross-tenant leak, wrong customer link. S1: duplicate/lost call, unsigned/replayed webhook accepted, retention failure. S2: search/presentation issue.

## Go/No-Go owner

CRM/operations, security/privacy, Voibot integration, data and release owners.

## Open decisions

Consent text, retention periods, unresolved-caller SLA, archive tier and outbound-call authorization.

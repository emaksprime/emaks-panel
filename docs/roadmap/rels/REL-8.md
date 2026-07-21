# REL-8 - Collaboration A-E

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Enable secure, auditable collaboration around root MRN/SRV work without competing histories, duplicate notifications or access surviving reassignment.

## Dependencies

REL-5, REL-4H and REL-10A. REL-8 is not a prerequisite for REL-4G, REL-7 or REL-6.

## Included scope

REL-8A ownership, 8B comments/mentions, 8C notification center/read state, 8D chat, and 8E thread/resolution/visibility/hardening; internal/partner visibility, timeline and reassignment revocation.

## Excluded scope

Generic call/interaction records (REL-11), Happy Call/survey (REL-14), CRM 360 projection (REL-13) and external consumer messaging.

## Source of truth

Laravel collaboration records plus REL-10A events are authoritative. Notification delivery state does not replace domain collaboration state.

## Entry criteria

Actor/membership identity and root/SRV IDs accepted, visibility policy approved, event schema active and notification channel policy defined.

## Exit/acceptance criteria

Ownership/comments/mentions/threads/chat resolve to exact entity scope; reassigned users lose old access immediately; no cross-partner leakage; notifications are idempotent; internal/partner visibility is enforced server-side.

## Exact evidence requirements

Exact SHA, actor/visibility matrix, direct API tamper tests, reassignment concurrency tests, duplicate-notification proof, desktop/mobile browser evidence and event reconciliation.

## RBAC/tenant isolation

Every read/write checks current entity scope and visibility. Mention suggestions reveal only authorized actors. Old assignment/member access is revoked on fresh read.

## Audit/event contract

Emit owner, comment, mention, thread, resolution, read state, chat and visibility transitions with actor/entity/correlation and redacted content metadata.

## Migration/schema

Use additive entity references, visibility enums, idempotency keys and indexes. Preserve immutable event chronology.

## Backfill/import

If historical comments exist, import with stable source IDs and visibility review; ambiguous ownership remains internal/reviewed.

## DEV/UAT/PROD env

External notification delivery is fake/off in tests. Environment-specific queues and origins cannot cross tenants.

## Public/internal URL and callback

Internal panel links are authenticated and entity-scoped. No public chat/comment URL unless separately contracted.

## Secret/credential

Notification credentials stay external. Message body and PII are redacted in logs/evidence.

## Feature flag and safe default

Each A-E phase has an independent off-by-default flag; visibility defaults internal/deny.

## Queue/worker/scheduler/cron

Notification jobs use event/idempotency keys, current authorization recheck and duplicate suppression. Stale jobs cannot notify removed actors.

## Build/restart/post-deploy command

Deploy phase disabled -> migrate -> seed no permissions silently -> run scope/event smoke -> enable internal cohort -> start bounded notification worker -> monitor.

## External provider contract

External delivery is optional and never defines comment/read/thread state. Accepted/sent/delivered are separate and duplicate-safe.

## Health check

Unread counts, queue age, duplicate notification rate, unresolved thread count and visibility-denial metrics.

## Log/metric/alert

Alert on cross-scope read, stale-recipient send, duplicate notification, missing event or queue backlog.

## Backup/restore

Restore preserves chronology, ownership and read state without replaying notifications.

## Cutover

Enable A -> B -> C -> D -> E only after each exact acceptance; never enable all phases from one broad flag.

## Rollback/disable

Disable the affected phase and notification workers while preserving collaboration history/events. Revoke stale sessions if visibility changed.

## Production smoke

Synthetic internal and partner actors prove create/read/mention/reassign/revoke and duplicate notification behavior.

## Data reconciliation

Entity ownership, participant visibility, comment/thread counts, unread state, event count and notification idempotency must agree.

## S0/S1/S2 blockers

S0: cross-partner content leak. S1: stale reassignment access, duplicate notification, lost audit. S2: non-security UX issue.

## Go/No-Go owner

Product, security, partner operations, internal operations and release owners.

## Open decisions

Content retention, edit/delete policy, notification channels, mention eligibility and chat moderation/escalation.

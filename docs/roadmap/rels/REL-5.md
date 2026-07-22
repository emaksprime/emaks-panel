# REL-5 - RBAC, Customer Identity and Partner Onboarding

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md). REL-5C is the customer-identity phase inside this contract.

## Business outcome

Ensure every user, partner, technician, customer and PII operation is authorized, tenant-isolated, auditable and based on explicit identity rather than role explosion or phone-only matching.

## Dependencies

REL-4H, REL-10A design, environment foundation, existing Admin Users/membership work, and INT-MIKRO preparation for cari validation.

## Included scope

Full RBAC matrix, authoritative superadmin boundary, delegated permission envelope, partner/technician isolation, Admin Review queue, masked cari/reveal/copy/export permissions, customer contacts/addresses, multiple Mikro accounts, duplicate review, reversible merge/split, snapshot retention, PII retention, and locksmith importer final acceptance.

## Excluded scope

Composite roles, silent role escalation, phone-as-global-identity, CRM 360 presentation (REL-13), and production Mikro activation.

## Source of truth

`User.aktif` is global login state; `User.role_code` is one base permission template; partner profiles/access/capabilities define entity scope; partner-technician links and explicit membership metadata define technician identity. Laravel owns customer identity; Mikro account IDs are linked ERP references.

## Entry criteria

Current accepted Admin Users and superadmin changes preserved, exact permission catalog inventoried, actor scopes defined, identity/merge rules approved, and import dependencies mapped.

## Exit/acceptance criteria

Direct HTTP permission/tenant matrix passes; no role/composite-role shortcut; non-super delegation cannot exceed actor envelope; inactive global/member states deny correctly; merge/split is reversible; hidden partner/customer data does not leak; importer rerun is idempotent and does not silently grant access.

## Exact evidence requirements

Exact SHA, permission matrix, query/response leak tests, multi-membership browser proof, random-code superadmin flag tests, merge/split snapshots, import preview/apply hashes, row-level results and cleanup.

## RBAC/tenant isolation

Server-side authorization precedes lookup/mutation. Reveal, copy and export are separate permissions. Denials are generic and PII-free. A user sees only active manageable memberships/scopes; frontend filters are never authorization.

## Audit/event contract

Audit global status, role/access changes, membership/scope/mapping changes, reveal/copy/export, customer link/merge/split and import batch/row decisions with redacted before/after.

## Migration/schema

Prefer existing membership/capability models. Customer identity and reversible merge may require additive schema; fresh/upgrade migration and rollback compatibility are mandatory.

## Backfill/import

Preview/dry-run with stable key and hash; normalize contacts; identify duplicate candidates; validate partner/capability/region/geocode/cari/fee policy; controlled audited apply; idempotent rerun; batch rollback/deactivate. Invitations stay separate/off.

## DEV/UAT/PROD env

Fixtures use synthetic identities. Production PII never enters test evidence. Import source is stored outside Git with restricted access.

## Public/internal URL and callback

Admin and partner APIs remain authenticated/CSRF-protected. Public customer identity endpoints, if added, use opaque tokens and enumeration-safe responses.

## Secret/credential

Passwords, import credentials and Mikro secrets stay external. Password hashes and reset tokens are never returned or logged.

## Feature flag and safe default

New customer merge, reveal/export and import apply default disabled until their acceptance gates pass.

## Inherited Local/Live control-plane

REL-5 inherits [the global contract](../MASTER_REL_ROADMAP.md). It owns:

| Capability | Class | Activation | Readiness |
| --- | --- | --- | --- |
| `bulk.support.apply` | `BULK_APPLY_OR_INVITATION` | `REQUIRED` | Preview hash, approval, idempotent apply and rollback/deactivate |
| `bulk.b2b.apply` | `BULK_APPLY_OR_INVITATION` | `REQUIRED` | Tenant/capability validation, approval and reconciliation |
| `bulk.technician_locksmith.apply` | `BULK_APPLY_OR_INVITATION` | `REQUIRED` | Membership/technician isolation, approval and rollback/deactivate |
| `invitation.send` | `BULK_APPLY_OR_INVITATION` | `OPTIONAL` | Separate recipient approval, delivery profile and dedupe |
| `maps.google.geocode` | `EXTERNAL_READ` | `OPTIONAL` | Fixed environment profile, rate/timeout policy and review fallback |

`LOCAL` permits preview and audited internal apply only where separately enabled; it performs no invitation or external geocode call. Import never implies send. `LIVE` revalidates epoch/revision/profile at job claim and adapter boundary. Stale batches/invitations remain blocked. Disable preserves memberships, batch lineage and audit; rollback is exact deactivate/reconcile, never broad deletion.

## Queue/worker/scheduler/cron

Large import/backfill jobs use leases, stable batch keys and idempotent row processing. Invitation and outbound notification workers remain disabled during import.

## Build/restart/post-deploy command

Backup -> additive migration -> permission/catalog sync without privilege expansion -> dry-run backfill/import -> reconcile -> enable internal UI -> restart bounded workers -> authorization smoke.

## External provider contract

No message is sent as an implicit result of user/membership/import changes. Mikro validation is read-only until INT-MIKRO write activation.

## Health check

Permission/catalog consistency, hidden-scope leak checks, duplicate identity queue, import batch status and unresolved mapping counts.

## Log/metric/alert

Alert on denied privilege escalation, cross-tenant query, hidden-data exposure, duplicate merge anomaly, failed import rows and unexpected invitation/message.

## Backup/restore

Restore rehearsal preserves users, memberships, access matrices, technician mappings, merge lineage and audit history without reactivating disabled accounts/memberships.

## Cutover

Deploy deny-first guards -> migrate/backfill -> reconcile permissions/identity -> internal UAT -> controlled importer dry-run/apply -> keep invitations off -> staging tenant/PII acceptance.

## Rollback/disable

Disable new mutations/import/reveal/export, retain memberships and audit, reverse merge through recorded lineage, deactivate import batch rather than destructive broad delete.

## Production smoke

Use synthetic scoped accounts to prove global inactive, membership inactive, hidden partner, same-partner technician mapping and reveal/export denials. Production import is separately authorized in REL-15.

## Data reconciliation

User/role/access/profile counts, active/inactive memberships, capability scopes, technician mappings, duplicate candidates, merge lineage and import batch totals reconcile with no hidden-count leak.

## S0/S1/S2 blockers

S0: cross-tenant/PII exposure or privilege escalation. S1: irreversible merge, silent membership/permission mutation, non-idempotent import. S2: non-security UX issue.

## Go/No-Go owner

Burhan/product, security owner, data/privacy owner, partner operations owner and independent verifier.

## Open decisions

Delegated permission-envelope policy, Admin Review assignment policy without person hard-code, customer merge survivorship rules, PII retention periods, and final import source/rollback window.

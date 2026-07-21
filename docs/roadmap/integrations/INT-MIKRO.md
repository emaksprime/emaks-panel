# INT-MIKRO - Direct API Preparation and Final Activation

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Replace ERP-path n8n dependencies with a typed, observable, replay-safe Laravel-to-Mikro API integration while keeping credentials and traffic off until the final guarded production activation.

## Dependencies

REL-5C customer/cari identity, REL-10A events, environment foundation and accepted ERP ownership/operation inventory. REL-4G and REL-12 consume this port; REL-15 owns final activation.

## Included scope

Typed `ErpGateway`/`MikroApiClient`, operation catalog, named-query allowlist, timeout/rate limit/read retry, projection/cache, outbox, idempotency, ambiguity/reconcile, DLQ, cockpit, off/shadow/active read/write states, audit/monitoring, rollback and complete inventory of datasource/workflow/query consumers.

Operations include accepted cari, stock/warehouse, serial, sale, order, proforma, invoice, dispatch, return/exchange and stock movement/transfer capabilities. Business parity such as HTN-IRAN rules must be preserved where applicable.

## Excluded scope

Arbitrary SQL, credential entry during preparation, automatic n8n fallback for writes, public traffic activation and domain ownership migration into a second Laravel ERP truth.

## Source of truth

Mikro owns ERP stock, cari, order, proforma, invoice, dispatch, sale and serial movement. Laravel owns MRN/SRV, installation, appointment, technician/service, warranty start, part/payment operations, calls, audit, Happy Call and survey. CRM is a source-labelled projection.

## Entry criteria

Datasource, `N8nPanelDataGateway`, query/workflow and consumer inventory complete; operation ownership approved; `unmapped=0` plan defined; credentials remain absent/off.

## Exit/acceptance criteria

Every consumer maps to a typed operation; arbitrary SQL is impossible; read parity passes; writes are outbox/idempotency/ambiguity/reconcile safe; DLQ/cockpit/metrics/rollback work; off/shadow/active states fail closed; no credential or flag alone enables traffic.

## Exact evidence requirements

Exact SHA, operation matrix, source ownership, contract tests, fake Mikro responses, parity reports, write replay/timeout/reconcile tests, DLQ/cockpit browser evidence, monitoring and rollback rehearsal.

## RBAC/tenant isolation

Operations derive tenant/company/period and actor scope server-side. Clients cannot supply arbitrary database, query or company context. Cockpit actions require dedicated permission and audit.

## Audit/event contract

Record operation, tenant/company/period, entity/correlation/idempotency, request fingerprint, result/ambiguity/reconcile/DLQ, actor/system actor and flag/credential changes without secrets.

## Migration/schema

Additive projection/outbox/idempotency/reconcile/DLQ schema with unique keys and indexes. Fresh/upgrade migrations and retention are required.

## Backfill/import

Build projections/checkpoints in bounded batches, compare hashes/counts and route discrepancies to review. Never manufacture missing ERP truth.

## DEV/UAT/PROD env

DEV uses fake/test endpoints; UAT uses approved isolated tenant; PROD identifiers/secrets are entered last. Endpoint/tenant/period cannot be copied across environments.

## Public/internal URL and callback

Mikro endpoints are internal/external API destinations through the typed client. Cockpit is internal. Any callback is authenticated and replay-safe.

## Secret/credential

Credentials are external, environment-specific and rotatable. Save is inert; only REL-15 guarded activation may atomically enable capabilities after all checks.

## Feature flag and safe default

Global and per-capability read/write states default `off`; `shadow` cannot mutate; `active` requires recorded all-pass activation.

## Queue/worker/scheduler/cron

Outbox/reconcile/DLQ workers are capability-specific, leased, bounded and idempotent. Ambiguous write is no blind retry; no n8n write fallback.

## Build/restart/post-deploy command

Deploy off -> migrate -> inventory/operation matrix -> build projection -> fake contract tests -> read shadow/parity -> controlled write canary only at final gate -> start accepted workers -> observe.

## External provider contract

Timeouts, rate limits, read retries, write idempotency and exact GUID/document/line/amount/warehouse/tax reconciliation are operation-specific. ACK does not imply business reconciliation.

## Health check

API health/license/tenant/company/period/database/capability, projection lag, outbox age, ambiguity, DLQ and reconcile parity.

## Log/metric/alert

Alert on auth/license/tenant mismatch, parity drift, duplicate write, stale outbox, ambiguity, DLQ, reconciliation mismatch and unexpected n8n ERP traffic.

## Backup/restore

Restore Laravel projection/outbox only with external Mikro reconciliation. Never replay committed writes because local state was restored.

## Cutover

Preparation remains credentials-off. In REL-15 enter production credential last, run health/license/tenant/period/capability/read parity/controlled write/replay/reconcile, atomically enable only all-pass capabilities, disable ERP n8n flows and observe their traffic at zero before public launch.

## Rollback/disable

Set capabilities off, stop outbox workers, preserve ambiguity/DLQ/audit, reconcile Mikro truth and roll application separately. Do not route writes back to n8n automatically.

## Production smoke

One separately authorized read parity and controlled write canary with exact GUID/document/line/amount/warehouse/tax reconciliation.

## Data reconciliation

Operation-by-operation source counts, keys, amounts, documents, serials, warehouses, tax, outbox/idempotency and projection checkpoints match; `unmapped=0`.

## S0/S1/S2 blockers

S0: wrong tenant/company, duplicate/wrong ERP write. S1: unmapped operation, parity drift, unreconciled ambiguity, fallback path. S2: cockpit presentation issue.

## Go/No-Go owner

ERP/Mikro owner, application architecture, finance/inventory data owners, security, operations and release commander.

## Open decisions

Final operation catalog, tenant/period identifiers, rate/timeouts, projection freshness SLA, canary operations and exact n8n flows disabled at cutover.

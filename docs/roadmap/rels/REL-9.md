# REL-9 - Settlement, Payment and Cari Final Audit

Status is owned only by [the canonical ledger](../REL_STATUS_LEDGER.md).

## Business outcome

Finalize existing payment/earning flows for production without rebuilding them, proving financial uniqueness, trusted provider reconciliation, correct cari ownership and safe refund/cancel behavior.

## Dependencies

REL-4H, REL-5C, REL-10A, REL-12 part lifecycle, environment public HTTPS and INT-MIKRO preparation.

## Included scope

Parent/child earning, part fees, refund/cancel, provider transaction uniqueness, ambiguous reconciliation, callback/replay idempotency, trusted paid source, no fake receipt, cari correctness, existing payment-link actions, post-payment notification/mail and Iyzico live readiness.

## Excluded scope

Rewriting accepted settlement/payment foundations, entering production credentials early, and Mikro final activation.

## Source of truth

Provider is authoritative for payment transaction result; Laravel owns payment operation/settlement/earning workflow and reconciliation ledger; Mikro owns ERP cari/financial document truth after cutover.

## Entry criteria

Exact current-head payment regression passes, customer/cari mapping accepted, public callback origin ready, settlement ownership defined and finance owner approves canary/reconcile contract.

## Exit/acceptance criteria

Client cannot mark paid; each provider transaction applies once; replay/duplicate mail/payment are zero; parent/child/part/refund totals reconcile; ambiguity blocks irreversible completion; cari is exact; controlled live canary reconciles amount/currency/token/reference.

## Exact evidence requirements

Exact SHA, sandbox/live mode, provider transaction fingerprints without secrets, request/callback/reconcile logs, before/after ledger totals, duplicate/replay tests, browser evidence and finance sign-off.

## RBAC/tenant isolation

Payment creation/cancel/refund/marking and cari reveal require server permissions and exact customer/request scope. Denials expose no provider or PII details.

## Audit/event contract

Emit link create/cancel/open/send, callback, reconcile, settlement, payout, refund and ambiguity events with actor/source, amount/currency and redacted provider reference.

## Migration/schema

Unique provider references/idempotency and settlement constraints must pass fresh/upgrade migrations. Never fabricate provider references during backfill.

## Backfill/import

Preview/reconcile existing links and settlements. Ambiguous/missing references enter review; no local ID is promoted as a fake provider reference.

## DEV/UAT/PROD env

Sandbox and live merchant configuration are isolated. Live readiness stays off until HTTPS, secrets and callback/reconcile checks pass.

## Public/internal URL and callback

Payment links/callbacks use canonical HTTPS, trusted proxy and strict callback verification. Internal preview/support URLs are never sent to customers.

## Secret/credential

Merchant keys are external, separate by environment, rotatable and never logged. Saving them does not enable live payment creation.

## Feature flag and safe default

Live creation/callback mutation/refund default off independently. Kill switch must stop new external actions without corrupting existing reconciliation.

## Queue/worker/scheduler/cron

Reconcile/mail/message jobs are idempotent, leased and mode-aware. Ambiguous provider outcomes are not blindly retried.

## Build/restart/post-deploy command

Deploy live-disabled -> migrate -> reconcile dry-run -> HTTPS/callback smoke -> sandbox regression -> authorize one live canary -> reconcile -> keep broader traffic gated until REL-15.

## External provider contract

Iyzico accepted/paid/cancelled/refunded states are mapped explicitly. Token/reference, amount and currency must match. Callback and scheduled reconcile are idempotent.

## Health check

Provider readiness, callback reachability, pending/ambiguous age, reconciliation lag, duplicate reference and settlement imbalance.

## Log/metric/alert

Alert on duplicate payment/mail, amount/currency mismatch, untrusted paid transition, stale pending, refund failure or cari mismatch.

## Backup/restore

Backup/restore cannot replay external payment or erase provider truth. Reconcile provider state after restore before reopening writes.

## Cutover

Production-disabled deploy -> callback verification -> one bounded live canary -> exact reconciliation -> finance Go/No-Go -> enable only accepted actions during REL-15.

## Rollback/disable

Stop new link/refund actions, preserve callbacks/ledger/audit, reconcile provider state, and separate app rollback from financial data recovery.

## Production smoke

One separately authorized low-risk payment canary and, only if explicitly required, controlled refund proof; no duplicate mail/message.

## Data reconciliation

Provider status/reference, amount/currency, payment link, settlement, earning, customer/request and cari all match one-to-one.

## S0/S1/S2 blockers

S0: duplicate/wrong payment, cross-customer link, false paid. S1: ambiguous unreconciled result, wrong cari, duplicate notification. S2: display-only issue.

## Go/No-Go owner

Finance, product, security, provider integration and release owners.

## Open decisions

Live canary amount, refund proof necessity, callback allowlist policy and finance reconciliation SLA.

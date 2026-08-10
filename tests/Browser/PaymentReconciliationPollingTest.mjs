import assert from 'node:assert/strict'
import test from 'node:test'
import {
  PAYMENT_RECONCILIATION_POLL_INTERVAL_MS,
  resolveVisiblePendingPaymentTargets,
} from '../../resources/js/components/technical-service/payment-reconciliation.ts'

test('visible_pending_payment_auto_polls_exact_customer_charge_payment', () => {
  const targets = resolveVisiblePendingPaymentTargets({
    id: 264,
    saleAndPayment: {
      extra_mount_payment: { id: 192, request_id: 264, status: 'paid' },
      customer_charges: {
        rows: [
          { id: 195, request_id: 264, status: 'pending' },
        ],
      },
    },
  })

  assert.deepEqual(targets, [{ requestId: '264', paymentId: '195' }])
})

test('visible_pending_payment_deduplicates_shared_presenter_rows', () => {
  const targets = resolveVisiblePendingPaymentTargets({
    id: '264',
    saleAndPayment: {
      extra_mount_payment: { id: '195', request_id: '264', status: 'pending' },
      mount_payments: {
        pending_rows: [{ id: 195, request_id: 264, status: 'pending' }],
      },
      customer_charges: {
        rows: [{ id: 195, request_id: 264, status: 'pending' }],
      },
    },
  })

  assert.deepEqual(targets, [{ requestId: '264', paymentId: '195' }])
})

test('passive_poll_stops_targeting_terminal_payments', () => {
  const targets = resolveVisiblePendingPaymentTargets({
    id: 264,
    saleAndPayment: {
      mount_payments: {
        pending_rows: [
          { id: 195, request_id: 264, status: 'paid' },
          { id: 196, request_id: 264, status: 'cancelled' },
          { id: 197, request_id: 264, status: 'expired' },
        ],
      },
      customer_charges: {
        rows: [{ id: 198, request_id: 264, status: 'failed' }],
      },
    },
  })

  assert.deepEqual(targets, [])
})

test('passive_poll_interval_is_bounded_to_five_seconds', () => {
  assert.equal(PAYMENT_RECONCILIATION_POLL_INTERVAL_MS, 5000)
  assert.ok(PAYMENT_RECONCILIATION_POLL_INTERVAL_MS <= 5000)
})

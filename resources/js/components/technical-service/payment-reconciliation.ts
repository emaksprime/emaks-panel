export const PAYMENT_RECONCILIATION_POLL_INTERVAL_MS = 5000

type PendingPaymentCandidate = {
  id?: number | string | null
  request_id?: number | string | null
  status?: string | null
}

type VisiblePaymentRequest = {
  id?: number | string | null
  saleAndPayment?: {
    extra_mount_payment?: PendingPaymentCandidate | null
    mount_payments?: {
      pending_rows?: PendingPaymentCandidate[] | null
    } | null
    customer_charges?: {
      rows?: PendingPaymentCandidate[] | null
    } | null
  } | null
}

export type PendingPaymentPollTarget = {
  requestId: string
  paymentId: string
}

export const resolveVisiblePendingPaymentTargets = (
  request: VisiblePaymentRequest | null | undefined,
): PendingPaymentPollTarget[] => {
  const fallbackRequestId = String(request?.id ?? '').trim()

  if (fallbackRequestId === '') {
    return []
  }

  const saleAndPayment = request?.saleAndPayment
  const candidates: PendingPaymentCandidate[] = [
    saleAndPayment?.extra_mount_payment,
    ...(saleAndPayment?.mount_payments?.pending_rows ?? []),
    ...(saleAndPayment?.customer_charges?.rows ?? []),
  ].filter((payment): payment is PendingPaymentCandidate => Boolean(payment))
  const seen = new Set<string>()

  return candidates.reduce<PendingPaymentPollTarget[]>((targets, payment) => {
    if (String(payment.status ?? '').toLowerCase() !== 'pending') {
      return targets
    }

    const paymentId = String(payment.id ?? '').trim()
    const requestId = String(payment.request_id ?? fallbackRequestId).trim()
    const identity = `${requestId}:${paymentId}`

    if (requestId === '' || paymentId === '' || seen.has(identity)) {
      return targets
    }

    seen.add(identity)
    targets.push({ requestId, paymentId })

    return targets
  }, [])
}

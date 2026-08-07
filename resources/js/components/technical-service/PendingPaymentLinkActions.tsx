import { Copy, ExternalLink, RefreshCw, Send, XCircle } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { ServiceRequestExtraMountPayment } from './types'

export type PendingPaymentLinkSurface = 'payment-modal' | 'technical-service' | 'root-mrn' | 'child-srv' | 'part-request'

export type PendingPaymentLinkActionPayment = Pick<ServiceRequestExtraMountPayment,
  | 'id'
  | 'status'
  | 'canonical_url'
  | 'payment_url'
  | 'copy_url'
  | 'can_open'
  | 'can_copy'
  | 'can_send'
  | 'can_check'
  | 'can_cancel'
  | 'can_open_payment_url'
  | 'can_copy_payment_url'
  | 'can_cancel_payment'
  | 'is_external_provider'
  | 'disabled_reason'
  | 'payment_action_disabled_reason'
>

export type PaymentLinkSendContext = PendingPaymentLinkActionPayment & {
  id: number | string
  request_code?: string | null
  root_mrn?: string | null
  customer_name?: string | null
  amount: number
  amount_label?: string | null
  currency?: string | null
  status: string
  status_label?: string | null
  purpose?: string | null
  purpose_label?: string | null
  link_token?: string | null
  message_target_phone_masked?: string | null
  message_target_mode?: 'test' | 'actual' | string | null
  message_send_count?: number | null
  last_message_sent_at?: string | null
}

export type PaymentLinkSendPayload = {
  payment_id: number | string
  send_request_id: string
  resend_reason?: string | null
}

export type PaymentLinkSendResult = {
  message?: string | null
  payment?: PaymentLinkSendContext | null
  idempotent_replay?: boolean
}

export const canonicalPaymentLinkSendPayload = (
  payment: PaymentLinkSendContext,
  sendRequestId: string,
  resendReason: string | null,
): PaymentLinkSendPayload => ({
  payment_id: payment.id,
  send_request_id: sendRequestId,
  resend_reason: resendReason,
})

type PendingPaymentLinkActionsProps = {
  payment: PendingPaymentLinkActionPayment
  surface: PendingPaymentLinkSurface
  copyFeedback?: ReactNode
  sendLabel?: string
  sendDisabledReason?: string | null
  sendBusy?: boolean
  checkBusy?: boolean
  cancelBusy?: boolean
  onCopy?: (canonicalUrl: string) => void | Promise<void>
  onSend?: () => void | Promise<void>
  onCheck?: () => void | Promise<void>
  onCancel?: () => void | Promise<void>
}

type PaymentLinkSendDialogProps = {
  open: boolean
  payment: PaymentLinkSendContext | null
  requestReference: string
  resendReason: string
  busy?: boolean
  resultMessage?: string | null
  errorMessage?: string | null
  onResendReasonChange: (value: string) => void
  onConfirm: () => void | Promise<void>
  onClose: () => void
}

export const canonicalPendingPaymentUrl = (payment: PendingPaymentLinkActionPayment): string => (
  String(payment.canonical_url ?? payment.copy_url ?? payment.payment_url ?? '').trim()
)

export const paymentLinkSendDisabledReason = (payment: PaymentLinkSendContext): string | null => {
  if (payment.status === 'paid') {
    return 'Bu ödeme zaten tahsil edildi; bağlantı yeniden gönderilemez.'
  }

  if (payment.status === 'cancelled') {
    return 'Bu ödeme bağlantısı iptal edildi; yeniden gönderilemez.'
  }

  if (payment.status === 'expired') {
    return 'Bu ödeme bağlantısının süresi doldu; yeniden gönderilemez.'
  }

  if (payment.status !== 'pending') {
    return 'Seçilen ödeme bağlantısı aktif bekleyen durumda değil; gönderilemez.'
  }

  if (!canonicalPendingPaymentUrl(payment)) {
    return 'Ödeme bağlantısı bu kayıt için bulunamadı.'
  }

  return null
}

export function PaymentLinkSendDialog({
  open,
  payment,
  requestReference,
  resendReason,
  busy = false,
  resultMessage = null,
  errorMessage = null,
  onResendReasonChange,
  onConfirm,
  onClose,
}: PaymentLinkSendDialogProps) {
  if (!open || !payment) {
    return null
  }

  const canonicalUrl = canonicalPendingPaymentUrl(payment)
  const disabledReason = paymentLinkSendDisabledReason(payment)
  const isResend = Number(payment.message_send_count ?? 0) > 0
  const resendReasonMissing = isResend && resendReason.trim().length < 3
  const sendDisabledReason = disabledReason
    ?? (resendReasonMissing ? 'Yeniden gönderim nedeni en az 3 karakter olmalıdır.' : null)
  const fields = [
    ['Payment ID', String(payment.id)],
    ['MRN / SRV', payment.request_code || payment.root_mrn || requestReference],
    ['Tahsilat amacı', payment.purpose_label || payment.purpose || '-'],
    ['Tutar', payment.amount_label || `${payment.amount.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${payment.currency || 'TRY'}`],
    ['Durum', payment.status_label || payment.status],
    ['Link token', payment.link_token || '-'],
    ['Müşteri', payment.customer_name || '-'],
    ['Hedef', payment.message_target_phone_masked || '-'],
    ['Hedef modu', payment.message_target_mode === 'test' ? 'Test yönlendirmesi' : 'Gerçek müşteri'],
    ['Kanallar', 'WhatsApp + SMS'],
  ] as const

  return (
    <div className="fixed inset-0 z-[120] flex items-end justify-center bg-slate-950/55 p-3 sm:items-center" role="dialog" aria-modal="true" aria-label="Ödeme bağlantısını müşteriye gönder">
      <div className="grid max-h-[92vh] w-full max-w-2xl gap-4 overflow-y-auto rounded-lg bg-white p-5 shadow-xl">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="text-base font-semibold text-slate-950">Ödeme bağlantısını müşteriye gönder</h2>
            <p className="mt-1 text-sm text-slate-600">Seçilen canonical ödeme kaydı ve gönderim hedefi</p>
          </div>
          <Button type="button" size="sm" variant="ghost" onClick={onClose}>Kapat</Button>
        </div>

        <dl data-testid="payment-link-send-context" className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-2">
          {fields.map(([label, value]) => (
            <div key={label} className="min-w-0 bg-white px-3 py-2">
              <dt className="text-xs font-medium text-slate-500">{label}</dt>
              <dd className="mt-1 break-words text-sm font-semibold text-slate-900">{value}</dd>
            </div>
          ))}
        </dl>

        <div className="min-w-0 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
          <p className="text-xs font-medium text-slate-500">Canonical URL</p>
          <p data-testid="payment-link-send-canonical-url" className="mt-1 break-all text-sm font-semibold text-slate-900">{canonicalUrl || '-'}</p>
        </div>

        {isResend ? (
          <label className="grid gap-1 text-sm font-medium text-slate-800">
            Yeniden gönderim nedeni
            <Input
              value={resendReason}
              onChange={(event) => onResendReasonChange(event.target.value)}
              placeholder="En az 3 karakter"
              aria-label="Ödeme linki yeniden gönderim nedeni"
            />
          </label>
        ) : null}

        {sendDisabledReason ? <p data-testid="payment-link-send-disabled-reason" role="status" className="text-sm font-medium text-amber-800">{sendDisabledReason}</p> : null}
        {resultMessage ? <p data-testid="payment-link-send-result" role="status" className="text-sm font-medium text-emerald-800">{resultMessage}</p> : null}
        {errorMessage ? <p data-testid="payment-link-send-error" role="alert" className="text-sm font-medium text-red-700">{errorMessage}</p> : null}

        <div className="flex flex-wrap justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>Vazgeç</Button>
          <Button data-testid="payment-link-send-confirm" type="button" disabled={Boolean(sendDisabledReason) || busy} onClick={() => void onConfirm()}>
            <Send aria-hidden="true" />
            {busy ? 'Kuyruğa alınıyor...' : isResend ? 'Yeniden gönder' : 'Gönder'}
          </Button>
        </div>
      </div>
    </div>
  )
}

export function PendingPaymentLinkActions({
  payment,
  surface,
  copyFeedback,
  sendLabel = 'Linki gönder',
  sendDisabledReason = null,
  sendBusy = false,
  checkBusy = false,
  cancelBusy = false,
  onCopy,
  onSend,
  onCheck,
  onCancel,
}: PendingPaymentLinkActionsProps) {
  const canonicalUrl = canonicalPendingPaymentUrl(payment)
  const isPending = payment.status === 'pending'
  const canOpen = isPending && canonicalUrl !== '' && (payment.can_open ?? payment.can_open_payment_url ?? true)
  const canCopy = isPending && canonicalUrl !== '' && (payment.can_copy ?? payment.can_copy_payment_url ?? true) && Boolean(onCopy)
  const canSend = isPending && canonicalUrl !== '' && (payment.can_send ?? true) && Boolean(onSend) && !sendDisabledReason
  const canCheck = isPending && (payment.can_check ?? payment.is_external_provider ?? false) && Boolean(onCheck)
  const canCancel = isPending && (payment.can_cancel ?? payment.can_cancel_payment ?? true) && Boolean(onCancel)
  const disabledReason = payment.disabled_reason
    ?? payment.payment_action_disabled_reason
    ?? (canonicalUrl === '' ? 'Ödeme bağlantısı bu kayıt için bulunamadı.' : null)
  const testId = `pending-payment-actions-${surface}`

  return (
    <div data-testid={testId} data-payment-link-surface={surface} className="grid min-w-0 gap-2">
      <div className="flex min-w-0 flex-wrap items-center gap-2">
        {canOpen ? (
          <Button asChild type="button" size="sm" variant="outline">
            <a data-testid={`${testId}-open`} href={canonicalUrl} target="_blank" rel="noreferrer">
              <ExternalLink aria-hidden="true" />
              Linki aç
            </a>
          </Button>
        ) : (
          <Button data-testid={`${testId}-open`} type="button" size="sm" variant="outline" disabled title={disabledReason ?? undefined}>
            <ExternalLink aria-hidden="true" />
            Linki aç
          </Button>
        )}
        <Button
          data-testid={`${testId}-copy`}
          type="button"
          size="sm"
          variant="outline"
          disabled={!canCopy}
          title={!canCopy ? disabledReason ?? undefined : 'Canonical ödeme bağlantısını kopyala'}
          onClick={() => canCopy && void onCopy?.(canonicalUrl)}
        >
          <Copy aria-hidden="true" />
          Linki kopyala
        </Button>
        <Button
          data-testid={`${testId}-send`}
          type="button"
          size="sm"
          variant="outline"
          disabled={!canSend || sendBusy}
          title={sendDisabledReason ?? (!canSend ? disabledReason ?? undefined : 'Ödeme bağlantısını müşteriye WhatsApp ve SMS ile gönder')}
          onClick={() => canSend && void onSend?.()}
        >
          <Send aria-hidden="true" />
          {sendBusy ? 'Kuyruğa alınıyor...' : sendLabel}
        </Button>
        <Button
          data-testid={`${testId}-check`}
          type="button"
          size="sm"
          variant="outline"
          disabled={!canCheck || checkBusy}
          title={!canCheck ? disabledReason ?? undefined : 'Provider ödeme durumunu kontrol et'}
          onClick={() => canCheck && void onCheck?.()}
        >
          <RefreshCw aria-hidden="true" />
          {checkBusy ? 'Kontrol ediliyor...' : 'Durumu Kontrol Et'}
        </Button>
        <Button
          data-testid={`${testId}-cancel`}
          type="button"
          size="sm"
          variant="destructive"
          disabled={!canCancel || cancelBusy}
          title={!canCancel ? disabledReason ?? undefined : 'Bekleyen ödeme bağlantısını iptal et'}
          onClick={() => canCancel && void onCancel?.()}
        >
          <XCircle aria-hidden="true" />
          {cancelBusy ? 'İptal ediliyor...' : 'İptal et'}
        </Button>
      </div>
      {disabledReason && (!canOpen || !canCopy || !canSend) ? (
        <p data-testid={`${testId}-disabled-reason`} role="status" className="text-xs font-medium text-amber-800">
          {disabledReason}
        </p>
      ) : null}
      {copyFeedback}
    </div>
  )
}

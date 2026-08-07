import { Copy, ExternalLink, RefreshCw, Send, XCircle } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from '@/components/ui/button'
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

export const canonicalPendingPaymentUrl = (payment: PendingPaymentLinkActionPayment): string => (
  String(payment.canonical_url ?? payment.copy_url ?? payment.payment_url ?? '').trim()
)

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

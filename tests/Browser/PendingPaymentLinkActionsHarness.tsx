import { useRef, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { PaymentLinkSendDialog, PendingPaymentLinkActions, canonicalPaymentLinkSendPayload } from '../../resources/js/components/technical-service/PendingPaymentLinkActions'
import type { PaymentLinkSendContext, PaymentLinkSendPayload, PendingPaymentLinkActionPayment, PendingPaymentLinkSurface } from '../../resources/js/components/technical-service/PendingPaymentLinkActions'
import '../../resources/css/app.css'

type HarnessState = {
  copiedUrl: Record<string, string>
  sendCount: Record<string, number>
  checkCount: Record<string, number>
  cancelCount: Record<string, number>
  modalRequestCount: number
  modalPayloads: PaymentLinkSendPayload[]
}

declare global {
  interface Window {
    __pendingPaymentDomReady?: boolean
    __pendingPaymentDomState?: HarnessState
  }
}

const canonicalUrl = 'https://sandbox.iyzi.link/dom-acceptance-token'
const surfaces: PendingPaymentLinkSurface[] = ['payment-modal', 'technical-service', 'root-mrn', 'child-srv', 'part-request']
const state: HarnessState = {
  copiedUrl: {},
  sendCount: {},
  checkCount: {},
  cancelCount: {},
  modalRequestCount: 0,
  modalPayloads: [],
}

window.__pendingPaymentDomState = state

function Harness() {
  const [feedback, setFeedback] = useState<Record<string, string>>({})
  const [sendBusy, setSendBusy] = useState<Record<string, boolean>>({})
  const [modalPayment, setModalPayment] = useState<PaymentLinkSendContext | null>({
    id: 195,
    request_code: 'MRN-DOM-SELECTED-PAYMENT',
    customer_name: 'Test Müşteri',
    amount: 3000,
    amount_label: '3.000,00 TL',
    currency: 'TRY',
    status: 'pending',
    status_label: 'Bekliyor',
    purpose: 'service_payment',
    purpose_label: 'Ek servis',
    canonical_url: canonicalUrl,
    payment_url: canonicalUrl,
    copy_url: canonicalUrl,
    link_token: 'dom-acceptance-token',
    message_target_phone_masked: '9053****633',
    message_target_mode: 'test',
    message_send_count: 0,
    can_open: true,
    can_copy: true,
    can_send: true,
    can_check: true,
    can_cancel: true,
  })
  const [modalBusy, setModalBusy] = useState(false)
  const [modalRequestCount, setModalRequestCount] = useState(0)
  const [modalPaymentId, setModalPaymentId] = useState<number | string | null>(null)
  const sendLocks = useRef(new Set<string>())
  const modalRequestLock = useRef(false)
  const payment: PendingPaymentLinkActionPayment = {
    id: 192,
    status: 'pending',
    canonical_url: canonicalUrl,
    payment_url: canonicalUrl,
    copy_url: canonicalUrl,
    can_open: true,
    can_copy: true,
    can_send: true,
    can_check: true,
    can_cancel: true,
    is_external_provider: true,
    disabled_reason: null,
  }

  const copy = (surface: PendingPaymentLinkSurface, value: string) => {
    state.copiedUrl[surface] = value
    setFeedback((current) => ({ ...current, [surface]: 'Ödeme bağlantısı kopyalandı.' }))
  }
  const send = (surface: PendingPaymentLinkSurface) => {
    if (sendLocks.current.has(surface)) {
      return
    }

    sendLocks.current.add(surface)
    state.sendCount[surface] = (state.sendCount[surface] ?? 0) + 1
    setSendBusy((current) => ({ ...current, [surface]: true }))
    setFeedback((current) => ({ ...current, [surface]: 'Ödeme bağlantısı müşteriye gönderim kuyruğuna alındı.' }))
  }
  const sendSelectedPayment = () => {
    if (!modalPayment || modalRequestLock.current) {
      return
    }

    modalRequestLock.current = true
    const payload = canonicalPaymentLinkSendPayload(
      modalPayment,
      '95f29308-3626-4a27-9e8c-b85c650269b4',
      null,
    )

    state.modalRequestCount += 1
    state.modalPayloads.push(payload)
    setModalRequestCount((current) => current + 1)
    setModalPaymentId(payload.payment_id)
    setModalBusy(true)
  }

  return (
    <main className="grid gap-6 p-6">
      {surfaces.map((surface) => (
        <section key={surface} id={`surface-${surface}`} data-testid={`surface-${surface}`} className="grid gap-2">
          <h2>{surface}</h2>
          <PendingPaymentLinkActions
            payment={payment}
            surface={surface}
            copyFeedback={feedback[surface] ? <p role="status">{feedback[surface]}</p> : null}
            sendBusy={sendBusy[surface] === true}
            onCopy={(value) => copy(surface, value)}
            onSend={() => send(surface)}
            onCheck={() => {
              state.checkCount[surface] = (state.checkCount[surface] ?? 0) + 1
            }}
            onCancel={() => {
              state.cancelCount[surface] = (state.cancelCount[surface] ?? 0) + 1
            }}
          />
          <output data-testid={`harness-copied-url-${surface}`} className="sr-only">{state.copiedUrl[surface] ?? ''}</output>
          <output data-testid={`harness-send-count-${surface}`} className="sr-only">{state.sendCount[surface] ?? 0}</output>
        </section>
      ))}
      <section id="terminal-payment" data-testid="terminal-payment">
        <PendingPaymentLinkActions
          payment={{
            ...payment,
            id: 193,
            status: 'paid',
            can_open: false,
            can_copy: false,
            can_send: false,
            can_check: false,
            can_cancel: false,
            disabled_reason: 'Ödeme tamamlandı.',
          }}
          surface="part-request"
        />
      </section>
      <section id="missing-url-payment" data-testid="missing-url-payment">
        <PendingPaymentLinkActions
          payment={{
            id: 194,
            status: 'pending',
            canonical_url: null,
            can_open: false,
            can_copy: false,
            can_send: false,
            can_check: true,
            can_cancel: true,
            disabled_reason: 'Ödeme bağlantısı bu kayıt için bulunamadı.',
          }}
          surface="technical-service"
          onCheck={() => undefined}
          onCancel={() => undefined}
        />
      </section>
      <section id="payment-send-modal-controls" className="flex gap-2">
        <button
          type="button"
          data-testid="open-pending-payment-send-modal"
          onClick={() => {
            modalRequestLock.current = false
            setModalBusy(false)
            setModalPayment({
              id: 195,
              request_code: 'MRN-DOM-SELECTED-PAYMENT',
              customer_name: 'Test Müşteri',
              amount: 3000,
              amount_label: '3.000,00 TL',
              currency: 'TRY',
              status: 'pending',
              status_label: 'Bekliyor',
              purpose: 'service_payment',
              purpose_label: 'Ek servis',
              canonical_url: canonicalUrl,
              link_token: 'dom-acceptance-token',
              message_target_phone_masked: '9053****633',
              message_target_mode: 'test',
              message_send_count: 0,
            })
          }}
        >Pending modal</button>
        <button
          type="button"
          data-testid="open-paid-payment-send-modal"
          onClick={() => setModalPayment({
            id: 192,
            request_code: 'MRN-DOM-SELECTED-PAYMENT',
            customer_name: 'Test Müşteri',
            amount: 3000,
            amount_label: '3.000,00 TL',
            currency: 'TRY',
            status: 'paid',
            status_label: 'Ödendi',
            purpose: 'manual_mount_payment',
            purpose_label: 'Genel ek tahsilat',
            canonical_url: 'https://sandbox.iyzi.link/paid-token',
            link_token: 'paid-token',
            message_target_phone_masked: '9053****633',
            message_target_mode: 'test',
            message_send_count: 1,
          })}
        >Paid modal</button>
      </section>
      <PaymentLinkSendDialog
        open={modalPayment !== null}
        payment={modalPayment}
        requestReference="MRN-DOM-SELECTED-PAYMENT"
        resendReason=""
        busy={modalBusy}
        resultMessage={modalBusy ? '3.000,00 TL tutarındaki Ek servis ödeme bağlantısı müşteriye gönderim kuyruğuna alındı.' : null}
        onResendReasonChange={() => undefined}
        onConfirm={sendSelectedPayment}
        onClose={() => setModalPayment(null)}
      />
      <output data-testid="modal-network-request-count" className="sr-only">{modalRequestCount}</output>
      <output data-testid="modal-payment-id" className="sr-only">{modalPaymentId ?? ''}</output>
    </main>
  )
}

createRoot(document.getElementById('root')!).render(<Harness />)
window.__pendingPaymentDomReady = true

import { useRef, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { PendingPaymentLinkActions } from '../../resources/js/components/technical-service/PendingPaymentLinkActions'
import type { PendingPaymentLinkActionPayment, PendingPaymentLinkSurface } from '../../resources/js/components/technical-service/PendingPaymentLinkActions'
import '../../resources/css/app.css'

type HarnessState = {
  copiedUrl: Record<string, string>
  sendCount: Record<string, number>
  checkCount: Record<string, number>
  cancelCount: Record<string, number>
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
}

window.__pendingPaymentDomState = state

function Harness() {
  const [feedback, setFeedback] = useState<Record<string, string>>({})
  const [sendBusy, setSendBusy] = useState<Record<string, boolean>>({})
  const sendLocks = useRef(new Set<string>())
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
    </main>
  )
}

createRoot(document.getElementById('root')!).render(<Harness />)
window.__pendingPaymentDomReady = true

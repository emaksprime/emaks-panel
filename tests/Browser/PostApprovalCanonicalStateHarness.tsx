import { useCallback, useEffect, useRef, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { ServiceRequestDetails } from '../../resources/js/components/technical-service/ServiceRequestDetails'
import type { PostApprovalState } from '../../resources/js/components/technical-service/ServiceRequestDetails'
import type { ServiceRequest, WarrantySerialResponse } from '../../resources/js/components/technical-service/types'
import { apiRequest } from '../../resources/js/lib/api'
import { applyPostApprovalRequestDelta, usePostApprovalRevalidation } from '../../resources/js/pages/panel/technical-service'
import '../../resources/css/app.css'

type HarnessState = {
  pollCount: number
  pollInFlight: number
  maxPollInFlight: number
  suitabilityPostCount: number
  finalCheckPostCount: number
  boardRefetchCount: number
  modalMountCount: number
  appliedStateRequestIds: number[]
  serverApprovalStatus: 'pending' | 'approved'
  documentHidden: boolean
  delayNextPoll: boolean
  releaseDelayedPoll: (() => void) | null
  externalApprovalStartedAt: number | null
  approvalLatencyMs: number | null
  immediatePollLatencyMs: number | null
  completedWarrantyLatencyMs: number | null
  suitabilityLatencyMs: number | null
  finalCheckLatencyMs: number | null
}

type AutomatedAcceptanceResult = Record<string, unknown>

declare global {
  interface Window {
    __postApprovalDomReady?: boolean
    __postApprovalDomState?: HarnessState
  }
}

const state: HarnessState = {
  pollCount: 0,
  pollInFlight: 0,
  maxPollInFlight: 0,
  suitabilityPostCount: 0,
  finalCheckPostCount: 0,
  boardRefetchCount: 0,
  modalMountCount: 0,
  appliedStateRequestIds: [],
  serverApprovalStatus: 'pending',
  documentHidden: false,
  delayNextPoll: false,
  releaseDelayedPoll: null,
  externalApprovalStartedAt: null,
  approvalLatencyMs: null,
  immediatePollLatencyMs: null,
  completedWarrantyLatencyMs: null,
  suitabilityLatencyMs: null,
  finalCheckLatencyMs: null,
}

window.__postApprovalDomState = state
const isHarnessDocumentHidden = (): boolean => state.documentHidden

const completionAction = {
  id: 701,
  action: 'completion_submitted',
  action_label: 'Tamamlama gönderildi',
  status: 'applied',
  payload: {
    checklist_gate: 'server_checked',
    checklist: { job_completed: true },
    confirmation_status: 'approved',
    ops_final_check_required: true,
  },
  created_at: '2026-08-12T12:32:00+00:00',
}

const staleApprovalAction = {
  id: 702,
  action: 'customer_otp_requested',
  action_label: 'Müşteri onayı istendi',
  status: 'submitted',
  payload: {
    dispatch_status: 'queued',
    message_payload: {
      dispatch_status: 'queued',
      approval_url: 'http://10.0.28.64:8000/service-job-confirmation/dom-token',
      message_text: 'Müşteri onay mesajı kuyruğa alındı.',
    },
  },
  created_at: '2026-08-12T12:30:00+00:00',
}

const approvalWhatsappBody = `EMAKS Prime Teknik Servis

Sayın Test Müşteri,
MRN-DOM-POST-APPROVAL numaralı işleminiz için servis tamamlandı bilgisi alınmıştır.

Onay bağlantınız:
http://10.0.28.64:8000/service-job-confirmation/dom-token`

const pendingDocuments = [
  { id: 801, field_code: 'before_photo', label: 'Öncesi', review_status: null },
  { id: 802, field_code: 'after_photo', label: 'Sonrası', review_status: null },
  { id: 803, field_code: 'warranty_document_photo', label: 'Garanti Belgesi', review_status: null },
]

const acceptedDocuments = pendingDocuments.map((document) => ({
  ...document,
  review_status: 'accepted',
  reviewed_at: '2026-08-12T12:33:00+00:00',
}))

const rejectedDocuments = pendingDocuments.map((document) => ({
  ...document,
  review_status: 'rejected',
  review_note: 'Belge tekrar yüklenmeli.',
  reviewed_at: '2026-08-12T12:33:00+00:00',
}))

const initialRequest = (id = '9001'): ServiceRequest => ({
  id,
  mrn: id === '9001' ? 'MRN-DOM-POST-APPROVAL' : 'MRN-DOM-NEWER-REQUEST',
  customer: 'Test Müşteri',
  phone: '9053****633',
  city: 'İstanbul',
  district: 'Kadıköy',
  product: 'Test Ürün',
  serialNumber: `SERIAL-${id}`,
  serviceType: 'Montaj',
  priority: 'Orta',
  technician: 'Test Usta',
  technicianId: '81',
  technicianPhone: '9054****428',
  appointment: '12.08.2026 15:00',
  status: 'Devam Ediyor',
  workflowStatus: 'Son Kontrol',
  address: 'Test adresi',
  model: 'TEST-MODEL',
  channel: 'Panel',
  notes: '',
  checklistStatus: 'tamamlandı',
  kanbanColumn: 'final_check',
  customerClosureApprovalStatus: null,
  operationControl: {
    door_photos_checked: 'compatible',
    applies_to_assignment: false,
  },
  assignmentBlockers: {
    applies_to_assignment: false,
    messages: [],
  },
  visibleSections: {
    warranty: true,
    warranty_mode: 'full',
  },
  partnerPortalActions: [completionAction, staleApprovalAction],
  partRequests: [],
  doorPhotos: [],
  fieldCompletionDocuments: pendingDocuments,
  previousFieldCompletionDocuments: [],
})

const postApprovalState = (
  requestId: number,
  approvalStatus: 'pending' | 'approved',
  documents = pendingDocuments,
  completed = false,
): PostApprovalState => ({
  request_id: requestId,
  generated_at: new Date().toISOString(),
  approval: {
    business_status: approvalStatus,
    business_label: approvalStatus === 'approved' ? 'Müşteri onayı alındı' : 'Müşteri onayı bekleniyor',
    approved_at: approvalStatus === 'approved' ? '2026-08-12T15:32:00+03:00' : null,
    terminal: approvalStatus === 'approved',
    normal_resend_allowed: approvalStatus !== 'approved',
    transport: {
      summary: approvalStatus === 'approved'
        ? 'Onay bağlantısı WhatsApp ve SMS ile gönderildi.'
        : 'Onay bağlantısı gönderim sırasında.',
      channels: {
        whatsapp: { status: approvalStatus === 'approved' ? 'sent' : 'queued', status_label: approvalStatus === 'approved' ? 'Gönderildi' : 'Kuyrukta' },
        sms: { status: approvalStatus === 'approved' ? 'sent' : 'queued', status_label: approvalStatus === 'approved' ? 'Gönderildi' : 'Kuyrukta' },
      },
    },
  },
  field_completion_documents: documents,
  completion: {
    completed,
    completed_at: completed ? '2026-08-12T15:34:00+03:00' : null,
    final_check_state: completed ? 'completed' : 'pending',
    payment_badge: {
      state: completed ? 'customer_pays_technician' : 'no_payment_required',
      label: completed ? 'Alındı' : 'Gerekmez',
      detail: completed ? 'Müşteri ödemeyi ustaya doğrudan yaptı.' : 'Bu iş için müşteri ödemesi gerekmiyor.',
      tone: completed ? 'positive' : 'neutral',
      blocks_completion: false,
    },
  },
  request: {
    id: requestId,
    status: completed ? 'Tamamlandı' : 'Devam Ediyor',
    workflow_status: completed ? 'Tamamlandı' : 'Son Kontrol',
    completed_at: completed ? '2026-08-12T15:34:00+03:00' : null,
    field_status: completed ? 'tamamlandı' : 'son_kontrol',
    field_completed_at: completed ? '2026-08-12T15:34:00+03:00' : null,
    customer_closure_approval_status: approvalStatus === 'approved' ? 'onaylandı' : null,
    customer_closure_approved_at: approvalStatus === 'approved' ? '2026-08-12T15:32:00+03:00' : null,
    field_completion_documents: documents,
  },
})

const activeWarranty: WarrantySerialResponse = {
  serial_no: 'SERIAL-9001',
  status: 'Garanti Aktif',
  warranty_started_at: '2026-08-12',
  warranty_started_at_datetime: '2026-08-12T15:34:00+03:00',
  warranty_ends_at: '2028-08-12',
  remaining_days: 731,
  warranty_period_months: 24,
  source: 'panel_completed_installation',
  installation: {
    completed_at: '2026-08-12',
    completed_at_datetime: '2026-08-12T15:34:00+03:00',
    source: 'panel',
  },
  warnings: [],
}

const jsonResponse = (payload: unknown) => new Response(JSON.stringify(payload), {
  status: 200,
  headers: { 'Content-Type': 'application/json' },
})

window.fetch = async (input, init) => {
  const url = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url

  if (url.includes('section=post-approval')) {
    state.pollCount += 1
    state.pollInFlight += 1
    state.maxPollInFlight = Math.max(state.maxPollInFlight, state.pollInFlight)
    let pollSettled = false
    const settlePoll = () => {
      if (!pollSettled) {
        pollSettled = true
        state.pollInFlight -= 1
      }
    }
    const requestId = Number(url.match(/requests\/(\d+)/)?.[1] ?? 0)
    const payload = postApprovalState(
      requestId,
      state.serverApprovalStatus,
      state.serverApprovalStatus === 'approved' ? acceptedDocuments : pendingDocuments,
    )

    try {
      if (state.delayNextPoll) {
        state.delayNextPoll = false

        return await new Promise<Response>((resolve, reject) => {
          const abort = () => {
            state.releaseDelayedPoll = null
            settlePoll()
            reject(new DOMException('Aborted', 'AbortError'))
          }

          state.releaseDelayedPoll = () => {
            state.releaseDelayedPoll = null
            init?.signal?.removeEventListener('abort', abort)
            resolve(jsonResponse(payload))
          }

          if (init?.signal?.aborted) {
            abort()
          } else {
            init?.signal?.addEventListener('abort', abort, { once: true })
          }
        })
      }

      if (init?.signal?.aborted) {
        throw new DOMException('Aborted', 'AbortError')
      }

      return jsonResponse(payload)
    } finally {
      settlePoll()
    }
  }

  if (url.includes('/field-documents/') && init?.method === 'PATCH') {
    state.suitabilityPostCount += 1
    await new Promise((resolve) => window.setTimeout(resolve, 120))
    const submitted = typeof init.body === 'string' ? JSON.parse(init.body) as { status?: string } : {}
    const documents = submitted.status === 'rejected' ? rejectedDocuments : acceptedDocuments

    return jsonResponse({
      status: 'ok',
      post_approval: postApprovalState(9001, 'approved', documents),
    })
  }

  if (url.includes('/partner-completions/') && init?.method === 'POST') {
    state.finalCheckPostCount += 1
    await new Promise((resolve) => window.setTimeout(resolve, 120))
    const canonical = postApprovalState(9001, 'approved', acceptedDocuments, true)

    return jsonResponse({
      status: 'applied',
      request: {
        ...canonical.request,
        post_approval_state: canonical,
      },
      post_approval: canonical,
      warranty: activeWarranty,
    })
  }

  return new Response('Unexpected harness request: '+url, { status: 500 })
}

function MountedDetails(props: React.ComponentProps<typeof ServiceRequestDetails>) {
  useEffect(() => {
    state.modalMountCount += 1
  }, [])

  return <ServiceRequestDetails {...props} />
}

function Harness() {
  const [request, setRequest] = useState<ServiceRequest>(() => initialRequest())
  const [currentPostApproval, setCurrentPostApproval] = useState<PostApprovalState>(() => postApprovalState(9001, 'pending'))
  const [warranty, setWarranty] = useState<WarrantySerialResponse | null>(null)
  const [isOpen, setIsOpen] = useState(true)
  const [finalCheckInFlight, setFinalCheckInFlight] = useState(false)
  const [finalCheckError, setFinalCheckError] = useState<string | null>(null)
  const [automatedResult, setAutomatedResult] = useState<AutomatedAcceptanceResult | null>(null)
  const [automationRunning, setAutomationRunning] = useState(false)
  const selectedRequestIdRef = useRef<string | null>(request.id)
  const suitabilityInFlightRef = useRef(false)
  const finalCheckInFlightRef = useRef(false)

  useEffect(() => {
    selectedRequestIdRef.current = request.id
  }, [request.id])

  const applyCanonicalState = useCallback((next: PostApprovalState) => {
    if (next.approval.business_status === 'approved' && state.externalApprovalStartedAt !== null) {
      state.approvalLatencyMs = Math.round(performance.now() - state.externalApprovalStartedAt)
      state.externalApprovalStartedAt = null
    }

    state.appliedStateRequestIds.push(next.request_id)
    state.appliedStateRequestIds.splice(0, Math.max(0, state.appliedStateRequestIds.length - 20))
    setCurrentPostApproval(next)
    setRequest((current) => current.id === String(next.request_id)
      ? applyPostApprovalRequestDelta(current, next)
      : current)
  }, [])

  usePostApprovalRevalidation({
    isOpen,
    requestId: request.id,
    businessStatus: currentPostApproval.approval.business_status,
    currentState: currentPostApproval,
    selectedRequestIdRef,
    onState: applyCanonicalState,
    isDocumentHidden: isHarnessDocumentHidden,
  })

  const handleSuitability = async (uploadId: number | string, payload: { status: 'accepted' | 'rejected', note?: string | null, apply_to_current_completion_set?: boolean }) => {
    if (suitabilityInFlightRef.current) {
      return
    }

    suitabilityInFlightRef.current = true
    const startedAt = performance.now()

    try {
      const response = await apiRequest(`/api/technical-service/requests/${request.id}/field-documents/${uploadId}/review`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      }) as { post_approval: PostApprovalState }
      applyCanonicalState(response.post_approval)
      state.suitabilityLatencyMs = Math.round(performance.now() - startedAt)
    } finally {
      suitabilityInFlightRef.current = false
    }
  }

  const handleFinalCheck = async (actionId: number | string) => {
    if (finalCheckInFlightRef.current) {
      return
    }

    finalCheckInFlightRef.current = true
    const startedAt = performance.now()
    setFinalCheckInFlight(true)
    setFinalCheckError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${request.id}/partner-completions/${actionId}/approve`, {
        method: 'POST',
        body: JSON.stringify({}),
      }) as { post_approval: PostApprovalState, warranty: WarrantySerialResponse }
      applyCanonicalState(response.post_approval)
      setWarranty(response.warranty)
      state.finalCheckLatencyMs = Math.round(performance.now() - startedAt)
    } catch (caught) {
      setFinalCheckError(caught instanceof Error ? caught.message : 'Son kontrol tamamlanamadı.')
    } finally {
      finalCheckInFlightRef.current = false
      setFinalCheckInFlight(false)
    }
  }

  const waitUntil = async (predicate: () => boolean, timeoutMs = 3000) => {
    const startedAt = performance.now()

    while (!predicate()) {
      if (performance.now() - startedAt > timeoutMs) {
        throw new Error('DOM acceptance condition timed out.')
      }

      await new Promise((resolve) => window.setTimeout(resolve, 20))
    }
  }

  const prepareApprovedScenario = async () => {
    state.serverApprovalStatus = 'approved'
    state.suitabilityLatencyMs = null
    state.finalCheckLatencyMs = null
    setWarranty(null)
    setFinalCheckError(null)
    setRequest(initialRequest())
    setCurrentPostApproval(postApprovalState(9001, 'approved'))
    await waitUntil(() => Boolean(document.querySelector('[data-testid="field-documents-overall-accepted"]')))
  }

  const runAutomatedAcceptance = async () => {
    if (automationRunning) {
      return
    }

    setAutomationRunning(true)
    setAutomatedResult(null)
    let stage = 'initialization'

    try {
      state.pollCount = 0
      state.pollInFlight = 0
      state.maxPollInFlight = 0
      state.suitabilityPostCount = 0
      state.finalCheckPostCount = 0
      state.appliedStateRequestIds = []
      state.documentHidden = false
      state.approvalLatencyMs = null
      state.immediatePollLatencyMs = null
      state.completedWarrantyLatencyMs = null
      setIsOpen(true)
      state.serverApprovalStatus = 'approved'

      stage = 'completed_initial_warranty'
      const completedWarrantyStartedAt = performance.now()
      setRequest({
        ...initialRequest(),
        status: 'Tamamlandı',
        workflowStatus: 'Tamamlandı',
        completedAt: '2026-08-12T15:34:00+03:00',
      })
      setCurrentPostApproval(postApprovalState(9001, 'approved', acceptedDocuments, true))
      setWarranty(activeWarranty)
      await waitUntil(() => Boolean(document.querySelector('[data-testid="harness-modal"]')))
      await waitUntil(() => document.body.innerText.includes('Garanti Başladı'))
      state.completedWarrantyLatencyMs = Math.round(performance.now() - completedWarrantyStartedAt)
      const completedPollStart = state.pollCount
      await new Promise((resolve) => window.setTimeout(resolve, 1100))
      const completedPollRequests = state.pollCount - completedPollStart
      const completedPollingZero = completedPollRequests === 0

      stage = 'immediate_pending_revalidation'
      state.serverApprovalStatus = 'pending'
      setWarranty(null)
      const immediatePollStart = state.pollCount
      const immediatePollStartedAt = performance.now()
      setRequest(initialRequest())
      setCurrentPostApproval(postApprovalState(9001, 'pending'))
      await waitUntil(() => state.pollCount > immediatePollStart)
      state.immediatePollLatencyMs = Math.round(performance.now() - immediatePollStartedAt)

      stage = 'hidden_poll_stop'
      state.documentHidden = true
      document.dispatchEvent(new Event('visibilitychange'))
      const hiddenPollStart = state.pollCount
      await new Promise((resolve) => window.setTimeout(resolve, 1100))
      const hiddenPollStopped = state.pollCount === hiddenPollStart

      state.documentHidden = false
      document.dispatchEvent(new Event('visibilitychange'))
      await waitUntil(() => state.pollCount > hiddenPollStart)

      stage = 'single_in_flight_revalidation'
      state.delayNextPoll = true
      const delayedPollStart = state.pollCount
      document.dispatchEvent(new Event('visibilitychange'))
      await waitUntil(() => state.pollCount > delayedPollStart)
      await new Promise((resolve) => window.setTimeout(resolve, 1100))
      const singleInFlight = state.pollCount === delayedPollStart + 1 && state.maxPollInFlight === 1
      state.releaseDelayedPoll?.()
      await waitUntil(() => state.pollInFlight === 0)

      stage = 'closed_modal_poll_stop'
      setIsOpen(false)
      await waitUntil(() => !document.querySelector('[data-testid="harness-modal"]'))
      const closedPollStart = state.pollCount
      await new Promise((resolve) => window.setTimeout(resolve, 1100))
      const closedPollStopped = state.pollCount === closedPollStart
      setIsOpen(true)
      await waitUntil(() => Boolean(document.querySelector('[data-testid="harness-modal"]')))

      stage = 'external_approval'
      state.externalApprovalStartedAt = performance.now()
      state.serverApprovalStatus = 'approved'
      await waitUntil(() => document.body.innerText.includes('Müşteri onayı alındı'))
      const completionCtaAppeared = Boolean(document.querySelector('[data-testid="final-completion-approve-button"]'))
      const terminalPollStart = state.pollCount
      await new Promise((resolve) => window.setTimeout(resolve, 1100))
      const terminalPollStopped = state.pollCount === terminalPollStart
      const approvedWithoutResend = !document.body.innerText.includes('Müşteri onayını tekrar gönder')
      const queuedDoesNotOverride = !document.body.innerText.includes('Son mesaj durumu: Kuyrukta')

      const suitabilityDurations: number[] = []
      const finalCheckDurations: number[] = []
      const acceptanceFeedback: boolean[] = []
      const finalFeedback: boolean[] = []
      const finalCompletedInSameModal: boolean[] = []
      const finalWarrantyInSameModal: boolean[] = []
      const finalPaymentBadgeConsistent: boolean[] = []
      const mutationMountCounts: number[] = []

      for (let run = 0; run < 5; run += 1) {
        stage = `accepted_suitability_${run + 1}`
        await prepareApprovedScenario()
        const mountBefore = state.modalMountCount
        const acceptedButton = document.querySelector<HTMLButtonElement>('[data-testid="field-documents-overall-accepted"]')!
        const rejectedButton = document.querySelector<HTMLButtonElement>('[data-testid="field-documents-overall-rejected"]')!
        const suitabilityPostsBefore = state.suitabilityPostCount
        acceptedButton.click()
        acceptedButton.click()
        await new Promise<void>((resolve) => window.requestAnimationFrame(() => resolve()))
        acceptanceFeedback.push(acceptedButton.textContent?.includes('Kaydediliyor') === true && !rejectedButton.textContent?.includes('Kaydediliyor'))
        await waitUntil(() => state.suitabilityLatencyMs !== null)
        suitabilityDurations.push(state.suitabilityLatencyMs!)

        if (state.suitabilityPostCount - suitabilityPostsBefore !== 1) {
          throw new Error('Suitability double click created duplicate POST.')
        }

        stage = `final_check_${run + 1}`
        const finalButton = document.querySelector<HTMLButtonElement>('[data-testid="final-completion-approve-button"]')!
        const finalPostsBefore = state.finalCheckPostCount
        finalButton.click()
        finalButton.click()
        await new Promise<void>((resolve) => window.requestAnimationFrame(() => resolve()))
        finalFeedback.push(finalButton.textContent?.includes('Tamamlanıyor') === true)
        await waitUntil(() => state.finalCheckLatencyMs !== null && Boolean(document.querySelector('[data-testid="final-check-completed-state"]')))
        finalCheckDurations.push(state.finalCheckLatencyMs!)
        finalCompletedInSameModal.push(Boolean(document.querySelector('[data-testid="final-check-completed-state"]')))
        finalWarrantyInSameModal.push(document.body.innerText.includes('Garanti Başladı'))
        finalPaymentBadgeConsistent.push(!document.body.innerText.includes('Ödeme: Bekleniyor') && document.body.innerText.includes('Alındı'))

        if (state.finalCheckPostCount - finalPostsBefore !== 1) {
          throw new Error('Final check double click created duplicate POST.')
        }

        mutationMountCounts.push(state.modalMountCount - mountBefore)
      }

      const rejectionDurations: number[] = []
      const rejectionFeedback: boolean[] = []

      for (let run = 0; run < 5; run += 1) {
        stage = `rejected_suitability_${run + 1}`
        await prepareApprovedScenario()
        const rejectionInput = document.querySelector<HTMLInputElement>('input[placeholder="Uygun değil açıklaması"]')!
        const valueSetter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set
        valueSetter?.call(rejectionInput, 'Belge tekrar yüklenmeli.')
        rejectionInput.dispatchEvent(new Event('input', { bubbles: true }))
        await waitUntil(() => document.querySelector<HTMLButtonElement>('[data-testid="field-documents-overall-rejected"]')?.disabled === false)
        const acceptedButton = document.querySelector<HTMLButtonElement>('[data-testid="field-documents-overall-accepted"]')!
        const rejectedButton = document.querySelector<HTMLButtonElement>('[data-testid="field-documents-overall-rejected"]')!
        const postsBefore = state.suitabilityPostCount
        rejectedButton.click()
        rejectedButton.click()
        await new Promise<void>((resolve) => window.requestAnimationFrame(() => resolve()))
        rejectionFeedback.push(rejectedButton.textContent?.includes('Kaydediliyor') === true && !acceptedButton.textContent?.includes('Kaydediliyor'))
        await waitUntil(() => state.suitabilityLatencyMs !== null)
        rejectionDurations.push(state.suitabilityLatencyMs!)

        if (state.suitabilityPostCount - postsBefore !== 1) {
          throw new Error('Rejected suitability double click created duplicate POST.')
        }
      }

      stage = 'stale_response_guard'
      state.appliedStateRequestIds = []
      state.serverApprovalStatus = 'pending'
      state.delayNextPoll = true
      setRequest(initialRequest())
      setCurrentPostApproval(postApprovalState(9001, 'pending'))
      document.dispatchEvent(new Event('visibilitychange'))
      window.setTimeout(() => {
        const newerRequest = initialRequest('9002')
        selectedRequestIdRef.current = newerRequest.id
        setRequest(newerRequest)
        setCurrentPostApproval(postApprovalState(9002, 'pending'))
      }, 100)
      await waitUntil(() => selectedRequestIdRef.current === '9002')
      state.releaseDelayedPoll?.()
      await new Promise((resolve) => window.setTimeout(resolve, 150))

      const median = (values: number[]) => [...values].sort((a, b) => a - b)[Math.floor(values.length / 2)]
      const max = (values: number[]) => Math.max(...values)
      const approvalUrl = 'http://10.0.28.64:8000/service-job-confirmation/dom-token'
      const approvalBodyLines = approvalWhatsappBody.split('\n')
      const approvalUrlIsOwnRawLine = approvalBodyLines.filter((line) => line === approvalUrl).length === 1
      const approvalBodyHasNoMarkdown = !/\[[^\]]+\]\([^)]+\)/u.test(approvalWhatsappBody)
      const acceptancePassed = completedPollingZero
        && (state.completedWarrantyLatencyMs ?? Number.POSITIVE_INFINITY) <= 800
        && (state.immediatePollLatencyMs ?? Number.POSITIVE_INFINITY) <= 250
        && singleInFlight
        && state.maxPollInFlight === 1
        && (state.approvalLatencyMs ?? Number.POSITIVE_INFINITY) <= 2000
        && completionCtaAppeared
        && terminalPollStopped
        && hiddenPollStopped
        && closedPollStopped
        && approvalUrlIsOwnRawLine
        && approvalBodyHasNoMarkdown
        && state.boardRefetchCount === 0
        && mutationMountCounts.every((count) => count === 0)

      setAutomatedResult({
        pass: acceptancePassed,
        completed_initial_warranty: {
          visible_latency_ms: state.completedWarrantyLatencyMs,
          approval_poll_requests: completedPollRequests,
          polling_zero: completedPollingZero,
        },
        approval: {
          latency_ms: state.approvalLatencyMs,
          immediate_check_latency_ms: state.immediatePollLatencyMs,
          one_in_flight: singleInFlight && state.maxPollInFlight === 1,
          max_in_flight: state.maxPollInFlight,
          completion_cta_same_update: completionCtaAppeared,
          approved_without_resend: approvedWithoutResend,
          queued_does_not_override: queuedDoesNotOverride,
          terminal_poll_stopped: terminalPollStopped,
        },
        whatsapp_approval_url: {
          raw_url_line_count: approvalBodyLines.filter((line) => line === approvalUrl).length,
          own_raw_line: approvalUrlIsOwnRawLine,
          markdown_absent: approvalBodyHasNoMarkdown,
        },
        lifecycle: {
          hidden_poll_stopped: hiddenPollStopped,
          closed_poll_stopped: closedPollStopped,
          stale_response_ignored: !state.appliedStateRequestIds.includes(9001) && selectedRequestIdRef.current === '9002',
          modal_remounts_per_mutation: mutationMountCounts,
          board_refetch_count: state.boardRefetchCount,
        },
        suitability_accepted: {
          runs_ms: suitabilityDurations,
          median_ms: median(suitabilityDurations),
          max_ms: max(suitabilityDurations),
          clicked_only_feedback: acceptanceFeedback.every(Boolean),
        },
        suitability_rejected: {
          runs_ms: rejectionDurations,
          median_ms: median(rejectionDurations),
          max_ms: max(rejectionDurations),
          clicked_only_feedback: rejectionFeedback.every(Boolean),
        },
        final_check: {
          runs_ms: finalCheckDurations,
          median_ms: median(finalCheckDurations),
          max_ms: max(finalCheckDurations),
          immediate_feedback: finalFeedback.every(Boolean),
          completed_in_same_modal: finalCompletedInSameModal.every(Boolean),
          active_warranty_in_same_modal: finalWarrantyInSameModal.every(Boolean),
          stale_payment_pending_absent: finalPaymentBadgeConsistent.every(Boolean),
        },
        request_counts: {
          suitability_posts: state.suitabilityPostCount,
          final_check_posts: state.finalCheckPostCount,
          board_refetches: state.boardRefetchCount,
          max_poll_in_flight: state.maxPollInFlight,
        },
      })
    } catch (caught) {
      setAutomatedResult({
        pass: false,
        stage,
        error: caught instanceof Error ? caught.message : String(caught),
      })
    } finally {
      setAutomationRunning(false)
    }
  }

  return (
    <main className="min-h-screen bg-slate-100 p-4">
      <div className="mb-3 flex flex-wrap gap-2" data-testid="harness-controls">
        <button type="button" data-testid="simulate-external-approval" onClick={() => {
          state.externalApprovalStartedAt = performance.now()
          state.serverApprovalStatus = 'approved'
        }}>Dış onayı simüle et</button>
        <button type="button" data-testid="set-document-hidden" onClick={() => {
          state.documentHidden = true
          document.dispatchEvent(new Event('visibilitychange'))
        }}>Sekmeyi gizle</button>
        <button type="button" data-testid="set-document-visible" onClick={() => {
          state.documentHidden = false
          document.dispatchEvent(new Event('visibilitychange'))
        }}>Sekmeyi göster</button>
        <button type="button" data-testid="close-harness-modal" onClick={() => setIsOpen(false)}>Modalı kapat</button>
        <button type="button" data-testid="open-harness-modal" onClick={() => setIsOpen(true)}>Modalı aç</button>
        <button type="button" data-testid="start-stale-response" onClick={() => {
          state.delayNextPoll = true
          state.serverApprovalStatus = 'pending'
          setCurrentPostApproval(postApprovalState(9001, 'pending'))
          window.setTimeout(() => {
            const newerRequest = initialRequest('9002')
            selectedRequestIdRef.current = newerRequest.id
            setRequest(newerRequest)
            setCurrentPostApproval(postApprovalState(9002, 'pending'))
          }, 100)
        }}>Stale response senaryosu</button>
        <button type="button" data-testid="release-stale-response" onClick={() => state.releaseDelayedPoll?.()}>Eski cevabı bırak</button>
        <button type="button" data-testid="run-automated-acceptance" disabled={automationRunning} onClick={() => void runAutomatedAcceptance()}>
          {automationRunning ? 'DOM testleri çalışıyor...' : 'DOM testlerini çalıştır'}
        </button>
      </div>
      <output data-testid="poll-count">{state.pollCount}</output>
      <output data-testid="suitability-post-count">{state.suitabilityPostCount}</output>
      <output data-testid="final-check-post-count">{state.finalCheckPostCount}</output>
      <output data-testid="board-refetch-count">{state.boardRefetchCount}</output>
      <output data-testid="modal-mount-count">{state.modalMountCount}</output>
      <output data-testid="selected-request-id">{request.id}</output>
      <output data-testid="applied-request-ids">{state.appliedStateRequestIds.join(',')}</output>
      <output data-testid="approval-latency-ms">{state.approvalLatencyMs ?? ''}</output>
      <output data-testid="immediate-poll-latency-ms">{state.immediatePollLatencyMs ?? ''}</output>
      <output data-testid="completed-warranty-latency-ms">{state.completedWarrantyLatencyMs ?? ''}</output>
      <output data-testid="suitability-latency-ms">{state.suitabilityLatencyMs ?? ''}</output>
      <output data-testid="final-check-latency-ms">{state.finalCheckLatencyMs ?? ''}</output>
      <pre data-testid="customer-approval-whatsapp-preview" className="whitespace-pre-wrap">{approvalWhatsappBody}</pre>
      <pre data-testid="automated-result">{automatedResult ? JSON.stringify(automatedResult) : ''}</pre>
      {isOpen ? (
        <section data-testid="harness-modal" className="mt-3 h-[720px] overflow-y-auto rounded-lg bg-white p-3">
          <MountedDetails
            request={request}
            events={[]}
            loading={false}
            displayMrn={request.mrn}
            warranty={warranty}
            warrantyLoading={false}
            postApprovalState={currentPostApproval}
            onCustomerApprovalResend={async () => undefined}
            onFieldDocumentReview={handleSuitability}
            onPartnerCompletionApprove={handleFinalCheck}
            partnerCompletionApproveInFlight={finalCheckInFlight}
            partnerCompletionApproveError={finalCheckError}
          />
        </section>
      ) : null}
    </main>
  )
}

createRoot(document.getElementById('root')!).render(<Harness />)
window.__postApprovalDomReady = true

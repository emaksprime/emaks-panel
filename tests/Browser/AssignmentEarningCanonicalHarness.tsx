import { useRef, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { ServiceRequestDetails } from '../../resources/js/components/technical-service/ServiceRequestDetails'
import type { ServiceRequest, ServiceRequestCanonicalEarningSnapshot, ServiceRequestCompanyPaymentDecisionSubmit, ServiceRequestTechnicianEarningMessagePayload } from '../../resources/js/components/technical-service/types'
import '../../resources/css/app.css'

type HarnessState = {
  saveCount: number
  sendCount: number
  assignmentActionCount: number
  assignmentPopupCount: number
  assignmentConfirmCount: number
  completionApproveCount: number
  allocationSubmitCount: number
  boardRefetchCount: number
  modalMountCount: number
  failNextSave: boolean
  lastSavePayload: {
    labor_amount: number
    route_fee_amount: number
    expected_earning_revision: string
    note?: string | null
  } | null
  lastSavedSnapshot: ServiceRequestCanonicalEarningSnapshot | null
  lastSendPayload: ServiceRequestTechnicianEarningMessagePayload | null
  lastCompanyPaymentDecision: 'pay_technician' | 'retain_company' | null
  lastCompanyPaymentDecisionPayload: ServiceRequestCompanyPaymentDecisionSubmit[] | null
}

declare global {
  interface Window {
    __assignmentEarningDomReady?: boolean
    __assignmentEarningDomState?: HarnessState
  }
}

const initialRevision = 'a'.repeat(64)
const savedRevision = 'b'.repeat(64)
const companyPaymentRevision = 'c'.repeat(64)
const companyPaymentMessageRevision = 'd'.repeat(64)
const historicalWrongMessageRevision = companyPaymentMessageRevision
const initialPreview = [
  'Merhaba Test Usta,',
  '',
  'MRN-DOM-EARNING numaralı iş için hakedişiniz güncellendi.',
  '',
  'İşçilik: 3.000,00 TL',
  'Toplam hakedişiniz: 3.000,00 TL',
  '',
  'Hakedişiniz EMAKS Prime tarafından yapılacaktır.',
  '',
  'İş kartınız:',
  'http://192.168.1.10:8000/partner/job/dom-token',
].join('\n')

const initialRequest: ServiceRequest = {
  id: '9001',
  mrn: 'MRN-DOM-EARNING',
  customer: 'Test Müşteri',
  phone: '9053****633',
  city: 'İstanbul',
  district: 'Kadıköy',
  product: 'Test Ürün',
  serialNumber: 'SERI-DOM',
  serviceType: 'Montaj',
  priority: 'Orta',
  technician: 'Test Usta',
  technicianId: '111',
  technicianPhone: '9054****428',
  appointment: '-',
  status: 'Atandı',
  workflowStatus: 'Usta Onayı Bekleyen',
  address: 'Test adresi',
  model: 'Test Model',
  channel: 'Web',
  notes: '',
  technicianPaymentAmount: 3000,
  travelFeeAmount: 0,
  operationControl: {
    applies_to_assignment: false,
  },
  assignmentBlockers: {
    applies_to_assignment: false,
    messages: [],
  },
  assignmentOffer: {
    id: 104,
    technical_service_request_id: 9001,
    technical_service_technician_id: 111,
    technician_name: 'Test Usta',
    labor_amount: 3000,
    route_fee_amount: 0,
    total_amount: 3000,
    currency: 'TRY',
    status: 'sent',
    note: null,
    earning_snapshot: {
      schema_version: 1,
      assignment_id: 104,
      technician_id: 111,
      labor_amount: 3000,
      route_fee_amount: 0,
      total_amount: 3000,
      currency: 'TRY',
      operation_note: null,
      revision: initialRevision,
      persisted_at: '2026-08-10T05:00:00+00:00',
    },
    message_preview: initialPreview,
    message_text: initialPreview,
  },
  technicianJobCard: {
    ready: true,
    technician_id: 111,
    canonical_url: 'http://192.168.1.10:8000/partner/job/dom-token',
    ops_support_url: 'http://192.168.1.10:8000/ops/job/dom-token',
    preview_url: 'http://192.168.1.10:8000/partner/job/dom-token',
  },
  partnerPortalActions: [],
  partRequests: [],
  doorPhotos: [],
  fieldCompletionDocuments: [],
  previousFieldCompletionDocuments: [],
}

const staleSrvRouteRequest = (): ServiceRequest => {
  const requestCode = 'SRV-2607SP070002-001'
  const snapshot: ServiceRequestCanonicalEarningSnapshot = {
    schema_version: 3,
    assignment_id: 104,
    technician_id: 111,
    labor_amount: 100,
    route_fee_amount: 1406.5,
    base_total_amount: 1506.5,
    company_payment_amount: 0,
    company_payment_breakdown: [],
    total_amount: 1506.5,
    technician_paid_amount: 0,
    technician_remaining_amount: 1506.5,
    payer_state: 'company_collected_company_pays_technician',
    payer_state_key: 'company_collected_company_pays_technician',
    technician_payment_source_label: 'EMAKS Prime',
    technician_payment_status_key: 'payable',
    technician_payment_status_label: 'Ödenecek',
    currency: 'TRY',
    operation_note: null,
    revision: initialRevision,
    snapshot_hash: initialRevision,
    persisted_at: '2026-08-11T08:00:00+00:00',
  }

  return {
    ...initialRequest,
    mrn: requestCode,
    technicianPaymentAmount: 100,
    travelFeeAmount: 1406.5,
    assignmentOffer: {
      ...initialRequest.assignmentOffer!,
      labor_amount: 100,
      route_fee_amount: 1406.5,
      total_amount: 1506.5,
      earning_snapshot: snapshot,
      message_preview: canonicalPreview(snapshot, requestCode),
      message_text: canonicalPreview(snapshot, requestCode),
    },
  }
}

const companyPaymentRequest = (): ServiceRequest => ({
  ...initialRequest,
  workflowStatus: 'Son Kontrol',
  kanbanColumn: 'final_check',
  checklistStatus: 'tamamlandı',
  customerClosureApprovalStatus: 'onaylandı',
  settlement: {
    id: 501,
    technical_service_request_id: 9001,
    technical_service_assignment_offer_id: 104,
    technical_service_technician_id: 111,
    currency: 'TRY',
    labor_earning_amount: 3000,
    route_earning_amount: 0,
    technician_earning_total: 3000,
    company_payment_amount: 0,
    company_payment_breakdown: [],
    company_retained_amount: 0,
    company_retained_breakdown: [],
    company_payment_decisions: {
      schema_version: 1,
      eligible_items: [{
        payment_id: 196,
        payment_purpose: 'service_payment',
        payment_purpose_label: 'Ek servis',
        provider: 'iyzico',
        provider_label: 'Iyzico Sandbox',
        paid_at: '2026-08-10T06:00:00+00:00',
        source_paid_amount: 1000,
        source_paid_amount_label: '1.000,00 TL',
        covered_amount: 0,
        covered_amount_label: '0,00 TL',
        previously_allocated_amount: 0,
        previously_allocated_amount_label: '0,00 TL',
        eligible_amount: 1000,
        eligible_amount_label: '1.000,00 TL',
        currency: 'TRY',
        request_id: 9001,
        root_request_id: 9001,
        current_srv_id: 9001,
        mrn_or_srv: 'MRN-DOM-EARNING',
        assignment_id: 104,
        technician_id: 111,
        technician_name: 'Test Usta',
        can_pay_technician: true,
        disabled_reason: null,
      }],
      decisions: [],
      eligible_count: 1,
      pending_decision_count: 1,
      all_decisions_required: true,
      context_ready: true,
      context_blocker: null,
      earning_revision: initialRevision,
      visit_count_used: false,
    },
    customer_collection_amount: 4000,
    company_payable_amount: 3000,
    company_paid_amount: 0,
    company_remaining_amount: 3000,
  },
  financeSummary: {
    generated_at: '2026-08-10T06:00:00+00:00',
    currency: 'TRY',
    scope: {
      request_id: 9001,
      root_request_id: 9001,
      current_srv_id: 9001,
      request_code: 'MRN-DOM-EARNING',
      root_mrn: 'MRN-DOM-EARNING',
      scope_type: 'current_job',
    },
    technician: {
      technician_id: 111,
      technician_name: 'Test Usta',
      assignment_id: 104,
    },
    history: {
      loaded: false,
      current_count: null,
      root_count: null,
    },
    current_visit: {
      customer_collection: {
        mount_amount: 3000,
        service_amount: 0,
        extra_amount: 1000,
        route_amount: 0,
        part_amount: 0,
        unclassified_amount: 0,
        service_total_amount: 4000,
        total_amount: 4000,
        mount_amount_label: '3.000,00 TL',
        extra_amount_label: '1.000,00 TL',
        service_total_amount_label: '4.000,00 TL',
        total_amount_label: '4.000,00 TL',
        has_collection: true,
      },
      locksmith_payout: {
        labor_amount: 3000,
        route_fee_amount: 0,
        company_payment_amount: 0,
        total_amount: 3000,
        technician_paid_amount: 0,
        technician_remaining_amount: 3000,
        labor_amount_label: '3.000,00 TL',
        route_fee_amount_label: '0,00 TL',
        company_payment_amount_label: '0,00 TL',
        total_amount_label: '3.000,00 TL',
        technician_paid_amount_label: '0,00 TL',
        technician_remaining_amount_label: '3.000,00 TL',
        payout_status: 'confirmed',
        payout_status_label: 'Onaylanan usta hakedişi',
        technician_id: 111,
        technician_name: 'Test Usta',
      },
      company_payment_amount: 0,
      company_payment_amount_label: '0,00 TL',
      company_retained_amount: 0,
      company_retained_amount_label: '0,00 TL',
      company_payment_decisions: null,
      net_margin: {
        amount: 1000,
        amount_label: 'Hesap bekliyor',
        provisional_amount_label: '1.000,00 TL',
        is_definitive: false,
      },
      result_state: 'allocation_pending',
      result_state_label: 'Ek tahsilat dağıtım kararı bekliyor',
      is_definitive: false,
    },
    root_total: {
      customer_collection: {
        mount_amount: 3000,
        service_amount: 0,
        extra_amount: 1000,
        route_amount: 0,
        part_amount: 0,
        unclassified_amount: 0,
        service_total_amount: 4000,
        total_amount: 4000,
        service_total_amount_label: '4.000,00 TL',
        total_amount_label: '4.000,00 TL',
        has_collection: true,
      },
      locksmith_payout: {
        labor_amount: 3000,
        route_fee_amount: 0,
        company_payment_amount: 0,
        total_amount: 3000,
        technician_paid_amount: 0,
        technician_remaining_amount: 3000,
        total_amount_label: '3.000,00 TL',
        technician_paid_amount_label: '0,00 TL',
        technician_remaining_amount_label: '3.000,00 TL',
      },
      company_payment_amount: 0,
      company_payment_amount_label: '0,00 TL',
      company_retained_amount: 0,
      company_retained_amount_label: '0,00 TL',
      net_margin: {
        amount: 1000,
        amount_label: '1.000,00 TL',
        provisional_amount_label: '1.000,00 TL',
        is_definitive: true,
      },
      result_state: 'definitive',
      result_state_label: 'Kesinleşmiş',
      is_definitive: true,
    },
  },
  partnerPortalActions: [{
    id: 801,
    action: 'completion_submitted',
    action_label: 'Tamamlamaya gönderildi',
    status: 'ops_review',
    payload: {
      checklist_gate: 'server_checked',
      checklist: {
        installation_complete: true,
      },
    },
    created_at: '2026-08-10T06:05:00+00:00',
  }],
  fieldCompletionDocuments: [
    { id: 901, field_code: 'before_photo', label: 'Öncesi', review_status: 'accepted' },
    { id: 902, field_code: 'after_photo', label: 'Sonrası', review_status: 'accepted' },
    { id: 903, field_code: 'warranty_document_photo', label: 'Garanti Belgesi', review_status: 'accepted' },
  ],
})

const companyPaymentMessageRequest = (): ServiceRequest => {
  const snapshot: ServiceRequestCanonicalEarningSnapshot = {
    schema_version: 3,
    assignment_id: 104,
    technician_id: 111,
    labor_amount: 3000,
    route_fee_amount: 1400,
    base_total_amount: 4400,
    company_payment_amount: 600,
    company_payment_breakdown: [{
      line_id: 11,
      payment_id: 198,
      purpose: 'service_payment',
      purpose_label: 'Ek servis',
      source: 'extra_service',
      amount: 600,
      amount_label: '600,00 TL',
      status: 'payable',
      status_label: 'Ödenecek',
    }],
    total_amount: 5000,
    technician_paid_amount: 0,
    technician_remaining_amount: 5000,
    customer_collection_amount: 5000,
    payer_state: 'company_collected_company_pays_technician',
    payer_state_key: 'company_collected_company_pays_technician',
    technician_payment_model_label: 'Şirket ödemesi',
    technician_payment_source_label: 'EMAKS Prime',
    technician_payment_status_key: 'payable',
    technician_payment_status_label: 'Ödenecek',
    customer_collection_source_label: 'EMAKS Prime tarafından alındı',
    currency: 'TRY',
    operation_note: null,
    revision: companyPaymentMessageRevision,
    snapshot_hash: companyPaymentMessageRevision,
    persisted_at: '2026-08-11T06:30:00+00:00',
  }
  const historicalSnapshot: ServiceRequestCanonicalEarningSnapshot = {
    ...snapshot,
    revision: historicalWrongMessageRevision,
    snapshot_hash: historicalWrongMessageRevision,
  }

  return {
    ...initialRequest,
    technicianPaymentAmount: 3000,
    travelFeeAmount: 1400,
    assignmentOffer: {
      ...initialRequest.assignmentOffer!,
      route_fee_amount: 1400,
      total_amount: 5000,
      earning_snapshot: snapshot,
      message_preview: canonicalPreview(snapshot),
      message_text: canonicalPreview(snapshot),
    },
    saleAndPayment: {
      technician_earning_message: {
        status: 'sent',
        sent_at: '2026-08-11T06:00:00+00:00',
        technician_id: 111,
        technician_name: 'Test Usta',
        labor_amount: 3000,
        route_fee_amount: 1400,
        base_total_amount: 4400,
        company_payment_amount: 600,
        company_payment_breakdown: snapshot.company_payment_breakdown,
        total_amount: 5000,
        earning_snapshot_revision: historicalWrongMessageRevision,
        earning_snapshot: historicalSnapshot,
        message_text: 'Montaj işçilik: 3.000,00 TL\nUsta yol hakedişi: 1.400,00 TL\nToplam hakediş: 4.400,00 TL',
      },
    },
  }
}

const state: HarnessState = {
  saveCount: 0,
  sendCount: 0,
  assignmentActionCount: 0,
  assignmentPopupCount: 0,
  assignmentConfirmCount: 0,
  completionApproveCount: 0,
  allocationSubmitCount: 0,
  boardRefetchCount: 0,
  modalMountCount: 0,
  failNextSave: false,
  lastSavePayload: null,
  lastSavedSnapshot: null,
  lastSendPayload: null,
  lastCompanyPaymentDecision: null,
  lastCompanyPaymentDecisionPayload: null,
}

window.__assignmentEarningDomState = state

function canonicalPreview(snapshot: ServiceRequestCanonicalEarningSnapshot, requestCode = 'MRN-DOM-EARNING'): string {
  const money = (value: number) => `${new Intl.NumberFormat('tr-TR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value)} TL`

  const companyPaymentLines = (snapshot.company_payment_breakdown ?? [])
    .filter((line) => Number(line.amount ?? 0) > 0)
    .map((line) => {
      const label = line.purpose === 'service_payment' || line.purpose === 'extra_service'
        ? 'Ek servis'
        : line.purpose === 'route_fee' || line.purpose === 'route_difference'
          ? 'Yol farkı'
          : line.purpose === 'additional_labor'
            ? 'Ek işçilik'
            : 'Bilinmeyen kalem'

      return `${label}: ${money(line.amount)}`
    })
  const paymentSentence = snapshot.payer_state === 'customer_pays_technician'
    ? 'Hakedişiniz müşteri tarafından ödenecektir.'
    : snapshot.technician_payment_status_key === 'paid'
        && Number(snapshot.technician_paid_amount ?? 0) >= Number(snapshot.total_amount ?? 0)
        && Number(snapshot.technician_remaining_amount ?? 0) <= 0
      ? 'Hakediş ödemeniz EMAKS Prime tarafından yapılmıştır.'
      : 'Hakedişiniz EMAKS Prime tarafından yapılacaktır.'

  if (Number(snapshot.total_amount ?? 0) <= 0) {
    return [
      'Merhaba Test Usta,',
      '',
      `${requestCode} numaralı iş için hakedişiniz güncellendi.`,
      '',
      'Bu iş için hakediş 0 TL olarak belirlenmiştir.',
      '',
      'İş kartınız:',
      'http://192.168.1.10:8000/partner/job/dom-token',
    ].join('\n')
  }

  return [
    'Merhaba Test Usta,',
    '',
    `${requestCode} numaralı iş için hakedişiniz güncellendi.`,
    '',
    ...(Number(snapshot.labor_amount ?? 0) > 0 ? [`İşçilik: ${money(snapshot.labor_amount)}`] : []),
    ...(Number(snapshot.route_fee_amount ?? 0) > 0 ? [`Yol: ${money(snapshot.route_fee_amount)}`] : []),
    ...companyPaymentLines,
    `Toplam hakedişiniz: ${money(snapshot.total_amount)}`,
    '',
    paymentSentence,
    '',
    'İş kartınız:',
    'http://192.168.1.10:8000/partner/job/dom-token',
  ].join('\n')
}

function Harness() {
  const [modalMountId] = useState(() => {
    state.modalMountCount += 1

    return state.modalMountCount
  })
  const [request, setRequest] = useState(initialRequest)
  const [saveCount, setSaveCount] = useState(0)
  const [lastSavePayload, setLastSavePayload] = useState<HarnessState['lastSavePayload']>(null)
  const [sendCount, setSendCount] = useState(0)
  const [saveInFlight, setSaveInFlight] = useState(false)
  const [assignmentActionCount, setAssignmentActionCount] = useState(0)
  const [assignmentPopupOpen, setAssignmentPopupOpen] = useState(false)
  const [assignmentPopupCount, setAssignmentPopupCount] = useState(0)
  const [assignmentConfirmCount, setAssignmentConfirmCount] = useState(0)
  const [allocationSubmitCount, setAllocationSubmitCount] = useState(0)
  const [lastAllocationPayload, setLastAllocationPayload] = useState<ServiceRequestCompanyPaymentDecisionSubmit[] | null>(null)
  const [allocationError, setAllocationError] = useState<string | null>(null)
  const [lastSendRevision, setLastSendRevision] = useState('')
  const [lastSendPayload, setLastSendPayload] = useState<ServiceRequestTechnicianEarningMessagePayload | null>(null)
  const [failNextSave, setFailNextSave] = useState(false)
  const saveLock = useRef(false)
  const assignmentPopupOpenRef = useRef(false)

  return (
    <>
      <button
        type="button"
        data-testid="load-company-payment-scenario"
        onClick={() => {
          state.saveCount = 0
          state.completionApproveCount = 0
          state.allocationSubmitCount = 0
          state.boardRefetchCount = 0
          state.lastCompanyPaymentDecision = null
          state.lastCompanyPaymentDecisionPayload = null
          setSaveCount(0)
          setAllocationSubmitCount(0)
          setLastAllocationPayload(null)
          setAllocationError(null)
          setRequest(companyPaymentRequest())
        }}
      >Uygun şirket ödemesi senaryosunu yükle</button>
      <button
        type="button"
        data-testid="load-company-payment-message-scenario"
        onClick={() => {
          state.sendCount = 0
          state.lastSendPayload = null
          state.boardRefetchCount = 0
          setSendCount(0)
          setLastSendRevision('')
          setLastSendPayload(null)
          setRequest(companyPaymentMessageRequest())
        }}
      >Şirket ödemeli mesaj senaryosunu yükle</button>
      <button
        type="button"
        data-testid="load-stale-srv-route-scenario"
        onClick={() => {
          state.saveCount = 0
          state.assignmentActionCount = 0
          state.assignmentPopupCount = 0
          state.assignmentConfirmCount = 0
          state.boardRefetchCount = 0
          state.lastSavePayload = null
          assignmentPopupOpenRef.current = false
          setSaveCount(0)
          setLastSavePayload(null)
          setAssignmentActionCount(0)
          setAssignmentPopupCount(0)
          setAssignmentConfirmCount(0)
          setAssignmentPopupOpen(false)
          setAllocationError(null)
          setRequest(staleSrvRouteRequest())
        }}
      >Stale SRV yol senaryosunu yükle</button>
      <button
        type="button"
        data-testid="earning-fail-next-save"
        onClick={() => {
          state.failNextSave = true
          setFailNextSave(true)
        }}
      >Sonraki kaydı başarısız yap</button>
      <output data-testid="earning-fail-next-save-state" className="sr-only">{failNextSave ? 'true' : 'false'}</output>
      <output data-testid="earning-save-count" className="sr-only">{saveCount}</output>
      <output data-testid="earning-last-save-payload" className="sr-only">{JSON.stringify(lastSavePayload)}</output>
      <output data-testid="earning-send-count" className="sr-only">{sendCount}</output>
      <output data-testid="assignment-action-count" className="sr-only">{assignmentActionCount}</output>
      <output data-testid="assignment-popup-count" className="sr-only">{assignmentPopupCount}</output>
      <output data-testid="assignment-confirm-count" className="sr-only">{assignmentConfirmCount}</output>
      <output data-testid="earning-last-send-revision" className="sr-only">{lastSendRevision}</output>
      <output data-testid="earning-last-send-payload" className="sr-only">{JSON.stringify(lastSendPayload)}</output>
      <output data-testid="company-payment-decision-submit-count" className="sr-only">{allocationSubmitCount}</output>
      <output data-testid="company-payment-decision-last-payload" className="sr-only">{JSON.stringify(lastAllocationPayload)}</output>
      <output data-testid="financial-board-refetch-count" className="sr-only">{state.boardRefetchCount}</output>
      <output data-testid="financial-modal-mount-count" className="sr-only">{modalMountId}</output>
      <ServiceRequestDetails
        request={request}
        events={[]}
        loading={false}
        displayMrn={request.mrn}
        technicianSuggestions={[{
        id: '111',
        name: 'Test Usta',
        location: 'İstanbul',
        phone: '9054****428',
        distanceKmLabel: '0 km',
        scheduledCount: 0,
        availableSlots: [],
        technicianAmountLabel: '3.000,00 TL',
        technicianAmountSourceLabel: 'Canonical assignment',
        travelAmountLabel: '0,00 TL',
        totalCostLabel: '3.000,00 TL',
        costDeltaLabel: '0,00 TL',
        recommended: true,
        }]}
        selectedTechnicianId="111"
        canSubmitAssign
        onAssignSelectedTechnician={() => {
          state.assignmentActionCount += 1
          setAssignmentActionCount(state.assignmentActionCount)

          if (!assignmentPopupOpenRef.current) {
            assignmentPopupOpenRef.current = true
            state.assignmentPopupCount += 1
            setAssignmentPopupCount(state.assignmentPopupCount)
            setAssignmentPopupOpen(true)
          }
        }}
        onAssignmentOfferUpdate={async (_offerId, payload) => {
          if (saveLock.current) {
            return
          }

          saveLock.current = true
          setSaveInFlight(true)
          setAllocationError(null)
          state.saveCount += 1
          state.lastSavePayload = payload
          setSaveCount(state.saveCount)
          setLastSavePayload(payload)

          await new Promise((resolve) => window.setTimeout(resolve, 50))

          if (failNextSave) {
            state.failNextSave = false
            setFailNextSave(false)
            setAllocationError('Hakediş kaydedilemedi. Girdiğiniz tutarlar korunuyor.')
            saveLock.current = false
            setSaveInFlight(false)

            throw new Error('Hakediş kaydedilemedi. Girdiğiniz tutarlar korunuyor.')
          }

          const companyPaymentDecision = payload.company_payment_decisions?.[0]?.decision ?? null
          const companyPaymentAmount = companyPaymentDecision === 'pay_technician' ? 1000 : 0
          const companyRetainedAmount = companyPaymentDecision === 'retain_company' ? 1000 : 0
          const payoutTotal = payload.labor_amount + payload.route_fee_amount + companyPaymentAmount
          const netMargin = 4000 - payoutTotal
          const snapshot: ServiceRequestCanonicalEarningSnapshot = {
            schema_version: companyPaymentDecision ? 2 : 1,
            assignment_id: 104,
            technician_id: 111,
            labor_amount: payload.labor_amount,
            route_fee_amount: payload.route_fee_amount,
            base_total_amount: payload.labor_amount + payload.route_fee_amount,
            company_payment_amount: companyPaymentAmount,
            company_payment_breakdown: companyPaymentDecision === 'pay_technician' ? [{
              line_id: 9901,
              payment_id: 196,
              purpose: 'service_payment',
              purpose_label: 'Ek servis',
              source: 'extra_service',
              amount: 1000,
              amount_label: '1.000,00 TL',
              status: 'payable',
              status_label: 'Ödenecek',
            }] : [],
            total_amount: payoutTotal,
            technician_paid_amount: 0,
            technician_remaining_amount: payoutTotal,
            payer_state: payoutTotal > 0 ? 'company_collected_company_pays_technician' : 'no_payment_required',
            payer_state_key: payoutTotal > 0 ? 'company_collected_company_pays_technician' : 'no_payment_required',
            technician_payment_source_label: payoutTotal > 0 ? 'EMAKS Prime' : null,
            technician_payment_status_key: payoutTotal > 0 ? 'payable' : 'not_required',
            technician_payment_status_label: payoutTotal > 0 ? 'Ödenecek' : 'Gerekli değil',
            currency: 'TRY',
            operation_note: payload.note ?? null,
            revision: companyPaymentDecision ? companyPaymentRevision : savedRevision,
            snapshot_hash: companyPaymentDecision ? companyPaymentRevision : savedRevision,
            persisted_at: '2026-08-10T05:30:00+00:00',
          }
          const messagePreview = canonicalPreview(snapshot, request.mrn)
          const decidedCompanyPaymentPayload = request.settlement?.company_payment_decisions
            ? companyPaymentDecision ? {
              ...request.settlement.company_payment_decisions,
              eligible_items: [],
              decisions: [{
              allocation_id: 9902,
              payment_id: 196,
              payment_purpose: 'service_payment',
              payment_purpose_label: 'Ek servis',
              decision: companyPaymentDecision,
              decision_label: companyPaymentDecision === 'pay_technician' ? 'Ustaya ödenecek' : 'Şirkette bırak',
              eligible_amount: 1000,
              eligible_amount_label: '1.000,00 TL',
              settlement_line_id: companyPaymentDecision === 'pay_technician' ? 9901 : null,
              status: companyPaymentDecision === 'pay_technician' ? 'payable' : 'retained',
              }],
              eligible_count: 0,
              pending_decision_count: 0,
              pending_decision_amount: 0,
              pending_decision_amount_label: '0,00 TL',
              earning_revision: snapshot.revision,
            } : {
              ...request.settlement.company_payment_decisions,
              earning_revision: snapshot.revision,
            }
            : null
          const allocationPending = Boolean(decidedCompanyPaymentPayload?.pending_decision_count)
          const nextFinanceSummary = request.financeSummary ? {
            ...request.financeSummary,
            generated_at: '2026-08-10T06:30:00+00:00',
            current_visit: {
              ...request.financeSummary.current_visit,
              locksmith_payout: {
                ...request.financeSummary.current_visit.locksmith_payout,
                labor_amount: snapshot.labor_amount,
                route_fee_amount: snapshot.route_fee_amount,
                company_payment_amount: companyPaymentAmount,
                company_payment_breakdown: snapshot.company_payment_breakdown,
                company_retained_amount: companyRetainedAmount,
                total_amount: payoutTotal,
                technician_remaining_amount: payoutTotal,
                labor_amount_label: `${snapshot.labor_amount.toLocaleString('tr-TR')},00 TL`,
                route_fee_amount_label: `${snapshot.route_fee_amount.toLocaleString('tr-TR')},00 TL`,
                company_payment_amount_label: `${companyPaymentAmount.toLocaleString('tr-TR')},00 TL`,
                company_retained_amount_label: `${companyRetainedAmount.toLocaleString('tr-TR')},00 TL`,
                total_amount_label: `${payoutTotal.toLocaleString('tr-TR')},00 TL`,
                technician_remaining_amount_label: `${payoutTotal.toLocaleString('tr-TR')},00 TL`,
              },
              company_payment_amount: companyPaymentAmount,
              company_payment_amount_label: `${companyPaymentAmount.toLocaleString('tr-TR')},00 TL`,
              company_retained_amount: companyRetainedAmount,
              company_retained_amount_label: `${companyRetainedAmount.toLocaleString('tr-TR')},00 TL`,
              company_payment_decisions: decidedCompanyPaymentPayload,
              net_margin: {
                amount: netMargin,
                amount_label: allocationPending ? 'Hesap bekliyor' : `${netMargin.toLocaleString('tr-TR')},00 TL`,
                provisional_amount_label: `${netMargin.toLocaleString('tr-TR')},00 TL`,
                is_definitive: !allocationPending,
              },
              result_state: allocationPending ? 'allocation_pending' : 'definitive',
              result_state_label: allocationPending ? 'Ek tahsilat dağıtım kararı bekliyor' : 'Kesinleşmiş',
              is_definitive: !allocationPending,
            },
            root_total: {
              ...request.financeSummary.root_total,
              locksmith_payout: {
                ...request.financeSummary.root_total.locksmith_payout,
                labor_amount: snapshot.labor_amount,
                route_fee_amount: snapshot.route_fee_amount,
                company_payment_amount: companyPaymentAmount,
                company_payment_breakdown: snapshot.company_payment_breakdown,
                company_retained_amount: companyRetainedAmount,
                total_amount: payoutTotal,
                technician_remaining_amount: payoutTotal,
                labor_amount_label: `${snapshot.labor_amount.toLocaleString('tr-TR')},00 TL`,
                route_fee_amount_label: `${snapshot.route_fee_amount.toLocaleString('tr-TR')},00 TL`,
                company_payment_amount_label: `${companyPaymentAmount.toLocaleString('tr-TR')},00 TL`,
                company_retained_amount_label: `${companyRetainedAmount.toLocaleString('tr-TR')},00 TL`,
                total_amount_label: `${payoutTotal.toLocaleString('tr-TR')},00 TL`,
                technician_remaining_amount_label: `${payoutTotal.toLocaleString('tr-TR')},00 TL`,
              },
              company_payment_amount: companyPaymentAmount,
              company_payment_amount_label: `${companyPaymentAmount.toLocaleString('tr-TR')},00 TL`,
              company_retained_amount: companyRetainedAmount,
              company_retained_amount_label: `${companyRetainedAmount.toLocaleString('tr-TR')},00 TL`,
              net_margin: {
                amount: netMargin,
                amount_label: allocationPending ? 'Hesap bekliyor' : `${netMargin.toLocaleString('tr-TR')},00 TL`,
                provisional_amount_label: `${netMargin.toLocaleString('tr-TR')},00 TL`,
                is_definitive: !allocationPending,
              },
              result_state: allocationPending ? 'allocation_pending' : 'definitive',
              result_state_label: allocationPending ? 'Ek tahsilat dağıtım kararı bekliyor' : 'Kesinleşmiş',
              is_definitive: !allocationPending,
            },
          } : null
          const nextRequest: ServiceRequest = {
            ...request,
            technicianPaymentAmount: snapshot.labor_amount,
            travelFeeAmount: snapshot.route_fee_amount,
            assignmentOffer: request.assignmentOffer ? {
              ...request.assignmentOffer,
              labor_amount: snapshot.labor_amount,
              route_fee_amount: snapshot.route_fee_amount,
              total_amount: snapshot.total_amount,
              note: snapshot.operation_note,
              earning_snapshot: snapshot,
              message_preview: messagePreview,
              message_text: messagePreview,
            } : null,
            settlement: request.settlement ? {
              ...request.settlement,
              technician_earning_total: snapshot.total_amount,
              company_payment_amount: companyPaymentAmount,
              company_payment_breakdown: snapshot.company_payment_breakdown,
              company_retained_amount: companyRetainedAmount,
              company_retained_breakdown: companyPaymentDecision === 'retain_company' ? [{
                allocation_id: 9902,
                payment_id: 196,
                payment_purpose: 'service_payment',
                payment_purpose_label: 'Ek servis',
                decision: 'retain_company',
                decision_label: 'Şirkette bırak',
                eligible_amount: 1000,
                eligible_amount_label: '1.000,00 TL',
                status: 'retained',
              }] : [],
              company_payment_decisions: decidedCompanyPaymentPayload,
            } : null,
            financeSummary: nextFinanceSummary,
          }

          state.lastSavedSnapshot = snapshot
          state.lastCompanyPaymentDecision = companyPaymentDecision
          setRequest(nextRequest)
          saveLock.current = false
          setSaveInFlight(false)

          return {
            status: 'revised',
            earning_snapshot: snapshot,
            message_preview: messagePreview,
            request: nextRequest,
          }
        }}
        onCompanyPaymentDecisionApprove={async (decisions) => {
          state.allocationSubmitCount += 1
          state.lastCompanyPaymentDecisionPayload = decisions
          setAllocationSubmitCount(state.allocationSubmitCount)
          setLastAllocationPayload(decisions)
          setAllocationError(null)

          await new Promise((resolve) => window.setTimeout(resolve, 50))

          if (failNextSave) {
            state.failNextSave = false
            setFailNextSave(false)
            setAllocationError('Dağıtım kararı kaydedilemedi. Seçiminizi kontrol edip tekrar deneyin.')

            throw new Error('Dağıtım kararı kaydedilemedi. Seçiminizi kontrol edip tekrar deneyin.')
          }

          const decision = decisions[0]?.decision ?? null
          const companyPaymentAmount = decision === 'pay_technician' ? 1000 : 0
          const companyRetainedAmount = decision === 'retain_company' ? 1000 : 0
          const currentSnapshot = request.assignmentOffer?.earning_snapshot
          const laborAmount = Number(currentSnapshot?.labor_amount ?? 3000)
          const routeFeeAmount = Number(currentSnapshot?.route_fee_amount ?? 0)
          const payoutTotal = laborAmount + routeFeeAmount + companyPaymentAmount
          const netMargin = 4000 - payoutTotal
          const snapshot: ServiceRequestCanonicalEarningSnapshot = {
            ...currentSnapshot,
            schema_version: 2,
            assignment_id: 104,
            technician_id: 111,
            labor_amount: laborAmount,
            route_fee_amount: routeFeeAmount,
            base_total_amount: laborAmount + routeFeeAmount,
            company_payment_amount: companyPaymentAmount,
            company_payment_breakdown: decision === 'pay_technician' ? [{
              line_id: 9901,
              payment_id: 196,
              purpose: 'service_payment',
              purpose_label: 'Ek servis',
              source: 'extra_service',
              amount: 1000,
              amount_label: '1.000,00 TL',
              status: 'payable',
              status_label: 'Ödenecek',
            }] : [],
            total_amount: payoutTotal,
            currency: 'TRY',
            operation_note: currentSnapshot?.operation_note ?? null,
            revision: companyPaymentRevision,
            persisted_at: '2026-08-10T06:30:00+00:00',
          }
          const messagePreview = canonicalPreview(snapshot, request.mrn)
          const decisionPayload = {
            ...(request.settlement?.company_payment_decisions ?? {}),
            eligible_items: [],
            decisions: [{
              allocation_id: 9902,
              payment_id: 196,
              payment_purpose: 'service_payment',
              payment_purpose_label: 'Ek servis',
              decision,
              decision_label: decision === 'pay_technician' ? 'Ustaya ödenecek' : 'Şirkette bırak',
              eligible_amount: 1000,
              eligible_amount_label: '1.000,00 TL',
              settlement_line_id: decision === 'pay_technician' ? 9901 : null,
              status: decision === 'pay_technician' ? 'payable' : 'retained',
            }],
            eligible_count: 0,
            pending_decision_count: 0,
            pending_decision_amount: 0,
            pending_decision_amount_label: '0,00 TL',
            earning_revision: companyPaymentRevision,
          }
          const updateFinanceArea = <T extends NonNullable<ServiceRequest['financeSummary']>['current_visit']>(area: T): T => ({
            ...area,
            locksmith_payout: {
              ...area.locksmith_payout,
              labor_amount: laborAmount,
              route_fee_amount: routeFeeAmount,
              company_payment_amount: companyPaymentAmount,
              company_payment_breakdown: snapshot.company_payment_breakdown,
              company_retained_amount: companyRetainedAmount,
              total_amount: payoutTotal,
              technician_remaining_amount: payoutTotal,
              labor_amount_label: `${laborAmount.toLocaleString('tr-TR')},00 TL`,
              route_fee_amount_label: `${routeFeeAmount.toLocaleString('tr-TR')},00 TL`,
              company_payment_amount_label: `${companyPaymentAmount.toLocaleString('tr-TR')},00 TL`,
              company_retained_amount_label: `${companyRetainedAmount.toLocaleString('tr-TR')},00 TL`,
              total_amount_label: `${payoutTotal.toLocaleString('tr-TR')},00 TL`,
              technician_remaining_amount_label: `${payoutTotal.toLocaleString('tr-TR')},00 TL`,
            },
            company_payment_amount: companyPaymentAmount,
            company_payment_amount_label: `${companyPaymentAmount.toLocaleString('tr-TR')},00 TL`,
            company_retained_amount: companyRetainedAmount,
            company_retained_amount_label: `${companyRetainedAmount.toLocaleString('tr-TR')},00 TL`,
            company_payment_decisions: decisionPayload,
            net_margin: {
              amount: netMargin,
              amount_label: `${netMargin.toLocaleString('tr-TR')},00 TL`,
              provisional_amount_label: `${netMargin.toLocaleString('tr-TR')},00 TL`,
              is_definitive: true,
            },
            result_state: 'definitive',
            result_state_label: 'Kesinleşmiş',
            is_definitive: true,
          })
          const nextRequest: ServiceRequest = {
            ...request,
            assignmentOffer: request.assignmentOffer ? {
              ...request.assignmentOffer,
              total_amount: snapshot.total_amount,
              earning_snapshot: snapshot,
              message_preview: messagePreview,
              message_text: messagePreview,
            } : null,
            settlement: request.settlement ? {
              ...request.settlement,
              technician_earning_total: snapshot.total_amount,
              company_payment_amount: companyPaymentAmount,
              company_payment_breakdown: snapshot.company_payment_breakdown,
              company_retained_amount: companyRetainedAmount,
              company_retained_breakdown: decision === 'retain_company' ? decisionPayload.decisions : [],
              company_payment_decisions: decisionPayload,
            } : null,
            financeSummary: request.financeSummary ? {
              ...request.financeSummary,
              generated_at: '2026-08-10T06:30:00+00:00',
              current_visit: updateFinanceArea(request.financeSummary.current_visit),
              root_total: updateFinanceArea(request.financeSummary.root_total),
            } : null,
          }

          state.lastCompanyPaymentDecision = decision
          setRequest(nextRequest)

          return {
            status: 'decided',
            earning_snapshot: snapshot,
            message_preview: messagePreview,
            request: nextRequest,
          }
        }}
        onTechnicianEarningMessageCreate={async (payload) => {
          state.sendCount += 1
          state.lastSendPayload = payload
          setSendCount(state.sendCount)
          setLastSendRevision(payload.earning_revision)
          setLastSendPayload(payload)

          return {
            earning_snapshot: request.assignmentOffer?.earning_snapshot,
            message_preview: request.assignmentOffer?.message_preview,
            message_text: request.assignmentOffer?.message_preview ?? '',
            copy_text: request.assignmentOffer?.message_preview ?? '',
            whatsapp_url: '',
            corrective_resend: Boolean(payload.corrective_resend_reason),
            dispatch: { id: 7001, status: 'queued', channel: 'whatsapp', provider_key: 'evolution' },
          }
        }}
        onPartnerCompletionApprove={async () => {
          state.completionApproveCount += 1
        }}
        assignmentOfferUpdateError={allocationError}
        assignmentOfferUpdateInFlight={saveInFlight}
      />
      {assignmentPopupOpen ? (
        <section role="dialog" aria-modal="true" data-testid="assignment-final-popup" className="grid gap-3 border border-slate-300 bg-white p-4">
          <h2>Atamayı onayla ve mesajı hazırla</h2>
          <div data-testid="assignment-final-popup-values">
            İşçilik: {request.assignmentOffer?.earning_snapshot?.labor_amount ?? 0} TL · Yol: {request.assignmentOffer?.earning_snapshot?.route_fee_amount ?? 0} TL · Toplam: {request.assignmentOffer?.earning_snapshot?.total_amount ?? 0} TL
          </div>
          <pre data-testid="assignment-final-popup-preview" className="whitespace-pre-wrap">{request.assignmentOffer?.message_preview ?? ''}</pre>
          <button
            type="button"
            data-testid="assignment-final-popup-confirm"
            onClick={() => {
              state.assignmentConfirmCount += 1
              setAssignmentConfirmCount(state.assignmentConfirmCount)
            }}
          >Atamayı onayla ve mesajı hazırla</button>
        </section>
      ) : null}
    </>
  )
}

createRoot(document.getElementById('root')!).render(<Harness />)
window.__assignmentEarningDomReady = true

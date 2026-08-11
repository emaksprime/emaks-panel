import { useState } from 'react'
import { createRoot } from 'react-dom/client'
import { ServiceRequestDetails } from '../../resources/js/components/technical-service/ServiceRequestDetails'
import type { ServiceRequest, ServiceRequestCanonicalEarningSnapshot, ServiceRequestCompanyPaymentDecisionSubmit, ServiceRequestTechnicianEarningMessagePayload } from '../../resources/js/components/technical-service/types'
import '../../resources/css/app.css'

type HarnessState = {
  saveCount: number
  sendCount: number
  completionApproveCount: number
  allocationSubmitCount: number
  boardRefetchCount: number
  modalMountCount: number
  failNextSave: boolean
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
const initialPreview = [
  'Merhaba Test Usta,',
  'Hakediş bilgisi:',
  'MRN: MRN-DOM-EARNING',
  'Bölge: İstanbul / Kadıköy',
  'Ürün / Seri: Test Ürün / SERI-DOM',
  'Montaj işçilik: 3.000,00 TL',
  'Usta yol hakedişi: 0,00 TL',
  'Toplam hakediş: 3.000,00 TL',
  'Randevu: -',
  'İş kartı:',
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

const state: HarnessState = {
  saveCount: 0,
  sendCount: 0,
  completionApproveCount: 0,
  allocationSubmitCount: 0,
  boardRefetchCount: 0,
  modalMountCount: 0,
  failNextSave: false,
  lastSavedSnapshot: null,
  lastSendPayload: null,
  lastCompanyPaymentDecision: null,
  lastCompanyPaymentDecisionPayload: null,
}

window.__assignmentEarningDomState = state

function canonicalPreview(snapshot: ServiceRequestCanonicalEarningSnapshot): string {
  const money = (value: number) => new Intl.NumberFormat('tr-TR', {
    style: 'currency',
    currency: 'TRY',
    minimumFractionDigits: 2,
  }).format(value)

  return [
    'Merhaba Test Usta,',
    'Hakediş bilgisi:',
    'MRN: MRN-DOM-EARNING',
    'Bölge: İstanbul / Kadıköy',
    'Ürün / Seri: Test Ürün / SERI-DOM',
    `Montaj işçilik: ${money(snapshot.labor_amount)}`,
    `Usta yol hakedişi: ${money(snapshot.route_fee_amount)}`,
    ...(snapshot.company_payment_breakdown ?? []).map((line) => (
      `Şirket ödemesi — ${line.purpose_label ?? line.purpose ?? 'Ek tahsilat'}: ${money(line.amount)}`
    )),
    `Toplam hakediş: ${money(snapshot.total_amount)}`,
    'Randevu: -',
    snapshot.operation_note ? `Not: ${snapshot.operation_note}` : null,
    'İş kartı:',
    'http://192.168.1.10:8000/partner/job/dom-token',
  ].filter((line): line is string => typeof line === 'string').join('\n')
}

function Harness() {
  const [modalMountId] = useState(() => {
    state.modalMountCount += 1

    return state.modalMountCount
  })
  const [request, setRequest] = useState(initialRequest)
  const [saveCount, setSaveCount] = useState(0)
  const [sendCount, setSendCount] = useState(0)
  const [allocationSubmitCount, setAllocationSubmitCount] = useState(0)
  const [lastAllocationPayload, setLastAllocationPayload] = useState<ServiceRequestCompanyPaymentDecisionSubmit[] | null>(null)
  const [allocationError, setAllocationError] = useState<string | null>(null)
  const [lastSendRevision, setLastSendRevision] = useState('')
  const [failNextSave, setFailNextSave] = useState(false)

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
        data-testid="earning-fail-next-save"
        onClick={() => {
          state.failNextSave = true
          setFailNextSave(true)
        }}
      >Sonraki kaydı başarısız yap</button>
      <output data-testid="earning-fail-next-save-state" className="sr-only">{failNextSave ? 'true' : 'false'}</output>
      <output data-testid="earning-save-count" className="sr-only">{saveCount}</output>
      <output data-testid="earning-send-count" className="sr-only">{sendCount}</output>
      <output data-testid="earning-last-send-revision" className="sr-only">{lastSendRevision}</output>
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
        onAssignSelectedTechnician={() => undefined}
        onAssignmentOfferUpdate={async (_offerId, payload) => {
          state.saveCount += 1
          setSaveCount(state.saveCount)

          if (failNextSave) {
            state.failNextSave = false
            setFailNextSave(false)

            return
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
            currency: 'TRY',
            operation_note: payload.note ?? null,
            revision: companyPaymentDecision ? companyPaymentRevision : savedRevision,
            persisted_at: '2026-08-10T05:30:00+00:00',
          }
          const messagePreview = canonicalPreview(snapshot)
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
          const messagePreview = canonicalPreview(snapshot)
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

          return {
            earning_snapshot: request.assignmentOffer?.earning_snapshot,
            message_preview: request.assignmentOffer?.message_preview,
            message_text: request.assignmentOffer?.message_preview ?? '',
            copy_text: request.assignmentOffer?.message_preview ?? '',
            whatsapp_url: '',
            dispatch: { id: 7001, status: 'queued', channel: 'whatsapp', provider_key: 'evolution' },
          }
        }}
        onPartnerCompletionApprove={async () => {
          state.completionApproveCount += 1
        }}
        assignmentOfferUpdateError={allocationError}
      />
    </>
  )
}

createRoot(document.getElementById('root')!).render(<Harness />)
window.__assignmentEarningDomReady = true

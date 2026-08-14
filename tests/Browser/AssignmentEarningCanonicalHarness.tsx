import { useRef, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { ServiceRequestDetails } from '../../resources/js/components/technical-service/ServiceRequestDetails'
import type { ServiceRequestAssignmentDraft } from '../../resources/js/components/technical-service/ServiceRequestDetails'
import { TechnicalServiceKanbanCard } from '../../resources/js/components/technical-service/TechnicalServiceKanbanCard'
import type { ServiceRequest, ServiceRequestCanonicalEarningSnapshot, ServiceRequestCompanyPaymentDecisionPayload, ServiceRequestCompanyPaymentDecisionSubmit, ServiceRequestExtraMountPayment, ServiceRequestExtraMountPaymentPayload, ServiceRequestFinanceCollection, ServiceRequestFinancePayout, ServiceRequestFinanceSummary, ServiceRequestPaymentEarningImpact, ServiceRequestPaymentOrderContext, ServiceRequestTechnicianEarningMessagePayload } from '../../resources/js/components/technical-service/types'
import '../../resources/css/app.css'

type HarnessState = {
  saveCount: number
  sendCount: number
  assignmentActionCount: number
  assignmentPopupCount: number
  assignmentConfirmCount: number
  routeRequestCount: number
  scrollResetCount: number
  completionApproveCount: number
  allocationSubmitCount: number
  boardRefetchCount: number
  modalMountCount: number
  paymentCreateCount: number
  paymentOrderStateUpdateCount: number
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
  lastAssignmentDraft: ServiceRequestAssignmentDraft | null
  lastPaymentCreatePayload: (ServiceRequestExtraMountPaymentPayload & { terminal_retry_reason?: string | null }) | null
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
const assignmentMapsUrl = 'https://www.google.com/maps/search/?api=1&query=37.8980452%2C29.1855785'
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
  technicianProfile: {
    id: '111',
    name: 'Test Usta',
    phone: '905467647428',
    city: 'Denizli',
    district: 'Pamukkale',
    active: true,
  },
  appointment: '-',
  status: 'Atandı',
  workflowStatus: 'Usta Onayı Bekleyen',
  address: 'Test adresi',
  location: {
    latitude: 37.8980452,
    longitude: 29.1855785,
    route_latitude: 37.8980452,
    route_longitude: 29.1855785,
    route_source: 'request',
    formatted_address: 'Pamukkale/Denizli, Türkiye',
    map_url: assignmentMapsUrl,
    shared: true,
  },
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

const initialAssignmentRequest = (): ServiceRequest => ({
  ...initialRequest,
  technician: 'Atanmadı',
  technicianId: null,
  technicianPhone: null,
  technicianProfile: null,
  status: 'Yeni',
  workflowStatus: 'Yeni Talep',
  assignmentOffer: null,
  settlement: null,
  technicianJobCard: null,
  assignmentBlockers: {
    applies_to_assignment: true,
    payment_check_required: true,
    messages: [
      'Hakediş ödeme kaynağı netleşmeden atama güncellenemez. EMAKS Prime veya müşteri doğrudan seçimini yapın.',
    ],
  },
})

const canonicalTechnicianBoardRequest: ServiceRequest = {
  ...initialRequest,
  city: 'Ankara',
  district: 'Beypazarı',
}

const missingTechnicianCityBoardRequest: ServiceRequest = {
  ...canonicalTechnicianBoardRequest,
  technicianProfile: {
    ...canonicalTechnicianBoardRequest.technicianProfile!,
    city: null,
    district: null,
  },
}

const partContextRequest = (status: 'paid' | 'unpaid' | 'free' | 'none'): ServiceRequest => {
  if (status === 'none') {
    return {
      ...initialRequest,
      partRequests: [],
    }
  }

  const isFree = status === 'free'
  const isPaid = status === 'paid'

  return {
    ...initialRequest,
    partRequests: [{
      id: status === 'paid' ? 26 : status === 'unpaid' ? 27 : 28,
      technical_service_request_id: 9001,
      root_request_id: 9001,
      status: 'approved',
      status_label: 'Parça talebi onaylandı',
      part_name: 'Gateway',
      quantity: 1,
      requires_service_visit: false,
      charge_decision: isFree ? 'free' : 'chargeable',
      charge_decision_label: isFree ? 'Ücretsiz / garanti kapsamında' : 'Ücretli',
      service_amount: 0,
      service_amount_label: '0,00 TL',
      part_amount: isFree ? 0 : 2000,
      part_amount_label: isFree ? '0,00 TL' : '2.000,00 TL',
      total_amount: isFree ? 0 : 2000,
      total_amount_label: isFree ? '0,00 TL' : '2.000,00 TL',
      charge_status: isPaid ? 'paid' : isFree ? null : 'pending',
      is_payment_required: status === 'unpaid',
      is_payment_paid: isPaid,
      can_ship: isPaid || isFree,
      payment_id: isPaid ? 167 : null,
      provider_payment_reference: isPaid ? '37164237' : null,
      provider_transaction_reference: isPaid ? '39067702' : null,
      paid_at: isPaid ? '2026-08-07T07:04:50+00:00' : null,
      customer_charge: isPaid ? {
        id: 167,
        status: 'paid',
        status_label: 'Ödendi',
        total_amount: 2000,
        total_amount_label: '2.000,00 TL',
        provider: 'iyzico',
        provider_payment_reference: '37164237',
        provider_transaction_reference: '39067702',
        paid_at: '2026-08-07T07:04:50+00:00',
        currency: 'TRY',
      } : null,
    }],
  }
}

const technicianSuggestions = [
  {
    id: '111',
    name: 'Test Usta',
    location: 'Denizli / Pamukkale',
    city: 'Denizli',
    district: 'Pamukkale',
    phone: '905467647428',
    distanceKmLabel: '0 km',
    scheduledCount: 0,
    availableSlots: [],
    technicianAmountLabel: '3.000,00 TL',
    technicianAmountSourceLabel: 'Canonical assignment',
    travelAmountLabel: '0,00 TL',
    totalCostLabel: '3.000,00 TL',
    costDeltaLabel: '0,00 TL',
    recommended: true,
  },
  {
    id: '54',
    name: 'BAHATTİN ÖZBEK',
    location: 'Ankara / Çankaya',
    city: 'Ankara',
    district: 'Çankaya',
    phone: '905353345959',
    distanceKmLabel: '12 km',
    scheduledCount: 2,
    availableSlots: [],
    technicianAmountLabel: '3.000,00 TL',
    technicianAmountSourceLabel: 'Canonical assignment',
    travelAmountLabel: '500,00 TL',
    totalCostLabel: '3.500,00 TL',
    costDeltaLabel: '0,00 TL',
    recommended: false,
  },
  {
    id: '112',
    name: 'Şule Çilingir',
    location: 'İstanbul / Şişli',
    city: 'İstanbul',
    district: 'Şişli',
    phone: '905551112233',
    distanceKmLabel: '8 km',
    scheduledCount: 1,
    availableSlots: [],
    technicianAmountLabel: '3.000,00 TL',
    technicianAmountSourceLabel: 'Canonical assignment',
    travelAmountLabel: '400,00 TL',
    totalCostLabel: '3.400,00 TL',
    costDeltaLabel: '0,00 TL',
    recommended: false,
  },
  ...Array.from({ length: 49 }, (_, index) => ({
    id: String(200 + index),
    name: `Usta ${String(index + 1).padStart(2, '0')}`,
    location: index % 2 === 0 ? 'İzmir / Bornova' : 'Bursa / Nilüfer',
    city: index % 2 === 0 ? 'İzmir' : 'Bursa',
    district: index % 2 === 0 ? 'Bornova' : 'Nilüfer',
    phone: `9053200${String(index).padStart(4, '0')}`,
    distanceKmLabel: `${20 + index} km`,
    scheduledCount: index % 4,
    availableSlots: [],
    technicianAmountLabel: '3.000,00 TL',
    technicianAmountSourceLabel: 'Canonical assignment',
    travelAmountLabel: '400,00 TL',
    totalCostLabel: '3.400,00 TL',
    costDeltaLabel: '0,00 TL',
    recommended: false,
  })),
]

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
        selected_scope_customer_collection_total: 4000,
        selected_scope_customer_collection_total_label: '4.000,00 TL',
        included_collection_sources: [
          {
            source_type: 'payment',
            source_reference: 'Ödeme #195',
            source_label: 'Ödeme #195',
            payment_id: 195,
            amount: 3000,
            amount_label: '3.000,00 TL',
            status: 'paid',
            status_label: 'Ödendi',
            purpose_label: 'Ek montaj tahsilatı',
          },
          {
            source_type: 'payment',
            source_reference: 'Ödeme #196',
            source_label: 'Ödeme #196',
            payment_id: 196,
            amount: 1000,
            amount_label: '1.000,00 TL',
            status: 'paid',
            status_label: 'Ödendi',
            purpose_label: 'Ek servis',
            earning_impact: {
              state: 'decision_pending',
              covered_components: [],
              covered_amount: 0,
              additional_earning_amount: 0,
              retained_by_company_amount: 0,
              unmatched_residual_amount: 1000,
              unmatched_residual_amount_label: '1.000,00 TL',
              decision_state: 'pending',
              decision_label: 'Karar bekliyor',
              decision_required: true,
              technician_id: 111,
              technician_name: 'Test Usta',
              canonical_source: 'company_payment_decision_payload',
            },
          },
        ],
        included_source_total: 4000,
        included_source_total_label: '4.000,00 TL',
        reconciliation_ok: true,
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

const routeCollectionMatchingRequest = (contextReady: boolean): ServiceRequest => {
  const request = companyPaymentRequest()
  const routeAmount = 1787.4
  const decisionPayload = {
    schema_version: 1,
    eligible_items: contextReady ? [] : [{
      payment_id: 16,
      payment_purpose: 'route_fee',
      payment_purpose_label: 'Yol ücreti',
      provider: 'fake',
      provider_label: 'Local fake',
      paid_at: '2026-06-23T08:00:00+00:00',
      source_paid_amount: routeAmount,
      source_paid_amount_label: '1.787,40 TL',
      covered_amount: 0,
      covered_amount_label: '0,00 TL',
      previously_allocated_amount: 0,
      previously_allocated_amount_label: '0,00 TL',
      eligible_amount: routeAmount,
      eligible_amount_label: '1.787,40 TL',
      currency: 'TRY',
      request_id: 9001,
      root_request_id: 9001,
      current_srv_id: 9001,
      mrn_or_srv: 'MRN-2606SY230001',
      assignment_id: null,
      technician_id: null,
      technician_name: null,
      can_pay_technician: false,
      disabled_reason: 'Atama tamamlandıktan sonra tahsilat dağılımı hesaplanacaktır.',
    }],
    decisions: [],
    eligible_count: contextReady ? 0 : 1,
    pending_decision_count: 0,
    pending_decision_amount: 0,
    pending_decision_amount_label: '0,00 TL',
    all_decisions_required: false,
    context_ready: contextReady,
    context_state: contextReady ? 'ready' as const : 'awaiting_assignment' as const,
    context_blocker: contextReady ? null : 'Atama tamamlandıktan sonra tahsilat dağılımı hesaplanacaktır.',
    earning_revision: contextReady ? initialRevision : null,
    component_matching: {
      route: {
        earning_amount: contextReady ? routeAmount : 0,
        earning_amount_label: contextReady ? '1.787,40 TL' : '0,00 TL',
        collection_amount: routeAmount,
        collection_amount_label: '1.787,40 TL',
        covered_amount: contextReady ? routeAmount : 0,
        covered_amount_label: contextReady ? '1.787,40 TL' : '0,00 TL',
        residual_allocatable_amount: contextReady ? 0 : routeAmount,
        residual_allocatable_amount_label: contextReady ? '0,00 TL' : '1.787,40 TL',
        company_top_up_amount: 0,
        company_top_up_amount_label: '0,00 TL',
        payments: [{
          payment_id: 16,
          paid_amount: routeAmount,
          paid_amount_label: '1.787,40 TL',
          covered_amount: contextReady ? routeAmount : 0,
          covered_amount_label: contextReady ? '1.787,40 TL' : '0,00 TL',
          previously_allocated_amount: 0,
          previously_allocated_amount_label: '0,00 TL',
          residual_allocatable_amount: contextReady ? 0 : routeAmount,
          residual_allocatable_amount_label: contextReady ? '0,00 TL' : '1.787,40 TL',
        }],
      },
    },
    visit_count_used: false as const,
  }

  return {
    ...request,
    mrn: 'MRN-2606SY230001',
    settlement: request.settlement ? {
      ...request.settlement,
      technical_service_technician_id: contextReady ? 111 : null,
      route_earning_amount: contextReady ? routeAmount : 0,
      technician_earning_total: contextReady ? 4787.4 : 3000,
      company_payment_decisions: decisionPayload,
    } : null,
    financeSummary: request.financeSummary ? {
      ...request.financeSummary,
      current_visit: {
        ...request.financeSummary.current_visit,
        company_payment_decisions: null,
        result_state: contextReady ? 'definitive' : 'allocation_pending',
        result_state_label: contextReady ? 'Kesinleşmiş' : 'Tahsilat dağılımı atama bekliyor',
        locksmith_payout: {
          ...request.financeSummary.current_visit.locksmith_payout,
          technician_id: contextReady ? 111 : null,
          technician_name: contextReady ? 'Test Usta' : null,
          route_fee_amount: contextReady ? routeAmount : 0,
          route_fee_amount_label: contextReady ? '1.787,40 TL' : '0,00 TL',
          total_amount: contextReady ? 4787.4 : 3000,
          total_amount_label: contextReady ? '4.787,40 TL' : '3.000,00 TL',
        },
      },
    } : null,
  }
}

const reassignmentEligibilityRequest = (): ServiceRequest => {
  const request = routeCollectionMatchingRequest(false)
  const oldSnapshot = request.assignmentOffer?.earning_snapshot

  return {
    ...request,
    technician: 'BAHATTİN ÖZBEK',
    technicianId: '54',
    technicianPhone: '9054****054',
    technicianProfile: {
      id: '54',
      name: 'BAHATTİN ÖZBEK',
      phone: '9054****054',
      city: 'Ankara',
      district: 'Çankaya',
      active: true,
    },
    routeQuote: {
      ok: true,
      id: 88,
      technician_id: 111,
      status: 'calculated',
      one_way_distance_km: 503.93,
      round_trip_distance_km: 1007.86,
      distance_km: 1007.86,
      threshold_km: 30,
      billable_km: 977.86,
      fee_per_km: 10,
      fee_amount: 9778.6,
      provider: 'google_routes',
      source: 'google_routes',
      calculated_at: '2026-08-13T07:45:00+00:00',
    },
    assignmentOffer: request.assignmentOffer ? {
      ...request.assignmentOffer,
      technical_service_technician_id: 54,
      technician_name: 'BAHATTİN ÖZBEK',
      labor_amount: 3000,
      route_fee_amount: 1787.4,
      total_amount: 4787.4,
      earning_snapshot: oldSnapshot ? {
        ...oldSnapshot,
        technician_id: 54,
        labor_amount: 3000,
        route_fee_amount: 1787.4,
        total_amount: 4787.4,
      } : null,
    } : null,
    technicianJobCard: request.technicianJobCard ? {
      ...request.technicianJobCard,
      technician_id: 54,
    } : null,
  }
}

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

const paymentImpactRequest = (): ServiceRequest => {
  const request = companyPaymentMessageRequest()
  const baseImpact: ServiceRequestPaymentEarningImpact = {
    state: 'covers_existing_earning',
    covered_components: [
      { key: 'labor', label: 'İşçilik', amount: 3000, amount_label: '3.000,00 TL' },
      { key: 'route', label: 'Yol', amount: 1400, amount_label: '1.400,00 TL' },
    ],
    covered_amount: 4400,
    covered_amount_label: '4.400,00 TL',
    additional_earning_component: null,
    additional_earning_component_label: null,
    additional_earning_amount: 0,
    additional_earning_amount_label: '0,00 TL',
    retained_by_company_amount: 0,
    retained_by_company_amount_label: '0,00 TL',
    unmatched_residual_amount: 0,
    unmatched_residual_amount_label: '0,00 TL',
    decision_state: 'not_required',
    decision_label: 'Karar gerekmiyor',
    decision_required: false,
    technician_id: 111,
    technician_name: 'Test Usta',
    canonical_source: 'settlement.base_company_payable_amount',
  }
  const extraImpact: ServiceRequestPaymentEarningImpact = {
    ...baseImpact,
    state: 'adds_technician_earning',
    covered_components: [],
    covered_amount: 0,
    covered_amount_label: '0,00 TL',
    additional_earning_component: 'service_payment',
    additional_earning_component_label: 'Ek servis',
    additional_earning_amount: 600,
    additional_earning_amount_label: '600,00 TL',
    decision_state: 'completed',
    decision_label: 'Ustaya ödenecek — Tamamlandı',
    canonical_source: 'payment_settlement_allocation',
  }
  const cancelledImpact: ServiceRequestPaymentEarningImpact = {
    ...baseImpact,
    state: 'cancelled_or_failed',
    covered_components: [],
    covered_amount: 0,
    covered_amount_label: '0,00 TL',
    decision_label: null,
    canonical_source: 'payment_status',
  }
  const completedDecision = {
    allocation_id: 1,
    payment_id: 198,
    payment_purpose: 'service_payment',
    payment_purpose_label: 'Ek servis',
    decision: 'pay_technician' as const,
    decision_label: 'Evet, şirket ödemesi olarak ustaya eklendi',
    eligible_amount: 600,
    eligible_amount_label: '600,00 TL',
    decided_at: '2026-08-11T07:10:20+00:00',
    status: 'active',
  }
  const decisions: ServiceRequestCompanyPaymentDecisionPayload = {
    schema_version: 1,
    eligible_items: [],
    decisions: [completedDecision],
    eligible_count: 0,
    pending_decision_count: 0,
    pending_decision_amount: 0,
    pending_decision_amount_label: '0,00 TL',
    all_decisions_required: false,
    context_ready: true,
    context_state: 'ready',
    context_blocker: null,
    earning_revision: companyPaymentMessageRevision,
    visit_count_used: false,
  }
  const collection: ServiceRequestFinanceCollection = {
    mount_amount: 4400,
    service_amount: 0,
    extra_amount: 600,
    route_amount: 0,
    part_amount: 0,
    unclassified_amount: 0,
    service_total_amount: 5000,
    total_amount: 5000,
    mount_amount_label: '4.400,00 TL',
    service_amount_label: '0,00 TL',
    extra_amount_label: '600,00 TL',
    route_amount_label: '0,00 TL',
    part_amount_label: '0,00 TL',
    unclassified_amount_label: '0,00 TL',
    service_total_amount_label: '5.000,00 TL',
    total_amount_label: '5.000,00 TL',
    selected_scope_customer_collection_total: 5000,
    selected_scope_customer_collection_total_label: '5.000,00 TL',
    included_collection_sources: [
      {
        source_type: 'payment',
        source_reference: 'Ödeme #197',
        source_label: 'Ödeme #197',
        payment_id: 197,
        amount: 4400,
        amount_label: '4.400,00 TL',
        status: 'paid',
        status_label: 'Ödendi',
        purpose_label: 'Ek montaj tahsilatı',
        relation_label: 'Bu MRN',
        earning_impact: baseImpact,
      },
      {
        source_type: 'payment',
        source_reference: 'Ödeme #198',
        source_label: 'Ödeme #198',
        payment_id: 198,
        amount: 600,
        amount_label: '600,00 TL',
        status: 'paid',
        status_label: 'Ödendi',
        purpose_label: 'Servis ücreti',
        relation_label: 'Bu MRN',
        earning_impact: extraImpact,
      },
    ],
    included_source_total: 5000,
    included_source_total_label: '5.000,00 TL',
    reconciliation_ok: true,
    has_collection: true,
  }
  const payout: ServiceRequestFinancePayout = {
    labor_amount: 3000,
    route_fee_amount: 1400,
    company_payment_amount: 600,
    company_payment_breakdown: request.assignmentOffer?.earning_snapshot?.company_payment_breakdown ?? [],
    company_retained_amount: 0,
    company_payment_decisions: decisions,
    technician_paid_amount: 0,
    technician_remaining_amount: 5000,
    total_amount: 5000,
    labor_amount_label: '3.000,00 TL',
    route_fee_amount_label: '1.400,00 TL',
    company_payment_amount_label: '600,00 TL',
    company_retained_amount_label: '0,00 TL',
    technician_paid_amount_label: '0,00 TL',
    technician_remaining_amount_label: '5.000,00 TL',
    total_amount_label: '5.000,00 TL',
    payout_status: 'confirmed',
    payout_status_label: 'Onaylanan usta hakedişi',
    technician_id: 111,
    technician_name: 'Test Usta',
    payer_state: 'company_collected_company_pays_technician',
    technician_payment_source_key: 'emaks_prime',
    technician_payment_source_label: 'EMAKS Prime',
  }
  const financeArea: ServiceRequestFinanceSummary['current_visit'] = {
    customer_collection: collection,
    locksmith_payout: payout,
    company_payment_amount: 600,
    company_payment_amount_label: '600,00 TL',
    company_retained_amount: 0,
    company_retained_amount_label: '0,00 TL',
    company_payment_decisions: decisions,
    net_margin: {
      amount: 0,
      amount_label: '0,00 TL',
      provisional_amount_label: '0,00 TL',
      is_negative: false,
      is_definitive: true,
    },
    result_state: 'definitive',
    result_state_label: 'Kesinleşmiş',
    is_definitive: true,
  }
  const cancelledHistory: NonNullable<ServiceRequestFinanceSummary['payment_records']>['current_scope_history'] = {
    paid_rows: [],
    pending_rows: [],
    historical_groups: [{
      key: 'request:9001',
      relation_type: 'request',
      relation_id: 9001,
      relation_label: 'Bu MRN',
      latest_status: 'cancelled',
      latest_status_label: 'İptal edildi',
      attempt_count: 1,
      attempt_count_label: '1 eski ödeme denemesi',
      rows: [{
        id: 1960,
        amount: 750,
        amount_label: 'Talep edilen tutar',
        amount_formatted: '750,00 TL',
        status: 'cancelled',
        status_label: 'İptal edildi',
        status_bucket: 'historical',
        purpose_label: 'Ek servis',
        relation_label: 'Bu MRN',
        terminal_at: '2026-08-12T09:00:00+00:00',
        earning_impact: cancelledImpact,
      }],
    }],
    paid_count: 0,
    pending_count: 0,
    historical_count: 1,
    total_count: 1,
  }

  return {
    ...request,
    settlement: {
      id: 78,
      technical_service_request_id: 9001,
      technical_service_assignment_offer_id: 104,
      technical_service_technician_id: 111,
      currency: 'TRY',
      labor_earning_amount: 3000,
      route_earning_amount: 1400,
      technician_earning_total: 5000,
      company_payment_amount: 600,
      company_payment_breakdown: payout.company_payment_breakdown,
      company_retained_amount: 0,
      company_retained_breakdown: [],
      company_payment_decisions: decisions,
      customer_collection_amount: 5000,
      company_payable_amount: 5000,
      company_paid_amount: 0,
      company_remaining_amount: 5000,
    },
    financeSummary: {
      generated_at: '2026-08-13T12:00:00+00:00',
      currency: 'TRY',
      scope: {
        request_id: 9001,
        root_request_id: 9001,
        request_code: 'MRN-2608DD060002',
        root_mrn: 'MRN-2608DD060002',
        scope_type: 'current_mrn',
      },
      scope_context: {
        current_record_type: 'mrn',
        current_record_code: 'MRN-2608DD060002',
        root_request_id: 9001,
        root_mrn_code: 'MRN-2608DD060002',
        current_scope_key: 'current_mrn',
        current_scope_label: 'Bu MRN',
        root_total_available: false,
        has_descendants: false,
        scope_options: [{ key: 'current_mrn', label: 'Bu MRN', available: true }],
      },
      technician: {
        technician_id: 111,
        technician_name: 'Test Usta',
        assignment_id: 104,
      },
      history: { loaded: false, current_count: null, root_count: null },
      payment_records: {
        current_scope_rows: [],
        related_scope_rows: [],
        root_scope_rows: [],
        current_scope_history: cancelledHistory,
        root_scope_history: cancelledHistory,
      },
      current_visit: financeArea,
      root_total: financeArea,
    },
  }
}

const state: HarnessState = {
  saveCount: 0,
  sendCount: 0,
  assignmentActionCount: 0,
  assignmentPopupCount: 0,
  assignmentConfirmCount: 0,
  routeRequestCount: 0,
  scrollResetCount: 0,
  completionApproveCount: 0,
  allocationSubmitCount: 0,
  boardRefetchCount: 0,
  modalMountCount: 0,
  paymentCreateCount: 0,
  paymentOrderStateUpdateCount: 0,
  failNextSave: false,
  lastSavePayload: null,
  lastSavedSnapshot: null,
  lastSendPayload: null,
  lastCompanyPaymentDecision: null,
  lastCompanyPaymentDecisionPayload: null,
  lastAssignmentDraft: null,
  lastPaymentCreatePayload: null,
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
  const [routeRequestCount, setRouteRequestCount] = useState(0)
  const [lastAssignmentDraft, setLastAssignmentDraft] = useState<ServiceRequestAssignmentDraft | null>(null)
  const [selectedTechnicianId, setSelectedTechnicianId] = useState<string | null>('111')
  const [assignmentReason, setAssignmentReason] = useState('')
  const [assignmentReasonError, setAssignmentReasonError] = useState<string | null>(null)
  const [assignmentSuccess, setAssignmentSuccess] = useState<string | null>(null)
  const [allocationSubmitCount, setAllocationSubmitCount] = useState(0)
  const [lastAllocationPayload, setLastAllocationPayload] = useState<ServiceRequestCompanyPaymentDecisionSubmit[] | null>(null)
  const [allocationError, setAllocationError] = useState<string | null>(null)
  const [lastSendRevision, setLastSendRevision] = useState('')
  const [lastSendPayload, setLastSendPayload] = useState<ServiceRequestTechnicianEarningMessagePayload | null>(null)
  const [failNextSave, setFailNextSave] = useState(false)
  const [paymentCreateCount, setPaymentCreateCount] = useState(0)
  const [paymentOrderStateUpdateCount, setPaymentOrderStateUpdateCount] = useState(0)
  const [lastPaymentCreatePayload, setLastPaymentCreatePayload] = useState<HarnessState['lastPaymentCreatePayload']>(null)
  const saveLock = useRef(false)
  const paymentCreateLock = useRef(false)
  const assignmentPopupOpenRef = useRef(false)
  const assignmentReasonRef = useRef<HTMLTextAreaElement | null>(null)
  const assignmentConfirmInFlightRef = useRef(false)
  const assignmentRequiresReason = Boolean(
    request.technicianId
    && selectedTechnicianId
    && String(request.technicianId) !== String(selectedTechnicianId),
  )

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
        data-testid="load-unbound-route-collection-scenario"
        onClick={() => {
          state.assignmentActionCount = 0
          state.assignmentPopupCount = 0
          assignmentPopupOpenRef.current = false
          setAssignmentActionCount(0)
          setAssignmentPopupCount(0)
          setAssignmentPopupOpen(false)
          setRequest(routeCollectionMatchingRequest(false))
        }}
      >Atama bekleyen yol tahsilatını yükle</button>
      <button
        type="button"
        data-testid="load-reassignment-eligibility-scenario"
        onClick={() => {
          state.assignmentActionCount = 0
          state.assignmentPopupCount = 0
          state.assignmentConfirmCount = 0
          state.routeRequestCount = 0
          state.scrollResetCount = 0
          state.boardRefetchCount = 0
          state.lastAssignmentDraft = null
          assignmentPopupOpenRef.current = false
          assignmentConfirmInFlightRef.current = false
          setAssignmentActionCount(0)
          setAssignmentPopupCount(0)
          setAssignmentConfirmCount(0)
          setRouteRequestCount(0)
          setLastAssignmentDraft(null)
          setAssignmentPopupOpen(false)
          setAssignmentReason('')
          setAssignmentReasonError(null)
          setAssignmentSuccess(null)
          setSelectedTechnicianId('111')
          setRequest(reassignmentEligibilityRequest())
        }}
      >Yeniden atama CTA senaryosunu yükle</button>
      <button
        type="button"
        data-testid="load-initial-assignment-scenario"
        onClick={() => {
          state.assignmentActionCount = 0
          state.assignmentPopupCount = 0
          state.assignmentConfirmCount = 0
          state.routeRequestCount = 0
          state.scrollResetCount = 0
          state.boardRefetchCount = 0
          state.lastAssignmentDraft = null
          assignmentPopupOpenRef.current = false
          assignmentConfirmInFlightRef.current = false
          setAssignmentActionCount(0)
          setAssignmentPopupCount(0)
          setAssignmentConfirmCount(0)
          setRouteRequestCount(0)
          setLastAssignmentDraft(null)
          setAssignmentPopupOpen(false)
          setAssignmentReason('')
          setAssignmentReasonError(null)
          setAssignmentSuccess(null)
          setSelectedTechnicianId('111')
          setRequest(initialAssignmentRequest())
        }}
      >İlk atama senaryosunu yükle</button>
      <button
        type="button"
        data-testid="load-assignment-missing-technician-scenario"
        onClick={() => {
          setSelectedTechnicianId(null)
          setRequest(reassignmentEligibilityRequest())
        }}
      >Usta seçilmemiş atama senaryosunu yükle</button>
      <button
        type="button"
        data-testid="load-matched-route-collection-scenario"
        onClick={() => setRequest(routeCollectionMatchingRequest(true))}
      >Eşleşmiş yol tahsilatını yükle</button>
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
      <button type="button" data-testid="load-payment-impact-scenario" onClick={() => setRequest(paymentImpactRequest())}>Ödeme etkisi senaryosunu yükle</button>
      <button type="button" data-testid="load-paid-part-scenario" onClick={() => setRequest(partContextRequest('paid'))}>Ödenmiş parça senaryosunu yükle</button>
      <button type="button" data-testid="load-unpaid-part-scenario" onClick={() => setRequest(partContextRequest('unpaid'))}>Ödenmemiş parça senaryosunu yükle</button>
      <button type="button" data-testid="load-free-part-scenario" onClick={() => setRequest(partContextRequest('free'))}>Ücretsiz parça senaryosunu yükle</button>
      <button type="button" data-testid="load-no-part-scenario" onClick={() => setRequest(partContextRequest('none'))}>Parçasız senaryoyu yükle</button>
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
      <output data-testid="assignment-route-request-count" className="sr-only">{routeRequestCount}</output>
      <output data-testid="assignment-scroll-reset-count" className="sr-only">{state.scrollResetCount}</output>
      <output data-testid="assignment-selected-technician-id" className="sr-only">{selectedTechnicianId}</output>
      <output data-testid="assignment-popup-open" className="sr-only">{assignmentPopupOpen ? 'true' : 'false'}</output>
      <output data-testid="request-detail-open" className="sr-only">true</output>
      <output data-testid="assignment-last-draft" className="sr-only">{JSON.stringify(lastAssignmentDraft)}</output>
      <output data-testid="earning-last-send-revision" className="sr-only">{lastSendRevision}</output>
      <output data-testid="earning-last-send-payload" className="sr-only">{JSON.stringify(lastSendPayload)}</output>
      <output data-testid="company-payment-decision-submit-count" className="sr-only">{allocationSubmitCount}</output>
      <output data-testid="company-payment-decision-last-payload" className="sr-only">{JSON.stringify(lastAllocationPayload)}</output>
      <output data-testid="financial-board-refetch-count" className="sr-only">{state.boardRefetchCount}</output>
      <output data-testid="financial-modal-mount-count" className="sr-only">{modalMountId}</output>
      <output data-testid="payment-order-create-count" className="sr-only">{paymentCreateCount}</output>
      <output data-testid="payment-order-state-update-count" className="sr-only">{paymentOrderStateUpdateCount}</output>
      <output data-testid="payment-order-last-payload" className="sr-only">{JSON.stringify(lastPaymentCreatePayload)}</output>
      <section className="grid max-w-md gap-4 p-4" aria-label="Board technician location fixtures">
        <div data-testid="assignment-dynamic-board-card">
          <TechnicalServiceKanbanCard request={request} onClick={() => undefined} />
        </div>
        <div data-testid="canonical-technician-board-card">
          <TechnicalServiceKanbanCard request={canonicalTechnicianBoardRequest} onClick={() => undefined} />
        </div>
        <div data-testid="missing-technician-city-board-card">
          <TechnicalServiceKanbanCard request={missingTechnicianCityBoardRequest} onClick={() => undefined} />
        </div>
      </section>
      <ServiceRequestDetails
        request={request}
        events={[]}
        loading={false}
        displayMrn={request.mrn}
        technicianSuggestions={technicianSuggestions}
        selectedTechnicianId={selectedTechnicianId}
        assignSuccess={assignmentSuccess}
        onTechnicianSelect={(technicianId) => {
          setSelectedTechnicianId(technicianId)
          state.routeRequestCount += 1
          setRouteRequestCount(state.routeRequestCount)
        }}
        onAssignSelectedTechnician={(draft) => {
          state.assignmentActionCount += 1
          state.lastAssignmentDraft = draft ?? null
          setAssignmentActionCount(state.assignmentActionCount)
          setLastAssignmentDraft(state.lastAssignmentDraft)

          if (!assignmentPopupOpenRef.current) {
            assignmentPopupOpenRef.current = true
            state.assignmentPopupCount += 1
            setAssignmentPopupCount(state.assignmentPopupCount)
            setAssignmentReason('')
            setAssignmentReasonError(null)
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
        onExtraMountPaymentCreate={async (payload) => {
          if (paymentCreateLock.current) {
            return
          }

          paymentCreateLock.current = true
          state.paymentCreateCount += 1
          state.lastPaymentCreatePayload = payload
          setPaymentCreateCount(state.paymentCreateCount)
          setLastPaymentCreatePayload(payload)

          try {
            await new Promise((resolve) => window.setTimeout(resolve, 50))
            const purpose = payload.purpose === 'part_charge' ? 'part_charge' : 'mount_collection'
            const orderInput = payload.order_context ?? {}
            const supplier = orderInput.part_supplier ?? null
            const commercialMode = purpose === 'part_charge' && supplier === 'emaks_prime'
              ? orderInput.commercial_mode ?? 'paid'
              : purpose === 'mount_collection' ? 'paid' : null
            const deliveryMode = purpose === 'part_charge' && supplier === 'emaks_prime'
              ? orderInput.delivery_mode ?? 'shipment'
              : null
            const shipmentRequired = purpose === 'part_charge' && supplier === 'emaks_prime' && deliveryMode === 'shipment'
            const sameAsBilling = shipmentRequired && Boolean(orderInput.shipping_same_as_billing)
            const paymentLinkRequired = purpose === 'mount_collection'
              || supplier === 'technician'
              || (supplier === 'emaks_prime' && commercialMode === 'paid' && deliveryMode === 'shipment')
            const collectionRequired = purpose === 'mount_collection'
              || supplier === 'technician'
              || (supplier === 'emaks_prime' && commercialMode === 'paid')
            const desiredSeries = purpose === 'mount_collection'
              ? 'S'
              : supplier === 'technician' ? null : deliveryMode === 'hand_delivery' || commercialMode === 'free' ? 'Q' : 'S'
            const taxMode = desiredSeries === 'Q'
              ? 'none'
              : purpose === 'mount_collection' ? 'standard_from_mikro_service_item' : supplier === 'emaks_prime' ? 'standard_from_mikro' : null
            const stockByToken = new Map([
              ['stand-one-token', {
                item_code: 'EE.BCK.STD.0010', item_name: 'PHILIPS SUNUM STANDI - D BOY', item_short_name: 'SUNUM STANDI', unit_code: 'ADET',
                item_kind: 'accessory' as const, physical_stock_total: 83, physical_stock_total_label: '83', serial_tracking_state: 'not_required',
              }],
              ['stand-two-token', {
                item_code: 'EE.BCK.STD.0011', item_name: 'PHILIPS SUNUM STANDI - Y BOY', item_short_name: 'SUNUM STANDI', unit_code: 'ADET',
                item_kind: 'accessory' as const, physical_stock_total: 4, physical_stock_total_label: '4', serial_tracking_state: 'not_required',
              }],
              ['serial-part-token', {
                item_code: 'TKN000011', item_name: 'Seri Takipli Motor', item_short_name: null, unit_code: 'ADET',
                item_kind: 'part' as const, physical_stock_total: 2, physical_stock_total_label: '2', serial_tracking_state: 'required',
              }],
            ])
            const inputLines = Array.isArray(orderInput.lines) ? orderInput.lines : []
            const partLines = supplier === 'emaks_prime'
              ? inputLines.map((line, index) => {
                  const stock = stockByToken.get(String(line.stock_selection_token ?? '')) ?? {
                    item_code: `UNKNOWN-${index + 1}`,
                    item_name: 'Doğrulanmamış satır',
                    item_short_name: null,
                    unit_code: 'ADET',
                    item_kind: 'unknown' as const,
                    physical_stock_total: null,
                    physical_stock_total_label: null,
                    serial_tracking_state: 'unverified',
                  }
                  const quantity = Number(line.quantity ?? 0)
                  const unitPrice = commercialMode === 'free' && deliveryMode === 'shipment' ? 0 : Number(line.unit_price ?? 0)
                  const lineTotal = Math.round(quantity * unitPrice * 100) / 100

                  return {
                    id: null,
                    line_key: stock.item_code,
                    position: index + 1,
                    selection_token: String(line.stock_selection_token ?? ''),
                    ...stock,
                    item_kind: stock.item_kind,
                    classification_source: 'mikro_stock_type',
                    classification_contract_version: 'technical-service-part-classification-v2',
                    quantity,
                    unit_price: unitPrice,
                    unit_price_label: `${unitPrice.toLocaleString('tr-TR', { minimumFractionDigits: 2 })} TL`,
                    line_total: lineTotal,
                    line_total_label: `${lineTotal.toLocaleString('tr-TR', { minimumFractionDigits: 2 })} TL`,
                    currency: payload.currency ?? 'TRY',
                    warehouse_code: null,
                    stock_source: 'mikro',
                    stock_source_label: 'Mikro API',
                    stock_freshness_at: '2026-08-14T17:45:00+03:00',
                    availability_verified: stock.physical_stock_total !== null,
                    physical_stock_verified: stock.physical_stock_total !== null,
                    physical_stock_state: Number(stock.physical_stock_total) > 0 ? 'positive' as const : 'unverified' as const,
                    physical_stock_total: stock.physical_stock_total,
                    physical_stock_total_snapshot: stock.physical_stock_total,
                    physical_stock_total_label: stock.physical_stock_total_label,
                    physical_stock_contract_version: 'technical-service-part-physical-stock-v1',
                    stock_status_label: stock.physical_stock_total !== null ? `Stokta: ${stock.physical_stock_total_label} ${stock.unit_code}` : 'Stok doğrulanamadı',
                    serial_tracking_required: stock.serial_tracking_state === 'required',
                    selected_part_serial: null,
                  }
                })
              : []
            const requestedAmount = Number(payload.amount ?? payload.service_amount ?? 0)
            const orderLineTotal = supplier === 'emaks_prime'
              ? Math.round(partLines.reduce((total, line) => total + Number(line.line_total ?? 0), 0) * 100) / 100
              : commercialMode === 'free' && deliveryMode === 'shipment' ? 0 : requestedAmount
            const amount = supplier === 'emaks_prime' ? orderLineTotal : requestedAmount
            const collectionAmount = collectionRequired ? amount : 0
            const readinessBlocked = purpose === 'part_charge'
              && supplier === 'emaks_prime'
              && (taxMode === 'standard_from_mikro'
                || partLines.some((line) => line.serial_tracking_state === 'required' && !line.selected_part_serial))
            const paymentStatus = collectionRequired ? (paymentLinkRequired && !readinessBlocked ? 'paid' : 'pending') : 'not_required'
            const stateValue = readinessBlocked
              ? 'draft'
              : paymentLinkRequired
              ? 'paid_waiting_mikro_write'
              : paymentStatus === 'not_required' ? 'ready_without_collection' : 'manual_collection_pending'
            const stateLabel = readinessBlocked
              ? 'Parça taslağı; stok uygunluğu bekleniyor'
              : paymentLinkRequired
              ? (supplier === 'technician' ? 'Ödeme alındı; Mikro siparişi gerekmiyor' : 'Ödeme alındı; Mikro yazımı bekliyor')
              : paymentStatus === 'not_required' ? 'Tahsilat gerekmiyor' : 'Ödeme bekleniyor'
            const manualBilling = orderInput.billing
            const billing = orderInput.billing_source === 'manual_billing_draft' && manualBilling
              ? {
                  source: 'manual_billing_draft',
                  billing_type: manualBilling.billing_type,
                  first_name: manualBilling.first_name,
                  last_name: manualBilling.last_name,
                  legal_title: manualBilling.legal_title,
                  name_or_title: manualBilling.billing_type === 'company'
                    ? manualBilling.legal_title
                    : [manualBilling.first_name, manualBilling.last_name].filter(Boolean).join(' '),
                  phone: manualBilling.phone,
                  email: manualBilling.email,
                  tckn: manualBilling.tckn,
                  vkn: manualBilling.vkn,
                  tax_office: manualBilling.tax_office,
                  address: manualBilling.address,
                  city: manualBilling.city,
                  district: manualBilling.district,
                  postal_code: manualBilling.postal_code,
                }
              : {
                  source: 'mrn_customer',
                  billing_type: 'individual',
                  first_name: request.customer,
                  last_name: null,
                  legal_title: null,
                  name_or_title: request.customer,
                  phone: request.phone,
                  address: request.address,
                  city: request.city,
                  district: request.district,
                }
            const deliveryTarget = shipmentRequired
              ? (sameAsBilling ? 'billing_address' : orderInput.delivery_target ?? null)
              : deliveryMode === 'hand_delivery' ? orderInput.delivery_target ?? null : null
            const shipping = shipmentRequired ? {
              recipient_name: sameAsBilling ? billing.name_or_title : request.technician,
              recipient_phone: sameAsBilling ? billing.phone : request.technicianPhone,
              address: sameAsBilling ? billing.address : request.technicianProfile?.address,
              city: sameAsBilling ? billing.city : request.technicianProfile?.city,
              district: sameAsBilling ? billing.district : request.technicianProfile?.district,
            } : null
            const contextLines = supplier === 'emaks_prime'
              ? partLines
              : supplier === 'technician'
                ? [{
                    id: null,
                    line_key: orderInput.technician_part_code ?? orderInput.technician_part_name ?? 'technician-part',
                    position: 1,
                    item_code: orderInput.technician_part_code ?? null,
                    item_name: orderInput.technician_part_name ?? 'Usta parçası',
                    item_short_name: null,
                    item_kind: 'part' as const,
                    classification_source: 'technician_declaration',
                    classification_contract_version: 'technical-service-part-classification-v1',
                    quantity: Number(orderInput.quantity ?? 1),
                    unit_code: 'ADET',
                    unit_price: amount,
                    unit_price_label: `${amount.toLocaleString('tr-TR', { minimumFractionDigits: 2 })} TL`,
                    line_total: amount,
                    line_total_label: `${amount.toLocaleString('tr-TR', { minimumFractionDigits: 2 })} TL`,
                    currency: payload.currency ?? 'TRY',
                    warehouse_code: null,
                    stock_source: 'technician_declaration',
                    stock_source_label: 'Usta beyanı',
                    stock_freshness_at: '2026-08-14T15:00:00+03:00',
                    availability_verified: false,
                    serial_tracking_state: 'not_required' as const,
                    serial_tracking_required: false,
                    selected_part_serial: null,
                  }]
                : []
            const firstPart = contextLines[0] ?? null
            const totalQuantity = contextLines.reduce((total, line) => total + Number(line.quantity ?? 0), 0)
            const money = (value: number) => `${value.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} TL`
            const descriptionLines = contextLines.map((line, index) => [
              `${index + 1}. ${line.quantity} ${line.unit_code ?? 'ADET'} · ${line.item_code ?? '-'} · ${line.item_name}`,
              `   BİRİM TUTAR: ${money(Number(line.unit_price ?? 0))}`,
              `   SATIR TOPLAMI: ${money(Number(line.line_total ?? 0))}`,
            ].join('\n')).join('\n\n')
            const context: ServiceRequestPaymentOrderContext = {
              id: 9800 + state.paymentCreateCount,
              payment_id: paymentLinkRequired && !readinessBlocked ? 9900 + state.paymentCreateCount : null,
              request_id: request.id,
              root_request_id: request.id,
              srv_request_id: null,
              payment_purpose: purpose,
              purpose_label: purpose === 'part_charge' ? 'Parça ödemesi' : 'Montaj ücreti tahsilatı',
              context_type: purpose === 'mount_collection' ? 'mount_service' : supplier === 'technician' ? 'technician_supplied_part' : 'part_sale',
              state: stateValue,
              state_label: stateLabel,
              desired_mikro_series: desiredSeries,
              tax_mode: taxMode,
              tax_label: taxMode === 'none' ? 'Yok / %0' : taxMode === 'standard_from_mikro_service_item' ? 'Mikro hizmet kartından' : taxMode === 'standard_from_mikro' ? 'Mikro stok kartından' : null,
              vat_rate: taxMode === 'none' ? 0 : null,
              future_mikro_write_state: supplier === 'technician' ? 'not_required' : 'not_authorized',
              future_mikro_write_label: supplier === 'technician' ? 'Mikro siparişi gerekmiyor' : 'Mikro yazımı bu aşamada kapalı',
              billing,
              shipping_same_as_billing: sameAsBilling,
              delivery_target: deliveryTarget,
              delivery_target_label: deliveryTarget === 'technician' ? 'Usta' : deliveryTarget === 'mrn_customer' ? 'MRN müşterisi' : deliveryTarget === 'billing_address' ? 'Fatura adresi' : null,
              shipping,
              part_supplier: supplier,
              part_supplier_label: supplier === 'technician' ? 'Usta' : supplier === 'emaks_prime' ? 'EMAKS Prime' : null,
              collection_allocation: supplier === 'technician' ? 'pay_technician' : supplier === 'emaks_prime' ? 'retain_company' : null,
              collection_allocation_label: supplier === 'technician' ? 'Ustaya hakediş olarak eklenecek' : supplier === 'emaks_prime' ? 'Şirkette bırakılacak' : null,
              part: purpose === 'part_charge' ? firstPart : null,
              lines: purpose === 'part_charge' ? contextLines : [],
              line_count: purpose === 'part_charge' ? contextLines.length : 0,
              total_quantity: purpose === 'part_charge' ? totalQuantity : 0,
              total_quantity_label: String(totalQuantity),
              commercial_mode: commercialMode,
              commercial_mode_label: commercialMode === 'free' ? 'Ücretsiz' : commercialMode === 'paid' ? 'Ücretli' : null,
              delivery_mode: deliveryMode,
              delivery_mode_label: deliveryMode === 'hand_delivery' ? 'Elden' : deliveryMode === 'shipment' ? 'Sevk' : 'Yok',
              delivery_status: deliveryMode === 'hand_delivery' ? 'pending' : null,
              delivery_status_label: deliveryMode === 'hand_delivery' ? 'Teslim bekliyor' : null,
              payment_collection_mode: paymentLinkRequired ? 'payment_link' : collectionRequired ? 'manual' : 'none',
              payment_status: paymentStatus,
              payment_status_label: paymentStatus === 'paid' ? 'Ödeme alındı' : paymentStatus === 'pending' ? 'Ödeme bekleniyor' : paymentStatus === 'not_required' ? 'Tahsilat gerekmiyor' : 'İptal',
              payment_status_source: paymentLinkRequired && !readinessBlocked ? 'provider' : 'system',
              payment_status_source_label: paymentLinkRequired && !readinessBlocked ? 'Ödeme sağlayıcısı' : 'Sistem',
              payment_link_required: paymentLinkRequired,
              collection_required: collectionRequired,
              order_line_unit_price: contextLines.length === 1 ? Number(contextLines[0]?.unit_price ?? orderLineTotal) : 0,
              order_line_unit_price_label: money(contextLines.length === 1 ? Number(contextLines[0]?.unit_price ?? orderLineTotal) : 0),
              order_line_total: orderLineTotal,
              order_line_total_label: money(orderLineTotal),
              order_reference_total: orderLineTotal,
              order_reference_total_label: money(orderLineTotal),
              collection_amount: collectionAmount,
              collection_amount_label: money(collectionAmount),
              future_order_trigger: paymentLinkRequired ? 'payment_paid' : deliveryMode === 'hand_delivery' && commercialMode === 'paid' ? 'delivery_recorded' : 'ops_approved',
              finance_review_required: false,
              related_product_serial: request.serialNumber,
              charged_amount: amount,
              charged_amount_label: money(amount),
              currency: payload.currency ?? 'TRY',
              shipment_required: shipmentRequired,
              future_carrier_state: shipmentRequired ? 'waiting_future_integration' : 'not_required',
              future_carrier_label: shipmentRequired ? 'Kargo hazırlığı bekliyor; HepsiJet entegrasyonu çalıştırılmayacak' : 'Sevkiyat yok',
              readiness: readinessBlocked ? {
                ready: false,
                order_ready: false,
                payment_ready: false,
                blocker_codes: taxMode === 'standard_from_mikro' ? ['vat_unverified'] : ['part_serial_selection_unverified'],
                blockers: taxMode === 'standard_from_mikro'
                  ? ['KDV bilgisi Mikro stok kartından doğrulanmadan ücretli sevk hazırlığı tamamlanamaz.']
                  : ['Bu parça seri numarasıyla takip ediliyor. Güncel parça seri seçimi doğrulanmadan ödeme/sipariş hazırlığı tamamlanamaz.'],
              } : {
                ready: true,
                order_ready: true,
                payment_ready: true,
                blocker_codes: [],
                blockers: [],
              },
              description2_preview: purpose === 'mount_collection'
                ? `MRN/SRV: ${request.mrn}\nHİZMET: MONTAJ\nSERİ NO: ${request.serialNumber}\nSEVKİYAT: YOK`
                : `${shipmentRequired && !sameAsBilling ? 'SEVK ADRESİ FARKLIDIR.\n' : ''}MRN/SRV: ${request.mrn}\nİLGİLİ ÜRÜN SERİ NO: ${request.serialNumber}\n\nPARÇALAR:\n${descriptionLines}\n\nPARÇA KALEMİ: ${contextLines.length}\nTOPLAM ADET: ${totalQuantity}\nSİPARİŞ/REFERANS TOPLAMI: ${money(orderLineTotal)}\nTAHSİLAT TOPLAMI: ${money(collectionAmount)}`,
              description2_version: purpose === 'part_charge' ? 2 : 1,
              context_hash: orderInput.expected_context_hash ?? 'f'.repeat(64),
              revision: orderInput.expected_revision ?? 1,
              mikro_write_execution_count: 0,
              carrier_execution_count: 0,
            }
            const paidPayment: ServiceRequestExtraMountPayment | null = paymentLinkRequired && !readinessBlocked ? {
              id: 9900 + state.paymentCreateCount,
              request_id: request.id,
              status: 'paid',
              status_label: 'Ödendi',
              amount,
              amount_label: money(amount),
              currency: payload.currency ?? 'TRY',
              provider: 'fake',
              provider_mode: 'local',
              purpose,
              source: 'operation_order_context_payment',
              readonly: true,
              paid_at: '2026-08-14T06:30:00+03:00',
              order_context: context,
            } : null
            setRequest((current) => ({
              ...current,
              saleAndPayment: {
                ...current.saleAndPayment,
                part_order_context: purpose === 'part_charge' ? context : current.saleAndPayment?.part_order_context,
                history_loaded: true,
                mount_payments: paidPayment ? {
                  rows: [paidPayment],
                  paid_rows: [paidPayment],
                  pending_rows: [],
                  cancelled_rows: [],
                  latest: paidPayment,
                  latest_paid: paidPayment,
                  paid_total_amount: paidPayment.amount ?? 0,
                  paid_total_amount_label: paidPayment.amount_label,
                  has_paid: true,
                  has_pending: false,
                  has_cancelled: false,
                } : current.saleAndPayment?.mount_payments,
              },
            }))
          } finally {
            paymentCreateLock.current = false
          }
        }}
        onPaymentOrderContextStateUpdate={async (contextId, payload) => {
          state.paymentOrderStateUpdateCount += 1
          setPaymentOrderStateUpdateCount(state.paymentOrderStateUpdateCount)
          setRequest((current) => {
            const context = current.saleAndPayment?.part_order_context

            if (!context || String(context.id) !== String(contextId)) {
              return current
            }

            const deliveredToTechnician = payload.action === 'record_delivery'
              && context.delivery_target === 'technician'
              && context.commercial_mode === 'paid'
              && context.delivery_mode === 'hand_delivery'
            const paymentStatus = deliveredToTechnician
              ? 'paid'
              : payload.action === 'set_payment_status' ? payload.payment_status ?? context.payment_status : context.payment_status
            const nextContext: ServiceRequestPaymentOrderContext = {
              ...context,
              revision: Number(context.revision ?? 0) + 1,
              delivery_status: payload.action === 'record_delivery' ? 'delivered' : context.delivery_status,
              delivery_status_label: payload.action === 'record_delivery' ? 'Teslim edildi' : context.delivery_status_label,
              payment_status: paymentStatus,
              payment_status_label: paymentStatus === 'paid' ? 'Ödeme alındı' : paymentStatus === 'pending' ? 'Ödeme bekleniyor' : 'İptal',
              payment_status_source: deliveredToTechnician ? 'auto_from_technician_delivery' : payload.action === 'set_payment_status' ? 'manual' : context.payment_status_source,
              payment_status_source_label: deliveredToTechnician ? 'Teslim kaydı' : payload.action === 'set_payment_status' ? 'OPS düzeltmesi' : context.payment_status_source_label,
              payment_status_reason: payload.reason ?? context.payment_status_reason,
              finance_review_required: context.delivery_status === 'delivered' && paymentStatus === 'cancelled',
              state: paymentStatus === 'paid' ? 'manual_collection_paid' : paymentStatus === 'cancelled' ? 'cancelled' : 'manual_collection_pending',
              state_label: paymentStatus === 'paid' ? 'Ödeme alındı' : paymentStatus === 'cancelled' ? 'İptal edildi' : 'Ödeme bekleniyor',
            }

            return {
              ...current,
              saleAndPayment: {
                ...current.saleAndPayment,
                part_order_context: nextContext,
              },
            }
          })
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
            İşçilik: {lastAssignmentDraft?.labor_amount ?? request.assignmentOffer?.earning_snapshot?.labor_amount ?? 0} TL · Yol: {lastAssignmentDraft?.route_fee_amount ?? request.assignmentOffer?.earning_snapshot?.route_fee_amount ?? 0} TL · Toplam: {(lastAssignmentDraft?.labor_amount ?? request.assignmentOffer?.earning_snapshot?.labor_amount ?? 0) + (lastAssignmentDraft?.route_fee_amount ?? request.assignmentOffer?.earning_snapshot?.route_fee_amount ?? 0)} TL
          </div>
          <div data-testid="assignment-final-popup-preview" className="whitespace-pre-wrap">
            <span className="block">Harita:</span>
            <a
              data-testid="assignment-preview-map-link"
              href={request.location?.map_url ?? undefined}
              target="_blank"
              rel="noreferrer"
              className="block"
            >
              {request.location?.map_url}
            </a>
          </div>
          {assignmentRequiresReason ? (
            <label className="grid gap-2">
              Yeniden atama nedeni *
              <textarea
                ref={assignmentReasonRef}
                data-testid="assignment-reason-input"
                value={assignmentReason}
                onChange={(event) => {
                  setAssignmentReason(event.target.value)
                  setAssignmentReasonError(null)
                }}
                placeholder="Önceki ustanın işi neden tamamlayamadığını yazınız"
                aria-invalid={assignmentReasonError ? 'true' : 'false'}
              />
              {assignmentReasonError ? <span data-testid="assignment-reason-error">{assignmentReasonError}</span> : null}
              <span>Bu açıklama eski atamanın tarihçesinde saklanacaktır.</span>
            </label>
          ) : null}
          <button
            type="button"
            data-testid="assignment-final-popup-confirm"
            onClick={() => {
              if (assignmentRequiresReason && assignmentReason.trim().length < 5) {
                setAssignmentReasonError('Yeniden atama nedeni yazınız.')
                assignmentReasonRef.current?.focus()

                return
              }

              if (assignmentConfirmInFlightRef.current) {
                return
              }

              assignmentConfirmInFlightRef.current = true
              state.assignmentConfirmCount += 1
              setAssignmentConfirmCount(state.assignmentConfirmCount)
              setRequest((current) => ({
                ...current,
                technician: 'Test Usta',
                technicianId: '111',
                technicianPhone: '905467647428',
                technicianProfile: {
                  id: '111',
                  name: 'Test Usta',
                  phone: '905467647428',
                  city: 'Denizli',
                  district: 'Pamukkale',
                  active: true,
                },
                technicianPaymentAmount: lastAssignmentDraft?.labor_amount ?? current.technicianPaymentAmount,
                travelFeeAmount: lastAssignmentDraft?.route_fee_amount ?? current.travelFeeAmount,
              }))
              setAssignmentSuccess('Atama Test Usta olarak güncellendi.')
              assignmentPopupOpenRef.current = false
              setAssignmentPopupOpen(false)
            }}
          >Atamayı onayla ve mesajı hazırla</button>
        </section>
      ) : null}
    </>
  )
}

createRoot(document.getElementById('root')!).render(<Harness />)
window.__assignmentEarningDomReady = true

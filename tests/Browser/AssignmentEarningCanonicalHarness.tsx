import { useState } from 'react'
import { createRoot } from 'react-dom/client'
import { ServiceRequestDetails } from '../../resources/js/components/technical-service/ServiceRequestDetails'
import type { ServiceRequest, ServiceRequestCanonicalEarningSnapshot, ServiceRequestTechnicianEarningMessagePayload } from '../../resources/js/components/technical-service/types'
import '../../resources/css/app.css'

type HarnessState = {
  saveCount: number
  sendCount: number
  failNextSave: boolean
  lastSavedSnapshot: ServiceRequestCanonicalEarningSnapshot | null
  lastSendPayload: ServiceRequestTechnicianEarningMessagePayload | null
}

declare global {
  interface Window {
    __assignmentEarningDomReady?: boolean
    __assignmentEarningDomState?: HarnessState
  }
}

const initialRevision = 'a'.repeat(64)
const savedRevision = 'b'.repeat(64)
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

const state: HarnessState = {
  saveCount: 0,
  sendCount: 0,
  failNextSave: false,
  lastSavedSnapshot: null,
  lastSendPayload: null,
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
    `Toplam hakediş: ${money(snapshot.total_amount)}`,
    'Randevu: -',
    snapshot.operation_note ? `Not: ${snapshot.operation_note}` : null,
    'İş kartı:',
    'http://192.168.1.10:8000/partner/job/dom-token',
  ].filter((line): line is string => typeof line === 'string').join('\n')
}

function Harness() {
  const [request, setRequest] = useState(initialRequest)
  const [saveCount, setSaveCount] = useState(0)
  const [sendCount, setSendCount] = useState(0)
  const [lastSendRevision, setLastSendRevision] = useState('')
  const [failNextSave, setFailNextSave] = useState(false)

  return (
    <>
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

          const snapshot: ServiceRequestCanonicalEarningSnapshot = {
            schema_version: 1,
            assignment_id: 104,
            technician_id: 111,
            labor_amount: payload.labor_amount,
            route_fee_amount: payload.route_fee_amount,
            total_amount: payload.labor_amount + payload.route_fee_amount,
            currency: 'TRY',
            operation_note: payload.note ?? null,
            revision: savedRevision,
            persisted_at: '2026-08-10T05:30:00+00:00',
          }
          const messagePreview = canonicalPreview(snapshot)
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
          }

          state.lastSavedSnapshot = snapshot
          setRequest(nextRequest)

          return {
            status: 'revised',
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
      />
    </>
  )
}

createRoot(document.getElementById('root')!).render(<Harness />)
window.__assignmentEarningDomReady = true

import { CheckCircle2, ChevronDown, Pencil, XCircle } from 'lucide-react'
import { useRef, useState } from 'react'
import type { ReactNode, Ref } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import type { MikroMountCheckResult, ServicePriority, ServiceRequest, ServiceRequestEvent, ServiceRequestExtraMountPayment, ServiceRequestExtraMountPaymentPayload, ServiceRequestInvoiceSerial, ServiceRequestRouteQuote, ServiceRequestRouteQuoteManualPayload, ServiceRequestTechnicianEarningMessagePayload, WarrantySerialResponse } from './types'
import { formatTechnicalServiceDate, formatTechnicalServiceDateTime, getServicePaymentInfo } from './utils'

type OpsDoorPhotoType = 'ops_door_front_photo' | 'ops_door_side_photo' | 'ops_door_back_photo' | 'ops_door_photo'
type OpsExtraDocumentType = 'ops_extra_photo' | OpsDoorPhotoType | 'ops_additional_document'

type PaymentLinkSendTarget = {
  id?: number | string | null
  status?: string | null
  payment_url?: string | null
  copy_url?: string | null
  amount?: number | null
}

const OPS_DOOR_PHOTO_FIELD_CODES = new Set<string>([
  'ops_door_front_photo',
  'ops_door_side_photo',
  'ops_door_back_photo',
  'ops_door_photo',
])

const OPS_DOOR_PHOTO_OPTIONS: Array<{ value: OpsDoorPhotoType, label: string }> = [
  { value: 'ops_door_front_photo', label: 'Kapı ön yüzü' },
  { value: 'ops_door_side_photo', label: 'Kapı yan yüzü' },
  { value: 'ops_door_back_photo', label: 'Kapı arka yüzü' },
  { value: 'ops_door_photo', label: 'Ek kapı görseli' },
]

const statusVariant = (status: ServiceRequest['status']) => {
  switch (status) {
    case 'Yeni':
      return 'secondary'
    case 'Atandı':
      return 'default'
    case 'Randevulu':
      return 'warning'
    case 'Devam Ediyor':
      return 'accent'
    case 'Tamamlandı':
      return 'positive'
    case 'İptal':
      return 'destructive'
    default:
      return 'default'
  }
}

type ServiceRequestDetailsProps = {
  request: ServiceRequest
  opsDetailVisibility?: OpsDetailVisibilitySettings
  displayMrn?: string
  events: ServiceRequestEvent[]
  loading: boolean
  error?: string | null
  mikroMountCheck?: MikroMountCheckResult | null
  mikroMountLoading?: boolean
  mikroMountError?: string | null
  warranty?: WarrantySerialResponse | null
  warrantyLoading?: boolean
  warrantyError?: string | null
  onAssign?: () => void
  onSchedule?: () => void
  onComplete?: () => void
  onReopen?: () => void
  onPriorityChange?: (priority: ServicePriority) => void | Promise<void>
  onWorkflowAction?: (action: string) => void
  onOperationControlChange?: (payload: Partial<NonNullable<ServiceRequest['operationControl']>>) => void | Promise<void>
  onAdminOverrideSubmit?: (payload: { field_key: string, new_value: unknown, reason: string, mode?: 'apply' | 'request' }) => void | Promise<void>
  onAdminOverrideReview?: (overrideId: number | string, action: 'approve' | 'reject', note?: string | null) => void | Promise<void>
  onInvoiceSerialRecheck?: () => void | Promise<void>
  onInvoiceSerialAdd?: (serialId: number | string) => void | Promise<void>
  onInvoiceSerialRemove?: (serialId: number | string) => void | Promise<void>
  onInvoiceSerialAddAll?: () => void | Promise<void>
  priorityUpdateInFlight?: boolean
  priorityUpdateError?: string | null
  workflowActionInFlight?: string | null
  operationControlUpdateInFlight?: boolean
  operationControlUpdateError?: string | null
  adminOverrideInFlight?: boolean
  adminOverrideError?: string | null
  invoiceSerialRecheckInFlight?: boolean
  invoiceSerialRecheckError?: string | null
  invoiceSerialActionInFlight?: string | null
  invoiceSerialActionError?: string | null
  technicianSuggestions?: Array<{
    id: string
    name: string
    location: string
    phone?: string | null
    priority?: string | number | null
    latitude?: number | string | null
    longitude?: number | string | null
    startLatitude?: number | string | null
    startLongitude?: number | string | null
    needsReview?: boolean
    hasLocation?: boolean
    hasAddressInfo?: boolean
    hasPlusCodeInfo?: boolean
    hasCoordinates?: boolean
    routeReady?: boolean
    addressSummary?: string | null
    locationCode?: string | null
    routeLocationMessage?: string
    distanceKmLabel: string
    scheduledCount: number
    availableSlots: string[]
    technicianAmountLabel: string
    travelAmountLabel: string
    totalCostLabel: string
    costDeltaLabel: string
    recommended: boolean
    estimatedRoundTripKm?: number | null
  }>
  scheduleSupport?: {
    scheduledLabel: string
    preferredLabel: string
    customerContactLabel: string
    slotSuggestions: string[]
  } | null
  selectedTechnicianId?: string | null
  routeQuoteLoading?: boolean
  routeQuoteError?: string | null
  routeQuoteManualSaveLoading?: boolean
  routeQuoteManualSaveError?: string | null
  extraPaymentCreateLoading?: boolean
  extraPaymentCreateError?: string | null
  technicianEarningMessageLoading?: boolean
  technicianEarningMessageError?: string | null
  assignLoading?: boolean
  canSubmitAssign?: boolean
  assignError?: string | null
  mountExclusionAcknowledged?: boolean
  mountExclusionNote?: string
  onMountExclusionAcknowledgedChange?: (checked: boolean) => void
  onMountExclusionNoteChange?: (note: string) => void
  onTechnicianSelect?: (technicianId: string, estimatedRoundTripKm?: number | null) => void
  onRouteQuoteCalculate?: () => void | Promise<void>
  onRouteQuoteManualSave?: (payload: ServiceRequestRouteQuoteManualPayload) => void | Promise<void>
  onExtraMountPaymentCreate?: (payload: ServiceRequestExtraMountPaymentPayload) => void | Promise<void>
  onMountPaymentCancel?: (paymentId: number | string, payload?: { reason?: string | null }) => void | Promise<void>
  onMountPaymentSync?: (paymentId: number | string) => void | Promise<void>
  onMountPaymentSend?: (paymentId: number | string) => void | Promise<void>
  onTechnicianEarningMessageCreate?: (payload: ServiceRequestTechnicianEarningMessagePayload) => void | Promise<{ message_text?: string, whatsapp_url?: string, copy_text?: string } | void>
  onAssignSelectedTechnician?: () => void | Promise<void>
  onPartnerAppointmentProposalApprove?: (actionId: number | string, payload?: { note?: string | null, selected_slot_index?: number }) => void | Promise<void>
  onPartnerAppointmentProposalReject?: (actionId: number | string, payload: { note: string, status?: string }) => void | Promise<void>
  onPartnerCompletionApprove?: (actionId: number | string, payload?: { note?: string | null, approved_visit_ids?: Array<number | string> }) => void | Promise<void>
  onRevisitServiceVisitCreate?: (actionId: number | string, payload?: { note?: string | null }) => void | Promise<void>
  onPartRequestCreate?: (payload: { part_name: string, part_code?: string | null, quantity?: number | null, charge_decision: 'free' | 'chargeable', service_amount?: number | null, part_amount?: number | null, note?: string | null, partner_message?: string | null, customer_message?: string | null }) => void | Promise<void>
  onPartRequestTransition?: (partRequestId: number | string, payload: { status: string, note?: string | null, partner_message?: string | null, shipment_provider?: string | null, tracking_no?: string | null, charge_decision?: string | null, service_amount?: number | null, part_amount?: number | null, customer_message?: string | null }) => void | Promise<void>
  onPartRequestServiceVisitCreate?: (partRequestId: number | string, payload?: { reason?: string | null }) => void | Promise<void>
  onAssignmentOfferUpdate?: (offerId: number | string, payload: { labor_amount: number, route_fee_amount: number, total_amount?: number, note?: string | null }) => void | Promise<void>
  onFieldDocumentReview?: (uploadId: number | string, payload: { status: 'accepted' | 'rejected', note?: string | null }) => void | Promise<void>
  onOpsExtraDocumentUpload?: (payload: { files: File[], note?: string | null, document_type?: string | null }) => void | Promise<void>
  onCustomerApprovalResend?: (payload?: { note?: string | null }) => void | Promise<void>
  fieldDocumentReviewInFlight?: string | null
  fieldDocumentReviewError?: string | null
  customerApprovalResendLoading?: boolean
  customerApprovalResendError?: string | null
}

type OpsDetailVisibilitySettings = {
  show_mount_excluded_approval_block: boolean
  show_payment_mount_control_block: boolean
  show_address_control_block: boolean
}

const DEFAULT_OPS_DETAIL_VISIBILITY: OpsDetailVisibilitySettings = {
  show_mount_excluded_approval_block: false,
  show_payment_mount_control_block: false,
  show_address_control_block: false,
}

const eventTime = (timestamp: string): string => {
  return formatTechnicalServiceDateTime(timestamp, 'Bilinmiyor')
}

const cleanDisplayText = (value: string | null | undefined): string => {
  if (value === null || value === undefined) {
    return ''
  }

  return String(value)
    .replaceAll('M??teri', 'Müşteri')
    .replaceAll('Planl?', 'Planlı')
    .replaceAll('Tamamland?', 'Tamamlandı')
    .replaceAll('Atamas?', 'Ataması')
    .replaceAll('Onay?', 'Onayı')
    .replaceAll('onaylad?', 'onayladı')
    .replaceAll('onayland?', 'onaylandı')
    .replaceAll('Ã‡', 'Ç')
    .replaceAll('Ã–', 'Ö')
    .replaceAll('Ãœ', 'Ü')
    .replaceAll('Ã§', 'ç')
    .replaceAll('Ã¶', 'ö')
    .replaceAll('Ã¼', 'ü')
    .replaceAll('Ä°', 'İ')
    .replaceAll('Ä±', 'ı')
    .replaceAll('ÄŸ', 'ğ')
    .replaceAll('ÅŸ', 'ş')
    .replaceAll('Åž', 'Ş')
    .replaceAll('Â', '')
    .replaceAll('ï¿½', '')
}

const formatDisplayValue = (value: string | null | undefined): string => {
  const normalized = cleanDisplayText(value).trim()

  return normalized !== '' ? normalized : '-'
}

const hasText = (value: string | null | undefined): boolean => String(value ?? '').trim() !== ''

const displayOrEmpty = (value: string | null | undefined, fallback: string): string => {
  const normalized = cleanDisplayText(value).trim()

  return normalized !== '' ? normalized : fallback
}

const optionalMetricValue = (value: string | number | null | undefined): string | null => {
  const normalized = cleanDisplayText(value === null || value === undefined ? '' : String(value)).trim()

  return normalized !== '' && normalized !== '-' ? normalized : null
}

const dateTimeOrEmpty = (value: string | null | undefined, fallback: string): string => (
  hasText(value) ? formatTechnicalServiceDateTime(value, fallback) : fallback
)

const dateOrDateTimeOrEmpty = (value: string | null | undefined, fallback: string): string => {
  if (!hasText(value)) {
    return fallback
  }

  return /^\d{4}-\d{2}-\d{2}$/.test(String(value))
    ? formatTechnicalServiceDate(value, fallback)
    : formatTechnicalServiceDateTime(value, fallback)
}

const actionCodeLabels: Record<string, string> = {
  accepted: 'İş kabul edildi',
  assignment_archived: 'Önceki usta ataması arşivlendi',
  assignment_created: 'Usta atandı',
  assignment_reassigned: 'Servis ataması güncellendi',
  assignment_updated: 'Servis ataması güncellendi',
  assignment_offer: 'Hakediş teklifi oluşturuldu',
  assignment_offer_sent: 'Hakediş bilgisi hazırlandı',
  assignment_offer_cancelled: 'Eski hakediş teklifi iptal edildi',
  reassign_after_review_resolved: 'İş yeniden atamaya alındı',
  appointment_accepted_by_technician: 'Randevu onaylandı',
  appointment_proposed: 'Randevu önerildi',
  partner_portal_appointment_proposed: 'Randevu önerildi',
  appointment_change_requested: 'Randevu değişikliği istendi',
  schedule_updated: 'Randevu güncellendi',
  appointment_approved: 'Randevu onaylandı',
  appointment_updated: 'Randevu güncellendi',
  customer_otp_requested: 'Müşteri onayı istendi',
  customer_approval_request: 'Müşteri onayı istendi',
  customer_approval_request_resent: 'Müşteri onayı istendi',
  customer_approval_confirmed: 'Müşteri onayladı',
  customer_approved: 'Müşteri onayladı',
  customer_approval_rejected: 'Müşteri onaylamadı',
  customer_rejected: 'Müşteri onaylamadı',
  completion_submitted: 'Tamamlamaya gönderildi',
  job_rejected: 'Usta işi reddetti',
  revisit_requested: 'Tekrar ziyaret istendi',
  support_requested: 'Ek talep oluşturuldu',
  partner_portal_support_requested: 'Ek talep oluşturuldu',
  technical_support: 'Teknik destek istendi',
  price_revision_requested: 'Hakediş revize talep edildi',
  photos_uploaded: 'Fotoğraf yüklendi',
  route_quote_created: 'Yol hakedişi hesaplandı',
  route_quote_updated: 'Yol hakedişi güncellendi',
  manual_fee: 'Manuel ücret girildi',
  payment_paid: 'Ödeme alındı',
  mount_payment_paid: 'Ödeme alındı',
  mount_payment_link_created: 'Ödeme linki oluşturuldu',
  payment_pending: 'Ödeme bekleniyor',
  payment_failed: 'Ödeme başarısız',
  final_check: 'Son kontrol bekliyor',
  submitted: 'Gönderildi',
  applied: 'Uygulandı',
  revised: 'Revize edildi',
  ops_review: 'Operasyon incelemesinde',
  note_added: 'Not eklendi',
  contact_customer_called: 'Müşteri arandı',
  part_request_created: 'Parça talebi oluşturuldu',
  part_requested: 'Parça talebi oluşturuldu',
  part_approved: 'Parça talebi onaylandı',
  part_ordered: 'Parça tedarikte',
  part_sent: 'Parça gönderildi',
  part_received: 'Parça teslim alındı',
  part_request_approved: 'Parça talebi onaylandı',
  part_request_ordered: 'Parça tedarikte',
  part_request_sent: 'Parça gönderildi',
  part_request_received: 'Parça teslim alındı',
  part_request_service_visit_required: 'Parça sonrası servis gerekli',
  part_request_service_visit_created: 'Parça sonrası servis oluşturuldu',
  part_request_rejected: 'Parça talebi reddedildi',
  part_request_srv_created: 'Parça sonrası servis oluşturuldu',
  service_visit_created: 'Servis kaydı oluşturuldu',
  srv_created: 'Servis kaydı oluşturuldu',
  srv_child_created: 'Servis kaydı oluşturuldu',
  second_visit_required: 'Tekrar randevu gerekli',
  technician_updated: 'Usta bilgisi güncellendi',
  technician_earning_message_sent: 'Hakediş bilgisi gönderildi',
  settlement_review_approved: 'Hakediş mutabakatı onaylandı',
  settlement_review_corrected: 'Hakediş mutabakatı düzeltildi',
  settlement_review_excluded: 'Hakedişe dahil değil kararı',
  technician_revision_requested: 'Usta revize talep etti',
  field_override_requested: 'Düzeltme talebi oluşturuldu',
  field_override_applied: 'Düzeltme uygulandı',
  field_override_rejected: 'Düzeltme talebi reddedildi',
  admin_recompute_requested: 'Yeniden hesaplama kontrolü kaydedildi',
  customer_called: 'Müşteri arandı',
  cancel: 'İptal edildi',
  cancelled: 'İptal edildi',
}

const hasRawCodeShape = (value: string): boolean => /^[a-z0-9_-]+$/i.test(value)

const safeActionLabelFallback = (value: string): string => {
  if (value === '') {
    return 'Kayıt detayı'
  }

  return hasRawCodeShape(value) ? 'Kayıt detayı' : value
}

const actionLabel = (code: string | null | undefined, provided?: string | null): string => {
  const normalizedProvided = cleanDisplayText(provided).trim()

  if (actionCodeLabels[normalizedProvided]) {
    return actionCodeLabels[normalizedProvided]
  }

  if (normalizedProvided !== '' && !hasRawCodeShape(normalizedProvided)) {
    return normalizedProvided
  }

  const normalized = cleanDisplayText(code).trim()

  return actionCodeLabels[normalized] ?? safeActionLabelFallback(normalized)
}

const stringValue = (source: Record<string, unknown> | null | undefined, key: string): string | null => {
  const value = source?.[key]

  return typeof value === 'string' && value.trim() !== '' ? value : null
}

const formatKmValue = (value: number | null | undefined): string => (
  typeof value === 'number' && Number.isFinite(value)
    ? `${value.toLocaleString('tr-TR', { maximumFractionDigits: 2 })} km`
    : '-'
)

const formatMoneyValue = (value: number | null | undefined): string => (
  typeof value === 'number' && Number.isFinite(value)
    ? `${value.toLocaleString('tr-TR', { maximumFractionDigits: 2 })} TL`
    : '-'
)

const correctionFieldGroups: Array<{
  group: string
  label: string
  fields: Array<{ key: string, label: string, input: 'text' | 'number' | 'datetime-local', mode?: 'apply' | 'request' }>
}> = [
  {
    group: 'customer',
    label: 'Müşteri / adres',
    fields: [
      { key: 'customer_phone', label: 'Telefon', input: 'text' },
      { key: 'customer_address', label: 'Adres', input: 'text' },
      { key: 'city', label: 'İl', input: 'text' },
      { key: 'district', label: 'İlçe', input: 'text' },
    ],
  },
  {
    group: 'schedule',
    label: 'Randevu',
    fields: [
      { key: 'appointment_at', label: 'Randevu zamanı', input: 'datetime-local' },
      { key: 'appointment_note', label: 'Randevu notu', input: 'text' },
    ],
  },
  {
    group: 'earning',
    label: 'Usta / hakediş',
    fields: [
      { key: 'assigned_technician_id', label: 'Atanan usta ID', input: 'number' },
      { key: 'labor_earning', label: 'İşçilik hakedişi', input: 'number' },
      { key: 'route_earning', label: 'Yol hakedişi', input: 'number' },
      { key: 'technician_route_distance', label: 'Yol mesafesi', input: 'number' },
    ],
  },
  {
    group: 'serial',
    label: 'Seri / ürün',
    fields: [
      { key: 'serial_no', label: 'Seri numarası', input: 'text', mode: 'request' },
      { key: 'activation_code', label: 'Aktivasyon kodu', input: 'text', mode: 'request' },
      { key: 'product_model', label: 'Ürün modeli', input: 'text', mode: 'request' },
    ],
  },
]

const correctionFieldByKey = new Map(
  correctionFieldGroups.flatMap((group) => group.fields.map((field) => [field.key, field] as const)),
)

const requestCorrectionValue = (request: ServiceRequest, fieldKey: string): string => {
  switch (fieldKey) {
    case 'customer_phone':
      return request.phone ?? ''
    case 'customer_address':
      return request.address ?? ''
    case 'city':
      return request.city ?? ''
    case 'district':
      return request.district ?? ''
    case 'appointment_at':
      return request.scheduledAt ? request.scheduledAt.slice(0, 16) : ''
    case 'appointment_note':
      return String(request.operationControl?.note ?? '')
    case 'assigned_technician_id':
      return request.technicianId ? String(request.technicianId) : ''
    case 'labor_earning':
      return numericInputValue(request.technicianPaymentAmount)
    case 'route_earning':
      return numericInputValue(request.travelFeeAmount)
    case 'technician_route_distance':
      return numericInputValue(request.travelRoundTripKm)
    case 'serial_no':
      return request.serialNumber ?? ''
    case 'activation_code':
      return request.productInfo?.activation_code ?? ''
    case 'product_model':
      return request.model ?? ''
    default:
      return ''
  }
}

const paymentStatusLabel = (status: string | null | undefined, isPaid = false): string => {
  if (isPaid) {
    return 'Ödendi'
  }

  switch (String(status ?? '').trim()) {
    case 'paid':
      return 'Ödendi'
    case 'pending':
      return 'Ödeme bekleniyor'
    case 'failed':
      return 'Ödeme başarısız'
    case 'cancelled':
      return 'İptal edildi'
    case 'expired':
      return 'Süresi doldu'
    case 'not_required':
      return 'Ödeme gerekmiyor'
    case 'skipped_multi_product':
      return 'Operasyon kontrolünde'
    default:
      return 'Ödeme bilgisi yok'
  }
}

const operationPaymentCheckLabel = (status: string | null | undefined): string => {
  switch (String(status ?? 'unreviewed')) {
    case 'yes':
      return 'Evet'
    case 'no':
      return 'Hayır'
    default:
      return 'Kontrol edilmedi'
  }
}

const roundTwo = (value: number): number => Math.round(value * 100) / 100

const numericInputValue = (value: number | null | undefined): string => (
  typeof value === 'number' && Number.isFinite(value) ? String(value) : ''
)

const parseNumericInput = (value: string): number | null => {
  const normalized = value.trim().replace(',', '.')

  if (normalized === '') {
    return null
  }

  const parsed = Number(normalized)

  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null
}

const parseCoordinateValue = (value: number | string | null | undefined): number | null => {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const parsed = typeof value === 'number' ? value : Number(String(value).trim())

  if (!Number.isFinite(parsed)) {
    return null
  }

  return parsed
}

const formatCoordinatePair = (
  latitude: number | string | null | undefined,
  longitude: number | string | null | undefined,
): string => {
  const parsedLatitude = parseCoordinateValue(latitude)
  const parsedLongitude = parseCoordinateValue(longitude)

  if (parsedLatitude === null || parsedLongitude === null) {
    return '-'
  }

  return `${parsedLatitude.toFixed(6)}, ${parsedLongitude.toFixed(6)}`
}

const routeDestinationCoordinatePair = (
  locationInfo: ServiceRequest['location'] | null,
): { latitude: number, longitude: number } | null => {
  const routeLatitude = parseCoordinateValue(locationInfo?.route_latitude)
  const routeLongitude = parseCoordinateValue(locationInfo?.route_longitude)

  if (routeLatitude !== null && routeLongitude !== null) {
    return { latitude: routeLatitude, longitude: routeLongitude }
  }

  const latitude = parseCoordinateValue(locationInfo?.latitude)
  const longitude = parseCoordinateValue(locationInfo?.longitude)

  return latitude !== null && longitude !== null ? { latitude, longitude } : null
}

const sameCoordinateValue = (
  left: number | string | null | undefined,
  right: number | string | null | undefined,
): boolean => {
  const parsedLeft = parseCoordinateValue(left)
  const parsedRight = parseCoordinateValue(right)

  return parsedLeft !== null && parsedRight !== null && Math.abs(parsedLeft - parsedRight) <= 0.000001
}

const routeQuoteMatchesCoordinates = (
  routeQuote: ServiceRequestRouteQuote | null,
  technician: { latitude?: number | string | null, longitude?: number | string | null, startLatitude?: number | string | null, startLongitude?: number | string | null } | null,
  locationInfo: ServiceRequest['location'] | null,
): boolean => {
  if (!routeQuote || !technician || !locationInfo) {
    return false
  }

  const technicianLatitude = parseCoordinateValue(technician.latitude) ?? parseCoordinateValue(technician.startLatitude)
  const technicianLongitude = parseCoordinateValue(technician.longitude) ?? parseCoordinateValue(technician.startLongitude)
  const destination = routeDestinationCoordinatePair(locationInfo)

  if (destination === null) {
    return false
  }

  return sameCoordinateValue(routeQuote.origin_latitude, technicianLatitude)
    && sameCoordinateValue(routeQuote.origin_longitude, technicianLongitude)
    && sameCoordinateValue(routeQuote.destination_latitude, destination.latitude)
    && sameCoordinateValue(routeQuote.destination_longitude, destination.longitude)
}

const routeQuoteMessage = (message: string | null | undefined): string => {
  if (message === 'Usta konumu eksik.') {
    return 'Usta konumu eksik; yol hakedişi manuel girilmeli.'
  }

  if (message === 'Müşteri konumu eksik.') {
    return 'Müşteri konumu eksik; yol hakedişi manuel girilmeli.'
  }

  return displayOrEmpty(message, 'Usta yol hakedişi hesaplanamadı')
}

type OperationStepStatus = 'Tamamlandı' | 'Bekliyor' | 'Kontrol gerekli' | 'Engelleyici hata'

const operationStepTone = (status: OperationStepStatus): string => {
  switch (status) {
    case 'Tamamlandı':
      return 'border-emerald-200 bg-emerald-50 text-emerald-900'
    case 'Engelleyici hata':
      return 'border-rose-200 bg-rose-50 text-rose-900'
    case 'Kontrol gerekli':
      return 'border-amber-200 bg-amber-50 text-amber-900'
    default:
      return 'border-slate-200 bg-white text-slate-700'
  }
}

const nextActionTone = (severity: string | null | undefined): string => {
  switch (severity) {
    case 'success':
      return 'border-emerald-200 bg-emerald-50 text-emerald-950'
    case 'danger':
      return 'border-rose-200 bg-rose-50 text-rose-950'
    case 'warning':
      return 'border-amber-200 bg-amber-50 text-amber-950'
    case 'neutral':
      return 'border-slate-200 bg-slate-50 text-slate-900'
    default:
      return 'border-blue-200 bg-blue-50 text-blue-950'
  }
}

const whatsappHrefForPhone = (phone: string | null | undefined): string => {
  let digits = String(phone ?? '').replace(/\D/g, '')

  if (digits.startsWith('00')) {
    digits = digits.slice(2)
  }

  if (digits.startsWith('0')) {
    digits = `90${digits.slice(1)}`
  }

  if (!digits.startsWith('90') && digits.length === 10) {
    digits = `90${digits}`
  }

  return digits ? `https://wa.me/${digits}` : ''
}

const MiniMetric = ({
  label,
  value,
  hint,
}: {
  label: string
  value: ReactNode
  hint?: ReactNode
}) => (
  <div className="min-w-0 rounded-2xl border border-slate-200 bg-white/80 p-3.5 lg:p-4">
    <p className="text-[13px] font-medium text-slate-500">{label}</p>
    <div className="mt-1 text-[15px] font-semibold text-slate-950 break-words">{value}</div>
    {hint ? <div className="mt-1 text-sm leading-5 text-slate-500 break-words">{hint}</div> : null}
  </div>
)

type DetailPanelTone = 'slate' | 'product' | 'customer' | 'door' | 'payment' | 'address' | 'schedule' | 'technician' | 'route' | 'earning' | 'serial' | 'history' | 'warning'
type NextActionSectionTarget = 'operation' | 'assignment' | 'fieldCompletion' | 'finalCheck'
type OpsDetailSectionKey = 'product' | 'customer' | 'operation' | 'assignment' | 'finalCheck' | 'invoiceSerials' | 'fieldCompletion' | 'history'

type OpsDetailSectionContext = {
  isCompleted: boolean
  isFinalCheckStage: boolean
  hasAppointmentProposal: boolean
  hasReviewBlocker: boolean
  hasSupportRequest: boolean
  hasPartRequest: boolean
  hasAssignedTechnician: boolean
  isServiceVisitRequest: boolean
  workflowStatus?: string | null
  kanbanColumn?: string | null
}

const isAssignedTechnicianStage = (context: OpsDetailSectionContext): boolean => (
  context.hasAssignedTechnician
  && !context.isCompleted
  && !context.isFinalCheckStage
  && context.kanbanColumn === 'assigned'
  && !context.hasAppointmentProposal
  && !context.hasReviewBlocker
  && !context.hasSupportRequest
  && !context.hasPartRequest
)

const getOpsActiveSection = (context: OpsDetailSectionContext): OpsDetailSectionKey | null => {
  if (context.isCompleted) {
    return 'finalCheck'
  }

  if (context.isFinalCheckStage || context.workflowStatus === 'Son Kontrol' || context.kanbanColumn === 'final_check') {
    return 'fieldCompletion'
  }

  if (context.hasReviewBlocker || context.hasAppointmentProposal || context.hasSupportRequest || context.hasPartRequest) {
    return 'assignment'
  }

  if (!context.hasAssignedTechnician && context.isServiceVisitRequest) {
    return 'assignment'
  }

  if (!context.hasAssignedTechnician) {
    return 'operation'
  }

  return isAssignedTechnicianStage(context) ? null : 'assignment'
}

const getOpsDefaultOpenSections = (context: OpsDetailSectionContext): Set<OpsDetailSectionKey> => {
  const activeSection = getOpsActiveSection(context)

  if (!activeSection) {
    return new Set()
  }

  if (activeSection === 'fieldCompletion') {
    return new Set(['fieldCompletion'])
  }

  if (activeSection === 'finalCheck') {
    return new Set(['finalCheck'])
  }

  return new Set([activeSection])
}

const opsSectionClass = (section: OpsDetailSectionKey, activeSection: OpsDetailSectionKey | null): string => {
  if (section === activeSection) {
    return 'order-30'
  }

  if (!activeSection) {
    const passiveOrder: Record<OpsDetailSectionKey, string> = {
      product: 'order-30',
      customer: 'order-35',
      assignment: 'order-40',
      fieldCompletion: 'order-60',
      operation: 'order-70',
      finalCheck: 'order-75',
      invoiceSerials: 'order-80',
      history: 'order-[90]',
    }

    return passiveOrder[section]
  }

  const order: Record<OpsDetailSectionKey, string> = {
    operation: 'order-40',
    assignment: 'order-45',
    fieldCompletion: 'order-50',
    finalCheck: 'order-55',
    product: 'order-60',
    customer: 'order-65',
    invoiceSerials: 'order-70',
    history: 'order-[90]',
  }

  return order[section]
}

const detailPanelToneClass = (tone: DetailPanelTone = 'slate'): string => {
  switch (tone) {
    case 'product':
      return 'border-blue-100 bg-blue-50/70'
    case 'customer':
      return 'border-slate-200 bg-white'
    case 'door':
      return 'border-amber-100 bg-amber-50/70'
    case 'payment':
      return 'border-emerald-100 bg-emerald-50/70'
    case 'address':
      return 'border-cyan-100 bg-cyan-50/70'
    case 'schedule':
      return 'border-indigo-100 bg-indigo-50/70'
    case 'technician':
      return 'border-sky-100 bg-sky-50/70'
    case 'route':
      return 'border-blue-100 bg-blue-50/70'
    case 'earning':
      return 'border-teal-100 bg-teal-50/70'
    case 'serial':
      return 'border-violet-100 bg-violet-50/70'
    case 'history':
      return 'border-slate-200 bg-slate-50'
    case 'warning':
      return 'border-rose-100 bg-rose-50/80'
    default:
      return 'border-slate-200 bg-slate-50'
  }
}

const DetailPanel = ({
  title,
  summary,
  children,
  open,
  onOpenChange,
  tone = 'slate',
  panelRef,
  sectionTarget,
  highlighted = false,
  className = '',
}: {
  title: string
  summary?: ReactNode
  children: ReactNode
  open?: boolean
  onOpenChange?: (open: boolean) => void
  tone?: DetailPanelTone
  panelRef?: Ref<HTMLDetailsElement>
  sectionTarget?: NextActionSectionTarget
  highlighted?: boolean
  className?: string
}) => (
  <details
    ref={panelRef}
    data-next-action-target={sectionTarget}
    className={[
      'group scroll-mt-6 rounded-2xl border p-4 shadow-sm transition-colors',
      detailPanelToneClass(tone),
      highlighted ? 'ring-2 ring-blue-400 ring-offset-2 ring-offset-white' : '',
      className,
    ].join(' ')}
    open={open}
    onToggle={(event) => onOpenChange?.(event.currentTarget.open)}
  >
    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-left">
      <span className="min-w-0">
        <span className="block text-sm font-semibold uppercase tracking-[0.08em] text-slate-700 sm:text-base">{title}</span>
        {summary ? <span className="mt-1 block text-sm leading-5 text-slate-600">{summary}</span> : null}
      </span>
      <ChevronDown className="h-4 w-4 shrink-0 text-slate-500 transition-transform group-open:rotate-180" />
    </summary>
    <div className="mt-4 grid gap-4 motion-safe:transition-all">
      {children}
    </div>
  </details>
)

type OperationControlTone = 'positive' | 'problem' | 'neutral'
type OperationControlValue = 'yes' | 'no' | 'unreviewed' | 'compatible' | 'incompatible'

const operationControlTone = (value: OperationControlValue, tone?: OperationControlTone) => {
  switch (tone ?? value) {
    case 'positive':
    case 'yes':
    case 'compatible':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'problem':
    case 'no':
    case 'incompatible':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    default:
      return 'border-slate-200 bg-slate-50 text-slate-600'
  }
}

const OperationControlRow = ({
  label,
  value,
  options,
  disabled,
  onChange,
}: {
  label: string
  value: OperationControlValue
  options: Array<{ value: OperationControlValue, label: string, tone?: OperationControlTone }>
  disabled: boolean
  onChange: (value: OperationControlValue) => void
}) => (
  <div className="rounded-2xl border border-slate-200 bg-[#F8FAFD] p-3">
    <p className="text-[11px] font-medium text-slate-500">{label}</p>
    <div className="mt-2 flex flex-wrap gap-2">
      {options.map((option) => {
        const active = option.value === value

        return (
          <button
            key={option.value}
            type="button"
            onClick={() => onChange(option.value)}
            disabled={disabled}
            className={[
              'inline-flex h-8 items-center rounded-full border px-3 text-xs font-semibold transition disabled:cursor-wait disabled:opacity-60',
              active ? operationControlTone(option.value, option.tone) : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50',
            ].join(' ')}
          >
            {option.label}
          </button>
        )
      })}
    </div>
  </div>
)

const serialToneClass = (serial: ServiceRequestInvoiceSerial): string => {
  switch (serial.color_status) {
    case 'green':
      return 'border-emerald-200 bg-emerald-50 text-emerald-900'
    case 'red':
      return 'border-rose-200 bg-rose-50 text-rose-900'
    default:
      return 'border-amber-200 bg-amber-50 text-amber-950'
  }
}

const serialToneLabel = (serial: ServiceRequestInvoiceSerial): string => {
  if (serial.is_returned) {
    return 'İade gelen seri'
  }

  if (serial.customer_selected) {
    return 'Müşteri seçti / talep edilen'
  }

  if (serial.operation_added) {
    return 'Montaja eklendi'
  }

  if (serial.hidden_reason === 'dealer_or_partner' || serial.hidden_reason === 'responsibility_code_blocked') {
    return serial.hidden_reason_label && serial.hidden_reason_label.includes(':')
      ? serial.hidden_reason_label
      : `Müşteriye gösterilmedi - sorumluluk kodu: ${serialResponsibilityCodeLabel(serial)}`
  }

  return serial.hidden_reason_label || 'Montaja eklenmedi'
}

const serialResponsibilityCodeLabel = (serial: ServiceRequestInvoiceSerial): string => {
  const code = String(serial.responsibility_code ?? serial.normalized_responsibility_code ?? '').trim()

  return code !== '' ? code : 'Boş'
}

const currentLatestSaleLabel = (serial: ServiceRequestInvoiceSerial): string => {
  if (serial.is_current_latest_sale === true) {
    return 'Bu faturadaki güncel satış'
  }

  if (serial.is_current_latest_sale === false) {
    return 'Bu fatura son satış değil'
  }

  return 'Son satış kontrolü doğrulanamadı'
}

const needsLatestSaleWarning = (serial: ServiceRequestInvoiceSerial): boolean => (
  Boolean(serial.customer_selected || serial.is_primary) && serial.is_current_latest_sale === false
)

const InvoiceSerialRow = ({
  serial,
  onAdd,
  onRemove,
  actionInFlight,
}: {
  serial: ServiceRequestInvoiceSerial
  onAdd?: (serialId: number | string) => void | Promise<void>
  onRemove?: (serialId: number | string) => void | Promise<void>
  actionInFlight?: string | null
}) => (
  <div className={['rounded-2xl border p-3 text-sm', serialToneClass(serial)].join(' ')}>
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div>
        <p className="font-semibold">{displayOrEmpty(serial.serial_number, '-')}</p>
        <p className="mt-1 text-xs opacity-80">{displayOrEmpty(serial.product_name, 'Ürün bilgisi yok')}</p>
      </div>
      <div className="flex flex-wrap items-center justify-end gap-2">
        <Badge variant={serial.is_returned ? 'destructive' : (serial.customer_selected || serial.operation_added) ? 'secondary' : 'warning'}>
          {serialToneLabel(serial)}
        </Badge>
        {serial.is_returned ? (
          <Button type="button" variant="outline" size="sm" disabled className="border-rose-200 bg-rose-50 text-rose-700">
            İade - eklenemez
          </Button>
        ) : serial.is_primary ? (
          <Button type="button" variant="outline" size="sm" disabled>
            Ana seri - çıkarılamaz
          </Button>
        ) : serial.customer_selected || serial.operation_added ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!serial.id || actionInFlight === `remove:${serial.id}`}
            onClick={() => serial.id ? void onRemove?.(serial.id) : undefined}
          >
            {actionInFlight === `remove:${serial.id}` ? 'Çıkarılıyor...' : 'Çıkar'}
          </Button>
        ) : (
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!serial.id || actionInFlight === `add:${serial.id}`}
            onClick={() => serial.id ? void onAdd?.(serial.id) : undefined}
            className="border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100"
          >
            {actionInFlight === `add:${serial.id}` ? 'Ekleniyor...' : 'Montaja ekle'}
          </Button>
        )}
      </div>
    </div>
    <div className="mt-3 grid gap-2 sm:grid-cols-2">
      <MiniMetric label="Model" value={displayOrEmpty(serial.product_model, '-')} />
      <MiniMetric label="Durum etiketi" value={serialToneLabel(serial)} />
      <MiniMetric label="Montaj durumu" value={displayOrEmpty(serial.mount_status_label, serial.mount_payment_status === 'paid' ? 'Montaj Dahil' : '-')} />
      {serial.hidden_reason ? (
        <MiniMetric
          label="Müşteri görünürlüğü"
          value={serial.hidden_reason === 'dealer_or_partner'
            ? 'Müşteriye gösterilmedi - sorumluluk kodu'
            : serial.hidden_reason_label || 'Müşteriye gösterilmedi'}
        />
      ) : null}
      <MiniMetric label="MRN bağı" value={displayOrEmpty(serial.linked_mrn, '-')} hint={displayOrEmpty(serial.customer_phone, 'Telefon yok')} />
      <MiniMetric
        label="Son satış durumu"
        value={currentLatestSaleLabel(serial)}
        hint={serial.latest_sale_conflict
          ? 'Son satış kontrolü çelişkili'
          : needsLatestSaleWarning(serial)
            ? 'Bu seri için son satış kontrolü tekrar doğrulanmalı.'
            : undefined}
      />
      {serial.warning_labels?.length ? (
        <div className="sm:col-span-2 flex flex-wrap gap-2">
          {serial.warning_labels.map((warning) => (
            <span key={warning} className="rounded-full border border-amber-300 bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-950">
              {warning}
            </span>
          ))}
        </div>
      ) : null}
      {serial.is_returned ? (
        <>
          <MiniMetric label="İade Notu" value={displayOrEmpty(serial.return_note, 'İade gelen seri')} />
          <MiniMetric label="İade Tarihi" value={displayOrEmpty(serial.return_date, '-')} />
          <MiniMetric label="İade Evrak No" value={displayOrEmpty(serial.return_document_no, '-')} />
          <MiniMetric label="Müşteriye gösterilmedi" value="Evet" />
        </>
      ) : null}
      {serial.operation_note ? (
        <div className="sm:col-span-2 rounded-xl border border-slate-200 bg-white/70 p-3 text-xs font-semibold">
          {serial.operation_note}
        </div>
      ) : null}
    </div>
  </div>
)

const normalizeInvoiceSerialSearch = (value: unknown): string => String(value ?? '').toLocaleLowerCase('tr-TR')

const invoiceSerialMatchesSearch = (serial: ServiceRequestInvoiceSerial, normalizedSearch: string): boolean => {
  if (!normalizedSearch) {
    return true
  }

  return [
    serial.serial_number,
    serial.normalized_serial,
    serial.product_name,
    serial.product_model,
    serial.brand,
    serial.model,
    serial.color,
    serial.invoice_display_no,
    serial.invoice_number,
    serial.invoice_series,
    serial.current_latest_sale_invoice_series,
    serial.current_latest_sale_invoice_number,
    serial.mount_status_label,
    serial.hidden_reason_label,
    serial.operation_warning,
  ].some((value) => normalizeInvoiceSerialSearch(value).includes(normalizedSearch))
}

const filterInvoiceSerials = (
  items: ServiceRequestInvoiceSerial[] | undefined,
  normalizedSearch: string,
): ServiceRequestInvoiceSerial[] => {
  if (!items || items.length === 0) {
    return []
  }

  return normalizedSearch
    ? items.filter((serial) => invoiceSerialMatchesSearch(serial, normalizedSearch))
    : items
}

const invoiceSerialIdentity = (serial: ServiceRequestInvoiceSerial, index: number): string => {
  if (serial.id !== null && serial.id !== undefined) {
    return `id:${String(serial.id)}`
  }

  const normalizedSerial = String(serial.normalized_serial ?? serial.serial_number ?? '').trim()
  const invoiceNo = String(serial.invoice_display_no ?? `${serial.invoice_series ?? ''}-${serial.invoice_number ?? ''}`).trim()

  return normalizedSerial !== '' || invoiceNo !== '' ? `${normalizedSerial}|${invoiceNo}` : `row:${index}`
}

const uniqueInvoiceSerialRows = (rows: ServiceRequestInvoiceSerial[]): ServiceRequestInvoiceSerial[] => {
  const seen = new Set<string>()

  return rows.filter((serial, index) => {
    const key = invoiceSerialIdentity(serial, index)

    if (seen.has(key)) {
      return false
    }

    seen.add(key)

    return true
  })
}

const invoiceSerialIsSelected = (serial: ServiceRequestInvoiceSerial): boolean => Boolean(serial.customer_selected || serial.operation_added)

const invoiceSerialIsReturned = (serial: ServiceRequestInvoiceSerial): boolean => Boolean(serial.is_returned)

const invoiceSerialIsOther = (serial: ServiceRequestInvoiceSerial): boolean => (
  !invoiceSerialIsSelected(serial)
  && !invoiceSerialIsReturned(serial)
  && serial.customer_visible === true
)

const invoiceSerialIsHidden = (serial: ServiceRequestInvoiceSerial): boolean => (
  !invoiceSerialIsSelected(serial)
  && !invoiceSerialIsReturned(serial)
  && serial.customer_visible !== true
)

const InvoiceSerialSection = ({
  title,
  items,
  totalCount,
  searchActive = false,
  onAdd,
  onRemove,
  actionInFlight,
}: {
  title: string
  items?: ServiceRequestInvoiceSerial[]
  totalCount?: number
  searchActive?: boolean
  onAdd?: (serialId: number | string) => void | Promise<void>
  onRemove?: (serialId: number | string) => void | Promise<void>
  actionInFlight?: string | null
}) => {
  if (!items || items.length === 0) {
    return null
  }

  const effectiveTotal = totalCount ?? items.length
  const hasMore = !searchActive && effectiveTotal > items.length

  return (
    <section className="grid gap-3">
      <div className="flex flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p className="text-sm font-semibold text-slate-900">{title}</p>
          <p className="mt-1 text-xs font-medium text-slate-500">
            Toplam {effectiveTotal} kayıt. {hasMore ? `İlk ${items.length} kayıt gösteriliyor.` : `${items.length} kayıt gösteriliyor.`}
          </p>
        </div>
      </div>
      {hasMore ? (
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900">
          Liste performans için sınırlandı. Gerekirse seri sorgusunu yenileyip arama ile daraltın.
        </div>
      ) : null}
      <div className="grid gap-3">
        {items.map((serial, index) => (
          <InvoiceSerialRow
            key={`${serial.serial_number ?? 'serial'}-${index}`}
            serial={serial}
            onAdd={onAdd}
            onRemove={onRemove}
            actionInFlight={actionInFlight}
          />
        ))}
      </div>
    </section>
  )
}

const doorPhotoLabel = (fieldCode: string | null | undefined): string => {
  switch (fieldCode) {
    case 'door_front_photo':
      return 'Kapı Ön Yüzü'
    case 'door_side_photo':
      return 'Kapı Yan Yüzü'
    case 'door_back_photo':
      return 'Kapı Arka Yüzü'
    case 'ops_door_front_photo':
      return 'OPS Kapı Ön Yüzü'
    case 'ops_door_side_photo':
      return 'OPS Kapı Yan Yüzü'
    case 'ops_door_back_photo':
      return 'OPS Kapı Arka Yüzü'
    case 'ops_door_photo':
      return 'OPS Ek Kapı Görseli'
    default:
      return 'Kapı görseli'
  }
}

const slaTone = (status: ServiceRequest['slaStatus']) => {
  switch (status) {
    case 'geciken':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    case 'yaklaşan':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

const PRIORITY_OPTIONS: Array<{ value: ServicePriority, label: string }> = [
  { value: 'Orta' as ServicePriority, label: 'Normal' },
  { value: 'Yüksek' as ServicePriority, label: 'Yüksek' },
  { value: 'Kritik' as ServicePriority, label: 'Acil' },
]

const priorityDisplayLabel = (priority: ServicePriority | string | null | undefined): string => {
  switch (priority) {
    case 'Orta':
      return 'Normal'
    case 'Kritik':
      return 'Acil'
    default:
      return displayOrEmpty(priority, 'Belirlenmedi')
  }
}

const statusDisplayLabel = (request: ServiceRequest): string => {
  if (hasText(request.workflowStatus)) {
    return cleanDisplayText(request.workflowStatus)
  }

  return request.status === 'Yeni' ? 'Yeni Talep' : cleanDisplayText(request.status)
}

const assignmentOfferStatusLabel = (status: string | null | undefined): string => {
  switch (String(status ?? '').trim()) {
    case 'sent':
      return 'Gönderildi'
    case 'revised':
      return 'Revize edildi'
    case 'accepted':
      return 'Kabul edildi'
    case 'cancelled':
      return 'İptal edildi'
    case 'superseded':
      return 'Yenilendi'
    case 'draft':
      return 'Taslak'
    case 'pending':
      return 'Bekliyor'
    default:
      return displayOrEmpty(status, 'Kontrol edilecek')
  }
}

const settlementStatusLabel = (status: string | null | undefined): string => {
  switch (String(status ?? '').trim()) {
    case 'calculated':
      return 'Hesaplandı'
    case 'admin_review':
      return 'Admin incelemesi'
    case 'finalized':
      return 'Kesinleşti'
    case 'sent':
      return 'Gönderildi'
    case 'partial_paid':
      return 'Kısmi ödendi'
    case 'paid':
      return 'Ödendi'
    case 'excluded':
      return 'Hakedişe dahil değil'
    case 'draft':
      return 'Taslak'
    default:
      return displayOrEmpty(status, 'Settlement yok')
  }
}

const slaStatusLabel = (status: ServiceRequest['slaStatus']): string => {
  switch (status) {
    case 'yaklaşan':
      return 'Yaklaşan'
    case 'geciken':
      return 'Gecikti'
    case 'normal':
    case null:
    case undefined:
      return 'Normal'
    default:
      return String(status)
  }
}

const slaStatusDescription = (status: ServiceRequest['slaStatus']): string => {
  switch (status) {
    case 'yaklaşan':
      return 'Yaklaşan: SLA hedefine kısa süre kaldı'
    case 'geciken':
      return 'Gecikti: SLA hedef tarihi aşıldı'
    default:
      return 'Normal: SLA hedefi içinde'
  }
}

type TechnicianApprovalState = {
  tone: string
  title: string
  detail?: string | null
}

const technicianApprovalState = (request: ServiceRequest, events: ServiceRequestEvent[]): TechnicianApprovalState => {
  const hasTechnician = Boolean(request.technicianId || (request.technician && request.technician !== 'Atanmadı'))

  if (!hasTechnician) {
    return {
      tone: 'border-slate-200 bg-slate-50 text-slate-900',
      title: 'Usta atanmadı',
      detail: null,
    }
  }

  const approvalHaystack = JSON.stringify([
    request.technicianApprovalStatus,
    request.technicianConfirmationStatus,
    request.operationalState?.action_label,
    request.operationalState?.display_action_label,
  ]).toLocaleLowerCase('tr-TR')
  const technicianApproved = Boolean(request.technicianApprovedAt)
    || approvalHaystack.includes('onay')
    || approvalHaystack.includes('kabul')
    || approvalHaystack.includes('accept')
  const technicianRejected = approvalHaystack.includes('redd')
    || approvalHaystack.includes('reject')
    || approvalHaystack.includes('declin')

  if (technicianRejected) {
    return {
      tone: 'border-rose-200 bg-rose-50 text-rose-950',
      title: 'Usta işi reddetti',
      detail: null,
    }
  }

  if (technicianApproved) {
    return {
      tone: 'border-green-200 bg-green-50 text-green-950',
      title: 'Usta işi kabul etti',
      detail: null,
    }
  }

  const matchingEvent = [...events].reverse().find((event) => {
    const haystack = JSON.stringify([
      event.event_type,
      event.title,
      event.note,
      event.metadata,
    ]).toLocaleLowerCase('tr-TR')

    return ['kabul', 'accepted', 'onay', 'redd', 'reject', 'declin', 'revize', 'schedule change', 'değiştir'].some((keyword) => haystack.includes(keyword))
  })

  if (!matchingEvent) {
    return {
      tone: 'border-amber-200 bg-amber-50 text-amber-950',
      title: 'Usta onayı bekleniyor',
      detail: null,
    }
  }

  const haystack = JSON.stringify([
    matchingEvent.event_type,
    matchingEvent.title,
    matchingEvent.note,
    matchingEvent.metadata,
  ]).toLocaleLowerCase('tr-TR')
  const metadata = matchingEvent.metadata ?? {}
  const rejectionReason = formatDisplayValue(String(
    metadata.rejection_reason
    ?? metadata.reason
    ?? metadata.technician_rejection_reason
    ?? matchingEvent.note
    ?? '',
  ))
  const revisionDetail = formatDisplayValue(String(
    metadata.requested_schedule_at
    ?? metadata.technician_schedule_change_requested_at
    ?? metadata.reschedule_note
    ?? metadata.technician_reschedule_note
    ?? matchingEvent.note
    ?? '',
  ))

  if (haystack.includes('revize') || haystack.includes('schedule change') || haystack.includes('değiş')) {
    return {
      tone: 'border-indigo-200 bg-indigo-50 text-indigo-950',
      title: 'Randevu revize talebi var',
      detail: revisionDetail !== '-' ? revisionDetail : null,
    }
  }

  if (haystack.includes('redd') || haystack.includes('reject') || haystack.includes('declin')) {
    return {
      tone: 'border-rose-200 bg-rose-50 text-rose-950',
      title: 'Usta işi reddetti',
      detail: rejectionReason !== '-' ? `Ret nedeni: ${rejectionReason}` : null,
    }
  }

  if (haystack.includes('kabul') || haystack.includes('accepted') || haystack.includes('onay')) {
    return {
      tone: 'border-green-200 bg-green-50 text-green-950',
      title: 'Usta işi kabul etti',
      detail: null,
    }
  }

  return {
    tone: 'border-amber-200 bg-amber-50 text-amber-950',
    title: 'Usta onayı bekleniyor',
    detail: null,
  }
}

const parseEventTimestamp = (event: ServiceRequestEvent): number => {
  const raw = event.created_at ?? event.updated_at ?? ''
  const parsed = raw ? new Date(raw).getTime() : Number.NaN

  return Number.isFinite(parsed) ? parsed : Number.NEGATIVE_INFINITY
}

export function ServiceRequestDetails({
  request,
  opsDetailVisibility = DEFAULT_OPS_DETAIL_VISIBILITY,
  displayMrn,
  events,
  loading,
  error,
  mikroMountCheck,
  mikroMountLoading = false,
  mikroMountError = null,
  warranty = null,
  warrantyLoading = false,
  warrantyError = null,
  onAssign,
  onSchedule,
  onComplete,
  onReopen,
  onPriorityChange,
  onWorkflowAction,
  onOperationControlChange,
  onAdminOverrideSubmit,
  onAdminOverrideReview,
  onInvoiceSerialRecheck,
  onInvoiceSerialAdd,
  onInvoiceSerialRemove,
  onInvoiceSerialAddAll,
  priorityUpdateInFlight = false,
  priorityUpdateError = null,
  workflowActionInFlight = null,
  operationControlUpdateInFlight = false,
  operationControlUpdateError = null,
  adminOverrideInFlight = false,
  adminOverrideError = null,
  invoiceSerialRecheckInFlight = false,
  invoiceSerialRecheckError = null,
  invoiceSerialActionInFlight = null,
  invoiceSerialActionError = null,
  technicianSuggestions = [],
  scheduleSupport = null,
  selectedTechnicianId = null,
  routeQuoteLoading = false,
  routeQuoteError = null,
  routeQuoteManualSaveLoading = false,
  routeQuoteManualSaveError = null,
  extraPaymentCreateLoading = false,
  extraPaymentCreateError = null,
  technicianEarningMessageLoading = false,
  technicianEarningMessageError = null,
  assignLoading = false,
  canSubmitAssign = false,
  assignError = null,
  mountExclusionAcknowledged = false,
  mountExclusionNote = '',
  onMountExclusionAcknowledgedChange,
  onMountExclusionNoteChange,
  onTechnicianSelect,
  onRouteQuoteCalculate,
  onRouteQuoteManualSave,
  onExtraMountPaymentCreate,
  onMountPaymentCancel,
  onMountPaymentSync,
  onMountPaymentSend,
  onTechnicianEarningMessageCreate,
  onAssignSelectedTechnician,
  onPartnerAppointmentProposalApprove,
  onPartnerAppointmentProposalReject,
  onPartnerCompletionApprove,
  onRevisitServiceVisitCreate,
  onPartRequestCreate,
  onPartRequestTransition,
  onPartRequestServiceVisitCreate,
  onAssignmentOfferUpdate,
  onFieldDocumentReview,
  onOpsExtraDocumentUpload,
  onCustomerApprovalResend,
  fieldDocumentReviewInFlight = null,
  fieldDocumentReviewError = null,
  customerApprovalResendLoading = false,
  customerApprovalResendError = null,
}: ServiceRequestDetailsProps) {
  const basePaymentInfo = getServicePaymentInfo(
    request.serviceType,
    null,
    null,
    null,
    request.technicianPaymentAmount,
  )
  const isActionDisabled = request.status === 'Tamamlandı' || request.status === 'İptal'
  const disabledTitle = 'Tamamlanan veya iptal edilen taleplerde işlem yapılamaz'
  const isReopenVisible = isActionDisabled
  const hasAssignedTechnician = Boolean(request.technicianId || (request.technician && request.technician !== 'Atanmadı'))
  const productInfo = request.productInfo ?? null
  const saleAndPayment = request.saleAndPayment ?? null
  const documentInfo = request.documentInfo ?? null
  const invoiceSerials = request.invoiceSerials ?? null
  const serviceVisitHistory = request.serviceVisitHistory ?? null
  const partnerPortalActions = request.partnerPortalActions ?? []
  const adminOverrides = request.adminOverrides ?? []
  const pendingAdminOverrides = adminOverrides.filter((override) => override.status === 'pending')
  const openAppointmentProposals = partnerPortalActions.filter((action) => ['appointment_proposed', 'appointment_change_requested'].includes(action.action) && action.status === 'ops_review')
  const jobRejections = partnerPortalActions.filter((action) => action.action === 'job_rejected' && action.status === 'ops_review')
  const customerApprovalRejections = partnerPortalActions.filter((action) => action.action === 'customer_approval_rejected' && action.status === 'ops_review')
  const supportRequests = partnerPortalActions.filter((action) => action.action === 'support_requested' && action.status === 'ops_review')
  const revisitRequests = partnerPortalActions.filter((action) => action.action === 'revisit_requested' && action.status === 'ops_review')
  const partRequests = request.partRequests ?? []
  const activePartRequests = partRequests.filter((partRequest) => ['requested', 'ops_review', 'approved', 'ordered', 'sent', 'received', 'service_visit_required'].includes(partRequest.status))
  const visibleSections = request.visibleSections ?? null
  const warrantySectionVisible = visibleSections?.warranty === true
  const warrantySectionMode = visibleSections?.warranty_mode ?? null
  const servicePartChargeSectionVisible = visibleSections?.service_part_charge === true
  const canCreatePartRequest = Boolean(onPartRequestCreate)
    && (visibleSections?.is_service_visit === true || servicePartChargeSectionVisible || partRequests.length > 0)
  const warrantyStatusText = String(warranty?.status ?? '')
  const warrantyIsActive = warrantyStatusText.includes('Aktif')
  const warrantyIsExpired = warrantyStatusText.includes('Bitti')
  const activeChargeablePartRequests = activePartRequests.filter((partRequest) => partRequest.charge_decision === 'chargeable')
  const canShowServicePartPaymentAction = servicePartChargeSectionVisible && !warrantyLoading && (!warrantyIsActive || activeChargeablePartRequests.length > 0)
  const shouldRenderWarrantySection = warrantySectionVisible && (
    warrantySectionMode === 'full'
      ? (warrantyLoading || Boolean(warranty) || Boolean(warrantyError))
      : (Boolean(warrantyError) || warrantyIsActive || warrantyIsExpired)
  )
  const servicePartPaymentSummaryHint = servicePartChargeSectionVisible
    ? (canShowServicePartPaymentAction
        ? 'Garanti d\u0131\u015f\u0131 servis veya \u00fccretli par\u00e7a tahsilat\u0131 ayr\u0131 \u00f6deme linkiyle izlenir.'
        : 'Garanti kapsam\u0131 aktif. M\u00fc\u015fteri servis \u00fccreti istenmez.')
    : 'Servis/par\u00e7a tahsilat\u0131 yaln\u0131zca \u00fccretli par\u00e7a veya servis karar\u0131 varsa a\u00e7\u0131l\u0131r.'
  const serviceVisitHistoryRecords = request.serviceVisitHistory?.history_records ?? []
  const completionSubmissions = partnerPortalActions.filter((action) => action.action === 'completion_submitted' && action.status === 'ops_review')
  const latestCustomerApprovalRequest = [...partnerPortalActions]
    .filter((action) => action.action === 'customer_otp_requested')
    .sort((a, b) => Date.parse(b.created_at ?? '') - Date.parse(a.created_at ?? ''))[0] ?? null
  const latestCustomerApprovalPayload = latestCustomerApprovalRequest?.payload ?? {}
  const latestCustomerApprovalMessagePayload = (
    typeof latestCustomerApprovalPayload.message_payload === 'object'
    && latestCustomerApprovalPayload.message_payload !== null
  )
    ? (latestCustomerApprovalPayload.message_payload as Record<string, unknown>)
    : null
  const latestCustomerApprovalDispatchStatus = String(
    latestCustomerApprovalMessagePayload?.dispatch_status
    ?? latestCustomerApprovalPayload.dispatch_status
    ?? '',
  )
  const latestCustomerApprovalUrl = stringValue(latestCustomerApprovalPayload, 'approval_url')
    ?? stringValue(latestCustomerApprovalPayload, 'confirmation_url')
    ?? stringValue(latestCustomerApprovalMessagePayload, 'approval_url')
    ?? stringValue(latestCustomerApprovalMessagePayload, 'confirmation_url')
  const latestCustomerApprovalWhatsappUrl = stringValue(latestCustomerApprovalPayload, 'whatsapp_url')
    ?? stringValue(latestCustomerApprovalMessagePayload, 'whatsapp_url')
  const latestCustomerApprovalMessageText = stringValue(latestCustomerApprovalPayload, 'message_text')
    ?? stringValue(latestCustomerApprovalMessagePayload, 'message_text')
  const isFinalCheckStage = completionSubmissions.length > 0 || request.workflowStatus === 'Son Kontrol'
  const portalActionLabels: Record<string, string> = {
    appointment_proposed: 'Randevu önerildi',
    appointment_change_requested: 'Randevu değişikliği istendi',
    appointment_accepted_by_technician: 'Usta randevuyu onayladı',
    customer_otp_requested: 'Müşteri onayı istendi',
    customer_approval_confirmed: 'Müşteri montajı onayladı',
    photos_uploaded: 'Fotoğraf yüklendi',
    support_requested: 'Ek talep oluşturuldu',
    completion_submitted: 'Tamamlama gönderildi',
    job_rejected: 'Usta reddetti',
    note_added: 'Not eklendi',
  }
  const opsSectionContext: OpsDetailSectionContext = {
    isCompleted: request.status === 'Tamamlandı',
    isFinalCheckStage,
    hasAppointmentProposal: openAppointmentProposals.length > 0,
    hasReviewBlocker: jobRejections.length > 0 || customerApprovalRejections.length > 0,
    hasSupportRequest: supportRequests.length > 0,
    hasPartRequest: activePartRequests.length > 0,
    hasAssignedTechnician,
    isServiceVisitRequest: visibleSections?.is_service_visit === true || Boolean(request.serviceVisitHistory?.service_code || request.serviceVisitHistory?.reason),
    workflowStatus: request.workflowStatus,
    kanbanColumn: request.kanbanColumn,
  }
  const activeOpsSection = getOpsActiveSection(opsSectionContext)
  const defaultOpenOpsSections = getOpsDefaultOpenSections(opsSectionContext)
  const [invoiceSerialsOpenByRequest, setInvoiceSerialsOpenByRequest] = useState<Record<string, boolean>>({})
  const invoiceSerialsOpen = invoiceSerialsOpenByRequest[request.id] ?? defaultOpenOpsSections.has('invoiceSerials')
  const setInvoiceSerialsOpen = (open: boolean) => {
    setInvoiceSerialsOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [invoiceSerialSearchByRequest, setInvoiceSerialSearchByRequest] = useState<Record<string, string>>({})
  const invoiceSerialSearch = invoiceSerialSearchByRequest[request.id] ?? ''
  const setInvoiceSerialSearch = (value: string) => {
    setInvoiceSerialSearchByRequest((current) => ({ ...current, [request.id]: value }))
  }
  const [productInfoOpenByRequest, setProductInfoOpenByRequest] = useState<Record<string, boolean>>({})
  const productInfoOpen = productInfoOpenByRequest[request.id] ?? defaultOpenOpsSections.has('product')
  const setProductInfoOpen = (open: boolean) => {
    setProductInfoOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [customerInfoOpenByRequest, setCustomerInfoOpenByRequest] = useState<Record<string, boolean>>({})
  const customerInfoOpen = customerInfoOpenByRequest[request.id] ?? defaultOpenOpsSections.has('customer')
  const setCustomerInfoOpen = (open: boolean) => {
    setCustomerInfoOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [assignmentInfoOpenByRequest, setAssignmentInfoOpenByRequest] = useState<Record<string, boolean>>({})
  const assignmentInfoOpen = assignmentInfoOpenByRequest[request.id] ?? defaultOpenOpsSections.has('assignment')
  const setAssignmentInfoOpen = (open: boolean) => {
    setAssignmentInfoOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [otherTechniciansModalOpenByRequest, setOtherTechniciansModalOpenByRequest] = useState<Record<string, boolean>>({})
  const [finalCheckOpenByRequest, setFinalCheckOpenByRequest] = useState<Record<string, boolean>>({})
  const finalCheckOpen = finalCheckOpenByRequest[request.id] ?? defaultOpenOpsSections.has('finalCheck')
  const setFinalCheckOpen = (open: boolean) => {
    setFinalCheckOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [fieldCompletionOpenByRequest, setFieldCompletionOpenByRequest] = useState<Record<string, boolean>>({})
  const fieldCompletionOpen = fieldCompletionOpenByRequest[request.id] ?? defaultOpenOpsSections.has('fieldCompletion')
  const setFieldCompletionOpen = (open: boolean) => {
    setFieldCompletionOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [correctionFieldKey, setCorrectionFieldKey] = useState<string | null>(null)
  const [correctionValue, setCorrectionValue] = useState('')
  const [correctionReason, setCorrectionReason] = useState('')
  const correctionField = correctionFieldKey ? correctionFieldByKey.get(correctionFieldKey) ?? null : null
  const openCorrectionEditor = (fieldKey: string) => {
    setCorrectionFieldKey(fieldKey)
    setCorrectionValue(requestCorrectionValue(request, fieldKey))
    setCorrectionReason('')
  }
  const closeCorrectionEditor = () => {
    setCorrectionFieldKey(null)
    setCorrectionValue('')
    setCorrectionReason('')
  }
  const submitCorrection = async () => {
    if (!correctionField || !onAdminOverrideSubmit) {
      return
    }

    const inputType = correctionField.input
    const normalizedValue = inputType === 'number'
      ? parseNumericInput(correctionValue)
      : correctionValue.trim()

    await onAdminOverrideSubmit({
      field_key: correctionField.key,
      new_value: normalizedValue,
      reason: correctionReason.trim(),
      mode: correctionField.mode ?? 'apply',
    })
    closeCorrectionEditor()
  }
  const [customerApprovalModalOpen, setCustomerApprovalModalOpen] = useState(false)
  const [customerApprovalCopyMessage, setCustomerApprovalCopyMessage] = useState<string | null>(null)
  const [serialQueryOpen, setSerialQueryOpen] = useState(false)
  const [routeFeeEditorOpen, setRouteFeeEditorOpen] = useState(false)
  const [routeFeeEditorMode, setRouteFeeEditorMode] = useState<'route_fee' | 'payment_link'>('route_fee')
  const [routeFeeEditorMessage, setRouteFeeEditorMessage] = useState<string | null>(null)
  const [routeFeeNote, setRouteFeeNote] = useState('')
  const [routeFeeOneWayKmInput, setRouteFeeOneWayKmInput] = useState('')
  const [routeFeeRoundTripKmInput, setRouteFeeRoundTripKmInput] = useState('')
  const [routeFeeThresholdKmInput, setRouteFeeThresholdKmInput] = useState('')
  const [routeFeePerKmInput, setRouteFeePerKmInput] = useState('')
  const [routeFeeBillableKmInput, setRouteFeeBillableKmInput] = useState('')
  const [routeFeeAmountInput, setRouteFeeAmountInput] = useState('')
  const [routeFeeExtraPaymentInput, setRouteFeeExtraPaymentInput] = useState('')
  const [paymentCancelInFlight, setPaymentCancelInFlight] = useState<number | string | null>(null)
  const [paymentSyncInFlight, setPaymentSyncInFlight] = useState<number | string | null>(null)
  const [paymentSendInFlight, setPaymentSendInFlight] = useState<number | string | null>(null)
  const [paymentCancelError, setPaymentCancelError] = useState<string | null>(null)
  const [paymentLinkCopyMessage, setPaymentLinkCopyMessage] = useState<string | null>(null)
  const [paymentLinkManualCopyValue, setPaymentLinkManualCopyValue] = useState<string | null>(null)
  const [customerServiceChargeInput, setCustomerServiceChargeInput] = useState('')
  const [customerPartChargeInput, setCustomerPartChargeInput] = useState('')
  const [customerChargeNoteInput, setCustomerChargeNoteInput] = useState('')
  const [customerChargeMessageInput, setCustomerChargeMessageInput] = useState('')
  const [customerChargeModalOpen, setCustomerChargeModalOpen] = useState(false)
  const [customerChargeCopyMessage, setCustomerChargeCopyMessage] = useState<string | null>(null)
  const [routeFeeManualAmountTouched, setRouteFeeManualAmountTouched] = useState(false)
  const [routeFeeEditorInitialSnapshot, setRouteFeeEditorInitialSnapshot] = useState('')
  const [earningNoteInput, setEarningNoteInput] = useState('')
  const [earningMessageText, setEarningMessageText] = useState('')
  const [earningMessageUrl, setEarningMessageUrl] = useState('')
  const [earningTotalOverrideByRequest, setEarningTotalOverrideByRequest] = useState<Record<string, string>>({})
  const [earningTotalOverrideTouchedByRequest, setEarningTotalOverrideTouchedByRequest] = useState<Record<string, boolean>>({})
  const [appointmentReviewNote, setAppointmentReviewNote] = useState('')
  const [appointmentSelectedSlotByAction, setAppointmentSelectedSlotByAction] = useState<Record<string, number>>({})
  const [completionReviewNote, setCompletionReviewNote] = useState('')
  const [finalPayoutSelectionByRequest, setFinalPayoutSelectionByRequest] = useState<Record<string, string[]>>({})
  const [offerLaborInput, setOfferLaborInput] = useState('')
  const [offerRouteInput, setOfferRouteInput] = useState('')
  const [offerNoteInput, setOfferNoteInput] = useState('')
  const [partRequestNotes, setPartRequestNotes] = useState<Record<string, string>>({})
  const [partRequestPartnerMessages, setPartRequestPartnerMessages] = useState<Record<string, string>>({})
  const [partRequestProviders, setPartRequestProviders] = useState<Record<string, string>>({})
  const [partRequestTrackings, setPartRequestTrackings] = useState<Record<string, string>>({})
  const [partCreateModalOpen, setPartCreateModalOpen] = useState(false)
  const [partCreateName, setPartCreateName] = useState('')
  const [partCreateCode, setPartCreateCode] = useState('')
  const [partCreateQuantity, setPartCreateQuantity] = useState('1')
  const [partCreateMode, setPartCreateMode] = useState<'free' | 'chargeable'>('free')
  const [partCreateServiceAmount, setPartCreateServiceAmount] = useState('')
  const [partCreatePartAmount, setPartCreatePartAmount] = useState('')
  const [partCreateNote, setPartCreateNote] = useState('')
  const [partCreateMessage, setPartCreateMessage] = useState('')
  const [partCreateSubmitting, setPartCreateSubmitting] = useState(false)
  const [partCreateError, setPartCreateError] = useState<string | null>(null)
  const [partDecisionRequestId, setPartDecisionRequestId] = useState<number | string | null>(null)
  const [partDecisionMode, setPartDecisionMode] = useState<'free' | 'chargeable'>('free')
  const [partDecisionServiceAmount, setPartDecisionServiceAmount] = useState('')
  const [partDecisionPartAmount, setPartDecisionPartAmount] = useState('')
  const [partDecisionMessage, setPartDecisionMessage] = useState('')
  const [opsExtraFiles, setOpsExtraFiles] = useState<File[]>([])
  const [opsExtraDocumentType, setOpsExtraDocumentType] = useState<OpsExtraDocumentType>('ops_extra_photo')
  const [opsExtraNote, setOpsExtraNote] = useState('')
  const [opsExtraUploading, setOpsExtraUploading] = useState(false)
  const [opsExtraMessage, setOpsExtraMessage] = useState<string | null>(null)
  const [opsDoorPhotoFiles, setOpsDoorPhotoFiles] = useState<File[]>([])
  const [opsDoorPhotoType, setOpsDoorPhotoType] = useState<OpsDoorPhotoType>('ops_door_photo')
  const [opsDoorPhotoNote, setOpsDoorPhotoNote] = useState('')
  const [opsDoorPhotoUploading, setOpsDoorPhotoUploading] = useState(false)
  const [opsDoorPhotoMessage, setOpsDoorPhotoMessage] = useState<string | null>(null)
  const [historyRecordId, setHistoryRecordId] = useState<number | string | null>(null)
  const selectedPartDecisionRequest = partDecisionRequestId === null
    ? null
    : partRequests.find((partRequest) => String(partRequest.id) === String(partDecisionRequestId)) ?? null
  const selectedHistoryRecord = historyRecordId === null
    ? null
    : serviceVisitHistoryRecords.find((record) => String(record.id) === String(historyRecordId)) ?? null
  const [fieldDocumentOverallRejectNote, setFieldDocumentOverallRejectNote] = useState('')
  const [fieldDocumentOverallReviewLoading, setFieldDocumentOverallReviewLoading] = useState(false)
  const [fieldDocumentOverallReviewEditing, setFieldDocumentOverallReviewEditing] = useState(false)
  const [differentAddressInfoOpen, setDifferentAddressInfoOpen] = useState(false)
  const locationInfo = request.location ?? null
  const doorPhotos = request.doorPhotos ?? []
  const routeQuote = request.routeQuote ?? null
  const assignmentOffer = request.assignmentOffer ?? null
  const settlement = request.settlement ?? null
  const settlementReviewDecision = settlement?.review_decision ?? null
  const settlementNeedsAdminReview = Boolean(settlement && (settlement.status === 'admin_review' || settlement.overpay_requires_review))
  const settlementReviewResolved = Boolean(settlementReviewDecision?.decision)
  const technicianRevisionOffer = request.technicianRevisionOffer?.exists ? request.technicianRevisionOffer : null
  const technicianRevisionOfferPending = technicianRevisionOffer?.status === 'pending'
  const selectedTechnician = technicianSuggestions.find((technician) => technician.id === selectedTechnicianId) ?? null
  const selectedTechnicianIdString = selectedTechnicianId ? String(selectedTechnicianId) : null
  const requestTechnicianIdString = request.technicianId !== null && request.technicianId !== undefined
    ? String(request.technicianId)
    : null
  const topTechnicianSuggestions = technicianSuggestions.slice(0, 4)
  const remainingTechnicianSuggestions = technicianSuggestions.slice(4)
  const otherTechnicianCount = remainingTechnicianSuggestions.length
  const otherTechniciansModalOpen = otherTechniciansModalOpenByRequest[request.id] ?? false
  const selectedTechnicianMatchesRequest = selectedTechnicianIdString
    ? requestTechnicianIdString === selectedTechnicianIdString
    : Boolean(requestTechnicianIdString)
  const assignmentOfferTechnicianIdString = assignmentOffer?.technical_service_technician_id !== null && assignmentOffer?.technical_service_technician_id !== undefined
    ? String(assignmentOffer.technical_service_technician_id)
    : null
  const assignmentOfferMatchesSelectedTechnician = Boolean(
    assignmentOffer
    && (
      selectedTechnicianIdString
        ? !assignmentOfferTechnicianIdString || assignmentOfferTechnicianIdString === selectedTechnicianIdString
        : requestTechnicianIdString
          ? !assignmentOfferTechnicianIdString || assignmentOfferTechnicianIdString === requestTechnicianIdString
          : false
    ),
  )
  const activeAssignmentOffer = assignmentOfferMatchesSelectedTechnician ? assignmentOffer : null
  const assignmentOfferLaborAmount = activeAssignmentOffer && Number.isFinite(Number(activeAssignmentOffer.labor_amount))
    ? Number(activeAssignmentOffer.labor_amount)
    : null
  const assignmentOfferRouteAmount = activeAssignmentOffer && Number.isFinite(Number(activeAssignmentOffer.route_fee_amount))
    ? Number(activeAssignmentOffer.route_fee_amount)
    : null
  const assignmentOfferTotalAmount = activeAssignmentOffer && Number.isFinite(Number(activeAssignmentOffer.total_amount))
    ? Number(activeAssignmentOffer.total_amount)
    : assignmentOfferLaborAmount !== null || assignmentOfferRouteAmount !== null
      ? roundTwo((assignmentOfferLaborAmount ?? 0) + (assignmentOfferRouteAmount ?? 0))
      : null
  const assignmentOfferMessagePayload = activeAssignmentOffer?.message_payload && typeof activeAssignmentOffer.message_payload === 'object'
    ? activeAssignmentOffer.message_payload
    : activeAssignmentOffer?.metadata
      && typeof activeAssignmentOffer.metadata === 'object'
      && activeAssignmentOffer.metadata.message_payload
      && typeof activeAssignmentOffer.metadata.message_payload === 'object'
        ? activeAssignmentOffer.metadata.message_payload as Record<string, unknown>
        : null
  const assignmentOfferMessageText = activeAssignmentOffer?.message_text
    ?? stringValue(assignmentOfferMessagePayload, 'message_text')
  const assignmentOfferJobLink = activeAssignmentOffer?.job_link
    ?? stringValue(assignmentOfferMessagePayload, 'job_link')
  const routeQuoteTechnicianIdString = routeQuote?.technician_id !== null && routeQuote?.technician_id !== undefined
    ? String(routeQuote.technician_id)
    : null
  const routeQuoteMatchesSelectedTechnician = Boolean(routeQuote && selectedTechnicianIdString && routeQuoteTechnicianIdString === selectedTechnicianIdString)
  const routeQuoteStaleForSelectedTechnician = Boolean(routeQuote && selectedTechnicianIdString && routeQuoteTechnicianIdString && routeQuoteTechnicianIdString !== selectedTechnicianIdString)
  const hasAssignmentChange = Boolean(selectedTechnicianIdString && selectedTechnicianIdString !== requestTechnicianIdString)
  const hasMultiProductRequest = Boolean(invoiceSerials?.has_multi_product || (invoiceSerials?.selected_serials?.length ?? 0) > 1 || saleAndPayment?.mount_payment_status === 'skipped_multi_product')
  const customerOpenAddress = [
    [request.city, request.district].filter(Boolean).join(' / '),
    request.address,
  ].filter((value) => String(value ?? '').trim() !== '').join(' - ')
  const routeFeeConfigThresholdKm = typeof request.routeFeeConfig?.threshold_km === 'number' && Number.isFinite(request.routeFeeConfig.threshold_km)
    ? request.routeFeeConfig.threshold_km
    : 30
  const routeFeeConfigPerKm = typeof request.routeFeeConfig?.fee_per_km === 'number' && Number.isFinite(request.routeFeeConfig.fee_per_km)
    ? request.routeFeeConfig.fee_per_km
    : null
  const routeQuoteStatusCanDisplay = routeQuote?.status === 'calculated' || routeQuote?.status === 'manual_override'
  const routeQuoteCoordinatesMatchCurrent = routeQuoteMatchesCoordinates(routeQuote, selectedTechnician, locationInfo)
  const routeQuoteFeeMatchesCurrent = routeQuote?.fee_per_km_matches_current === true
    || (
      typeof routeQuote?.fee_per_km === 'number'
      && routeFeeConfigPerKm !== null
      && Math.abs(routeQuote.fee_per_km - routeFeeConfigPerKm) <= 0.001
    )
  const routeQuoteActiveForSelectedTechnician = Boolean(
    routeQuote
    && routeQuoteMatchesSelectedTechnician
    && routeQuoteStatusCanDisplay
    && routeQuoteCoordinatesMatchCurrent
    && routeQuoteFeeMatchesCurrent,
  )
  const activeRouteQuote = routeQuoteActiveForSelectedTechnician ? routeQuote : null
  const hasActiveRouteQuote = Boolean(activeRouteQuote)
  const storedRouteCostMatchesSelection = selectedTechnicianMatchesRequest || assignmentOfferMatchesSelectedTechnician
  const storedRouteRoundTripKm = storedRouteCostMatchesSelection && typeof request.travelRoundTripKm === 'number' && Number.isFinite(request.travelRoundTripKm)
    ? request.travelRoundTripKm
    : null
  const storedRouteBillableKm = storedRouteCostMatchesSelection && typeof request.travelBillableKm === 'number' && Number.isFinite(request.travelBillableKm)
    ? request.travelBillableKm
    : null
  const storedRouteFeeAmount = assignmentOfferRouteAmount !== null
    ? assignmentOfferRouteAmount
    : storedRouteCostMatchesSelection && typeof request.travelFeeAmount === 'number' && Number.isFinite(request.travelFeeAmount)
      ? request.travelFeeAmount
      : null
  const hasStoredRouteFeeAmount = storedRouteFeeAmount !== null && storedRouteFeeAmount > 0
  const hasStoredRouteCost = Boolean(
    selectedTechnician && (
      storedRouteRoundTripKm !== null
      || storedRouteBillableKm !== null
      || storedRouteFeeAmount !== null
    ),
  )
  const hasRouteCostEvidence = hasActiveRouteQuote || hasStoredRouteCost
  const routeFeeNeedsApproval = Boolean(activeRouteQuote?.travel_fee_required && !hasStoredRouteFeeAmount)
  const selectedTechnicianName = selectedTechnician?.name ?? request.technician ?? 'Seçili usta'
  const routeFeeNotCalculatedMessage = `${selectedTechnicianName} için usta yol hakedişi henüz hesaplanmadı.`
  const routeFeeNotCalculatedHint = 'Usta yol hakedişini hesaplamak için seçili usta ve müşteri konumu kullanılacak.'
  const routeFeeSavedHint = assignmentOfferRouteAmount !== null
    ? 'Atama hakedişi üzerinden kaydedildi.'
    : storedRouteCostMatchesSelection
      ? 'Kaydedilmiş yol hakedişi üzerinden gösteriliyor.'
      : 'Seçili usta değiştiği için yol hakedişi yeniden hesaplanmalı.'
  const shouldShowRouteFeeNotCalculatedMessage = Boolean(
    selectedTechnician && !routeQuoteLoading && !hasRouteCostEvidence,
  )
  const shouldShowRouteQuoteLoading = routeQuoteLoading && !hasRouteCostEvidence
  const routeFeeCalculateButtonText = shouldShowRouteQuoteLoading
    ? 'Hesaplanıyor...'
    : 'Yeniden hesapla'
  const routeFeeStatusText = hasStoredRouteFeeAmount
    ? 'Usta yol hakedişi kaydedildi'
    : routeQuoteStaleForSelectedTechnician
    ? shouldShowRouteQuoteLoading ? 'Usta yol hakedişi hesaplanıyor' : hasStoredRouteCost ? 'Usta yol hakedişi kaydedildi' : 'Usta yol hakedişi hesaplanmadı'
    : hasActiveRouteQuote && activeRouteQuote
      ? activeRouteQuote.travel_fee_required ? 'Usta yol hakedişi gönderilmeli' : 'Usta yol hakedişi yok'
      : hasStoredRouteCost
        ? 'Usta yol hakedişi kaydedildi'
      : selectedTechnician ? 'Usta yol hakedişi hesaplanmadı' : routeQuote ? 'Usta yol hakedişi hesaplanamadı' : 'Usta yol hakedişi hesaplanmadı'
  const routeRoundTripKm = hasActiveRouteQuote
    ? typeof activeRouteQuote?.round_trip_distance_km === 'number' && Number.isFinite(activeRouteQuote.round_trip_distance_km)
      ? activeRouteQuote.round_trip_distance_km
      : typeof activeRouteQuote?.distance_km === 'number' && Number.isFinite(activeRouteQuote.distance_km)
        ? activeRouteQuote.distance_km
        : null
    : storedRouteRoundTripKm
  const routeOneWayKm = hasActiveRouteQuote
    ? typeof activeRouteQuote?.one_way_distance_km === 'number' && Number.isFinite(activeRouteQuote.one_way_distance_km)
      ? activeRouteQuote.one_way_distance_km
      : routeRoundTripKm !== null
        ? roundTwo(routeRoundTripKm / 2)
        : null
    : routeRoundTripKm !== null ? roundTwo(routeRoundTripKm / 2) : null
  const routeBillableKm = hasActiveRouteQuote
    ? typeof activeRouteQuote?.billable_km === 'number' && Number.isFinite(activeRouteQuote.billable_km)
      ? activeRouteQuote.billable_km
      : typeof activeRouteQuote?.extra_km === 'number' && Number.isFinite(activeRouteQuote.extra_km)
        ? activeRouteQuote.extra_km
        : null
    : storedRouteBillableKm
  const routeFeePerKm = routeFeeConfigPerKm
  const routeFeeAmount = hasActiveRouteQuote && typeof activeRouteQuote?.fee_amount === 'number' && Number.isFinite(activeRouteQuote.fee_amount)
    ? activeRouteQuote.fee_amount
    : storedRouteFeeAmount
  const routeStraightLineKm = hasActiveRouteQuote && typeof activeRouteQuote?.straight_line_distance_km === 'number' && Number.isFinite(activeRouteQuote.straight_line_distance_km)
    ? activeRouteQuote.straight_line_distance_km
    : null
  const routeSuspicious = Boolean(hasActiveRouteQuote && activeRouteQuote?.suspicious_route)
  const mountPaymentRecords = saleAndPayment?.mount_payments?.rows ?? []
  const paidMountPaymentRecords = saleAndPayment?.mount_payments?.paid_rows ?? mountPaymentRecords.filter((payment) => payment.status === 'paid')
  const pendingMountPaymentRecords = saleAndPayment?.mount_payments?.pending_rows ?? mountPaymentRecords.filter((payment) => payment.status === 'pending')
  const cancelledMountPaymentRecords = saleAndPayment?.mount_payments?.cancelled_rows ?? mountPaymentRecords.filter((payment) => payment.status === 'cancelled')
  const latestPendingMountPayment = saleAndPayment?.mount_payments?.latest_pending ?? pendingMountPaymentRecords[0] ?? null
  const latestPaidMountPayment = saleAndPayment?.mount_payments?.latest_paid ?? paidMountPaymentRecords[0] ?? null
  const latestCancelledMountPayment = saleAndPayment?.mount_payments?.latest_cancelled ?? cancelledMountPaymentRecords[0] ?? null
  const extraMountPayment = latestPendingMountPayment ?? saleAndPayment?.extra_mount_payment ?? latestPaidMountPayment
  const customerChargeSummary = saleAndPayment?.customer_charges ?? null
  const paymentSummary = saleAndPayment?.payment_summary ?? null
  const paymentSummaryMount = paymentSummary?.mount ?? null
  const paymentSummaryService = paymentSummary?.service ?? null
  const paymentSummaryPart = paymentSummary?.part ?? null
  const paymentSummaryExtra = paymentSummary?.extra ?? null
  const latestCustomerCharge = customerChargeSummary?.latest ?? null
  const technicianEarningMessage = saleAndPayment?.technician_earning_message ?? null
  const earningBreakdown = request.earningBreakdown ?? null
  const financeSummary = request.financeSummary ?? null
  const financeCurrentVisit = financeSummary?.current_visit ?? null
  const financeRootTotal = financeSummary?.root_total ?? null
  const financeCustomerCollection = financeCurrentVisit?.customer_collection ?? null
  const financeLocksmithPayout = financeCurrentVisit?.locksmith_payout ?? null
  const financeNetMargin = financeCurrentVisit?.net_margin ?? null
  const financePayoutTechnicianIdString = financeLocksmithPayout?.technician_id !== null && financeLocksmithPayout?.technician_id !== undefined
    ? String(financeLocksmithPayout.technician_id)
    : null
  const financePayoutMatchesSelectedTechnician = Boolean(
    financeLocksmithPayout
    && (
      selectedTechnicianIdString
        ? financePayoutTechnicianIdString === selectedTechnicianIdString
        : requestTechnicianIdString
          ? !financePayoutTechnicianIdString || financePayoutTechnicianIdString === requestTechnicianIdString
          : false
    ),
  )
  const activeFinanceLocksmithPayout = financePayoutMatchesSelectedTechnician ? financeLocksmithPayout : null
  const existingPendingPaymentAmount = typeof latestPendingMountPayment?.amount === 'number' && Number.isFinite(latestPendingMountPayment.amount) && latestPendingMountPayment.amount > 0
    ? latestPendingMountPayment.amount
    : null
  const pendingOnlinePaymentLink = pendingMountPaymentRecords.length > 0
  const paidOnlinePaymentLink = paidMountPaymentRecords.length > 0
  const cancelledOnlinePaymentLink = cancelledMountPaymentRecords.length > 0
  const paymentLinkActionLabel = paidOnlinePaymentLink
    ? 'Ödeme Düzenle'
    : pendingOnlinePaymentLink
      ? 'Ödeme Düzenle'
      : 'Ödeme Al'
  const paymentLinkModalTitle = paidOnlinePaymentLink
    ? 'Ödeme Düzenle'
    : pendingOnlinePaymentLink ? 'Ödeme Düzenle' : 'Ödeme Al'
  const paymentLinkSubmitLabel = extraPaymentCreateLoading
    ? paidOnlinePaymentLink ? 'Ek ödeme linki hazırlanıyor...' : pendingOnlinePaymentLink ? 'Link güncelleniyor...' : 'Link oluşturuluyor...'
    : paidOnlinePaymentLink
      ? pendingOnlinePaymentLink ? 'Ek ödeme linki oluştur / bekleyen linki kullan' : 'Ek ödeme linki oluştur'
      : pendingOnlinePaymentLink
      ? 'Bekleyen linki güncelle / yeniden kullan'
      : 'Link oluştur'
  const existingPendingPaymentAmountInput = numericInputValue(existingPendingPaymentAmount)
  const extraPaymentAmount = parseNumericInput(routeFeeExtraPaymentInput)
  const paymentLinkAmountSourceLabel = routeFeeExtraPaymentInput.trim() === ''
    ? 'Tutar kaynağı: Manuel giriş gerekli'
    : existingPendingPaymentAmount !== null && routeFeeExtraPaymentInput === existingPendingPaymentAmountInput
      ? 'Tutar kaynağı: Mevcut ödeme kaydı'
      : 'Tutar kaynağı: Operasyon manuel girişi'
  const paymentLinkAmountWarning = routeFeeEditorMode === 'payment_link' && routeFeeExtraPaymentInput.trim() === ''
    ? 'Ödeme tutarı net değil. Link oluşturmak için tutar girin.'
    : null
  const customerServiceChargeAmount = parseNumericInput(customerServiceChargeInput) ?? 0
  const customerPartChargeAmount = parseNumericInput(customerPartChargeInput) ?? 0
  const customerChargeTotalAmount = roundTwo(customerServiceChargeAmount + customerPartChargeAmount)
  const customerChargeAddressLabel = [
    request.address,
    [request.district, request.city].filter(Boolean).join(' / '),
  ].filter((value) => typeof value === 'string' && value.trim() !== '').join(' - ')
  const hasCustomerChargeAddress = customerChargeAddressLabel.trim() !== ''
  const customerChargeAddressError = 'Müşteri adresi eksik. Ödeme linki oluşturmak için müşteri adresini girin.'
  const canCreateCustomerCharge = Boolean(onExtraMountPaymentCreate && customerChargeTotalAmount > 0 && hasCustomerChargeAddress)
  const customerChargePurpose = customerServiceChargeAmount > 0 && customerPartChargeAmount > 0
    ? 'service_and_part_payment'
    : customerPartChargeAmount > 0
      ? 'part_payment'
      : 'service_payment'
  const canCreateExtraPayment = Boolean(
    onExtraMountPaymentCreate
    && extraPaymentAmount !== null
    && extraPaymentAmount > 0
    && (routeFeeEditorMode === 'payment_link' || selectedTechnician)
  )
  const selectedTechnicianCoordinateLabel = formatCoordinatePair(
    selectedTechnician?.latitude ?? selectedTechnician?.startLatitude,
    selectedTechnician?.longitude ?? selectedTechnician?.startLongitude,
  )
  const selectedTechnicianMapHref = selectedTechnicianCoordinateLabel !== '-'
    ? `https://www.google.com/maps?q=${selectedTechnicianCoordinateLabel.replace(/\s/g, '')}`
    : null
  const routeDestinationCoordinates = routeDestinationCoordinatePair(locationInfo)
  const customerCoordinateLabel = routeDestinationCoordinates
    ? formatCoordinatePair(routeDestinationCoordinates.latitude, routeDestinationCoordinates.longitude)
    : formatCoordinatePair(locationInfo?.latitude, locationInfo?.longitude)
  const customerMapHref = customerCoordinateLabel !== '-'
    ? `https://www.google.com/maps?q=${customerCoordinateLabel.replace(/\s/g, '')}`
    : null
  const routeFeeEditorCurrentSnapshot = JSON.stringify({
    oneWay: routeFeeOneWayKmInput,
    roundTrip: routeFeeRoundTripKmInput,
    threshold: routeFeeThresholdKmInput,
    feePerKm: routeFeePerKmInput,
    billable: routeFeeBillableKmInput,
    amount: routeFeeAmountInput,
    extraPayment: routeFeeExtraPaymentInput,
    manualAmountTouched: routeFeeManualAmountTouched,
    note: routeFeeNote.trim(),
  })
  const routeFeeEditorHasChanges = routeFeeEditorInitialSnapshot !== '' && routeFeeEditorCurrentSnapshot !== routeFeeEditorInitialSnapshot
  const operationControl = request.operationControl ?? {}
  const isServiceVisitDetail = visibleSections?.is_service_visit === true
    || operationControl.is_service_visit === true
    || Boolean(serviceVisitHistory?.service_code || serviceVisitHistory?.reason)
  const showMountOperationControls = (visibleSections?.operation_mount_controls ?? operationControl.show_mount_controls ?? !isServiceVisitDetail) === true
  const showPaymentControl = showMountOperationControls
    && (visibleSections?.payment_control ?? operationControl.show_payment_control ?? true) === true
  const showDoorPhotoControl = showMountOperationControls
    && (visibleSections?.door_photo_control ?? operationControl.show_door_photo_control ?? true) === true
  const showAddressControl = showMountOperationControls
    && (visibleSections?.address_control ?? operationControl.show_address_control ?? false) === true
  const showScheduleControl = (visibleSections?.schedule_control ?? operationControl.show_schedule_control ?? showMountOperationControls) === true
  const showMountExcludedApprovalBlock = Boolean(opsDetailVisibility.show_mount_excluded_approval_block)
  const showAddressControlBlock = Boolean(opsDetailVisibility.show_address_control_block)
  const paymentControlMissing = showPaymentControl && operationControl.payment_checked !== 'yes'
  const doorPhotoControlMissing = showDoorPhotoControl && (!operationControl.door_photos_checked || operationControl.door_photos_checked === 'unreviewed')
  const [operationInfoOpenByRequest, setOperationInfoOpenByRequest] = useState<Record<string, boolean>>({})
  const operationInfoOpen = operationInfoOpenByRequest[request.id] ?? defaultOpenOpsSections.has('operation')
  const setOperationInfoOpen = (open: boolean) => {
    setOperationInfoOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const operationInfoRef = useRef<HTMLDetailsElement | null>(null)
  const assignmentInfoRef = useRef<HTMLDetailsElement | null>(null)
  const fieldCompletionRef = useRef<HTMLDetailsElement | null>(null)
  const finalCheckRef = useRef<HTMLDetailsElement | null>(null)
  const [highlightedNextActionTarget, setHighlightedNextActionTarget] = useState<NextActionSectionTarget | null>(null)
  const [nextActionNavigationMessage, setNextActionNavigationMessage] = useState<string | null>(null)
  const canonicalPaymentStatus = saleAndPayment?.payment_status ?? null
  const canonicalPaymentRequiresPayment = Boolean(canonicalPaymentStatus?.requires_payment && !canonicalPaymentStatus?.is_paid)
  const mountPaymentReceived = Boolean(
    canonicalPaymentStatus?.is_paid
    || saleAndPayment?.mount_payment_received
    || saleAndPayment?.mount_payment_status === 'paid'
    || paidOnlinePaymentLink,
  )
  const mountPaymentStageLabel = displayOrEmpty(
    canonicalPaymentStatus?.stage_label ?? saleAndPayment?.payment_stage_label,
    mountPaymentReceived ? 'Ödeme onaylandı' : canonicalPaymentRequiresPayment ? 'Montaj ödemesi henüz alınmadı' : 'Montaj ödemesi gerekmiyor',
  )
  const mountPaymentAmountLabel = paymentSummaryMount?.amount_label
    ?? saleAndPayment?.paid_amount_label
    ?? (typeof saleAndPayment?.paid_amount === 'number' && Number.isFinite(saleAndPayment.paid_amount)
      ? formatMoneyValue(saleAndPayment.paid_amount)
      : typeof canonicalPaymentStatus?.amount === 'number' && Number.isFinite(canonicalPaymentStatus.amount)
        ? formatMoneyValue(canonicalPaymentStatus.amount)
        : typeof saleAndPayment?.mount_payments?.paid_total_amount === 'number' && Number.isFinite(saleAndPayment.mount_payments.paid_total_amount) && saleAndPayment.mount_payments.paid_total_amount > 0
          ? formatMoneyValue(saleAndPayment.mount_payments.paid_total_amount)
          : '-')
  const paymentCollectionStatusLabel = saleAndPayment?.payment_status_label
    ?? paymentStatusLabel(saleAndPayment?.mount_payment_status, mountPaymentReceived)
  const paidAmountDisplayLabel = paymentSummaryMount?.amount_label
    ?? saleAndPayment?.paid_amount_label
    ?? mountPaymentAmountLabel
  const paymentPaidAtLabel = dateTimeOrEmpty(saleAndPayment?.payment_paid_at ?? saleAndPayment?.paid_at, '-')
  const opsPaymentCheckLabel = saleAndPayment?.ops_payment_check_label
    ?? operationPaymentCheckLabel(operationControl.payment_checked)
  const paidMountPaymentAmount = mountPaymentReceived
    ? typeof paymentSummaryMount?.amount === 'number' && Number.isFinite(paymentSummaryMount.amount)
      ? paymentSummaryMount.amount
      : typeof saleAndPayment?.paid_amount === 'number' && Number.isFinite(saleAndPayment.paid_amount)
      ? saleAndPayment.paid_amount
      : typeof canonicalPaymentStatus?.amount === 'number' && Number.isFinite(canonicalPaymentStatus.amount)
        ? canonicalPaymentStatus.amount
        : null
    : null
  const customerMountAmount = paidMountPaymentAmount ?? (showPaymentControl ? basePaymentInfo.customerAmount : null)
  const mountPaymentLabel = paidMountPaymentAmount !== null
    ? `${formatMoneyValue(paidMountPaymentAmount)} KDV dahil`
    : showPaymentControl && basePaymentInfo.customerAmountLabel && basePaymentInfo.customerAmountLabel !== 'Belirlenmedi'
      ? basePaymentInfo.customerAmountLabel
      : '-'
  const mountPaymentHeaderLabel = mountPaymentReceived
    ? `Montaj ödeme: ${mountPaymentStageLabel}`
    : canonicalPaymentRequiresPayment
      ? 'Montaj ödeme: Alınmadı'
      : `Montaj ödeme: ${mountPaymentStageLabel}`
  const shouldRenderHeaderPaymentSummary = showPaymentControl && (
    mountPaymentReceived
    || canonicalPaymentRequiresPayment
    || hasMultiProductRequest
    || Boolean(saleAndPayment?.payment_reference)
    || Boolean(saleAndPayment?.payment_provider)
    || paymentPaidAtLabel !== '-'
  )
  const mountPaymentDetailLabel = mountPaymentReceived
    ? 'Montaj ödemesi alındı'
    : canonicalPaymentRequiresPayment
      ? 'Montaj ödemesi henüz alınmadı'
      : mountPaymentStageLabel
  const mountExclusionAcknowledgement = operationControl.mount_exclusion_acknowledgement ?? null
  const mountExclusionAckRequired = Boolean(
    mountExclusionAcknowledgement?.required
    || (
      (saleAndPayment?.sale_mount_status === 'montaj_haric' || saleAndPayment?.sale_mount_label === 'Montaj Hariç')
      && hasMultiProductRequest
      && !mountPaymentReceived
    ),
  )
  const mountExclusionAckComplete = !mountExclusionAckRequired
    || (mountExclusionAcknowledged && mountExclusionNote.trim().length >= 5)
  const mountExcludedOrPaymentRequired = Boolean(
    canonicalPaymentRequiresPayment
    || saleAndPayment?.sale_mount_status === 'montaj_haric'
    || saleAndPayment?.sale_mount_label === 'Montaj Hariç'
    || saleAndPayment?.mount_payment_status === 'skipped_multi_product'
    || saleAndPayment?.mount_payment_status === 'pending',
  )
  const paymentOwnership = settlement?.payer_state_key ? settlement : saleAndPayment?.payment_ownership
  const payerStateKey = paymentOwnership?.payer_state_key
    ?? (mountPaymentReceived ? 'company_collected_online' : pendingOnlinePaymentLink ? 'pending_online_payment' : mountExcludedOrPaymentRequired ? 'payment_decision_missing' : 'no_payment_required')
  const payerStateLabel = paymentOwnership?.payer_state_label
    ?? (payerStateKey === 'company_collected_external'
      ? 'Dış ödeme alındı.'
      : payerStateKey === 'company_collected_online'
        ? 'Ödeme şirket tarafından alındı.'
        : payerStateKey === 'pending_online_payment'
          ? 'Online ödeme linki bekliyor.'
          : payerStateKey === 'customer_pays_technician'
            ? 'Ödeme müşteriden ustaya yapılacak.'
            : payerStateKey === 'no_payment_required'
              ? 'Bu işte ek ödeme gerekmiyor.'
              : 'Ödeme yöntemi netleşmedi.')
  const payerStateDescription = paymentOwnership?.payer_state_description
    ?? (payerStateKey === 'company_collected_external' || payerStateKey === 'company_collected_online'
      ? 'Müşteri ustaya ödeme yapmayacak; şirket ödemesi hakediş mutabakatından takip edilir.'
      : payerStateKey === 'pending_online_payment'
        ? 'Ödeme alınmadan müşteri tahsilatı sayılmaz; bekleyen veya iptal edilen linkler tahsilata eklenmez.'
        : payerStateKey === 'customer_pays_technician'
          ? 'Müşteriye bildirilecek tutar ustaya ödenecek tutardır; şirketin kalan ödemesi hakediş mutabakatında takip edilir.'
          : 'Ödeme linki oluşturun veya müşterinin ustaya ödeyeceği tutarı belirleyin.')
  const payerCustomerInstruction = paymentOwnership?.payment_instruction_for_customer
    ?? (payerStateKey === 'company_collected_external' || payerStateKey === 'company_collected_online'
      ? 'Müşteri ustaya ödeme yapmayacak.'
      : payerStateKey === 'customer_pays_technician'
        ? 'Müşteri ustaya ödeme yapacak.'
        : payerStateKey === 'pending_online_payment'
          ? 'Online ödeme sonucu bekleniyor.'
          : payerStateKey === 'no_payment_required'
            ? 'Müşteriye ödeme talimatı yok.'
            : 'Ödeme yöntemi netleşmeli.')
  const customerShouldPayTechnician = Boolean(paymentOwnership?.customer_should_pay_technician)
  const shouldShowCustomerPaysTechnicianCard = payerStateKey === 'customer_pays_technician' && customerShouldPayTechnician
  const shouldShowPayerStateCard = Boolean(
    mountPaymentReceived
    || pendingOnlinePaymentLink
    || mountExcludedOrPaymentRequired
    || settlement
    || paymentOwnership,
  )
  const payerStateTone = payerStateKey === 'company_collected_online' || payerStateKey === 'company_collected_external'
    ? 'border-emerald-200 bg-emerald-50 text-emerald-950'
    : payerStateKey === 'pending_online_payment' || payerStateKey === 'payment_decision_missing'
      ? 'border-amber-200 bg-amber-50 text-amber-950'
      : 'border-slate-200 bg-slate-50 text-slate-900'
  const customerDirectAmountLabel = typeof settlement?.customer_direct_to_technician_amount === 'number'
    && Number.isFinite(settlement.customer_direct_to_technician_amount)
    ? formatMoneyValue(settlement.customer_direct_to_technician_amount)
    : null
  const activeCustomerDirectAmountLabel = typeof paymentOwnership?.active_customer_direct_to_technician_amount === 'number'
    && Number.isFinite(paymentOwnership.active_customer_direct_to_technician_amount)
    ? formatMoneyValue(paymentOwnership.active_customer_direct_to_technician_amount)
    : customerDirectAmountLabel
  const companyPayableAmountLabel = typeof settlement?.company_payable_amount === 'number'
    && Number.isFinite(settlement.company_payable_amount)
    ? formatMoneyValue(settlement.company_payable_amount)
    : null
  const companyCollectedAmountLabel = typeof paymentOwnership?.company_collected_amount === 'number'
    && Number.isFinite(paymentOwnership.company_collected_amount)
    ? formatMoneyValue(paymentOwnership.company_collected_amount)
    : mountPaymentAmountLabel !== '-' ? mountPaymentAmountLabel : null
  const pendingPaymentTotalLabel = typeof paymentOwnership?.pending_payment_total === 'number'
    && Number.isFinite(paymentOwnership.pending_payment_total)
    && paymentOwnership.pending_payment_total > 0
    ? formatMoneyValue(paymentOwnership.pending_payment_total)
    : pendingOnlinePaymentLink ? mountPaymentAmountLabel : null
  const assignmentBlockerMessages = request.assignmentBlockers?.messages ?? []
  const backendAssignmentBlockersAvailable = request.assignmentBlockers !== undefined && request.assignmentBlockers !== null
  const assignmentRequiresOperationControls = (request.assignmentBlockers?.applies_to_assignment ?? operationControl.applies_to_assignment ?? !isServiceVisitDetail) !== false
  const assignmentUiBlockerMessages = !backendAssignmentBlockersAvailable && assignmentRequiresOperationControls ? [
    showPaymentControl && canonicalPaymentRequiresPayment && operationControl.payment_checked !== 'yes' ? 'Önce ödeme kontrolünü tamamlayın.' : null,
    showDoorPhotoControl && operationControl.door_photos_checked !== 'compatible' ? 'Önce kapı görsellerini uygun olarak işaretleyin.' : null,
  ].filter((message): message is string => Boolean(message)) : []
  const combinedAssignmentBlockerMessages = Array.from(new Set([...assignmentUiBlockerMessages, ...assignmentBlockerMessages]))
  const isAssignmentBlocked = combinedAssignmentBlockerMessages.length > 0
  const assignmentSubmitDisabled = assignLoading
    || !selectedTechnicianId
    || isAssignmentBlocked
    || !canSubmitAssign
    || !onAssignSelectedTechnician
  const resolvedSaleMountLabel = saleAndPayment?.sale_mount_label ?? mikroMountCheck?.montaj_durumu ?? '-'
  const resolvedMountPaymentLabel = mountPaymentReceived
    ? mountPaymentStageLabel
    : canonicalPaymentRequiresPayment
      ? saleAndPayment?.mount_payment_label ?? mountPaymentStageLabel
      : mountPaymentStageLabel
  const paidExtraCustomerAmount = typeof financeCustomerCollection?.extra_amount === 'number' && Number.isFinite(financeCustomerCollection.extra_amount)
    ? financeCustomerCollection.extra_amount
    : typeof paymentSummaryExtra?.amount === 'number' && Number.isFinite(paymentSummaryExtra.amount)
    ? paymentSummaryExtra.amount
    : typeof saleAndPayment?.mount_payments?.paid_extra_amount === 'number' && Number.isFinite(saleAndPayment.mount_payments.paid_extra_amount)
      ? saleAndPayment.mount_payments.paid_extra_amount
      : 0
  const paidServiceCustomerAmount = typeof financeCustomerCollection?.service_amount === 'number' && Number.isFinite(financeCustomerCollection.service_amount)
    ? financeCustomerCollection.service_amount
    : typeof paymentSummaryService?.amount === 'number' && Number.isFinite(paymentSummaryService.amount)
    ? paymentSummaryService.amount
    : typeof customerChargeSummary?.paid_service_amount === 'number' && Number.isFinite(customerChargeSummary.paid_service_amount)
      ? customerChargeSummary.paid_service_amount
      : 0
  const paidPartCustomerAmount = typeof financeCustomerCollection?.part_amount === 'number' && Number.isFinite(financeCustomerCollection.part_amount)
    ? financeCustomerCollection.part_amount
    : typeof paymentSummaryPart?.amount === 'number' && Number.isFinite(paymentSummaryPart.amount)
    ? paymentSummaryPart.amount
    : typeof customerChargeSummary?.paid_part_amount === 'number' && Number.isFinite(customerChargeSummary.paid_part_amount)
      ? customerChargeSummary.paid_part_amount
      : 0
  const paidCustomerChargeAmount = typeof customerChargeSummary?.paid_total_amount === 'number' && Number.isFinite(customerChargeSummary.paid_total_amount)
    ? customerChargeSummary.paid_total_amount
    : roundTwo(paidServiceCustomerAmount + paidPartCustomerAmount)
  const totalCustomerCollectedAmount = typeof financeCustomerCollection?.total_amount === 'number' && Number.isFinite(financeCustomerCollection.total_amount)
    ? financeCustomerCollection.total_amount
    : typeof paymentSummary?.total_customer_collection === 'number' && Number.isFinite(paymentSummary.total_customer_collection)
    ? paymentSummary.total_customer_collection
    : customerMountAmount !== null
    ? roundTwo(customerMountAmount + paidExtraCustomerAmount + paidCustomerChargeAmount)
    : paidExtraCustomerAmount + paidCustomerChargeAmount > 0 ? roundTwo(paidExtraCustomerAmount + paidCustomerChargeAmount) : null
  const hasServiceCustomerPayment = financeCustomerCollection
    ? paidServiceCustomerAmount > 0 || financeCustomerCollection.has_service_charge === true
    : paidServiceCustomerAmount > 0 || paymentSummary?.has_service_charge === true
  const hasPartCustomerPayment = financeCustomerCollection
    ? paidPartCustomerAmount > 0 || financeCustomerCollection.has_part_charge === true
    : paidPartCustomerAmount > 0 || paymentSummary?.has_part_charge === true
  const hasExtraCustomerPayment = financeCustomerCollection
    ? paidExtraCustomerAmount > 0 || financeCustomerCollection.has_extra_charge === true
    : paidExtraCustomerAmount > 0 || paymentSummary?.has_extra_charge === true
  const hasMountCustomerPayment = financeCustomerCollection
    ? financeCustomerCollection.mount_amount > 0 || financeCustomerCollection.has_mount_collection === true
    : paidMountPaymentAmount !== null || paymentSummary?.has_mount_collection === true
  const serviceCustomerPaymentLabel = hasServiceCustomerPayment
    ? `${paymentSummaryService?.status_label ?? 'Ödendi'} - ${financeCustomerCollection?.service_amount_label ?? paymentSummaryService?.amount_label ?? formatMoneyValue(paidServiceCustomerAmount)}`
    : null
  const partCustomerPaymentLabel = hasPartCustomerPayment
    ? `${paymentSummaryPart?.status_label ?? 'Ödendi'} - ${financeCustomerCollection?.part_amount_label ?? paymentSummaryPart?.amount_label ?? formatMoneyValue(paidPartCustomerAmount)}`
    : null
  const totalCustomerCollectionLabel = financeCustomerCollection?.total_amount_label
    ?? paymentSummary?.total_customer_collection_label
    ?? (totalCustomerCollectedAmount !== null ? formatMoneyValue(totalCustomerCollectedAmount) : null)
  const zeroCustomerCollectionIsExpected = financeCurrentVisit?.is_service_visit === true || financeCurrentVisit?.warranty_covered === true
  const totalCustomerCollectionDisplayLabel = financeCustomerCollection
    && financeCustomerCollection.has_collection !== true
    && financeCustomerCollection.total_amount <= 0
    && !zeroCustomerCollectionIsExpected
    ? 'Ödeme kaydı yok'
    : (totalCustomerCollectionLabel ?? 'Ödeme kaydı yok')
  const financeRootCollection = financeRootTotal?.customer_collection ?? null
  const financeRootCustomerCollectionDisplayLabel = financeRootCollection
    && financeRootCollection.has_collection !== true
    && financeRootCollection.total_amount <= 0
    ? 'Ödeme kaydı yok'
    : (financeRootCollection?.total_amount_label ?? (financeRootCollection ? formatMoneyValue(financeRootCollection.total_amount) : 'Ödeme kaydı yok'))
  const showServicePartPaymentSummary = servicePartChargeSectionVisible
    || Boolean(latestCustomerCharge)
    || hasServiceCustomerPayment
    || hasPartCustomerPayment
  const showPaymentTechnicalDetails = Boolean(saleAndPayment?.payment_reference || paymentPaidAtLabel !== '-' || saleAndPayment?.payment_provider)
  const fallbackTechnicianLaborCostLabel = selectedTechnician?.technicianAmountLabel && selectedTechnician.technicianAmountLabel !== 'Belirlenmedi'
    ? selectedTechnician.technicianAmountLabel
    : basePaymentInfo.technicianAmountLabel && basePaymentInfo.technicianAmountLabel !== 'Belirlenmedi'
      ? basePaymentInfo.technicianAmountLabel
      : 'Hakediş ayarı eksik'
  const fallbackTechnicianLaborCostAmount = typeof request.technicianPaymentAmount === 'number' && Number.isFinite(request.technicianPaymentAmount)
    ? request.technicianPaymentAmount
    : basePaymentInfo.customerAmount
  const hasPayoutTechnicianContext = Boolean(selectedTechnician || requestTechnicianIdString || activeAssignmentOffer || activeFinanceLocksmithPayout)
  const hasCanonicalPayout = Boolean(
    activeFinanceLocksmithPayout
    && (
      activeFinanceLocksmithPayout.total_amount > 0
      || activeAssignmentOffer
      || request.technicianPaymentAmount !== null
      || request.travelFeeAmount !== null
    ),
  )
  const technicianLaborCostAmount = !hasPayoutTechnicianContext
    ? null
    : hasCanonicalPayout
    ? activeFinanceLocksmithPayout?.labor_amount ?? 0
    : assignmentOfferLaborAmount ?? fallbackTechnicianLaborCostAmount
  const technicianLaborCostLabel = !hasPayoutTechnicianContext
    ? 'Usta seçilmedi'
    : hasCanonicalPayout
    ? activeFinanceLocksmithPayout?.labor_amount_label ?? formatMoneyValue(activeFinanceLocksmithPayout?.labor_amount ?? 0)
    : assignmentOfferLaborAmount !== null
    ? formatMoneyValue(assignmentOfferLaborAmount)
    : fallbackTechnicianLaborCostLabel
  const fallbackTravelCostLabel = hasRouteCostEvidence
    ? routeFeeAmount === null && activeRouteQuote?.travel_fee_required
      ? 'Km başı ücret ayarı eksik'
      : formatMoneyValue(routeFeeAmount)
    : 'Hesaplanmadı'
  const travelCostLabel = hasCanonicalPayout
    ? activeFinanceLocksmithPayout?.route_fee_amount_label ?? formatMoneyValue(activeFinanceLocksmithPayout?.route_fee_amount ?? 0)
    : assignmentOfferRouteAmount !== null
    ? formatMoneyValue(assignmentOfferRouteAmount)
    : fallbackTravelCostLabel
  const totalTechnicianCostAmount = !hasPayoutTechnicianContext
    ? null
    : hasCanonicalPayout
    ? activeFinanceLocksmithPayout?.total_amount ?? 0
    : assignmentOfferTotalAmount !== null
    ? assignmentOfferTotalAmount
    : technicianLaborCostAmount !== null
      ? roundTwo(technicianLaborCostAmount + (hasRouteCostEvidence && routeFeeAmount !== null ? routeFeeAmount : 0))
      : null
  const requestStateKey = String(request.id)
  const earningTotalOverride = earningTotalOverrideByRequest[requestStateKey] ?? ''
  const earningTotalOverrideTouched = Boolean(earningTotalOverrideTouchedByRequest[requestStateKey])
  const parsedEarningTotalOverride = parseNumericInput(earningTotalOverride)
  const earningTotalAmount = earningTotalOverrideTouched ? parsedEarningTotalOverride : totalTechnicianCostAmount
  const totalTechnicianCostLabel = !hasPayoutTechnicianContext
    ? 'Usta seçilmedi'
    : hasCanonicalPayout
    ? activeFinanceLocksmithPayout?.total_amount_label ?? formatMoneyValue(activeFinanceLocksmithPayout?.total_amount ?? 0)
    : totalTechnicianCostAmount !== null
    ? formatMoneyValue(totalTechnicianCostAmount)
    : 'Hakediş ayarı eksik'
  const locksmithPayoutStatus = activeFinanceLocksmithPayout?.payout_status
    ?? (financePayoutMatchesSelectedTechnician ? financeCurrentVisit?.payout_status : null)
    ?? (activeAssignmentOffer ? 'confirmed' : hasCanonicalPayout ? 'draft' : null)
  const locksmithPayoutStatusLabel = activeFinanceLocksmithPayout?.payout_status_label
    ?? (financePayoutMatchesSelectedTechnician ? financeCurrentVisit?.payout_status_label : null)
    ?? (locksmithPayoutStatus === 'confirmed'
      ? 'Onaylanan usta hakedişi'
      : locksmithPayoutStatus === 'draft'
        ? 'Önerilen / taslak hakediş'
        : 'Hakediş yok')
  const locksmithPayoutPaymentStatusLabel = activeFinanceLocksmithPayout?.payment_status_label
    ?? (financePayoutMatchesSelectedTechnician ? financeCurrentVisit?.payment_status_label : null)
    ?? 'Hakediş ödeme kaydı yok'
  const locksmithPayoutPaidAt = activeFinanceLocksmithPayout?.paid_at ?? (financePayoutMatchesSelectedTechnician ? financeCurrentVisit?.paid_at : null) ?? null
  const locksmithPayoutTotalMetricLabel = locksmithPayoutStatus === 'confirmed'
    ? 'Onaylanan usta hakedişi'
    : locksmithPayoutStatus === 'draft'
      ? 'Önerilen / taslak usta hakedişi'
      : 'Usta hakedişi toplamı'
  const financeSummaryTitle = financeCurrentVisit?.warranty_covered
    ? 'Usta Hakedişi / Operasyon Maliyeti'
    : 'Usta Hakedişi'
  const financeSummaryHint = financeCurrentVisit?.warranty_covered
    ? 'Müşteri tahsilatı 0 TL ise usta hakedişi operasyon maliyetidir; ödeme/tahsilat değildir.'
    : 'Müşteri tahsilatı ve usta hakedişi ayrı izlenir.'
  const netDifferenceMetricLabel = financeCurrentVisit?.warranty_covered
    ? 'Net operasyon farkı'
    : 'Net fark / kâr'
  const showFinanceCollectionMetrics = !hasAssignmentChange && Boolean(requestTechnicianIdString || activeFinanceLocksmithPayout || activeAssignmentOffer)
  const earningSummaryTechnicianName = cleanDisplayText(selectedTechnician?.name || request.technicianName || 'Usta seçilmedi')
  const netProfitLabel = financeNetMargin?.amount_label
    ?? (totalCustomerCollectedAmount !== null && earningTotalAmount !== null
    ? formatMoneyValue(totalCustomerCollectedAmount - earningTotalAmount)
    : 'Hesaplanamadı')
  const canSendTechnicianEarning = Boolean(
    selectedTechnician
    && selectedTechnician.phone
    && onTechnicianEarningMessageCreate
    && earningTotalAmount !== null
    && earningTotalAmount >= 0,
  )
  const technicianEarningPreviewText = selectedTechnician && earningTotalAmount !== null
    ? [
      `Merhaba ${selectedTechnician.name},`,
      'Hakediş bilgisi:',
      `MRN: ${displayMrn || request.mrn}`,
      `Bölge: ${[request.city, request.district].filter(Boolean).join(' / ') || '-'}`,
      `Ürün / Seri: ${[request.product || '-', request.serialNumber || '-'].join(' / ')}`,
      `Montaj işçilik: ${formatMoneyValue(technicianLaborCostAmount ?? 0)}`,
      `Usta yol hakedişi: ${formatMoneyValue(assignmentOfferRouteAmount ?? (hasRouteCostEvidence ? routeFeeAmount ?? 0 : 0))}`,
      `Toplam hakediş: ${formatMoneyValue(earningTotalAmount)}`,
      `Randevu: ${request.scheduledAt ? dateTimeOrEmpty(request.scheduledAt, '-') : request.scheduledDate ? [request.scheduledDate, request.scheduledTime].filter(Boolean).join(' ') : '-'}`,
      earningNoteInput.trim() ? `Not: ${earningNoteInput.trim()}` : null,
    ].filter((line): line is string => typeof line === 'string' && line.trim() !== '').join('\n')
    : ''
  const displayedEarningMessageText = earningMessageText || assignmentOfferMessageText || technicianEarningMessage?.message_text || technicianEarningPreviewText || ''
  const displayedEarningWhatsappUrl = earningMessageUrl
    || (displayedEarningMessageText && (selectedTechnician?.phone || request.technicianPhone)
      ? `${whatsappHrefForPhone(selectedTechnician?.phone ?? request.technicianPhone)}?text=${encodeURIComponent(displayedEarningMessageText)}`
      : '')
  const assignmentOfferDispatchStatus = activeAssignmentOffer?.dispatch_status
    ?? (
      activeAssignmentOffer?.metadata
      && typeof activeAssignmentOffer.metadata === 'object'
      && activeAssignmentOffer.metadata.message_dispatch
      && typeof activeAssignmentOffer.metadata.message_dispatch === 'object'
        ? stringValue(activeAssignmentOffer.metadata.message_dispatch as Record<string, unknown>, 'status')
        : null
    )
  const earningDispatchStatusLabel = technicianEarningMessage?.status === 'sent'
    ? 'Hakediş bilgisi gönderildi'
    : assignmentOfferDispatchStatus === 'sent'
      ? 'Hakediş bilgisi gönderildi'
      : assignmentOfferDispatchStatus
        ? 'Hakediş mesajı hazırlandı, gerçek WhatsApp gönderimi kapalı'
        : 'Hakediş bilgisi gönderilmedi'
  const hasSupportRequestDetail = supportRequests.length > 0
  const hasSparePartDetail = partRequests.length > 0
  const hasPriceRevisionDetail = Boolean(String(request.technicianRevisionNote ?? '').trim())
  const hasRevisitDetail = revisitRequests.length > 0 || Boolean(request.requiresSecondVisit)
  const hasProductIdentityDetail = Boolean(
    optionalMetricValue(productInfo?.product_name ?? request.product)
    || optionalMetricValue(productInfo?.product_model ?? request.model)
    || optionalMetricValue(productInfo?.serial_number ?? request.serialNumber)
    || optionalMetricValue(productInfo?.brand)
    || optionalMetricValue(productInfo?.activation_code)
    || optionalMetricValue(documentInfo?.invoice_display_no)
    || optionalMetricValue(documentInfo?.dispatch_display_no)
    || optionalMetricValue(documentInfo?.order_display_no),
  )
  const shouldRenderProductInfoPanel = hasProductIdentityDetail || shouldRenderHeaderPaymentSummary || hasMultiProductRequest
  const customerCityDistrictLabel = [request.city, request.district].filter(Boolean).join(' / ')
  const buildingAddressLabel = [
    locationInfo?.building_no,
    locationInfo?.apartment_no,
    locationInfo?.door_no,
    locationInfo?.floor_no,
  ].filter(Boolean).join(' / ')
  const hasCustomerDetail = Boolean(
    optionalMetricValue(request.customer)
    || optionalMetricValue(request.phone)
    || optionalMetricValue(customerCityDistrictLabel)
    || optionalMetricValue(request.address)
    || locationInfo?.shared
    || optionalMetricValue(buildingAddressLabel),
  )
  const invoiceSerialTotalCount = invoiceSerials?.all_invoice_serial_count
    ?? invoiceSerials?.selected_serial_count
    ?? invoiceSerials?.all_invoice_serials?.length
    ?? invoiceSerials?.selected_serials?.length
    ?? 0
  const shouldRenderInvoiceSerialsPanel = Boolean(invoiceSerials?.check_error || invoiceSerialTotalCount > 0)
  const normalizedInvoiceSerialSearch = invoiceSerialSearch.trim().toLocaleLowerCase('tr-TR')
  const invoiceSerialSearchActive = normalizedInvoiceSerialSearch.length > 0
  const canonicalInvoiceSerialRows = uniqueInvoiceSerialRows(invoiceSerials?.all_invoice_serials ?? [])
  const hasCanonicalInvoiceSerialRows = canonicalInvoiceSerialRows.length > 0
  const sourceRequestedInvoiceSerials = hasCanonicalInvoiceSerialRows
    ? canonicalInvoiceSerialRows.filter(invoiceSerialIsSelected)
    : invoiceSerials?.selected_serials ?? []
  const sourceOtherInvoiceSerials = hasCanonicalInvoiceSerialRows
    ? canonicalInvoiceSerialRows.filter(invoiceSerialIsOther)
    : invoiceSerials?.other_serials ?? []
  const sourceHiddenInvoiceSerials = hasCanonicalInvoiceSerialRows
    ? canonicalInvoiceSerialRows.filter(invoiceSerialIsHidden)
    : invoiceSerials?.hidden_serials ?? []
  const sourceReturnedInvoiceSerials = hasCanonicalInvoiceSerialRows
    ? canonicalInvoiceSerialRows.filter(invoiceSerialIsReturned)
    : invoiceSerials?.returned_serials ?? []
  const allSearchableInvoiceSerials = hasCanonicalInvoiceSerialRows
    ? canonicalInvoiceSerialRows
    : uniqueInvoiceSerialRows([
      ...sourceRequestedInvoiceSerials,
      ...sourceOtherInvoiceSerials,
      ...sourceHiddenInvoiceSerials,
      ...sourceReturnedInvoiceSerials,
    ])
  const filteredRequestedInvoiceSerials = filterInvoiceSerials(sourceRequestedInvoiceSerials, normalizedInvoiceSerialSearch)
  const filteredOtherInvoiceSerials = filterInvoiceSerials(sourceOtherInvoiceSerials, normalizedInvoiceSerialSearch)
  const filteredHiddenInvoiceSerials = filterInvoiceSerials(sourceHiddenInvoiceSerials, normalizedInvoiceSerialSearch)
  const filteredReturnedInvoiceSerials = filterInvoiceSerials(sourceReturnedInvoiceSerials, normalizedInvoiceSerialSearch)
  const filteredAllSearchableInvoiceSerials = filterInvoiceSerials(allSearchableInvoiceSerials, normalizedInvoiceSerialSearch)
  const hasAnyFilteredInvoiceSerial = filteredAllSearchableInvoiceSerials.length > 0
  const showInvoiceSerialNoSearchResult = invoiceSerialSearchActive && !invoiceSerialRecheckInFlight && allSearchableInvoiceSerials.length > 0 && !hasAnyFilteredInvoiceSerial
  const shouldRenderHistoryPanel = Boolean((request.auditLogs ?? []).length > 0 || events.length > 0)
  const shouldShowPartCreateAction = canCreatePartRequest && (partRequests.length > 0 || servicePartChargeSectionVisible || activePartRequests.length > 0)
  const shouldShowFinalReasonMetrics = Boolean(request.pendingReason || request.cancellationReason)
  const showAssignmentPortalActionBlock = Boolean(
    openAppointmentProposals.length > 0
    || jobRejections.length > 0
    || customerApprovalRejections.length > 0
    || supportRequests.length > 0
    || revisitRequests.length > 0
    || assignmentOffer,
  )
  const assignedTechnicianCityLabel = displayOrEmpty(selectedTechnician?.location ?? request.city, '-')
  const assignmentDetailsExpandedByDefault = !hasAssignedTechnician || hasAssignmentChange || shouldShowRouteQuoteLoading
  const routeFeeEditorSnapshot = (
    oneWay: string,
    roundTrip: string,
    threshold: string,
    feePerKm: string,
    billable: string,
    amount: string,
    extraPayment: string,
    manualAmountTouched: boolean,
    note: string,
  ) => JSON.stringify({
    oneWay,
    roundTrip,
    threshold,
    feePerKm,
    billable,
    amount,
    extraPayment,
    manualAmountTouched,
    note: note.trim(),
  })
  const updateRouteFeeDerivedFields = (roundTripValue: string, thresholdValue: string, feePerKmValue: string, keepManualAmount: boolean) => {
    const roundTrip = parseNumericInput(roundTripValue)
    const threshold = parseNumericInput(thresholdValue) ?? 30
    const feePerKm = parseNumericInput(feePerKmValue)
    const billable = roundTrip === null ? null : roundTwo(Math.max(roundTrip - threshold, 0))

    setRouteFeeBillableKmInput(numericInputValue(billable))

    if (!keepManualAmount) {
      setRouteFeeAmountInput(billable !== null && feePerKm !== null ? numericInputValue(roundTwo(billable * feePerKm)) : '')
    }
  }
  const openRouteFeeEditor = () => {
    if (!selectedTechnician) {
      setRouteFeeEditorOpen(false)
      setRouteFeeEditorMessage('Önce usta seçin.')

      return
    }

    const oneWay = hasRouteCostEvidence ? numericInputValue(routeOneWayKm) : ''
    const roundTrip = hasRouteCostEvidence ? numericInputValue(routeRoundTripKm) : ''
    const threshold = numericInputValue(activeRouteQuote?.threshold_km ?? routeFeeConfigThresholdKm)
    const feePerKm = numericInputValue(routeFeePerKm)
    const billable = hasRouteCostEvidence ? numericInputValue(routeBillableKm) : '0'
    const amount = hasRouteCostEvidence ? numericInputValue(routeFeeAmount) : '0'
    const extraPayment = existingPendingPaymentAmountInput
    const manualTouched = Boolean(activeRouteQuote?.manual_override)
    const note = activeRouteQuote?.manual_note ?? ''

    setRouteFeeEditorMode('route_fee')
    setRouteFeeOneWayKmInput(oneWay)
    setRouteFeeRoundTripKmInput(roundTrip)
    setRouteFeeThresholdKmInput(threshold)
    setRouteFeePerKmInput(feePerKm)
    setRouteFeeBillableKmInput(billable)
    setRouteFeeAmountInput(amount)
    setRouteFeeExtraPaymentInput(extraPayment)
    setRouteFeeManualAmountTouched(manualTouched)
    setRouteFeeNote(note)
    setRouteFeeEditorInitialSnapshot(routeFeeEditorSnapshot(oneWay, roundTrip, threshold, feePerKm, billable, amount, extraPayment, manualTouched, note))
    setRouteFeeEditorMessage(null)
    setRouteFeeEditorOpen(true)
  }
  const openPaymentLinkModal = () => {
    const oneWay = hasRouteCostEvidence ? numericInputValue(routeOneWayKm) : ''
    const roundTrip = hasRouteCostEvidence ? numericInputValue(routeRoundTripKm) : ''
    const threshold = numericInputValue(activeRouteQuote?.threshold_km ?? routeFeeConfigThresholdKm)
    const feePerKm = numericInputValue(routeFeePerKm)
    const billable = hasRouteCostEvidence ? numericInputValue(routeBillableKm) : '0'
    const amount = hasRouteCostEvidence ? numericInputValue(routeFeeAmount) : '0'
    const paymentAmount = existingPendingPaymentAmountInput
    const manualTouched = Boolean(activeRouteQuote?.manual_override)
    const note = activeRouteQuote?.manual_note ?? ''

    setRouteFeeEditorMode('payment_link')
    setRouteFeeOneWayKmInput(oneWay)
    setRouteFeeRoundTripKmInput(roundTrip)
    setRouteFeeThresholdKmInput(threshold)
    setRouteFeePerKmInput(feePerKm)
    setRouteFeeBillableKmInput(billable)
    setRouteFeeAmountInput(amount)
    setRouteFeeExtraPaymentInput(paymentAmount)
    setRouteFeeManualAmountTouched(manualTouched)
    setRouteFeeNote(note)
    setRouteFeeEditorInitialSnapshot(routeFeeEditorSnapshot(oneWay, roundTrip, threshold, feePerKm, billable, amount, paymentAmount, manualTouched, note))
    setRouteFeeEditorMessage(paymentAmount === '' ? 'Ödeme tutarı net değil. Link oluşturmak için tutar girin.' : null)
    setPaymentCancelError(null)
    setPaymentLinkCopyMessage(null)
    setRouteFeeEditorOpen(true)
  }
  const handleRouteFeeOneWayChange = (value: string) => {
    const oneWay = parseNumericInput(value)
    const roundTripValue = numericInputValue(oneWay === null ? null : roundTwo(oneWay * 2))

    setRouteFeeOneWayKmInput(value)
    setRouteFeeRoundTripKmInput(roundTripValue)
    updateRouteFeeDerivedFields(roundTripValue, routeFeeThresholdKmInput, routeFeePerKmInput, routeFeeManualAmountTouched)
  }
  const handleRouteFeeRoundTripChange = (value: string) => {
    const roundTrip = parseNumericInput(value)

    setRouteFeeRoundTripKmInput(value)
    setRouteFeeOneWayKmInput(numericInputValue(roundTrip === null ? null : roundTwo(roundTrip / 2)))
    updateRouteFeeDerivedFields(value, routeFeeThresholdKmInput, routeFeePerKmInput, routeFeeManualAmountTouched)
  }
  const handleRouteFeeThresholdChange = (value: string) => {
    setRouteFeeThresholdKmInput(value)
    updateRouteFeeDerivedFields(routeFeeRoundTripKmInput, value, routeFeePerKmInput, routeFeeManualAmountTouched)
  }
  const handleRouteFeePerKmChange = (value: string) => {
    setRouteFeePerKmInput(value)
    updateRouteFeeDerivedFields(routeFeeRoundTripKmInput, routeFeeThresholdKmInput, value, routeFeeManualAmountTouched)
  }
  const handleRouteFeeBillableChange = (value: string) => {
    const billable = parseNumericInput(value)
    const feePerKm = parseNumericInput(routeFeePerKmInput)

    setRouteFeeBillableKmInput(value)

    if (!routeFeeManualAmountTouched) {
      setRouteFeeAmountInput(billable !== null && feePerKm !== null ? numericInputValue(roundTwo(billable * feePerKm)) : '')
    }
  }
  const handleRouteFeeAmountChange = (value: string) => {
    setRouteFeeManualAmountTouched(true)
    setRouteFeeAmountInput(value)
  }
  const handleRouteFeeManualSave = async () => {
    if (!selectedTechnician || !onRouteQuoteManualSave) {
      setRouteFeeEditorMessage('Önce usta seçin.')

      return
    }

    const payload: ServiceRequestRouteQuoteManualPayload = {
      technical_service_technician_id: selectedTechnician.id,
      one_way_distance_km: parseNumericInput(routeFeeOneWayKmInput),
      round_trip_distance_km: parseNumericInput(routeFeeRoundTripKmInput),
      threshold_km: parseNumericInput(routeFeeThresholdKmInput),
      billable_km: parseNumericInput(routeFeeBillableKmInput),
      fee_per_km: parseNumericInput(routeFeePerKmInput),
      fee_amount: parseNumericInput(routeFeeAmountInput),
      manual_override: routeFeeManualAmountTouched,
      manual_note: routeFeeNote.trim() || null,
    }

    try {
      await onRouteQuoteManualSave(payload)
      setRouteFeeEditorInitialSnapshot(routeFeeEditorCurrentSnapshot)
      setRouteFeeEditorOpen(false)
    } catch {
      // Parent keeps the error message; keep this editor open so the operator can correct values.
    }
  }
  const handleExtraPaymentCreate = async () => {
    if (!onExtraMountPaymentCreate) {
      setRouteFeeEditorMessage('Ödeme linki oluşturma servisi bağlı değil.')

      return
    }

    if (routeFeeEditorMode !== 'payment_link' && !selectedTechnician) {
      setRouteFeeEditorMessage('Önce usta seçin.')

      return
    }

    if (extraPaymentAmount === null || extraPaymentAmount <= 0) {
      setRouteFeeEditorOpen(true)
      setRouteFeeEditorMessage('Ödeme linki için ödeme tutarını girin. Tutar 0 TL üzerinde olmalı.')

      return
    }

    const selectedSerialIds = invoiceSerials?.selected_serials
      ?.map((serial) => serial.id)
      .filter((id): id is number | string => id !== null && id !== undefined) ?? []

    const payload: ServiceRequestExtraMountPaymentPayload = {
      route_quote_id: activeRouteQuote?.id ?? null,
      technician_id: selectedTechnician?.id ?? null,
      selected_serial_ids: selectedSerialIds,
      amount: extraPaymentAmount,
      currency: 'TRY',
      reason: routeFeeEditorMode === 'payment_link' ? 'manual_extra' : 'route_fee',
      purpose: routeFeeEditorMode === 'payment_link' ? 'manual_mount_payment' : 'route_fee',
      note: routeFeeNote.trim() || null,
    }

    try {
      await onExtraMountPaymentCreate(payload)
      setPaymentCancelError(null)
      setPaymentLinkCopyMessage(null)
      setPaymentLinkManualCopyValue(null)
      setRouteFeeEditorMessage('Ödeme linki oluşturuldu.')
    } catch (caught) {
      setRouteFeeEditorMessage(caught instanceof Error ? caught.message : 'Ödeme linki oluşturulamadı.')
    }
  }
  const handlePendingPaymentCancel = async (payment: NonNullable<typeof pendingMountPaymentRecords[number]>) => {
    if (!payment.id) {
      setPaymentCancelError('İptal edilecek ödeme kaydı bulunamadı.')

      return
    }

    if (!onMountPaymentCancel) {
      setPaymentCancelError('Ödeme linki iptal servisi bağlı değil.')

      return
    }

    const confirmed = window.confirm('Bekleyen ödeme linki iptal edilecek. Ödeme alınmadıysa tahsilata eklenmez.')

    if (!confirmed) {
      return
    }

    setPaymentCancelInFlight(payment.id)
    setPaymentCancelError(null)

    try {
      await onMountPaymentCancel(payment.id, { reason: 'OPS tarafından iptal edildi' })
      setRouteFeeEditorMessage('Bekleyen ödeme linki iptal edildi.')
    } catch (caught) {
      setPaymentCancelError(caught instanceof Error ? caught.message : 'Ödeme linki iptal edilemedi.')
    } finally {
      setPaymentCancelInFlight(null)
    }
  }
  const handlePendingPaymentSync = async (payment: NonNullable<typeof pendingMountPaymentRecords[number]>) => {
    if (!payment.id) {
      setPaymentCancelError('Kontrol edilecek ödeme kaydı bulunamadı.')

      return
    }

    if (!onMountPaymentSync) {
      setPaymentCancelError('Ödeme durumu kontrol servisi bağlı değil.')

      return
    }

    setPaymentSyncInFlight(payment.id)
    setPaymentCancelError(null)

    try {
      await onMountPaymentSync(payment.id)
      setRouteFeeEditorMessage('Ödeme durumu kontrol edildi.')
    } catch (caught) {
      setPaymentCancelError(caught instanceof Error ? caught.message : 'Ödeme durumu kontrol edilemedi.')
    } finally {
      setPaymentSyncInFlight(null)
    }
  }
  const handlePendingPaymentSend = async (payment: PaymentLinkSendTarget) => {
    if (!payment.id) {
      setPaymentCancelError('Gönderilecek ödeme kaydı bulunamadı.')

      return
    }

    if (!onMountPaymentSend) {
      setPaymentCancelError('Ödeme linki mesaj kuyruğu servisi bağlı değil.')

      return
    }

    setPaymentSendInFlight(payment.id)
    setPaymentCancelError(null)

    try {
      await onMountPaymentSend(payment.id)
      setRouteFeeEditorMessage('Ödeme linki müşteriye gönderilmek üzere kuyruğa alındı.')
    } catch (caught) {
      setPaymentCancelError(caught instanceof Error ? caught.message : 'Ödeme linki müşteriye gönderilemedi.')
    } finally {
      setPaymentSendInFlight(null)
    }
  }
  const paymentLinkSendBlocker = (payment: PaymentLinkSendTarget | null | undefined): string | null => {
    if (!payment?.id) {
      return 'Gönderilecek ödeme kaydı bulunamadı.'
    }

    if (!paymentLinkCopyUrl(payment)) {
      return 'Ödeme linki olmadan müşteriye mesaj kuyruğu oluşturulamaz.'
    }

    if (Number(payment.amount ?? 0) <= 0) {
      return 'Ödeme tutarı olmadan müşteriye mesaj kuyruğu oluşturulamaz.'
    }

    if (payment.status && payment.status !== 'pending') {
      return 'Yalnızca bekleyen ödeme linki müşteriye gönderilebilir.'
    }

    return null
  }
  const renderPaymentLinkSendAction = (payment: PaymentLinkSendTarget | null | undefined) => {
    const blocker = paymentLinkSendBlocker(payment)
    const paymentId = payment?.id ?? null

    return (
      <Button
        type="button"
        size="sm"
        variant="outline"
        disabled={Boolean(blocker) || paymentSendInFlight === paymentId}
        title={blocker ?? 'Ödeme linkini müşteriye WhatsApp ve SMS kuyruğuna al'}
        onClick={() => payment && void handlePendingPaymentSend(payment)}
      >
        {paymentSendInFlight === paymentId ? 'Kuyruğa alınıyor...' : 'Linki müşteriye gönder'}
      </Button>
    )
  }
  const handleCreatePaymentLinkAction = () => {
    scrollToNextActionSection('assignment')

    if (!onExtraMountPaymentCreate) {
      openPaymentLinkModal()
      setRouteFeeEditorMessage('Ödeme linki oluşturma servisi bağlı değil.')

      return
    }

    openPaymentLinkModal()
  }
  const handleBottomPaymentLinkAction = () => {
    if (!onExtraMountPaymentCreate) {
      setRouteFeeEditorMessage('Ödeme linki oluşturma servisi bağlı değil.')
    }

    openPaymentLinkModal()
  }
  const renderTechnicianSuggestionCard = (technician: NonNullable<ServiceRequestDetailsProps['technicianSuggestions']>[number]) => {
    const selected = selectedTechnicianId === technician.id
    const routeMismatch = selected
      && hasActiveRouteQuote
      && typeof technician.estimatedRoundTripKm === 'number'
      && technician.estimatedRoundTripKm > 0
      && typeof routeRoundTripKm === 'number'
      && routeRoundTripKm > 0
      && Math.max(routeRoundTripKm, technician.estimatedRoundTripKm) / Math.min(routeRoundTripKm, technician.estimatedRoundTripKm) > 3

    return (
      <div key={technician.id} className={[
        'grid gap-2 rounded-xl border bg-white p-3 text-sm transition md:grid-cols-[minmax(0,1fr)_auto] md:items-center',
        selected ? 'border-blue-300 ring-2 ring-blue-100' : 'border-slate-200',
      ].join(' ')}>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="truncate font-semibold text-slate-950">{technician.name}</span>
            {technician.recommended ? <Badge variant="positive">Önerilen</Badge> : null}
            {selected ? <Badge variant="secondary">Seçildi</Badge> : null}
            {(technician.needsReview || routeMismatch) ? <Badge variant="warning">Kontrol gerekli</Badge> : null}
          </div>
          <p className="mt-1 text-xs text-slate-500">
            {[technician.phone, technician.location, technician.distanceKmLabel].filter(Boolean).join(' · ')}
          </p>
          {optionalMetricValue(technician.addressSummary) ? (
            <p className="mt-1 truncate text-xs text-slate-500" title={technician.addressSummary ?? undefined}>
              Adres: {technician.addressSummary}
            </p>
          ) : null}
        </div>
        <div className="flex flex-wrap items-center gap-2 text-xs text-slate-600 md:justify-end">
          <span className="rounded-full bg-slate-100 px-2 py-1">Öncelik: {displayOrEmpty(String(technician.priority ?? ''), '-')}</span>
          <span className="rounded-full bg-slate-100 px-2 py-1">İş: {technician.scheduledCount}</span>
          <Button
            type="button"
            size="sm"
            variant={selected ? 'secondary' : 'outline'}
            onClick={() => {
              setRouteFeeEditorMessage(null)
              setRouteFeeEditorOpen(false)
              setRouteFeeEditorInitialSnapshot('')
              setOtherTechniciansModalOpenByRequest((current) => ({ ...current, [request.id]: false }))
              onTechnicianSelect?.(technician.id, technician.estimatedRoundTripKm ?? null)
            }}
          >
            {selected ? 'Seçildi' : 'Seç'}
          </Button>
        </div>
      </div>
    )
  }
  const handleCustomerChargeCreate = async () => {
    if (!onExtraMountPaymentCreate) {
      setRouteFeeEditorMessage('Ödeme linki oluşturma servisi bağlı değil.')

      return
    }

    if (customerChargeTotalAmount <= 0) {
      setRouteFeeEditorMessage('Servis/parça ödeme tutarı 0 TL üzerinde olmalı.')

      return
    }

    if (!hasCustomerChargeAddress) {
      setRouteFeeEditorMessage(customerChargeAddressError)

      return
    }

    await onExtraMountPaymentCreate({
      service_amount: customerServiceChargeAmount,
      part_amount: customerPartChargeAmount,
      amount: customerChargeTotalAmount,
      currency: 'TRY',
      purpose: customerChargePurpose,
      reason: customerChargePurpose,
      note: customerChargeNoteInput.trim() || null,
      message_template: customerChargeMessageInput.trim() || null,
    })
    setCustomerChargeCopyMessage(null)
    setCustomerChargeModalOpen(true)
    setRouteFeeEditorMessage('Müşteri servis/parça ödeme linki oluşturuldu.')
  }
  const openPartDecisionModal = (partRequest: NonNullable<ServiceRequest['partRequests']>[number]) => {
    setPartDecisionRequestId(partRequest.id)
    setPartDecisionMode(partRequest.charge_decision === 'chargeable' ? 'chargeable' : warrantyIsActive ? 'free' : 'chargeable')
    setPartDecisionServiceAmount(partRequest.service_amount !== null && partRequest.service_amount !== undefined ? String(partRequest.service_amount) : '')
    setPartDecisionPartAmount(partRequest.part_amount !== null && partRequest.part_amount !== undefined ? String(partRequest.part_amount) : '')
    setPartDecisionMessage(partRequest.customer_message ?? partRequest.partner_message ?? '')
  }
  const openPartCreateModal = () => {
    setPartCreateMode(warrantyIsActive ? 'free' : 'chargeable')
    setPartCreateError(null)
    setPartCreateModalOpen(true)
  }
  const closePartCreateModal = () => {
    setPartCreateModalOpen(false)
    setPartCreateName('')
    setPartCreateCode('')
    setPartCreateQuantity('1')
    setPartCreateMode('free')
    setPartCreateServiceAmount('')
    setPartCreatePartAmount('')
    setPartCreateNote('')
    setPartCreateMessage('')
    setPartCreateError(null)
  }
  const handlePartCreateSubmit = async () => {
    if (!onPartRequestCreate) {
      return
    }

    const partName = partCreateName.trim()
    const quantity = Math.max(1, Math.round(parseNumericInput(partCreateQuantity) ?? 1))
    const serviceAmount = parseNumericInput(partCreateServiceAmount) ?? 0
    const partAmount = parseNumericInput(partCreatePartAmount) ?? 0
    const note = partCreateNote.trim()
    const message = partCreateMessage.trim()

    if (partName.length < 2) {
      setPartCreateError('Parça adı zorunludur.')

      return
    }

    if (partCreateMode === 'chargeable' && partAmount <= 0) {
      setPartCreateError('Ücretli parça için parça bedeli 0 TL üzerinde olmalı.')

      return
    }

    if (partCreateMode === 'chargeable' && !hasCustomerChargeAddress) {
      setPartCreateError(customerChargeAddressError)

      return
    }

    if (partCreateMode === 'chargeable' && message.length < 3) {
      setPartCreateError('Ücretli parça için müşteriye gönderilecek mesaj zorunludur.')

      return
    }

    setPartCreateSubmitting(true)
    setPartCreateError(null)

    try {
      await onPartRequestCreate({
        part_name: partName,
        part_code: partCreateCode.trim() || null,
        quantity,
        charge_decision: partCreateMode,
        service_amount: serviceAmount,
        part_amount: partAmount,
        note: note || null,
        partner_message: partCreateMode === 'free' ? 'Parça ücretsiz / garanti kapsamında karşılanacak.' : message,
        customer_message: partCreateMode === 'chargeable' ? message : null,
      })
      closePartCreateModal()
      setRouteFeeEditorMessage(partCreateMode === 'chargeable' ? 'Parça talebi ve ödeme state’i oluşturuldu.' : 'Ücretsiz parça talebi oluşturuldu.')
    } catch (caught) {
      setPartCreateError(caught instanceof Error ? caught.message : 'Parça talebi oluşturulamadı.')
    } finally {
      setPartCreateSubmitting(false)
    }
  }
  const closePartDecisionModal = () => {
    setPartDecisionRequestId(null)
    setPartDecisionMode('free')
    setPartDecisionServiceAmount('')
    setPartDecisionPartAmount('')
    setPartDecisionMessage('')
  }
  const handlePartDecisionSubmit = async () => {
    if (!selectedPartDecisionRequest || !onPartRequestTransition) {
      return
    }

    const serviceAmount = parseNumericInput(partDecisionServiceAmount) ?? 0
    const partAmount = parseNumericInput(partDecisionPartAmount) ?? 0
    const message = partDecisionMessage.trim()

    if (partDecisionMode === 'chargeable' && partAmount <= 0) {
      setRouteFeeEditorMessage('Ücretli parça kararında parça bedeli 0 TL üzerinde olmalı.')

      return
    }

    if (partDecisionMode === 'chargeable' && !hasCustomerChargeAddress) {
      setRouteFeeEditorMessage(customerChargeAddressError)

      return
    }

    if (partDecisionMode === 'chargeable' && message.length < 3) {
      setRouteFeeEditorMessage('Ücretli parça kararında müşteri mesajı zorunludur.')

      return
    }

    try {
      await onPartRequestTransition(selectedPartDecisionRequest.id, {
        status: selectedPartDecisionRequest.status === 'requested' || selectedPartDecisionRequest.status === 'ops_review'
          ? 'approved'
          : selectedPartDecisionRequest.status,
        note: partRequestNotes[String(selectedPartDecisionRequest.id)] ?? null,
        partner_message: partDecisionMode === 'free' ? 'Parça ücretsiz / garanti kapsamında karşılanacak.' : message,
        charge_decision: partDecisionMode,
        service_amount: serviceAmount,
        part_amount: partAmount,
        customer_message: partDecisionMode === 'chargeable' ? message : null,
      })

      closePartDecisionModal()
      setRouteFeeEditorMessage(partDecisionMode === 'chargeable' ? 'Parça ödeme linki oluşturuldu.' : 'Parça ücretsiz olarak işaretlendi.')
    } catch (caught) {
      setRouteFeeEditorMessage(caught instanceof Error ? caught.message : 'Parça talebi kararı kaydedilemedi.')
    }
  }
  const handleTechnicianEarningMessageCreate = async () => {
    if (!selectedTechnician || !onTechnicianEarningMessageCreate) {
      setRouteFeeEditorMessage('Önce usta seçin.')

      return
    }

    if (!selectedTechnician.phone) {
      setRouteFeeEditorMessage('Usta telefonu olmadan hakediş bilgisi gönderilemez.')

      return
    }

    if (earningTotalAmount === null) {
      setRouteFeeEditorMessage('Ustaya gönderilecek toplam hakediş hesaplanamadı.')

      return
    }

    const response = await onTechnicianEarningMessageCreate({
      technician_id: selectedTechnician.id,
      labor_amount: technicianLaborCostAmount,
      route_fee_amount: hasRouteCostEvidence ? routeFeeAmount ?? 0 : 0,
      total_amount: earningTotalAmount,
      note: earningNoteInput.trim() || null,
      message_text: earningMessageText.trim() || null,
      manual_override: earningTotalOverrideTouched,
    })

    if (response && typeof response === 'object') {
      setEarningMessageText(response.message_text ?? response.copy_text ?? '')
      setEarningMessageUrl(response.whatsapp_url ?? '')
    }

    setRouteFeeEditorMessage('Hakediş bilgisi gönderildi.')
  }
  const operationControlChange = <K extends keyof NonNullable<ServiceRequest['operationControl']>>(
    key: K,
    value: NonNullable<ServiceRequest['operationControl']>[K],
  ) => {
    void onOperationControlChange?.({ [key]: value } as Partial<NonNullable<ServiceRequest['operationControl']>>)
  }
  const whatsappHref = whatsappHrefForPhone(request.phone)
  const latestCustomerChargePaymentUrl = latestCustomerCharge?.payment_url ?? ''
  const customerChargeDefaultMessage = latestCustomerCharge?.message_text
    ?? latestCustomerCharge?.message_template
    ?? (latestCustomerChargePaymentUrl
      ? `Emaks Prime servis/parça ödemeniz için bağlantı:\n\n${latestCustomerChargePaymentUrl}`
      : 'Emaks Prime servis/parça ödemeniz için ödeme bağlantısı oluşturulacaktır.')
  const customerChargeMessageText = customerChargeMessageInput.trim() || customerChargeDefaultMessage
  const openCustomerChargeModal = () => {
    setCustomerChargeCopyMessage(null)
    setCustomerChargeModalOpen(true)
  }
  const customerChargeModal = customerChargeModalOpen ? (
    <div className="fixed inset-0 z-[80] flex items-end justify-center bg-slate-950/50 p-3 sm:items-center" role="dialog" aria-modal="true" aria-label="Servis/parça ödeme linki">
      <div className="max-h-[92dvh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-blue-100 bg-white p-4 shadow-2xl">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-base font-semibold text-slate-950">Servis/parça ödeme linki</p>
            <p className="mt-1 text-xs text-slate-600">Servis ve parça tutarını girin; toplam server tarafında yeniden hesaplanır.</p>
          </div>
          <Button type="button" size="sm" variant="ghost" onClick={() => setCustomerChargeModalOpen(false)}>
            Kapat
          </Button>
        </div>
        <div className="mt-4 grid gap-3">
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="grid gap-1 text-xs font-semibold text-slate-600">
              Servis ücreti
              <Input type="number" inputMode="decimal" min="0" step="1" value={customerServiceChargeInput} onChange={(event) => setCustomerServiceChargeInput(event.target.value)} />
            </label>
            <label className="grid gap-1 text-xs font-semibold text-slate-600">
              Parça ücreti
              <Input type="number" inputMode="decimal" min="0" step="1" value={customerPartChargeInput} onChange={(event) => setCustomerPartChargeInput(event.target.value)} />
            </label>
            <MiniMetric label="Toplam" value={formatMoneyValue(customerChargeTotalAmount)} />
          </div>
          <p className={['rounded-xl border px-3 py-2 text-xs font-semibold', hasCustomerChargeAddress ? 'border-emerald-100 bg-emerald-50 text-emerald-800' : 'border-rose-100 bg-rose-50 text-rose-800'].join(' ')}>
            {hasCustomerChargeAddress ? `Ödeme linkinde müşteri adresi kullanılacak: ${customerChargeAddressLabel}` : customerChargeAddressError}
          </p>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Açıklama
            <Input value={customerChargeNoteInput} onChange={(event) => setCustomerChargeNoteInput(event.target.value)} placeholder="İç operasyon notu" />
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Müşteriye gönderilecek mesaj
            <textarea
              value={customerChargeMessageInput}
              onChange={(event) => setCustomerChargeMessageInput(event.target.value)}
              placeholder="Servis/parça ödeme açıklaması"
              className="min-h-24 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
            />
          </label>
          {latestCustomerCharge ? (
            <div className="grid gap-2 rounded-xl border border-blue-100 bg-blue-50 p-3 text-xs text-blue-900">
              <p className="font-semibold">Son ödeme linki: {latestCustomerCharge.status_label ?? latestCustomerCharge.status ?? '-'}</p>
              <p>Servis: {latestCustomerCharge.service_amount_label ?? formatMoneyValue(latestCustomerCharge.service_amount ?? 0)} · Parça: {latestCustomerCharge.part_amount_label ?? formatMoneyValue(latestCustomerCharge.part_amount ?? 0)} · Toplam: {latestCustomerCharge.amount_label ?? formatMoneyValue(latestCustomerCharge.amount ?? 0)}</p>
              {latestCustomerChargePaymentUrl ? (
                <>
                  <input
                    readOnly
                    value={latestCustomerChargePaymentUrl}
                    className="w-full rounded-lg border border-blue-100 bg-white px-3 py-2 text-xs text-blue-950"
                  />
                  <textarea
                    readOnly
                    value={customerChargeMessageText}
                    className="min-h-24 w-full rounded-lg border border-blue-100 bg-white px-3 py-2 text-xs text-blue-950"
                  />
                  <div className="flex flex-wrap gap-2">
                    <Button type="button" size="sm" variant="outline" onClick={() => void copyCustomerChargeValue(latestCustomerChargePaymentUrl, 'Link kopyalandı.')}>
                      Linki kopyala
                    </Button>
                    {renderPaymentLinkSendAction(latestCustomerCharge)}
                    <Button asChild type="button" size="sm" variant="outline">
                      <a href={latestCustomerChargePaymentUrl} target="_blank" rel="noreferrer">Linki aç</a>
                    </Button>
                    <Button type="button" size="sm" variant="outline" onClick={() => void copyCustomerChargeValue(customerChargeMessageText, 'Mesaj metni kopyalandı.')}>
                      Mesaj metnini kopyala
                    </Button>
                  </div>
                </>
              ) : null}
            </div>
          ) : null}
          {customerChargeCopyMessage ? (
            <p className="text-xs font-semibold text-blue-800">{customerChargeCopyMessage}</p>
          ) : null}
          {routeFeeEditorMessage ? (
            <p className="text-xs font-semibold text-slate-700">{routeFeeEditorMessage}</p>
          ) : null}
          <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3">
            <Button type="button" variant="outline" size="sm" onClick={() => setCustomerChargeModalOpen(false)}>
              Vazgeç
            </Button>
            <Button type="button" size="sm" onClick={() => void handleCustomerChargeCreate()} disabled={!canCreateCustomerCharge || extraPaymentCreateLoading}>
              {extraPaymentCreateLoading ? 'Link oluşturuluyor...' : 'Link oluştur'}
            </Button>
          </div>
        </div>
      </div>
    </div>
  ) : null
  const otherTechniciansModal = otherTechniciansModalOpen ? (
    <div className="fixed inset-0 z-[80] flex items-end justify-center bg-slate-950/50 p-3 sm:items-center" role="dialog" aria-modal="true" aria-label="Diğer ustalar">
      <div className="max-h-[92dvh] w-full max-w-4xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-base font-semibold text-slate-950">Diğer ustalar</p>
            <p className="mt-1 text-xs text-slate-600">İlk 4 öneri ekranda kalır; kalan ustaları buradan seçin.</p>
          </div>
          <Button type="button" size="sm" variant="ghost" onClick={() => setOtherTechniciansModalOpenByRequest((current) => ({ ...current, [request.id]: false }))}>
            Kapat
          </Button>
        </div>
        <div className="mt-4 grid gap-2">
          {remainingTechnicianSuggestions.length > 0 ? (
            remainingTechnicianSuggestions.map((technician) => renderTechnicianSuggestionCard(technician))
          ) : (
            <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
              Gösterilecek ek usta yok.
            </div>
          )}
        </div>
      </div>
    </div>
  ) : null
  const paymentLinkEditorModal = routeFeeEditorOpen && routeFeeEditorMode === 'payment_link' ? (
    <div className="pointer-events-auto fixed inset-0 z-[110] flex items-end justify-center bg-slate-950/50 p-3 sm:items-center" role="dialog" aria-modal="true" aria-label={paymentLinkModalTitle}>
      <div className="max-h-[92dvh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-blue-100 bg-white p-4 shadow-2xl">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-base font-semibold text-slate-950">{paymentLinkModalTitle}</p>
            <p className="mt-1 text-xs text-slate-600">İlk tıklama sadece bu pencereyi açar; ödeme linki yalnızca tutar onaylandıktan sonra oluşturulur.</p>
          </div>
          <Button type="button" size="sm" variant="ghost" onClick={() => setRouteFeeEditorOpen(false)}>
            Kapat
          </Button>
        </div>
        <div className="mt-4 grid gap-3">
          {paymentLinkAmountWarning ? (
            <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
              {paymentLinkAmountWarning}
            </div>
          ) : null}
          {routeFeeEditorMessage ? (
            <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
              {routeFeeEditorMessage}
            </div>
          ) : null}
          {extraPaymentCreateError ? (
            <div className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-900">
              {extraPaymentCreateError}
            </div>
          ) : null}
          {paymentCancelError ? (
            <div className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-900">
              {paymentCancelError}
            </div>
          ) : null}
          {paymentLinkCopyMessage ? (
            <div className="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-900">
              {paymentLinkCopyMessage}
            </div>
          ) : null}
          {paymentLinkManualCopyValue ? (
            <label className="grid gap-1 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-950">
              Manuel kopyalama
              <input
                readOnly
                value={paymentLinkManualCopyValue}
                onClick={(event) => event.currentTarget.select()}
                onFocus={(event) => event.currentTarget.select()}
                className="min-w-0 rounded-md border border-amber-200 bg-white px-2 py-1 font-mono text-[11px] font-medium text-amber-950"
              />
            </label>
          ) : null}
          {paidOnlinePaymentLink ? (
            <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900">
              Ödenmiş kayıtlar salt okunur. Ek tahsilat gerekiyorsa yeni ödeme linki oluşturabilirsiniz.
            </div>
          ) : null}
          <div className="grid gap-2 sm:grid-cols-3">
            <MiniMetric label="Toplam alınan ödeme" value={saleAndPayment?.mount_payments?.paid_total_amount_label ?? formatMoneyValue(saleAndPayment?.mount_payments?.paid_total_amount ?? 0)} />
            <MiniMetric label="Bekleyen ödeme linkleri" value={saleAndPayment?.mount_payments?.pending_total_amount_label ?? formatMoneyValue(saleAndPayment?.mount_payments?.pending_total_amount ?? 0)} />
            <MiniMetric label="İptal edilen linkler" value={cancelledOnlinePaymentLink ? (saleAndPayment?.mount_payments?.cancelled_total_amount_label ?? formatMoneyValue(saleAndPayment?.mount_payments?.cancelled_total_amount ?? 0)) : '0 TL'} />
          </div>
          <div className="grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
            <p className="font-semibold text-slate-900">Talep bilgisi</p>
            <div className="grid gap-1 sm:grid-cols-2">
              <span>MRN: {displayMrn ?? request.mrn}</span>
              <span>Seri: {displayOrEmpty(request.serialNumber, '-')}</span>
              <span>Müşteri: {displayOrEmpty(request.customer, '-')}</span>
              <span>Telefon: {displayOrEmpty(request.phone, '-')}</span>
            </div>
          </div>
          {paidMountPaymentRecords.length > 0 ? (
            <div className="grid gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-950">
              <p className="font-semibold">Ödenmiş tahsilatlar</p>
              {paidMountPaymentRecords.map((payment) => (
                <div key={String(payment.id ?? payment.payment_url ?? payment.amount)} className="grid gap-1 rounded-lg border border-emerald-100 bg-white/80 p-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-semibold">{payment.amount_label ?? formatMoneyValue(payment.amount ?? 0)}</span>
                    <Badge variant="secondary">Ödendi</Badge>
                  </div>
                  <p>{payment.paid_at ? `Ödeme zamanı: ${dateTimeOrEmpty(payment.paid_at, '-')}` : 'Ödeme zamanı kaydı yok'}</p>
                  {renderPaymentProviderReferences(payment)}
                  {paymentLinkCopyUrl(payment) ? (
                    <>
                      <p className="break-all text-emerald-800">{paymentLinkCopyUrl(payment)}</p>
                      <Button type="button" size="sm" variant="outline" onClick={() => void copyPaymentLinkValue(paymentLinkCopyUrl(payment))}>
                        Linki kopyala
                      </Button>
                    </>
                  ) : null}
                </div>
              ))}
            </div>
          ) : null}
          {pendingMountPaymentRecords.length > 0 ? (
            <div className="grid gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-950">
              <p className="font-semibold">Bekleyen linkler</p>
              {pendingMountPaymentRecords.map((payment) => (
                <div key={String(payment.id ?? payment.payment_url ?? payment.amount)} className="grid gap-1 rounded-lg border border-amber-100 bg-white/80 p-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-semibold">{payment.amount_label ?? formatMoneyValue(payment.amount ?? 0)}</span>
                    <Badge variant="outline">Bekliyor</Badge>
                  </div>
                  {paymentLinkCopyUrl(payment) ? (
                    <>
                      <p className="break-all text-amber-900">{paymentLinkCopyUrl(payment)}</p>
                      {payment.payment_action_kind === 'open_provider_url' ? (
                        <p className="text-amber-800">Iyzico Sandbox ödeme ekranı açılacak. Ödeme yapıldıktan sonra durum kontrolü/reconciliation ile güncellenecek.</p>
                      ) : null}
                      {renderPaymentProviderReferences(payment)}
                      <div className="flex flex-wrap gap-2">
                        <Button asChild type="button" size="sm" variant="outline">
                          <a href={paymentLinkCopyUrl(payment)} target="_blank" rel="noreferrer">
                            Ödeme linkini aç
                          </a>
                        </Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => void copyPaymentLinkValue(paymentLinkCopyUrl(payment))}>
                          Linki kopyala
                        </Button>
                        {renderPaymentLinkSendAction(payment)}
                        {payment.is_external_provider ? (
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={paymentSyncInFlight === payment.id}
                            onClick={() => void handlePendingPaymentSync(payment)}
                          >
                            {paymentSyncInFlight === payment.id ? 'Kontrol ediliyor...' : 'Durumu Kontrol Et'}
                          </Button>
                        ) : null}
                        <Button
                          type="button"
                          size="sm"
                          variant="destructive"
                          disabled={paymentCancelInFlight === payment.id}
                          onClick={() => void handlePendingPaymentCancel(payment)}
                        >
                          {paymentCancelInFlight === payment.id ? 'İptal ediliyor...' : 'İptal et'}
                        </Button>
                      </div>
                    </>
                  ) : (
                    <div className="flex flex-wrap gap-2">
                      {payment.is_external_provider ? (
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          disabled={paymentSyncInFlight === payment.id}
                          onClick={() => void handlePendingPaymentSync(payment)}
                        >
                          {paymentSyncInFlight === payment.id ? 'Kontrol ediliyor...' : 'Durumu Kontrol Et'}
                        </Button>
                      ) : null}
                      <Button
                        type="button"
                        size="sm"
                        variant="destructive"
                        disabled={paymentCancelInFlight === payment.id}
                        onClick={() => void handlePendingPaymentCancel(payment)}
                      >
                        {paymentCancelInFlight === payment.id ? 'İptal ediliyor...' : 'İptal et'}
                      </Button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          ) : null}
          {cancelledMountPaymentRecords.length > 0 ? (
            <details className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
              <summary className="cursor-pointer font-semibold text-slate-900">
                İptal edilen linkler ({cancelledMountPaymentRecords.length})
                {latestCancelledMountPayment?.cancelled_at ? ` · Son iptal: ${dateTimeOrEmpty(latestCancelledMountPayment.cancelled_at, '-')}` : ''}
              </summary>
              <div className="mt-2 grid gap-2">
                {cancelledMountPaymentRecords.map((payment) => (
                  <div key={String(payment.id ?? payment.payment_url ?? payment.amount)} className="grid gap-1 rounded-lg border border-slate-200 bg-white p-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-semibold">{payment.amount_label ?? formatMoneyValue(payment.amount ?? 0)}</span>
                      <Badge variant="secondary">İptal edildi</Badge>
                    </div>
                    {paymentLinkCopyUrl(payment) ? <p className="break-all text-slate-600">{paymentLinkCopyUrl(payment)}</p> : null}
                    {paymentLinkCopyUrl(payment) ? <p className="text-slate-500">İptal edilmiş link geçmiş kaydıdır; yeniden tahsilat için yeni link oluşturun.</p> : null}
                    {renderPaymentProviderReferences(payment)}
                    <p>İptal zamanı: {dateTimeOrEmpty(payment.cancelled_at, '-')}</p>
                    {payment.cancellation_reason ? <p>Neden: {payment.cancellation_reason}</p> : null}
                    {payment.cancelled_by_name ? <p>İptal eden: {payment.cancelled_by_name}</p> : null}
                  </div>
                ))}
              </div>
            </details>
          ) : null}
          <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_160px]">
            <label className="grid gap-1 text-xs font-semibold text-slate-600">
              {pendingOnlinePaymentLink ? 'Bekleyen / yeni ödeme linki tutarı' : 'Yeni ek ödeme linki tutarı'}
              <Input type="number" inputMode="decimal" min="0" step="1" value={routeFeeExtraPaymentInput} onChange={(event) => setRouteFeeExtraPaymentInput(event.target.value)} />
              <span className="font-medium text-slate-500">{paymentLinkAmountSourceLabel}</span>
            </label>
            <MiniMetric label="Durum" value={paidOnlinePaymentLink ? 'Ödeme düzenleme' : pendingOnlinePaymentLink ? 'Bekleyen link' : 'Yeni link'} />
          </div>
          <label className="grid gap-2 text-sm font-medium text-slate-700">
            Not
            <textarea
              value={routeFeeNote}
              onChange={(event) => setRouteFeeNote(event.target.value)}
              className="min-h-[84px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
              placeholder="Ödeme linki için operasyon notu"
            />
          </label>
          <div className="flex flex-wrap justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => void handleExtraPaymentCreate()}
              disabled={!canCreateExtraPayment || extraPaymentCreateLoading}
            >
              {paymentLinkSubmitLabel}
            </Button>
            <Button type="button" variant="secondary" onClick={() => setRouteFeeEditorOpen(false)}>İptal</Button>
          </div>
        </div>
      </div>
    </div>
  ) : null
  const partCreateModal = partCreateModalOpen ? (
    <div className="fixed inset-0 z-[85] flex items-end justify-center bg-slate-950/50 p-3 sm:items-center" role="dialog" aria-modal="true" aria-label="Parça talebi oluştur">
      <div className="grid max-h-[92vh] w-full max-w-2xl gap-4 overflow-y-auto rounded-3xl bg-white p-5 shadow-2xl">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-violet-700">Parça talebi</p>
            <h3 className="mt-1 text-lg font-bold text-slate-950">Parça Talebi Oluştur</h3>
            <p className="mt-1 text-sm text-slate-600">SRV/MRN üzerinde gerçek parça talebi kaydı açılır; karar aynı kayda işlenir.</p>
          </div>
          <Button type="button" variant="ghost" onClick={closePartCreateModal}>Kapat</Button>
        </div>
        <div className="grid gap-3">
          <div className="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_96px]">
            <label className="grid gap-1 text-xs font-semibold text-slate-600">
              Parça adı
              <Input value={partCreateName} onChange={(event) => setPartCreateName(event.target.value)} placeholder="Örn. Kilit gövdesi" />
            </label>
            <label className="grid gap-1 text-xs font-semibold text-slate-600">
              Parça kodu
              <Input value={partCreateCode} onChange={(event) => setPartCreateCode(event.target.value)} placeholder="Opsiyonel" />
            </label>
            <label className="grid gap-1 text-xs font-semibold text-slate-600">
              Adet
              <Input type="number" min="1" step="1" value={partCreateQuantity} onChange={(event) => setPartCreateQuantity(event.target.value)} />
            </label>
          </div>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Not
            <textarea value={partCreateNote} onChange={(event) => setPartCreateNote(event.target.value)} className="min-h-20 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 outline-none focus:border-violet-300 focus:ring-2 focus:ring-violet-100" placeholder="Parça ihtiyacı, neden veya operasyon notu" />
          </label>
          <div className="grid gap-2 sm:grid-cols-2">
            <button type="button" onClick={() => setPartCreateMode('free')} className={['rounded-2xl border px-4 py-3 text-left text-sm font-semibold', partCreateMode === 'free' ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-700'].join(' ')}>
              Ücretsiz / Garanti kapsamında
            </button>
            <button type="button" onClick={() => setPartCreateMode('chargeable')} className={['rounded-2xl border px-4 py-3 text-left text-sm font-semibold', partCreateMode === 'chargeable' ? 'border-violet-300 bg-violet-50 text-violet-900' : 'border-slate-200 bg-white text-slate-700'].join(' ')}>
              Ücretli
            </button>
          </div>
          {partCreateMode === 'chargeable' ? (
            <div className="grid gap-3 rounded-2xl border border-violet-100 bg-violet-50 p-3">
              <div className="grid gap-3 sm:grid-cols-3">
                <label className="grid gap-1 text-xs font-semibold text-slate-600">
                  Servis bedeli
                  <Input type="number" inputMode="decimal" min="0" step="1" value={partCreateServiceAmount} onChange={(event) => setPartCreateServiceAmount(event.target.value)} />
                </label>
                <label className="grid gap-1 text-xs font-semibold text-slate-600">
                  Parça bedeli
                  <Input type="number" inputMode="decimal" min="0" step="1" value={partCreatePartAmount} onChange={(event) => setPartCreatePartAmount(event.target.value)} />
                </label>
                <MiniMetric label="Toplam" value={formatMoneyValue(roundTwo((parseNumericInput(partCreateServiceAmount) ?? 0) + (parseNumericInput(partCreatePartAmount) ?? 0)))} />
              </div>
              <p className={['rounded-xl border px-3 py-2 text-xs font-semibold', hasCustomerChargeAddress ? 'border-emerald-100 bg-emerald-50 text-emerald-800' : 'border-rose-100 bg-rose-50 text-rose-800'].join(' ')}>
                {hasCustomerChargeAddress ? `Ücretli parça ödeme linkinde müşteri adresi kullanılacak: ${customerChargeAddressLabel}` : customerChargeAddressError}
              </p>
              <label className="grid gap-1 text-xs font-semibold text-slate-600">
                Müşteri mesajı
                <textarea value={partCreateMessage} onChange={(event) => setPartCreateMessage(event.target.value)} className="min-h-24 rounded-xl border border-violet-100 bg-white px-3 py-2 text-sm text-slate-950 outline-none focus:border-violet-300 focus:ring-2 focus:ring-violet-100" placeholder="Parça bedeli ve ödeme linki için mesaj" />
              </label>
            </div>
          ) : null}
          {partCreateError ? (
            <p className="rounded-xl border border-rose-100 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800">{partCreateError}</p>
          ) : null}
          <div className="flex flex-wrap justify-end gap-2">
            <Button type="button" variant="secondary" onClick={closePartCreateModal}>Vazgeç</Button>
            <Button type="button" onClick={() => void handlePartCreateSubmit()} disabled={partCreateSubmitting}>
              {partCreateSubmitting ? 'Kaydediliyor...' : 'Parça talebi oluştur'}
            </Button>
          </div>
        </div>
      </div>
    </div>
  ) : null
  const partDecisionModal = selectedPartDecisionRequest ? (
    <div className="fixed inset-0 z-[85] flex items-end justify-center bg-slate-950/50 p-3 sm:items-center" role="dialog" aria-modal="true" aria-label="Parça talebi kararı">
      <div className="grid max-h-[92vh] w-full max-w-2xl gap-4 overflow-y-auto rounded-3xl bg-white p-5 shadow-2xl">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-violet-700">Parça talebi kararı</p>
            <h3 className="mt-1 text-lg font-bold text-slate-950">{selectedPartDecisionRequest.part_name}</h3>
            <p className="mt-1 text-sm text-slate-600">{selectedPartDecisionRequest.technician_note || selectedPartDecisionRequest.reason || 'Usta açıklaması yok'}</p>
          </div>
          <Button type="button" variant="ghost" onClick={closePartDecisionModal}>Kapat</Button>
        </div>
        {warrantyIsActive ? (
          <div className="rounded-2xl border border-emerald-100 bg-emerald-50 p-3 text-sm text-emerald-900">
            Ürün garanti kapsamında. Varsayılan karar ücretsizdir; ücretli seçilecekse müşteri mesajında sebep net yazılmalı.
          </div>
        ) : null}
        <div className="grid gap-2 sm:grid-cols-2">
          <button type="button" onClick={() => setPartDecisionMode('free')} className={['rounded-2xl border px-4 py-3 text-left text-sm font-semibold', partDecisionMode === 'free' ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-700'].join(' ')}>
            Ücretsiz / Garanti kapsamında
          </button>
          <button type="button" onClick={() => setPartDecisionMode('chargeable')} className={['rounded-2xl border px-4 py-3 text-left text-sm font-semibold', partDecisionMode === 'chargeable' ? 'border-violet-300 bg-violet-50 text-violet-900' : 'border-slate-200 bg-white text-slate-700'].join(' ')}>
            Ücretli
          </button>
        </div>
        {partDecisionMode === 'chargeable' ? (
          <div className="grid gap-3 rounded-2xl border border-violet-100 bg-violet-50 p-3">
            <div className="grid gap-3 sm:grid-cols-3">
              <label className="grid gap-1 text-xs font-semibold text-slate-600">
                Servis bedeli
                <Input type="number" inputMode="decimal" min="0" step="1" value={partDecisionServiceAmount} onChange={(event) => setPartDecisionServiceAmount(event.target.value)} />
              </label>
              <label className="grid gap-1 text-xs font-semibold text-slate-600">
                Parça bedeli
                <Input type="number" inputMode="decimal" min="0" step="1" value={partDecisionPartAmount} onChange={(event) => setPartDecisionPartAmount(event.target.value)} />
              </label>
              <MiniMetric label="Toplam" value={formatMoneyValue(roundTwo((parseNumericInput(partDecisionServiceAmount) ?? 0) + (parseNumericInput(partDecisionPartAmount) ?? 0)))} />
            </div>
            <p className={['rounded-xl border px-3 py-2 text-xs font-semibold', hasCustomerChargeAddress ? 'border-emerald-100 bg-emerald-50 text-emerald-800' : 'border-rose-100 bg-rose-50 text-rose-800'].join(' ')}>
              {hasCustomerChargeAddress ? `Ücretli parça ödeme linkinde müşteri adresi kullanılacak: ${customerChargeAddressLabel}` : customerChargeAddressError}
            </p>
            <label className="grid gap-1 text-xs font-semibold text-slate-600">
              Müşteri mesajı
              <textarea value={partDecisionMessage} onChange={(event) => setPartDecisionMessage(event.target.value)} className="min-h-24 rounded-xl border border-violet-100 bg-white px-3 py-2 text-sm text-slate-950 outline-none focus:border-violet-300 focus:ring-2 focus:ring-violet-100" placeholder="Parça/servis bedeli ve ödeme linki mesajı" />
            </label>
          </div>
        ) : (
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Ustaya/partnere not
            <textarea value={partDecisionMessage} onChange={(event) => setPartDecisionMessage(event.target.value)} className="min-h-20 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 outline-none focus:border-emerald-300 focus:ring-2 focus:ring-emerald-100" placeholder="Parça garanti kapsamında ücretsiz karşılanacak." />
          </label>
        )}
        {routeFeeEditorMessage ? (
          <p className="rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">{routeFeeEditorMessage}</p>
        ) : null}
        <div className="flex flex-wrap justify-end gap-2">
          <Button type="button" variant="secondary" onClick={closePartDecisionModal}>Vazgeç</Button>
          <Button type="button" onClick={() => void handlePartDecisionSubmit()}>
            {partDecisionMode === 'chargeable' ? 'Kararı kaydet ve ödeme linki oluştur' : 'Ücretsiz olarak kaydet'}
          </Button>
        </div>
      </div>
    </div>
  ) : null
  const selectedHistoryStartTimestamp = selectedHistoryRecord?.technician_arrived_at ?? selectedHistoryRecord?.field_started_at ?? null
  const historyRecordModal = selectedHistoryRecord ? (
    <div className="fixed inset-0 z-[90] flex items-end justify-center bg-slate-950/55 p-3 sm:items-center" role="dialog" aria-modal="true" aria-label="SRV ve ana MRN geçmiş detayı">
      <div className="max-h-[92dvh] w-full max-w-4xl overflow-y-auto rounded-3xl border border-violet-100 bg-white p-5 shadow-2xl">
        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-4">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-violet-700">SRV / Ana MRN Geçmiş Detayı</p>
            <h3 className="mt-1 text-xl font-bold text-slate-950">{selectedHistoryRecord.service_code || selectedHistoryRecord.mrn}</h3>
            <p className="mt-1 text-sm text-slate-600">{[selectedHistoryRecord.service_visit_reason_label, selectedHistoryRecord.workflow_status ?? selectedHistoryRecord.status].filter(Boolean).join(' · ')}</p>
          </div>
          <Button type="button" variant="ghost" onClick={() => setHistoryRecordId(null)}>
            Kapat
          </Button>
        </div>

        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <MiniMetric label="MRN" value={selectedHistoryRecord.mrn} />
          <MiniMetric label="Usta" value={displayOrEmpty(selectedHistoryRecord.technician_name, 'Usta bilgisi yok')} hint={displayOrEmpty(selectedHistoryRecord.technician_phone, 'Telefon yok')} />
          <MiniMetric label="Randevu" value={dateTimeOrEmpty(selectedHistoryRecord.scheduled_at, 'Randevu yok')} />
          <MiniMetric label="Tamamlanma" value={dateTimeOrEmpty(selectedHistoryRecord.technician_completed_at ?? selectedHistoryRecord.field_completed_at ?? selectedHistoryRecord.completed_at, 'Tamamlanmadı')} />
        </div>

        <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_1.2fr]">
          <section className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Ziyaret zaman çizelgesi</p>
            <div className="mt-3 grid gap-2 text-sm text-slate-700">
              {selectedHistoryStartTimestamp ? (
                <div className="flex justify-between gap-3 rounded-xl bg-white px-3 py-2">
                  <span>Usta gidiş / başlangıç</span>
                  <strong className="text-right text-slate-950">{dateTimeOrEmpty(selectedHistoryStartTimestamp, 'Kayıt yok')}</strong>
                </div>
              ) : null}
              <div className="flex justify-between gap-3 rounded-xl bg-white px-3 py-2">
                <span>İş tamamlanma</span>
                <strong className="text-right text-slate-950">{dateTimeOrEmpty(selectedHistoryRecord.technician_completed_at ?? selectedHistoryRecord.field_completed_at ?? selectedHistoryRecord.completed_at, 'Kayıt yok')}</strong>
              </div>
              {selectedHistoryRecord.completion_note ? (
                <div className="rounded-xl bg-white px-3 py-2">
                  <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Tamamlama notu</span>
                  <p className="mt-1 whitespace-pre-wrap break-words text-slate-800">{selectedHistoryRecord.completion_note}</p>
                </div>
              ) : null}
            </div>
          </section>

          <section className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Önceki saha belgeleri</p>
            {(selectedHistoryRecord.documents?.length ?? 0) > 0 ? (
              <div className="mt-3 grid gap-3 sm:grid-cols-3">
                {selectedHistoryRecord.documents?.map((document) => {
                  const documentUrl = document.preview_url ?? document.download_url ?? document.url ?? ''

                  return (
                    <div key={String(document.id ?? document.field_code ?? document.original_name)} className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                      {document.preview_url ? (
                        <img src={document.preview_url} alt={document.label ?? document.field_code ?? 'Belge'} className="h-32 w-full object-cover" />
                      ) : (
                        <div className="flex h-32 items-center justify-center bg-slate-100 text-xs font-semibold text-slate-500">Önizleme yok</div>
                      )}
                      <div className="grid gap-2 px-3 py-2 text-xs">
                        <span className="font-semibold text-slate-800">{document.label ?? document.field_code ?? 'Belge'}</span>
                        {documentUrl ? (
                          <a href={documentUrl} target="_blank" rel="noreferrer" className="font-semibold text-blue-700 underline-offset-4 hover:underline">
                            Belgeyi aç
                          </a>
                        ) : null}
                      </div>
                    </div>
                  )
                })}
              </div>
            ) : (
              <p className="mt-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500">Bu kayda ait saha belgesi bulunmuyor.</p>
            )}
          </section>
        </div>

        <section className="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">İşlem geçmişi</p>
          {(selectedHistoryRecord.events?.length ?? 0) > 0 ? (
            <div className="mt-3 grid gap-2">
              {selectedHistoryRecord.events?.map((event) => {
                const eventTitle = event.title_label ?? event.event_type_label ?? actionLabel(event.event_type, event.title)
                const statusTransition = event.from_status_label && event.to_status_label && event.from_status_label !== event.to_status_label
                  ? `${event.from_status_label} -> ${event.to_status_label}`
                  : null

                return (
                  <div key={String(event.id)} className="grid gap-1 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="font-semibold text-slate-800">{eventTitle}</span>
                      <span className="text-xs text-slate-500">{eventTime(event.created_at)}</span>
                    </div>
                    {event.note ? <p className="text-xs text-slate-600">{event.note}</p> : null}
                    {statusTransition ? <p className="text-[11px] font-semibold text-slate-500">{statusTransition}</p> : null}
                  </div>
                )
              })}
            </div>
          ) : (
            <p className="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">İşlem geçmişi bulunmuyor.</p>
          )}
        </section>
      </div>
    </div>
  ) : null
  const approvalState = technicianApprovalState(request, events)
  const operationSteps: Array<{ title: string, status: OperationStepStatus, message: string }> = [
    ...(isServiceVisitDetail
      ? [{
          title: 'SRV servis ziyareti',
          status: 'Tamamlandı' as OperationStepStatus,
          message: 'Parent MRN kapı ve montaj kontrolleri bu child atamasını engellemez.',
        }]
      : [
          operationControl.door_photos_checked === 'compatible'
            ? { title: 'Kapı görselleri kontrolü', status: 'Tamamlandı' as OperationStepStatus, message: 'Kapı görselleri uygun işaretlendi.' }
            : { title: 'Kapı görselleri kontrolü', status: 'Engelleyici hata' as OperationStepStatus, message: 'Kapı görselleri kontrol edilmedi.' },
          mountPaymentReceived || !canonicalPaymentRequiresPayment || operationControl.payment_checked === 'yes'
            ? { title: 'Montaj ödeme kontrolü', status: 'Tamamlandı' as OperationStepStatus, message: mountPaymentReceived ? mountPaymentStageLabel : canonicalPaymentRequiresPayment ? 'Ödeme kontrolü tamamlandı.' : mountPaymentStageLabel }
            : mountExclusionAckRequired
              ? { title: 'Montaj ödeme kontrolü', status: 'Kontrol gerekli' as OperationStepStatus, message: 'Montaj hariç çoklu ürün onayı gerekiyor.' }
              : { title: 'Montaj ödeme kontrolü', status: 'Engelleyici hata' as OperationStepStatus, message: 'Montaj ödeme durumu net değil.' },
          operationControl.schedule_update_required === 'no' || Boolean(request.scheduledAt || request.scheduledDate)
            ? { title: 'Müşteri/randevu kontrolü', status: 'Tamamlandı' as OperationStepStatus, message: 'Randevu kontrolü tamamlandı.' }
            : { title: 'Müşteri/randevu kontrolü', status: 'Bekliyor' as OperationStepStatus, message: 'Randevu veya müşteri dönüşü bekliyor.' },
        ]),
    selectedTechnician
      ? { title: 'Usta seçimi', status: 'Tamamlandı', message: `${selectedTechnician.name} seçildi.` }
      : { title: 'Usta seçimi', status: 'Bekliyor', message: 'Usta seçimi bekliyor.' },
    hasRouteCostEvidence
      ? { title: 'Usta yol hakedişi hesaplandı', status: 'Tamamlandı', message: 'Usta seçildi, yol hakedişi hesaplandı.' }
      : selectedTechnician
        ? { title: 'Usta yol hakedişi gönderilmeli', status: 'İşlem gerekli', message: 'Seçili usta için yol hakedişi bekliyor.' }
        : { title: 'Usta yol hakedişi bekliyor', status: 'Bekliyor', message: 'Önce usta seçilmeli.' },
    !assignmentSubmitDisabled
      ? { title: 'Servis atama', status: 'Tamamlandı', message: 'Servis atanabilir.' }
      : { title: 'Servis atama', status: 'Bekliyor', message: combinedAssignmentBlockerMessages[0] ?? (mountExclusionAckRequired && !mountExclusionAckComplete ? 'Montaj hariç çoklu ürün onayı gerekiyor.' : 'Atama koşulları tamamlanmalı.') },
    approvalState.title.toLocaleLowerCase('tr-TR').includes('bek')
      ? { title: 'Usta onayı bekleme', status: 'Bekliyor', message: 'Usta onayı bekleniyor.' }
      : hasAssignedTechnician
        ? { title: 'Usta onayı bekleme', status: 'Tamamlandı', message: approvalState.title }
        : { title: 'Usta onayı bekleme', status: 'Bekliyor', message: 'Servis atanınca takip edilecek.' },
    request.status === 'Tamamlandı'
      ? { title: 'Tamamlama kontrolü', status: 'Tamamlandı', message: 'Tamamlama kontrolü tamamlandı.' }
      : { title: 'Tamamlama kontrolü', status: 'Bekliyor', message: 'Ustanın tamamlama gönderimi bekleniyor.' },
  ]
  const sortedEvents = [...events].sort((a, b) => parseEventTimestamp(b) - parseEventTimestamp(a))
  const hiddenOpsWorkflowActions = new Set([
    'on_the_way',
    'on_site',
    'field_travel_started',
    'field_arrived',
    'field_work_started',
  ])
  const workflowActions = Object.entries(request.allowedWorkflowActions ?? {})
    .filter(([key, action]) => {
      if (hiddenOpsWorkflowActions.has(key)) {
        return false
      }

      const normalized = `${key} ${action.label}`.toLocaleLowerCase('tr-TR')

      return !normalized.includes('usta yolda')
        && !normalized.includes('sahaya çıktı')
        && !normalized.includes('sahaya cikti')
        && !normalized.includes('sahaya vardı')
        && !normalized.includes('sahaya vardi')
    })
  const footerWorkflowActions = [...workflowActions].sort(([leftKey, leftAction], [rightKey, rightAction]) => {
    const priority = (key: string, label: string) => {
      const normalized = `${key} ${label}`.toLocaleLowerCase('tr-TR')

      if (normalized.includes('eksik') || normalized.includes('foto')) {
        return 0
      }

      if (normalized.includes('arandı') || normalized.includes('arandi') || normalized.includes('contact')) {
        return 1
      }

      if (normalized.includes('ulaşılamadı') || normalized.includes('ulasilamadi')) {
        return 2
      }

      return 3
    }

    return priority(leftKey, leftAction.label) - priority(rightKey, rightAction.label)
  })
  const finalCheckCompletionAction = completionSubmissions[0] ?? null
  const canReassignAfterReview = jobRejections.length > 0 || customerApprovalRejections.length > 0 || completionSubmissions.length > 0 || revisitRequests.length > 0
  const sameTechnicianReviewActionLabel = completionSubmissions.length > 0
    ? 'Revize için ustaya geri gönder'
    : customerApprovalRejections.length > 0
      ? 'Usta ile devam et'
      : 'Aynı ustaya tekrar gönder'
  const finalCheckActionChecklist = finalCheckCompletionAction?.payload?.checklist
  const finalCheckChecklistSource = (
    finalCheckCompletionAction?.payload?.checklist_gate === 'server_checked'
    && finalCheckActionChecklist
    && typeof finalCheckActionChecklist === 'object'
    && !Array.isArray(finalCheckActionChecklist)
    && Object.keys(finalCheckActionChecklist).length > 0
  )
    ? (finalCheckActionChecklist as Record<string, unknown>)
    : (request.checklistPayload ?? {})
  const checklistEntries = Object.entries(finalCheckChecklistSource).map(([key, completed]) => ({
    key,
    label: key
      .replace(/_/g, ' ')
      .replace(/\b\w/g, (letter) => letter.toLocaleUpperCase('tr-TR')),
    completed: Boolean(completed),
  }))
  const checklistCompletedCount = checklistEntries.filter((item) => item.completed).length
  const checklistTotalCount = checklistEntries.length
  const checklistMissingCount = Math.max(checklistTotalCount - checklistCompletedCount, 0)
  const fieldCompletionDocumentTypes = [
    { field: 'before_photo', label: 'Öncesi' },
    { field: 'after_photo', label: 'Sonrası' },
    { field: 'warranty_document_photo', label: 'Garanti Belgesi' },
  ]
  const fieldCompletionDocuments = request.fieldCompletionDocuments ?? []
  const previousFieldCompletionDocuments = request.previousFieldCompletionDocuments ?? []
  const opsExtraFieldDocuments = fieldCompletionDocuments.filter((document) => (
    !OPS_DOOR_PHOTO_FIELD_CODES.has(String(document.field_code ?? ''))
    && (
      document.category === 'ops_extra_document'
      || document.field_code === 'ops_extra_photo'
      || document.field_code === 'ops_additional_document'
    )
  ))
  const fieldCompletionDocumentStatuses = fieldCompletionDocumentTypes.map((type) => {
    const document = fieldCompletionDocuments.find((item) => item.field_code === type.field)

    return {
      ...type,
      document,
      uploaded: Boolean(document),
    }
  })
  const completedFieldDocumentCount = fieldCompletionDocumentStatuses.filter((item) => item.uploaded).length
  const missingFieldDocumentLabels = fieldCompletionDocumentStatuses
    .filter((item) => !item.uploaded)
    .map((item) => item.label)
  const photoCompletionLabel = `${completedFieldDocumentCount}/3 belge tamam`
  const reviewableFieldDocuments = fieldCompletionDocumentStatuses
    .map((item) => item.document)
    .filter((document): document is NonNullable<typeof document> => Boolean(document?.id))
  const fieldDocumentRejectedNotes = reviewableFieldDocuments
    .filter((document) => document.review_status === 'rejected' && hasText(document.review_note))
    .map((document) => String(document.review_note))
  const fieldDocumentOverallReviewStatus = reviewableFieldDocuments.length === 0
    ? 'missing'
    : reviewableFieldDocuments.every((document) => document.review_status === 'accepted')
      ? 'accepted'
      : reviewableFieldDocuments.some((document) => document.review_status === 'rejected')
        ? 'rejected'
        : 'pending'
  const fieldDocumentOverallReviewLabel = {
    accepted: 'Saha belgeleri uygun',
    rejected: 'Saha belgeleri uygun değil',
    pending: 'Uygunluk bekliyor',
    missing: 'Belge bekleniyor',
  }[fieldDocumentOverallReviewStatus]
  const isFieldDocumentOverallReviewBusy = fieldDocumentOverallReviewLoading || fieldDocumentReviewInFlight !== null
  const showFieldDocumentOverallReviewControls = reviewableFieldDocuments.length > 0
    && (fieldDocumentOverallReviewStatus === 'pending' || fieldDocumentOverallReviewEditing)
  const finalCheckActionChecklistComplete = Boolean(
    finalCheckCompletionAction?.payload?.checklist_gate === 'server_checked'
    && finalCheckActionChecklist
    && typeof finalCheckActionChecklist === 'object'
    && !Array.isArray(finalCheckActionChecklist)
    && Object.keys(finalCheckActionChecklist).length > 0
    && Object.values(finalCheckActionChecklist).every(Boolean),
  )
  const backendControlComplete = request.checklistStatus === 'tamamlandı' || (checklistTotalCount > 0 && checklistMissingCount === 0) || finalCheckActionChecklistComplete
  const finalCompletionMissingReasons = [
    ...missingFieldDocumentLabels.map((label) => `${label} eksik`),
    ...(fieldDocumentOverallReviewStatus === 'accepted' ? [] : [
      fieldDocumentOverallReviewStatus === 'rejected'
        ? 'Saha belgeleri uygun değil'
        : 'Saha belgeleri uygunluk kararı bekliyor',
    ]),
    ...(request.customerClosureApprovalStatus === 'onaylandı' ? [] : ['Müşteri onayı bekliyor']),
    ...(backendControlComplete ? [] : ['Backend kontrol eksik']),
  ]
  const finalPayoutRows = earningBreakdown?.rows ?? []
  const finalPayoutApprovalRequired = Boolean(earningBreakdown?.root_total?.payout_approval_required)
  const defaultFinalPayoutSelection = finalPayoutRows
    .filter((row) => row.payout_included !== false)
    .map((row) => String(row.id))
  const finalPayoutSelectionKey = String(request.id)
  const finalPayoutSelectedIds = finalPayoutSelectionByRequest[finalPayoutSelectionKey] ?? defaultFinalPayoutSelection
  const finalPayoutSelectedSet = new Set(finalPayoutSelectedIds)
  const finalPayoutSelectedRows = finalPayoutRows.filter((row) => finalPayoutSelectedSet.has(String(row.id)))
  const finalPayoutSelectedTotal = finalPayoutSelectedRows.reduce((total, row) => total + Number(row.total_amount ?? 0), 0)
  const toggleFinalPayoutRow = (rowId: number | string) => {
    const id = String(rowId)
    setFinalPayoutSelectionByRequest((current) => {
      const selected = current[finalPayoutSelectionKey] ?? defaultFinalPayoutSelection
      const next = selected.includes(id)
        ? selected.filter((item) => item !== id)
        : [...selected, id]

      return {
        ...current,
        [finalPayoutSelectionKey]: next,
      }
    })
  }
  const reviewFieldDocumentsOverall = async (status: 'accepted' | 'rejected') => {
    if (! onFieldDocumentReview || reviewableFieldDocuments.length === 0) {
      return
    }

    const note = fieldDocumentOverallRejectNote.trim()

    if (status === 'rejected' && note === '') {
      return
    }

    setFieldDocumentOverallReviewLoading(true)

    try {
      for (const document of reviewableFieldDocuments) {
        await onFieldDocumentReview(document.id!, {
          status,
          note: status === 'rejected' ? note : null,
        })
      }

      if (status === 'accepted') {
        setFieldDocumentOverallRejectNote('')
      }

      setFieldDocumentOverallReviewEditing(false)
    } finally {
      setFieldDocumentOverallReviewLoading(false)
    }
  }
  const handleOpsExtraDocumentUpload = async () => {
    if (!onOpsExtraDocumentUpload) {
      return
    }

    if (opsExtraFiles.length === 0) {
      setOpsExtraMessage('Yüklenecek ek görsel seçilmedi.')

      return
    }

    setOpsExtraUploading(true)
    setOpsExtraMessage(null)

    try {
      await onOpsExtraDocumentUpload({
        files: opsExtraFiles,
        note: opsExtraNote.trim() || null,
        document_type: opsExtraDocumentType,
      })
      setOpsExtraFiles([])
      setOpsExtraNote('')
      setOpsExtraMessage('OPS ek görsel yüklendi.')
    } catch (caught) {
      setOpsExtraMessage(caught instanceof Error ? caught.message : 'OPS ek görsel yüklenemedi.')
    } finally {
      setOpsExtraUploading(false)
    }
  }

  const handleOpsDoorPhotoUpload = async () => {
    if (!onOpsExtraDocumentUpload) {
      return
    }

    if (opsDoorPhotoFiles.length === 0) {
      setOpsDoorPhotoMessage('Yüklenecek kapı görseli seçilmedi.')

      return
    }

    setOpsDoorPhotoUploading(true)
    setOpsDoorPhotoMessage(null)

    try {
      await onOpsExtraDocumentUpload({
        files: opsDoorPhotoFiles,
        note: opsDoorPhotoNote.trim() || null,
        document_type: opsDoorPhotoType,
      })
      setOpsDoorPhotoFiles([])
      setOpsDoorPhotoNote('')
      setOpsDoorPhotoMessage('Kapı görseli yüklendi.')
    } catch (caught) {
      setOpsDoorPhotoMessage(caught instanceof Error ? caught.message : 'Kapı görseli yüklenemedi.')
    } finally {
      setOpsDoorPhotoUploading(false)
    }
  }

  const scheduledDateLabel = request.scheduledDate
    ? formatTechnicalServiceDate(request.scheduledDate)
    : dateTimeOrEmpty(request.scheduledAt, 'Randevu bekliyor')
  const scheduledTimeLabel = displayOrEmpty(request.scheduledTime || request.appointment, 'Saat planlanmadı')
  const closureApprovalLabel = displayOrEmpty(request.customerClosureApprovalStatus, 'Kapanış onayı yok')
  const canonicalActionLabel = request.operationalState?.action_label
    ?? request.operationalState?.display_action_label
    ?? request.displayActionLabel
    ?? request.nextAction
  const nextActionLabel = displayOrEmpty(canonicalActionLabel, 'Sıradaki aksiyon tanımlı değil')
  const nextActionPayload = request.nextActionPayload
  const nextActionTitle = displayOrEmpty(canonicalActionLabel ?? nextActionPayload?.title, nextActionLabel)
  const nextActionDescription = displayOrEmpty(
    request.operationalState?.action_hint ?? nextActionPayload?.description,
    'Operasyon akışı için sıradaki adım bekleniyor.',
  )
  const isAssignedPartnerActionStage = isAssignedTechnicianStage(opsSectionContext)
  const hasScheduledAppointment = Boolean(request.scheduledAt || request.scheduledDate)
  const assignedStageNeedsAppointment = isAssignedPartnerActionStage && !hasScheduledAppointment
  const displayedNextActionTitle = assignedStageNeedsAppointment
    ? 'Usta randevu önerecek'
    : isAssignedPartnerActionStage ? 'İş ustada' : nextActionTitle
  const displayedNextActionDescription = assignedStageNeedsAppointment
    ? 'Usta müşteriye uygun randevu zamanı önerecek.'
    : isAssignedPartnerActionStage
    ? 'Usta fotoğrafları ve müşteri onayını tamamlayacak.'
    : nextActionDescription
  const displayedNextActionSeverity = isAssignedPartnerActionStage ? 'neutral' : nextActionPayload?.severity
  const displayedNextActionHeader = isAssignedPartnerActionStage ? 'Süreç Bilgisi' : 'Sıradaki Operasyon Aksiyonu'
  const showNextActionPrimaryButton = Boolean(nextActionPayload?.primary_action && !isAssignedPartnerActionStage)
  const cancelContext = request.cancelContext?.exists ? request.cancelContext : null
  const isCancelledOrReviewContext = Boolean(cancelContext?.is_cancelled || cancelContext?.is_cancel_review)
  const paymentActionWorkflowText = [
    request.workflowStatus,
    request.status,
    request.currentStageSummary?.label,
    displayedNextActionTitle,
  ].filter(Boolean).join(' ').toLocaleLowerCase('tr-TR')
  const paymentActionRelevantByWorkflow = [
    'iş ustada',
    'beklemede',
    'usta onayı',
    'usta onayi',
    'usta onayı bekleyen',
  ].some((label) => paymentActionWorkflowText.includes(label))
  const hasPaymentManagementContext = Boolean(
    pendingOnlinePaymentLink
    || paidOnlinePaymentLink
    || cancelledOnlinePaymentLink
    || shouldShowCustomerPaysTechnicianCard
    || canonicalPaymentRequiresPayment
    || mountExcludedOrPaymentRequired
    || nextActionPayload?.primary_action === 'create_payment_link'
    || paymentActionRelevantByWorkflow
  )
  const shouldShowFooterPaymentLinkAction = Boolean(
    !isActionDisabled
    && !isCancelledOrReviewContext
    && hasPaymentManagementContext
  )
  const cancelSummaryRows = cancelContext
    ? [
      { label: 'İptal edilen iş', value: cancelContext.cancelled_code ?? cancelContext.previous_cancelled_code ?? request.mrn },
      { label: 'Müşteri', value: cancelContext.customer_name ?? request.customer },
      { label: 'Seri / aktivasyon', value: [cancelContext.serial_no, cancelContext.activation_code].filter(Boolean).join(' / ') },
      { label: 'Son usta', value: [cancelContext.last_technician_name, cancelContext.last_technician_phone].filter(Boolean).join(' / ') },
      { label: 'Son randevu', value: cancelContext.last_appointment_at ? dateTimeOrEmpty(cancelContext.last_appointment_at, '-') : null },
      { label: 'İptal nedeni', value: cancelContext.cancel_reason ?? cancelContext.previous_cancel_reason ?? null },
      { label: 'İptal zamanı', value: cancelContext.cancelled_at ? dateTimeOrEmpty(cancelContext.cancelled_at, '-') : null },
      { label: 'Hakediş durumu', value: cancelContext.earning_excluded_label ?? (cancelContext.earning_excluded ? 'İptal nedeniyle hakedişe dahil değil' : null) },
      { label: 'Şu anki aşama', value: cancelContext.current_stage_label ?? request.currentStageSummary?.label ?? null },
    ].filter((row) => displayOrEmpty(row.value, '') !== '')
    : []
  const scrollToNextActionSection = (target: NextActionSectionTarget) => {
    const targetRef = {
      operation: operationInfoRef,
      assignment: assignmentInfoRef,
      fieldCompletion: fieldCompletionRef,
      finalCheck: finalCheckRef,
    }[target]

    if (target === 'operation') {
      setOperationInfoOpen(true)
    }

    if (target === 'assignment') {
      setAssignmentInfoOpen(true)
    }

    if (target === 'fieldCompletion') {
      setFieldCompletionOpen(true)
    }

    if (target === 'finalCheck') {
      setFinalCheckOpen(true)
    }

    setNextActionNavigationMessage(null)
    setHighlightedNextActionTarget(target)

    window.setTimeout(() => {
      const targetElement = targetRef.current

      if (!targetElement) {
        setNextActionNavigationMessage('Bu aksiyon için gidilecek bölüm bulunamadı.')
        setHighlightedNextActionTarget(null)

        return
      }

      targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' })

      window.setTimeout(() => {
        setHighlightedNextActionTarget((current) => current === target ? null : current)
      }, 1800)
    }, 80)
  }

  function copyTextWithTextarea(text: string): boolean {
    try {
      const textarea = document.createElement('textarea')
      textarea.value = text
      textarea.setAttribute('readonly', 'true')
      textarea.style.position = 'fixed'
      textarea.style.left = '0'
      textarea.style.top = '0'
      textarea.style.width = '1px'
      textarea.style.height = '1px'
      textarea.style.padding = '0'
      textarea.style.border = '0'
      textarea.style.opacity = '0'
      document.body.appendChild(textarea)
      textarea.focus()
      textarea.select()
      textarea.setSelectionRange(0, textarea.value.length)
      const copied = document.execCommand('copy')
      document.body.removeChild(textarea)

      return copied
    } catch {
      return false
    }
  }

  async function clipboardMatchesText(text: string): Promise<boolean | null> {
    try {
      if (!navigator.clipboard?.readText) {
        return null
      }

      return (await navigator.clipboard.readText()) === text
    } catch {
      return null
    }
  }

  async function verifiedCopyResult(copied: boolean, text: string): Promise<boolean> {
    if (!copied) {
      return false
    }

    const verified = await clipboardMatchesText(text)

    return verified ?? true
  }

  async function writeTextToClipboard(text: string): Promise<boolean> {
    if (window.location.protocol !== 'https:') {
      return verifiedCopyResult(copyTextWithTextarea(text), text)
    }

    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text)

        return verifiedCopyResult(true, text)
      }
    } catch {
      // Fall back below for denied clipboard permissions or unsupported browsers.
    }

    return verifiedCopyResult(copyTextWithTextarea(text), text)
  }

  async function copyPaymentLinkValue(value: string | null | undefined, successMessage = 'Link kopyalandı.') {
    const text = String(value ?? '').trim()

    if (text === '') {
      setPaymentLinkCopyMessage('Kopyalanacak link yok.')
      setPaymentLinkManualCopyValue(null)

      return false
    }

    const copied = await writeTextToClipboard(text)
    setPaymentLinkCopyMessage(copied ? successMessage : 'Link kopyalanamadı. Bağlantıyı aşağıdaki alandan manuel kopyalayın.')
    setPaymentLinkManualCopyValue(copied ? null : text)

    return copied
  }

  function paymentLinkCopyUrl(payment: PaymentLinkSendTarget | null | undefined): string {
    return String(payment?.copy_url ?? payment?.payment_url ?? '').trim()
  }

  function paymentProviderLabel(payment: ServiceRequestExtraMountPayment | null | undefined): string {
    return String(payment?.provider_display_label ?? payment?.provider_label ?? (payment?.is_fake_provider ? 'Fake/Yerel ödeme simülasyonu' : 'Ödeme sağlayıcısı')).trim()
  }

  function paymentProviderReferenceValue(value: string | null | undefined): string {
    const text = String(value ?? '').trim()

    return text !== '' ? text : 'Sağlayıcı tarafından dönmedi'
  }

  function paymentProviderReferenceRows(payment: ServiceRequestExtraMountPayment | null | undefined): Array<{ label: string, value: string }> {
    const syncRows = [
      payment?.provider_last_synced_at
        ? { label: 'Son kontrol', value: dateTimeOrEmpty(payment.provider_last_synced_at, '-') }
        : null,
      payment?.provider_last_sync_status
        ? { label: 'Kontrol sonucu', value: String(payment.provider_last_sync_status) }
        : null,
      typeof payment?.provider_sync_attempts === 'number' && payment.provider_sync_attempts > 0
        ? { label: 'Kontrol denemesi', value: String(payment.provider_sync_attempts) }
        : null,
      payment?.provider_last_sync_error
        ? { label: 'Son kontrol hatası', value: String(payment.provider_last_sync_error) }
        : null,
      payment?.provider_paid_confirmed_at
        ? { label: 'Provider paid teyidi', value: dateTimeOrEmpty(payment.provider_paid_confirmed_at, '-') }
        : null,
    ].filter(Boolean) as Array<{ label: string, value: string }>

    return [
      {
        label: 'Link token',
        value: String(payment?.provider_reference ?? payment?.provider_token ?? '').trim() || '-',
      },
      {
        label: 'Provider ödeme referansı',
        value: paymentProviderReferenceValue(payment?.provider_payment_reference),
      },
      {
        label: 'Provider işlem referansı',
        value: paymentProviderReferenceValue(payment?.provider_transaction_reference),
      },
      {
        label: 'Dekont referansı',
        value: paymentProviderReferenceValue(payment?.provider_receipt_reference),
      },
      ...syncRows,
    ]
  }

  function renderPaymentProviderReferences(payment: ServiceRequestExtraMountPayment | null | undefined) {
    return (
      <div className="grid gap-1 rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] leading-relaxed text-slate-700">
        <p className="font-semibold text-slate-900">Provider bilgisi</p>
        {paymentProviderReferenceRows(payment).map((row) => (
          <div key={row.label} className="grid gap-0.5 sm:grid-cols-[150px_minmax(0,1fr)]">
            <span className="font-medium text-slate-500">{row.label}</span>
            <span className="break-all text-slate-800">{row.value}</span>
          </div>
        ))}
        {payment?.provider_sync_message ? (
          <p className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 font-medium text-amber-900">
            {payment.provider_sync_message}
          </p>
        ) : null}
      </div>
    )
  }

  const copyCustomerApprovalValue = async (value: string | null | undefined, successMessage: string) => {
    const text = String(value ?? '').trim()

    if (text === '') {
      setCustomerApprovalCopyMessage('Kopyalanacak onay bilgisi yok.')

      return
    }

    const copied = await writeTextToClipboard(text)
    setCustomerApprovalCopyMessage(copied ? successMessage : 'Kopyalama başarısız. Aşağıdaki alanı elle seçip kopyalayın.')
  }

  async function copyCustomerChargeValue(value: string | null | undefined, successMessage: string) {
    const text = String(value ?? '').trim()

    if (text === '') {
      setCustomerChargeCopyMessage('Kopyalanacak ödeme bilgisi yok.')

      return
    }

    const copied = await writeTextToClipboard(text)
    setCustomerChargeCopyMessage(copied ? successMessage : 'Kopyalama başarısız. Aşağıdaki alanı elle seçip kopyalayın.')
  }

  const handleNextActionClick = () => {
    const action = nextActionPayload?.primary_action

    if (!action) {
      setNextActionNavigationMessage('Bu aksiyon için gidilecek bölüm bulunamadı.')

      return
    }

    if (action === 'assign_technician' || action === 'select_technician') {
      scrollToNextActionSection('assignment')
      void onAssignSelectedTechnician?.()

      return
    }

    if (action === 'copy_payment_link') {
      const paymentUrl = extraMountPayment?.copy_url ?? extraMountPayment?.payment_url ?? ''

      scrollToNextActionSection('operation')

      void (async () => {
        if (!paymentUrl) {
          setNextActionNavigationMessage('Kopyalanacak ödeme linki bulunamadı.')

          return
        }

        const copied = await writeTextToClipboard(paymentUrl)
        setNextActionNavigationMessage(copied ? 'Link kopyalandı.' : 'Link kopyalanamadı, bağlantıyı manuel kopyalayın.')
      })()

      return
    }

    if (action === 'create_payment_link') {
      void handleCreatePaymentLinkAction()

      return
    }

    if (action === 'calculate_route_fee') {
      scrollToNextActionSection('assignment')
      void onRouteQuoteCalculate?.()

      return
    }

    if (action === 'review_photos') {
      scrollToNextActionSection('operation')

      return
    }

    if (action === 'acknowledge_mount_exclusion') {
      scrollToNextActionSection('assignment')

      return
    }

    if (action === 'plan_appointment') {
      scrollToNextActionSection('operation')
      onSchedule?.()

      return
    }

    if (action.includes('customer_approval')) {
      scrollToNextActionSection('fieldCompletion')

      return
    }

    if (action.includes('final') || action.includes('completion') || action.includes('field_document')) {
      scrollToNextActionSection('fieldCompletion')

      return
    }

    setNextActionNavigationMessage('Bu aksiyon için gidilecek bölüm bulunamadı.')
  }
  const compactControlChips = [
    {
      label: 'Görseller',
      value: operationControl.door_photos_checked === 'compatible' ? 'Tamam' : 'Kontrol gerekli',
      tone: operationControl.door_photos_checked === 'compatible' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800',
    },
    {
      label: 'Ödeme',
      value: mountPaymentReceived ? 'Alındı' : canonicalPaymentRequiresPayment || mountExclusionAckRequired ? 'Bekleniyor' : 'Gerekmez',
      tone: mountPaymentReceived ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800',
    },
    {
      label: 'Usta',
      value: hasAssignedTechnician ? (approvalState.title.toLocaleLowerCase('tr-TR').includes('bek') ? 'Onay bekliyor' : 'Seçildi') : 'Seçilmedi',
      tone: hasAssignedTechnician ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-slate-200 bg-white text-slate-700',
    },
    {
      label: 'Randevu',
      value: request.scheduledAt || request.scheduledDate ? 'Planlandı' : 'Bekliyor',
      tone: request.scheduledAt || request.scheduledDate ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white text-slate-700',
    },
  ]
  const visibleCompactControlChips = compactControlChips.filter((chip) => {
    if (assignedStageNeedsAppointment && !['Usta', 'Randevu'].includes(chip.label)) {
      return false
    }

    if (isServiceVisitDetail && chip.label === 'Görseller') {
      return false
    }

    return true
  })
  const notesLabel = displayOrEmpty(request.notes, 'Talep notu girilmedi')
  const currentStatusLabel = statusDisplayLabel(request)
  const currentPriorityLabel = priorityDisplayLabel(request.priority)
  const currentPriorityInOptions = PRIORITY_OPTIONS.some((option) => option.value === request.priority)
  const currentSlaLabel = slaStatusLabel(request.slaStatus)
  const slaDueLabel = dateTimeOrEmpty(request.slaDueAt, 'SLA hedefi yok')
  const slaTitle = `${slaStatusDescription(request.slaStatus)}. SLA hedefi: ${slaDueLabel}`
  const showSlaStatusChip = hasText(request.slaDueAt) || ['geciken', 'yaklaşan'].includes(String(request.slaStatus ?? ''))
  const handleWorkflowAction = (action: string) => {
    if (action === 'assign_technician' || action === 'select_technician') {
      if (isAssignmentBlocked) {
        return
      }

      onAssign?.()

      return
    }

    if (action === 'schedule_planned') {
      onSchedule?.()

      return
    }

    if (action === 'complete' || action === 'cancel') {
      onComplete?.()

      return
    }

    onWorkflowAction?.(action)
  }

  return (
    <Card className="w-full max-w-none min-w-0 border-0 bg-transparent shadow-none break-words">
      <CardContent className="flex flex-col gap-4 p-0 pb-24 sm:pb-20">
        {error ? (
          <section className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p className="font-semibold">Bazı detay blokları yüklenemedi.</p>
            <p className="mt-1">{error}</p>
          </section>
        ) : null}

        <section className="order-10 rounded-3xl border border-slate-200 bg-gradient-to-br from-white via-slate-50 to-blue-50 p-4 text-slate-950 shadow-sm lg:p-5">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Talep Referansı</p>
              <div className="mt-1 flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  onClick={() => void navigator.clipboard?.writeText(displayMrn ?? request.mrn)}
                  className="text-left text-xl font-bold text-slate-950 underline-offset-4 hover:underline"
                  title="MRN kopyala"
                >
                  {displayMrn ?? request.mrn}
                </button>
                <Badge variant="outline">Kopyalanabilir</Badge>
              </div>
              <button
                type="button"
                onClick={() => setSerialQueryOpen(true)}
                className="mt-1 text-left text-xs font-semibold text-blue-700 underline-offset-4 hover:underline"
              >
                Seri No Sorgu: {displayOrEmpty(request.serialNumber, '-')}
              </button>
            </div>
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Müşteri</p>
              <p className="mt-1 truncate text-lg font-semibold">{request.customer}</p>
              {whatsappHref ? (
                <a className="mt-1 block text-xs font-semibold text-blue-700 underline-offset-4 hover:underline" href={whatsappHref} target="_blank" rel="noreferrer">
                  {displayOrEmpty(request.phone, 'Bilgi yok')}
                </a>
              ) : (
                <p className="mt-1 text-xs text-slate-500">{displayOrEmpty(request.phone, 'Bilgi yok')}</p>
              )}
            </div>
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Randevu</p>
              <p className="mt-1 text-lg font-semibold">{scheduledDateLabel}</p>
              <p className="mt-1 text-xs text-slate-300">{scheduledTimeLabel}</p>
            </div>
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Atanan Usta</p>
              <p className="mt-1 truncate text-lg font-semibold">{hasAssignedTechnician ? displayOrEmpty(request.technician, 'Bilgi yok') : 'Atanmadı'}</p>
              <p className="mt-1 text-xs font-semibold text-blue-700">{hasAssignedTechnician ? displayOrEmpty(request.technicianPhone, 'Telefon yok') : 'Usta atanmadı'}</p>
            </div>
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Sıradaki Aksiyon</p>
              <p className="mt-1 line-clamp-2 text-sm font-semibold">{nextActionLabel}</p>
              {showSlaStatusChip ? <p className="mt-1 text-xs text-slate-300">SLA: {currentSlaLabel} / {slaDueLabel}</p> : null}
            </div>
          </div>
          <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-200/80 pt-3">
            <Badge variant={statusVariant(request.status)}>Durum: {currentStatusLabel}</Badge>
            {onPriorityChange ? (
              <label
                className="inline-flex h-7 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700"
                title="Öncelik düzenlenebilir"
              >
                <span>Öncelik:</span>
                <select
                  className="cursor-pointer bg-transparent text-xs font-semibold text-slate-900 outline-none disabled:cursor-wait disabled:opacity-60"
                  value={request.priority}
                  onChange={(event) => {
                    void onPriorityChange(event.target.value as ServicePriority)
                  }}
                  disabled={priorityUpdateInFlight}
                  aria-label="Talep önceliğini değiştir"
                >
                  {currentPriorityInOptions ? null : (
                    <option value={request.priority}>{currentPriorityLabel}</option>
                  )}
                  {PRIORITY_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>
            ) : (
              <Badge variant="outline">Öncelik: {currentPriorityLabel}</Badge>
            )}
            {showSlaStatusChip ? (
              <span
                className={['inline-flex h-7 items-center rounded-full border px-2.5 py-1 text-xs font-semibold', slaTone(request.slaStatus)].join(' ')}
                title={slaTitle}
                aria-label={`SLA: ${currentSlaLabel}. ${slaTitle}`}
              >
                SLA: {currentSlaLabel}
              </span>
            ) : null}
            {(request.qrSource?.source_channel ?? request.channel) === 'qr_mount_form' ? <Badge variant="outline">QR Montaj Formu</Badge> : null}
            {hasMultiProductRequest ? <Badge variant="warning">Çoklu ürün talebi</Badge> : null}
            {routeFeeNeedsApproval ? <Badge variant="warning">Usta yol hakedişi gönderilmeli</Badge> : null}
          </div>
          {shouldRenderHeaderPaymentSummary ? (
            <div className="mt-3 grid gap-2 rounded-2xl border border-slate-200/80 bg-white/70 p-3 text-sm sm:grid-cols-3">
              <div>
                <p className="text-[13px] font-medium text-slate-500">Montaj ödeme durumu</p>
                <p className="mt-1 font-semibold text-slate-950">{mountPaymentHeaderLabel}</p>
              </div>
              {mountPaymentAmountLabel !== '-' ? (
              <div>
                <p className="text-[13px] font-medium text-slate-500">Ödeme tutarı</p>
                <p className="mt-1 font-semibold text-slate-950">{mountPaymentAmountLabel}</p>
              </div>
              ) : null}
              {hasMultiProductRequest ? (
              <div>
                <p className="text-[13px] font-medium text-slate-500">Çoklu ürün</p>
                <p className="mt-1 font-semibold text-slate-950">
                  {mountPaymentReceived ? 'Çoklu ürün ödemesi takipte' : canonicalPaymentRequiresPayment ? 'Ödeme operasyon tarafından netleştirilecek' : mountPaymentStageLabel}
                </p>
              </div>
              ) : null}
            </div>
          ) : null}
          {priorityUpdateError ? (
            <p className="mt-2 text-xs font-medium text-rose-700">{priorityUpdateError}</p>
          ) : null}
        </section>

        {cancelContext ? (
          <section className="order-[15] rounded-3xl border border-rose-200 bg-rose-50 p-4 text-rose-950 shadow-sm lg:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-rose-700">İptal Özeti</p>
                <h3 className="mt-1 text-lg font-bold text-slate-950">
                  {cancelContext.is_cancel_review ? 'İptal talebi incelemede' : cancelContext.is_cancelled ? 'İş iptal edildi' : 'Önceki iş iptal edildi'}
                </h3>
                <p className="mt-1 max-w-3xl text-sm leading-6 text-rose-900">
                  {cancelContext.summary ?? cancelContext.next_ops_message ?? 'İptal bağlamı operasyon için saklanıyor.'}
                </p>
              </div>
              <Badge variant={cancelContext.is_cancel_review ? 'warning' : 'destructive'}>
                {cancelContext.current_stage_label ?? 'İptal edildi'}
              </Badge>
            </div>
            {cancelSummaryRows.length > 0 ? (
              <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {cancelSummaryRows.map((row) => (
                  <MiniMetric key={row.label} label={row.label} value={displayOrEmpty(row.value, '-')} />
                ))}
              </div>
            ) : null}
            {cancelContext.next_ops_message ? (
              <p className="mt-4 rounded-2xl border border-rose-100 bg-white/70 px-3 py-2 text-sm font-medium text-rose-900">
                {cancelContext.next_ops_message}
              </p>
            ) : null}
          </section>
        ) : null}

        {serviceVisitHistory ? (
          <details className="order-[85] rounded-3xl border border-violet-100 bg-violet-50 p-4 text-sm text-violet-950 shadow-sm lg:p-5">
            <summary className="flex cursor-pointer list-none flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-violet-700">MRN / SRV geçmiş kayıtları</p>
                <h3 className="mt-1 text-lg font-bold text-slate-950">
                  {serviceVisitHistory.service_code ? `Servis ziyareti: ${serviceVisitHistory.service_code}` : 'Ana talep servis geçmişi'}
                </h3>
                <p className="mt-1 text-sm text-violet-800">
                  Ana talep: {serviceVisitHistory.root_request?.mrn ?? serviceVisitHistory.root_mrn ?? serviceVisitHistory.parent_request?.mrn ?? '-'}
                  {serviceVisitHistory.reason_label ? ` · ${serviceVisitHistory.reason_label}` : ''}
                </p>
              </div>
              {serviceVisitHistory.direct_parent_request ?? serviceVisitHistory.parent_request ? (
                <Badge variant="outline">
                  Parent: {(serviceVisitHistory.direct_parent_request ?? serviceVisitHistory.parent_request)?.mrn}
                </Badge>
              ) : null}
            </summary>
            <div className="mt-4 grid gap-3 lg:grid-cols-3">
              {((serviceVisitHistory.root_request ?? serviceVisitHistory.parent_request)?.workflow_status || (serviceVisitHistory.root_request ?? serviceVisitHistory.parent_request)?.status) ? (
                <MiniMetric
                  label="Ana iş durumu"
                  value={displayOrEmpty((serviceVisitHistory.root_request ?? serviceVisitHistory.parent_request)?.workflow_status ?? (serviceVisitHistory.root_request ?? serviceVisitHistory.parent_request)?.status, '-')}
                  hint={(serviceVisitHistory.root_request ?? serviceVisitHistory.parent_request)?.completed_at ? `Tamamlandı: ${dateTimeOrEmpty((serviceVisitHistory.root_request ?? serviceVisitHistory.parent_request)?.completed_at, '-')}` : undefined}
                />
              ) : null}
              <MiniMetric label="SRV kodu" value={displayOrEmpty(serviceVisitHistory.service_code, 'Ana talep')} />
              <MiniMetric label="SRV nedeni" value={displayOrEmpty(serviceVisitHistory.reason_label, 'Ek servis ziyareti')} />
            </div>
            {(serviceVisitHistory.parent_part_requests?.length ?? 0) > 0 ? (
              <div className="grid gap-2 rounded-2xl border border-violet-100 bg-white p-3">
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-violet-700">Parent parça geçmişi</p>
                {serviceVisitHistory.parent_part_requests?.slice(0, 4).map((partRequest) => (
                  <div key={String(partRequest.id)} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                    <span className="font-semibold text-slate-800">{partRequest.part_name} {partRequest.quantity > 1 ? `x${partRequest.quantity}` : ''}</span>
                    <span className="text-xs font-semibold text-violet-800">{partRequest.status_label}</span>
                  </div>
                ))}
              </div>
            ) : null}
            {serviceVisitHistoryRecords.length > 0 ? (
              <div className="grid gap-2 rounded-2xl border border-violet-100 bg-white p-3">
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-violet-700">MRN / SRV geçmiş kayıtları</p>
                {serviceVisitHistoryRecords.map((record) => (
                  <div key={String(record.id)} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <p className="truncate font-semibold text-slate-800">{record.code || record.service_code || record.mrn}</p>
                        {record.is_current ? <Badge variant="outline">Bu kayıt</Badge> : null}
                      </div>
                      <p className="mt-0.5 text-xs text-slate-600">
                        {[record.label, record.reason ?? record.service_visit_reason_label, record.status_label ?? record.workflow_status ?? record.status, record.completed_at ? `Tamamlandı: ${dateTimeOrEmpty(record.completed_at, '-')}` : null].filter(Boolean).join(' · ')}
                      </p>
                    </div>
                    <Button type="button" size="sm" variant="outline" onClick={() => setHistoryRecordId(record.id)}>
                      Detayı aç
                    </Button>
                  </div>
                ))}
              </div>
            ) : null}
            {(serviceVisitHistory.parent_events?.length ?? 0) > 0 ? (
              <details className="rounded-2xl border border-violet-100 bg-white p-3">
                <summary className="cursor-pointer text-xs font-semibold uppercase tracking-[0.12em] text-violet-700">Parent işlem geçmişi</summary>
                <div className="mt-3 grid gap-2">
                  {serviceVisitHistory.parent_events?.slice(0, 6).map((event) => (
                    <div key={String(event.id)} className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                      <p className="font-semibold text-slate-800">{event.title_label ?? event.event_type_label ?? actionLabel(event.event_type, event.title)}</p>
                      <p className="text-xs text-slate-500">{eventTime(event.created_at)}</p>
                    </div>
                  ))}
                </div>
              </details>
            ) : null}
          </details>
        ) : null}

        {!isCancelledOrReviewContext ? (
        <section className={['order-20 rounded-3xl border p-4 shadow-sm lg:p-5', nextActionTone(displayedNextActionSeverity)].join(' ')}>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-xs font-semibold uppercase tracking-[0.12em] opacity-70">{displayedNextActionHeader}</p>
              <h3 className="mt-1 text-lg font-bold">{displayedNextActionTitle}</h3>
              <p className="mt-1 max-w-3xl text-sm leading-6 opacity-90">{displayedNextActionDescription}</p>
            </div>
            {showNextActionPrimaryButton ? (
              <Button
                type="button"
                size="sm"
                variant={nextActionPayload.blocking ? 'default' : 'outline'}
                onClick={handleNextActionClick}
              >
                {nextActionPayload.primary_action === 'assign_technician'
                  ? hasAssignedTechnician ? 'Atamayı Güncelle' : 'Servis Ata'
                  : nextActionPayload.primary_action === 'select_technician'
                    ? 'Usta Seç'
                    : nextActionPayload.primary_action === 'review_photos'
                      ? 'Kontrole Git'
                      : nextActionPayload.primary_action === 'create_payment_link'
                        ? paymentLinkActionLabel
                        : nextActionPayload.primary_action === 'copy_payment_link'
                          ? 'Linki Kopyala'
                          : nextActionPayload.primary_action === 'plan_appointment'
                            ? 'Randevu Planla'
                            : 'Aksiyonu Aç'}
              </Button>
            ) : null}
          </div>
          {nextActionNavigationMessage ? (
            <p className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
              {nextActionNavigationMessage}
            </p>
          ) : null}
          <div className="mt-4 flex flex-wrap gap-2">
            {visibleCompactControlChips.map((chip) => (
              <span key={chip.label} className={['inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold', chip.tone].join(' ')}>
                <span className="opacity-70">{chip.label}:</span>
                <span>{chip.value}</span>
              </span>
            ))}
          </div>
          <details className="mt-3 rounded-2xl border border-white/60 bg-white/50 p-3 text-sm">
            <summary className="cursor-pointer font-semibold">Teknik detaylar</summary>
            <div className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
              {operationSteps.map((step, index) => (
                <div key={step.title} className={['rounded-2xl border p-3 text-sm', operationStepTone(step.status)].join(' ')}>
                  <div className="flex items-start justify-between gap-2">
                    <p className="font-semibold">{index + 1}. {step.title}</p>
                    <span className="shrink-0 rounded-full bg-white/70 px-2 py-0.5 text-[11px] font-semibold">{step.status}</span>
                  </div>
                  <p className="mt-2 leading-5">{step.message}</p>
                </div>
              ))}
            </div>
          </details>
        </section>
        ) : null}

        {shouldRenderWarrantySection ? (
          <section className="order-23 grid gap-3 rounded-3xl border border-emerald-100 bg-emerald-50/80 p-4 text-sm text-emerald-950 shadow-sm lg:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-emerald-700">Seri garanti durumu</p>
                <h3 className="mt-1 text-base font-bold text-slate-950">
                  {warrantyLoading ? 'Garanti bilgisi okunuyor' : warranty?.status ?? 'Garanti bilgisi okunamadı'}
                </h3>
                {warrantyError ? <p className="mt-1 text-sm text-rose-700">{warrantyError}</p> : null}
              </div>
              {warrantyIsActive ? <Badge variant="secondary">Garanti devam ediyor</Badge> : warrantyIsExpired ? <Badge variant="destructive">Garanti bitti</Badge> : null}
            </div>
            {warranty ? (
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <MiniMetric label="Başlangıç" value={dateOrDateTimeOrEmpty(warranty.warranty_started_at_datetime ?? warranty.installation.completed_at_datetime ?? warranty.warranty_started_at, '-')} />
                <MiniMetric label="Bitiş" value={dateOrDateTimeOrEmpty(warranty.warranty_ends_at, '-')} />
                <MiniMetric label="Kalan süre" value={warranty.remaining_days === null || warranty.remaining_days === undefined ? '-' : `${warranty.remaining_days} gün`} />
                <MiniMetric label="Garanti süresi" value={`${warranty.warranty_period_months} ay`} />
              </div>
            ) : null}
          </section>
        ) : null}

        {canShowServicePartPaymentAction ? (
        <section data-testid="service-part-payment-action" className="order-24 grid gap-3 rounded-3xl border border-blue-100 bg-blue-50/80 p-4 text-sm text-blue-950 shadow-sm lg:p-5">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-blue-700">Servis/parça ödeme linki</p>
              <h3 className="mt-1 text-base font-bold text-slate-950">Garanti dışı servis/parça tahsilatı</h3>
              <p className="mt-1 max-w-3xl text-sm leading-6 text-blue-900">
                Servis veya parça bedeli için müşteriye ayrı ödeme linki oluşturun. Tutar server tarafında servis + parça olarak yeniden hesaplanır.
              </p>
            </div>
            <Button
              type="button"
              size="sm"
              variant="outline"
              aria-label="Servis/parça ödeme linki oluştur"
              onClick={openCustomerChargeModal}
              className="border-blue-200 bg-white text-blue-800 hover:bg-blue-100"
            >
              Servis/parça ödeme linki oluştur
            </Button>
          </div>
          {latestCustomerCharge ? (
            <div className="grid gap-2 rounded-2xl border border-blue-100 bg-white/80 p-3 text-xs text-blue-900">
              <p className="font-semibold">Son link: {latestCustomerCharge.status_label ?? latestCustomerCharge.status ?? '-'}</p>
              <p>Servis: {latestCustomerCharge.service_amount_label ?? formatMoneyValue(latestCustomerCharge.service_amount ?? 0)} · Parça: {latestCustomerCharge.part_amount_label ?? formatMoneyValue(latestCustomerCharge.part_amount ?? 0)} · Toplam: {latestCustomerCharge.amount_label ?? formatMoneyValue(latestCustomerCharge.amount ?? 0)}</p>
              {latestCustomerCharge.payment_url ? (
                <div className="flex flex-wrap gap-2">
                  <Button asChild size="sm" variant="outline">
                    <a href={latestCustomerCharge.payment_url} target="_blank" rel="noreferrer">Ödeme linkini aç</a>
                  </Button>
                  <Button type="button" size="sm" variant="outline" onClick={() => void copyCustomerChargeValue(latestCustomerCharge.payment_url, 'Link kopyalandı.')}>Linki kopyala</Button>
                  {renderPaymentLinkSendAction(latestCustomerCharge)}
                </div>
              ) : null}
            </div>
          ) : null}
        </section>
        ) : null}
        {customerChargeModal}
        {otherTechniciansModal}
        {paymentLinkEditorModal}
        {partCreateModal}
        {partDecisionModal}
        {historyRecordModal}

        {serialQueryOpen ? (
          <section className="order-25 grid gap-3 rounded-3xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-950 lg:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-blue-700">Seri No Sorgu</p>
                <p className="mt-1 text-base font-semibold text-slate-950">{displayOrEmpty(productInfo?.serial_number ?? request.serialNumber, '-')}</p>
                <p className="mt-1 text-xs text-blue-800">Sorgu bu talebin seri numarasıyla otomatik çalışır; operasyon modalından çıkmadan kontrol edilir.</p>
              </div>
              <Button type="button" variant="outline" onClick={() => setSerialQueryOpen(false)} className="border-blue-200 bg-white text-blue-800 hover:bg-blue-100">
                Kapat
              </Button>
            </div>
            {mikroMountLoading ? (
              <div className="rounded-2xl border border-blue-200 bg-white p-3 font-semibold text-blue-800">
                Seri bilgisi kontrol ediliyor...
              </div>
            ) : mikroMountError ? (
              <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 font-semibold text-amber-900">
                {mikroMountError}
              </div>
            ) : (
              <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MiniMetric label="Ürün" value={displayOrEmpty(productInfo?.product_name ?? request.product, '-')} />
                <MiniMetric label="Model" value={displayOrEmpty(productInfo?.product_model ?? request.model, '-')} />
                <MiniMetric label="Satış montaj durumu" value={resolvedSaleMountLabel} />
                <MiniMetric label="Montaj ödeme durumu" value={resolvedMountPaymentLabel} />
              </div>
            )}
          </section>
        ) : null}

        {shouldRenderProductInfoPanel ? (
        <DetailPanel
          title="Ürün / Seri Bilgisi"
          summary="Ürün adı, model, marka, seri ve belge numaraları"
          tone="product"
          open={productInfoOpen}
          onOpenChange={setProductInfoOpen}
          className={opsSectionClass('product', activeOpsSection)}
        >
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {optionalMetricValue(productInfo?.product_name ?? request.product) ? <MiniMetric label="Ürün" value={optionalMetricValue(productInfo?.product_name ?? request.product)} /> : null}
            {optionalMetricValue(productInfo?.product_model ?? request.model) ? <MiniMetric label="Model" value={optionalMetricValue(productInfo?.product_model ?? request.model)} /> : null}
            {optionalMetricValue(productInfo?.serial_number ?? request.serialNumber) ? <MiniMetric label="Seri No" value={optionalMetricValue(productInfo?.serial_number ?? request.serialNumber)} /> : null}
            {optionalMetricValue(productInfo?.brand) ? <MiniMetric label="Marka" value={optionalMetricValue(productInfo?.brand)} /> : null}
            {optionalMetricValue(productInfo?.activation_code) ? <MiniMetric label="Aktivasyon Kodu" value={optionalMetricValue(productInfo?.activation_code)} /> : null}
            {optionalMetricValue(documentInfo?.invoice_display_no) ? <MiniMetric label="Fatura No" value={optionalMetricValue(documentInfo?.invoice_display_no)} /> : null}
            {optionalMetricValue(documentInfo?.dispatch_display_no) ? <MiniMetric label="İrsaliye No" value={optionalMetricValue(documentInfo?.dispatch_display_no)} /> : null}
            {optionalMetricValue(documentInfo?.order_display_no) ? <MiniMetric label="Sipariş No" value={optionalMetricValue(documentInfo?.order_display_no)} /> : null}
            {shouldRenderHeaderPaymentSummary ? <MiniMetric label="Montaj ödeme durumu" value={mountPaymentDetailLabel} /> : null}
            {shouldRenderHeaderPaymentSummary ? <MiniMetric label="Ödeme aşaması" value={mountPaymentStageLabel} /> : null}
            {mountPaymentAmountLabel !== '-' ? <MiniMetric label="Ödeme tutarı" value={mountPaymentAmountLabel} /> : null}
            {hasMultiProductRequest ? (
              <MiniMetric label="Çoklu ürün ödeme durumu" value={mountPaymentReceived ? 'Ödeme onaylandı' : canonicalPaymentRequiresPayment ? 'Ödeme operasyon tarafından netleştirilecek' : mountPaymentStageLabel} />
            ) : null}
          </div>
        </DetailPanel>
        ) : null}

        {hasCustomerDetail ? (
        <DetailPanel
          title="Müşteri Bilgisi"
          summary="Müşteri, telefon, adres ve paylaşılan konum bilgileri"
          tone="customer"
          open={customerInfoOpen}
          onOpenChange={setCustomerInfoOpen}
          className={opsSectionClass('customer', activeOpsSection)}
        >
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {optionalMetricValue(request.customer) ? <MiniMetric label="Müşteri" value={optionalMetricValue(request.customer)} /> : null}
            {optionalMetricValue(request.phone) ? <MiniMetric label="Telefon" value={optionalMetricValue(request.phone)} /> : null}
            {optionalMetricValue(customerCityDistrictLabel) ? <MiniMetric label="İl / İlçe" value={optionalMetricValue(customerCityDistrictLabel)} /> : null}
            {optionalMetricValue(request.address) ? <MiniMetric label="Adres" value={optionalMetricValue(request.address)} /> : null}
            {locationInfo?.shared ? (
              <MiniMetric
                label="Konum paylaşıldı"
                value={locationInfo.map_url ? (
                  <a className="text-blue-700 underline-offset-4 hover:underline" href={locationInfo.map_url} target="_blank" rel="noreferrer">
                    Haritada aç
                  </a>
                ) : 'Evet'}
                hint={displayOrEmpty(locationInfo.formatted_address, 'Konum adresi yok')}
              />
            ) : null}
            {optionalMetricValue(buildingAddressLabel) ? <MiniMetric label="Bina / Daire / Kapı / Kat" value={optionalMetricValue(buildingAddressLabel)} /> : null}
          </div>
        </DetailPanel>
        ) : null}

        <DetailPanel
          title={showMountOperationControls ? 'Operasyon ve Montaj Kontrolü' : 'SRV Bağlamı'}
          summary={showMountOperationControls ? 'Kapı fotoğrafları, ödeme, adres, randevu ve montaj durumu tek yerde' : 'Parent montaj bilgileri kapalı bağlamdır; current SRV atama ve saha belgeleri ayrıdır'}
          tone={doorPhotoControlMissing || paymentControlMissing ? 'warning' : 'slate'}
          open={operationInfoOpen}
          onOpenChange={setOperationInfoOpen}
          panelRef={operationInfoRef}
          sectionTarget="operation"
          highlighted={highlightedNextActionTarget === 'operation'}
          className={opsSectionClass('operation', activeOpsSection)}
        >
          <div className="flex flex-wrap items-start justify-between gap-3">
            {routeQuote || hasStoredRouteCost ? (
              <Badge variant={routeFeeNeedsApproval ? 'warning' : hasRouteCostEvidence ? 'positive' : 'outline'}>
                {routeFeeStatusText}
              </Badge>
            ) : null}
          </div>

          {!showMountOperationControls ? (
            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-700">
              SRV kaydı parent montaj kapı/ödeme kontrolünü devralmaz. Parent görselleri ve montaj geçmişi servis geçmişi alanında kapalı bağlam olarak tutulur.
            </div>
          ) : null}

          {showDoorPhotoControl ? (
          <div className="grid gap-3 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="font-semibold text-slate-800">Kapı görselleri</p>
                <p className="mt-1 text-xs text-slate-500">Usta ataması için kapı görselleri uygun olarak işaretlenmelidir.</p>
              </div>
              <Badge variant={operationControl.door_photos_checked === 'compatible' ? 'positive' : operationControl.door_photos_checked === 'incompatible' ? 'destructive' : 'outline'}>
                {operationControl.door_photos_checked === 'compatible' ? 'Uyumlu' : operationControl.door_photos_checked === 'incompatible' ? 'Uyumsuz' : 'Kontrol edilmedi'}
              </Badge>
            </div>
            {doorPhotos.length === 0 ? (
              <p>Henüz kapı fotoğrafı yüklenmedi.</p>
            ) : (
              <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {doorPhotos.map((photo) => {
                  const previewUrl = photo.preview_url ?? photo.download_url ?? photo.url ?? ''

                  return (
                    <a
                      key={String(photo.id ?? photo.field_code)}
                      href={photo.download_url ?? photo.url ?? '#'}
                      target="_blank"
                      rel="noreferrer"
                      className="group min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white p-2 text-xs font-semibold text-slate-800 hover:border-blue-300 hover:text-blue-700"
                    >
                      <div className="relative mb-2 grid aspect-[4/3] w-full place-items-center overflow-hidden rounded-lg bg-slate-100 text-[11px] text-slate-500">
                        <span>Görüntü açılamadı</span>
                        {previewUrl ? (
                          <img
                            src={previewUrl}
                            alt={doorPhotoLabel(photo.field_code)}
                            className="absolute inset-0 h-full w-full object-cover"
                            onError={(event) => {
                              event.currentTarget.classList.add('hidden')
                            }}
                          />
                        ) : null}
                      </div>
                      <span className="block">{doorPhotoLabel(photo.field_code)}</span>
                      <span className="mt-1 block truncate font-medium text-slate-500 group-hover:text-blue-600" title={displayOrEmpty(photo.original_name, 'Görüntüle')}>
                        {displayOrEmpty(photo.original_name, 'Görüntüle')}
                      </span>
                    </a>
                  )
                })}
              </div>
            )}
            {onOpsExtraDocumentUpload ? (
              <div className="grid gap-3 rounded-2xl border border-blue-100 bg-white p-3">
                <div>
                  <p className="text-sm font-semibold text-slate-900">Görsel ekle</p>
                  <p className="mt-1 text-xs text-slate-500">OPS tarafından eklenen kapı görseli aynı kapı önizleme gridinde görünür; saha tamamlama belgelerini değiştirmez.</p>
                </div>
                <div className="grid gap-2 md:grid-cols-[160px_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                  <label className="grid gap-1 text-xs font-semibold text-slate-600">
                    Görsel tipi
                    <select
                      value={opsDoorPhotoType}
                      onChange={(event) => setOpsDoorPhotoType(event.target.value as OpsDoorPhotoType)}
                      className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-blue-300 focus:ring-blue-100/70 focus:ring-[3px]"
                    >
                      {OPS_DOOR_PHOTO_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                  </label>
                  <label className="grid gap-1 text-xs font-semibold text-slate-600">
                    Görsel
                    <Input
                      type="file"
                      multiple
                      accept="image/*"
                      onChange={(event) => setOpsDoorPhotoFiles(Array.from(event.target.files ?? []))}
                    />
                  </label>
                  <label className="grid gap-1 text-xs font-semibold text-slate-600">
                    Not
                    <Input value={opsDoorPhotoNote} onChange={(event) => setOpsDoorPhotoNote(event.target.value)} placeholder="Opsiyonel kapı görsel notu" />
                  </label>
                  <Button type="button" variant="outline" onClick={() => void handleOpsDoorPhotoUpload()} disabled={opsDoorPhotoUploading || opsDoorPhotoFiles.length === 0}>
                    {opsDoorPhotoUploading ? 'Yükleniyor...' : 'Görsel ekle'}
                  </Button>
                </div>
                {opsDoorPhotoFiles.length > 0 ? (
                  <p className="text-xs font-semibold text-blue-800">{opsDoorPhotoFiles.length} dosya seçildi.</p>
                ) : null}
                {opsDoorPhotoMessage ? (
                  <p className="text-xs font-semibold text-blue-800">{opsDoorPhotoMessage}</p>
                ) : null}
              </div>
            ) : null}
            {doorPhotoControlMissing ? (
              <div className="rounded-xl border border-rose-200 bg-rose-50/80 px-3 py-2 text-sm font-semibold text-rose-800">
                Kapı görseli kontrol edilmedi
              </div>
            ) : null}
            <OperationControlRow
              label="Kapı görselleri bakıldı mı?"
              value={operationControl.door_photos_checked ?? 'unreviewed'}
              options={[
                { value: 'compatible', label: 'Uyumlu', tone: 'positive' },
                { value: 'incompatible', label: 'Uyumsuz', tone: 'problem' },
                { value: 'unreviewed', label: 'Kontrol edilmedi', tone: 'neutral' },
              ]}
              disabled={operationControlUpdateInFlight}
              onChange={(value) => operationControlChange('door_photos_checked', value as 'compatible' | 'incompatible' | 'unreviewed')}
            />
          </div>
          ) : null}

          {operationControlUpdateError ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
              {operationControlUpdateError}
            </div>
          ) : null}

          {(showPaymentControl || (showAddressControl && showAddressControlBlock) || showScheduleControl) ? (
          <div className="grid gap-4 xl:grid-cols-2">
            {showPaymentControl ? (
            <section className="grid gap-3 rounded-2xl border border-slate-200 bg-[#F8FAFD] p-4">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Ödeme / Montaj Bloğu</p>
                <p className="mt-1 text-sm text-slate-600">Ödeme bilgisi ve ödeme kontrolü aynı blokta takip edilir.</p>
              </div>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <MiniMetric label="Satış montaj durumu" value={resolvedSaleMountLabel} />
                <MiniMetric
                  label="Montaj ödeme durumu"
                  value={resolvedMountPaymentLabel}
                />
                <MiniMetric label="Tahsilat durumu" value={paymentCollectionStatusLabel} />
                <MiniMetric label="Alınan ödeme tutarı" value={paidAmountDisplayLabel} />
                {serviceCustomerPaymentLabel ? <MiniMetric label="Servis ödemesi" value={serviceCustomerPaymentLabel} /> : null}
                {partCustomerPaymentLabel ? <MiniMetric label="Parça ödemesi" value={partCustomerPaymentLabel} /> : null}
                {totalCustomerCollectionLabel ? <MiniMetric label="Toplam müşteri tahsilatı" value={totalCustomerCollectionLabel} /> : null}
                <MiniMetric label="Operasyon ödeme kontrolü" value={opsPaymentCheckLabel} />
              </div>
              {showPaymentTechnicalDetails ? (
                <details className="rounded-2xl border border-slate-200 bg-white/80 p-3 text-sm text-slate-700">
                  <summary className="cursor-pointer font-semibold text-slate-800">Ödeme teknik detayları</summary>
                  <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <MiniMetric label="Ödeme referansı" value={<span className="break-all" title={displayOrEmpty(saleAndPayment?.payment_reference, '-')}>{displayOrEmpty(saleAndPayment?.payment_reference, '-')}</span>} />
                    <MiniMetric label="Ödeme tarihi" value={paymentPaidAtLabel} />
                    {saleAndPayment?.payment_provider ? (
                      <MiniMetric label="Sağlayıcı" value={saleAndPayment.payment_provider} />
                    ) : null}
                  </div>
                </details>
              ) : null}
              {showServicePartPaymentSummary ? (
              <div className="rounded-2xl border border-blue-100 bg-white/80 p-3 text-sm text-slate-700">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="font-semibold text-slate-800">Servis / parça müşteri ödemesi</p>
                    <p className="mt-1 text-xs text-slate-500">
                      {servicePartPaymentSummaryHint}
                    </p>
                  </div>
                </div>
                {latestCustomerCharge ? (
                  <div className="mt-3 rounded-xl border border-blue-100 bg-blue-50 p-3 text-xs text-blue-900">
                    <p className="font-semibold">Son link: {latestCustomerCharge.status_label ?? latestCustomerCharge.status ?? '-'}</p>
                    <p className="mt-1">Servis: {latestCustomerCharge.service_amount_label ?? formatMoneyValue(latestCustomerCharge.service_amount ?? 0)} · Parça: {latestCustomerCharge.part_amount_label ?? formatMoneyValue(latestCustomerCharge.part_amount ?? 0)} · Toplam: {latestCustomerCharge.amount_label ?? formatMoneyValue(latestCustomerCharge.amount ?? 0)}</p>
                    {latestCustomerCharge.payment_url ? (
                      <div className="mt-2 flex flex-wrap gap-2">
                        <Button asChild size="sm" variant="outline">
                          <a href={latestCustomerCharge.payment_url} target="_blank" rel="noreferrer">Ödeme linkini aç</a>
                        </Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => void copyCustomerChargeValue(latestCustomerCharge.payment_url, 'Link kopyalandı.')}>Linki kopyala</Button>
                        {renderPaymentLinkSendAction(latestCustomerCharge)}
                      </div>
                    ) : null}
                  </div>
                ) : null}
              </div>
              ) : null}
              {paymentControlMissing ? (
                <div className="rounded-xl border border-rose-200 bg-rose-50/80 px-3 py-2 text-sm font-semibold text-rose-800">
                  Ödeme kontrol edilmedi
                </div>
              ) : null}
              <OperationControlRow
                label="Ödeme kontrol edildi mi?"
                value={operationControl.payment_checked ?? 'unreviewed'}
                options={[
                  { value: 'yes', label: 'Evet', tone: 'positive' },
                  { value: 'no', label: 'Hayır', tone: 'problem' },
                  { value: 'unreviewed', label: 'Kontrol edilmedi', tone: 'neutral' },
                ]}
                disabled={operationControlUpdateInFlight}
                onChange={(value) => operationControlChange('payment_checked', value as 'yes' | 'no' | 'unreviewed')}
              />
            </section>
            ) : null}

            {showAddressControl && showAddressControlBlock ? (
            <section className="grid gap-3 rounded-2xl border border-slate-200 bg-[#F8FAFD] p-4">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Adres Kontrol Bloğu</p>
                <p className="mt-1 text-sm text-slate-600">Adres bilgisi ve adres kontrolü birlikte değerlendirilir.</p>
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <MiniMetric label="İl / İlçe" value={displayOrEmpty([request.city, request.district].filter(Boolean).join(' / '), '-')} />
                <MiniMetric label="Adres" value={displayOrEmpty(request.address, '-')} />
                {locationInfo?.shared ? (
                  <MiniMetric
                    label="Konum / Haritada aç"
                    value={locationInfo.map_url ? (
                      <a className="text-blue-700 underline-offset-4 hover:underline" href={locationInfo.map_url} target="_blank" rel="noreferrer">
                        Haritada aç
                      </a>
                    ) : 'Konum paylaşıldı'}
                    hint={displayOrEmpty(locationInfo.formatted_address, 'Konum adresi yok')}
                  />
                ) : null}
              </div>
              <OperationControlRow
                label="Adres kontrol edildi mi?"
                value={operationControl.address_checked ?? 'unreviewed'}
                options={[
                  { value: 'yes', label: 'Evet', tone: 'positive' },
                  { value: 'no', label: 'Hayır', tone: 'problem' },
                  { value: 'unreviewed', label: 'Kontrol edilmedi', tone: 'neutral' },
                ]}
                disabled={operationControlUpdateInFlight}
                onChange={(value) => operationControlChange('address_checked', value as 'yes' | 'no' | 'unreviewed')}
              />
            </section>
            ) : null}

            {showScheduleControl ? (
            <section className="grid gap-3 rounded-2xl border border-slate-200 bg-[#F8FAFD] p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Randevu Kontrol Bloğu</p>
                  <p className="mt-1 text-sm text-slate-600">Randevu tarihi ve servis aşamasıyla birlikte kontrol edilir.</p>
                </div>
                <Button type="button" variant="outline" onClick={() => onSchedule?.()} disabled={isActionDisabled}>
                  Randevu Planla
                </Button>
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <MiniMetric label="Randevu tarihi" value={scheduledDateLabel} hint={scheduledTimeLabel} />
                <MiniMetric label="Servis aşaması" value={currentStatusLabel} />
              </div>
              <OperationControlRow
                label="Randevu tarihi güncellenecek mi?"
                value={operationControl.schedule_update_required ?? 'unreviewed'}
                options={[
                  { value: 'no', label: 'Hayır', tone: 'positive' },
                  { value: 'yes', label: 'Evet', tone: 'problem' },
                  { value: 'unreviewed', label: 'Kontrol edilmedi', tone: 'neutral' },
                ]}
                disabled={operationControlUpdateInFlight}
                onChange={(value) => operationControlChange('schedule_update_required', value as 'yes' | 'no' | 'unreviewed')}
              />
            </section>
            ) : null}

          </div>
          ) : null}

          <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <Badge variant={statusVariant(request.status)}>Durum: {currentStatusLabel}</Badge>
            <Badge variant="outline">Öncelik: {currentPriorityLabel}</Badge>
            {showSlaStatusChip ? (
              <span className={['inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold', slaTone(request.slaStatus)].join(' ')} title={slaTitle}>
                SLA: {currentSlaLabel}
              </span>
            ) : null}
            {hasRouteCostEvidence ? (
              <Badge variant={routeFeeNeedsApproval ? 'warning' : 'positive'}>
                {routeFeeStatusText}
              </Badge>
            ) : routeQuote ? (
              <Badge variant="warning">{routeQuoteStaleForSelectedTechnician ? 'Usta yol hakedişi hesaplanmadı' : 'Usta yol hakedişi hesaplanamadı'}</Badge>
            ) : null}
            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">
              Mevcut etiketler: {currentPriorityLabel} / {currentSlaLabel}
            </span>
            {showDoorPhotoControl ? (
              <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">
                Kapı uygunluk durumu: {operationControl.door_photos_checked === 'compatible' ? 'Uyumlu' : operationControl.door_photos_checked === 'incompatible' ? 'Uyumsuz' : 'Kontrol edilmedi'}
              </span>
            ) : null}
          </div>

          {combinedAssignmentBlockerMessages.length > 0 ? (
            <div className="rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
              <p className="font-semibold">Usta atama engelleri</p>
              <div className="mt-2 flex flex-wrap gap-2">
                {combinedAssignmentBlockerMessages.map((message) => (
                  <span key={message} className="rounded-full border border-amber-200 bg-white px-2.5 py-1 text-xs font-semibold text-amber-950">
                    {message}
                  </span>
                ))}
              </div>
            </div>
          ) : null}
        </DetailPanel>

        <DetailPanel
          title="Usta / Çilingir Atama"
          summary="Usta seçimi, yol hakedişi ve servis bilgileri"
          tone="technician"
          open={assignmentInfoOpen}
          onOpenChange={setAssignmentInfoOpen}
          panelRef={assignmentInfoRef}
          sectionTarget="assignment"
          highlighted={highlightedNextActionTarget === 'assignment'}
          className={opsSectionClass('assignment', activeOpsSection)}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
              <p className="max-w-3xl text-sm text-slate-600">
                {isCancelledOrReviewContext
                  ? 'İptal bağlamında son usta ve hakediş bilgisi salt okunur gösterilir.'
                  : 'Seçili usta, yol hakedişi ve atama aksiyonları aynı akışta takip edilir.'}
              </p>
              {!isCancelledOrReviewContext ? (
              <Button
                type="button"
                variant="outline"
                onClick={() => void onAssignSelectedTechnician?.()}
                disabled={assignmentSubmitDisabled}
                title={isAssignmentBlocked ? combinedAssignmentBlockerMessages.join(' ') : undefined}
              >
                {hasAssignedTechnician ? 'Atamayı Güncelle' : 'Servis Ata'}
              </Button>
              ) : null}
            </div>
            {!isCancelledOrReviewContext && canReassignAfterReview ? (
              <div className="grid gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                <div>
                  <p className="font-semibold">Aynı MRN ile yeniden işleme al</p>
                  <p className="mt-1 text-xs text-amber-900">
                    Red, müşteri onayı reddi veya son kontrol sonrası bu iş yeni MRN açmadan yeniden atanabilir. Geçmiş kayıtlar korunur; aktif kart yeni atama aşamasına döner.
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button type="button" variant="outline" size="sm" onClick={() => onAssign?.()}>
                    Başka usta ata
                  </Button>
                  {hasAssignedTechnician ? (
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => void onAssignSelectedTechnician?.()}
                      disabled={assignmentSubmitDisabled}
                      title={isAssignmentBlocked ? combinedAssignmentBlockerMessages.join(' ') : undefined}
                    >
                      {sameTechnicianReviewActionLabel}
                    </Button>
                  ) : null}
                </div>
              </div>
            ) : null}
            {!isCancelledOrReviewContext && shouldShowPayerStateCard ? (
              <div className={`grid gap-3 rounded-2xl border p-3 text-sm ${payerStateTone}`}>
                <div>
                  <div>
                    <p className="font-semibold">{payerStateLabel}</p>
                    <p className="mt-1 text-xs opacity-80">{payerStateDescription}</p>
                    {payerCustomerInstruction ? (
                      <p className="mt-2 text-xs font-semibold opacity-90">{payerCustomerInstruction}</p>
                    ) : null}
                  </div>
                </div>
                {activeCustomerDirectAmountLabel || companyPayableAmountLabel || companyCollectedAmountLabel || pendingPaymentTotalLabel || paymentLinkCopyUrl(extraMountPayment) ? (
                  <div className="grid gap-2 sm:grid-cols-3">
                    {shouldShowCustomerPaysTechnicianCard && activeCustomerDirectAmountLabel ? <MiniMetric label="Müşteriye bildirilecek tutar" value={activeCustomerDirectAmountLabel} /> : null}
                    {companyPayableAmountLabel ? <MiniMetric label="Şirket ödemesi" value={companyPayableAmountLabel} /> : null}
                    {companyCollectedAmountLabel && (payerStateKey === 'company_collected_online' || payerStateKey === 'company_collected_external') ? <MiniMetric label="Müşteri tahsilatı" value={companyCollectedAmountLabel} /> : null}
                    {pendingPaymentTotalLabel && payerStateKey === 'pending_online_payment' ? <MiniMetric label="Bekleyen tahsilat" value={pendingPaymentTotalLabel} /> : null}
                    {paymentLinkCopyUrl(extraMountPayment) ? (
                      <MiniMetric
                        label="Bekleyen link"
                        value={<span className="break-all">{paymentLinkCopyUrl(extraMountPayment)}</span>}
                        hint={(
                          <div className="flex flex-wrap items-center gap-2">
                            <Button asChild type="button" size="sm" variant="outline">
                              <a href={paymentLinkCopyUrl(extraMountPayment)} target="_blank" rel="noreferrer">
                                Ödeme linkini aç
                              </a>
                            </Button>
                            <Button type="button" size="sm" variant="outline" onClick={() => void copyPaymentLinkValue(paymentLinkCopyUrl(extraMountPayment))}>
                              Linki kopyala
                            </Button>
                            {renderPaymentLinkSendAction(extraMountPayment)}
                          </div>
                        )}
                      />
                    ) : null}
                  </div>
                ) : null}
              </div>
            ) : null}
            {mountExclusionAckRequired && showMountExcludedApprovalBlock ? (
              <div className="grid gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                <div>
                  <p className="font-semibold">Montaj hariç / çoklu ürün onayı</p>
                  <p className="mt-1 text-amber-900">Bu onay tamamlanmadan servis atanamaz.</p>
                </div>
                <label className="flex items-start gap-2 font-semibold">
                  <input
                    type="checkbox"
                    checked={mountExclusionAcknowledged}
                    onChange={(event) => onMountExclusionAcknowledgedChange?.(event.target.checked)}
                    className="mt-1 h-4 w-4 rounded border-amber-300 text-amber-700 focus:ring-amber-500"
                  />
                  <span>Bu işte montaj ödemesi henüz alınmadı; operasyon onayıyla servis ataması yapılacak.</span>
                </label>
                <label className="grid gap-1 font-semibold">
                  Açıklama
                  <textarea
                    value={mountExclusionNote}
                    onChange={(event) => onMountExclusionNoteChange?.(event.target.value)}
                    placeholder="Ödeme/montaj durumu notu girin. Örn: Çoklu ürün talebi, ödeme operasyon tarafından takip edilecek."
                    className="min-h-20 rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm text-slate-950 outline-none transition focus:border-amber-400 focus:ring-2 focus:ring-amber-100"
                  />
                </label>
                {!mountExclusionAckComplete ? (
                  <p className="rounded-xl border border-amber-200 bg-white px-3 py-2 text-xs font-semibold text-amber-900">
                    Checkbox işaretlenmeli ve açıklama girilmelidir.
                  </p>
                ) : null}
              </div>
            ) : null}
            {assignError ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-800">
                {assignError}
              </div>
            ) : null}
            {hasAssignedTechnician ? (
              <div className="grid gap-3 rounded-2xl border border-sky-100 bg-white p-3 text-sm text-slate-800">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-sky-700">Atanan usta özeti</p>
                    <p className="mt-1 text-base font-bold text-slate-950">{displayOrEmpty(request.technician, 'Atanmadı')}</p>
                    <p className="mt-1 text-xs font-semibold text-blue-700">{displayOrEmpty(request.technicianPhone, 'Telefon yok')}</p>
                  </div>
                  <Badge variant={assignmentOfferDispatchStatus === 'sent' ? 'secondary' : activeAssignmentOffer ? 'outline' : 'warning'}>
                    {earningDispatchStatusLabel}
                  </Badge>
                </div>
                <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-6">
                  <MiniMetric label="Şehir" value={assignedTechnicianCityLabel} />
                  <MiniMetric label="İşçilik" value={technicianLaborCostLabel} />
                  <MiniMetric label="Yol" value={travelCostLabel} />
                  <MiniMetric label={locksmithPayoutTotalMetricLabel} value={totalTechnicianCostLabel} />
                <MiniMetric label="Müşteri tahsilatı" value={totalCustomerCollectionDisplayLabel} />
                  <MiniMetric label={netDifferenceMetricLabel} value={netProfitLabel} />
                </div>
              </div>
            ) : null}
            {shouldShowPartCreateAction ? (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-violet-100 bg-white p-3 text-sm text-slate-700">
                <div>
                  <p className="font-semibold text-slate-950">Parça talepleri</p>
                  <p className="mt-1 text-xs text-slate-600">Ücretli veya ücretsiz parça ihtiyacı bu talep üzerinde kayıt altına alınır.</p>
                </div>
                <Button type="button" variant="outline" onClick={openPartCreateModal}>
                  Parça Talebi Oluştur
                </Button>
              </div>
            ) : null}
            {partRequests.length > 0 ? (
              <div className="grid gap-3 rounded-2xl border border-violet-100 bg-violet-50 p-3 text-sm text-violet-950">
                <div>
                  <p className="font-semibold">Parça Talepleri</p>
                  <p className="mt-1 text-xs text-violet-800">Parça talebi kapanmadan iş tamamlamaya gönderilemez. SRV gerekirse aynı kök MRN altında yeni servis açılır.</p>
                </div>
                {partRequests.slice(0, 4).map((partRequest) => {
                  const partKey = String(partRequest.id)
                  const note = partRequestNotes[partKey] ?? ''
                  const partnerMessage = partRequestPartnerMessages[partKey] ?? ''
                  const provider = partRequestProviders[partKey] ?? ''
                  const tracking = partRequestTrackings[partKey] ?? ''
                  const paymentRequired = partRequest.is_payment_required === true
                    || (partRequest.charge_decision === 'chargeable' && partRequest.is_payment_paid !== true && partRequest.charge_status !== 'paid')
                  const canShipPart = partRequest.can_ship !== false && !paymentRequired
                  const showShipmentInputs = (partRequest.status === 'approved' || partRequest.status === 'ordered') && canShipPart
                  const transition = (status: string) => onPartRequestTransition?.(partRequest.id, {
                    status,
                    note: note || null,
                    partner_message: partnerMessage || null,
                    shipment_provider: provider || null,
                    tracking_no: tracking || null,
                  })

                  return (
                    <div key={partKey} className="grid gap-3 rounded-xl border border-violet-100 bg-white p-3">
                      <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                          <p className="font-semibold text-slate-950">{partRequest.part_name} {partRequest.quantity > 1 ? `x${partRequest.quantity}` : ''}</p>
                          <p className="mt-1 text-xs text-slate-600">{partRequest.technician_note || partRequest.reason || 'Usta açıklaması yok'}</p>
                          {partRequest.tracking_no ? (
                            <p className="mt-1 text-xs text-slate-600">Kargo: {[partRequest.shipment_provider, partRequest.tracking_no].filter(Boolean).join(' / ')}</p>
                          ) : null}
                        </div>
                        <Badge variant={partRequest.status === 'rejected' ? 'destructive' : partRequest.status === 'sent' ? 'warning' : 'outline'}>
                          {partRequest.status_label}
                        </Badge>
                      </div>
                      {partRequest.ops_note ? <p className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-xs text-slate-700">Ops notu: {partRequest.ops_note}</p> : null}
                      {partRequest.partner_message ? <p className="rounded-xl border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">Partner mesajı: {partRequest.partner_message}</p> : null}
                      {partRequest.next_action_label && partRequest.next_action_label !== partRequest.status_label ? (
                        <p className="rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-900">Sıradaki aksiyon: {partRequest.next_action_label}</p>
                      ) : null}
                      {partRequest.charge_decision_label ? (
                        <div className="rounded-xl border border-violet-100 bg-violet-50 px-3 py-2 text-xs text-violet-900">
                          <p className="font-semibold">{partRequest.charge_decision_label}</p>
                          {partRequest.charge_decision === 'chargeable' ? (
                            <div className="mt-1 grid gap-1">
                              <p>Servis: {partRequest.service_amount_label ?? formatMoneyValue(partRequest.service_amount ?? 0)} · Parça: {partRequest.part_amount_label ?? formatMoneyValue(partRequest.part_amount ?? 0)} · Toplam: {partRequest.total_amount_label ?? formatMoneyValue(partRequest.total_amount ?? 0)}</p>
                              {partRequest.customer_charge?.status_label ? (
                                <p>Ödeme: {partRequest.customer_charge.status_label}</p>
                              ) : null}
                              {partRequest.customer_charge?.paid_at || partRequest.paid_at ? (
                                <p>Ödeme tarihi: {dateTimeOrEmpty(partRequest.customer_charge?.paid_at ?? partRequest.paid_at, '-')}</p>
                              ) : null}
                              {partRequest.customer_charge?.payment_reference || partRequest.payment_reference || partRequest.provider_reference ? (
                                <p>Referans: {displayOrEmpty(partRequest.customer_charge?.payment_reference ?? partRequest.payment_reference ?? partRequest.provider_reference, '-')}</p>
                              ) : null}
                              {partRequest.payment_url ? (
                                <div className="flex flex-wrap items-center gap-2">
                                  <a href={partRequest.payment_url} target="_blank" rel="noreferrer" className="font-semibold text-blue-700 underline-offset-4 hover:underline">Ödeme linkini aç</a>
                                  <Button type="button" size="sm" variant="outline" onClick={() => void copyCustomerChargeValue(partRequest.payment_url, 'Link kopyalandı.')}>Linki kopyala</Button>
                                  {renderPaymentLinkSendAction({
                                    id: partRequest.payment_id ?? partRequest.customer_charge?.id ?? null,
                                    status: partRequest.customer_charge?.status ?? 'pending',
                                    payment_url: partRequest.payment_url,
                                    amount: partRequest.customer_charge?.amount ?? ((Number(partRequest.service_amount ?? 0) || 0) + (Number(partRequest.part_amount ?? 0) || 0)),
                                  })}
                                </div>
                              ) : null}
                            </div>
                          ) : null}
                        </div>
                      ) : null}
                      {['requested', 'ops_review', 'approved', 'ordered', 'sent', 'received', 'service_visit_required'].includes(partRequest.status) ? (
                        <div className="grid gap-2">
                          <div className="grid gap-2 sm:grid-cols-2">
                            <Input value={note} onChange={(event) => setPartRequestNotes((current) => ({ ...current, [partKey]: event.target.value }))} placeholder="Operasyon notu" />
                            <Input value={partnerMessage} onChange={(event) => setPartRequestPartnerMessages((current) => ({ ...current, [partKey]: event.target.value }))} placeholder="Partner'a gösterilecek mesaj" />
                          </div>
                          {showShipmentInputs ? (
                            <div className="grid gap-2 sm:grid-cols-2">
                              <Input value={provider} onChange={(event) => setPartRequestProviders((current) => ({ ...current, [partKey]: event.target.value }))} placeholder="Kargo / sağlayıcı" />
                              <Input value={tracking} onChange={(event) => setPartRequestTrackings((current) => ({ ...current, [partKey]: event.target.value }))} placeholder="Takip no" />
                            </div>
                          ) : null}
                          <div className="flex flex-wrap justify-end gap-2">
                            {partRequest.status === 'ops_review' || partRequest.status === 'requested' ? (
                              <>
                                <Button type="button" variant="outline" onClick={() => openPartDecisionModal(partRequest)}>Karar ver</Button>
                                <Button type="button" variant="destructive" disabled={note.trim().length < 3} onClick={() => void transition('rejected')}>Reddet</Button>
                              </>
                            ) : null}
                            {partRequest.status === 'approved' ? (
                              <Button type="button" variant="outline" onClick={() => void transition('ordered')}>Tedarikte işaretle</Button>
                            ) : null}
                            {(partRequest.status === 'approved' || partRequest.status === 'ordered') && canShipPart ? (
                              <Button type="button" onClick={() => void transition('sent')}>Gönderildi işaretle</Button>
                            ) : null}
                            {partRequest.status === 'received' ? (
                              <Button type="button" variant="outline" onClick={() => void transition('service_visit_required')}>Parça sonrası servis gerekli</Button>
                            ) : null}
                            {partRequest.status === 'service_visit_required' ? (
                              <Button type="button" onClick={() => void onPartRequestServiceVisitCreate?.(partRequest.id, { reason: 'spare_part' })}>SRV oluştur</Button>
                            ) : null}
                            {['received', 'service_visit_created'].includes(partRequest.status) ? (
                              <Button type="button" variant="outline" onClick={() => void transition('closed')}>Kapat</Button>
                            ) : null}
                          </div>
                        </div>
                      ) : null}
                    </div>
                  )
                })}
              </div>
            ) : null}
            {showAssignmentPortalActionBlock ? (
              <div className="grid gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-3 text-sm text-blue-950">
                <div>
                  <p className="font-semibold">Çilingir Portal Aksiyonları</p>
                  <p className="mt-1 text-xs text-blue-800">Portal gönderimleri core iş akışını bypass etmez; operasyon onayı burada verilir.</p>
                </div>
                {openAppointmentProposals.map((action) => {
                  const slots = Array.isArray(action.payload?.slots)
                    ? action.payload.slots as Array<Record<string, unknown>>
                    : []
                  const legacyProposal = (action.payload?.proposal ?? {}) as Record<string, unknown>
                  const proposalRows = slots.length > 0
                    ? slots
                    : [legacyProposal].filter((slot) => Object.keys(slot).length > 0)
                  const selectedSlotIndex = appointmentSelectedSlotByAction[String(action.id)] ?? 0

                  return (
                    <div key={String(action.id)} className="grid gap-2 rounded-xl border border-blue-100 bg-white p-3">
                      <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                          <p className="font-semibold text-slate-950">Usta randevu önerisi</p>
                          <p className="mt-1 text-xs text-slate-600">Operasyon bir saat aralığını seçip randevuyu onaylar.</p>
                          {action.note ? <p className="mt-1 text-xs text-slate-500">{action.note}</p> : null}
                        </div>
                        <Badge variant="warning">Operasyon onayı bekliyor</Badge>
                      </div>
                      {proposalRows.length > 0 ? (
                        <div className="grid gap-2">
                          {proposalRows.map((slot, slotIndex) => {
                            const date = String(slot.date ?? slot.proposed_date ?? '')
                            const start = String(slot.start_time ?? '')
                            const end = String(slot.end_time ?? '')
                            const legacyLabel = String(slot.slot ?? slot.slot_label ?? '')
                            const label = [date, start && end ? `${start} - ${end}` : legacyLabel].filter(Boolean).join(' · ')

                            return (
                              <label key={`${action.id}-${slotIndex}`} className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700">
                                <input
                                  type="radio"
                                  name={`appointment-proposal-${action.id}`}
                                  checked={selectedSlotIndex === slotIndex}
                                  onChange={() => setAppointmentSelectedSlotByAction((current) => ({
                                    ...current,
                                    [String(action.id)]: slotIndex,
                                  }))}
                                />
                                <span>{label || 'Randevu seçeneği'}</span>
                              </label>
                            )
                          })}
                        </div>
                      ) : (
                        <p className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">Öneri detayı yok.</p>
                      )}
                      <label className="grid gap-1 text-xs font-semibold text-slate-600">
                        Onay / revize notu
                        <Input value={appointmentReviewNote} onChange={(event) => setAppointmentReviewNote(event.target.value)} placeholder="Müşteri ve usta mesajına eklenecek operasyon notu" />
                      </label>
                      <div className="flex flex-wrap justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => void onPartnerAppointmentProposalReject?.(action.id, { note: appointmentReviewNote || 'Randevu önerisi revize istendi.', status: 'revision_requested' })}>
                          Revize iste
                        </Button>
                        <Button type="button" onClick={() => void onPartnerAppointmentProposalApprove?.(action.id, { note: appointmentReviewNote || null, selected_slot_index: selectedSlotIndex })}>
                          Randevuyu onayla
                        </Button>
                      </div>
                    </div>
                  )
                })}
                {jobRejections.slice(0, 2).map((action) => (
                  <div key={String(action.id)} className="rounded-xl border border-rose-100 bg-rose-50 p-3 text-rose-900">
                    <p className="font-semibold">Usta işi reddetti</p>
                    <p className="mt-1 text-xs">{String(action.payload?.reason_label ?? action.note ?? 'Neden belirtilmedi')}</p>
                  </div>
                ))}
                {customerApprovalRejections.slice(0, 2).map((action) => (
                  <div key={String(action.id)} className="rounded-xl border border-rose-100 bg-rose-50 p-3 text-rose-900">
                    <p className="font-semibold">Müşteri onayı reddedildi</p>
                    <p className="mt-1 text-xs">{String(action.payload?.customer_note ?? action.note ?? 'Açıklama yok')}</p>
                  </div>
                ))}
                {supportRequests.slice(0, 3).map((action) => (
                  <div key={String(action.id)} className="rounded-xl border border-amber-100 bg-amber-50 p-3 text-amber-950">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <p className="font-semibold">Yedek parça / ek talep</p>
                        <p className="mt-1 text-xs">{String(action.payload?.description ?? action.note ?? 'Açıklama yok')}</p>
                      </div>
                      <Badge variant="warning">Operasyon incelemede</Badge>
                    </div>
                  </div>
                ))}
                {revisitRequests.slice(0, 3).map((action) => (
                  <div key={String(action.id)} className="rounded-xl border border-violet-100 bg-violet-50 p-3 text-violet-950">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <p className="font-semibold">Tekrar ziyaret talebi</p>
                        <p className="mt-1 text-xs">{String(action.payload?.reason ?? action.note ?? 'Açıklama yok')}</p>
                      </div>
                      <Badge variant="warning">Operasyon incelemede</Badge>
                    </div>
                    <div className="mt-3 flex flex-wrap justify-end gap-2">
                      <Button type="button" onClick={() => void onRevisitServiceVisitCreate?.(action.id, { note: action.note ?? null })}>
                        SRV oluştur
                      </Button>
                    </div>
                  </div>
                ))}
                {technicianRevisionOffer ? (
                  <div className={[
                    'grid gap-3 rounded-xl border p-3',
                    technicianRevisionOfferPending
                      ? 'border-amber-200 bg-amber-50 text-amber-950'
                      : 'border-slate-200 bg-white text-slate-900',
                  ].join(' ')}>
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <p className="font-semibold">Ustanın revize teklifi</p>
                        <p className="mt-1 text-xs">
                          {displayOrEmpty(technicianRevisionOffer.technician_name, 'Usta bilgisi yok')} · {dateTimeOrEmpty(technicianRevisionOffer.requested_at, 'Tarih yok')}
                        </p>
                      </div>
                      <Badge variant={technicianRevisionOfferPending ? 'warning' : 'outline'}>
                        {technicianRevisionOffer.status_label ?? (technicianRevisionOfferPending ? 'Operasyon yanıtı bekliyor' : 'Yanıtlandı')}
                      </Badge>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-3">
                      <MiniMetric label="Teklif işçilik" value={formatMoneyValue(technicianRevisionOffer.labor_earning ?? null)} />
                      <MiniMetric label="Teklif yol" value={formatMoneyValue(technicianRevisionOffer.route_earning ?? null)} />
                      <MiniMetric label="Teklif toplam" value={formatMoneyValue(technicianRevisionOffer.total_earning ?? null)} />
                    </div>
                    {technicianRevisionOffer.note ? (
                      <p className="rounded-lg bg-white/70 px-3 py-2 text-xs font-semibold">
                        Not: {technicianRevisionOffer.note}
                      </p>
                    ) : null}
                    <p className="text-xs">
                      Bu teklif onaylanan hakedişi otomatik değiştirmez; aşağıdaki onaylanan hakediş kartı canonical kaynaktır.
                    </p>
                  </div>
                ) : null}
                {assignmentOffer ? (
                  <div className="grid gap-3 rounded-xl border border-emerald-100 bg-emerald-50 p-3 text-emerald-950">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="font-semibold">Onaylanan hakediş</p>
                      <Badge variant="positive">{assignmentOfferStatusLabel(assignmentOffer.status)}</Badge>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-4">
                      <MiniMetric label="İşçilik" value={formatMoneyValue(assignmentOffer.labor_amount)} />
                      <MiniMetric label="Yol" value={formatMoneyValue(assignmentOffer.route_fee_amount)} />
                      <MiniMetric label="Toplam" value={formatMoneyValue(assignmentOffer.total_amount)} />
                      <MiniMetric label="Durum" value={assignmentOfferStatusLabel(assignmentOffer.status)} />
                    </div>
                    {settlement ? (
                      <div className="grid gap-2 rounded-xl border border-white/70 bg-white/80 p-3 sm:grid-cols-4">
                        <MiniMetric label="Müşteriye bildirilecek ödeme" value={formatMoneyValue(settlement.customer_direct_to_technician_amount ?? null)} />
                        <MiniMetric label="Usta hakedişi" value={formatMoneyValue(settlement.technician_earning_total ?? null)} />
                        <MiniMetric label="Ustaya ödendi varsayılan" value={formatMoneyValue(settlement.customer_direct_assumed_paid_amount ?? null)} />
                        <MiniMetric label="Müşteri online tahsilat" value={formatMoneyValue(settlement.customer_collection_amount ?? null)} />
                        <MiniMetric label="Şirket ödemesi" value={formatMoneyValue(settlement.company_payable_amount ?? null)} />
                        <MiniMetric label="Şirket ödedi" value={formatMoneyValue(settlement.company_paid_amount ?? null)} />
                        <MiniMetric label="Şirket kalan" value={formatMoneyValue(settlement.company_remaining_amount ?? null)} />
                        <MiniMetric label="Fazla bildirim" value={formatMoneyValue(settlement.overpay_warning_amount ?? null)} />
                        <MiniMetric label="Settlement" value={settlementStatusLabel(settlement.status)} />
                        {settlementNeedsAdminReview ? (
                          <div className="grid gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950 sm:col-span-4">
                            <p className="font-semibold">Hakediş admin incelemesi gerekiyor</p>
                            <p>{settlement.review_reason || 'Müşteriye bildirilen tutar usta hakedişinden yüksek.'}</p>
                            <div className="grid gap-1 sm:grid-cols-4">
                              <span>Usta hakedişi: <strong>{formatMoneyValue(settlement.technician_earning_total ?? null)}</strong></span>
                              <span>Müşteriye bildirilen: <strong>{formatMoneyValue(settlement.customer_direct_to_technician_amount ?? null)}</strong></span>
                              <span>Fazla bildirim: <strong>{formatMoneyValue(settlement.overpay_warning_amount ?? null)}</strong></span>
                              <span>Şirket ödemesi: <strong>{formatMoneyValue(settlement.company_payable_amount ?? null)}</strong></span>
                            </div>
                          </div>
                        ) : null}
                        {settlementReviewResolved ? (
                          <div className="grid gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-950 sm:col-span-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                              <p className="font-semibold">Hakediş inceleme kararı</p>
                              <Badge variant="positive">{settlementReviewDecision?.decision_label ?? 'Karar verildi'}</Badge>
                            </div>
                            <p>{settlementReviewDecision?.reason || 'Admin incelemesi tamamlandı.'}</p>
                            <p>
                              {settlementReviewDecision?.reviewed_by_name ? `${settlementReviewDecision.reviewed_by_name} · ` : ''}
                              {dateTimeOrEmpty(settlementReviewDecision?.reviewed_at, 'Tarih yok')}
                            </p>
                          </div>
                        ) : null}
                      </div>
                    ) : null}
                    <div className="grid gap-2 sm:grid-cols-[140px_140px_minmax(0,1fr)]">
                      <Input type="number" min="0" step="1" value={offerLaborInput} onChange={(event) => setOfferLaborInput(event.target.value)} placeholder={String(assignmentOffer.labor_amount)} />
                      <Input type="number" min="0" step="1" value={offerRouteInput} onChange={(event) => setOfferRouteInput(event.target.value)} placeholder={String(assignmentOffer.route_fee_amount)} />
                      <Input value={offerNoteInput} onChange={(event) => setOfferNoteInput(event.target.value)} placeholder="Revize notu" />
                    </div>
                    <div className="flex justify-end">
                      <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                          const labor = parseNumericInput(offerLaborInput) ?? assignmentOffer.labor_amount
                          const route = parseNumericInput(offerRouteInput) ?? assignmentOffer.route_fee_amount

                          void onAssignmentOfferUpdate?.(assignmentOffer.id, {
                            labor_amount: labor,
                            route_fee_amount: route,
                            total_amount: roundTwo(labor + route),
                            note: offerNoteInput || null,
                          })
                        }}
                      >
                        Hakedişi revize et
                      </Button>
                    </div>
                  </div>
                ) : null}
                {!assignmentOffer && settlement ? (
                  <div className="grid gap-3 rounded-xl border border-emerald-100 bg-emerald-50 p-3 text-emerald-950">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="font-semibold">Hakediş mutabakatı</p>
                      <Badge variant={settlementNeedsAdminReview ? 'warning' : 'positive'}>{settlementStatusLabel(settlement.status)}</Badge>
                    </div>
                    <div className="grid gap-2 rounded-xl border border-white/70 bg-white/80 p-3 sm:grid-cols-4">
                      <MiniMetric label="Müşteriye bildirilecek ödeme" value={formatMoneyValue(settlement.customer_direct_to_technician_amount ?? null)} />
                      <MiniMetric label="Usta hakedişi" value={formatMoneyValue(settlement.technician_earning_total ?? null)} />
                      <MiniMetric label="Ustaya ödendi varsayılan" value={formatMoneyValue(settlement.customer_direct_assumed_paid_amount ?? null)} />
                      <MiniMetric label="Müşteri online tahsilat" value={formatMoneyValue(settlement.customer_collection_amount ?? null)} />
                      <MiniMetric label="Şirket ödemesi" value={formatMoneyValue(settlement.company_payable_amount ?? null)} />
                      <MiniMetric label="Şirket ödedi" value={formatMoneyValue(settlement.company_paid_amount ?? null)} />
                      <MiniMetric label="Şirket kalan" value={formatMoneyValue(settlement.company_remaining_amount ?? null)} />
                      <MiniMetric label="Fazla bildirim" value={formatMoneyValue(settlement.overpay_warning_amount ?? null)} />
                      <MiniMetric label="Settlement" value={settlementStatusLabel(settlement.status)} />
                      {settlementNeedsAdminReview ? (
                        <div className="grid gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950 sm:col-span-4">
                          <p className="font-semibold">Hakediş admin incelemesi gerekiyor</p>
                          <p>{settlement.review_reason || 'Müşteriye bildirilen tutar usta hakedişinden yüksek.'}</p>
                          <div className="grid gap-1 sm:grid-cols-4">
                            <span>Usta hakedişi: <strong>{formatMoneyValue(settlement.technician_earning_total ?? null)}</strong></span>
                            <span>Müşteriye bildirilen: <strong>{formatMoneyValue(settlement.customer_direct_to_technician_amount ?? null)}</strong></span>
                            <span>Fazla bildirim: <strong>{formatMoneyValue(settlement.overpay_warning_amount ?? null)}</strong></span>
                            <span>Şirket ödemesi: <strong>{formatMoneyValue(settlement.company_payable_amount ?? null)}</strong></span>
                          </div>
                        </div>
                      ) : null}
                      {settlementReviewResolved ? (
                        <div className="grid gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-950 sm:col-span-4">
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="font-semibold">Hakediş inceleme kararı</p>
                            <Badge variant="positive">{settlementReviewDecision?.decision_label ?? 'Karar verildi'}</Badge>
                          </div>
                          <p>{settlementReviewDecision?.reason || 'Admin incelemesi tamamlandı.'}</p>
                          <p>
                            {settlementReviewDecision?.reviewed_by_name ? `${settlementReviewDecision.reviewed_by_name} · ` : ''}
                            {dateTimeOrEmpty(settlementReviewDecision?.reviewed_at, 'Tarih yok')}
                          </p>
                        </div>
                      ) : null}
                    </div>
                  </div>
                ) : null}
              </div>
            ) : null}
            {!showAssignmentPortalActionBlock && !assignmentOffer && settlement ? (
              <div className="grid gap-3 rounded-xl border border-emerald-100 bg-emerald-50 p-3 text-emerald-950">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-semibold">Hakediş mutabakatı</p>
                  <Badge variant={settlementNeedsAdminReview ? 'warning' : 'positive'}>{settlementStatusLabel(settlement.status)}</Badge>
                </div>
                <div className="grid gap-2 rounded-xl border border-white/70 bg-white/80 p-3 sm:grid-cols-4">
                  <MiniMetric label="Müşteriye bildirilecek ödeme" value={formatMoneyValue(settlement.customer_direct_to_technician_amount ?? null)} />
                  <MiniMetric label="Usta hakedişi" value={formatMoneyValue(settlement.technician_earning_total ?? null)} />
                  <MiniMetric label="Ustaya ödendi varsayılan" value={formatMoneyValue(settlement.customer_direct_assumed_paid_amount ?? null)} />
                  <MiniMetric label="Müşteri online tahsilat" value={formatMoneyValue(settlement.customer_collection_amount ?? null)} />
                  <MiniMetric label="Şirket ödemesi" value={formatMoneyValue(settlement.company_payable_amount ?? null)} />
                  <MiniMetric label="Şirket ödedi" value={formatMoneyValue(settlement.company_paid_amount ?? null)} />
                  <MiniMetric label="Şirket kalan" value={formatMoneyValue(settlement.company_remaining_amount ?? null)} />
                  <MiniMetric label="Fazla bildirim" value={formatMoneyValue(settlement.overpay_warning_amount ?? null)} />
                  <MiniMetric label="Settlement" value={settlementStatusLabel(settlement.status)} />
                  {settlementNeedsAdminReview ? (
                    <div className="grid gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950 sm:col-span-4">
                      <p className="font-semibold">Hakediş admin incelemesi gerekiyor</p>
                      <p>{settlement.review_reason || 'Müşteriye bildirilen tutar usta hakedişinden yüksek.'}</p>
                      <div className="grid gap-1 sm:grid-cols-4">
                        <span>Usta hakedişi: <strong>{formatMoneyValue(settlement.technician_earning_total ?? null)}</strong></span>
                        <span>Müşteriye bildirilen: <strong>{formatMoneyValue(settlement.customer_direct_to_technician_amount ?? null)}</strong></span>
                        <span>Fazla bildirim: <strong>{formatMoneyValue(settlement.overpay_warning_amount ?? null)}</strong></span>
                        <span>Şirket ödemesi: <strong>{formatMoneyValue(settlement.company_payable_amount ?? null)}</strong></span>
                      </div>
                    </div>
                  ) : null}
                  {settlementReviewResolved ? (
                    <div className="grid gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-950 sm:col-span-4">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="font-semibold">Hakediş inceleme kararı</p>
                        <Badge variant="positive">{settlementReviewDecision?.decision_label ?? 'Karar verildi'}</Badge>
                      </div>
                      <p>{settlementReviewDecision?.reason || 'Admin incelemesi tamamlandı.'}</p>
                      <p>
                        {settlementReviewDecision?.reviewed_by_name ? `${settlementReviewDecision.reviewed_by_name} · ` : ''}
                        {dateTimeOrEmpty(settlementReviewDecision?.reviewed_at, 'Tarih yok')}
                      </p>
                    </div>
                  ) : null}
                </div>
              </div>
            ) : null}
            <details
              className="rounded-2xl border border-slate-200 bg-white p-3 text-sm text-slate-700"
              open={assignmentDetailsExpandedByDefault}
            >
              <summary className="cursor-pointer font-semibold text-slate-900">
                {hasAssignedTechnician ? 'Usta ve yol detayını aç' : 'Usta seçimi ve yol önerileri'}
              </summary>
              <div className="mt-3 grid gap-3 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
              <div className="grid gap-3 rounded-2xl border border-slate-200 bg-[#F8FAFD] p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-slate-900">Mesafe ve önceliğe göre önerilen ustalar</p>
                    {optionalMetricValue(customerOpenAddress) ? (
                      <p className="mt-1 max-w-3xl truncate text-xs text-slate-500" title={customerOpenAddress || undefined}>
                        Müşteri açık adresi: {customerOpenAddress}
                      </p>
                    ) : null}
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="outline">{technicianSuggestions.length > 0 ? `${technicianSuggestions.length} öneri` : 'Öneri yok'}</Badge>
                    {otherTechnicianCount > 0 ? (
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setOtherTechniciansModalOpenByRequest((current) => ({ ...current, [request.id]: true }))}
                      >
                        Diğer ustalar ({otherTechnicianCount})
                      </Button>
                    ) : null}
                  </div>
                </div>
                {topTechnicianSuggestions.length > 0 ? (
                  <div className="grid gap-2">
                    {topTechnicianSuggestions.map((technician) => renderTechnicianSuggestionCard(technician))}
                  </div>
                ) : (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <p className="font-semibold">Bu taleple aynı şehirde aktif usta bulunamadı.</p>
                    <p className="mt-1">Diğer / Yakın İlleri Göster ile farklı şehirlerdeki ustaları kontrol edin.</p>
                    <Button type="button" variant="outline" className="mt-3 border-amber-200 bg-white text-amber-900 hover:bg-amber-100" onClick={() => onAssign?.()}>
                      Diğer / Yakın İlleri Göster
                    </Button>
                  </div>
                )}
                {scheduleSupport ? (
                  <div className="grid gap-2 rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-600">
                    <span><strong className="text-slate-800">Kesin randevu:</strong> {scheduleSupport.scheduledLabel}</span>
                  </div>
                ) : null}
              </div>

              <div className="grid gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-3 text-sm text-blue-950">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-semibold">Usta yol hakedişi</p>
                  <div className="flex flex-wrap gap-2">
                    <Badge variant={routeFeeNeedsApproval ? 'warning' : hasRouteCostEvidence ? 'positive' : 'outline'}>{routeFeeStatusText}</Badge>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => void onRouteQuoteCalculate?.()}
                      disabled={shouldShowRouteQuoteLoading || !selectedTechnicianId || !onRouteQuoteCalculate}
                      className="border-blue-200 bg-white text-blue-800 hover:bg-blue-100"
                    >
                      {routeFeeCalculateButtonText}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={openRouteFeeEditor}
                      className="border-blue-200 bg-white text-blue-800 hover:bg-blue-100"
                    >
                      Usta hakedişi / yol düzenle
                    </Button>
                  </div>
                </div>
                {routeQuoteError ? (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                    {routeQuoteError}
                  </div>
                ) : null}
                {routeFeeEditorMessage ? (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                    {routeFeeEditorMessage}
                  </div>
                ) : null}
                {routeQuoteManualSaveError ? (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                    {routeQuoteManualSaveError}
                  </div>
                ) : null}
                {extraPaymentCreateError ? (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                    {extraPaymentCreateError}
                  </div>
                ) : null}
                {shouldShowRouteQuoteLoading ? (
                  <div className="rounded-xl border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-900">
                    Usta yol hakedişi hesaplanıyor...
                  </div>
                ) : null}
                {shouldShowRouteFeeNotCalculatedMessage ? (
                  <div className="rounded-xl border border-blue-200 bg-white px-3 py-2 text-xs text-blue-950">
                    <p className="font-semibold">{routeFeeNotCalculatedMessage}</p>
                    <p className="mt-1 text-blue-800">{routeFeeNotCalculatedHint}</p>
                  </div>
                ) : null}
                {routeSuspicious ? (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                    Rota mesafesi düz çizgi mesafesine göre yüksek. Konumlar kontrol edilmeli.
                  </div>
                ) : null}
                <div className="grid gap-2 sm:grid-cols-2">
                  <MiniMetric label="Usta adı" value={selectedTechnician?.name ?? '-'} />
                  <MiniMetric label="Usta ↔ müşteri düz çizgi mesafesi" value={formatKmValue(routeStraightLineKm)} hint="Bu değer rota ücreti hesabında kullanılmaz." />
                  <MiniMetric label="Google Routes tek yön mesafesi" value={formatKmValue(routeOneWayKm)} hint={hasActiveRouteQuote && activeRouteQuote?.duration_text ? `Tahmini süre: ${activeRouteQuote.duration_text}` : hasStoredRouteCost ? routeFeeSavedHint : 'Yol hesabı yapılınca gösterilir.'} />
                  <MiniMetric label="Gidiş-geliş mesafe" value={formatKmValue(routeRoundTripKm)} hint={hasRouteCostEvidence ? undefined : 'Yol hesabı sonucu yok.'} />
                  <MiniMetric
                    label="Tahmini usta yol hakedişi"
                    value={hasRouteCostEvidence ? routeFeeAmount === null && activeRouteQuote?.travel_fee_required ? 'Km başı ücret ayarı eksik' : formatMoneyValue(routeFeeAmount) : '-'}
                    hint={hasActiveRouteQuote && activeRouteQuote ? routeQuoteMessage(activeRouteQuote.message) : hasStoredRouteCost ? routeFeeSavedHint : routeFeeNotCalculatedHint}
                  />
                  <MiniMetric label="Km başı ücret" value={routeFeePerKm === null ? 'Km başı ücret ayarı eksik' : formatMoneyValue(routeFeePerKm)} />
                  <MiniMetric label="Ücretsiz sınır" value={formatKmValue(activeRouteQuote?.threshold_km ?? routeFeeConfigThresholdKm)} />
                </div>
                <div className="grid gap-2 rounded-2xl border border-blue-100 bg-white/70 p-3 text-xs text-blue-950 sm:grid-cols-2 lg:grid-cols-3">
                  <MiniMetric
                    label="Usta konumu"
                    value={selectedTechnicianCoordinateLabel !== '-' ? 'Haritada açılabilir' : 'Konum yok'}
                    hint={selectedTechnicianMapHref ? <a className="font-semibold text-blue-700 hover:underline" href={selectedTechnicianMapHref} target="_blank" rel="noreferrer">Usta konumunu haritada aç</a> : 'Gerçek koordinat yok'}
                  />
                  <MiniMetric
                    label="Müşteri konumu"
                    value={customerCoordinateLabel !== '-' ? 'Haritada açılabilir' : 'Konum yok'}
                    hint={customerMapHref ? <a className="font-semibold text-blue-700 hover:underline" href={customerMapHref} target="_blank" rel="noreferrer">Müşteri konumunu haritada aç</a> : 'Müşteri konumu yok'}
                  />
                </div>
                {activeRouteQuote ? (
                  <details className="rounded-2xl border border-blue-100 bg-white/70 p-3 text-xs text-blue-950">
                    <summary className="cursor-pointer font-semibold text-blue-900">Teknik detay</summary>
                    <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                      <MiniMetric label="Origin lat/lng" value={`${displayOrEmpty(String(activeRouteQuote.origin_latitude ?? ''), '-')}, ${displayOrEmpty(String(activeRouteQuote.origin_longitude ?? ''), '-')}`} />
                      <MiniMetric label="Destination lat/lng" value={`${displayOrEmpty(String(activeRouteQuote.destination_latitude ?? ''), '-')}, ${displayOrEmpty(String(activeRouteQuote.destination_longitude ?? ''), '-')}`} />
                      <MiniMetric label="Route quote id" value={displayOrEmpty(String(activeRouteQuote.id ?? ''), '-')} />
                      <MiniMetric label="Selected technician id" value={displayOrEmpty(String(selectedTechnicianId ?? ''), '-')} />
                      <MiniMetric label="Quote technician id" value={displayOrEmpty(String(activeRouteQuote.technician_id ?? ''), '-')} />
                      <MiniMetric label="Route source" value={displayOrEmpty(activeRouteQuote.source ?? activeRouteQuote.provider, '-')} />
                    </div>
                  </details>
                ) : null}
                {routeFeeEditorOpen && routeFeeEditorMode !== 'payment_link' ? (
                  <div className="grid gap-3 rounded-2xl border border-blue-200 bg-white p-3 text-sm text-slate-700">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <p className="font-semibold text-slate-950">
                          {routeFeeEditorMode === 'payment_link' ? paymentLinkModalTitle : 'Usta hakedişi / yol düzenle'}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                          {routeFeeEditorMode === 'payment_link'
                            ? 'İlk tıklama sadece bu pencereyi açar; ödeme linki yalnızca tutar onaylandıktan sonra oluşturulur.'
                            : 'Usta yol hakedişi ve ödeme linki tutarı ayrı alanlardır.'}
                        </p>
                      </div>
                      <Button type="button" size="sm" variant="ghost" onClick={() => setRouteFeeEditorOpen(false)}>
                        İptal
                      </Button>
                    </div>
                    {paymentLinkAmountWarning ? (
                      <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                        {paymentLinkAmountWarning}
                      </div>
                    ) : null}
                    {routeFeeEditorMode === 'payment_link' && paidOnlinePaymentLink ? (
                      <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900">
                        Ödenmiş kayıtlar salt okunur. Ek tahsilat gerekiyorsa yeni ödeme linki oluşturabilirsiniz.
                      </div>
                    ) : null}
                    {routeFeeEditorMode !== 'payment_link' && !selectedTechnician ? (
                      <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                        Önce usta seçin.
                      </div>
                    ) : null}
                    <div className="grid gap-2 sm:grid-cols-2">
                      <MiniMetric label="Seçili usta" value={displayOrEmpty(selectedTechnician?.name ?? request.technician, '-')} />
                      <MiniMetric label="Telefon" value={displayOrEmpty(selectedTechnician?.phone ?? request.technicianPhone, '-')} />
                      <MiniMetric label="Şehir" value={displayOrEmpty(selectedTechnician?.location, '-')} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                      <label className="grid gap-1 text-xs font-semibold text-slate-600">
                        Tek yön km
                        <Input type="number" min="0" step="0.01" value={routeFeeOneWayKmInput} onChange={(event) => handleRouteFeeOneWayChange(event.target.value)} />
                      </label>
                      <label className="grid gap-1 text-xs font-semibold text-slate-600">
                        Gidiş-geliş km
                        <Input type="number" min="0" step="0.01" value={routeFeeRoundTripKmInput} onChange={(event) => handleRouteFeeRoundTripChange(event.target.value)} />
                      </label>
                      <label className="grid gap-1 text-xs font-semibold text-slate-600">
                        Ücretsiz sınır km
                        <Input type="number" min="0" step="0.01" value={routeFeeThresholdKmInput} onChange={(event) => handleRouteFeeThresholdChange(event.target.value)} />
                      </label>
                      <label className="grid gap-1 text-xs font-semibold text-slate-600">
                        Km başı ücret
                        <Input type="number" min="0" step="0.01" value={routeFeePerKmInput} onChange={(event) => handleRouteFeePerKmChange(event.target.value)} />
                      </label>
                      <label className="grid gap-1 text-xs font-semibold text-slate-600">
                        Ücrete tabi km
                        <Input type="number" min="0" step="0.01" value={routeFeeBillableKmInput} onChange={(event) => handleRouteFeeBillableChange(event.target.value)} />
                      </label>
                      <label className="grid gap-1 text-xs font-semibold text-slate-600">
                        Usta yol hakedişi
                        <Input type="number" min="0" step="1" value={routeFeeAmountInput} onChange={(event) => handleRouteFeeAmountChange(event.target.value)} />
                      </label>
                      <label className="grid gap-1 text-xs font-semibold text-slate-600">
                        Ödeme linki tutarı
                        <Input type="number" inputMode="decimal" min="0" step="1" value={routeFeeExtraPaymentInput} onChange={(event) => setRouteFeeExtraPaymentInput(event.target.value)} />
                        <span className="font-medium text-slate-500">{paymentLinkAmountSourceLabel}</span>
                      </label>
                    </div>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Not
                      <textarea
                        value={routeFeeNote}
                        onChange={(event) => setRouteFeeNote(event.target.value)}
                        className="min-h-[84px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                        placeholder="Usta yol hakedişi veya müşteri onayı için operasyon notu"
                      />
                    </label>
                    {paymentLinkCopyUrl(extraMountPayment) ? (
                      <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-950">
                        <p className="font-semibold">
                          {extraMountPayment.status === 'paid' ? 'Ödeme onaylandı' : `${paymentProviderLabel(extraMountPayment)} ödeme linki`}
                        </p>
                        <p className="mt-1 break-all">{paymentLinkCopyUrl(extraMountPayment)}</p>
                        {extraMountPayment.payment_action_kind === 'open_provider_url' ? (
                          <p className="mt-1 text-emerald-800">Iyzico Sandbox ödeme ekranı açılacak. Ödeme yapıldıktan sonra durum kontrolü/reconciliation ile güncellenecek.</p>
                        ) : null}
                        <div className="mt-2 flex flex-wrap gap-2">
                        <Button asChild type="button" size="sm" variant="outline">
                          <a href={paymentLinkCopyUrl(extraMountPayment)} target="_blank" rel="noreferrer">
                            Ödeme linkini aç
                          </a>
                        </Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => void copyPaymentLinkValue(paymentLinkCopyUrl(extraMountPayment))}>
                          Linki kopyala
                        </Button>
                        {renderPaymentLinkSendAction(extraMountPayment)}
                          {extraMountPayment.is_external_provider && extraMountPayment.status === 'pending' ? (
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              disabled={paymentSyncInFlight === extraMountPayment.id}
                              onClick={() => void handlePendingPaymentSync(extraMountPayment)}
                            >
                              {paymentSyncInFlight === extraMountPayment.id ? 'Kontrol ediliyor...' : 'Durumu Kontrol Et'}
                            </Button>
                          ) : null}
                        </div>
                      </div>
                    ) : null}
                    <div className="flex flex-wrap justify-end gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        onClick={() => void handleExtraPaymentCreate()}
                        disabled={!canCreateExtraPayment || extraPaymentCreateLoading}
                      >
                        {paymentLinkSubmitLabel}
                      </Button>
                      <Button type="button" variant="secondary" onClick={() => setRouteFeeEditorOpen(false)}>İptal</Button>
                      <Button type="button" onClick={() => void handleRouteFeeManualSave()} disabled={!selectedTechnician || !routeFeeEditorHasChanges || routeQuoteManualSaveLoading || !onRouteQuoteManualSave}>
                        {routeQuoteManualSaveLoading ? 'Kaydediliyor...' : 'Kaydet'}
                      </Button>
                    </div>
                  </div>
                ) : null}
              </div>
              </div>
            </details>
            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-3">
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-sm font-semibold text-slate-950">{financeSummaryTitle} — {earningSummaryTechnicianName}</p>
                  <span className="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">
                    {locksmithPayoutStatusLabel}
                  </span>
                </div>
                <p className="mt-1 text-xs text-slate-500">{financeSummaryHint}</p>
                {financeCurrentVisit?.warranty_note ? (
                  <p className="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                    {financeCurrentVisit.warranty_note}
                    {financeCurrentVisit.operation_cost_note ? ` · ${financeCurrentVisit.operation_cost_note}` : ''}
                  </p>
                ) : null}
              </div>
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {showFinanceCollectionMetrics && (hasMountCustomerPayment || showPaymentControl) ? (
                  <MiniMetric label="Müşteriden alınan montaj ücreti" value={mountPaymentLabel} />
                ) : null}
                {showFinanceCollectionMetrics && hasServiceCustomerPayment ? (
                  <MiniMetric label="Müşteriden alınan servis ücreti" value={formatMoneyValue(paidServiceCustomerAmount)} />
                ) : null}
                {showFinanceCollectionMetrics && hasPartCustomerPayment ? (
                  <MiniMetric label="Müşteriden alınan parça ücreti" value={formatMoneyValue(paidPartCustomerAmount)} />
                ) : null}
                {showFinanceCollectionMetrics && hasExtraCustomerPayment ? (
                  <MiniMetric label="Müşteriden alınan ek ödeme" value={formatMoneyValue(paidExtraCustomerAmount)} />
                ) : null}
                {showFinanceCollectionMetrics ? (
                  <MiniMetric label="Toplam müşteri tahsilatı" value={totalCustomerCollectionDisplayLabel} />
                ) : null}
                {showFinanceCollectionMetrics && showPaymentControl ? (
                  <MiniMetric label="Montaj ödeme durumu" value={resolvedMountPaymentLabel} />
                ) : null}
                <MiniMetric label="Usta işçilik hakedişi" value={technicianLaborCostLabel} />
                <MiniMetric label="Usta yol hakedişi" value={travelCostLabel} />
                <MiniMetric label={locksmithPayoutTotalMetricLabel} value={earningTotalAmount !== null ? formatMoneyValue(earningTotalAmount) : totalTechnicianCostLabel} />
                {showFinanceCollectionMetrics ? (
                  <MiniMetric label={netDifferenceMetricLabel} value={netProfitLabel} />
                ) : null}
                <MiniMetric
                  label="Hakediş statüsü"
                  value={locksmithPayoutStatusLabel}
                  hint={technicianEarningMessage?.sent_at || activeAssignmentOffer?.sent_at
                    ? `Mesaj: ${earningDispatchStatusLabel} · ${dateTimeOrEmpty(technicianEarningMessage?.sent_at ?? activeAssignmentOffer?.sent_at, '-')}`
                    : `Mesaj: ${earningDispatchStatusLabel}`}
                />
                <MiniMetric
                  label="Ödeme durumu"
                  value={locksmithPayoutPaymentStatusLabel}
                  hint={locksmithPayoutPaidAt ? `Ödeme tarihi: ${dateTimeOrEmpty(locksmithPayoutPaidAt, '-')}` : 'Ödeme onayı sadece hakediş ödeme kaydı varsa gösterilir.'}
                />
              </div>
              {showFinanceCollectionMetrics && earningBreakdown?.root_total ? (
                <div className="grid gap-2 rounded-2xl border border-emerald-100 bg-white p-3 text-xs text-slate-700">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-semibold text-slate-950">MRN / SRV hakediş kırılımı</p>
                    <p className="font-semibold text-emerald-700">
                      Toplam: {earningBreakdown.root_total.total_amount_label ?? formatMoneyValue(earningBreakdown.root_total.total_amount)}
                      {earningBreakdown.root_total.is_multi_technician ? ` (${earningBreakdown.root_total.technician_count ?? earningBreakdown.root_total.technician_names?.length ?? 0} usta toplamı)` : ''}
                    </p>
                  </div>
                  {earningBreakdown.root_total.is_multi_technician ? (
                    <p className="rounded-xl bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                      Bu toplam birden fazla ustanın MRN/SRV hakedişlerini içerir: {(earningBreakdown.root_total.technician_names ?? []).join(', ')}
                    </p>
                  ) : null}
                  {showFinanceCollectionMetrics && financeRootTotal ? (
                    <div className="grid gap-2 rounded-xl bg-slate-50 px-3 py-2 sm:grid-cols-3">
                      <span>Müşteri tahsilatı: <strong>{financeRootCustomerCollectionDisplayLabel}</strong></span>
                      <span>Usta hakedişi: <strong>{financeRootTotal.locksmith_payout.total_amount_label ?? formatMoneyValue(financeRootTotal.locksmith_payout.total_amount)}</strong></span>
                      <span>Net fark: <strong>{financeRootTotal.net_margin.amount_label ?? formatMoneyValue(financeRootTotal.net_margin.amount)}</strong></span>
                    </div>
                  ) : null}
                  <div className="grid gap-1">
                    {earningBreakdown.rows.map((row) => (
                      <div key={`${row.id}-${row.mrn}`} className="grid gap-2 rounded-xl bg-slate-50 px-3 py-2 sm:grid-cols-[minmax(0,1fr)_110px_110px_110px]">
                        <span className="min-w-0">
                          <span className="block truncate font-semibold">{row.kind_label ?? 'İş'} - {row.display_mrn ?? row.mrn}{row.is_current ? ' (açık detay)' : ''}</span>
                          <span className="mt-0.5 block truncate text-[11px] font-semibold text-slate-500">Usta: {displayOrEmpty(row.technician_name, 'Usta bilgisi yok')}</span>
                        </span>
                        <span>İşçilik: {row.labor_amount_label ?? formatMoneyValue(row.labor_amount)}</span>
                        <span>Yol: {row.route_fee_amount_label ?? formatMoneyValue(row.route_fee_amount)}</span>
                        <strong>Toplam: {row.total_amount_label ?? formatMoneyValue(row.total_amount)}</strong>
                      </div>
                    ))}
                  </div>
                </div>
              ) : null}
              {technicianEarningMessageError ? (
                <div className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                  {technicianEarningMessageError}
                </div>
              ) : null}
              {!isCancelledOrReviewContext ? (
              <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div className="grid gap-3 sm:grid-cols-[220px_minmax(0,1fr)]">
                  <label className="grid gap-1 text-xs font-semibold text-slate-600">
                    Toplam hakediş
                    <Input
                      type="number"
                      inputMode="decimal"
                      min="0"
                      step="1"
                      value={earningTotalOverrideTouched ? earningTotalOverride : earningTotalAmount !== null ? numericInputValue(earningTotalAmount) : ''}
                      onChange={(event) => {
                        const nextValue = event.target.value
                        setEarningTotalOverrideByRequest((current) => ({ ...current, [requestStateKey]: nextValue }))
                        setEarningTotalOverrideTouchedByRequest((current) => ({ ...current, [requestStateKey]: true }))
                      }}
                      placeholder={totalTechnicianCostAmount !== null ? String(totalTechnicianCostAmount) : '0'}
                    />
                  </label>
                  <label className="grid gap-1 text-xs font-semibold text-slate-600">
                    Hakediş notu / mesaj
                    <Input
                      value={earningNoteInput}
                      onChange={(event) => setEarningNoteInput(event.target.value)}
                      placeholder="Ustaya gönderilecek mesaj için operasyon notu"
                    />
                  </label>
                </div>
                {displayedEarningMessageText ? (
                  <details className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-950">
                    <summary className="cursor-pointer font-semibold">Hakediş mesajını göster</summary>
                    <pre className="mt-3 whitespace-pre-wrap break-words font-sans">{displayedEarningMessageText}</pre>
                    <div className="mt-2 flex flex-wrap gap-2">
                      <Button type="button" size="sm" variant="outline" onClick={() => void navigator.clipboard?.writeText(displayedEarningMessageText)}>
                        Mesajı kopyala
                      </Button>
                      {displayedEarningWhatsappUrl ? (
                        <Button asChild type="button" size="sm" variant="outline">
                          <a href={displayedEarningWhatsappUrl} target="_blank" rel="noreferrer">
                            WhatsApp Aç
                          </a>
                        </Button>
                      ) : null}
                      {assignmentOfferJobLink ? (
                        <Button asChild type="button" size="sm" variant="outline">
                          <a href={assignmentOfferJobLink} target="_blank" rel="noreferrer">
                            İş kartını aç
                          </a>
                        </Button>
                      ) : null}
                    </div>
                  </details>
                ) : null}
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-xs text-slate-500">
                    Ödeme onayı hakedişi otomatik gönderilmiş saymaz; bu mesaj ayrı kaydedilir.
                  </p>
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => void handleTechnicianEarningMessageCreate()}
                    disabled={!canSendTechnicianEarning || technicianEarningMessageLoading}
                  >
                    {technicianEarningMessageLoading ? 'Hazırlanıyor...' : 'Hakediş bilgisini gönder'}
                  </Button>
                </div>
              </div>
              ) : null}
            </div>
            {!isCancelledOrReviewContext && combinedAssignmentBlockerMessages.length > 0 ? (
              <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                <p className="font-semibold">Atama için tamamlanması gerekenler</p>
                <ul className="mt-2 list-disc space-y-1 pl-5">
                  {combinedAssignmentBlockerMessages.map((message) => (
                    <li key={message}>{message}</li>
                  ))}
                </ul>
              </div>
            ) : null}
            {!isCancelledOrReviewContext ? (
            <div className="flex flex-wrap justify-end gap-2">
              {hasAssignmentChange ? (
                <Badge variant="warning">Atama değişikliği hazır</Badge>
              ) : null}
              <Button
                type="button"
                variant="outline"
                onClick={() => onAssign?.()}
              >
                Gelişmiş atama ayarları
              </Button>
              <Button
                type="button"
                onClick={() => void onAssignSelectedTechnician?.()}
                disabled={assignmentSubmitDisabled}
                title={isAssignmentBlocked ? combinedAssignmentBlockerMessages.join(' ') : undefined}
              >
                {assignLoading ? 'Kaydediliyor...' : hasAssignedTechnician ? 'Atamayı Güncelle' : 'Servis Ata'}
              </Button>
            </div>
            ) : null}
            <div className="grid gap-3 sm:grid-cols-2">
              {hasAssignedTechnician && optionalMetricValue(request.technician) ? <MiniMetric label="Atanan servis" value={optionalMetricValue(request.technician)} /> : null}
              {hasAssignedTechnician && optionalMetricValue(request.technicianPhone) ? <MiniMetric label="Servis telefonu" value={optionalMetricValue(request.technicianPhone)} /> : null}
              {hasAssignedTechnician && optionalMetricValue(selectedTechnician?.location ?? request.city) ? <MiniMetric label="Şehir" value={optionalMetricValue(selectedTechnician?.location ?? request.city)} /> : null}
              <MiniMetric label="Usta yol hakedişi durumu" value={routeFeeStatusText} />
              <MiniMetric label="Tahmini usta yol hakedişi" value={travelCostLabel} />
              {hasAssignedTechnician && approvalState.title.toLocaleLowerCase('tr-TR').includes('bek') ? (
                <div className="sm:col-span-2">
                  <Badge variant="warning">Usta onayı bekleniyor</Badge>
                </div>
              ) : null}
              {hasAssignedTechnician ? (
                <>
                  <MiniMetric label="Servis onay durumu" value={approvalState.title} hint={approvalState.detail ?? undefined} />
                  {hasSupportRequestDetail ? (
                    <MiniMetric label="Destek talebi" value={displayOrEmpty(request.technicianRevisionNote || request.pendingReason, 'İnceleniyor')} />
                  ) : null}
                  {hasSparePartDetail ? (
                    <MiniMetric label="Yedek parça" value={partRequests.length > 0 ? `${partRequests.length} talep` : displayOrEmpty(request.pendingReason, 'İnceleniyor')} />
                  ) : null}
                  {hasPriceRevisionDetail ? (
                    <MiniMetric label="Fiyat değişikliği" value={displayOrEmpty(request.technicianRevisionNote, 'Revize talebi var')} />
                  ) : null}
                  {hasRevisitDetail ? (
                    <MiniMetric label="Tekrar ziyaret" value={request.requiresSecondVisit ? 'Evet' : `${revisitRequests.length} talep`} hint={request.secondVisitReason || undefined} />
                  ) : null}
                </>
              ) : (
                <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600 sm:col-span-2">
                  Servis atanınca onay, destek, yedek parça ve fiyat talepleri burada görünür.
                </div>
              )}
            </div>
          </DetailPanel>

          <DetailPanel
            title="Operasyon Geçmişi / Notlar"
            summary="Operasyon onayı, karar alanı, not ve yorum özeti"
            tone="history"
            open={finalCheckOpen}
            onOpenChange={setFinalCheckOpen}
            panelRef={finalCheckRef}
            sectionTarget="finalCheck"
            highlighted={highlightedNextActionTarget === 'finalCheck'}
            className={opsSectionClass('finalCheck', activeOpsSection)}
          >
            <div className="flex flex-wrap items-start justify-between gap-3">
              <p className="text-sm text-slate-600">Son kontrol kararları ve operasyon notları burada özetlenir.</p>
              {!isActionDisabled ? (
              <Button
                type="button"
                variant="outline"
                className="border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 hover:text-rose-800"
                onClick={() => onComplete?.()}
              >
                İşi İptal Et
              </Button>
              ) : null}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <MiniMetric label="Operasyon onayı" value={closureApprovalLabel} hint={dateTimeOrEmpty(request.customerClosureApprovedAt, 'Bekliyor')} />
              <MiniMetric label="Tamamlandı / İnceleniyor / İptal" value={currentStatusLabel} />
              <MiniMetric label="Not / yorum alanı" value={notesLabel} />
              {shouldRenderHistoryPanel ? <MiniMetric label="İşlem geçmişi" value="Var" hint={`${(request.auditLogs ?? []).length} audit / ${sortedEvents.length} olay`} /> : null}
              {shouldShowFinalReasonMetrics && request.pendingReason ? <MiniMetric label="Bekleme nedeni" value={request.pendingReason} /> : null}
              {shouldShowFinalReasonMetrics && request.cancellationReason ? <MiniMetric label="İptal nedeni" value={request.cancellationReason} /> : null}
            </div>
          </DetailPanel>
        {shouldRenderInvoiceSerialsPanel ? (
        <DetailPanel
          title="Diğer serileri kontrol et"
          summary={invoiceSerials?.check_error ? 'Fatura seri kontrolü bekliyor' : 'Talep edilen, seçilebilir, gizlenen ve iade seri hareketleri'}
          tone="serial"
          open={invoiceSerialsOpen}
          onOpenChange={setInvoiceSerialsOpen}
          className={opsSectionClass('invoiceSerials', activeOpsSection)}
        >
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-sm text-slate-600">Diğer serileri kontrol et; uygun olanları aynı montaj kapsamına ekle, gizli ve iade satırları ayrı takip et.</p>
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => onInvoiceSerialAddAll?.()}
                disabled={invoiceSerialActionInFlight === 'add-all' || !onInvoiceSerialAddAll || (invoiceSerials?.addable_serial_count ?? 0) === 0}
                className="border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100"
              >
                {invoiceSerialActionInFlight === 'add-all' ? 'Ekleniyor...' : 'Tüm uygun serileri montaja ekle'}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => onInvoiceSerialRecheck?.()}
                disabled={invoiceSerialRecheckInFlight || !onInvoiceSerialRecheck}
              >
                {invoiceSerialRecheckInFlight ? 'Kontrol ediliyor...' : 'Serileri kontrol et'}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => setDifferentAddressInfoOpen((current) => !current)}
              >
                Farklı adres için yeni talep oluştur
              </Button>
            </div>
          </div>
          <div className="flex flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-3 sm:flex-row sm:items-center">
            <Input
              value={invoiceSerialSearch}
              onChange={(event) => setInvoiceSerialSearch(event.target.value)}
              placeholder="Seri, ürün, model, marka veya fatura ara"
              className="sm:max-w-md"
            />
            {invoiceSerialSearchActive ? (
              <Button
                type="button"
                variant="outline"
                onClick={() => setInvoiceSerialSearch('')}
                className="sm:w-auto"
              >
                Aramayı temizle
              </Button>
            ) : null}
          </div>
          {differentAddressInfoOpen ? (
            <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm font-semibold text-blue-900">
              Seçili seriler için farklı adres talebi sonraki fazda açılacak.
            </div>
          ) : null}
          {invoiceSerialRecheckError ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
              {invoiceSerialRecheckError}
            </div>
          ) : null}
          {invoiceSerialActionError ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
              {invoiceSerialActionError}
            </div>
          ) : null}
          {invoiceSerials?.check_error ? (
            <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">
              Fatura seri kontrolü bekliyor. Tekrar kontrol et aksiyonu ile sorgu yenilenebilir.
            </div>
          ) : null}
          {showInvoiceSerialNoSearchResult ? (
            <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
              Bu aramada seri bulunamadı. Serileri kontrol et ile Mikro sorgusunu yenileyin.
            </div>
          ) : null}
          <InvoiceSerialSection title="Talep edilen seriler" items={filteredRequestedInvoiceSerials} totalCount={invoiceSerials?.selected_serial_count} searchActive={invoiceSerialSearchActive} onAdd={onInvoiceSerialAdd} onRemove={onInvoiceSerialRemove} actionInFlight={invoiceSerialActionInFlight} />
          <InvoiceSerialSection title="Aynı faturadaki diğer seriler" items={filteredOtherInvoiceSerials} totalCount={invoiceSerials?.other_serial_count} searchActive={invoiceSerialSearchActive} onAdd={onInvoiceSerialAdd} onRemove={onInvoiceSerialRemove} actionInFlight={invoiceSerialActionInFlight} />
          <InvoiceSerialSection title="Müşteriye gösterilmeyen seriler" items={filteredHiddenInvoiceSerials} totalCount={invoiceSerials?.hidden_serial_count} searchActive={invoiceSerialSearchActive} onAdd={onInvoiceSerialAdd} onRemove={onInvoiceSerialRemove} actionInFlight={invoiceSerialActionInFlight} />
          <InvoiceSerialSection title="İade gelen seriler" items={filteredReturnedInvoiceSerials} totalCount={invoiceSerials?.returned_serial_count} searchActive={invoiceSerialSearchActive} onAdd={onInvoiceSerialAdd} onRemove={onInvoiceSerialRemove} actionInFlight={invoiceSerialActionInFlight} />
          {allSearchableInvoiceSerials.length === 0 && !invoiceSerials?.check_error ? (
            <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
              Fatura seri hareketi henüz kaydedilmedi.
            </div>
          ) : null}
        </DetailPanel>
        ) : null}

        <DetailPanel
          title="Saha Tamamlama Belgeleri"
          summary="Öncesi, sonrası, garanti belgesi ve müşteri onayı"
          tone="door"
          open={fieldCompletionOpen}
          onOpenChange={setFieldCompletionOpen}
          panelRef={fieldCompletionRef}
          sectionTarget="fieldCompletion"
          highlighted={highlightedNextActionTarget === 'fieldCompletion'}
          className={opsSectionClass('fieldCompletion', activeOpsSection)}
        >
          <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 lg:p-5">
            <div className="grid gap-3 sm:grid-cols-2">
              <MiniMetric
                label="Saha belgeleri"
                value={photoCompletionLabel}
                hint={isActionDisabled ? 'Salt okunur belge özeti' : missingFieldDocumentLabels.length > 0 ? `${missingFieldDocumentLabels.join(', ')} bekliyor` : 'Tamam'}
              />
              <MiniMetric label="Müşteri onayı" value={closureApprovalLabel} hint={dateTimeOrEmpty(request.customerClosureApprovedAt, 'Bekliyor')} />
              <MiniMetric label="Usta açıklaması" value={displayOrEmpty(request.fieldCompletionNote, 'Bilgi yok')} />
              <MiniMetric label="Eksik / hatalı evrak" value={displayOrEmpty(request.completionBlockReason || request.incompleteReason, 'Yok')} />
              <MiniMetric
                label="Backend kontrol"
                value={backendControlComplete ? 'Tamam' : checklistTotalCount > 0 ? `${checklistCompletedCount}/${checklistTotalCount} tamam` : 'Bekliyor'}
                hint={backendControlComplete ? 'Backend kontrol tamam' : checklistTotalCount > 0 ? `${checklistMissingCount} eksik adım` : 'Checklist bu işte henüz tamamlanmadı'}
              />
            </div>
            {onCustomerApprovalResend ? (
              <div className="flex flex-col gap-3 rounded-2xl border border-violet-100 bg-violet-50 p-3 text-sm text-violet-950 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="font-semibold">Müşteri onayı</p>
                  <p className="mt-1 text-xs text-violet-800">
                    {latestCustomerApprovalDispatchStatus === 'sent'
                      ? 'Son onay mesajı gönderildi.'
                      : latestCustomerApprovalDispatchStatus !== ''
                        ? 'Son onay mesajı gönderilemedi veya bastırıldı.'
                        : 'Gerekirse yeni onay linki oluşturup müşteriye tekrar gönderin.'}
                  </p>
                  {latestCustomerApprovalRequest?.created_at ? (
                    <p className="mt-1 text-[11px] font-semibold text-violet-700">
                      Son istek: {formatTechnicalServiceDateTime(latestCustomerApprovalRequest.created_at, 'Bilinmiyor')}
                    </p>
                  ) : null}
                  {customerApprovalModalOpen && latestCustomerApprovalUrl ? (
                    <div className="mt-3 grid gap-2 rounded-xl border border-violet-100 bg-white p-3">
                      <p className="text-xs font-semibold text-violet-900">Onay linki</p>
                      <input
                        readOnly
                        value={latestCustomerApprovalUrl}
                        className="w-full rounded-lg border border-violet-100 bg-violet-50 px-3 py-2 text-xs text-violet-950"
                      />
                      <div className="flex flex-wrap gap-2">
                        <Button type="button" size="sm" variant="outline" onClick={() => void copyCustomerApprovalValue(latestCustomerApprovalUrl, 'Link kopyalandı.')}>
                          Onay linkini kopyala
                        </Button>
                        <Button asChild type="button" size="sm" variant="outline">
                          <a href={latestCustomerApprovalUrl} target="_blank" rel="noreferrer">
                            Onay linkini aç
                          </a>
                        </Button>
                        {latestCustomerApprovalWhatsappUrl ? (
                          <Button asChild type="button" size="sm" variant="outline">
                            <a href={latestCustomerApprovalWhatsappUrl} target="_blank" rel="noreferrer">
                              WhatsApp mesajını aç
                            </a>
                          </Button>
                        ) : null}
                      </div>
                    </div>
                  ) : null}
                  {customerApprovalModalOpen && latestCustomerApprovalMessageText ? (
                    <div className="mt-3 grid gap-2 rounded-xl border border-violet-100 bg-white p-3">
                      <p className="text-xs font-semibold text-violet-900">WhatsApp mesaj metni</p>
                      <textarea
                        readOnly
                        value={latestCustomerApprovalMessageText}
                        className="min-h-24 w-full rounded-lg border border-violet-100 bg-violet-50 px-3 py-2 text-xs text-violet-950"
                      />
                      <div className="flex flex-wrap gap-2">
                        <Button type="button" size="sm" variant="outline" onClick={() => void copyCustomerApprovalValue(latestCustomerApprovalMessageText, 'Mesaj metni kopyalandı.')}>
                          Mesaj metnini kopyala
                        </Button>
                      </div>
                    </div>
                  ) : null}
                  {customerApprovalCopyMessage ? (
                    <p className="mt-2 text-xs font-semibold text-violet-800">{customerApprovalCopyMessage}</p>
                  ) : null}
                  {customerApprovalResendError ? (
                    <p className="mt-2 text-xs font-semibold text-rose-700">{customerApprovalResendError}</p>
                  ) : null}
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={customerApprovalResendLoading}
                  onClick={() => setCustomerApprovalModalOpen(true)}
                  className="border-violet-200 bg-white text-violet-800 hover:bg-violet-100"
                >
                  Müşteri onayını tekrar gönder
                </Button>
              </div>
            ) : null}
            {onCustomerApprovalResend && customerApprovalModalOpen ? (
              <div className="fixed inset-0 z-[80] flex items-end justify-center bg-slate-950/50 p-3 sm:items-center" role="dialog" aria-modal="true" aria-label="Müşteri onayı / OTP">
                <div className="max-h-[92dvh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-violet-100 bg-white p-4 shadow-2xl">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-base font-semibold text-slate-950">Müşteri onayı / OTP</p>
                      <p className="mt-1 text-xs text-slate-600">Onay linki, WhatsApp mesajı ve tekrar gönderme aksiyonları bu pencerede tutulur.</p>
                    </div>
                    <Button type="button" size="sm" variant="ghost" onClick={() => setCustomerApprovalModalOpen(false)}>
                      Kapat
                    </Button>
                  </div>
                  <div className="mt-4 grid gap-3">
                    {latestCustomerApprovalUrl ? (
                      <div className="grid gap-2 rounded-xl border border-violet-100 bg-violet-50 p-3">
                        <p className="text-xs font-semibold text-violet-900">Onay linki</p>
                        <input
                          readOnly
                          value={latestCustomerApprovalUrl}
                          className="w-full rounded-lg border border-violet-100 bg-white px-3 py-2 text-xs text-violet-950"
                        />
                        <div className="flex flex-wrap gap-2">
                          <Button type="button" size="sm" variant="outline" onClick={() => void copyCustomerApprovalValue(latestCustomerApprovalUrl, 'Link kopyalandı.')}>
                            Onay linkini kopyala
                          </Button>
                          <Button asChild type="button" size="sm" variant="outline">
                            <a href={latestCustomerApprovalUrl} target="_blank" rel="noreferrer">
                              Onay linkini aç
                            </a>
                          </Button>
                        </div>
                      </div>
                    ) : (
                      <div className="rounded-xl border border-amber-100 bg-amber-50 p-3 text-sm text-amber-900">
                        Henüz aktif onay linki yok. Onay mesajını tekrar göndererek yeni link oluşturun.
                      </div>
                    )}
                    {latestCustomerApprovalMessageText ? (
                      <div className="grid gap-2 rounded-xl border border-violet-100 bg-violet-50 p-3">
                        <p className="text-xs font-semibold text-violet-900">WhatsApp mesaj metni</p>
                        <textarea
                          readOnly
                          value={latestCustomerApprovalMessageText}
                          className="min-h-32 w-full rounded-lg border border-violet-100 bg-white px-3 py-2 text-xs text-violet-950"
                        />
                        <div className="flex flex-wrap gap-2">
                          <Button type="button" size="sm" variant="outline" onClick={() => void copyCustomerApprovalValue(latestCustomerApprovalMessageText, 'Mesaj metni kopyalandı.')}>
                            Mesaj metnini kopyala
                          </Button>
                          {latestCustomerApprovalWhatsappUrl ? (
                            <Button asChild type="button" size="sm" variant="outline">
                              <a href={latestCustomerApprovalWhatsappUrl} target="_blank" rel="noreferrer">
                                WhatsApp mesajını aç
                              </a>
                            </Button>
                          ) : null}
                        </div>
                      </div>
                    ) : null}
                    {customerApprovalCopyMessage ? (
                      <p className="text-xs font-semibold text-violet-800">{customerApprovalCopyMessage}</p>
                    ) : null}
                    {customerApprovalResendError ? (
                      <p className="text-xs font-semibold text-rose-700">{customerApprovalResendError}</p>
                    ) : null}
                    <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3">
                      <Button type="button" variant="outline" size="sm" onClick={() => setCustomerApprovalModalOpen(false)}>
                        Vazgeç
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        disabled={customerApprovalResendLoading}
                        onClick={() => void onCustomerApprovalResend({ note: 'Operasyon müşteri onay linkini tekrar gönderdi.' })}
                        className="bg-violet-700 text-white hover:bg-violet-800"
                      >
                        {customerApprovalResendLoading ? 'Gönderiliyor...' : 'Onay mesajını tekrar gönder'}
                      </Button>
                    </div>
                  </div>
                </div>
              </div>
            ) : null}
            {finalCheckCompletionAction ? (
              <div className="grid gap-3 rounded-2xl border border-violet-200 bg-violet-50 p-3 text-violet-950">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p className="text-sm font-semibold">Son kontrol tamamlanabilir</p>
                    <p className="mt-1 text-xs text-violet-800">
                      Ustanın tamamlama gönderimi ops son kontrolünde. Eksik varsa aşağıda net görünür.
                    </p>
                  </div>
                  <Badge variant={finalCompletionMissingReasons.length === 0 ? 'secondary' : 'warning'}>
                    {finalCompletionMissingReasons.length === 0 ? 'Tamamlamaya hazır' : 'Eksik kontrol var'}
                  </Badge>
                </div>
                {finalCompletionMissingReasons.length > 0 ? (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900">
                    {finalCompletionMissingReasons.map((reason) => (
                      <p key={reason}>- {reason}</p>
                    ))}
                  </div>
                ) : null}
                {finalPayoutApprovalRequired ? (
                  <div className="grid gap-2 rounded-2xl border border-violet-200 bg-white p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div>
                        <p className="text-sm font-semibold text-slate-950">İş bazlı hakediş onayı</p>
                        <p className="mt-1 text-xs text-slate-600">MRN altında birden fazla SRV var. Hakedişe dahil edilecek işleri işaretleyin.</p>
                      </div>
                      <Badge variant={finalPayoutSelectedRows.length > 0 ? 'secondary' : 'warning'}>
                        {finalPayoutSelectedRows.length} iş seçili
                      </Badge>
                    </div>
                    <div className="grid gap-2">
                      {finalPayoutRows.map((row) => {
                        const rowId = String(row.id)
                        const checked = finalPayoutSelectedSet.has(rowId)

                        return (
                          <label key={`${row.id}-${row.mrn}`} className={`grid cursor-pointer gap-2 rounded-xl border px-3 py-2 text-xs sm:grid-cols-[auto_minmax(0,1fr)_90px_90px_100px] ${checked ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-slate-200 bg-slate-50 text-slate-600'}`}>
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={() => toggleFinalPayoutRow(row.id)}
                              className="mt-1 h-4 w-4 rounded border-slate-300"
                            />
                            <span className="min-w-0">
                              <span className="block truncate font-semibold">{row.kind_label ?? 'İş'} - {row.display_mrn ?? row.mrn}</span>
                              <span className="block truncate text-[11px]">Usta: {displayOrEmpty(row.technician_name, 'Usta bilgisi yok')}</span>
                            </span>
                            <span>İşçilik: {row.labor_amount_label ?? formatMoneyValue(row.labor_amount)}</span>
                            <span>Yol: {row.route_fee_amount_label ?? formatMoneyValue(row.route_fee_amount)}</span>
                            <strong>Toplam: {row.total_amount_label ?? formatMoneyValue(row.total_amount)}</strong>
                          </label>
                        )
                      })}
                    </div>
                    <p className="text-xs font-semibold text-violet-900">
                      Onaylanacak hakediş toplamı: {formatMoneyValue(finalPayoutSelectedTotal)}
                    </p>
                  </div>
                ) : null}
                <label className="grid gap-1 text-xs font-semibold text-violet-900">
                  Son kontrol notu
                  <Input value={completionReviewNote} onChange={(event) => setCompletionReviewNote(event.target.value)} placeholder="Operasyon son kontrol notu" />
                </label>
              </div>
            ) : null}
            {onFieldDocumentReview ? (
              <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Saha belgeleri genel uygunluk</p>
                    <p className="mt-1 text-sm text-slate-600">Öncesi, sonrası ve garanti belgesi tek karar olarak değerlendirilir.</p>
                  </div>
                  <Badge variant={fieldDocumentOverallReviewStatus === 'accepted' ? 'secondary' : fieldDocumentOverallReviewStatus === 'rejected' ? 'destructive' : 'outline'}>
                    {fieldDocumentOverallReviewLabel}
                  </Badge>
                </div>
                {fieldDocumentRejectedNotes.length > 0 ? (
                  <div className="rounded-xl border border-rose-100 bg-rose-50 p-3 text-sm text-rose-800">
                    {fieldDocumentRejectedNotes[0]}
                  </div>
                ) : null}
                {showFieldDocumentOverallReviewControls ? (
                  <>
                    <Input
                      value={fieldDocumentOverallRejectNote}
                      onChange={(event) => setFieldDocumentOverallRejectNote(event.target.value)}
                      placeholder="Uygun değil açıklaması"
                      disabled={isFieldDocumentOverallReviewBusy || reviewableFieldDocuments.length === 0}
                    />
                    <div className="grid gap-2 sm:grid-cols-2">
                      <Button
                        type="button"
                        variant="outline"
                        disabled={isFieldDocumentOverallReviewBusy || reviewableFieldDocuments.length === 0}
                        onClick={() => void reviewFieldDocumentsOverall('accepted')}
                        className="border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100"
                      >
                        {isFieldDocumentOverallReviewBusy ? 'Kaydediliyor...' : 'Uygun'}
                      </Button>
                      <Button
                        type="button"
                        variant="outline"
                        disabled={isFieldDocumentOverallReviewBusy || reviewableFieldDocuments.length === 0 || fieldDocumentOverallRejectNote.trim() === ''}
                        onClick={() => void reviewFieldDocumentsOverall('rejected')}
                        className="border-rose-200 bg-rose-50 text-rose-800 hover:bg-rose-100"
                      >
                        {isFieldDocumentOverallReviewBusy ? 'Kaydediliyor...' : 'Uygun değil'}
                      </Button>
                    </div>
                  </>
                ) : reviewableFieldDocuments.length > 0 ? (
                  <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                    <span className="text-sm font-semibold text-slate-700">{fieldDocumentOverallReviewLabel}</span>
                    <Button type="button" variant="outline" size="sm" onClick={() => setFieldDocumentOverallReviewEditing(true)}>
                      Kararı değiştir
                    </Button>
                  </div>
                ) : null}
              </div>
            ) : null}
            <div className="grid gap-2 md:grid-cols-3">
              {fieldCompletionDocumentStatuses.map((item) => (
                <div key={item.field} className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                  <div className="flex items-center justify-between gap-2">
                    <p className="text-sm font-semibold text-slate-900">{item.label}</p>
                    <Badge variant={item.uploaded ? 'secondary' : 'outline'}>
                      {item.uploaded ? 'Yüklendi' : isActionDisabled ? 'Kayıt yok' : 'Bekliyor'}
                    </Badge>
                  </div>
                  {item.document?.preview_url ? (
                    <a href={item.document.preview_url} target="_blank" rel="noreferrer" className="mt-3 block overflow-hidden rounded-xl border border-slate-200 bg-white">
                      <img src={item.document.preview_url} alt={item.label} className="h-40 w-full object-cover" />
                      <span className="block px-3 py-2 text-xs font-semibold text-blue-700">Belgeyi aç</span>
                    </a>
                  ) : item.document?.url ? (
                    <a
                      href={item.document.url}
                      target="_blank"
                      rel="noreferrer"
                      className="mt-3 inline-flex rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:text-blue-900"
                    >
                      Belgeyi aç
                    </a>
                  ) : (
                    <p className="mt-3 text-xs text-slate-500">{isActionDisabled ? 'Bu tamamlanmış işte belge kaydı yok.' : 'Bu belge henüz yüklenmedi.'}</p>
                  )}
                </div>
              ))}
            </div>
            {opsExtraFieldDocuments.length > 0 ? (
              <div className="grid gap-2 md:grid-cols-3">
                {opsExtraFieldDocuments.map((document) => {
                  const label = displayOrEmpty(document.label, 'OPS Ek Görsel')

                  return (
                    <div key={String(document.id ?? `${document.field_code}-${document.created_at ?? document.original_name}`)} className="rounded-2xl border border-blue-100 bg-blue-50 p-3">
                      <div className="flex items-center justify-between gap-2">
                        <p className="text-sm font-semibold text-blue-950">{label}</p>
                        <Badge variant="outline">OPS ek</Badge>
                      </div>
                      {document.preview_url ? (
                        <a href={document.preview_url} target="_blank" rel="noreferrer" className="mt-3 block overflow-hidden rounded-xl border border-blue-100 bg-white">
                          <img src={document.preview_url} alt={label} className="h-40 w-full object-cover" />
                          <span className="block px-3 py-2 text-xs font-semibold text-blue-700">Belgeyi aç</span>
                        </a>
                      ) : document.url ? (
                        <a
                          href={document.url}
                          target="_blank"
                          rel="noreferrer"
                          className="mt-3 inline-flex rounded-full border border-blue-100 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:text-blue-900"
                        >
                          Belgeyi aç
                        </a>
                      ) : (
                        <p className="mt-3 text-xs text-blue-700">Önizleme yok.</p>
                      )}
                    </div>
                  )
                })}
              </div>
            ) : null}
            {onOpsExtraDocumentUpload ? (
              <div className="grid gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-3">
                <div>
                  <p className="text-sm font-semibold text-blue-950">OPS ek görsel</p>
                  <p className="mt-1 text-xs text-blue-800">Bu görseller zorunlu üç saha belgesini değiştirmez; aynı önizleme yapısında ek kanıt olarak görünür.</p>
                </div>
                <div className="grid gap-2 md:grid-cols-[160px_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                  <label className="grid gap-1 text-xs font-semibold text-blue-900">
                    Tür
                    <select
                      value={opsExtraDocumentType}
                      onChange={(event) => setOpsExtraDocumentType(event.target.value as typeof opsExtraDocumentType)}
                      className="h-10 rounded-md border border-blue-100 bg-white px-3 text-sm text-blue-950 outline-none transition focus:border-blue-300 focus:ring-blue-100/70 focus:ring-[3px]"
                    >
                      <option value="ops_extra_photo">OPS ek görsel</option>
                      <option value="ops_additional_document">OPS ek belge</option>
                    </select>
                  </label>
                  <label className="grid gap-1 text-xs font-semibold text-blue-900">
                    Görseller
                    <Input
                      type="file"
                      multiple
                      accept="image/*"
                      onChange={(event) => setOpsExtraFiles(Array.from(event.target.files ?? []))}
                    />
                  </label>
                  <label className="grid gap-1 text-xs font-semibold text-blue-900">
                    Not
                    <Input value={opsExtraNote} onChange={(event) => setOpsExtraNote(event.target.value)} placeholder="OPS ek görsel notu" />
                  </label>
                  <Button type="button" variant="outline" onClick={() => void handleOpsExtraDocumentUpload()} disabled={opsExtraUploading || opsExtraFiles.length === 0}>
                    {opsExtraUploading ? 'Yükleniyor...' : 'Ek görsel yükle'}
                  </Button>
                </div>
                {opsExtraFiles.length > 0 ? (
                  <p className="text-xs font-semibold text-blue-800">{opsExtraFiles.length} dosya seçildi.</p>
                ) : null}
                {opsExtraMessage ? (
                  <p className="text-xs font-semibold text-blue-800">{opsExtraMessage}</p>
                ) : null}
              </div>
            ) : null}
            {previousFieldCompletionDocuments.length > 0 ? (
              <details className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <summary className="cursor-pointer text-sm font-semibold text-slate-700">
                  Önceki ziyaret belgeleri ({previousFieldCompletionDocuments.length})
                </summary>
                <div className="mt-3 grid gap-2 md:grid-cols-3">
                  {previousFieldCompletionDocuments.map((document) => (
                    <div key={String(document.id ?? `${document.field_code}-${document.created_at ?? document.original_name}`)} className="rounded-xl border border-slate-200 bg-white p-3">
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <p className="text-sm font-semibold text-slate-900">{displayOrEmpty(document.label, 'Belge')}</p>
                          <p className="mt-1 text-[11px] font-semibold text-slate-500">{dateTimeOrEmpty(document.created_at, 'Tarih yok')}</p>
                        </div>
                        <Badge variant="outline">Eski</Badge>
                      </div>
                      {document.preview_url ? (
                        <a href={document.preview_url} target="_blank" rel="noreferrer" className="mt-3 block overflow-hidden rounded-lg border border-slate-200">
                          <img src={document.preview_url} alt={displayOrEmpty(document.label, 'Belge')} className="h-28 w-full object-cover" />
                          <span className="block px-3 py-2 text-xs font-semibold text-blue-700">Belgeyi aç</span>
                        </a>
                      ) : document.url ? (
                        <a href={document.url} target="_blank" rel="noreferrer" className="mt-3 inline-flex rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:text-blue-900">
                          Belgeyi aç
                        </a>
                      ) : null}
                    </div>
                  ))}
                </div>
              </details>
            ) : null}
            {fieldDocumentReviewError ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
                {fieldDocumentReviewError}
              </div>
            ) : null}
            {partnerPortalActions.length > 0 ? (
              <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Portal aksiyonları</p>
                <div className="mt-3 grid gap-2">
                  {partnerPortalActions.slice(0, 6).map((action) => (
                    <div key={String(action.id)} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white bg-white px-3 py-2 text-sm">
                      <span className="font-medium text-slate-800">{portalActionLabels[action.action] ?? actionLabel(action.action, action.action_label)}</span>
                      <span className="text-xs text-slate-500">{dateTimeOrEmpty(action.created_at, 'Tarih yok')}</span>
                    </div>
                  ))}
                </div>
              </div>
            ) : null}
          </section>
        </DetailPanel>

        <DetailPanel title="Düzeltme / Denetim" summary="Alan düzeltmeleri ve onay bekleyen kayıtlar" tone="warning" className="order-75">
          <section className="grid gap-4 rounded-2xl border border-amber-100 bg-amber-50 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-sm font-semibold text-amber-950">OPS düzeltme defteri</p>
                <p className="mt-1 text-xs leading-5 text-amber-800">
                  Düzeltmeler eski/yeni değer, neden, uygulayan ve yeniden kontrol bayraklarıyla denetim kaydına yazılır.
                </p>
              </div>
              <Badge variant={pendingAdminOverrides.length > 0 ? 'warning' : 'outline'}>
                {pendingAdminOverrides.length > 0 ? `${pendingAdminOverrides.length} onay bekliyor` : 'Bekleyen yok'}
              </Badge>
            </div>

            {adminOverrideError ? (
              <div className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800">
                {adminOverrideError}
              </div>
            ) : null}

            {pendingAdminOverrides.length > 0 ? (
              <div className="grid gap-2">
                <div className="rounded-xl border border-amber-200 bg-amber-100 px-3 py-2 text-sm font-semibold text-amber-950">
                  Bu işte bekleyen düzeltme talepleri var.
                </div>
                {pendingAdminOverrides.map((override) => (
                  <div key={String(override.id)} className="rounded-xl border border-amber-200 bg-white p-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="text-sm font-semibold text-slate-950">{override.field_label}</p>
                        <p className="mt-1 text-xs text-slate-600 break-words">
                          Eski: {override.old_value?.display ?? '-'} · Yeni: {override.requested_value?.display ?? '-'}
                        </p>
                        {override.reason ? <p className="mt-1 text-xs text-slate-600 break-words">Neden: {override.reason}</p> : null}
                        <p className="mt-1 text-[11px] font-semibold text-amber-800">
                          {override.source_label ?? 'Düzeltme talebi'} · {dateTimeOrEmpty(override.created_at, 'Tarih yok')}
                        </p>
                      </div>
                      {onAdminOverrideReview ? (
                        <div className="grid gap-2 sm:grid-cols-2">
                          <Button type="button" size="sm" variant="outline" disabled={adminOverrideInFlight} onClick={() => void onAdminOverrideReview(override.id, 'approve', 'OPS onayıyla uygulandı.')}>
                            <CheckCircle2 className="mr-1 h-4 w-4" />
                            Onayla
                          </Button>
                          <Button type="button" size="sm" variant="outline" disabled={adminOverrideInFlight} onClick={() => void onAdminOverrideReview(override.id, 'reject', 'OPS tarafından reddedildi.')}>
                            <XCircle className="mr-1 h-4 w-4" />
                            Reddet
                          </Button>
                        </div>
                      ) : null}
                    </div>
                  </div>
                ))}
              </div>
            ) : null}

            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
              {correctionFieldGroups.map((group) => (
                <div key={group.group} className="rounded-xl border border-white bg-white/80 p-3">
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{group.label}</p>
                  <div className="mt-3 grid gap-2">
                    {group.fields.map((field) => (
                      <Button key={field.key} type="button" variant="outline" size="sm" className="justify-start" disabled={!onAdminOverrideSubmit || adminOverrideInFlight} onClick={() => openCorrectionEditor(field.key)}>
                        <Pencil className="mr-2 h-4 w-4" />
                        {field.label}
                      </Button>
                    ))}
                  </div>
                </div>
              ))}
            </div>

            {adminOverrides.length > pendingAdminOverrides.length ? (
              <details className="rounded-xl border border-amber-100 bg-white p-3">
                <summary className="cursor-pointer text-sm font-semibold text-amber-900">Son uygulanan / reddedilen düzeltmeler</summary>
                <div className="mt-3 grid gap-2">
                  {adminOverrides.filter((override) => override.status !== 'pending').slice(0, 6).map((override) => (
                    <div key={String(override.id)} className="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <strong>{override.field_label}</strong>
                        <span>{override.status_label ?? 'Düzeltme kaydı'}</span>
                      </div>
                      <p className="mt-1 break-words">Eski: {override.old_value?.display ?? '-'} · Yeni: {(override.new_value ?? override.requested_value)?.display ?? '-'}</p>
                    </div>
                  ))}
                </div>
              </details>
            ) : null}

            {correctionField ? (
              <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="text-sm font-semibold text-slate-950">Alanı düzelt: {correctionField.label}</p>
                    <p className="mt-1 text-xs text-slate-500">
                      {correctionField.mode === 'request' ? 'Bu hassas alan onay talebi olarak kaydedilir.' : 'Bu alan doğrudan uygulanır ve denetim kaydına yazılır.'}
                    </p>
                  </div>
                  <Button type="button" variant="outline" size="sm" onClick={closeCorrectionEditor}>Kapat</Button>
                </div>
                <div className="mt-3 grid gap-3">
                  <label className="grid gap-1 text-xs font-semibold text-slate-700">
                    Yeni değer
                    <Input type={correctionField.input} value={correctionValue} onChange={(event) => setCorrectionValue(event.target.value)} />
                  </label>
                  <label className="grid gap-1 text-xs font-semibold text-slate-700">
                    Düzeltme nedeni
                    <Input value={correctionReason} onChange={(event) => setCorrectionReason(event.target.value)} placeholder="Neden zorunlu" />
                  </label>
                  <Button type="button" disabled={adminOverrideInFlight || correctionReason.trim().length < 3} onClick={() => void submitCorrection()}>
                    {adminOverrideInFlight ? 'Kaydediliyor...' : correctionField.mode === 'request' ? 'Onay talebi oluştur' : 'Düzeltmeyi uygula'}
                  </Button>
                </div>
              </div>
            ) : null}
          </section>
        </DetailPanel>

        {shouldRenderHistoryPanel ? (
        <DetailPanel title="Operasyon Geçmişi" summary="Denetim kayıtları ve durum akışı" tone="history" className={opsSectionClass('history', activeOpsSection)}>
          <section className="rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Operasyon kayıtları</p>
            <div className="mt-4 space-y-3">
              {(request.auditLogs ?? []).length === 0 ? (
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                  Denetim kaydı bulunmuyor.
                </div>
              ) : (
                (request.auditLogs ?? []).map((log) => (
                  <div key={String(log.id)} className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="text-sm font-semibold text-slate-900">{actionLabel(log.action_type, log.action_label)}</p>
                      <span className="text-xs text-slate-500">{dateTimeOrEmpty(log.created_at, 'Tarih yok')}</span>
                    </div>
                    <p className="mt-2 text-xs text-slate-500">
                      {log.user_name || 'Sistem'}
                    </p>
                    {log.note ? (
                      <p className="mt-2 text-sm text-slate-700 break-words">{log.note}</p>
                    ) : null}
                  </div>
                ))
              )}
            </div>
          </section>

          <section className="rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Durum Akışı</p>
            <div className="mt-4 space-y-4">
              {loading ? (
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                  Detay yükleniyor...
                </div>
              ) : sortedEvents.length === 0 ? (
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                  Henüz işlem geçmişi yok.
                </div>
              ) : (
                sortedEvents.map((event) => (
                  <div key={String(event.id)} className="flex gap-3 text-sm">
                    <div className="mt-1 h-2.5 w-2.5 rounded-full bg-slate-400" />
                    <div>
                      <p className="font-semibold text-slate-900">{event.title_label ?? event.event_type_label ?? actionLabel(event.event_type, event.title)}</p>
                      <p className="text-xs text-slate-500">
                        {eventTime(event.created_at)}
                        {event.note ? ` · ${event.note}` : ''}
                      </p>
                    </div>
                  </div>
                ))
              )}
            </div>
          </section>
        </DetailPanel>
        ) : null}

      </CardContent>

      {(() => {
        const visibleFooterWorkflowActions = isActionDisabled ? [] : footerWorkflowActions
        const showFooterBar = visibleFooterWorkflowActions.length > 0
          || shouldShowFooterPaymentLinkAction
          || (!isActionDisabled && Boolean(finalCheckCompletionAction))
          || (!isActionDisabled && canReassignAfterReview)
          || (!isActionDisabled && Boolean(whatsappHref))
          || isReopenVisible

        return showFooterBar ? (
      <div
        className="sticky bottom-0 z-10 mt-2 flex justify-end bg-transparent px-2 py-2"
        style={{ paddingBottom: 'calc(0.5rem + env(safe-area-inset-bottom))' }}
      >
        <div className="grid max-w-full gap-2 rounded-2xl border border-slate-200 bg-white/95 p-2 shadow-[0_-10px_30px_rgba(15,23,42,0.08)] backdrop-blur sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-center lg:justify-end">
          {visibleFooterWorkflowActions.map(([actionKey, action]) => (
            <Button
              key={actionKey}
              className="h-9 w-full text-xs sm:text-sm lg:w-auto"
              variant={actionKey === 'complete' ? 'default' : actionKey === 'cancel' ? 'destructive' : 'outline'}
              type="button"
              onClick={() => handleWorkflowAction(actionKey)}
              disabled={isActionDisabled || workflowActionInFlight !== null || (actionKey === 'assign_technician' && isAssignmentBlocked)}
              title={isActionDisabled ? disabledTitle : actionKey === 'assign_technician' && isAssignmentBlocked ? combinedAssignmentBlockerMessages.join(' ') : undefined}
            >
              {workflowActionInFlight === actionKey ? 'İşleniyor...' : action.label}
            </Button>
          ))}
          {shouldShowFooterPaymentLinkAction ? (
            <Button
              data-testid="bottom-payment-link-action"
              className="h-9 w-full text-xs sm:text-sm lg:w-auto"
              type="button"
              variant={paidOnlinePaymentLink || pendingOnlinePaymentLink ? 'default' : 'outline'}
              onClick={handleBottomPaymentLinkAction}
            >
              {paymentLinkActionLabel}
            </Button>
          ) : null}
          {!isActionDisabled && finalCheckCompletionAction ? (
            <Button
              className="h-9 w-full text-xs sm:text-sm lg:w-auto"
              type="button"
              disabled={isActionDisabled || finalCompletionMissingReasons.length > 0 || !onPartnerCompletionApprove || (finalPayoutApprovalRequired && finalPayoutSelectedRows.length === 0)}
              title={finalCompletionMissingReasons.length > 0 ? finalCompletionMissingReasons.join(' ') : finalPayoutApprovalRequired && finalPayoutSelectedRows.length === 0 ? 'Hakedişe dahil edilecek en az bir iş seçilmelidir.' : undefined}
              onClick={() => void onPartnerCompletionApprove?.(finalCheckCompletionAction.id, {
                note: completionReviewNote || null,
                approved_visit_ids: finalPayoutApprovalRequired ? finalPayoutSelectedIds : undefined,
              })}
            >
              {finalPayoutApprovalRequired ? 'İşaretlileri onayla' : 'Son kontrolü tamamla'}
            </Button>
          ) : null}
          {!isActionDisabled && canReassignAfterReview ? (
            <>
              <Button
                className="h-9 w-full text-xs sm:text-sm lg:w-auto"
                type="button"
                variant="outline"
                onClick={() => onAssign?.()}
              >
                Başka usta ata
              </Button>
              {hasAssignedTechnician ? (
                <Button
                  className="h-9 w-full text-xs sm:text-sm lg:w-auto"
                  type="button"
                  variant="outline"
                  onClick={() => void onAssignSelectedTechnician?.()}
                  disabled={assignmentSubmitDisabled}
                  title={isAssignmentBlocked ? combinedAssignmentBlockerMessages.join(' ') : undefined}
                >
                  {sameTechnicianReviewActionLabel}
                </Button>
              ) : null}
            </>
          ) : null}
          {!isActionDisabled ? (
          <Button
            asChild
            className="h-9 w-full text-[0.72rem] sm:text-sm lg:w-auto"
            variant="secondary"
            disabled={!whatsappHref}
          >
            <a href={whatsappHref || '#'} target="_blank" rel="noreferrer">WhatsApp Aç</a>
          </Button>
          ) : null}
          {isReopenVisible ? (
            <Button
              className="h-9 w-full text-xs sm:text-sm lg:w-auto"
              variant="outline"
              type="button"
              onClick={() => onReopen?.()}
            >
              Talebi Yeniden Aç
            </Button>
          ) : null}
        </div>
      </div>
        ) : null
      })()}
    </Card>
  )
}

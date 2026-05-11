import { Head } from '@inertiajs/react'
import { Plus, RefreshCw, Wrench } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { DateTimeFields } from '@/components/technical-service/DateTimeFields'
import { ServiceRequestDetails } from '@/components/technical-service/ServiceRequestDetails'
import { TechnicalServiceKanbanBoard } from '@/components/technical-service/TechnicalServiceKanbanBoard'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'
import { TechnicalServiceWeekNavigator } from '@/components/technical-service/TechnicalServiceWeekNavigator'
import {
  findProvinceByName,
  getDistrictOptionsForProvince,
  haversineKm,
  normalizeDistrictName,
  normalizeProvinceName,
  normalizeTurkishLocation,
  TURKEY_PROVINCES,
} from '@/components/technical-service/turkey-locations'
import type {
  MikroMountCheckResult,
  MikroSerialHistoryResponse,
  ServiceFilters as FilterState,
  ServiceRequest,
  ServiceTechnician,
  WarrantySerialResponse,
} from '@/components/technical-service/types'
import {
  calculateTravelPreview,
  formatTechnicalServiceDateTime,
  formatTechnicalServiceDate,
  formatTechnicalServiceMrn,
  getServicePaymentInfo,
  normalizeTechnicalServiceText,
  toTechnicalServiceDateTimeInputValue,
} from '@/components/technical-service/utils'
import { Button } from '@/components/ui/button'
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

type NewRequestForm = {
  customer: string
  phone: string
  city: string
  district: string
  address: string
  product: string
  serialNumber: string
  serviceType: string
  notes: string
}

type ApiTechnicalServiceRequest = {
  id: number | string
  mrn: string
  customer_name: string
  customer_phone: string
  phone?: string | null
  telefon?: string | null
  customer_mobile_phone?: string | null
  customer_gsm?: string | null
  customer_city: string
  customer_district: string
  service_address: string
  product_name: string
  product_model?: string | null
  serial_number?: string | null
  service_type: string
  status: string
  priority: string
  technical_service_technician_id?: number | string | null
  technician_name?: string | null
  technician_phone?: string | null
  technicianPhone?: string | null
  technical_service_phone?: string | null
  technicalServicePhone?: string | null
  technical_service_technician_phone?: string | null
  technicalServiceTechnicianPhone?: string | null
  technician_mobile_phone?: string | null
  technicianMobilePhone?: string | null
  technician_gsm?: string | null
  technicianGsm?: string | null
  scheduled_at?: string | null
  scheduled_date?: string | null
  scheduled_time?: string | null
  completed_at?: string | null
  description?: string | null
  resolution_notes?: string | null
  source_channel?: string | null
  created_at?: string | null
  travel_round_trip_km?: number | string | null
  travel_billable_km?: number | string | null
  travel_fee_amount?: number | string | null
  technician_payment_amount?: number | string | null
  travel_calculation_source?: string | null
  travel_calculated_at?: string | null
  customer_contact_status?: string | null
  customer_contacted_at?: string | null
  customer_contact_note?: string | null
  customer_confirmed_at?: string | null
  customer_confirmation_method?: string | null
  customer_preferred_date?: string | null
  customer_preferred_time_start?: string | null
  customer_preferred_time_end?: string | null
  customer_callback_at?: string | null
  customer_rejection_reason?: string | null
  technician_approval_status?: string | null
  technician_approved_at?: string | null
  technician_revision_requested_at?: string | null
  technician_revision_note?: string | null
  technician_confirmation_status?: string | null
  revision_requested?: boolean | number | string | null
  reschedule_requested?: boolean | number | string | null
  field_status?: string | null
  field_started_at?: string | null
  field_arrived_at?: string | null
  field_completed_at?: string | null
  field_completion_note?: string | null
  technician_started_at?: string | null
  technician_arrived_at?: string | null
  technician_completed_at?: string | null
  checklist_payload?: Record<string, boolean> | null
  checklist_status?: string | null
  checklist_completed_at?: string | null
  before_photo_count?: number | null
  after_photo_count?: number | null
  general_photo_count?: number | null
  missing_info_reason?: string | null
  pending_reason?: string | null
  requires_reschedule?: boolean | number | string | null
  reschedule_reason?: string | null
  document_status?: string | null
  photo_status?: string | null
  customer_closure_approval_status?: string | null
  customer_closure_approved_at?: string | null
  customer_closure_approval_method?: string | null
  customer_closure_approval_code?: string | null
  customer_signature_name?: string | null
  customer_signature_at?: string | null
  completion_block_reason?: string | null
  incomplete_reason?: string | null
  requires_second_visit?: boolean | number | string | null
  second_visit_reason?: string | null
  cancellation_reason?: string | null
  workflow_status?: string | null
  next_action?: string | null
  sla_due_at?: string | null
  sla_status?: string | null
  allowed_workflow_actions?: Record<string, { label: string, target: string }> | null
  allowed_workflow_transitions?: string[] | null
  audit_logs?: Array<{
    id: string | number
    entity_type: string
    entity_id: string | number
    action_type: string
    old_value?: Record<string, unknown> | null
    new_value?: Record<string, unknown> | null
    user_id?: number | null
    user_name?: string | null
    note?: string | null
    created_at: string
  }> | null
  latest_event?: string | null
  technician?: {
    phone?: string | null
    mobile_phone?: string | null
    mobilePhone?: string | null
    gsm?: string | null
  } | null
  technical_service_technician?: {
    phone?: string | null
    mobile_phone?: string | null
  } | null
  technicalServiceTechnician?: {
    phone?: string | null
    mobilePhone?: string | null
  } | null
  document?: unknown
  documents?: unknown
  photo?: unknown
  photos?: unknown
}

type ApiTechnicalServiceEvent = {
  id: number | string
  event_type: string
  title: string
  note?: string | null
  from_status?: string | null
  to_status?: string | null
  author_user_id?: number | null
  metadata?: Record<string, unknown> | null
  created_at: string
  updated_at: string
}

type SummaryResponse = {
  total_requests: number
  ongoing_requests: number
  status_counts: Record<string, number>
  priority_counts: Record<string, number>
  scheduled_today: number
  workflow_queue_counts?: Record<string, number>
  customer_contact_counts?: Record<string, number>
}

const initialFilters: FilterState = {
  search: '',
  status: '',
  serviceType: '',
  priority: '',
  city: '',
  technician: '',
  onlyOpen: true,
}

const initialRequestForm: NewRequestForm = {
  customer: '',
  phone: '',
  city: '',
  district: '',
  address: '',
  product: '',
  serialNumber: '',
  serviceType: '',
  notes: '',
}

const selectClassName = 'h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]'

const normalizeFormCity = (value: string | null | undefined) => normalizeProvinceName(value) ?? String(value ?? '').trim()
const normalizeFormDistrict = (city: string | null | undefined, value: string | null | undefined) =>
  normalizeDistrictName(city, value) ?? String(value ?? '').trim()

const CLOSURE_REASONS = [
  'Montaj tamamlandÄ±',
  'MÃ¼ÅŸterinin kapÄ±sÄ± uygun deÄŸildi',
  'MÃ¼ÅŸteri sipariÅŸi iptal etti',
  'MÃ¼ÅŸteri randevuya gelmedi / evde yoktu',
  'ÃœrÃ¼n / seri numarasÄ± uyumsuz',
  'Servis Ã¼creti kabul edilmedi',
  'DiÄŸer',
] as const

const REOPEN_REASONS = [
  'YanlÄ±ÅŸlÄ±kla tamamlandÄ±',
  'Eksik fotoÄŸraf / belge',
  'MÃ¼ÅŸteri onayÄ± hatasÄ±',
  'Usta yanlÄ±ÅŸ kapattÄ±',
  'Operasyon dÃ¼zeltmesi',
  'DiÄŸer',
] as const

const APPOINTMENT_TIME_SLOTS = [
  { value: '10:00 - 12:00', start: '10:00' },
  { value: '12:00 - 14:00', start: '12:00' },
  { value: '14:00 - 16:00', start: '14:00' },
  { value: '16:00 - 18:00', start: '16:00' },
] as const

const CONTACT_CONFIRMATION_METHODS = ['telefon', 'whatsapp', 'sms', 'eposta', 'panel'] as const
const FIELD_CLOSURE_METHODS = ['otp', 'imza', 'telefon', 'panel'] as const
const FIELD_CHECKLIST_ITEMS = [
  'ÃœrÃ¼n seri numarasÄ± kontrol edildi',
  'KapÄ± / montaj yeri kontrol edildi',
  'Montaj uygunluÄŸu kontrol edildi',
  'ÃœrÃ¼n Ã§alÄ±ÅŸÄ±r durumda test edildi',
  'MÃ¼ÅŸteriye kullanÄ±m bilgisi verildi',
  'Garanti / servis formu bilgisi kontrol edildi',
] as const

function isMountPaymentMissing(result: MikroMountCheckResult | null | undefined): boolean {
  return result?.montaj_durumu === 'Montaj HariÃ§'
}

function isMountPaymentAccepted(result: MikroMountCheckResult | null | undefined): boolean {
  return result?.montaj_durumu === 'Montaj Dahil' || result?.montaj_durumu === 'Montaj Sonradan Dahil'
}

function normalizeSearchText(value: string | null | undefined): string {
  return normalizeTechnicalServiceText(value)
    .replace(/[-\s\p{Punctuation}]+/gu, '')
}

function parseNullableNumber(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const parsed = typeof value === 'number' ? value : Number(value)

  return Number.isFinite(parsed) ? parsed : null
}

function technicianDisplayName(technician: ServiceTechnician): string {
  return [technician.first_name, technician.last_name].filter(Boolean).join(' ').trim() || technician.name
}

function normalizeLocationText(value: string | null | undefined): string {
  return normalizeTurkishLocation(value)
}

function startOfLocalDay(value: Date): Date {
  return new Date(value.getFullYear(), value.getMonth(), value.getDate())
}

function addDays(value: Date, amount: number): Date {
  return new Date(value.getFullYear(), value.getMonth(), value.getDate() + amount)
}

function startOfWeek(value: Date): Date {
  const currentDay = startOfLocalDay(value).getDay()
  const offset = currentDay === 0 ? -6 : 1 - currentDay

  return addDays(value, offset)
}

function startOfMonth(value: Date): Date {
  return new Date(value.getFullYear(), value.getMonth(), 1)
}

function addMonths(value: Date, amount: number): Date {
  return new Date(value.getFullYear(), value.getMonth() + amount, 1)
}

function toDateKey(value: Date): string {
  const year = value.getFullYear()
  const month = String(value.getMonth() + 1).padStart(2, '0')
  const day = String(value.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

function isSameLocalDay(a: Date, b: Date): boolean {
  return toDateKey(a) === toDateKey(b)
}

function parseLocalDateValue(value: string | null | undefined): Date | null {
  if (!value) {
    return null
  }

  const trimmed = value.trim()

  if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
    const [year, month, day] = trimmed.split('-').map(Number)

    return new Date(year, (month ?? 1) - 1, day ?? 1)
  }

  const parsed = new Date(trimmed)

  return Number.isNaN(parsed.getTime()) ? null : parsed
}

function normalizeRequestStatus(status: string): string {
  const normalized = normalizeTechnicalServiceText(status)

  switch (normalized) {
    case 'atandi':
      return 'AtandÄ±'
    case 'tamamlandi':
      return 'TamamlandÄ±'
    case 'iptal':
      return 'Ä°ptal'
    case 'devam ediyor':
      return 'Devam Ediyor'
    default:
      return status
  }
}

function isClosedStatus(status: string): boolean {
  const normalized = normalizeRequestStatus(status)

  return normalized === 'TamamlandÄ±' || normalized === 'Ä°ptal'
}

function getRequestScheduledDate(request: ServiceRequest): Date | null {
  const requestWithFallbacks = request as ServiceRequest & Record<string, string | null | undefined>

  return parseLocalDateValue(
    request.scheduledDate
    ?? requestWithFallbacks.scheduled_date
    ?? request.scheduledAt
    ?? requestWithFallbacks.scheduled_at
    ?? request.customerPreferredDate
    ?? requestWithFallbacks.customer_preferred_date
    ?? null,
  )
}

function parseLooseBoolean(value: boolean | number | string | null | undefined): boolean | null {
  if (typeof value === 'boolean') {
    return value
  }

  if (typeof value === 'number') {
    return value !== 0
  }

  if (typeof value === 'string') {
    const normalized = normalizeTechnicalServiceText(value)

    if (['1', 'true', 'evet', 'yes', 'var'].includes(normalized)) {
      return true
    }

    if (['0', 'false', 'hayir', 'hayÄ±r', 'no', 'yok'].includes(normalized)) {
      return false
    }
  }

  return null
}

function displayStatusLabel(status: string): string {
  const normalized = normalizeTechnicalServiceText(status)

  switch (normalized) {
    case 'atandi':
      return 'AtandÄ±'
    case 'tamamlandi':
      return 'TamamlandÄ±'
    case 'iptal':
      return 'Ä°ptal'
    default:
      return status
  }
}

type TechnicianMatch = {
  technician: ServiceTechnician
  badge: 'AynÄ± ilÃ§e' | 'AynÄ± il' | 'YakÄ±n il / diÄŸer'
  rank: number
  distanceKm: number | null
  sameCity: boolean
}

type TechnicianAssignmentInsight = {
  id: string
  name: string
  location: string
  distanceKmLabel: string
  scheduledCount: number
  availableSlots: string[]
  technicianAmountLabel: string
  travelAmountLabel: string
  totalCostLabel: string
  costDeltaLabel: string
  recommended: boolean
  estimatedRoundTripKm: number | null
}

function technicianMatchInfo(technician: ServiceTechnician, request: ServiceRequest | null): TechnicianMatch {
  const technicianCity = normalizeLocationText(technician.city)
  const technicianDistrict = normalizeLocationText(technician.district)
  const requestCity = normalizeLocationText(request?.city)
  const requestDistrict = normalizeLocationText(request?.district)
  const technicianProvince = findProvinceByName(technician.city)
  const requestProvince = findProvinceByName(request?.city)
  const technicianLat = parseNullableNumber(technician.start_latitude ?? technician.latitude) ?? technicianProvince?.latitude ?? null
  const technicianLng = parseNullableNumber(technician.start_longitude ?? technician.longitude) ?? technicianProvince?.longitude ?? null
  const requestLat = requestProvince?.latitude ?? null
  const requestLng = requestProvince?.longitude ?? null
  const sameCity = technicianCity !== '' && technicianCity === requestCity
  const sameDistrict = sameCity && technicianDistrict !== '' && technicianDistrict === requestDistrict
  const distanceKm = haversineKm(technicianLat, technicianLng, requestLat, requestLng)

  if (sameDistrict) {
    return { technician, badge: 'AynÄ± ilÃ§e', rank: 0, distanceKm, sameCity }
  }

  if (sameCity) {
    return { technician, badge: 'AynÄ± il', rank: 1, distanceKm, sameCity }
  }

  return { technician, badge: 'YakÄ±n il / diÄŸer', rank: 2, distanceKm, sameCity }
}

function resolveAppointmentSlotValue(
  scheduledTime: string | null | undefined,
): (typeof APPOINTMENT_TIME_SLOTS)[number]['value'] | null {
  const normalized = String(scheduledTime ?? '').trim()

  if (normalized === '') {
    return null
  }

  const direct = APPOINTMENT_TIME_SLOTS.find((slot) => slot.value === normalized)

  if (direct) {
    return direct.value
  }

  const byStart = APPOINTMENT_TIME_SLOTS.find((slot) => slot.start === normalized.slice(0, 5))

  return byStart?.value ?? null
}

function formatPreferredAppointmentLabel(request: ServiceRequest | null): string {
  if (!request?.customerPreferredDate) {
    return '-'
  }

  const dateLabel = formatTechnicalServiceDate(request.customerPreferredDate)
  const timeLabel = request.customerPreferredTimeStart
    ? `${request.customerPreferredTimeStart}${request.customerPreferredTimeEnd ? ` - ${request.customerPreferredTimeEnd}` : ''}`
    : null

  return [dateLabel, timeLabel].filter(Boolean).join(' Â· ')
}

function mapApiRequest(request: ApiTechnicalServiceRequest): ServiceRequest {
  return {
    id: String(request.id),
    mrn: request.mrn,
    customer: request.customer_name,
    phone: request.customer_phone
      ?? request.phone
      ?? request.telefon
      ?? request.customer_mobile_phone
      ?? request.customer_gsm
      ?? '',
    city: request.customer_city,
    district: request.customer_district,
    product: request.product_name,
    model: request.product_model ?? '',
    serialNumber: request.serial_number ?? '',
    serviceType: request.service_type,
    priority: request.priority,
    technicianId: request.technical_service_technician_id === null || request.technical_service_technician_id === undefined
      ? null
      : String(request.technical_service_technician_id),
    technician: request.technician_name ?? 'AtanmadÄ±',
    technicianPhone: request.technician_phone
      ?? request.technicianPhone
      ?? request.technical_service_phone
      ?? request.technicalServicePhone
      ?? request.technical_service_technician_phone
      ?? request.technicalServiceTechnicianPhone
      ?? request.technician_mobile_phone
      ?? request.technicianMobilePhone
      ?? request.technician_gsm
      ?? request.technicianGsm
      ?? request.technician?.phone
      ?? request.technician?.mobile_phone
      ?? request.technician?.mobilePhone
      ?? request.technician?.gsm
      ?? request.technical_service_technician?.phone
      ?? request.technical_service_technician?.mobile_phone
      ?? request.technicalServiceTechnician?.phone
      ?? request.technicalServiceTechnician?.mobilePhone
      ?? null,
    appointment: formatTechnicalServiceDateTime(request.scheduled_at ?? request.scheduled_date ?? null, 'Belirlenmedi'),
    status: displayStatusLabel(request.status),
    address: request.service_address,
    notes: request.description ?? request.resolution_notes ?? '',
    channel: request.source_channel ?? '',
    scheduledAt: request.scheduled_at ?? null,
    scheduledDate: request.scheduled_date ?? null,
    scheduledTime: request.scheduled_time ?? null,
    createdAt: request.created_at ?? null,
    completedAt: request.completed_at ?? null,
    travelRoundTripKm: parseNullableNumber(request.travel_round_trip_km),
    travelBillableKm: parseNullableNumber(request.travel_billable_km),
    travelFeeAmount: parseNullableNumber(request.travel_fee_amount),
    technicianPaymentAmount: parseNullableNumber(request.technician_payment_amount),
    travelCalculationSource: request.travel_calculation_source ?? null,
    travelCalculatedAt: request.travel_calculated_at ?? null,
    customerContactStatus: request.customer_contact_status ?? null,
    customerContactedAt: request.customer_contacted_at ?? null,
    customerContactNote: request.customer_contact_note ?? null,
    customerConfirmedAt: request.customer_confirmed_at ?? null,
    customerConfirmationMethod: request.customer_confirmation_method ?? null,
    customerPreferredDate: request.customer_preferred_date ?? null,
    customerPreferredTimeStart: request.customer_preferred_time_start ?? null,
    customerPreferredTimeEnd: request.customer_preferred_time_end ?? null,
    customerCallbackAt: request.customer_callback_at ?? null,
    customerRejectionReason: request.customer_rejection_reason ?? null,
    technicianApprovalStatus: request.technician_approval_status ?? null,
    technicianApprovedAt: request.technician_approved_at ?? null,
    technicianRevisionRequestedAt: request.technician_revision_requested_at ?? null,
    technicianRevisionNote: request.technician_revision_note ?? null,
    technicianConfirmationStatus: request.technician_confirmation_status ?? null,
    revisionRequested: parseLooseBoolean(request.revision_requested),
    rescheduleRequested: parseLooseBoolean(request.reschedule_requested),
    fieldStatus: request.field_status ?? null,
    fieldStartedAt: request.field_started_at ?? null,
    fieldArrivedAt: request.field_arrived_at ?? null,
    fieldCompletedAt: request.field_completed_at ?? null,
    fieldCompletionNote: request.field_completion_note ?? null,
    technicianStartedAt: request.technician_started_at ?? null,
    technicianArrivedAt: request.technician_arrived_at ?? null,
    technicianCompletedAt: request.technician_completed_at ?? null,
    checklistPayload: request.checklist_payload ?? null,
    checklistStatus: request.checklist_status ?? null,
    checklistCompletedAt: request.checklist_completed_at ?? null,
    beforePhotoCount: request.before_photo_count ?? null,
    afterPhotoCount: request.after_photo_count ?? null,
    generalPhotoCount: request.general_photo_count ?? null,
    missingInfoReason: request.missing_info_reason ?? null,
    pendingReason: request.pending_reason ?? null,
    requiresReschedule: parseLooseBoolean(request.requires_reschedule),
    rescheduleReason: request.reschedule_reason ?? null,
    documentStatus: request.document_status ?? null,
    photoStatus: request.photo_status ?? null,
    customerClosureApprovalStatus: request.customer_closure_approval_status ?? null,
    customerClosureApprovedAt: request.customer_closure_approved_at ?? null,
    customerClosureApprovalMethod: request.customer_closure_approval_method ?? null,
    customerClosureApprovalCode: request.customer_closure_approval_code ?? null,
    customerSignatureName: request.customer_signature_name ?? null,
    customerSignatureAt: request.customer_signature_at ?? null,
    completionBlockReason: request.completion_block_reason ?? null,
    incompleteReason: request.incomplete_reason ?? null,
    requiresSecondVisit: parseLooseBoolean(request.requires_second_visit),
    secondVisitReason: request.second_visit_reason ?? null,
    cancellationReason: request.cancellation_reason ?? null,
    workflowStatus: request.workflow_status ?? null,
    nextAction: request.next_action ?? null,
    slaDueAt: request.sla_due_at ?? null,
    slaStatus: request.sla_status ?? null,
    allowedWorkflowActions: request.allowed_workflow_actions ?? null,
    allowedWorkflowTransitions: request.allowed_workflow_transitions ?? null,
    auditLogs: request.audit_logs ?? null,
    latestEvent: request.latest_event ?? null,
    document: request.document,
    documents: request.documents,
    photo: request.photo,
    photos: request.photos,
  }
}

function isUnassignedWorkflowRequest(request: ServiceRequest): boolean {
  const technicianName = normalizeTechnicalServiceText(request.technician)

  return technicianName === '' || technicianName === 'atanmadi' || technicianName === 'atanmadÄ±'
}

export function TechnicalServiceOperationCenter() {
  const [filters, setFilters] = useState<FilterState>(initialFilters)
  const [selectedPlanDayKey, setSelectedPlanDayKey] = useState<string | null>(null)
  const [requests, setRequests] = useState<ServiceRequest[]>([])
  const [weekReferenceDate, setWeekReferenceDate] = useState<Date>(() => new Date())
  const [selectedDate, setSelectedDate] = useState<Date>(() => startOfLocalDay(new Date()))
  const [technicians, setTechnicians] = useState<ServiceTechnician[]>([])
  const [techniciansLoading, setTechniciansLoading] = useState(false)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [selectedListRequest, setSelectedListRequest] = useState<ServiceRequest | null>(null)
  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [isDetailDialogOpen, setIsDetailDialogOpen] = useState(false)
  const [createForm, setCreateForm] = useState<NewRequestForm>(initialRequestForm)
  const [loading, setLoading] = useState(true)
  const [detailLoading, setDetailLoading] = useState(false)
  const [, setSummaryLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [selectedEvents, setSelectedEvents] = useState<ApiTechnicalServiceEvent[]>([])
  const [selectedDetailRequest, setSelectedDetailRequest] = useState<ServiceRequest | null>(null)
  const [assignDialogOpen, setAssignDialogOpen] = useState(false)
  const [scheduleDialogOpen, setScheduleDialogOpen] = useState(false)
  const [contactDialogOpen, setContactDialogOpen] = useState(false)
  const [contactAction, setContactAction] = useState<string | null>(null)
  const [assignTechnicianOption, setAssignTechnicianOption] = useState('')
  const [assignOtherTechnician, setAssignOtherTechnician] = useState('')
  const [assignNote, setAssignNote] = useState('')
  const [assignLoading, setAssignLoading] = useState(false)
  const [assignError, setAssignError] = useState<string | null>(null)
  const [showNearbyTechnicians, setShowNearbyTechnicians] = useState(false)
  const [scheduleDate, setScheduleDate] = useState('')
  const [scheduleTimeSlot, setScheduleTimeSlot] = useState('')
  const [scheduleNote, setScheduleNote] = useState('')
  const [scheduleLoading, setScheduleLoading] = useState(false)
  const [scheduleError, setScheduleError] = useState<string | null>(null)
  const [travelRoundTripKm, setTravelRoundTripKm] = useState('')
  const [assignOverrideWithoutPayment, setAssignOverrideWithoutPayment] = useState(false)
  const [assignOverrideReason, setAssignOverrideReason] = useState('')
  const [contactMethod, setContactMethod] = useState('telefon')
  const [contactNote, setContactNote] = useState('')
  const [contactPreferredDate, setContactPreferredDate] = useState('')
  const [contactPreferredTimeStart, setContactPreferredTimeStart] = useState('')
  const [contactPreferredTimeEnd, setContactPreferredTimeEnd] = useState('')
  const [contactCallbackAt, setContactCallbackAt] = useState('')
  const [contactRejectionReason, setContactRejectionReason] = useState('')
  const [contactCancellationReason, setContactCancellationReason] = useState('')
  const [contactLoading, setContactLoading] = useState(false)
  const [contactError, setContactError] = useState<string | null>(null)
  const [fieldDialogOpen, setFieldDialogOpen] = useState(false)
  const [fieldAction, setFieldAction] = useState<string | null>(null)
  const [fieldNote, setFieldNote] = useState('')
  const [fieldIncompleteReason, setFieldIncompleteReason] = useState('')
  const [fieldIncompleteWorkflowStatus, setFieldIncompleteWorkflowStatus] = useState('Beklemede')
  const [fieldRequiresSecondVisit, setFieldRequiresSecondVisit] = useState(false)
  const [fieldSecondVisitReason, setFieldSecondVisitReason] = useState('')
  const [fieldChecklist, setFieldChecklist] = useState<Record<string, boolean>>(() => Object.fromEntries(FIELD_CHECKLIST_ITEMS.map((item) => [item, false])))
  const [fieldBeforePhotoCount, setFieldBeforePhotoCount] = useState('3')
  const [fieldAfterPhotoCount, setFieldAfterPhotoCount] = useState('3')
  const [fieldGeneralPhotoCount, setFieldGeneralPhotoCount] = useState('1')
  const [fieldDocumentStatus, setFieldDocumentStatus] = useState('tamamlandÄ±')
  const [fieldApprovalMethod, setFieldApprovalMethod] = useState<(typeof FIELD_CLOSURE_METHODS)[number]>('otp')
  const [fieldApprovalCode, setFieldApprovalCode] = useState('')
  const [fieldSignatureName, setFieldSignatureName] = useState('')
  const [fieldLoading, setFieldLoading] = useState(false)
  const [fieldError, setFieldError] = useState<string | null>(null)
  const [completeDialogOpen, setCompleteDialogOpen] = useState(false)
  const [completionReason, setCompletionReason] = useState('')
  const [completionOtherNote, setCompletionOtherNote] = useState('')
  const [installationCompletedAt, setInstallationCompletedAt] = useState('')
  const [installationCompletionNote, setInstallationCompletionNote] = useState('')
  const [completeLoading, setCompleteLoading] = useState(false)
  const [completeError, setCompleteError] = useState<string | null>(null)
  const [reopenDialogOpen, setReopenDialogOpen] = useState(false)
  const [reopenReason, setReopenReason] = useState('')
  const [reopenNote, setReopenNote] = useState('')
  const [reopenLoading, setReopenLoading] = useState(false)
  const [reopenError, setReopenError] = useState<string | null>(null)
  const [createLoading, setCreateLoading] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)
  const [workflowActionLoading, setWorkflowActionLoading] = useState<string | null>(null)
  const [, setSummaryData] = useState<SummaryResponse | null>(null)
  const [mikroMountCheck, setMikroMountCheck] = useState<MikroMountCheckResult | null>(null)
  const [mikroMountLoading, setMikroMountLoading] = useState(false)
  const [mikroMountError, setMikroMountError] = useState<string | null>(null)
  const [warranty, setWarranty] = useState<WarrantySerialResponse | null>(null)
  const [warrantyLoading, setWarrantyLoading] = useState(false)
  const [warrantyError, setWarrantyError] = useState<string | null>(null)
  const [isDatePickerOpen, setIsDatePickerOpen] = useState(false)
  const [calendarMonth, setCalendarMonth] = useState<Date>(() => startOfMonth(new Date()))
  const datePickerRef = useRef<HTMLDivElement | null>(null)
  const selectedIdRef = useRef<string | null>(null)
  const detailRequestTokenRef = useRef(0)
  const serialLookupTokenRef = useRef(0)
  const createDistrictOptions = useMemo(() => getDistrictOptionsForProvince(createForm.city), [createForm.city])
  const hasCreateDistrictFallback = createForm.district.trim() !== ''
    && !createDistrictOptions.some((district) => district.normalizedName === normalizeTurkishLocation(createForm.district))

  const loadRequests = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const response = await apiRequest('/api/technical-service/requests')
      const items = Array.isArray(response.items) ? response.items : []
      setRequests(items.map(mapApiRequest))
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Teknik servis talepleri alÄ±namadÄ±.')
    } finally {
      setLoading(false)
    }
  }, [])

  const loadSummary = useCallback(async () => {
    setSummaryLoading(true)

    try {
      const response = await apiRequest('/api/technical-service/summary')
      setSummaryData(response as SummaryResponse)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Ã–zet verisi alÄ±namadÄ±.')
    } finally {
      setSummaryLoading(false)
    }
  }, [])

  const loadTechnicians = useCallback(async () => {
    setTechniciansLoading(true)

    try {
      const response = await apiRequest('/api/technical-service/technicians?active=1')
      const items = Array.isArray(response.items) ? response.items : []
      setTechnicians(items.map((technician: ServiceTechnician) => ({
        ...technician,
        id: String(technician.id),
      })))
    } catch (caught) {
      setAssignError(caught instanceof Error ? caught.message : 'Usta listesi alÄ±namadÄ±.')
    } finally {
      setTechniciansLoading(false)
    }
  }, [])

  useEffect(() => {
    selectedIdRef.current = selectedId
  }, [selectedId])

  const loadRequestDetail = useCallback(async (id: string) => {
    const requestId = String(id)
    const requestToken = detailRequestTokenRef.current + 1
    detailRequestTokenRef.current = requestToken
    const expectedListRequest = selectedListRequest ?? requests.find((item) => item.id === requestId) ?? null
    const isCurrentRequest = () => detailRequestTokenRef.current === requestToken && selectedIdRef.current === requestId
    setDetailLoading(true)
    setDetailError(null)
    setSelectedDetailRequest(null)
    setSelectedEvents([])

    try {
      const response = await apiRequest(`/api/technical-service/requests/${id}`)

      if (!isCurrentRequest()) {
        return
      }

      const request = response.request

      if (!request) {
        setDetailError('Talep detaylarÄ± bulunamadÄ±.')
        setDetailLoading(false)

        return
      }

      const mappedDetail = mapApiRequest(request)
      const currentListRequest = expectedListRequest

      if (!currentListRequest || String(mappedDetail.id) !== requestId || mappedDetail.mrn !== currentListRequest.mrn) {
        console.error('Technical service detail mismatch', {
          selectedListRequest: currentListRequest,
          detail: mappedDetail,
        })
        setDetailError('SeÃ§ilen kayÄ±t ile detay verisi eÅŸleÅŸmedi. LÃ¼tfen listeyi yenileyin.')
        setSelectedDetailRequest(null)
        setSelectedEvents([])
        setDetailLoading(false)

        return
      }

      if (!isCurrentRequest()) {
        return
      }

      setSelectedDetailRequest(mappedDetail)
      setSelectedEvents(Array.isArray(request?.events) ? request.events : [])
    } catch (caught) {
      if (!isCurrentRequest()) {
        return
      }

      setDetailError(caught instanceof Error ? caught.message : 'Talep detaylarÄ± yÃ¼klenemedi.')
      setSelectedEvents([])
      setSelectedDetailRequest(null)
    } finally {
      if (isCurrentRequest()) {
        setDetailLoading(false)
      }
    }
  }, [requests, selectedListRequest])

  useEffect(() => {
    void Promise.resolve().then(loadSummary)
  }, [loadSummary])

  useEffect(() => {
    void Promise.resolve().then(loadRequests)
  }, [loadRequests])

  useEffect(() => {
    void Promise.resolve().then(loadTechnicians)
  }, [loadTechnicians])

  useEffect(() => {
    let cancelled = false

    if (!selectedId) {
      detailRequestTokenRef.current += 1
      void Promise.resolve().then(() => {
        if (cancelled) {
          return
        }

        setSelectedEvents([])
        setSelectedListRequest(null)
        setSelectedDetailRequest(null)
        setMikroMountCheck(null)
        setMikroMountError(null)
        setMikroMountLoading(false)
        setWarranty(null)
        setWarrantyError(null)
        setWarrantyLoading(false)
        setShowNearbyTechnicians(false)
        setDetailError(null)
        setDetailLoading(false)
      })

      return () => {
        cancelled = true
      }
    }

    void Promise.resolve().then(() => {
      if (!cancelled) {
        void loadRequestDetail(selectedId)
      }
    })

    return () => {
      cancelled = true
    }
  }, [loadRequestDetail, selectedId])

  useEffect(() => {
    if (!isDatePickerOpen) {
      return
    }

    const handlePointerDown = (event: MouseEvent) => {
      if (!datePickerRef.current?.contains(event.target as Node)) {
        setIsDatePickerOpen(false)
      }
    }

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setIsDatePickerOpen(false)
      }
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [isDatePickerOpen])

  const todayDate = useMemo(() => startOfLocalDay(new Date()), [])
  const weekStartDate = useMemo(() => startOfWeek(weekReferenceDate), [weekReferenceDate])
  const weekEndDate = useMemo(() => addDays(weekStartDate, 7), [weekStartDate])

  const isOpenRequest = useCallback((request: ServiceRequest) => {
    const normalized = normalizeTechnicalServiceText(request.status)

    return normalized !== 'tamamlandi' && normalized !== 'iptal'
  }, [])
  const isUnassignedRequest = useCallback((request: ServiceRequest) => isUnassignedWorkflowRequest(request), [])
  const isOverdueRequest = useCallback((request: ServiceRequest) => {
    const scheduled = getRequestScheduledDate(request)

    return scheduled !== null && scheduled < todayDate && isOpenRequest(request)
  }, [isOpenRequest, todayDate])

  const compareRequestsBySchedule = useCallback((a: ServiceRequest, b: ServiceRequest) => {
    const aScheduled = getRequestScheduledDate(a)
    const bScheduled = getRequestScheduledDate(b)

    if (aScheduled && bScheduled && aScheduled.getTime() !== bScheduled.getTime()) {
      return aScheduled.getTime() - bScheduled.getTime()
    }

    if (aScheduled && !bScheduled) {
      return -1
    }

    if (!aScheduled && bScheduled) {
      return 1
    }

    const aTime = a.scheduledTime?.trim() ?? ''
    const bTime = b.scheduledTime?.trim() ?? ''

    if (aTime !== bTime) {
      return aTime.localeCompare(bTime, 'tr')
    }

    const aCreated = parseLocalDateValue(a.createdAt)?.getTime() ?? 0
    const bCreated = parseLocalDateValue(b.createdAt)?.getTime() ?? 0

    return bCreated - aCreated
  }, [])

  const matchesSearchFilter = useCallback((request: ServiceRequest) => {
    const search = normalizeSearchText(filters.search)

    if (!search) {
      return true
    }

    const displayMrn = formatTechnicalServiceMrn(request)
    const values = [
      request.mrn,
      displayMrn,
      request.customer,
      request.phone,
      request.city,
      request.district,
      request.product,
      request.model,
      request.serialNumber,
      request.serviceType,
      request.status,
      request.technician,
      request.appointment,
      request.scheduledAt,
      request.scheduledDate,
      request.scheduledTime,
      request.notes,
      request.workflowStatus,
      request.customerContactStatus,
      request.customerPreferredDate,
      request.customerCallbackAt,
    ]

    return values.some((value) => normalizeSearchText(value).includes(search))
  }, [filters.search])

  const sortedRequests = useMemo(() => {
    return [...requests].sort(compareRequestsBySchedule)
  }, [compareRequestsBySchedule, requests])

  const serviceTypeOptions = useMemo(() => {
    return Array.from(new Set(requests.map((request) => request.serviceType).filter(Boolean))).sort((a, b) => a.localeCompare(b, 'tr'))
  }, [requests])

  const priorityOptions = useMemo(() => {
    return Array.from(new Set(requests.map((request) => request.priority).filter(Boolean))).sort((a, b) => a.localeCompare(b, 'tr'))
  }, [requests])

  const cityOptions = useMemo(() => {
    return Array.from(new Set(requests.map((request) => request.city.trim()).filter(Boolean))).sort((a, b) => a.localeCompare(b, 'tr'))
  }, [requests])

  const technicianOptions = useMemo(() => {
    return Array.from(
      new Set(
        requests
          .map((request) => request.technician.trim())
          .filter((value) => value !== '' && normalizeTechnicalServiceText(value) !== 'atanmadi'),
      ),
    ).sort((a, b) => a.localeCompare(b, 'tr'))
  }, [requests])

  const baseKanbanRequests = useMemo(() => {
    return sortedRequests.filter((request) => {
      if (!matchesSearchFilter(request)) {
        return false
      }

      if (filters.onlyOpen && !isOpenRequest(request)) {
        return false
      }

      if (filters.serviceType && request.serviceType !== filters.serviceType) {
        return false
      }

      if (filters.priority && request.priority !== filters.priority) {
        return false
      }

      if (filters.city && request.city !== filters.city) {
        return false
      }

      if (filters.technician) {
        if (filters.technician === '__unassigned__') {
          return isUnassignedRequest(request)
        }

        return request.technician.trim() === filters.technician
      }

      return true
    })
  }, [filters.city, filters.onlyOpen, filters.priority, filters.serviceType, filters.technician, isOpenRequest, isUnassignedRequest, matchesSearchFilter, sortedRequests])

  const kanbanFilteredRequests = useMemo(() => {
    if (selectedPlanDayKey === null) {
      return baseKanbanRequests
    }

    return baseKanbanRequests.filter((request) => {
      const scheduledDate = getRequestScheduledDate(request)

      return scheduledDate !== null && toDateKey(scheduledDate) === selectedPlanDayKey
    })
  }, [baseKanbanRequests, selectedPlanDayKey])

  const kanbanSummary = useMemo(() => ({
    total: kanbanFilteredRequests.length,
    open: kanbanFilteredRequests.filter((request) => isOpenRequest(request)).length,
    assigned: kanbanFilteredRequests.filter((request) => !isUnassignedRequest(request)).length,
    overdue: kanbanFilteredRequests.filter((request) => isOverdueRequest(request)).length,
  }), [isOpenRequest, isOverdueRequest, isUnassignedRequest, kanbanFilteredRequests])

  const selectedRequest = selectedId
    ? requests.find((request) => request.id === selectedId) ?? null
    : null
  const modalRequest = selectedDetailRequest ?? selectedListRequest ?? selectedRequest
  const selectedListDisplayMrn = selectedListRequest ? formatTechnicalServiceMrn(selectedListRequest) : null
  const selectedDetailDisplayMrn = selectedDetailRequest ? formatTechnicalServiceMrn(selectedDetailRequest) : null
  const modalDisplayMrn = selectedListDisplayMrn ?? selectedDetailDisplayMrn
  const openFieldDialog = useCallback((action: string, request: ServiceRequest | null) => {
    setFieldAction(action)
    setFieldNote('')
    setFieldIncompleteReason(request?.incompleteReason ?? request?.pendingReason ?? '')
    setFieldIncompleteWorkflowStatus(action === 'parts_pending' ? 'ParÃ§a Bekleniyor' : 'Beklemede')
    setFieldRequiresSecondVisit(action === 'second_visit_required' ? true : (request?.requiresSecondVisit ?? false))
    setFieldSecondVisitReason(request?.secondVisitReason ?? '')
    setFieldChecklist(
      request?.checklistPayload && Object.keys(request.checklistPayload).length > 0
        ? request.checklistPayload
        : Object.fromEntries(FIELD_CHECKLIST_ITEMS.map((item) => [item, false])),
    )
    setFieldBeforePhotoCount(String(request?.beforePhotoCount ?? 3))
    setFieldAfterPhotoCount(String(request?.afterPhotoCount ?? 3))
    setFieldGeneralPhotoCount(String(request?.generalPhotoCount ?? 1))
    setFieldDocumentStatus(request?.documentStatus ?? 'tamamlandÄ±')
    setFieldApprovalMethod((request?.customerClosureApprovalMethod as (typeof FIELD_CLOSURE_METHODS)[number] | null) ?? 'otp')
    setFieldApprovalCode(request?.customerClosureApprovalCode ?? '')
    setFieldSignatureName(request?.customerSignatureName ?? '')
    setFieldError(null)
    setFieldDialogOpen(true)
  }, [])
  const modalPayment = getServicePaymentInfo(
    modalRequest?.serviceType,
    modalRequest?.travelRoundTripKm,
    modalRequest?.travelFeeAmount,
    modalRequest?.travelBillableKm,
    modalRequest?.technicianPaymentAmount,
  )
  const assignTravelRoundTripKm = travelRoundTripKm.trim() === '' ? null : Number(travelRoundTripKm)
  const assignTravelPreview = calculateTravelPreview(
    typeof assignTravelRoundTripKm === 'number' && Number.isFinite(assignTravelRoundTripKm) && assignTravelRoundTripKm >= 0
      ? assignTravelRoundTripKm
      : null,
  )
  const assignPaymentPreview = getServicePaymentInfo(
    modalRequest?.serviceType,
    assignTravelPreview.roundTripKm,
    assignTravelPreview.travelFeeAmount,
  )
  const effectiveMountPaymentMissing = modalRequest?.serviceType === 'Montaj' && isMountPaymentMissing(mikroMountCheck)
  const mountPaymentAccepted = modalRequest?.serviceType === 'Montaj' && isMountPaymentAccepted(mikroMountCheck)
  const effectiveAssignOverrideReady = !effectiveMountPaymentMissing || (assignOverrideWithoutPayment && assignOverrideReason.trim().length >= 5)
  const canSubmitAssign = Boolean(
    !assignLoading &&
    assignTechnicianOption &&
    (assignTechnicianOption !== 'other' || assignOtherTechnician.trim()) &&
    travelRoundTripKm.trim() !== '' &&
    Number.isFinite(Number(travelRoundTripKm)) &&
    Number(travelRoundTripKm) >= 0 &&
    effectiveAssignOverrideReady
  )
  const technicianMatches = technicians
    .map((technician) => technicianMatchInfo(technician, modalRequest))
    .sort((a, b) => {
      if (a.rank !== b.rank) {
        return a.rank - b.rank
      }

      if (a.distanceKm !== null && b.distanceKm !== null && a.distanceKm !== b.distanceKm) {
        return a.distanceKm - b.distanceKm
      }

      return technicianDisplayName(a.technician).localeCompare(technicianDisplayName(b.technician), 'tr')
    })
  const sameCityTechnicians = technicianMatches.filter((match) => match.sameCity)
  const otherCityTechnicians = technicianMatches.filter((match) => !match.sameCity)
  const visibleTechnicianMatches = showNearbyTechnicians
    ? technicianMatches
    : sameCityTechnicians
  const assignmentReferenceDateKey = useMemo(() => {
    if (modalRequest?.scheduledDate) {
      return modalRequest.scheduledDate
    }

    if (modalRequest?.customerPreferredDate) {
      return modalRequest.customerPreferredDate
    }

    return toDateKey(selectedDate)
  }, [modalRequest?.customerPreferredDate, modalRequest?.scheduledDate, selectedDate])
  const assignmentReferenceRequests = useMemo(() => {
    return requests.filter((request) => {
      const requestDate = request.scheduledDate
        ?? (request.scheduledAt ? toDateKey(new Date(request.scheduledAt)) : null)

      return requestDate === assignmentReferenceDateKey
    })
  }, [assignmentReferenceDateKey, requests])
  const technicianAssignmentInsights = useMemo<TechnicianAssignmentInsight[]>(() => {
    const insights = technicianMatches.map((match) => {
      const technicianName = technicianDisplayName(match.technician)
      const scheduledJobs = assignmentReferenceRequests.filter((request) => {
        const requestTechnicianId = request.technicianId ? String(request.technicianId) : null
        const requestTechnicianName = request.technician?.trim() ?? ''

        return requestTechnicianId === match.technician.id
          || (requestTechnicianName !== '' && requestTechnicianName === technicianName)
      })
      const bookedSlots = new Set(
        scheduledJobs
          .map((request) => resolveAppointmentSlotValue(request.scheduledTime))
          .filter((slot): slot is (typeof APPOINTMENT_TIME_SLOTS)[number]['value'] => slot !== null),
      )
      const availableSlots = APPOINTMENT_TIME_SLOTS
        .filter((slot) => !bookedSlots.has(slot.value))
        .map((slot) => slot.value)
      const estimatedRoundTripKm = match.distanceKm !== null
        ? Math.round(match.distanceKm * 2 * 10) / 10
        : null
      const paymentPreview = getServicePaymentInfo(
        modalRequest?.serviceType,
        estimatedRoundTripKm,
      )
      const costDelta = paymentPreview.customerAmount !== null && paymentPreview.totalTechnicianCostAmount !== null
        ? paymentPreview.customerAmount - paymentPreview.totalTechnicianCostAmount
        : null

      return {
        id: match.technician.id,
        name: technicianName,
        location: [match.technician.city, match.technician.district].filter(Boolean).join(' / ') || 'Konum bilgisi yok',
        distanceKmLabel: match.distanceKm !== null ? `YaklaÅŸÄ±k ${match.distanceKm.toLocaleString('tr-TR')} km` : 'Mesafe yok',
        scheduledCount: scheduledJobs.length,
        availableSlots,
        technicianAmountLabel: paymentPreview.technicianAmountLabel,
        travelAmountLabel: paymentPreview.travelAmountLabel,
        totalCostLabel: paymentPreview.totalTechnicianCostLabel,
        costDeltaLabel: costDelta === null
          ? '-'
          : costDelta > 0
            ? `+${costDelta.toLocaleString('tr-TR')} TL kÃ¢r`
            : costDelta < 0
              ? `-${Math.abs(costDelta).toLocaleString('tr-TR')} TL fark`
              : '0 TL',
        recommended: false,
        estimatedRoundTripKm,
      }
    })

    const recommendedId = [...insights]
      .sort((a, b) => {
        const matchA = technicianMatches.find((match) => match.technician.id === a.id)
        const matchB = technicianMatches.find((match) => match.technician.id === b.id)
        const rankA = matchA?.rank ?? 99
        const rankB = matchB?.rank ?? 99

        if (rankA !== rankB) {
          return rankA - rankB
        }

        if (a.scheduledCount !== b.scheduledCount) {
          return a.scheduledCount - b.scheduledCount
        }

        const distanceA = matchA?.distanceKm ?? Number.POSITIVE_INFINITY
        const distanceB = matchB?.distanceKm ?? Number.POSITIVE_INFINITY

        return distanceA - distanceB
      })[0]?.id ?? null

    return insights.map((insight) => ({
      ...insight,
      recommended: insight.id === recommendedId,
    }))
  }, [assignmentReferenceRequests, modalRequest?.serviceType, technicianMatches])
  const assignmentScheduleSupport = useMemo(() => {
    const currentSchedule = modalRequest?.scheduledDate
      ? [
          formatTechnicalServiceDate(modalRequest.scheduledDate),
          modalRequest.scheduledTime || null,
        ].filter(Boolean).join(' Â· ')
      : modalRequest?.appointment || '-'

    const preferredSchedule = formatPreferredAppointmentLabel(modalRequest)
    const recommendedSlots = technicianAssignmentInsights.find((insight) => insight.recommended)?.availableSlots
      ?? technicianAssignmentInsights[0]?.availableSlots
      ?? []

    return {
      scheduledLabel: currentSchedule || '-',
      preferredLabel: preferredSchedule,
      customerContactLabel: modalRequest?.customerContactStatus || 'MÃ¼ÅŸteri teyidi yok',
      slotSuggestions: recommendedSlots.slice(0, 3),
    }
  }, [modalRequest, technicianAssignmentInsights])

  const weeklyDayCounts = useMemo(() => {
    const labels = ['Pzt', 'Sal', 'Ã‡ar', 'Per', 'Cum', 'Cmt', 'Paz']

    const densityLabelForCount = (count: number): 'Yok' | 'Dusuk' | 'Normal' | 'Orta' | 'Yogun' => {
      if (count <= 0) {
        return 'Yok'
      }

      if (count <= 5) {
        return 'Dusuk'
      }

      if (count <= 12) {
        return 'Normal'
      }

      if (count <= 18) {
        return 'Orta'
      }

      return 'Yogun'
    }

    return Array.from({ length: 7 }, (_, index) => {
      const date = addDays(weekStartDate, index)
      const count = sortedRequests.filter((request) => {
        const scheduled = getRequestScheduledDate(request)

        return scheduled !== null && isSameLocalDay(scheduled, date)
      }).length
      const overdueCount = sortedRequests.filter((request) => {
        const scheduled = getRequestScheduledDate(request)

        return scheduled !== null && isSameLocalDay(scheduled, date) && isOverdueRequest(request)
      }).length

      return {
        key: toDateKey(date),
        label: labels[index] ?? '',
        shortDate: date.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit' }),
        fullDate: date.toLocaleDateString('tr-TR', { day: '2-digit', month: 'long', year: 'numeric' }),
        count,
        overdueCount,
        densityLabel: densityLabelForCount(count),
        isToday: isSameLocalDay(date, todayDate),
        isSelected: selectedPlanDayKey === toDateKey(date),
      }
    })
  }, [isOverdueRequest, selectedPlanDayKey, sortedRequests, todayDate, weekStartDate])
  const weeklyPlanSummary = useMemo(() => {
    let weekPlanned = 0
    let todayPlanned = 0
    let overdue = 0
    let unscheduled = 0
    let completedOrCancelled = 0

    sortedRequests.forEach((request) => {
      const scheduled = getRequestScheduledDate(request)

      if (scheduled === null) {
        unscheduled += 1
      } else {
        if (scheduled >= weekStartDate && scheduled < weekEndDate) {
          weekPlanned += 1
        }

        if (isSameLocalDay(scheduled, todayDate)) {
          todayPlanned += 1
        }
      }

      if (isClosedStatus(request.status)) {
        completedOrCancelled += 1
      }

      if (isOverdueRequest(request)) {
        overdue += 1
      }
    })

    return { weekPlanned, todayPlanned, overdue, unscheduled, completedOrCancelled }
  }, [isOverdueRequest, sortedRequests, todayDate, weekEndDate, weekStartDate])

  const selectedPlanDaySummary = useMemo(() => {
    const matchingRequests = sortedRequests.filter((request) => {
      const scheduled = getRequestScheduledDate(request)

      return scheduled !== null && isSameLocalDay(scheduled, selectedDate)
    })

    return {
      count: matchingRequests.length,
      overdueCount: matchingRequests.filter((request) => isOverdueRequest(request)).length,
    }
  }, [isOverdueRequest, selectedDate, sortedRequests])

  const selectedDateLabel = selectedDate.toLocaleDateString('tr-TR', {
    day: '2-digit',
    month: 'long',
    weekday: 'long',
  })
  const selectedDateButtonLabel = selectedDate.toLocaleDateString('tr-TR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
  const selectedWeekLabel = `${weekStartDate.toLocaleDateString('tr-TR', { day: '2-digit', month: 'long' })} - ${addDays(weekStartDate, 6).toLocaleDateString('tr-TR', { day: '2-digit', month: 'long', year: 'numeric' })}`
  const activePlanDayLabel = selectedPlanDayKey
    ? selectedDate.toLocaleDateString('tr-TR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
    : null
  const weekSummaryItems = [
    { label: 'Bu Hafta Planli', value: weeklyPlanSummary.weekPlanned, tone: 'blue' as const },
    { label: 'Bugun Planli', value: weeklyPlanSummary.todayPlanned, tone: 'emerald' as const },
    { label: 'Geciken', value: weeklyPlanSummary.overdue, tone: 'rose' as const },
    { label: 'Tarihsiz', value: weeklyPlanSummary.unscheduled, tone: 'amber' as const },
    { label: 'Tamamlanan / Iptal', value: weeklyPlanSummary.completedOrCancelled, tone: 'slate' as const },
  ]
  const calendarMonthLabel = calendarMonth.toLocaleDateString('tr-TR', {
    month: 'long',
    year: 'numeric',
  }).replace(/^./, (character) => character.toLocaleUpperCase('tr-TR'))
  const calendarWeekdays = ['Pzt', 'Sal', 'Ã‡ar', 'Per', 'Cum', 'Cmt', 'Paz']
  const calendarDays = useMemo(() => {
    const monthStart = startOfMonth(calendarMonth)
    const gridStart = startOfWeek(monthStart)

    return Array.from({ length: 42 }, (_, index) => {
      const date = addDays(gridStart, index)

      return {
        key: toDateKey(date),
        date,
        label: String(date.getDate()),
        inCurrentMonth: date.getMonth() === calendarMonth.getMonth(),
        isToday: isSameLocalDay(date, todayDate),
        isSelected: isSameLocalDay(date, selectedDate),
      }
    })
  }, [calendarMonth, selectedDate, todayDate])
  const handleCreateChange = (field: keyof NewRequestForm, value: string) => {
    setCreateForm((current) => {
      if (field === 'city') {
        return {
          ...current,
          city: normalizeFormCity(value),
          district: '',
        }
      }

      if (field === 'district') {
        return {
          ...current,
          district: normalizeFormDistrict(current.city, value),
        }
      }

      return { ...current, [field]: value }
    })
  }

  const handleCreateReset = () => {
    setCreateForm(initialRequestForm)
  }

  useEffect(() => {
    const serialNo = selectedDetailRequest?.serialNumber?.trim() ?? ''
    const lookupToken = serialLookupTokenRef.current + 1
    serialLookupTokenRef.current = lookupToken
    const isCurrentLookup = () => serialLookupTokenRef.current === lookupToken

    void Promise.resolve().then(() => {
      if (!isCurrentLookup()) {
        return
      }

      setMikroMountCheck(null)
      setMikroMountError(null)
      setWarranty(null)
      setWarrantyError(null)

      if (!serialNo) {
        setMikroMountLoading(false)
        setWarrantyLoading(false)

        return
      }

      const params = new URLSearchParams({ serial_no: serialNo })

      setMikroMountLoading(true)
      setWarrantyLoading(true)

      void Promise.allSettled([
        apiRequest(`/api/technical-service/mikro/serial-history?${params.toString()}`),
        apiRequest(`/api/technical-service/warranty/serial?${params.toString()}`),
      ]).then(([historyResponse, warrantyResponse]) => {
        if (!isCurrentLookup()) {
          return
        }

        if (historyResponse.status === 'fulfilled') {
          setMikroMountCheck((historyResponse.value as MikroSerialHistoryResponse).decision)
        } else {
          setMikroMountCheck(null)
          setMikroMountError(historyResponse.reason instanceof Error ? historyResponse.reason.message : 'Mikro montaj kontrolÃ¼ yapÄ±lamadÄ±.')
        }

        if (warrantyResponse.status === 'fulfilled') {
          setWarranty(warrantyResponse.value as WarrantySerialResponse)
        } else {
          setWarranty(null)
          setWarrantyError(warrantyResponse.reason instanceof Error ? warrantyResponse.reason.message : 'Garanti bilgisi alÄ±namadÄ±.')
        }
      }).finally(() => {
        if (isCurrentLookup()) {
          setMikroMountLoading(false)
          setWarrantyLoading(false)
        }
      })
    })
  }, [selectedDetailRequest?.serialNumber])

  const handleAssignReset = () => {
    setAssignTechnicianOption('')
    setAssignOtherTechnician('')
    setAssignNote('')
    setTravelRoundTripKm('')
    setAssignOverrideWithoutPayment(false)
    setAssignOverrideReason('')
    setShowNearbyTechnicians(false)
    setAssignError(null)
  }

  const handleScheduleReset = () => {
    setScheduleDate('')
    setScheduleTimeSlot('')
    setScheduleNote('')
    setScheduleError(null)
  }

  const handleContactReset = () => {
    setContactAction(null)
    setContactMethod('telefon')
    setContactNote('')
    setContactPreferredDate('')
    setContactPreferredTimeStart('')
    setContactPreferredTimeEnd('')
    setContactCallbackAt('')
    setContactRejectionReason('')
    setContactCancellationReason('')
    setContactError(null)
  }

  const handleFieldReset = useCallback(() => {
    setFieldAction(null)
    setFieldNote('')
    setFieldIncompleteReason('')
    setFieldIncompleteWorkflowStatus('Beklemede')
    setFieldRequiresSecondVisit(false)
    setFieldSecondVisitReason('')
    setFieldChecklist(Object.fromEntries(FIELD_CHECKLIST_ITEMS.map((item) => [item, false])))
    setFieldBeforePhotoCount('3')
    setFieldAfterPhotoCount('3')
    setFieldGeneralPhotoCount('1')
    setFieldDocumentStatus('tamamlandÄ±')
    setFieldApprovalMethod('otp')
    setFieldApprovalCode('')
    setFieldSignatureName('')
    setFieldError(null)
  }, [])

  const handleCompleteReset = () => {
    setCompletionReason('')
    setCompletionOtherNote('')
    setInstallationCompletedAt('')
    setInstallationCompletionNote('')
    setCompleteError(null)
  }

  const openCompleteDialog = () => {
    setInstallationCompletedAt(toTechnicalServiceDateTimeInputValue(modalRequest?.scheduledAt ?? null))
    setCompleteDialogOpen(true)
  }

  const handleReopenReset = () => {
    setReopenReason('')
    setReopenNote('')
    setReopenError(null)
  }

  const handleReopenSubmit = async () => {
    if (!selectedId) {
      return
    }

    if (!reopenReason) {
      setReopenError('Yeniden aÃ§ma nedeni seÃ§in.')

      return
    }

    if (reopenReason === 'DiÄŸer' && !reopenNote.trim()) {
      setReopenError('DiÄŸer nedeni seÃ§ildiÄŸinde aÃ§Ä±klama zorunludur.')

      return
    }

    setReopenLoading(true)
    setReopenError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/status`, {
        method: 'POST',
        body: JSON.stringify({
          status: 'Yeni',
          reopen_reason: reopenReason,
          reopen_note: reopenNote || null,
          note: reopenNote || reopenReason,
        }),
      })

      setReopenDialogOpen(false)
      handleReopenReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setReopenError(caught instanceof Error ? caught.message : 'Talep yeniden aÃ§ma iÅŸlemi baÅŸarÄ±sÄ±z oldu.')
    } finally {
      setReopenLoading(false)
    }
  }

  const handleWorkflowAction = async (action: string) => {
    if (!selectedId) {
      return
    }

    if ([
      'customer_called',
      'customer_unreachable',
      'customer_callback_scheduled',
      'customer_confirmation_pending',
      'customer_confirmed',
      'customer_rejected',
      'wrong_number',
      'customer_requested_cancel',
    ].includes(action)) {
      setContactAction(action)
      setContactDialogOpen(true)
      setContactError(null)

      return
    }

    if (['field_travel_started', 'field_arrived', 'field_work_started'].includes(action)) {
      await submitFieldAction(action, {})

      return
    }

    if ([
      'checklist_updated',
      'photos_updated',
      'customer_closure_approved',
      'field_marked_incomplete',
      'parts_pending',
      'second_visit_required',
      'field_completed',
    ].includes(action)) {
      openFieldDialog(action, selectedDetailRequest ?? selectedListRequest ?? selectedRequest)

      return
    }

    setWorkflowActionLoading(action)
    setDetailError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/workflow`, {
        method: 'PATCH',
        body: JSON.stringify({
          action,
        }),
      })

      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setDetailError(caught instanceof Error ? caught.message : 'Workflow aksiyonu uygulanamadÄ±.')
    } finally {
      setWorkflowActionLoading(null)
    }
  }

  const handleScheduleSubmit = async () => {
    if (!selectedId) {
      return
    }

    if (!scheduleDate || !scheduleTimeSlot) {
      setScheduleError('LÃ¼tfen randevu tarihi ve saat aralÄ±ÄŸÄ± seÃ§in.')

      return
    }

    setScheduleLoading(true)
    setScheduleError(null)

    try {
      const selectedTimeSlot = APPOINTMENT_TIME_SLOTS.find((slot) => slot.value === scheduleTimeSlot)

      await apiRequest(`/api/technical-service/requests/${selectedId}/schedule`, {
        method: 'PATCH',
        body: JSON.stringify({
          scheduled_date: scheduleDate,
          scheduled_time: selectedTimeSlot?.start ?? '10:00',
          note: scheduleNote || null,
        }),
      })

      setScheduleDialogOpen(false)
      handleScheduleReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setScheduleError(caught instanceof Error ? caught.message : 'Randevu planlama iÅŸlemi baÅŸarÄ±sÄ±z oldu.')
    } finally {
      setScheduleLoading(false)
    }
  }

  const handleContactActionSubmit = async () => {
    if (!selectedId || !contactAction) {
      return
    }

    setContactLoading(true)
    setContactError(null)

    try {
      const payload: Record<string, string | null> = {
        action: contactAction,
        note: contactNote || null,
      }

      if (contactAction === 'customer_called') {
        payload.contact_method = contactMethod
      }

      if (contactAction === 'customer_unreachable' && contactCallbackAt) {
        payload.customer_callback_at = contactCallbackAt
      }

      if (contactAction === 'customer_callback_scheduled') {
        payload.customer_callback_at = contactCallbackAt || null
      }

      if (contactAction === 'customer_confirmation_pending' || contactAction === 'customer_confirmed') {
        payload.customer_preferred_date = contactPreferredDate || null
        payload.customer_preferred_time_start = contactPreferredTimeStart || null
        payload.customer_preferred_time_end = contactPreferredTimeEnd || null
      }

      if (contactAction === 'customer_confirmed') {
        payload.customer_confirmation_method = contactMethod
      }

      if (contactAction === 'customer_rejected') {
        payload.customer_rejection_reason = contactRejectionReason || null
      }

      if (contactAction === 'customer_requested_cancel') {
        payload.cancellation_reason = contactCancellationReason || null
      }

      await apiRequest(`/api/technical-service/requests/${selectedId}/contact-log`, {
        method: 'POST',
        body: JSON.stringify(payload),
      })

      setContactDialogOpen(false)
      handleContactReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setContactError(caught instanceof Error ? caught.message : 'MÃ¼ÅŸteri iletiÅŸimi kaydedilemedi.')
    } finally {
      setContactLoading(false)
    }
  }

  const submitFieldAction = useCallback(async (action: string, payload: Record<string, unknown> = {}) => {
    if (!selectedId) {
      return
    }

    setFieldLoading(true)
    setFieldError(null)

    const actionPath = action === 'field_travel_started'
      ? 'start-travel'
      : action === 'field_arrived'
        ? 'arrive'
        : action === 'field_work_started'
          ? 'start-work'
          : action === 'checklist_updated'
            ? 'checklist'
            : action === 'photos_updated'
              ? 'photos'
              : action === 'customer_closure_approved'
                ? 'customer-closure-approval'
                : action === 'field_completed'
                  ? 'complete'
                  : 'mark-incomplete'

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/field/${actionPath}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })

      setFieldDialogOpen(false)
      handleFieldReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setFieldError(caught instanceof Error ? caught.message : 'Saha aksiyonu kaydedilemedi.')
    } finally {
      setFieldLoading(false)
    }
  }, [handleFieldReset, loadRequestDetail, loadRequests, loadSummary, selectedId])

  const handleFieldActionSubmit = async () => {
    if (!fieldAction) {
      return
    }

    if (fieldAction === 'checklist_updated') {
      await submitFieldAction(fieldAction, {
        checklist_payload: fieldChecklist,
        note: fieldNote || null,
      })

      return
    }

    if (fieldAction === 'photos_updated') {
      await submitFieldAction(fieldAction, {
        before_photo_count: Number(fieldBeforePhotoCount || 0),
        after_photo_count: Number(fieldAfterPhotoCount || 0),
        general_photo_count: Number(fieldGeneralPhotoCount || 0),
        document_status: fieldDocumentStatus,
        note: fieldNote || null,
      })

      return
    }

    if (fieldAction === 'customer_closure_approved') {
      await submitFieldAction(fieldAction, {
        approval_method: fieldApprovalMethod,
        approval_code: fieldApprovalCode || null,
        signature_name: fieldSignatureName || null,
        note: fieldNote || null,
      })

      return
    }

    if (fieldAction === 'field_marked_incomplete' || fieldAction === 'parts_pending' || fieldAction === 'second_visit_required') {
      await submitFieldAction(fieldAction, {
        workflow_status: fieldAction === 'parts_pending' ? 'ParÃ§a Bekleniyor' : fieldIncompleteWorkflowStatus,
        incomplete_reason: fieldIncompleteReason,
        pending_reason: fieldNote || fieldIncompleteReason,
        requires_second_visit: fieldAction === 'second_visit_required' || fieldRequiresSecondVisit,
        second_visit_reason: fieldAction === 'second_visit_required' ? (fieldSecondVisitReason || fieldIncompleteReason) : (fieldSecondVisitReason || null),
        note: fieldNote || null,
      })

      return
    }

    if (fieldAction === 'field_completed') {
      await submitFieldAction(fieldAction, {
        note: fieldNote || null,
      })
    }
  }

  const handleAssignSubmit = async () => {
    if (!selectedId) {
      return
    }

    const isManualTechnician = assignTechnicianOption === 'other'
    const selectedTechnicianRecord = technicians.find((technician) => technician.id === assignTechnicianOption)
    const selectedTechnician = isManualTechnician
      ? assignOtherTechnician.trim()
      : selectedTechnicianRecord ? technicianDisplayName(selectedTechnicianRecord) : ''

    if (!selectedTechnician) {
      setAssignError('LÃ¼tfen bir usta seÃ§in veya manuel isim girin.')

      return
    }

    if (effectiveMountPaymentMissing && !assignOverrideWithoutPayment) {
      setAssignError('Montaj Ã¶demesi alÄ±nmadÄ±ÄŸÄ± iÃ§in doÄŸrudan atama yapÄ±lamaz.')

      return
    }

    if (effectiveMountPaymentMissing && assignOverrideReason.trim().length < 5) {
      setAssignError('Atama nedeni en az 5 karakter olmalÄ±dÄ±r.')

      return
    }

    const parsedTravelRoundTripKm = Number(travelRoundTripKm)

    if (travelRoundTripKm.trim() === '' || !Number.isFinite(parsedTravelRoundTripKm) || parsedTravelRoundTripKm < 0) {
      setAssignError('LÃ¼tfen gidiÅŸ-geliÅŸ km bilgisini girin.')

      return
    }

    setAssignLoading(true)
    setAssignError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/assign`, {
        method: 'POST',
        body: JSON.stringify({
          ...(isManualTechnician
            ? { technician_name: selectedTechnician }
            : { technical_service_technician_id: assignTechnicianOption }),
          travel_round_trip_km: parsedTravelRoundTripKm,
          mount_payment_missing: effectiveMountPaymentMissing,
          override_without_payment: effectiveMountPaymentMissing ? assignOverrideWithoutPayment : false,
          override_reason: effectiveMountPaymentMissing ? assignOverrideReason.trim() || null : null,
          note: assignNote || null,
        }),
      })

      setAssignDialogOpen(false)
      handleAssignReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setAssignError(caught instanceof Error ? caught.message : 'Usta atama iÅŸlemi baÅŸarÄ±sÄ±z oldu.')
    } finally {
      setAssignLoading(false)
    }
  }

  const handleCompleteSubmit = async () => {
    if (!selectedId) {
      return
    }

    if (!completionReason) {
      setCompleteError('LÃ¼tfen bir kapanÄ±ÅŸ nedeni seÃ§in.')

      return
    }

    const isOtherReason = completionReason === 'DiÄŸer'
    const notes = isOtherReason ? completionOtherNote.trim() : completionReason

    if (isOtherReason && !notes) {
      setCompleteError('LÃ¼tfen aÃ§Ä±klama girin.')
      setCompleteLoading(false)

      return
    }

    const nextStatus = completionReason === 'Montaj tamamlandÄ±' ? 'TamamlandÄ±' : 'Ä°ptal'
    const isCompletingInstallation = nextStatus === 'TamamlandÄ±' && modalRequest?.serviceType === 'Montaj'

    if (isCompletingInstallation && !installationCompletedAt) {
      setCompleteError('Fiili montaj tarihi zorunludur.')

      return
    }

    if (isCompletingInstallation) {
      if (!/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(installationCompletedAt)) {
        setCompleteError('Fiili montaj saati 00:00 - 23:59 aralÄ±ÄŸÄ±nda HH:mm formatÄ±nda olmalÄ±dÄ±r.')

        return
      }

      const actualDate = new Date(installationCompletedAt)
      const scheduledDate = modalRequest?.scheduledAt ? new Date(modalRequest.scheduledAt) : null
      const nowDate = new Date()
      const actualDateKey = actualDate.toISOString().slice(0, 10)
      const scheduledDateKey = scheduledDate && !Number.isNaN(scheduledDate.getTime()) ? scheduledDate.toISOString().slice(0, 10) : null
      const differsFromSchedule = scheduledDateKey !== null && actualDateKey !== scheduledDateKey
      const olderThanOneDay = nowDate.getTime() - actualDate.getTime() > 24 * 60 * 60 * 1000

      if ((differsFromSchedule || olderThanOneDay) && !installationCompletionNote.trim()) {
        setCompleteError('Fiili montaj tarihi randevudan farklÄ±ysa veya kapanÄ±ÅŸtan 1 gÃ¼nden fazla eskiyse aÃ§Ä±klama zorunludur.')

        return
      }
    }

    setCompleteLoading(true)
    setCompleteError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/status`, {
        method: 'POST',
        body: JSON.stringify({
          status: nextStatus,
          resolution_notes: notes || null,
          ...(isCompletingInstallation
            ? {
                installation_completed_at: installationCompletedAt,
                installation_completion_note: installationCompletionNote || null,
                note: installationCompletionNote || notes || null,
              }
            : {}),
        }),
      })

      setCompleteDialogOpen(false)
      handleCompleteReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setCompleteError(caught instanceof Error ? caught.message : 'Talep kapatma iÅŸlemi baÅŸarÄ±sÄ±z oldu.')
    } finally {
      setCompleteLoading(false)
    }
  }

  const handleCreateSubmit = async () => {
    setCreateLoading(true)
    setCreateError(null)

    try {
      const response = await apiRequest('/api/technical-service/requests', {
        method: 'POST',
        body: JSON.stringify({
          customer_name: createForm.customer,
          customer_phone: createForm.phone,
          customer_city: normalizeFormCity(createForm.city),
          customer_district: normalizeFormDistrict(createForm.city, createForm.district),
          service_address: createForm.address,
          product_name: createForm.product,
          serial_number: createForm.serialNumber || null,
          service_type: createForm.serviceType,
          description: createForm.notes || null,
          source_channel: 'panel',
        }),
      })

      const createdRequest = mapApiRequest(response.request ?? response)
      setRequests((current) => [createdRequest, ...current])
      setSelectedId(createdRequest.id)
      setIsDialogOpen(false)
      handleCreateReset()
      void loadSummary()
    } catch (caught) {
      setCreateError(caught instanceof Error ? caught.message : 'Teknik servis talebi kaydedilemedi.')
    } finally {
      setCreateLoading(false)
    }
  }

  const openDatePicker = useCallback(() => {
    setCalendarMonth(startOfMonth(selectedDate))
    setIsDatePickerOpen(true)
  }, [selectedDate])

  const handleSelectNavigatorDay = useCallback((key: string) => {
    const nextDate = parseLocalDateValue(key)

    if (!nextDate) {
      return
    }

    const normalizedDate = startOfLocalDay(nextDate)
    setSelectedDate(normalizedDate)
    setWeekReferenceDate(normalizedDate)
    setSelectedPlanDayKey((current) => current === key ? null : key)
  }, [])

  const openRequestDetail = useCallback((request: ServiceRequest) => {
    detailRequestTokenRef.current += 1
    setSelectedListRequest(request)
    setSelectedDetailRequest(null)
    setSelectedEvents([])
    setDetailError(null)
    setMikroMountCheck(null)
    setMikroMountError(null)
    setMikroMountLoading(false)
    setWarranty(null)
    setWarrantyError(null)
    setWarrantyLoading(false)
    setSelectedId(request.id)
    setIsDetailDialogOpen(true)
  }, [])

  return (
    <>
      <Head title="Teknik Servis Operasyon Merkezi" />

      <div className="relative min-h-screen overflow-hidden bg-[#F4F7FB]">
        <div className="pointer-events-none absolute inset-x-0 top-0 h-80 bg-[radial-gradient(circle_at_top_left,_rgba(6,20,58,0.08),_transparent_38%),radial-gradient(circle_at_top_right,_rgba(37,99,235,0.06),_transparent_34%)]" />
        <div className="relative w-full max-w-none space-y-6 px-4 py-6 md:px-6 xl:px-8 2xl:px-10">
        <section className="rounded-[24px] border border-slate-200 bg-white px-5 py-5 shadow-sm sm:px-6 sm:py-6">
          <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
            <div className="flex items-start gap-4">
              <div className="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-[20px] bg-[#06143A] text-white shadow-[0_12px_24px_rgba(6,20,58,0.18)]">
                <Wrench className="h-6 w-6" />
              </div>
              <div className="max-w-3xl">
                <h1 className="text-3xl font-semibold tracking-tight text-slate-950">Teknik Servis</h1>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                  Montaj ve servis taleplerini aÅŸama bazlÄ± takip edin.
                </p>
              </div>
            </div>

            <div className="flex flex-col gap-3 xl:items-end">
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:min-w-[440px]">
                {[
                  { label: 'AÃ§Ä±k Ä°ÅŸ', value: String(kanbanSummary.open) },
                  { label: 'AtanmÄ±ÅŸ', value: String(kanbanSummary.assigned) },
                  { label: 'Geciken', value: String(kanbanSummary.overdue) },
                  { label: 'Toplam', value: String(kanbanSummary.total) },
                ].map((item) => (
                  <div key={item.label} className="rounded-[20px] border border-slate-200 bg-slate-50 px-4 py-3">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">{item.label}</p>
                    <p className="mt-2 text-2xl font-semibold tracking-[-0.03em] text-slate-950">{item.value}</p>
                  </div>
                ))}
              </div>

              <div className="flex flex-wrap items-center gap-2 xl:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  className="h-11 rounded-2xl border-slate-200 bg-white px-4 text-slate-700 hover:bg-slate-50"
                  onClick={() => {
                    void loadRequests()
                    void loadSummary()
                                      }}
                >
                  <RefreshCw className="mr-2 h-4 w-4" />
                  Yenile
                </Button>

              <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
              <DialogTrigger asChild>
                <Button type="button" className="h-11 rounded-2xl bg-[#06143A] px-5 text-white shadow-[0_12px_24px_rgba(6,20,58,0.16)] hover:bg-[#0b1d51]">
                  <Plus className="mr-2 h-4 w-4" />
                  Yeni Talep
                </Button>
              </DialogTrigger>
            <DialogContent className="max-w-2xl">
              <DialogHeader>
                <DialogTitle>Yeni Servis Talebi</DialogTitle>
                <DialogDescription>
                  Yeni servis talebi bu ekran Ã¼zerinden backend API aracÄ±lÄ±ÄŸÄ±yla kaydedilecektir.
                </DialogDescription>
              </DialogHeader>
              {createError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {createError}
                </div>
              ) : null}
              <div className="grid gap-4 pt-2">
                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    MÃ¼ÅŸteri adÄ±
                    <Input
                      value={createForm.customer}
                      onChange={(event) => handleCreateChange('customer', event.target.value)}
                      placeholder="MÃ¼ÅŸteri adÄ±"
                    />
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Telefon
                    <Input
                      value={createForm.phone}
                      onChange={(event) => handleCreateChange('phone', event.target.value)}
                      placeholder="Telefon"
                    />
                  </label>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Ä°l
                    <select
                      value={createForm.city}
                      onChange={(event) => handleCreateChange('city', event.target.value)}
                      className={selectClassName}
                    >
                      <option value="">SeÃ§iniz</option>
                      {TURKEY_PROVINCES.map((province) => (
                        <option key={province.plateCode} value={province.name}>
                          {province.name}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Ä°lÃ§e
                    <select
                      value={createForm.district}
                      onChange={(event) => handleCreateChange('district', event.target.value)}
                      disabled={!createForm.city}
                      className={selectClassName}
                    >
                      <option value="">{createForm.city ? 'SeÃ§iniz' : 'Ã–nce il seÃ§iniz'}</option>
                      {hasCreateDistrictFallback ? (
                        <option value={createForm.district}>Mevcut deÄŸer: {createForm.district}</option>
                      ) : null}
                      {createDistrictOptions.map((district) => (
                        <option key={district.normalizedName} value={district.name}>
                          {district.name}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Adres
                  <textarea
                    value={createForm.address}
                    onChange={(event) => handleCreateChange('address', event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    placeholder="Adres"
                  />
                </label>

                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    ÃœrÃ¼n
                    <Input
                      value={createForm.product}
                      onChange={(event) => handleCreateChange('product', event.target.value)}
                      placeholder="ÃœrÃ¼n"
                    />
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Seri No
                    <Input
                      value={createForm.serialNumber}
                      onChange={(event) => handleCreateChange('serialNumber', event.target.value)}
                      placeholder="Seri No"
                    />
                  </label>
                </div>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Servis Tipi
                  <select
                    value={createForm.serviceType}
                    onChange={(event) => handleCreateChange('serviceType', event.target.value)}
                    className={selectClassName}
                  >
                    <option value="">SeÃ§iniz</option>
                    <option value="Montaj">Montaj</option>
                    <option value="ArÄ±za">ArÄ±za</option>
                    <option value="Kontrol">Kontrol</option>
                  </select>
                </label>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Not
                  <textarea
                    value={createForm.notes}
                    onChange={(event) => handleCreateChange('notes', event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    placeholder="Talep notu"
                  />
                </label>
              </div>
              <DialogFooter>
                <Button variant="outline" type="button" onClick={() => setIsDialogOpen(false)}>
                  Ä°ptal
                </Button>
                <Button type="button" onClick={handleCreateSubmit} disabled={createLoading}>
                  {createLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
            </Dialog>
              </div>
          </div>
        </div>
      </section>

      <TechnicalServicePageLinks />

      <TechnicalServiceWeekNavigator
        weekLabel={selectedWeekLabel}
        selectedDateButtonLabel={selectedDateButtonLabel}
        selectedDayLabel={selectedDateLabel}
        selectedDayCount={selectedPlanDaySummary.count}
        selectedDayOverdueCount={selectedPlanDaySummary.overdueCount}
        hasActiveDayFilter={selectedPlanDayKey !== null}
        weekDays={weeklyDayCounts}
        summaryItems={weekSummaryItems}
        isDatePickerOpen={isDatePickerOpen}
        calendarMonthLabel={calendarMonthLabel}
        calendarWeekdays={calendarWeekdays}
        calendarDays={calendarDays}
        datePickerRef={datePickerRef}
        onPreviousWeek={() => {
          const nextDate = addDays(weekReferenceDate, -7)
          setWeekReferenceDate(nextDate)
          setSelectedDate(startOfLocalDay(nextDate))
          setSelectedPlanDayKey(null)
        }}
        onToday={() => {
          const now = startOfLocalDay(new Date())
          setWeekReferenceDate(now)
          setSelectedDate(now)
          setCalendarMonth(startOfMonth(now))
          setSelectedPlanDayKey(null)
          setIsDatePickerOpen(false)
        }}
        onToggleDatePicker={() => {
          if (isDatePickerOpen) {
            setIsDatePickerOpen(false)

            return
          }

          openDatePicker()
        }}
        onPreviousMonth={() => setCalendarMonth((current) => addMonths(current, -1))}
        onNextMonth={() => setCalendarMonth((current) => addMonths(current, 1))}
        onSelectCalendarDay={(day) => {
          const normalizedDate = startOfLocalDay(day)
          setSelectedDate(normalizedDate)
          setWeekReferenceDate(normalizedDate)
          setCalendarMonth(startOfMonth(day))
          setSelectedPlanDayKey(null)
          setIsDatePickerOpen(false)
        }}
        onSelectDay={handleSelectNavigatorDay}
        onCloseDatePicker={() => setIsDatePickerOpen(false)}
        onNextWeek={() => {
          const nextDate = addDays(weekReferenceDate, 7)
          setWeekReferenceDate(nextDate)
          setSelectedDate(startOfLocalDay(nextDate))
          setSelectedPlanDayKey(null)
        }}
      />

      {selectedPlanDayKey !== null && activePlanDayLabel ? (
        <section className="rounded-[20px] border border-blue-200 bg-blue-50/80 px-5 py-3 shadow-sm sm:px-6">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm font-medium text-blue-900">
              Tarih filtresi: {activePlanDayLabel}
            </p>
            <button
              type="button"
              onClick={() => setSelectedPlanDayKey(null)}
              className="inline-flex items-center rounded-full border border-blue-200 bg-white px-3 py-1.5 text-sm font-medium text-blue-700 transition hover:bg-blue-100"
            >
              Kaldir
            </button>
          </div>
        </section>
      ) : null}

      <section className="rounded-[24px] border border-slate-200 bg-white px-5 py-5 shadow-sm sm:px-6 sm:py-6">
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_repeat(4,minmax(0,1fr))]">
          <label className="grid gap-2 text-sm font-medium text-slate-700">
            Arama
            <Input
              value={filters.search}
              onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
              placeholder="MRN, mÃ¼ÅŸteri, Ã¼rÃ¼n/model, seri no, teknisyen"
              className="h-11 rounded-2xl border-slate-200 bg-slate-50"
            />
          </label>

          <label className="grid gap-2 text-sm font-medium text-slate-700">
            Servis tipi
            <select
              value={filters.serviceType ?? ''}
              onChange={(event) => setFilters((current) => ({ ...current, serviceType: event.target.value }))}
              className={selectClassName}
            >
              <option value="">TÃ¼mÃ¼</option>
              {serviceTypeOptions.map((option) => (
                <option key={option} value={option}>{option}</option>
              ))}
            </select>
          </label>

          <label className="grid gap-2 text-sm font-medium text-slate-700">
            Ã–ncelik
            <select
              value={filters.priority ?? ''}
              onChange={(event) => setFilters((current) => ({ ...current, priority: event.target.value }))}
              className={selectClassName}
            >
              <option value="">TÃ¼mÃ¼</option>
              {priorityOptions.map((option) => (
                <option key={option} value={option}>{option}</option>
              ))}
            </select>
          </label>

          <label className="grid gap-2 text-sm font-medium text-slate-700">
            Ä°l
            <select
              value={filters.city ?? ''}
              onChange={(event) => setFilters((current) => ({ ...current, city: event.target.value }))}
              className={selectClassName}
            >
              <option value="">TÃ¼mÃ¼</option>
              {cityOptions.map((option) => (
                <option key={option} value={option}>{option}</option>
              ))}
            </select>
          </label>

          <label className="grid gap-2 text-sm font-medium text-slate-700">
            Teknisyen
            <select
              value={filters.technician ?? ''}
              onChange={(event) => setFilters((current) => ({ ...current, technician: event.target.value }))}
              className={selectClassName}
            >
              <option value="">TÃ¼mÃ¼</option>
              <option value="__unassigned__">AtanmamÄ±ÅŸ</option>
              {technicianOptions.map((option) => (
                <option key={option} value={option}>{option}</option>
              ))}
            </select>
          </label>
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={() => setFilters((current) => ({ ...current, onlyOpen: !current.onlyOpen }))}
            className={[
              'inline-flex rounded-full border px-4 py-2 text-sm font-medium transition',
              filters.onlyOpen
                ? 'border-[#06143A] bg-[#06143A] text-white'
                : 'border-slate-200 bg-slate-50 text-slate-700',
            ].join(' ')}
          >
            Sadece aÃ§Ä±k iÅŸler
          </button>
          <button
            type="button"
            onClick={() => setFilters(initialFilters)}
            className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-white"
          >
            Filtreleri temizle
          </button>
        </div>
      </section>

          <Dialog open={assignDialogOpen} onOpenChange={(open) => {
            setAssignDialogOpen(open)

            if (!open) {
              handleAssignReset()
            }
          }}>
            <DialogContent className="max-w-lg max-h-[92vh] overflow-y-auto">
              <DialogClose asChild>
                <button
                  type="button"
                  className="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                >
                  Ã—
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Usta Ata</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} iÃ§in ${modalRequest?.customer} adÄ±na usta atayÄ±n.` : 'SeÃ§ili talep yok.'}
                </DialogDescription>
                {modalRequest?.serviceType ? (
                  <p className="text-sm leading-6 text-slate-600">
                    Bu talep {modalRequest.serviceType} iÅŸlemidir. Ustaya / servise Ã¶denecek tutar: {modalPayment.technicianAmountLabel}
                  </p>
                ) : null}
              </DialogHeader>

              {assignError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {assignError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                <div className="grid gap-3 sm:grid-cols-2">
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Kesin Randevu</p>
                    <p className="mt-2 font-semibold text-slate-900">{assignmentScheduleSupport.scheduledLabel}</p>
                    <p className="mt-1 text-xs text-slate-500">Usta atamasÄ± bu plan Ã¼zerinden deÄŸerlendirilir.</p>
                  </div>
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">MÃ¼ÅŸteri Tercihi</p>
                    <p className="mt-2 font-semibold text-slate-900">{assignmentScheduleSupport.preferredLabel}</p>
                    <p className="mt-1 text-xs text-slate-500">{assignmentScheduleSupport.customerContactLabel}</p>
                  </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Ã–nerilen Slotlar</p>
                  <p className="mt-2 font-semibold text-slate-900">
                    {assignmentScheduleSupport.slotSuggestions.length > 0 ? assignmentScheduleSupport.slotSuggestions.join(' Â· ') : 'BoÅŸ slot bilgisi yok'}
                  </p>
                </div>

                <div className={[
                  'grid gap-3 rounded-2xl border p-4 text-sm',
                  effectiveMountPaymentMissing
                    ? 'border-rose-200 bg-rose-50 text-rose-900'
                    : mountPaymentAccepted
                      ? 'border-green-200 bg-green-50 text-green-900'
                      : 'border-slate-200 bg-slate-50 text-slate-900',
                ].join(' ')}>
                  <div>
                    <p className={[
                      'text-xs font-semibold uppercase tracking-[0.12em]',
                      effectiveMountPaymentMissing ? 'text-rose-700' : mountPaymentAccepted ? 'text-green-700' : 'text-slate-700',
                    ].join(' ')}>Montaj KararÄ±</p>
                    <p className="mt-1">SonuÃ§: {mikroMountCheck?.montaj_durumu ?? 'Kontrol Edilemedi'}</p>
                  </div>
                  <div className="grid gap-2 sm:grid-cols-2">
                    <div>
                      <span className={[
                        'text-xs font-semibold uppercase tracking-[0.12em]',
                        effectiveMountPaymentMissing ? 'text-rose-700' : mountPaymentAccepted ? 'text-green-700' : 'text-slate-700',
                      ].join(' ')}>Dayanak</span>
                      <p className="mt-1">{mikroMountCheck?.montaj_durumu === 'Montaj Sonradan Dahil' ? 'Mikro + sonradan montaj kaydÄ±' : 'Mikro son geÃ§erli satÄ±ÅŸ kaydÄ±'}</p>
                    </div>
                    <div>
                      <span className={[
                        'text-xs font-semibold uppercase tracking-[0.12em]',
                        effectiveMountPaymentMissing ? 'text-rose-700' : mountPaymentAccepted ? 'text-green-700' : 'text-slate-700',
                      ].join(' ')}>Montaj Ã¶demesi</span>
                      <p className="mt-1">{effectiveMountPaymentMissing ? 'AlÄ±nmadÄ±' : mountPaymentAccepted ? 'AlÄ±ndÄ± / engel yok' : 'Kontrol edilemedi'}</p>
                    </div>
                  </div>
                  {effectiveMountPaymentMissing ? (
                    <p>Montaj Ã¶demesi alÄ±nmamÄ±ÅŸ gÃ¶rÃ¼nÃ¼yor. Atama yapmak iÃ§in operasyon onayÄ± gerekir.</p>
                  ) : null}
                  {mikroMountCheck?.montaj_ek_aciklama ? <p>{mikroMountCheck.montaj_ek_aciklama}</p> : null}
                  {mikroMountCheck?.farkli_cari_uyarisi ? <p>Sonradan montaj carisi, son geÃ§erli satÄ±ÅŸ carisinden farklÄ±.</p> : null}
                </div>

                {effectiveMountPaymentMissing ? (
                  <div className="grid gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <div>
                      <p className="font-semibold">Montaj Ã¶demesi alÄ±nmamÄ±ÅŸ gÃ¶rÃ¼nÃ¼yor. Atama yapmak iÃ§in operasyon onayÄ± gerekir.</p>
                      <p className="mt-1">Operasyon zorunlu durumda devam edecekse kontrollÃ¼ override kullanÄ±n.</p>
                    </div>

                    <label className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-white px-4 py-3">
                      <input
                        type="checkbox"
                        checked={assignOverrideWithoutPayment}
                        onChange={(event) => setAssignOverrideWithoutPayment(event.target.checked)}
                        className="mt-1 h-4 w-4 accent-primary"
                      />
                      <span className="font-medium text-slate-900">Montaj hariÃ§ iÅŸe atama yapÄ±lmasÄ±na onay veriyorum.</span>
                    </label>

                    {assignOverrideWithoutPayment ? (
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Atama nedeni
                        <textarea
                          value={assignOverrideReason}
                          onChange={(event) => setAssignOverrideReason(event.target.value)}
                          className="min-h-[96px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                          placeholder="Ã–rn. MÃ¼ÅŸteri montaj sonrasÄ± Ã¶deme yapacak / elden Ã¶deme alÄ±nacak / yÃ¶netici onayÄ± var"
                        />
                      </label>
                    ) : null}
                  </div>
                ) : null}

                <fieldset className="grid gap-3">
                  <legend className="text-sm font-medium text-slate-700">Usta / Ã‡ilingir adÄ±</legend>
                  <div className="grid gap-2">
                    {techniciansLoading ? (
                      <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                        Usta listesi yÃ¼kleniyor...
                      </div>
                    ) : null}
                    {!techniciansLoading && technicians.length === 0 ? (
                      <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                        Aktif usta kaydÄ± bulunamadÄ±. Manuel giriÅŸ iÃ§in DiÄŸer seÃ§eneÄŸini kullanabilirsiniz.
                      </div>
                    ) : null}
                    {!techniciansLoading && technicians.length > 0 && sameCityTechnicians.length > 0 ? (
                      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">AynÄ± ÅŸehirdeki ustalar</p>
                    ) : null}
                    {!techniciansLoading && technicians.length > 0 && sameCityTechnicians.length === 0 ? (
                      <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                        Bu taleple aynÄ± ÅŸehirde aktif usta bulunamadÄ±.
                      </div>
                    ) : null}
                    {visibleTechnicianMatches.map((match, index) => (
                      <div key={match.technician.id} className="grid gap-2">
                        {showNearbyTechnicians && index === sameCityTechnicians.length && otherCityTechnicians.length > 0 ? (
                          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">YakÄ±n / diÄŸer ÅŸehirlerdeki ustalar</p>
                        ) : null}
                        <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition hover:border-slate-300">
                          <input
                            type="radio"
                            name="assignTechnician"
                            value={match.technician.id}
                            checked={assignTechnicianOption === match.technician.id}
                            onChange={() => {
                              setAssignTechnicianOption(match.technician.id)
                              setShowNearbyTechnicians(false)

                              if (travelRoundTripKm.trim() === '' && match.distanceKm !== null) {
                                setTravelRoundTripKm(String(Math.round(match.distanceKm * 2 * 10) / 10))
                              }
                            }}
                            className="mt-1 h-4 w-4 accent-primary"
                          />
                          <span className="min-w-0 flex-1">
                            <span className="flex flex-wrap items-center gap-2">
                              <span className="font-semibold">{technicianDisplayName(match.technician)}</span>
                              <span className={[
                                'rounded-full px-2 py-0.5 text-[0.68rem] font-semibold',
                                match.rank === 0 ? 'bg-emerald-50 text-emerald-700' : match.rank === 1 ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600',
                              ].join(' ')}>
                                {match.badge}
                              </span>
                            </span>
                            <span className="mt-1 block text-xs font-normal text-slate-500">
                              {[match.technician.phone, [match.technician.city, match.technician.district].filter(Boolean).join(' / ')].filter(Boolean).join(' Â· ') || 'Ä°letiÅŸim / konum bilgisi yok'}
                            </span>
                            {(match.technician.mikro_cari_adi || match.technician.mikro_cari_kodu || match.distanceKm !== null) ? (
                              <span className="mt-1 block text-xs font-normal text-slate-500">
                                {[match.technician.mikro_cari_adi || match.technician.mikro_cari_kodu, match.distanceKm !== null ? `YaklaÅŸÄ±k ${match.distanceKm.toLocaleString('tr-TR')} km` : null].filter(Boolean).join(' Â· ')}
                              </span>
                            ) : null}
                            {(() => {
                              const insight = technicianAssignmentInsights.find((item) => item.id === match.technician.id)

                              if (!insight) {
                                return null
                              }

                              return (
                                <span className="mt-2 block text-xs font-normal text-slate-600">
                                  {[`${insight.scheduledCount} iÅŸ`, insight.availableSlots.length > 0 ? `Uygun: ${insight.availableSlots.slice(0, 2).join(' / ')}` : 'BoÅŸ slot gÃ¶rÃ¼nmÃ¼yor', insight.costDeltaLabel].filter(Boolean).join(' Â· ')}
                                </span>
                              )
                            })()}
                          </span>
                        </label>
                      </div>
                    ))}
                    {!showNearbyTechnicians && otherCityTechnicians.length > 0 ? (
                      <Button type="button" variant="secondary" onClick={() => setShowNearbyTechnicians(true)}>
                        DiÄŸer / YakÄ±n Ä°lleri GÃ¶ster
                      </Button>
                    ) : null}
                    <label className="flex cursor-pointer items-center rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition hover:border-slate-300">
                      <input
                        type="radio"
                        name="assignTechnician"
                        value="other"
                        checked={assignTechnicianOption === 'other'}
                        onChange={() => {
                          setAssignTechnicianOption('other')
                          setShowNearbyTechnicians(false)
                        }}
                        className="mr-3 h-4 w-4 accent-primary"
                      />
                      DiÄŸer
                      </label>
                  </div>
                </fieldset>

                {assignTechnicianOption === 'other' ? (
                  <div className="grid gap-4">
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Manuel usta adÄ±
                      <Input
                        value={assignOtherTechnician}
                        onChange={(event) => setAssignOtherTechnician(event.target.value)}
                        placeholder="Usta adÄ±"
                      />
                    </label>

                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Not / aÃ§Ä±klama
                      <textarea
                        value={assignNote}
                        onChange={(event) => setAssignNote(event.target.value)}
                        className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                        placeholder="Not / aÃ§Ä±klama"
                      />
                    </label>
                  </div>
                ) : null}

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  GidiÅŸ-geliÅŸ km
                  <Input
                    type="number"
                    min="0"
                    step="0.01"
                    value={travelRoundTripKm}
                    onChange={(event) => setTravelRoundTripKm(event.target.value)}
                    placeholder="Ã–rn. 42"
                  />
                </label>

                <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-slate-600">Ãœcretsiz km</span>
                    <span className="font-semibold text-slate-900">{assignPaymentPreview.freeKmLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-slate-600">Ãœcretli km</span>
                    <span className="font-semibold text-slate-900">{assignPaymentPreview.billableKmLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-slate-600">Yol Ã¼creti</span>
                    <span className="font-semibold text-slate-900">{assignPaymentPreview.travelAmountLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3 border-t border-slate-200 pt-3">
                    <span className="font-medium text-slate-600">Toplam usta maliyeti</span>
                    <span className="font-semibold text-slate-950">{assignPaymentPreview.totalTechnicianCostLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3 border-t border-slate-200 pt-3">
                    <span className="font-medium text-slate-600">MÃ¼ÅŸteriden alÄ±nan Ã¼cret</span>
                    <span className="font-semibold text-slate-950">{modalPayment.customerAmountLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-slate-600">Net fark / kÃ¢r</span>
                    <span className="font-semibold text-slate-950">
                      {modalPayment.customerAmount !== null && assignPaymentPreview.totalTechnicianCostAmount !== null
                        ? `${(modalPayment.customerAmount - assignPaymentPreview.totalTechnicianCostAmount).toLocaleString('tr-TR')} TL`
                        : '-'}
                    </span>
                  </div>
                </div>
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleAssignReset}>
                    Ä°ptal
                  </Button>
                </DialogClose>
                <Button
                  type="button"
                  onClick={handleAssignSubmit}
                  disabled={!canSubmitAssign}
                >
                  {assignLoading ? 'Kaydediliyor...' : 'Usta Ata'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={scheduleDialogOpen} onOpenChange={(open) => {
            setScheduleDialogOpen(open)

            if (!open) {
              handleScheduleReset()
            }
          }}>
            <DialogContent className="max-w-lg">
              <DialogHeader>
                <DialogTitle>Randevu Planla</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} iÃ§in kesin randevu planlayÄ±n.` : 'SeÃ§ili talep yok.'}
                </DialogDescription>
              </DialogHeader>

              {scheduleError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {scheduleError}
                </div>
              ) : null}

              {modalRequest?.customerPreferredDate || modalRequest?.customerPreferredTimeStart ? (
                <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                  <p className="font-semibold">MÃ¼ÅŸteri Tercihi</p>
                  <p className="mt-1">Tarih: {modalRequest.customerPreferredDate ?? '-'}</p>
                  <p className="mt-1">Saat: {modalRequest.customerPreferredTimeStart ?? '-'}{modalRequest.customerPreferredTimeEnd ? ` - ${modalRequest.customerPreferredTimeEnd}` : ''}</p>
                  <Button
                    type="button"
                    variant="secondary"
                    className="mt-3"
                    onClick={() => {
                      setScheduleDate(modalRequest.customerPreferredDate ?? '')

                      if (modalRequest.customerPreferredTimeStart) {
                        const matchingSlot = APPOINTMENT_TIME_SLOTS.find((slot) => slot.start === modalRequest.customerPreferredTimeStart)
                        setScheduleTimeSlot(matchingSlot?.value ?? '')
                      }
                    }}
                  >
                    MÃ¼ÅŸteri tercihini kullan
                  </Button>
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Randevu tarihi
                  <Input type="date" value={scheduleDate} onChange={(event) => setScheduleDate(event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Randevu saat aralÄ±ÄŸÄ±
                  <select
                    value={scheduleTimeSlot}
                    onChange={(event) => setScheduleTimeSlot(event.target.value)}
                    className={selectClassName}
                  >
                    <option value="">Saat aralÄ±ÄŸÄ± seÃ§in</option>
                    {APPOINTMENT_TIME_SLOTS.map((slot) => (
                      <option key={slot.value} value={slot.value}>
                        {slot.value}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Planlama notu
                  <textarea
                    value={scheduleNote}
                    onChange={(event) => setScheduleNote(event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    placeholder="MÃ¼ÅŸteri tercihi ile fark varsa kÄ±sa aÃ§Ä±klama ekleyin"
                  />
                </label>
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleScheduleReset}>
                    Ä°ptal
                  </Button>
                </DialogClose>
                <Button type="button" onClick={handleScheduleSubmit} disabled={scheduleLoading}>
                  {scheduleLoading ? 'Kaydediliyor...' : 'Randevuyu Planla'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={contactDialogOpen} onOpenChange={(open) => {
            setContactDialogOpen(open)

            if (!open) {
              handleContactReset()
            }
          }}>
            <DialogContent className="max-w-lg max-h-[92vh] overflow-y-auto">
              <DialogHeader>
                <DialogTitle>{contactAction ? contactAction : 'MÃ¼ÅŸteri Ä°letiÅŸimi'}</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} iÃ§in mÃ¼ÅŸteri iletiÅŸimi kaydÄ± oluÅŸturun.` : 'SeÃ§ili talep yok.'}
                </DialogDescription>
              </DialogHeader>

              {contactError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {contactError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                {(contactAction === 'customer_called' || contactAction === 'customer_confirmed') ? (
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Ä°letiÅŸim / onay yÃ¶ntemi
                    <select value={contactMethod} onChange={(event) => setContactMethod(event.target.value)} className={selectClassName}>
                      {CONTACT_CONFIRMATION_METHODS.map((method) => (
                        <option key={method} value={method}>{method}</option>
                      ))}
                    </select>
                  </label>
                ) : null}

                {(contactAction === 'customer_confirmation_pending' || contactAction === 'customer_confirmed') ? (
                  <>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Uygun gÃ¼n
                      <Input type="date" value={contactPreferredDate} onChange={(event) => setContactPreferredDate(event.target.value)} />
                    </label>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        BaÅŸlangÄ±Ã§ saati
                        <Input type="time" value={contactPreferredTimeStart} onChange={(event) => setContactPreferredTimeStart(event.target.value)} />
                      </label>
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        BitiÅŸ saati
                        <Input type="time" value={contactPreferredTimeEnd} onChange={(event) => setContactPreferredTimeEnd(event.target.value)} />
                      </label>
                    </div>
                  </>
                ) : null}

                {(contactAction === 'customer_unreachable' || contactAction === 'customer_callback_scheduled') ? (
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Tekrar arama tarihi
                    <Input type="datetime-local" value={contactCallbackAt} onChange={(event) => setContactCallbackAt(event.target.value)} />
                  </label>
                ) : null}

                {contactAction === 'customer_rejected' ? (
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Ret nedeni
                    <Input value={contactRejectionReason} onChange={(event) => setContactRejectionReason(event.target.value)} />
                  </label>
                ) : null}

                {contactAction === 'customer_requested_cancel' ? (
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Ä°ptal nedeni
                    <Input value={contactCancellationReason} onChange={(event) => setContactCancellationReason(event.target.value)} />
                  </label>
                ) : null}

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Not
                  <textarea
                    value={contactNote}
                    onChange={(event) => setContactNote(event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                  />
                </label>
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleContactReset}>
                    Ä°ptal
                  </Button>
                </DialogClose>
                <Button type="button" onClick={handleContactActionSubmit} disabled={contactLoading}>
                  {contactLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={fieldDialogOpen} onOpenChange={(open) => {
            setFieldDialogOpen(open)

            if (!open) {
              handleFieldReset()
            }
          }}>
            <DialogContent className="max-w-lg max-h-[92vh] overflow-y-auto">
              <DialogHeader>
                <DialogTitle>{({
                  checklist_updated: 'Checklist GÃ¼ncelle',
                  photos_updated: 'FotoÄŸraf SayÄ±larÄ±nÄ± GÃ¼ncelle',
                  customer_closure_approved: 'MÃ¼ÅŸteri KapanÄ±ÅŸ OnayÄ± Al',
                  field_marked_incomplete: 'TamamlanamadÄ±',
                  parts_pending: 'ParÃ§a Bekleniyor',
                  second_visit_required: 'Ä°kinci Randevu Gerekli',
                  field_completed: 'Ä°ÅŸi Tamamla',
                } as Record<string, string>)[fieldAction ?? ''] ?? 'Saha SÃ¼reci'}</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} iÃ§in saha sÃ¼reci bilgisini gÃ¼ncelleyin.` : 'SeÃ§ili talep yok.'}
                </DialogDescription>
              </DialogHeader>

              {fieldError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {fieldError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                {fieldAction === 'checklist_updated' ? (
                  <fieldset className="grid gap-3">
                    <legend className="text-sm font-medium text-slate-700">Checklist</legend>
                    <div className="grid gap-2">
                      {FIELD_CHECKLIST_ITEMS.map((item) => (
                        <label
                          key={item}
                          className="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900"
                        >
                          <input
                            type="checkbox"
                            checked={Boolean(fieldChecklist[item])}
                            onChange={(event) => setFieldChecklist((current) => ({ ...current, [item]: event.target.checked }))}
                            className="mt-1 h-4 w-4 accent-primary"
                          />
                          <span className="flex-1 break-words">{item}</span>
                        </label>
                      ))}
                    </div>
                  </fieldset>
                ) : null}

                {fieldAction === 'photos_updated' ? (
                  <>
                    <div className="grid gap-4 sm:grid-cols-3">
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Ã–ncesi fotoÄŸraf
                        <Input type="number" min="0" value={fieldBeforePhotoCount} onChange={(event) => setFieldBeforePhotoCount(event.target.value)} />
                      </label>
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        SonrasÄ± fotoÄŸraf
                        <Input type="number" min="0" value={fieldAfterPhotoCount} onChange={(event) => setFieldAfterPhotoCount(event.target.value)} />
                      </label>
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Genel fotoÄŸraf
                        <Input type="number" min="0" value={fieldGeneralPhotoCount} onChange={(event) => setFieldGeneralPhotoCount(event.target.value)} />
                      </label>
                    </div>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Belge durumu
                      <select
                        value={fieldDocumentStatus}
                        onChange={(event) => setFieldDocumentStatus(event.target.value)}
                        className={selectClassName}
                      >
                        <option value="eksik">Eksik</option>
                        <option value="tamamlandÄ±">TamamlandÄ±</option>
                        <option value="gerekli_degil">Belge gerekli deÄŸil</option>
                      </select>
                    </label>
                  </>
                ) : null}

                {fieldAction === 'customer_closure_approved' ? (
                  <>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Onay yÃ¶ntemi
                      <select value={fieldApprovalMethod} onChange={(event) => setFieldApprovalMethod(event.target.value)} className={selectClassName}>
                        {FIELD_CLOSURE_METHODS.map((method) => (
                          <option key={method} value={method}>{method}</option>
                        ))}
                      </select>
                    </label>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Onay kodu
                        <Input value={fieldApprovalCode} onChange={(event) => setFieldApprovalCode(event.target.value)} />
                      </label>
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Ä°mza adÄ±
                        <Input value={fieldSignatureName} onChange={(event) => setFieldSignatureName(event.target.value)} />
                      </label>
                    </div>
                  </>
                ) : null}

                {(fieldAction === 'field_marked_incomplete' || fieldAction === 'parts_pending' || fieldAction === 'second_visit_required') ? (
                  <>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Tamamlanamama nedeni
                      <Input value={fieldIncompleteReason} onChange={(event) => setFieldIncompleteReason(event.target.value)} />
                    </label>

                    {fieldAction === 'field_marked_incomplete' ? (
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Bekleme durumu
                        <select
                          value={fieldIncompleteWorkflowStatus}
                          onChange={(event) => setFieldIncompleteWorkflowStatus(event.target.value)}
                          className={selectClassName}
                        >
                          <option value="Beklemede">Beklemede</option>
                          <option value="MÃ¼ÅŸteri Yerinde Yok">MÃ¼ÅŸteri Yerinde Yok</option>
                          <option value="Montaj Yeri HazÄ±r DeÄŸil">Montaj Yeri HazÄ±r DeÄŸil</option>
                        </select>
                      </label>
                    ) : null}

                    <label className="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900">
                      <input
                        type="checkbox"
                        checked={fieldAction === 'second_visit_required' ? true : fieldRequiresSecondVisit}
                        onChange={(event) => setFieldRequiresSecondVisit(event.target.checked)}
                        className="mt-1 h-4 w-4 accent-primary"
                        disabled={fieldAction === 'second_visit_required'}
                      />
                      <span>Ä°kinci randevu gerekli</span>
                    </label>

                    {(fieldAction === 'second_visit_required' || fieldRequiresSecondVisit) ? (
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Ä°kinci randevu nedeni
                        <Input value={fieldSecondVisitReason} onChange={(event) => setFieldSecondVisitReason(event.target.value)} />
                      </label>
                    ) : null}
                  </>
                ) : null}

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Not
                  <textarea
                    value={fieldNote}
                    onChange={(event) => setFieldNote(event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                  />
                </label>
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleFieldReset}>
                    Ä°ptal
                  </Button>
                </DialogClose>
                <Button type="button" onClick={handleFieldActionSubmit} disabled={fieldLoading}>
                  {fieldLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={completeDialogOpen} onOpenChange={(open) => {
            setCompleteDialogOpen(open)

            if (!open) {
              handleCompleteReset()
            }
          }}>
            <DialogContent className="max-w-lg max-h-[92vh] overflow-y-auto">
              <DialogClose asChild>
                <button
                  type="button"
                  className="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                >
                  Ã—
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Talebi kapat / iptal et</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} iÃ§in ${modalRequest?.customer} talebinin sonucunu seÃ§in.` : 'SeÃ§ili talep yok.'}
                </DialogDescription>
                {modalRequest?.serviceType ? (
                  <p className="text-sm leading-6 text-slate-600">
                    MÃ¼ÅŸteriden alÄ±nacak tutar: {modalPayment.customerAmountLabel}
                  </p>
                ) : null}
              </DialogHeader>

              {completeError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {completeError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                <fieldset className="grid gap-3">
                  <legend className="text-sm font-medium text-slate-700">KapanÄ±ÅŸ / iptal nedeni</legend>
                  <div className="grid gap-2">
                    {CLOSURE_REASONS.map((reason) => (
                      <label
                        key={reason}
                        className="flex cursor-pointer items-center rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition hover:border-slate-300"
                      >
                        <input
                          type="radio"
                          name="completionReason"
                          value={reason}
                          checked={completionReason === reason}
                          onChange={() => setCompletionReason(reason)}
                          className="mr-3 h-4 w-4 accent-primary"
                        />
                        {reason}
                      </label>
                    ))}
                  </div>
                </fieldset>

                {completionReason === 'DiÄŸer' ? (
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    AÃ§Ä±klama
                    <textarea
                      value={completionOtherNote}
                      onChange={(event) => setCompletionOtherNote(event.target.value)}
                      className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                      placeholder="AÃ§Ä±klama"
                    />
                  </label>
                ) : null}

                {completionReason === 'Montaj tamamlandÄ±' && modalRequest?.serviceType === 'Montaj' ? (
                  <div className="grid gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <div className="text-sm leading-6 text-amber-800">
                      Garanti, talebin panelde kapatÄ±ldÄ±ÄŸÄ± tarihte deÄŸil, fiili montaj tarihinde baÅŸlar.
                    </div>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Fiili Montaj Tarihi
                      <DateTimeFields
                        value={installationCompletedAt}
                        max={toTechnicalServiceDateTimeInputValue(null)}
                        onChange={setInstallationCompletedAt}
                      />
                      <span className="text-xs font-normal text-slate-600">Garanti bu tarihten itibaren baÅŸlar.</span>
                    </label>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Fiili montaj aÃ§Ä±klamasÄ±
                      <textarea
                        value={installationCompletionNote}
                        onChange={(event) => setInstallationCompletionNote(event.target.value)}
                        className="min-h-[84px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                        placeholder="Randevu tarihinden farklÄ±ysa veya geÃ§miÅŸ tarih girildiyse aÃ§Ä±klama zorunlu"
                      />
                    </label>
                  </div>
                ) : null}
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleCompleteReset}>
                    Ä°ptal
                  </Button>
                </DialogClose>
                <Button
                  type="button"
                  onClick={handleCompleteSubmit}
                  disabled={
                    completeLoading ||
                    !completionReason ||
                    (completionReason === 'DiÄŸer' && !completionOtherNote.trim()) ||
                    (completionReason === 'Montaj tamamlandÄ±' && modalRequest?.serviceType === 'Montaj' && !installationCompletedAt)
                  }
                >
                  {completeLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={reopenDialogOpen} onOpenChange={(open) => {
            setReopenDialogOpen(open)

            if (!open) {
              handleReopenReset()
            }
          }}>
            <DialogContent className="max-w-lg">
              <DialogClose asChild>
                <button
                  type="button"
                  className="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                >
                  Ã—
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Talebi yeniden aÃ§</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} iÃ§in ${modalRequest?.customer} talebi yeniden aÃ§Ä±lacak.` : 'SeÃ§ili talep yok.'}
                </DialogDescription>
              </DialogHeader>

              {reopenError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {reopenError}
                </div>
              ) : null}

              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm leading-6 text-rose-700">
                Bu talep daha Ã¶nce tamamlandÄ±ysa garanti baÅŸlangÄ±cÄ± geri alÄ±nmaz. Yeniden aÃ§ma iÅŸlemi sadece operasyonel dÃ¼zeltme iÃ§indir.
              </div>

              {modalRequest?.serviceType === 'Montaj' && modalRequest.completedAt ? (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-800">
                  Bu montaj talebi tamamlanmÄ±ÅŸ gÃ¶rÃ¼nÃ¼yor. Garanti baÅŸladÄ±ysa Ã¶nerilen aksiyon yeni baÄŸlÄ± servis/takip talebi aÃ§maktÄ±r.
                </div>
              ) : null}

              <fieldset className="grid gap-3">
                <legend className="text-sm font-medium text-slate-700">Yeniden aÃ§ma nedeni</legend>
                <div className="grid gap-2">
                  {REOPEN_REASONS.map((reason) => (
                    <label
                      key={reason}
                      className="flex cursor-pointer items-center rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition hover:border-slate-300"
                    >
                      <input
                        type="radio"
                        name="reopenReason"
                        value={reason}
                        checked={reopenReason === reason}
                        onChange={() => setReopenReason(reason)}
                        className="mr-3 h-4 w-4 accent-primary"
                      />
                      {reason}
                    </label>
                  ))}
                </div>
              </fieldset>

              <label className="grid gap-2 text-sm font-medium text-slate-700">
                AÃ§Ä±klama
                <textarea
                  value={reopenNote}
                  onChange={(event) => setReopenNote(event.target.value)}
                  className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                  placeholder={reopenReason === 'DiÄŸer' ? 'AÃ§Ä±klama zorunlu' : 'Opsiyonel aÃ§Ä±klama'}
                />
              </label>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleReopenReset}>
                    Ä°ptal
                  </Button>
                </DialogClose>
                <Button type="button" onClick={handleReopenSubmit} disabled={reopenLoading || !reopenReason || (reopenReason === 'DiÄŸer' && !reopenNote.trim())}>
                  {reopenLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        <TechnicalServiceKanbanBoard
          requests={kanbanFilteredRequests}
          selectedRequestId={selectedRequest?.id ?? ''}
          onSelectRequest={openRequestDetail}
          loading={loading}
          error={error}
        />

        <Dialog open={isDetailDialogOpen} onOpenChange={(open) => {
          if (!open) {
            detailRequestTokenRef.current += 1
            setIsDetailDialogOpen(false)
            setSelectedId(null)
            setSelectedEvents([])
            setSelectedDetailRequest(null)
            setDetailError(null)
            setWarranty(null)
            setWarrantyError(null)
            setWarrantyLoading(false)
          }
        }}>
          <DialogContent className="flex w-[min(1280px,calc(100vw-64px))] max-w-none max-h-[calc(100vh-48px)] flex-col overflow-hidden rounded-[28px] p-0 shadow-[0_30px_80px_rgba(15,23,42,0.2)] sm:max-w-none max-sm:w-[calc(100vw-24px)]">
            <div className="flex h-full min-h-[420px] flex-col overflow-hidden bg-white">
              <DialogHeader className="sticky top-0 z-20 border-b border-slate-200 bg-white px-4 py-4 md:px-6 md:py-5">
                <div className="flex items-start justify-between gap-4">
                  <div className="min-w-0 space-y-2">
                    <DialogTitle className="text-base font-semibold text-slate-900">Talep DetayÄ±</DialogTitle>
                    <DialogDescription className="text-sm text-slate-600">
                      SeÃ§ili talebin MRN, mÃ¼ÅŸteri, durum ve Ã¶ncelik bilgilerini kontrol edin.
                    </DialogDescription>
                  </div>
                  <DialogClose asChild>
                    <button
                      type="button"
                      className="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                      aria-label="Talep detay modalini kapat"
                    >
                      Ã—
                    </button>
                  </DialogClose>
                </div>
                <div className="mt-4 flex flex-wrap items-center gap-2">
                  <span className="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-900">{modalDisplayMrn ?? modalRequest?.mrn ?? 'SeÃ§ili talep yok'}</span>
                  <span className="min-w-0 truncate text-sm text-slate-600">MÃ¼ÅŸteri: {modalRequest?.customer ?? '-'}</span>
                  <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">Durum: {modalRequest?.status ?? '-'}</span>
                </div>
              </DialogHeader>

              <div className="flex-1 min-h-0 overflow-y-auto overscroll-contain px-4 py-4 md:px-6 md:py-5">
                {detailLoading ? (
                  <div className="rounded-3xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                    Detay yÃ¼kleniyor...
                  </div>
                ) : (selectedDetailRequest || modalRequest) ? (
                  <ServiceRequestDetails
                    request={selectedDetailRequest ?? modalRequest!}
                    displayMrn={modalDisplayMrn ?? undefined}
                    events={selectedEvents}
                    loading={detailLoading}
                    error={detailError}
                    mikroMountCheck={mikroMountCheck}
                    mikroMountLoading={mikroMountLoading}
                    mikroMountError={mikroMountError}
                    warranty={warranty}
                    warrantyLoading={warrantyLoading}
                    warrantyError={warrantyError}
                    onAssign={() => setAssignDialogOpen(true)}
                    onSchedule={() => setScheduleDialogOpen(true)}
                    onComplete={openCompleteDialog}
                    onReopen={() => setReopenDialogOpen(true)}
                    onWorkflowAction={handleWorkflowAction}
                    workflowActionInFlight={workflowActionLoading}
                    technicianSuggestions={technicianAssignmentInsights.slice(0, 4)}
                    scheduleSupport={assignmentScheduleSupport}
                  />
                ) : (
                  <div className="rounded-3xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                    SeÃ§ilen kayÄ±t iÃ§in detay bekleniyor...
                  </div>
                )}
              </div>
            </div>
          </DialogContent>
        </Dialog>

        </div>
      </div>
    </>
  )
}

export default function TechnicalService() {
  return <TechnicalServiceOperationCenter />
}

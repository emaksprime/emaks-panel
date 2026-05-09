import { Head } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { DateTimeFields } from '@/components/technical-service/DateTimeFields'
import {
  type OperationQuickFilterKey,
  type WorkflowFilterKey,
  TechnicalServiceOperationsDashboard,
} from '@/components/technical-service/OperationCenterDashboard'
import { ServiceRequestDetails } from '@/components/technical-service/ServiceRequestDetails'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'
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
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight, Wrench } from 'lucide-react'
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
  missing_info_reason?: string | null
  pending_reason?: string | null
  requires_reschedule?: boolean | number | string | null
  reschedule_reason?: string | null
  document_status?: string | null
  photo_status?: string | null
  customer_closure_approval_status?: string | null
  customer_closure_approved_at?: string | null
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
}

type OperationsDashboardRequest = {
  id: number | string
  mrn: string
  customer_name: string
  status: string
  scheduled_at?: string | null
}

type OperationsDashboardResponse = {
  summary: Record<string, number>
  today_appointments: OperationsDashboardRequest[]
  overdue_requests: OperationsDashboardRequest[]
  warranty_started_requests: OperationsDashboardRequest[]
  past_scheduled_not_completed: OperationsDashboardRequest[]
}

type QuickFilterKey = OperationQuickFilterKey
type WorkflowQueueKey = WorkflowFilterKey

const initialFilters: FilterState = {
  search: '',
  status: '',
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
  'Montaj tamamlandı',
  'Müşterinin kapısı uygun değildi',
  'Müşteri siparişi iptal etti',
  'Müşteri randevuya gelmedi / evde yoktu',
  'Ürün / seri numarası uyumsuz',
  'Servis ücreti kabul edilmedi',
  'Diğer',
] as const

const REOPEN_REASONS = [
  'Yanlışlıkla tamamlandı',
  'Eksik fotoğraf / belge',
  'Müşteri onayı hatası',
  'Usta yanlış kapattı',
  'Operasyon düzeltmesi',
  'Diğer',
] as const

const APPOINTMENT_TIME_SLOTS = [
  { value: '10:00 - 12:00', start: '10:00' },
  { value: '12:00 - 14:00', start: '12:00' },
  { value: '14:00 - 16:00', start: '14:00' },
  { value: '16:00 - 18:00', start: '16:00' },
] as const

function isMountPaymentMissing(result: MikroMountCheckResult | null | undefined): boolean {
  return result?.montaj_durumu === 'Montaj Hariç'
}

function isMountPaymentAccepted(result: MikroMountCheckResult | null | undefined): boolean {
  return result?.montaj_durumu === 'Montaj Dahil' || result?.montaj_durumu === 'Montaj Sonradan Dahil'
}

function normalizeSearchText(value: string | null | undefined): string {
  return normalizeTechnicalServiceText(value)
    .replace(/[-\s\p{Punctuation}]+/gu, '')
}

function statusFilterLabel(status: FilterState['status']): string {
  switch (status) {
    case 'unassigned':
      return 'Atanmamış İşler'
    case 'today_installations':
      return 'Bugünkü Montajlar'
    case 'scheduled':
      return 'Randevulu'
    case 'Tamamlandı':
      return 'Tamamlandı'
    case 'İptal':
      return 'İptal'
    case 'closure_pending':
      return 'Kapanış Onayı Bekleyen İşler'
    default:
      return 'Tümü'
  }
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
      return 'Atandı'
    case 'tamamlandi':
      return 'Tamamlandı'
    case 'iptal':
      return 'İptal'
    case 'devam ediyor':
      return 'Devam Ediyor'
    default:
      return status
  }
}

function isClosedStatus(status: string): boolean {
  const normalized = normalizeRequestStatus(status)

  return normalized === 'Tamamlandı' || normalized === 'İptal'
}

function quickFilterItemsLabel(filter: QuickFilterKey): string {
  switch (filter) {
    case 'all_open':
      return 'Tüm Açık İşler'
    case 'unassigned':
      return 'Atama Bekleyen'
    case 'appointment_pending':
      return 'Randevu Bekleyen'
    case 'overdue':
      return 'Geciken'
    case 'in_service':
      return 'Serviste'
    case 'completed':
      return 'Tamamlanan'
    default:
      return 'İş Filtreleri'
  }
}

function getRequestScheduledDate(request: ServiceRequest): Date | null {
  return parseLocalDateValue(request.scheduledAt ?? request.scheduledDate ?? null)
}

function workflowPanelLabel(filter: WorkflowQueueKey | null): string | null {
  switch (filter) {
    case 'missing_info':
      return 'Eksik Bilgi / Fotoğraf Bekleyen İşler'
    case 'customer_call':
      return 'Müşteri Aranacak İşler'
    case 'customer_unreachable':
      return 'Müşteriye Ulaşılamadı Kayıtları'
    case 'customer_confirmation':
      return 'Müşteri Onayı Bekleyen İşler'
    case 'schedule_planning':
      return 'Randevu Planlanacak İşler'
    case 'unassigned':
      return 'Usta Ataması Bekleyen İşler'
    case 'technician_approval':
      return 'Usta Onayı Bekleyen İşler'
    case 'technician_reschedule':
      return 'Usta Tarih Revize Talepleri'
    case 'document_pending':
      return 'Belge / Fotoğraf Bekleyen İşler'
    case 'closure_pending':
      return 'Kapanış Onayı Bekleyen İşler'
    default:
      return null
  }
}

function toComparableText(...values: Array<string | null | undefined>): string {
  return values
    .map((value) => normalizeTechnicalServiceText(value))
    .filter((value) => value !== '')
    .join(' ')
}

function hasKeywordMatch(haystack: string, keywords: string[]): boolean {
  return keywords.some((keyword) => haystack.includes(normalizeTechnicalServiceText(keyword)))
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

    if (['0', 'false', 'hayir', 'hayır', 'no', 'yok'].includes(normalized)) {
      return false
    }
  }

  return null
}

function displayStatusLabel(status: string): string {
  const normalized = normalizeTechnicalServiceText(status)

  switch (normalized) {
    case 'atandi':
      return 'Atandı'
    case 'tamamlandi':
      return 'Tamamlandı'
    case 'iptal':
      return 'İptal'
    default:
      return status
  }
}

function hasPendingAssets(value: unknown): boolean | null {
  if (Array.isArray(value)) {
    return value.length === 0 ? true : false
  }

  if (typeof value === 'string') {
    const normalized = normalizeTechnicalServiceText(value)

    if (!normalized) {
      return true
    }

    if (['none', 'null', 'yok', 'eksik', 'bekleniyor', 'pending'].some((keyword) => normalized.includes(keyword))) {
      return true
    }

    return false
  }

  if (value && typeof value === 'object') {
    const record = value as Record<string, unknown>
    if ('length' in record && typeof record.length === 'number') {
      return Number(record.length) === 0
    }

    return Object.keys(record).length === 0
  }

  if (value === null || value === undefined) {
    return null
  }

  return false
}

type TechnicianMatch = {
  technician: ServiceTechnician
  badge: 'Aynı ilçe' | 'Aynı il' | 'Yakın il / diğer'
  rank: number
  distanceKm: number | null
  sameCity: boolean
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
    return { technician, badge: 'Aynı ilçe', rank: 0, distanceKm, sameCity }
  }

  if (sameCity) {
    return { technician, badge: 'Aynı il', rank: 1, distanceKm, sameCity }
  }

  return { technician, badge: 'Yakın il / diğer', rank: 2, distanceKm, sameCity }
}

function mapApiRequest(request: ApiTechnicalServiceRequest): ServiceRequest {
  return {
    id: String(request.id),
    mrn: request.mrn,
    customer: request.customer_name,
    phone: request.customer_phone,
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
    technician: request.technician_name ?? 'Atanmadı',
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
    missingInfoReason: request.missing_info_reason ?? null,
    pendingReason: request.pending_reason ?? null,
    requiresReschedule: parseLooseBoolean(request.requires_reschedule),
    rescheduleReason: request.reschedule_reason ?? null,
    documentStatus: request.document_status ?? null,
    photoStatus: request.photo_status ?? null,
    customerClosureApprovalStatus: request.customer_closure_approval_status ?? null,
    customerClosureApprovedAt: request.customer_closure_approved_at ?? null,
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

function requestWorkflowText(request: ServiceRequest): string {
  return toComparableText(
    request.status,
    request.notes,
    request.workflowStatus,
    request.latestEvent,
    request.technicianApprovalStatus,
    request.technicianConfirmationStatus,
  )
}

function isUnassignedWorkflowRequest(request: ServiceRequest): boolean {
  const technicianName = normalizeTechnicalServiceText(request.technician)

  return technicianName === '' || technicianName === 'atanmadi' || technicianName === 'atanmadı'
}

function isTechnicianApprovalPendingRequest(request: ServiceRequest): boolean {
  const approvalStatus = normalizeTechnicalServiceText(request.technicianApprovalStatus)
  const confirmationStatus = normalizeTechnicalServiceText(request.technicianConfirmationStatus)
  const workflowText = requestWorkflowText(request)

  if (approvalStatus && ['bekliyor', 'pending', 'waiting', 'onay bekliyor', 'usta onayi bekliyor', 'usta onayı bekliyor'].some((keyword) => approvalStatus.includes(normalizeTechnicalServiceText(keyword)))) {
    return true
  }

  if (confirmationStatus && ['bekliyor', 'pending'].some((keyword) => confirmationStatus.includes(normalizeTechnicalServiceText(keyword)))) {
    return true
  }

  return hasKeywordMatch(workflowText, ['usta onayı', 'usta onayi', 'onay bekliyor', 'teknisyen onayı', 'teknisyen onayi', 'usta bekliyor'])
}

function isTechnicianRescheduleRequest(request: ServiceRequest): boolean {
  if (request.rescheduleRequested === true || request.revisionRequested === true) {
    return true
  }

  return hasKeywordMatch(requestWorkflowText(request), ['revize', 'tarih revize', 'tarih değişikliği', 'tarih degisikligi', 'randevu değişikliği', 'randevu degisikligi', 'usta tarih'])
}

function isCustomerConfirmationPendingRequest(request: ServiceRequest): boolean {
  const confirmationStatus = normalizeTechnicalServiceText(request.technicianConfirmationStatus)

  if (confirmationStatus && ['bekliyor', 'pending', 'teyit bekliyor', 'musteri teyidi bekliyor', 'müşteri teyidi bekliyor'].some((keyword) => confirmationStatus.includes(normalizeTechnicalServiceText(keyword)))) {
    return true
  }

  return hasKeywordMatch(requestWorkflowText(request), ['müşteri teyidi', 'musteri teyidi', 'teyit bekliyor', 'müşteri aranacak', 'musteri aranacak', 'ulaşılamadı', 'ulasilamadi'])
}

function isDocumentPendingRequest(request: ServiceRequest): boolean {
  const documentState = [request.document, request.documents, request.photo, request.photos]
    .map((value) => hasPendingAssets(value))
    .find((value) => value !== null)

  if (documentState === true) {
    return true
  }

  return hasKeywordMatch(requestWorkflowText(request), ['belge', 'fotoğraf', 'fotograf', 'eksik evrak', 'garanti belgesi'])
}

function matchesWorkflowStatus(request: ServiceRequest, statuses: string[]): boolean {
  const workflowStatus = normalizeTechnicalServiceText(request.workflowStatus)

  return statuses.some((status) => workflowStatus === normalizeTechnicalServiceText(status))
}

function hasLegacyStatus(request: ServiceRequest, status: string): boolean {
  return normalizeTechnicalServiceText(displayStatusLabel(request.status)) === normalizeTechnicalServiceText(status)
}

function matchesNewWorkflowQueue(request: ServiceRequest, filter: WorkflowQueueKey | null, isOverdueRequest: (request: ServiceRequest) => boolean): boolean {
  switch (filter) {
    case 'missing_info':
      return matchesWorkflowStatus(request, ['Eksik Bilgi / Fotoğraf Bekleyen'])
    case 'customer_call':
      return matchesWorkflowStatus(request, ['Müşteri Aranacak'])
    case 'customer_unreachable':
      return matchesWorkflowStatus(request, ['Müşteriye Ulaşılamadı'])
    case 'customer_confirmation':
      return matchesWorkflowStatus(request, ['Müşteri Onayı Bekleyen'])
    case 'schedule_planning':
      return matchesWorkflowStatus(request, ['Müşteri Onayladı', 'Randevu Planlandı'])
    case 'unassigned':
      return matchesWorkflowStatus(request, ['Usta Ataması Bekleyen'])
    case 'technician_approval':
      return matchesWorkflowStatus(request, ['Usta Onayı Bekleyen'])
    case 'technician_reschedule':
      return matchesWorkflowStatus(request, ['Usta Tarih Revize Talebi'])
    case 'sla_overdue':
      return request.slaStatus === 'geciken' || isOverdueRequest(request)
    case 'parts_pending':
      return matchesWorkflowStatus(request, ['Parça Bekleniyor'])
    case 'document_pending':
      return matchesWorkflowStatus(request, ['Belge / Fotoğraf Bekleyen'])
    case 'closure_pending':
      return matchesWorkflowStatus(request, ['Müşteri Kapanış Onayı Bekleyen'])
    default:
      return true
  }
}

export function TechnicalServiceOperationCenter() {
  const [filters, setFilters] = useState<FilterState>(initialFilters)
  const [quickFilter, setQuickFilter] = useState<QuickFilterKey>('all_open')
  const [workflowFilter, setWorkflowFilter] = useState<WorkflowQueueKey | null>(null)
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
  const [summaryLoading, setSummaryLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [selectedEvents, setSelectedEvents] = useState<ApiTechnicalServiceEvent[]>([])
  const [selectedDetailRequest, setSelectedDetailRequest] = useState<ServiceRequest | null>(null)
  const [assignDialogOpen, setAssignDialogOpen] = useState(false)
  const [assignTechnicianOption, setAssignTechnicianOption] = useState('')
  const [assignOtherTechnician, setAssignOtherTechnician] = useState('')
  const [assignNote, setAssignNote] = useState('')
  const [assignLoading, setAssignLoading] = useState(false)
  const [assignError, setAssignError] = useState<string | null>(null)
  const [showNearbyTechnicians, setShowNearbyTechnicians] = useState(false)
  const [scheduleDate, setScheduleDate] = useState('')
  const [scheduleTimeSlot, setScheduleTimeSlot] = useState('')
  const [travelRoundTripKm, setTravelRoundTripKm] = useState('')
  const [assignOverrideWithoutPayment, setAssignOverrideWithoutPayment] = useState(false)
  const [assignOverrideReason, setAssignOverrideReason] = useState('')
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
  const [summaryData, setSummaryData] = useState<SummaryResponse | null>(null)
  const [operationsData, setOperationsData] = useState<OperationsDashboardResponse | null>(null)
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
      setError(caught instanceof Error ? caught.message : 'Teknik servis talepleri alınamadı.')
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
      setError(caught instanceof Error ? caught.message : 'Özet verisi alınamadı.')
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
      setAssignError(caught instanceof Error ? caught.message : 'Usta listesi alınamadı.')
    } finally {
      setTechniciansLoading(false)
    }
  }, [])

  const loadOperationsData = useCallback(async () => {
    try {
      const response = await apiRequest('/api/technical-service/operations-dashboard')
      setOperationsData(response as OperationsDashboardResponse)
    } catch {
      setOperationsData(null)
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
        setDetailError('Talep detayları bulunamadı.')
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
        setDetailError('Seçilen kayıt ile detay verisi eşleşmedi. Lütfen listeyi yenileyin.')
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

      setDetailError(caught instanceof Error ? caught.message : 'Talep detayları yüklenemedi.')
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
    void Promise.resolve().then(loadOperationsData)
  }, [loadOperationsData])

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
  const hasScheduledAppointment = useCallback((request: ServiceRequest) => getRequestScheduledDate(request) !== null, [])
  const isTodayAppointment = useCallback((request: ServiceRequest) => {
    const scheduled = getRequestScheduledDate(request)

    return scheduled !== null && isSameLocalDay(scheduled, todayDate) && isOpenRequest(request)
  }, [isOpenRequest, todayDate])
  const isThisWeekAppointment = useCallback((request: ServiceRequest) => {
    const scheduled = getRequestScheduledDate(request)

    return scheduled !== null && scheduled >= weekStartDate && scheduled < weekEndDate && isOpenRequest(request)
  }, [isOpenRequest, weekEndDate, weekStartDate])
  const isOverdueRequest = useCallback((request: ServiceRequest) => {
    const scheduled = getRequestScheduledDate(request)

    return scheduled !== null && scheduled < todayDate && isOpenRequest(request)
  }, [isOpenRequest, todayDate])
  const matchesWorkflowFilter = useCallback((request: ServiceRequest, filter: WorkflowQueueKey | null) => {
    return matchesNewWorkflowQueue(request, filter, isOverdueRequest)
  }, [isOverdueRequest])

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

  const allFilteredRequests = useMemo(() => {
    const search = normalizeSearchText(filters.search)

    return requests
      .filter((request) => {
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
        ]

        const matchesSearch = !search || values.some((value) => normalizeSearchText(value).includes(search))
        return matchesSearch
      })
      .sort(compareRequestsBySchedule)
  }, [compareRequestsBySchedule, filters.search, requests])

  const selectedRequest = selectedId
    ? requests.find((request) => request.id === selectedId) ?? null
    : null
  const modalRequest = selectedDetailRequest ?? selectedListRequest ?? selectedRequest
  const selectedListDisplayMrn = selectedListRequest ? formatTechnicalServiceMrn(selectedListRequest) : null
  const selectedDetailDisplayMrn = selectedDetailRequest ? formatTechnicalServiceMrn(selectedDetailRequest) : null
  const modalDisplayMrn = selectedListDisplayMrn ?? selectedDetailDisplayMrn
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
  const mountPaymentMissing = modalRequest?.serviceType === 'Montaj' && mikroMountCheck?.montaj_durumu === 'Montaj Hariç'
  const effectiveMountPaymentMissing = modalRequest?.serviceType === 'Montaj' && isMountPaymentMissing(mikroMountCheck)
  const mountPaymentAccepted = modalRequest?.serviceType === 'Montaj' && isMountPaymentAccepted(mikroMountCheck)
  const effectiveAssignOverrideReady = !effectiveMountPaymentMissing || (assignOverrideWithoutPayment && assignOverrideReason.trim().length >= 5)
  const canSubmitAssign = Boolean(
    !assignLoading &&
    assignTechnicianOption &&
    (assignTechnicianOption !== 'other' || assignOtherTechnician.trim()) &&
    scheduleDate &&
    scheduleTimeSlot &&
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

  const selectedDayRequests = useMemo(() => {
    return requests
      .filter((request) => {
        const scheduled = getRequestScheduledDate(request)
        return scheduled !== null && isSameLocalDay(scheduled, selectedDate)
      })
      .sort(compareRequestsBySchedule)
  }, [compareRequestsBySchedule, requests, selectedDate])

  const selectedDayScopedRequests = useMemo(() => {
    return allFilteredRequests
      .filter((request) => {
        const scheduled = getRequestScheduledDate(request)

        if (scheduled === null || !isSameLocalDay(scheduled, selectedDate)) {
          return false
        }

        switch (quickFilter) {
          case 'all_open':
            return isOpenRequest(request)
          case 'unassigned':
            return isOpenRequest(request) && isUnassignedRequest(request)
          case 'appointment_pending':
            return isOpenRequest(request) && (!request.scheduledTime?.trim() || hasLegacyStatus(request, 'Yeni'))
          case 'overdue':
            return isOverdueRequest(request)
          case 'in_service':
            return hasLegacyStatus(request, 'Devam Ediyor')
          case 'completed':
            return normalizeRequestStatus(request.status) === 'Tamamlandı'
          default:
            return true
        }
      })
      .sort(compareRequestsBySchedule)
  }, [allFilteredRequests, compareRequestsBySchedule, isOpenRequest, isOverdueRequest, isUnassignedRequest, quickFilter, selectedDate])

  const selectedDayFilteredRequests = useMemo(() => {
    return selectedDayScopedRequests
      .filter((request) => matchesWorkflowFilter(request, workflowFilter))
      .sort(compareRequestsBySchedule)
  }, [compareRequestsBySchedule, matchesWorkflowFilter, selectedDayScopedRequests, workflowFilter])

  const selectedDaySummary = useMemo(() => {
    const appointmentCount = selectedDayRequests.length
    const assignedCount = selectedDayRequests.filter((request) => !isUnassignedRequest(request)).length
    const unassignedCount = selectedDayRequests.filter((request) => isUnassignedRequest(request)).length
    const overdueCount = selectedDayRequests.filter((request) => isOverdueRequest(request)).length

    return {
      appointmentCount,
      assignedCount,
      unassignedCount,
      overdueCount,
    }
  }, [isOverdueRequest, isUnassignedRequest, selectedDayRequests])

  const selectedDayTechnicianSummary = useMemo(() => {
    const counts = new Map<string, number>()

    selectedDayRequests.forEach((request) => {
      const key = request.technician?.trim() && request.technician !== 'Atanmadı' ? request.technician.trim() : 'Atanmamış'
      counts.set(key, (counts.get(key) ?? 0) + 1)
    })

    return Array.from(counts.entries())
      .map(([name, count]) => ({ name, count }))
      .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name, 'tr'))
  }, [selectedDayRequests])

  const weeklyDayCounts = useMemo(() => {
    const labels = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz']

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
      const count = requests.filter((request) => {
        const scheduled = getRequestScheduledDate(request)

        return scheduled !== null && isSameLocalDay(scheduled, date) && isOpenRequest(request)
      }).length

      return {
        key: toDateKey(date),
        label: labels[index] ?? '',
        shortDate: date.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit' }),
        fullDate: date.toLocaleDateString('tr-TR', { day: '2-digit', month: 'long', year: 'numeric' }),
        count,
        densityLabel: densityLabelForCount(count),
        isToday: isSameLocalDay(date, todayDate),
        isSelected: isSameLocalDay(date, selectedDate),
      }
    })
  }, [isOpenRequest, requests, selectedDate, todayDate, weekStartDate])

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
  const selectedDayDescription = `${selectedDateLabel.replace(/^./, (character) => character.toLocaleUpperCase('tr-TR'))} operasyon görünümü`
  const calendarMonthLabel = calendarMonth.toLocaleDateString('tr-TR', {
    month: 'long',
    year: 'numeric',
  }).replace(/^./, (character) => character.toLocaleUpperCase('tr-TR'))
  const calendarWeekdays = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz']
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

  const workflowQueuePanelItems = useMemo(() => ([
    {
      key: 'missing_info' as const,
      label: 'Eksik Bilgi / Fotoğraf',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'missing_info')).length,
    },
    {
      key: 'customer_call' as const,
      label: 'Müşteri Aranacak',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'customer_call')).length,
    },
    {
      key: 'customer_unreachable' as const,
      label: 'Müşteriye Ulaşılamadı',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'customer_unreachable')).length,
    },
    {
      key: 'customer_confirmation' as const,
      label: 'Müşteri Onayı Bekleyen',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'customer_confirmation')).length,
    },
    {
      key: 'schedule_planning' as const,
      label: 'Randevu Planlanacak',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'schedule_planning')).length,
    },
    {
      key: 'unassigned' as const,
      label: 'Usta Ataması Bekleyen',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'unassigned')).length,
    },
    {
      key: 'technician_approval' as const,
      label: 'Usta Onayı Bekleyen',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'technician_approval')).length,
    },
    {
      key: 'technician_reschedule' as const,
      label: 'Usta Tarih Revize Talebi',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'technician_reschedule')).length,
    },
    {
      key: 'sla_overdue' as const,
      label: 'Geciken SLA',
      count: selectedDayRequests.filter((request) => matchesWorkflowFilter(request, 'sla_overdue')).length,
    },
    {
      key: 'parts_pending' as const,
      label: 'Parça Bekleyen',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'parts_pending')).length,
    },
    {
      key: 'document_pending' as const,
      label: 'Belge / Fotoğraf Bekleyen',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'document_pending')).length,
    },
    {
      key: 'closure_pending' as const,
      label: 'Kapanış Onayı Bekleyen',
      count: selectedDayRequests.filter((request) => isOpenRequest(request) && matchesWorkflowFilter(request, 'closure_pending')).length,
    },
  ]), [isOpenRequest, matchesWorkflowFilter, selectedDayRequests])

  const workflowTitle = workflowPanelLabel(workflowFilter)
  const tableTitle = workflowTitle ?? (isSameLocalDay(selectedDate, todayDate)
    ? 'Bugünün Randevuları'
    : `${selectedDateLabel.replace(/^./, (character) => character.toLocaleUpperCase('tr-TR'))} Randevuları`)

  const tableSubtitle = `${quickFilterItemsLabel(quickFilter)}${workflowTitle ? ` • ${workflowTitle}` : ''} • ${formatTechnicalServiceDate(toDateKey(selectedDate))}`

  const quickFilterItems = [
    { key: 'all_open' as const, label: 'Tüm Açık İşler', count: selectedDayRequests.filter((request) => isOpenRequest(request)).length },
    { key: 'unassigned' as const, label: 'Atama Bekleyen', count: selectedDayRequests.filter((request) => isOpenRequest(request) && isUnassignedRequest(request)).length },
    { key: 'appointment_pending' as const, label: 'Randevu Bekleyen', count: selectedDayRequests.filter((request) => isOpenRequest(request) && (!request.scheduledTime?.trim() || hasLegacyStatus(request, 'Yeni'))).length },
    { key: 'overdue' as const, label: 'Geciken', count: selectedDayRequests.filter((request) => isOverdueRequest(request)).length },
    { key: 'in_service' as const, label: 'Serviste', count: selectedDayRequests.filter((request) => hasLegacyStatus(request, 'Devam Ediyor')).length },
    { key: 'completed' as const, label: 'Tamamlanan', count: selectedDayRequests.filter((request) => normalizeRequestStatus(request.status) === 'Tamamlandı').length },
  ]

  const summaryMetrics = [
    { label: 'Randevu Sayısı', value: selectedDaySummary.appointmentCount, tone: 'blue' as const },
    { label: 'Atanmış İş', value: selectedDaySummary.assignedCount, tone: 'green' as const },
    { label: 'Atanmamış İş', value: selectedDaySummary.unassignedCount, tone: 'purple' as const },
    { label: 'Geciken İş', value: selectedDaySummary.overdueCount, tone: 'red' as const },
  ]

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
          setMikroMountError(historyResponse.reason instanceof Error ? historyResponse.reason.message : 'Mikro montaj kontrolü yapılamadı.')
        }

        if (warrantyResponse.status === 'fulfilled') {
          setWarranty(warrantyResponse.value as WarrantySerialResponse)
        } else {
          setWarranty(null)
          setWarrantyError(warrantyResponse.reason instanceof Error ? warrantyResponse.reason.message : 'Garanti bilgisi alınamadı.')
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
    setScheduleDate('')
    setScheduleTimeSlot('')
    setTravelRoundTripKm('')
    setAssignOverrideWithoutPayment(false)
    setAssignOverrideReason('')
    setShowNearbyTechnicians(false)
    setAssignError(null)
  }

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
      setReopenError('Yeniden açma nedeni seçin.')

      return
    }

    if (reopenReason === 'Diğer' && !reopenNote.trim()) {
      setReopenError('Diğer nedeni seçildiğinde açıklama zorunludur.')

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
      setReopenError(caught instanceof Error ? caught.message : 'Talep yeniden açma işlemi başarısız oldu.')
    } finally {
      setReopenLoading(false)
    }
  }

  const handleWorkflowAction = async (action: string) => {
    if (!selectedId) {
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
      setDetailError(caught instanceof Error ? caught.message : 'Workflow aksiyonu uygulanamadı.')
    } finally {
      setWorkflowActionLoading(null)
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
      setAssignError('Lütfen bir usta seçin veya manuel isim girin.')

      return
    }

    if (!scheduleDate || !scheduleTimeSlot) {
      setAssignError('Lütfen randevu tarihi ve saatini seçin.')

      return
    }

    if (effectiveMountPaymentMissing && !assignOverrideWithoutPayment) {
      setAssignError('Montaj ödemesi alınmadığı için doğrudan atama yapılamaz.')

      return
    }

    if (effectiveMountPaymentMissing && assignOverrideReason.trim().length < 5) {
      setAssignError('Atama nedeni en az 5 karakter olmalıdır.')

      return
    }

    const parsedTravelRoundTripKm = Number(travelRoundTripKm)

    if (travelRoundTripKm.trim() === '' || !Number.isFinite(parsedTravelRoundTripKm) || parsedTravelRoundTripKm < 0) {
      setAssignError('Lütfen gidiş-geliş km bilgisini girin.')

      return
    }

    setAssignLoading(true)
    setAssignError(null)

    try {
      const selectedTimeSlot = APPOINTMENT_TIME_SLOTS.find((slot) => slot.value === scheduleTimeSlot)
      const scheduledAt = `${scheduleDate}T${selectedTimeSlot?.start ?? '10:00'}:00`

      await apiRequest(`/api/technical-service/requests/${selectedId}/assign`, {
        method: 'POST',
        body: JSON.stringify({
          ...(isManualTechnician
            ? { technician_name: selectedTechnician }
            : { technical_service_technician_id: assignTechnicianOption }),
          travel_round_trip_km: parsedTravelRoundTripKm,
          mount_payment_missing: effectiveMountPaymentMissing,
          appointment_time_slot: scheduleTimeSlot,
          override_without_payment: effectiveMountPaymentMissing ? assignOverrideWithoutPayment : false,
          override_reason: effectiveMountPaymentMissing ? assignOverrideReason.trim() || null : null,
          note: isManualTechnician ? assignNote || null : null,
        }),
      })

      await apiRequest(`/api/technical-service/requests/${selectedId}`, {
        method: 'PATCH',
        body: JSON.stringify({
          scheduled_at: scheduledAt,
          schedule_note: assignNote || null,
        }),
      })

      setAssignDialogOpen(false)
      handleAssignReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setAssignError(caught instanceof Error ? caught.message : 'Usta atama işlemi başarısız oldu.')
    } finally {
      setAssignLoading(false)
    }
  }

  const handleCompleteSubmit = async () => {
    if (!selectedId) {
      return
    }

    if (!completionReason) {
      setCompleteError('Lütfen bir kapanış nedeni seçin.')

      return
    }

    const isOtherReason = completionReason === 'Diğer'
    const notes = isOtherReason ? completionOtherNote.trim() : completionReason

    if (isOtherReason && !notes) {
      setCompleteError('Lütfen açıklama girin.')
      setCompleteLoading(false)

      return
    }

    const nextStatus = completionReason === 'Montaj tamamlandı' ? 'Tamamlandı' : 'İptal'
    const isCompletingInstallation = nextStatus === 'Tamamlandı' && modalRequest?.serviceType === 'Montaj'

    if (isCompletingInstallation && !installationCompletedAt) {
      setCompleteError('Fiili montaj tarihi zorunludur.')

      return
    }

    if (isCompletingInstallation) {
      if (!/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(installationCompletedAt)) {
        setCompleteError('Fiili montaj saati 00:00 - 23:59 aralığında HH:mm formatında olmalıdır.')

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
        setCompleteError('Fiili montaj tarihi randevudan farklıysa veya kapanıştan 1 günden fazla eskiyse açıklama zorunludur.')

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
      setCompleteError(caught instanceof Error ? caught.message : 'Talep kapatma işlemi başarısız oldu.')
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
        <div className="relative mx-auto w-full max-w-[1800px] space-y-6 px-4 py-6 md:px-6 lg:px-10">
        <section className="rounded-[24px] border border-slate-200 bg-white px-5 py-5 shadow-sm sm:px-6 sm:py-6">
          <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
            <div className="flex items-start gap-4">
              <div className="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-[20px] bg-[#06143A] text-white shadow-[0_12px_24px_rgba(6,20,58,0.18)]">
                <Wrench className="h-6 w-6" />
              </div>
              <div className="max-w-3xl">
                <h1 className="text-3xl font-semibold tracking-tight text-slate-950">Teknik Servis Operasyon Merkezi</h1>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                  Teknik servis taleplerini takip edin, randevuları yönetin ve operasyonu kolayca izleyin.
                </p>
              </div>
            </div>

            <div className="flex flex-col gap-3 xl:items-end">
              <div className="flex flex-wrap items-center gap-2 xl:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  className="h-11 rounded-2xl border-slate-200 bg-white px-4 text-slate-700 hover:bg-slate-50"
                  title="Önceki haftaya git"
                  aria-label="Önceki haftaya git"
                  onClick={() => {
                    setWeekReferenceDate((current) => addDays(current, -7))
                    setSelectedDate((current) => addDays(current, -7))
                  }}
                >
                  <ChevronLeft className="mr-2 h-4 w-4" />
                  Önceki Hafta
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  className="h-11 rounded-2xl border-slate-200 bg-white px-4 text-slate-700 hover:bg-slate-50"
                  onClick={() => {
                    const now = new Date()
                    setWeekReferenceDate(now)
                    setSelectedDate(startOfLocalDay(now))
                  }}
                >
                  <CalendarDays className="mr-2 h-4 w-4" />
                  Bugün
                </Button>
                <div ref={datePickerRef} className="relative">
                  <Button
                    type="button"
                    variant="outline"
                    className="h-11 rounded-2xl border-slate-200 bg-white px-4 text-slate-700 hover:bg-slate-50"
                    onClick={() => {
                      if (isDatePickerOpen) {
                        setIsDatePickerOpen(false)
                        return
                      }

                      openDatePicker()
                    }}
                    aria-label="Tarih seç"
                    title="Tarih seç"
                    aria-expanded={isDatePickerOpen}
                  >
                    <span>{selectedDateButtonLabel}</span>
                    <ChevronDown className={['ml-2 h-4 w-4 text-slate-400 transition-transform', isDatePickerOpen ? 'rotate-180' : 'rotate-0'].join(' ')} />
                  </Button>

                  {isDatePickerOpen ? (
                    <div className="absolute top-[calc(100%+10px)] right-0 z-30 w-[320px] rounded-[24px] border border-slate-200 bg-white p-4 shadow-[0_18px_40px_rgba(15,23,42,0.12)]">
                      <div className="mb-4 flex items-center justify-between gap-2">
                        <button
                          type="button"
                          onClick={() => setCalendarMonth((current) => addMonths(current, -1))}
                          className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-600 transition hover:bg-slate-50"
                          aria-label="Önceki ay"
                        >
                          <ChevronLeft className="h-4 w-4" />
                        </button>
                        <p className="text-sm font-semibold text-slate-950">{calendarMonthLabel}</p>
                        <button
                          type="button"
                          onClick={() => setCalendarMonth((current) => addMonths(current, 1))}
                          className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-600 transition hover:bg-slate-50"
                          aria-label="Sonraki ay"
                        >
                          <ChevronRight className="h-4 w-4" />
                        </button>
                      </div>

                      <div className="grid grid-cols-7 gap-2 text-center text-[11px] font-semibold text-slate-500">
                        {calendarWeekdays.map((weekday) => (
                          <span key={weekday} className="py-1">
                            {weekday}
                          </span>
                        ))}
                      </div>

                      <div className="mt-2 grid grid-cols-7 gap-2">
                        {calendarDays.map((day) => (
                          <button
                            key={day.key}
                            type="button"
                            onClick={() => {
                              setSelectedDate(startOfLocalDay(day.date))
                              setWeekReferenceDate(startOfLocalDay(day.date))
                              setCalendarMonth(startOfMonth(day.date))
                              setIsDatePickerOpen(false)
                            }}
                            className={[
                              'flex h-10 items-center justify-center rounded-2xl text-sm font-medium transition',
                              day.isSelected
                                ? 'bg-[#06143A] text-white shadow-[0_10px_20px_rgba(6,20,58,0.18)]'
                                : day.isToday
                                  ? 'border border-blue-200 bg-blue-50 text-blue-700'
                                  : day.inCurrentMonth
                                    ? 'text-slate-700 hover:bg-slate-50'
                                    : 'text-slate-300 hover:bg-slate-50',
                            ].join(' ')}
                          >
                            {day.label}
                          </button>
                        ))}
                      </div>

                      <div className="mt-4 flex items-center justify-between gap-2">
                        <button
                          type="button"
                          onClick={() => {
                            const now = startOfLocalDay(new Date())
                            setSelectedDate(now)
                            setWeekReferenceDate(now)
                            setCalendarMonth(startOfMonth(now))
                            setIsDatePickerOpen(false)
                          }}
                          className="text-sm font-medium text-[#06143A] transition hover:text-slate-900"
                        >
                          Bugün
                        </button>
                        <button
                          type="button"
                          onClick={() => setIsDatePickerOpen(false)}
                          className="text-sm font-medium text-slate-500 transition hover:text-slate-900"
                        >
                          Kapat
                        </button>
                      </div>
                    </div>
                  ) : null}
                </div>
                <Button
                  type="button"
                  variant="outline"
                  className="h-11 rounded-2xl border-slate-200 bg-white px-4 text-slate-700 hover:bg-slate-50"
                  title="Sonraki haftaya git"
                  aria-label="Sonraki haftaya git"
                  onClick={() => {
                    setWeekReferenceDate((current) => addDays(current, 7))
                    setSelectedDate((current) => addDays(current, 7))
                  }}
                >
                  Sonraki Hafta
                  <ChevronRight className="ml-2 h-4 w-4" />
                </Button>
              </div>

              <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
              <DialogTrigger asChild>
                <Button type="button" className="h-11 rounded-2xl bg-[#06143A] px-5 text-white shadow-[0_12px_24px_rgba(6,20,58,0.16)] hover:bg-[#0b1d51]">
                  Yeni Servis Talebi
                </Button>
              </DialogTrigger>
            <DialogContent className="max-w-2xl">
              <DialogHeader>
                <DialogTitle>Yeni Servis Talebi</DialogTitle>
                <DialogDescription>
                  Yeni servis talebi bu ekran üzerinden backend API aracılığıyla kaydedilecektir.
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
                    Müşteri adı
                    <Input
                      value={createForm.customer}
                      onChange={(event) => handleCreateChange('customer', event.target.value)}
                      placeholder="Müşteri adı"
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
                    İl
                    <select
                      value={createForm.city}
                      onChange={(event) => handleCreateChange('city', event.target.value)}
                      className={selectClassName}
                    >
                      <option value="">Seçiniz</option>
                      {TURKEY_PROVINCES.map((province) => (
                        <option key={province.plateCode} value={province.name}>
                          {province.name}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    İlçe
                    <select
                      value={createForm.district}
                      onChange={(event) => handleCreateChange('district', event.target.value)}
                      disabled={!createForm.city}
                      className={selectClassName}
                    >
                      <option value="">{createForm.city ? 'Seçiniz' : 'Önce il seçiniz'}</option>
                      {hasCreateDistrictFallback ? (
                        <option value={createForm.district}>Mevcut değer: {createForm.district}</option>
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
                    Ürün
                    <Input
                      value={createForm.product}
                      onChange={(event) => handleCreateChange('product', event.target.value)}
                      placeholder="Ürün"
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
                    <option value="">Seçiniz</option>
                    <option value="Montaj">Montaj</option>
                    <option value="Arıza">Arıza</option>
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
                  İptal
                </Button>
                <Button type="button" onClick={handleCreateSubmit} disabled={createLoading}>
                  {createLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
            </Dialog>
          </div>
        </div>
      </section>

      <TechnicalServicePageLinks />

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
                  ×
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Usta ata ve randevu ver</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için ${modalRequest?.customer} adına usta atanıyor.` : 'Seçili talep yok.'}
                </DialogDescription>
                {modalRequest?.serviceType ? (
                  <p className="text-sm leading-6 text-slate-600">
                    Bu talep {modalRequest.serviceType} işlemidir. Ustaya / servise ödenecek tutar: {modalPayment.technicianAmountLabel}
                  </p>
                ) : null}
              </DialogHeader>

              {assignError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {assignError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
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
                    ].join(' ')}>Montaj Kararı</p>
                    <p className="mt-1">Sonuç: {mikroMountCheck?.montaj_durumu ?? 'Kontrol Edilemedi'}</p>
                  </div>
                  <div className="grid gap-2 sm:grid-cols-2">
                    <div>
                      <span className={[
                        'text-xs font-semibold uppercase tracking-[0.12em]',
                        effectiveMountPaymentMissing ? 'text-rose-700' : mountPaymentAccepted ? 'text-green-700' : 'text-slate-700',
                      ].join(' ')}>Dayanak</span>
                      <p className="mt-1">{mikroMountCheck?.montaj_durumu === 'Montaj Sonradan Dahil' ? 'Mikro + sonradan montaj kaydı' : 'Mikro son geçerli satış kaydı'}</p>
                    </div>
                    <div>
                      <span className={[
                        'text-xs font-semibold uppercase tracking-[0.12em]',
                        effectiveMountPaymentMissing ? 'text-rose-700' : mountPaymentAccepted ? 'text-green-700' : 'text-slate-700',
                      ].join(' ')}>Montaj ödemesi</span>
                      <p className="mt-1">{effectiveMountPaymentMissing ? 'Alınmadı' : mountPaymentAccepted ? 'Alındı / engel yok' : 'Kontrol edilemedi'}</p>
                    </div>
                  </div>
                  {effectiveMountPaymentMissing ? (
                    <p>Montaj ödemesi alınmamış görünüyor. Atama yapmak için operasyon onayı gerekir.</p>
                  ) : null}
                  {mikroMountCheck?.montaj_ek_aciklama ? <p>{mikroMountCheck.montaj_ek_aciklama}</p> : null}
                  {mikroMountCheck?.farkli_cari_uyarisi ? <p>Sonradan montaj carisi, son geçerli satış carisinden farklı.</p> : null}
                </div>

                {effectiveMountPaymentMissing ? (
                  <div className="grid gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <div>
                      <p className="font-semibold">Montaj ödemesi alınmamış görünüyor. Atama yapmak için operasyon onayı gerekir.</p>
                      <p className="mt-1">Operasyon zorunlu durumda devam edecekse kontrollü override kullanın.</p>
                    </div>

                    <label className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-white px-4 py-3">
                      <input
                        type="checkbox"
                        checked={assignOverrideWithoutPayment}
                        onChange={(event) => setAssignOverrideWithoutPayment(event.target.checked)}
                        className="mt-1 h-4 w-4 accent-primary"
                      />
                      <span className="font-medium text-slate-900">Montaj hariç işe atama yapılmasına onay veriyorum.</span>
                    </label>

                    {assignOverrideWithoutPayment ? (
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Atama nedeni
                        <textarea
                          value={assignOverrideReason}
                          onChange={(event) => setAssignOverrideReason(event.target.value)}
                          className="min-h-[96px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                          placeholder="Örn. Müşteri montaj sonrası ödeme yapacak / elden ödeme alınacak / yönetici onayı var"
                        />
                      </label>
                    ) : null}
                  </div>
                ) : null}

                <fieldset className="grid gap-3">
                  <legend className="text-sm font-medium text-slate-700">Usta / Çilingir adı</legend>
                  <div className="grid gap-2">
                    {techniciansLoading ? (
                      <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                        Usta listesi yükleniyor...
                      </div>
                    ) : null}
                    {!techniciansLoading && technicians.length === 0 ? (
                      <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                        Aktif usta kaydı bulunamadı. Manuel giriş için Diğer seçeneğini kullanabilirsiniz.
                      </div>
                    ) : null}
                    {!techniciansLoading && technicians.length > 0 && sameCityTechnicians.length > 0 ? (
                      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Aynı şehirdeki ustalar</p>
                    ) : null}
                    {!techniciansLoading && technicians.length > 0 && sameCityTechnicians.length === 0 ? (
                      <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                        Bu taleple aynı şehirde aktif usta bulunamadı.
                      </div>
                    ) : null}
                    {visibleTechnicianMatches.map((match, index) => (
                      <div key={match.technician.id} className="grid gap-2">
                        {showNearbyTechnicians && index === sameCityTechnicians.length && otherCityTechnicians.length > 0 ? (
                          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Yakın / diğer şehirlerdeki ustalar</p>
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
                              {[match.technician.phone, [match.technician.city, match.technician.district].filter(Boolean).join(' / ')].filter(Boolean).join(' · ') || 'İletişim / konum bilgisi yok'}
                            </span>
                            {(match.technician.mikro_cari_adi || match.technician.mikro_cari_kodu || match.distanceKm !== null) ? (
                              <span className="mt-1 block text-xs font-normal text-slate-500">
                                {[match.technician.mikro_cari_adi || match.technician.mikro_cari_kodu, match.distanceKm !== null ? `Yaklaşık ${match.distanceKm.toLocaleString('tr-TR')} km` : null].filter(Boolean).join(' · ')}
                              </span>
                            ) : null}
                          </span>
                        </label>
                      </div>
                    ))}
                    {!showNearbyTechnicians && otherCityTechnicians.length > 0 ? (
                      <Button type="button" variant="secondary" onClick={() => setShowNearbyTechnicians(true)}>
                        Diğer / Yakın İlleri Göster
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
                      Diğer
                      </label>
                  </div>
                </fieldset>

                {assignTechnicianOption === 'other' ? (
                  <div className="grid gap-4">
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Manuel usta adı
                      <Input
                        value={assignOtherTechnician}
                        onChange={(event) => setAssignOtherTechnician(event.target.value)}
                        placeholder="Usta adı"
                      />
                    </label>

                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Not / açıklama
                      <textarea
                        value={assignNote}
                        onChange={(event) => setAssignNote(event.target.value)}
                        className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                        placeholder="Not / açıklama"
                      />
                    </label>
                  </div>
                ) : null}

                <div className="grid gap-2 text-sm font-medium text-slate-700">
                  <label>Randevu tarihi</label>
                  <Input
                    type="date"
                    value={scheduleDate}
                    onChange={(event) => setScheduleDate(event.target.value)}
                  />
                </div>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Randevu saat aralığı
                  <select
                    value={scheduleTimeSlot}
                    onChange={(event) => setScheduleTimeSlot(event.target.value)}
                    className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                  >
                    <option value="">Saat aralığı seçin</option>
                    {APPOINTMENT_TIME_SLOTS.map((slot) => (
                      <option key={slot.value} value={slot.value}>
                        {slot.value}
                      </option>
                    ))}
                  </select>
                </label>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Gidiş-geliş km
                  <Input
                    type="number"
                    min="0"
                    step="0.01"
                    value={travelRoundTripKm}
                    onChange={(event) => setTravelRoundTripKm(event.target.value)}
                    placeholder="Örn. 42"
                  />
                </label>

                <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-slate-600">Ücretsiz km</span>
                    <span className="font-semibold text-slate-900">{assignPaymentPreview.freeKmLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-slate-600">Ücretli km</span>
                    <span className="font-semibold text-slate-900">{assignPaymentPreview.billableKmLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-slate-600">Yol ücreti</span>
                    <span className="font-semibold text-slate-900">{assignPaymentPreview.travelAmountLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3 border-t border-slate-200 pt-3">
                    <span className="font-medium text-slate-600">Toplam usta maliyeti</span>
                    <span className="font-semibold text-slate-950">{assignPaymentPreview.totalTechnicianCostLabel}</span>
                  </div>
                </div>
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleAssignReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button
                  type="button"
                  onClick={handleAssignSubmit}
                  disabled={!canSubmitAssign}
                >
                  {assignLoading ? 'Kaydediliyor...' : 'Usta Ata ve Randevu Ver'}
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
                  ×
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Talebi kapat / iptal et</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için ${modalRequest?.customer} talebinin sonucunu seçin.` : 'Seçili talep yok.'}
                </DialogDescription>
                {modalRequest?.serviceType ? (
                  <p className="text-sm leading-6 text-slate-600">
                    Müşteriden alınacak tutar: {modalPayment.customerAmountLabel}
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
                  <legend className="text-sm font-medium text-slate-700">Kapanış / iptal nedeni</legend>
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

                {completionReason === 'Diğer' ? (
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Açıklama
                    <textarea
                      value={completionOtherNote}
                      onChange={(event) => setCompletionOtherNote(event.target.value)}
                      className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                      placeholder="Açıklama"
                    />
                  </label>
                ) : null}

                {completionReason === 'Montaj tamamlandı' && modalRequest?.serviceType === 'Montaj' ? (
                  <div className="grid gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <div className="text-sm leading-6 text-amber-800">
                      Garanti, talebin panelde kapatıldığı tarihte değil, fiili montaj tarihinde başlar.
                    </div>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Fiili Montaj Tarihi
                      <DateTimeFields
                        value={installationCompletedAt}
                        max={toTechnicalServiceDateTimeInputValue(null)}
                        onChange={setInstallationCompletedAt}
                      />
                      <span className="text-xs font-normal text-slate-600">Garanti bu tarihten itibaren başlar.</span>
                    </label>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Fiili montaj açıklaması
                      <textarea
                        value={installationCompletionNote}
                        onChange={(event) => setInstallationCompletionNote(event.target.value)}
                        className="min-h-[84px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                        placeholder="Randevu tarihinden farklıysa veya geçmiş tarih girildiyse açıklama zorunlu"
                      />
                    </label>
                  </div>
                ) : null}
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleCompleteReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button
                  type="button"
                  onClick={handleCompleteSubmit}
                  disabled={
                    completeLoading ||
                    !completionReason ||
                    (completionReason === 'Diğer' && !completionOtherNote.trim()) ||
                    (completionReason === 'Montaj tamamlandı' && modalRequest?.serviceType === 'Montaj' && !installationCompletedAt)
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
                  ×
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Talebi yeniden aç</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için ${modalRequest?.customer} talebi yeniden açılacak.` : 'Seçili talep yok.'}
                </DialogDescription>
              </DialogHeader>

              {reopenError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {reopenError}
                </div>
              ) : null}

              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm leading-6 text-rose-700">
                Bu talep daha önce tamamlandıysa garanti başlangıcı geri alınmaz. Yeniden açma işlemi sadece operasyonel düzeltme içindir.
              </div>

              {modalRequest?.serviceType === 'Montaj' && modalRequest.completedAt ? (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-800">
                  Bu montaj talebi tamamlanmış görünüyor. Garanti başladıysa önerilen aksiyon yeni bağlı servis/takip talebi açmaktır.
                </div>
              ) : null}

              <fieldset className="grid gap-3">
                <legend className="text-sm font-medium text-slate-700">Yeniden açma nedeni</legend>
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
                Açıklama
                <textarea
                  value={reopenNote}
                  onChange={(event) => setReopenNote(event.target.value)}
                  className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                  placeholder={reopenReason === 'Diğer' ? 'Açıklama zorunlu' : 'Opsiyonel açıklama'}
                />
              </label>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleReopenReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button type="button" onClick={handleReopenSubmit} disabled={reopenLoading || !reopenReason || (reopenReason === 'Diğer' && !reopenNote.trim())}>
                  {reopenLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        <TechnicalServiceOperationsDashboard
          quickFilters={quickFilterItems}
          activeQuickFilter={quickFilter}
          onQuickFilterChange={(nextFilter) => setQuickFilter(nextFilter)}
          weekDays={weeklyDayCounts}
          onSelectDay={(key) => {
            const nextDate = parseLocalDateValue(key)

            if (!nextDate) {
              return
            }

            setSelectedDate(startOfLocalDay(nextDate))
            setWeekReferenceDate(startOfLocalDay(nextDate))
          }}
          tableTitle={tableTitle}
          tableSubtitle={tableSubtitle}
          tableSearch={filters.search}
          onTableSearchChange={(value) => setFilters((current) => ({ ...current, search: value }))}
          appointments={selectedDayFilteredRequests}
          selectedRequestId={selectedRequest?.id ?? ''}
          onSelectRequest={openRequestDetail}
          summaryMetrics={summaryMetrics}
          summaryDescription={selectedDayDescription}
          workflowQueues={workflowQueuePanelItems}
          activeWorkflowFilter={workflowFilter}
          onWorkflowFilterChange={setWorkflowFilter}
          technicianSummary={selectedDayTechnicianSummary}
          weeklyLegend={['Yogun', 'Orta', 'Normal', 'Dusuk', 'Yok']}
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
          <DialogContent className="w-[calc(100vw-16px)] h-[100dvh] max-h-[100dvh] p-0 overflow-hidden flex flex-col rounded-none sm:left-auto sm:right-0 sm:top-0 sm:h-screen sm:max-h-screen sm:w-[880px] sm:max-w-[880px] sm:translate-x-0 sm:translate-y-0 sm:rounded-l-[28px] sm:rounded-r-none">
            <div className="flex h-full min-h-[420px] flex-col overflow-hidden bg-white">
              <DialogHeader className="sticky top-0 z-20 border-b border-slate-200 bg-white px-4 py-4 md:px-6 md:py-5">
                <div className="flex items-start justify-between gap-4">
                  <div className="min-w-0 space-y-2">
                    <DialogTitle className="text-base font-semibold text-slate-900">Talep Detayı</DialogTitle>
                    <DialogDescription className="text-sm text-slate-600">
                      Seçili talebin MRN, müşteri, durum ve öncelik bilgilerini kontrol edin.
                    </DialogDescription>
                  </div>
                  <DialogClose asChild>
                    <button
                      type="button"
                      className="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                      aria-label="Talep detay modalini kapat"
                    >
                      ×
                    </button>
                  </DialogClose>
                </div>
                <div className="mt-4 flex flex-wrap items-center gap-2">
                  <span className="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-900">{modalDisplayMrn ?? modalRequest?.mrn ?? 'Seçili talep yok'}</span>
                  <span className="min-w-0 truncate text-sm text-slate-600">Müşteri: {modalRequest?.customer ?? '-'}</span>
                  <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">Durum: {modalRequest?.status ?? '-'}</span>
                </div>
              </DialogHeader>

              <div className="flex-1 min-h-0 overflow-y-auto overscroll-contain px-4 py-4 md:px-6 md:py-5">
                {detailLoading ? (
                  <div className="rounded-3xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                    Detay yükleniyor...
                  </div>
                ) : detailError ? (
                  <div className="rounded-3xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">
                    {detailError}
                  </div>
                ) : selectedDetailRequest ? (
                  <ServiceRequestDetails
                    request={selectedDetailRequest}
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
                    onComplete={openCompleteDialog}
                    onReopen={() => setReopenDialogOpen(true)}
                    onWorkflowAction={handleWorkflowAction}
                    workflowActionInFlight={workflowActionLoading}
                  />
                ) : (
                  <div className="rounded-3xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                    Seçilen kayıt için detay bekleniyor...
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





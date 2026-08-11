import { Head } from '@inertiajs/react'
import { Plus, RefreshCw, Search, ShieldCheck, TriangleAlert, Wrench } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { DateTimeFields } from '@/components/technical-service/DateTimeFields'
import {
  PAYMENT_RECONCILIATION_POLL_INTERVAL_MS,
  resolveVisiblePendingPaymentTargets,
} from '@/components/technical-service/payment-reconciliation'
import type { PaymentLinkSendContext, PaymentLinkSendPayload, PaymentLinkSendResult } from '@/components/technical-service/PendingPaymentLinkActions'
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
  ServicePriority,
  ServiceRequest,
  ServiceRequestCompanyPaymentDecisionSubmit,
  ServiceRequestExtraMountPaymentPayload,
  ServiceRequestRouteQuote,
  ServiceRequestRouteQuoteManualPayload,
  ServiceRequestTechnicianEarningMessagePayload,
  ServiceTechnician,
  WarrantySerialResponse,
} from '@/components/technical-service/types'
import {
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
  next_action_payload?: ServiceRequest['nextActionPayload'] | null
  sla_due_at?: string | null
  sla_status?: string | null
  allowed_workflow_actions?: Record<string, { label: string, target: string }> | null
  allowed_workflow_transitions?: string[] | null
  audit_logs?: Array<{
    id: string | number
    entity_type: string
    entity_id: string | number
    action_type: string
    action_label?: string | null
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
  qr_source?: ServiceRequest['qrSource']
  product?: ServiceRequest['productInfo'] | unknown
  sale_and_payment?: ServiceRequest['saleAndPayment']
  operation_control?: ServiceRequest['operationControl']
  assignment_blockers?: ServiceRequest['assignmentBlockers']
  invoice_serials?: ServiceRequest['invoiceSerials']
  location?: ServiceRequest['location']
  door_photos?: ServiceRequest['doorPhotos']
  field_completion_documents?: ServiceRequest['fieldCompletionDocuments']
  previous_field_completion_documents?: ServiceRequest['previousFieldCompletionDocuments']
  route_quote?: ServiceRequest['routeQuote']
  assignment_offer?: ServiceRequest['assignmentOffer']
  technician_job_card?: ServiceRequest['technicianJobCard']
  settlement?: ServiceRequest['settlement']
  technician_revision_offer?: ServiceRequest['technicianRevisionOffer']
  earning_breakdown?: ServiceRequest['earningBreakdown']
  finance_summary?: ServiceRequest['financeSummary']
  partner_portal_actions?: ServiceRequest['partnerPortalActions']
  part_requests?: ServiceRequest['partRequests']
  active_part_request?: ServiceRequest['activePartRequest']
  kanban_column?: ServiceRequest['kanbanColumn']
  display_action_label?: string | null
  display_tags?: ServiceRequest['displayTags']
  action_owner?: ServiceRequest['actionOwner']
  action_owner_label?: string | null
  action_priority?: number | string | null
  action_bucket?: ServiceRequest['actionBucket']
  card_tone?: ServiceRequest['cardTone']
  action_title?: string | null
  action_reason?: string | null
  action_filter_keys?: string[]
  operational_state?: ServiceRequest['operationalState']
  cancel_context?: ServiceRequest['cancelContext']
  current_stage_summary?: ServiceRequest['currentStageSummary']
  visible_sections?: ServiceRequest['visibleSections']
  service_visit_history?: ServiceRequest['serviceVisitHistory']
  admin_overrides?: ServiceRequest['adminOverrides']
  admin_override_summary?: ServiceRequest['adminOverrideSummary']
  field_correction_policy?: ServiceRequest['fieldCorrectionPolicy']
  document?: unknown
  documents?: unknown
  photo?: unknown
  photos?: unknown
}

type ApiOperationControlUpdate = {
  id: string | number
  operation_control?: ServiceRequest['operationControl'] | null
  assignment_blockers?: ServiceRequest['assignmentBlockers'] | null
  allowed_workflow_actions?: ApiTechnicalServiceRequest['allowed_workflow_actions']
  allowed_workflow_transitions?: string[] | null
  operational_state?: ServiceRequest['operationalState'] | null
  kanban_column?: ServiceRequest['kanbanColumn']
  display_action_label?: string | null
  display_tags?: ServiceRequest['displayTags']
  action_owner?: ServiceRequest['actionOwner']
  action_owner_label?: string | null
  action_priority?: number | string | null
  action_bucket?: ServiceRequest['actionBucket']
  card_tone?: ServiceRequest['cardTone']
  action_title?: string | null
  action_reason?: string | null
  action_filter_keys?: string[]
  attention?: ServiceRequest['attention']
  cancel_context?: ServiceRequest['cancelContext'] | null
  current_stage_summary?: ServiceRequest['currentStageSummary'] | null
  visible_sections?: ServiceRequest['visibleSections'] | null
  next_action?: string | null
  next_action_payload?: ServiceRequest['nextActionPayload'] | null
}

type ApiTechnicalServiceEvent = {
  id: number | string
  event_type: string
  event_type_label?: string | null
  title: string
  title_label?: string | null
  note?: string | null
  from_status?: string | null
  from_status_label?: string | null
  to_status?: string | null
  to_status_label?: string | null
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

type ExternalExecutionCapability = {
  code: string
  classification: string
  owner_track: string | null
  activation_class: 'required' | 'optional'
  required: boolean
  adapted: boolean
  ready: boolean
  mode_gated: boolean
  capability_revision: number
  readiness_blockers: string[]
  safe_default: string
}

type ExternalExecutionControl = {
  mode: 'local' | 'live'
  state: 'local' | 'activating' | 'live' | 'freezing' | 'blocked'
  epoch: number
  revision: number
  runtime_environment: string
  runtime_environment_label: string
  changed_at: string | null
  reason: string | null
  can_transition: boolean
  readiness: {
    eligible: boolean
    blocker_count: number
    required_count: number
    required_adapted_count: number
    required_ready_count: number
    registered_count: number
    blockers: Array<{ code: string, capability?: string, message: string }>
    optional_blockers: Array<{ code: string, capability?: string, message: string }>
    capabilities: ExternalExecutionCapability[]
  }
}

async function responseErrorMessage(response: Response, fallback: string): Promise<string> {
  try {
    const payload = await response.json() as { message?: string, errors?: Record<string, string[] | string> }
    const validation = Object.values(payload.errors ?? {}).flatMap((value) => Array.isArray(value) ? value : [value])

    return validation[0] ?? payload.message ?? fallback
  } catch {
    return fallback
  }
}

type PlanSummaryFilter = 'week' | 'today' | 'overdue' | 'unscheduled' | 'closed'

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

const initialFilters: FilterState = {
  search: '',
  status: '',
  onlyOpen: false,
}

const getTechnicalServiceInitialFilters = (): FilterState => {
  if (typeof window === 'undefined') {
    return initialFilters
  }

  const search = new URLSearchParams(window.location.search).get('search')?.trim() ?? ''

  return {
    ...initialFilters,
    search,
  }
}

const getTechnicalServiceRequestListUrl = (): string => {
  const params = new URLSearchParams({ limit: '200' })

  if (typeof window !== 'undefined') {
    const search = new URLSearchParams(window.location.search).get('search')?.trim()

    if (search) {
      params.set('search', search)
    }
  }

  return `/api/technical-service/requests?${params.toString()}`
}

const READ_REQUEST_IDS_STORAGE_KEY = 'emaks:technical-service:operation-center:read-request-ids'

const readStoredRequestIds = (): Set<string> => {
  if (typeof window === 'undefined') {
    return new Set()
  }

  try {
    const rawValue = window.localStorage.getItem(READ_REQUEST_IDS_STORAGE_KEY)
    const parsedValue: unknown = rawValue ? JSON.parse(rawValue) : []

    return new Set(Array.isArray(parsedValue) ? parsedValue.filter((value): value is string => typeof value === 'string') : [])
  } catch {
    return new Set()
  }
}

const writeStoredRequestIds = (requestIds: Set<string>) => {
  if (typeof window === 'undefined') {
    return
  }

  try {
    window.localStorage.setItem(READ_REQUEST_IDS_STORAGE_KEY, JSON.stringify(Array.from(requestIds).slice(-1000)))
  } catch {
    // localStorage can be unavailable in private mode; unread badges still work for the current render.
  }
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

const CANCELLATION_REASONS = [
  'Müşterinin kapısı uygun değildi',
  'Müşteri siparişi iptal etti',
  'Müşteri randevuya gelmedi / evde yoktu',
  'SRV yanlışlıkla açıldı',
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
  { value: '10:00 - 12:00', start: '10:00', end: '12:00' },
  { value: '12:00 - 14:00', start: '12:00', end: '14:00' },
  { value: '14:00 - 16:00', start: '14:00', end: '16:00' },
  { value: '16:00 - 18:00', start: '16:00', end: '18:00' },
] as const

const CONTACT_CONFIRMATION_METHODS = ['telefon', 'whatsapp', 'sms', 'eposta', 'panel'] as const
const FIELD_CLOSURE_METHODS = ['otp', 'imza', 'telefon', 'panel'] as const
const FIELD_CHECKLIST_ITEMS = [
  'Ürün seri numarası kontrol edildi',
  'Kapı / montaj yeri kontrol edildi',
  'Montaj uygunluğu kontrol edildi',
  'Ürün çalışır durumda test edildi',
  'Müşteriye kullanım bilgisi verildi',
  'Garanti / servis formu bilgisi kontrol edildi',
] as const

function isMountPaymentMissing(result: MikroMountCheckResult | null | undefined): boolean {
  return result?.montaj_durumu === 'Montaj Hariç'
}

function isMountPaymentAccepted(result: MikroMountCheckResult | null | undefined): boolean {
  return result?.montaj_durumu === 'Montaj Dahil' || result?.montaj_durumu === 'Montaj Sonradan Dahil'
}

function hasMountPaymentReceived(request: ServiceRequest | null | undefined): boolean {
  const saleAndPayment = request?.saleAndPayment
  const canonicalPayment = saleAndPayment?.payment_status

  return Boolean(
    canonicalPayment?.is_paid
    || saleAndPayment?.mount_payment_received
    || saleAndPayment?.mount_payment_status === 'paid'
    || saleAndPayment?.extra_mount_payment?.status === 'paid'
  )
}

function requiresCanonicalMountPayment(request: ServiceRequest | null | undefined): boolean {
  const canonicalPayment = request?.saleAndPayment?.payment_status

  if (canonicalPayment) {
    return Boolean(canonicalPayment.requires_payment && !canonicalPayment.is_paid)
  }

  const saleAndPayment = request?.saleAndPayment

  return Boolean(
    !hasMountPaymentReceived(request)
    && (
      saleAndPayment?.mount_payment_status === 'pending'
      || saleAndPayment?.mount_payment_status === 'failed'
      || saleAndPayment?.mount_payment_status === 'cancelled'
      || saleAndPayment?.mount_payment_status === 'skipped_multi_product'
      || saleAndPayment?.sale_mount_status === 'montaj_haric'
      || saleAndPayment?.sale_mount_label === 'Montaj Hariç'
    )
  )
}

function requiresMountExclusionAcknowledgement(request: ServiceRequest | null | undefined): boolean {
  if (!request || request.serviceType !== 'Montaj') {
    return false
  }

  const saleAndPayment = request.saleAndPayment
  const isMountExcluded = saleAndPayment?.sale_mount_status === 'montaj_haric'
    || saleAndPayment?.sale_mount_label === 'Montaj Hariç'
  const isMultiProduct = Boolean(
    request.invoiceSerials?.has_multi_product
    || request.saleAndPayment?.mount_payment_status === 'skipped_multi_product'
    || (request.invoiceSerials?.all_invoice_serials?.length ?? 0) > 1
  )

  return isMountExcluded && isMultiProduct && !hasMountPaymentReceived(request)
}

function normalizeSearchText(value: string | null | undefined): string {
  return normalizeTechnicalServiceText(value)
    .replace(/[-\s\p{Punctuation}]+/gu, '')
}

function parseNullableNumber(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const parsed = typeof value === 'number' ? value : Number(String(value).trim())

  return Number.isFinite(parsed) ? parsed : null
}

function roundTwo(value: number): number {
  return Math.round(value * 100) / 100
}

function formatMoneyLabel(value: number | null | undefined): string {
  return typeof value === 'number' && Number.isFinite(value)
    ? `${value.toLocaleString('tr-TR', { maximumFractionDigits: 2 })} TL`
    : '-'
}

function routeQuoteFailureMessage(message: string | null | undefined): string {
  if (message === 'Usta konumu eksik.') {
    return 'Usta konumu eksik; yol hakedişi manuel girilmeli.'
  }

  if (message === 'Müşteri konumu eksik.') {
    return 'Müşteri konumu eksik; yol hakedişi manuel girilmeli.'
  }

  if (typeof message === 'string' && message.trim() !== '') {
    return `${message} Manuel giriş yapın.`
  }

  return 'Yol hakedişi hesaplanamadı; manuel giriş yapın.'
}

function validCoordinatePair(
  latitude: number | string | null | undefined,
  longitude: number | string | null | undefined,
): { latitude: number, longitude: number } | null {
  const parsedLatitude = parseNullableNumber(latitude)
  const parsedLongitude = parseNullableNumber(longitude)

  if (parsedLatitude === null || parsedLongitude === null) {
    return null
  }

  if ((parsedLatitude === 0 && parsedLongitude === 0) || Math.abs(parsedLatitude) > 90 || Math.abs(parsedLongitude) > 180) {
    return null
  }

  return { latitude: parsedLatitude, longitude: parsedLongitude }
}

function technicianCoordinatePair(technician: ServiceTechnician): { latitude: number, longitude: number } | null {
  return validCoordinatePair(technician.latitude, technician.longitude)
    ?? validCoordinatePair(technician.start_latitude, technician.start_longitude)
}

function requestRouteCoordinatePair(request: ServiceRequest | null | undefined): { latitude: number, longitude: number } | null {
  return validCoordinatePair(request?.location?.route_latitude, request?.location?.route_longitude)
    ?? validCoordinatePair(request?.location?.latitude, request?.location?.longitude)
}

function sameCoordinateValue(left: number | string | null | undefined, right: number | string | null | undefined): boolean {
  const parsedLeft = parseNullableNumber(left)
  const parsedRight = parseNullableNumber(right)

  return parsedLeft !== null && parsedRight !== null && Math.abs(parsedLeft - parsedRight) <= 0.000001
}

function routeQuoteActiveForSelection(
  routeQuote: ServiceRequestRouteQuote | null | undefined,
  selectedTechnicianId: string,
  technician: ServiceTechnician | null,
  request: ServiceRequest | null,
): boolean {
  if (!routeQuote || !selectedTechnicianId || !technician || !request) {
    return false
  }

  const technicianCoordinates = technicianCoordinatePair(technician)
  const requestCoordinates = requestRouteCoordinatePair(request)
  const routeQuoteTechnicianId = routeQuote.technician_id === null || routeQuote.technician_id === undefined
    ? null
    : String(routeQuote.technician_id)
  const configFeePerKm = typeof request.routeFeeConfig?.fee_per_km === 'number' && Number.isFinite(request.routeFeeConfig.fee_per_km)
    ? request.routeFeeConfig.fee_per_km
    : null
  const feeMatchesConfig = routeQuote.fee_per_km_matches_current === true
    || (
      typeof routeQuote.fee_per_km === 'number'
      && configFeePerKm !== null
      && Math.abs(routeQuote.fee_per_km - configFeePerKm) <= 0.001
    )
  const isManualQuote = routeQuote.manual_override === true
    || routeQuote.provider === 'manual_override'
    || routeQuote.source === 'manual_override'

  return routeQuoteTechnicianId === selectedTechnicianId
    && (routeQuote.status === 'calculated' || routeQuote.status === 'manual_override')
    && feeMatchesConfig
    && (
      isManualQuote
      || (
        technicianCoordinates !== null
        && requestCoordinates !== null
        && sameCoordinateValue(routeQuote.origin_latitude, technicianCoordinates.latitude)
        && sameCoordinateValue(routeQuote.origin_longitude, technicianCoordinates.longitude)
        && sameCoordinateValue(routeQuote.destination_latitude, requestCoordinates.latitude)
        && sameCoordinateValue(routeQuote.destination_longitude, requestCoordinates.longitude)
      )
    )
}

function technicianDisplayName(technician: ServiceTechnician): string {
  return [technician.first_name, technician.last_name].filter(Boolean).join(' ').trim() || technician.name
}

function activeTechnicianPartnerLinks(technician: ServiceTechnician | null) {
  return (technician?.b2b_partner_links ?? []).filter((link) => (
    link.active !== false
    && ['owner', 'field_technician'].includes(link.relationship_type ?? '')
    && link.partner_id !== null
    && link.partner_id !== undefined
    && link.partner?.active !== false
  ))
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

type TechnicianMatch = {
  technician: ServiceTechnician
  badge: 'Aynı ilçe' | 'Aynı il' | 'Yakın il / diğer'
  rank: number
  distanceKm: number | null
  distanceSource: 'coordinates' | 'province' | null
  sameCity: boolean
}

type TechnicianAssignmentInsight = {
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
  technicianAmountSourceLabel: string
  travelAmountLabel: string
  totalCostLabel: string
  costDeltaLabel: string
  recommended: boolean
  estimatedRoundTripKm: number | null
}

function technicianAddressSummary(technician: ServiceTechnician): string {
  return [
    technician.city,
    technician.district,
    technician.address,
    technician.google_formatted_address,
    technician.default_start_address,
    technician.cari_address,
    technician.cari_city_district_country,
  ].filter((value): value is string => typeof value === 'string' && value.trim() !== '').join(' · ')
}

function technicianMatchInfo(technician: ServiceTechnician, request: ServiceRequest | null): TechnicianMatch {
  const technicianCity = normalizeLocationText(technician.city)
  const technicianDistrict = normalizeLocationText(technician.district)
  const requestCity = normalizeLocationText(request?.city)
  const requestDistrict = normalizeLocationText(request?.district)
  const technicianProvince = findProvinceByName(technician.city)
  const requestProvince = findProvinceByName(request?.city)
  const technicianCoordinates = technicianCoordinatePair(technician)
  const requestCoordinates = requestRouteCoordinatePair(request)
  const technicianLat = technicianCoordinates?.latitude ?? technicianProvince?.latitude ?? null
  const technicianLng = technicianCoordinates?.longitude ?? technicianProvince?.longitude ?? null
  const requestLat = requestCoordinates?.latitude ?? requestProvince?.latitude ?? null
  const requestLng = requestCoordinates?.longitude ?? requestProvince?.longitude ?? null
  const sameCity = technicianCity !== '' && technicianCity === requestCity
  const sameDistrict = sameCity && technicianDistrict !== '' && technicianDistrict === requestDistrict
  const distanceKm = haversineKm(technicianLat, technicianLng, requestLat, requestLng)
  const distanceSource = distanceKm === null ? null : technicianCoordinates && requestCoordinates ? 'coordinates' : 'province'

  if (sameDistrict) {
    return { technician, badge: 'Aynı ilçe', rank: 0, distanceKm, distanceSource, sameCity }
  }

  if (sameCity) {
    return { technician, badge: 'Aynı il', rank: 1, distanceKm, distanceSource, sameCity }
  }

  return { technician, badge: 'Yakın il / diğer', rank: 2, distanceKm, distanceSource, sameCity }
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

  return [dateLabel, timeLabel].filter(Boolean).join(' · ')
}

function mapApiRequest(request: ApiTechnicalServiceRequest): ServiceRequest {
  const qrProductInfo = typeof request.product === 'object' && request.product !== null
    ? request.product as ServiceRequest['productInfo']
    : null
  const documentInfo = typeof request.documents === 'object' && request.documents !== null
    ? request.documents as ServiceRequest['documentInfo']
    : null

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
    product: qrProductInfo?.product_name ?? request.product_name,
    model: qrProductInfo?.product_model ?? request.product_model ?? '',
    serialNumber: qrProductInfo?.serial_number ?? request.serial_number ?? '',
    serviceType: request.service_type,
    priority: request.priority,
    technicianId: request.technical_service_technician_id === null || request.technical_service_technician_id === undefined
      ? null
      : String(request.technical_service_technician_id),
    technician: request.technician_name ?? 'Atanmadı',
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
    nextActionPayload: request.next_action_payload ?? null,
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
    qrSource: request.qr_source ?? null,
    productInfo: qrProductInfo,
    saleAndPayment: request.sale_and_payment ?? null,
    documentInfo,
    operationControl: request.operation_control ?? null,
    assignmentBlockers: request.assignment_blockers ?? null,
    invoiceSerials: request.invoice_serials ?? null,
    location: request.location ?? null,
    doorPhotos: request.door_photos ?? [],
    fieldCompletionDocuments: request.field_completion_documents ?? [],
    previousFieldCompletionDocuments: request.previous_field_completion_documents ?? [],
    routeFeeConfig: request.route_fee_config ?? null,
    routeQuote: request.route_quote ?? null,
    assignmentOffer: request.assignment_offer ?? null,
    technicianJobCard: request.technician_job_card ?? null,
    settlement: request.settlement ?? null,
    technicianRevisionOffer: request.technician_revision_offer ?? null,
    earningBreakdown: request.earning_breakdown ?? null,
    financeSummary: request.finance_summary ?? null,
    partnerPortalActions: request.partner_portal_actions ?? [],
    partRequests: request.part_requests ?? [],
    activePartRequest: request.active_part_request ?? null,
    kanbanColumn: request.kanban_column ?? request.operational_state?.ops_column ?? null,
    displayActionLabel: request.display_action_label ?? request.operational_state?.display_action_label ?? null,
    displayTags: request.display_tags ?? request.operational_state?.display_tags ?? [],
    actionOwner: request.action_owner ?? request.operational_state?.dashboard_action_owner ?? request.operational_state?.action_owner ?? null,
    actionOwnerLabel: request.action_owner_label ?? request.operational_state?.action_owner_label ?? null,
    actionPriority: parseNullableNumber(request.action_priority ?? request.operational_state?.action_priority_score ?? request.operational_state?.sort_priority),
    actionBucket: request.action_bucket ?? request.operational_state?.action_bucket ?? null,
    cardTone: request.card_tone ?? request.operational_state?.card_tone ?? null,
    actionTitle: request.action_title ?? request.operational_state?.action_title ?? null,
    actionReason: request.action_reason ?? request.operational_state?.action_reason ?? request.operational_state?.attention_reason ?? null,
    actionFilterKeys: request.action_filter_keys ?? request.operational_state?.action_filter_keys ?? [],
    operationalState: request.operational_state ?? null,
    cancelContext: request.cancel_context ?? null,
    currentStageSummary: request.current_stage_summary ?? null,
    visibleSections: request.visible_sections ?? null,
    serviceVisitHistory: request.service_visit_history ?? null,
    adminOverrides: request.admin_overrides ?? null,
    adminOverrideSummary: request.admin_override_summary ?? null,
    fieldCorrectionPolicy: request.field_correction_policy ?? null,
    attention: request.attention ?? null,
  }
}

function applyOperationControlUpdate(request: ServiceRequest, update: ApiOperationControlUpdate): ServiceRequest {
  if (String(request.id) !== String(update.id)) {
    return request
  }

  return {
    ...request,
    operationControl: Object.prototype.hasOwnProperty.call(update, 'operation_control')
      ? update.operation_control ?? null
      : request.operationControl,
    assignmentBlockers: Object.prototype.hasOwnProperty.call(update, 'assignment_blockers')
      ? update.assignment_blockers ?? null
      : request.assignmentBlockers,
    allowedWorkflowActions: Object.prototype.hasOwnProperty.call(update, 'allowed_workflow_actions')
      ? update.allowed_workflow_actions ?? null
      : request.allowedWorkflowActions,
    allowedWorkflowTransitions: Object.prototype.hasOwnProperty.call(update, 'allowed_workflow_transitions')
      ? update.allowed_workflow_transitions ?? null
      : request.allowedWorkflowTransitions,
    operationalState: Object.prototype.hasOwnProperty.call(update, 'operational_state')
      ? update.operational_state ?? null
      : request.operationalState,
    cancelContext: Object.prototype.hasOwnProperty.call(update, 'cancel_context')
      ? update.cancel_context ?? null
      : request.cancelContext,
    currentStageSummary: Object.prototype.hasOwnProperty.call(update, 'current_stage_summary')
      ? update.current_stage_summary ?? null
      : request.currentStageSummary,
    kanbanColumn: Object.prototype.hasOwnProperty.call(update, 'kanban_column')
      ? update.kanban_column ?? null
      : request.kanbanColumn,
    displayActionLabel: Object.prototype.hasOwnProperty.call(update, 'display_action_label')
      ? update.display_action_label ?? null
      : request.displayActionLabel,
    displayTags: Object.prototype.hasOwnProperty.call(update, 'display_tags')
      ? update.display_tags ?? []
      : request.displayTags,
    attention: Object.prototype.hasOwnProperty.call(update, 'attention')
      ? update.attention ?? null
      : request.attention,
    visibleSections: Object.prototype.hasOwnProperty.call(update, 'visible_sections')
      ? update.visible_sections ?? null
      : request.visibleSections,
    nextAction: Object.prototype.hasOwnProperty.call(update, 'next_action')
      ? update.next_action ?? null
      : request.nextAction,
    nextActionPayload: Object.prototype.hasOwnProperty.call(update, 'next_action_payload')
      ? update.next_action_payload ?? null
      : request.nextActionPayload,
  }
}

function isUnassignedWorkflowRequest(request: ServiceRequest): boolean {
  const technicianName = normalizeTechnicalServiceText(request.technician)

  return technicianName === '' || technicianName === 'atanmadi' || technicianName === 'atanmadı'
}

export function TechnicalServiceOperationCenter() {
  const [filters, setFilters] = useState<FilterState>(() => getTechnicalServiceInitialFilters())
  const [executionControl, setExecutionControl] = useState<ExternalExecutionControl | null>(null)
  const [executionControlLoading, setExecutionControlLoading] = useState(true)
  const [executionControlMessage, setExecutionControlMessage] = useState<string | null>(null)
  const [readRequestIds, setReadRequestIds] = useState<Set<string>>(() => readStoredRequestIds())
  const [selectedPlanDayKey, setSelectedPlanDayKey] = useState<string | null>(null)
  const [planSummaryFilter, setPlanSummaryFilter] = useState<PlanSummaryFilter | null>(null)
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
  const [financialWorkspaceLoading, setFinancialWorkspaceLoading] = useState(false)
  const [financialWorkspaceError, setFinancialWorkspaceError] = useState<string | null>(null)
  const [, setSummaryLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [selectedEvents, setSelectedEvents] = useState<ApiTechnicalServiceEvent[]>([])
  const [selectedDetailRequest, setSelectedDetailRequest] = useState<ServiceRequest | null>(null)
  const selectedDetailRequestRef = useRef<ServiceRequest | null>(null)
  const [assignDialogOpen, setAssignDialogOpen] = useState(false)
  const [scheduleDialogOpen, setScheduleDialogOpen] = useState(false)
  const [contactDialogOpen, setContactDialogOpen] = useState(false)
  const [contactAction, setContactAction] = useState<string | null>(null)
  const [assignTechnicianOption, setAssignTechnicianOption] = useState('')
  const [assignPartnerOption, setAssignPartnerOption] = useState('')
  const [assignOtherTechnician, setAssignOtherTechnician] = useState('')
  const [assignNote, setAssignNote] = useState('')
  const [assignLoading, setAssignLoading] = useState(false)
  const [assignError, setAssignError] = useState<string | null>(null)
  const [assignSuccess, setAssignSuccess] = useState<string | null>(null)
  const assignMutationInFlightRef = useRef(false)
  const [routeQuoteLoading, setRouteQuoteLoading] = useState(false)
  const [routeQuoteError, setRouteQuoteError] = useState<string | null>(null)
  const [routeQuoteManualSaveLoading, setRouteQuoteManualSaveLoading] = useState(false)
  const [routeQuoteManualSaveError, setRouteQuoteManualSaveError] = useState<string | null>(null)
  const [routeQuoteAutoEnabled, setRouteQuoteAutoEnabled] = useState(false)
  const routeQuoteAutoRequestSeq = useRef(0)
  const routeQuoteLatestSelection = useRef({ requestId: '', technicianId: '' })
  const routeQuoteLastAutoKey = useRef('')
  const [extraPaymentCreateLoading, setExtraPaymentCreateLoading] = useState(false)
  const [extraPaymentCreateError, setExtraPaymentCreateError] = useState<string | null>(null)
  const extraPaymentCreateInFlightRef = useRef(false)
  const [technicianEarningMessageLoading, setTechnicianEarningMessageLoading] = useState(false)
  const [technicianEarningMessageError, setTechnicianEarningMessageError] = useState<string | null>(null)
  const [fieldDocumentReviewLoading, setFieldDocumentReviewLoading] = useState<string | null>(null)
  const [fieldDocumentReviewError, setFieldDocumentReviewError] = useState<string | null>(null)
  const [customerApprovalResendLoading, setCustomerApprovalResendLoading] = useState(false)
  const [customerApprovalResendError, setCustomerApprovalResendError] = useState<string | null>(null)
  const [partnerActionReviewLoading, setPartnerActionReviewLoading] = useState<string | null>(null)
  const [partnerActionReviewError, setPartnerActionReviewError] = useState<string | null>(null)
  const [appointmentApprovalInFlight, setAppointmentApprovalInFlight] = useState<string | null>(null)
  const [appointmentApprovalError, setAppointmentApprovalError] = useState<string | null>(null)
  const [appointmentApprovalSuccess, setAppointmentApprovalSuccess] = useState<string | null>(null)
  const appointmentApprovalInFlightRef = useRef<string | null>(null)
  const [assignmentOfferUpdateInFlight, setAssignmentOfferUpdateInFlight] = useState(false)
  const [assignmentOfferUpdateError, setAssignmentOfferUpdateError] = useState<string | null>(null)
  const [assignmentOfferUpdateSuccess, setAssignmentOfferUpdateSuccess] = useState<string | null>(null)
  const assignmentOfferUpdateInFlightRef = useRef(false)
  const [opsDetailVisibility, setOpsDetailVisibility] = useState<OpsDetailVisibilitySettings>(DEFAULT_OPS_DETAIL_VISIBILITY)
  const [showNearbyTechnicians, setShowNearbyTechnicians] = useState(false)
  const [scheduleDate, setScheduleDate] = useState('')
  const [scheduleTimeSlot, setScheduleTimeSlot] = useState('')
  const [scheduleNote, setScheduleNote] = useState('')
  const [scheduleLoading, setScheduleLoading] = useState(false)
  const [scheduleError, setScheduleError] = useState<string | null>(null)
  const [travelRoundTripKm, setTravelRoundTripKm] = useState('')
  const [assignOverrideWithoutPayment, setAssignOverrideWithoutPayment] = useState(false)
  const [assignOverrideReason, setAssignOverrideReason] = useState('')
  const [assignOfferLaborAmount, setAssignOfferLaborAmount] = useState('')
  const [assignOfferRouteFeeAmount, setAssignOfferRouteFeeAmount] = useState('')
  const [assignCustomerDirectAmount, setAssignCustomerDirectAmount] = useState('')
  const [assignOfferNote, setAssignOfferNote] = useState('')
  const [assignmentConfirmDialogOpen, setAssignmentConfirmDialogOpen] = useState(false)
  const assignmentDraftRequestId = useRef<string | null>(null)
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

  const resetAssignmentDraftForTechnicianChange = useCallback(() => {
    routeQuoteAutoRequestSeq.current += 1
    routeQuoteLastAutoKey.current = ''
    setRouteQuoteAutoEnabled(false)
    setAssignOfferLaborAmount('')
    setAssignOfferRouteFeeAmount('')
    setAssignCustomerDirectAmount('')
    setAssignmentConfirmDialogOpen(false)
    setRouteQuoteError(null)
    setRouteQuoteManualSaveError(null)
  }, [])
  const [fieldNote, setFieldNote] = useState('')
  const [fieldIncompleteReason, setFieldIncompleteReason] = useState('')
  const [fieldIncompleteWorkflowStatus, setFieldIncompleteWorkflowStatus] = useState('Beklemede')
  const [fieldRequiresSecondVisit, setFieldRequiresSecondVisit] = useState(false)
  const [fieldSecondVisitReason, setFieldSecondVisitReason] = useState('')
  const [fieldChecklist, setFieldChecklist] = useState<Record<string, boolean>>(() => Object.fromEntries(FIELD_CHECKLIST_ITEMS.map((item) => [item, false])))
  const [fieldBeforePhotoCount, setFieldBeforePhotoCount] = useState('3')
  const [fieldAfterPhotoCount, setFieldAfterPhotoCount] = useState('3')
  const [fieldGeneralPhotoCount, setFieldGeneralPhotoCount] = useState('1')
  const [fieldDocumentStatus, setFieldDocumentStatus] = useState('tamamlandı')
  const [fieldApprovalMethod, setFieldApprovalMethod] = useState<(typeof FIELD_CLOSURE_METHODS)[number]>('otp')
  const [fieldApprovalCode, setFieldApprovalCode] = useState('')
  const [fieldSignatureName, setFieldSignatureName] = useState('')
  const [fieldLoading, setFieldLoading] = useState(false)
  const [fieldError, setFieldError] = useState<string | null>(null)
  const [completeDialogOpen, setCompleteDialogOpen] = useState(false)
  const [requestCancellationDialogOpen, setRequestCancellationDialogOpen] = useState(false)
  const [requestCancellationReason, setRequestCancellationReason] = useState('')
  const [requestCancellationNote, setRequestCancellationNote] = useState('')
  const [requestCancellationLoading, setRequestCancellationLoading] = useState(false)
  const [requestCancellationError, setRequestCancellationError] = useState<string | null>(null)
  const [completionReason, setCompletionReason] = useState('')
  const [completionOtherNote, setCompletionOtherNote] = useState('')
  const [installationCompletedAt, setInstallationCompletedAt] = useState('')
  const [installationCompletionNote, setInstallationCompletionNote] = useState('')
  const [completeLoading, setCompleteLoading] = useState(false)
  const [completeError, setCompleteError] = useState<string | null>(null)
  const [reopenDialogOpen, setReopenDialogOpen] = useState(false)
  const [reopenType, setReopenType] = useState<'revisit' | 'service_request'>('service_request')
  const [reopenReason, setReopenReason] = useState('')
  const [reopenNote, setReopenNote] = useState('')
  const [reopenLoading, setReopenLoading] = useState(false)
  const [reopenError, setReopenError] = useState<string | null>(null)
  const [createLoading, setCreateLoading] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)
  const [priorityUpdateLoading, setPriorityUpdateLoading] = useState(false)
  const [priorityUpdateError, setPriorityUpdateError] = useState<string | null>(null)
  const [workflowActionLoading, setWorkflowActionLoading] = useState<string | null>(null)
  const [operationControlUpdateLoading, setOperationControlUpdateLoading] = useState(false)
  const [operationControlUpdateError, setOperationControlUpdateError] = useState<string | null>(null)
  const [adminOverrideLoading, setAdminOverrideLoading] = useState(false)
  const [adminOverrideError, setAdminOverrideError] = useState<string | null>(null)
  const [invoiceSerialRecheckLoading, setInvoiceSerialRecheckLoading] = useState(false)
  const [invoiceSerialRecheckError, setInvoiceSerialRecheckError] = useState<string | null>(null)
  const [invoiceSerialActionLoading, setInvoiceSerialActionLoading] = useState<string | null>(null)
  const [invoiceSerialActionError, setInvoiceSerialActionError] = useState<string | null>(null)
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
  const paymentWorkspacePromiseRef = useRef<Record<string, Promise<void>>>({})
  const serialLookupTokenRef = useRef(0)
  const detailScrollRef = useRef<HTMLDivElement | null>(null)
  const requestsRef = useRef<ServiceRequest[]>([])
  const selectedListRequestRef = useRef<ServiceRequest | null>(null)
  const createDistrictOptions = useMemo(() => getDistrictOptionsForProvince(createForm.city), [createForm.city])
  const hasCreateDistrictFallback = createForm.district.trim() !== ''
    && !createDistrictOptions.some((district) => district.normalizedName === normalizeTurkishLocation(createForm.district))

  const loadExecutionControl = useCallback(async (clearMessage = true): Promise<ExternalExecutionControl | null> => {
    setExecutionControlLoading(true)

    if (clearMessage) {
      setExecutionControlMessage(null)
    }

    try {
      const response = await fetch('/api/technical-service/execution-control', {
        method: 'GET',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })

      if (!response.ok) {
        setExecutionControlMessage(await responseErrorMessage(response, 'Sistem çalışma modu alınamadı.'))

        return null
      }

      const payload = await response.json() as { execution_control: ExternalExecutionControl }
      setExecutionControl(payload.execution_control)

      return payload.execution_control
    } catch {
      setExecutionControlMessage('Sistem çalışma modu alınamadı; otomatik tekrar yapılmadı.')

      return null
    } finally {
      setExecutionControlLoading(false)
    }
  }, [])

  const loadRequests = useCallback(async (options: { silent?: boolean, preserveSelection?: boolean } = {}) => {
    if (!options.silent) {
      setLoading(true)
    }

    setError(null)

    try {
      const response = await apiRequest(getTechnicalServiceRequestListUrl())
      const items = Array.isArray(response.items) ? response.items : []
      const mappedItems = items.map(mapApiRequest)
      setRequests(mappedItems)

      if (options.preserveSelection && selectedIdRef.current) {
        const selected = mappedItems.find((item) => item.id === selectedIdRef.current) ?? null

        if (selected) {
          setSelectedListRequest(selected)
          setSelectedDetailRequest((current) => (current?.id === selected.id ? { ...selected, ...current } : current))
        } else {
          selectedIdRef.current = null
          setSelectedId(null)
          setSelectedListRequest(null)
          setSelectedDetailRequest(null)
          setSelectedEvents([])
          setIsDetailDialogOpen(false)
        }
      }
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Teknik servis talepleri alınamadı.')
    } finally {
      if (!options.silent) {
        setLoading(false)
      }
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

  useEffect(() => {
    requestsRef.current = requests
  }, [requests])

  useEffect(() => {
    selectedListRequestRef.current = selectedListRequest
  }, [selectedListRequest])

  useEffect(() => {
    selectedDetailRequestRef.current = selectedDetailRequest
  }, [selectedDetailRequest])

  useEffect(() => {
    selectedIdRef.current = selectedId
  }, [selectedId])

  useEffect(() => {
    let active = true

    const loadOpsDetailVisibility = async () => {
      try {
        const response = await apiRequest('/api/technical-service/qr-flow-settings')
        const visibility = response?.settings?.ops_detail_visibility

        if (!active || !visibility || typeof visibility !== 'object') {
          return
        }

        setOpsDetailVisibility({
          show_mount_excluded_approval_block: Boolean(visibility.show_mount_excluded_approval_block),
          show_payment_mount_control_block: Boolean(visibility.show_payment_mount_control_block),
          show_address_control_block: Boolean(visibility.show_address_control_block),
        })
      } catch {
        if (active) {
          setOpsDetailVisibility(DEFAULT_OPS_DETAIL_VISIBILITY)
        }
      }
    }

    void loadOpsDetailVisibility()

    return () => {
      active = false
    }
  }, [])

  const preserveDetailScroll = useCallback((update: () => void) => {
    const scrollTop = detailScrollRef.current?.scrollTop ?? 0
    update()
    window.requestAnimationFrame(() => {
      if (detailScrollRef.current) {
        detailScrollRef.current.scrollTop = scrollTop
      }
    })
  }, [])

  const loadRequestDetail = useCallback(async (id: string) => {
    const requestId = String(id)
    const requestToken = detailRequestTokenRef.current + 1
    detailRequestTokenRef.current = requestToken
    const expectedListRequest = selectedListRequestRef.current ?? requestsRef.current.find((item) => item.id === requestId) ?? null
    const isCurrentRequest = () => detailRequestTokenRef.current === requestToken && selectedIdRef.current === requestId
    const preserveCurrentDetail = selectedDetailRequestRef.current?.id === requestId
    setDetailLoading(!preserveCurrentDetail)
    setDetailError(null)
    setFinancialWorkspaceLoading(true)
    setFinancialWorkspaceError(null)

    if (!preserveCurrentDetail) {
      setSelectedDetailRequest(null)
      setSelectedEvents([])
    }

    const financialWorkspacePromise = apiRequest(`/api/technical-service/requests/${id}?section=financial`)
      .then((workspace) => ({ workspace, error: null as Error | null }))
      .catch((caught) => ({
        workspace: null,
        error: caught instanceof Error ? caught : new Error('Finans ve hakediş özeti yüklenemedi.'),
      }))

    try {
      const response = await apiRequest(`/api/technical-service/requests/${id}`)

      if (!isCurrentRequest()) {
        return
      }

      const request = response.request

      if (!request) {
        setDetailError('Talep detayları bulunamadı.')
        setDetailLoading(false)
        setFinancialWorkspaceLoading(false)

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

        if (!preserveCurrentDetail) {
          setSelectedDetailRequest(null)
          setSelectedEvents([])
        }

        setDetailLoading(false)
        setFinancialWorkspaceLoading(false)

        return
      }

      if (!isCurrentRequest()) {
        return
      }

      setSelectedDetailRequest(mappedDetail)
      setSelectedEvents(Array.isArray(request?.events) ? request.events : [])

      void financialWorkspacePromise
        .then(({ workspace, error }) => {
          if (!isCurrentRequest()) {
            return
          }

          if (error || !workspace) {
            setFinancialWorkspaceError(error?.message ?? 'Finans ve hakediş özeti yüklenemedi.')

            return
          }

          preserveDetailScroll(() => {
            setSelectedDetailRequest((current) => current?.id === requestId ? {
              ...current,
              earningBreakdown: workspace.earning_breakdown ?? null,
              financeSummary: workspace.finance_summary ?? null,
              settlement: workspace.settlement ?? current.settlement ?? null,
            } : current)
          })
        })
        .finally(() => {
          if (isCurrentRequest()) {
            setFinancialWorkspaceLoading(false)
          }
        })
    } catch (caught) {
      if (!isCurrentRequest()) {
        return
      }

      setDetailError(caught instanceof Error ? caught.message : 'Talep detayları yüklenemedi.')

      if (!preserveCurrentDetail) {
        setSelectedEvents([])
        setSelectedDetailRequest(null)
      }

      setFinancialWorkspaceLoading(false)
    } finally {
      if (isCurrentRequest()) {
        setDetailLoading(false)
      }
    }
  }, [preserveDetailScroll])

  const loadPaymentWorkspace = useCallback((id: string): Promise<void> => {
    const requestId = String(id)
    const existing = paymentWorkspacePromiseRef.current[requestId]

    if (existing) {
      return existing
    }

    const promise = apiRequest(`/api/technical-service/requests/${requestId}?section=payments`)
      .then((workspace) => {
        if (selectedIdRef.current !== requestId || !workspace?.sale_and_payment) {
          return
        }

        preserveDetailScroll(() => {
          setSelectedDetailRequest((current) => current?.id === requestId ? {
            ...current,
            saleAndPayment: workspace.sale_and_payment,
          } : current)
        })
      })
      .finally(() => {
        delete paymentWorkspacePromiseRef.current[requestId]
      })

    paymentWorkspacePromiseRef.current[requestId] = promise

    return promise
  }, [preserveDetailScroll])

  useEffect(() => {
    void Promise.resolve().then(() => loadExecutionControl())
  }, [loadExecutionControl])

  useEffect(() => {
    void Promise.resolve().then(loadSummary)
  }, [loadSummary])

  useEffect(() => {
    void Promise.resolve().then(loadRequests)
  }, [loadRequests])

  useEffect(() => {
    const refreshVisibleRequests = () => {
      if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
        return
      }

      void loadRequests({ silent: true, preserveSelection: true })
    }

    const interval = window.setInterval(refreshVisibleRequests, 30000)
    window.addEventListener('focus', refreshVisibleRequests)
    document.addEventListener('visibilitychange', refreshVisibleRequests)

    return () => {
      window.clearInterval(interval)
      window.removeEventListener('focus', refreshVisibleRequests)
      document.removeEventListener('visibilitychange', refreshVisibleRequests)
    }
  }, [loadRequests])

  const pendingPaymentPollTargetKey = JSON.stringify(resolveVisiblePendingPaymentTargets(selectedDetailRequest))

  useEffect(() => {
    if (!selectedId || pendingPaymentPollTargetKey === '[]') {
      return
    }

    const selectedRequestId = String(selectedId)
    const targets = JSON.parse(pendingPaymentPollTargetKey) as Array<{ requestId: string, paymentId: string }>
    let cancelled = false
    let timer: number | null = null
    let inFlight = false

    const scheduleNextPoll = (delay: number) => {
      if (!cancelled) {
        timer = window.setTimeout(refreshPaymentStatus, delay)
      }
    }

    const refreshPaymentStatus = async () => {
      if (cancelled || inFlight) {
        return
      }

      if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
        scheduleNextPoll(PAYMENT_RECONCILIATION_POLL_INTERVAL_MS)

        return
      }

      inFlight = true
      const startedAt = Date.now()

      try {
        for (const target of targets) {
          const response = await apiRequest(`/api/technical-service/requests/${target.requestId}/payments/${target.paymentId}/status?sync_provider=1`)

          if (cancelled || String(selectedId ?? '') !== selectedRequestId || !response.request) {
            return
          }

          const updatedRequest = mapApiRequest(response.request)

          if (String(updatedRequest.id) !== selectedRequestId) {
            continue
          }

          preserveDetailScroll(() => {
            setRequests((current) => current.map((item) => (
              item.id === updatedRequest.id ? updatedRequest : item
            )))
            setSelectedListRequest((current) => (
              current?.id === updatedRequest.id ? updatedRequest : current
            ))
            setSelectedDetailRequest(updatedRequest)
          })
        }
      } catch {
        // Polling is intentionally quiet; explicit create/assign actions still surface errors.
      } finally {
        inFlight = false

        if (!cancelled) {
          const elapsed = Date.now() - startedAt
          scheduleNextPoll(Math.max(0, PAYMENT_RECONCILIATION_POLL_INTERVAL_MS - elapsed))
        }
      }
    }

    void refreshPaymentStatus()

    return () => {
      cancelled = true

      if (timer !== null) {
        window.clearTimeout(timer)
      }
    }
  }, [
    pendingPaymentPollTargetKey,
    preserveDetailScroll,
    selectedId,
  ])

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
        setFinancialWorkspaceError(null)
        setFinancialWorkspaceLoading(false)
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
  const isCompletedRequest = useCallback((request: ServiceRequest) => normalizeTechnicalServiceText(request.status) === 'tamamlandi', [])
  const isCancelledRequest = useCallback((request: ServiceRequest) => normalizeTechnicalServiceText(request.status) === 'iptal', [])
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

  const baseKanbanRequests = useMemo(() => {
    return sortedRequests.filter((request) => {
      if (!matchesSearchFilter(request)) {
        return false
      }

      if (filters.onlyOpen && !isOpenRequest(request)) {
        return false
      }

      return true
    })
  }, [filters.onlyOpen, isOpenRequest, matchesSearchFilter, sortedRequests])

  const summaryFilteredRequests = useMemo(() => {
    if (planSummaryFilter === null) {
      return baseKanbanRequests
    }

    return baseKanbanRequests.filter((request) => {
      const scheduledDate = getRequestScheduledDate(request)

      switch (planSummaryFilter) {
        case 'week':
          return scheduledDate !== null && scheduledDate >= weekStartDate && scheduledDate < weekEndDate
        case 'today':
          return scheduledDate !== null && isSameLocalDay(scheduledDate, todayDate)
        case 'overdue':
          return isOverdueRequest(request)
        case 'unscheduled':
          return scheduledDate === null
        case 'closed':
          return isCompletedRequest(request) || isCancelledRequest(request)
        default:
          return true
      }
    })
  }, [baseKanbanRequests, isCancelledRequest, isCompletedRequest, isOverdueRequest, planSummaryFilter, todayDate, weekEndDate, weekStartDate])

  const kanbanFilteredRequests = useMemo(() => {
    if (selectedPlanDayKey !== null) {
      return baseKanbanRequests.filter((request) => {
        const scheduledDate = getRequestScheduledDate(request)

        return scheduledDate !== null && toDateKey(scheduledDate) === selectedPlanDayKey
      })
    }

    return summaryFilteredRequests
  }, [baseKanbanRequests, selectedPlanDayKey, summaryFilteredRequests])

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

  useEffect(() => {
    routeQuoteLatestSelection.current = {
      requestId: selectedId ?? '',
      technicianId: assignTechnicianOption,
    }
  }, [assignTechnicianOption, selectedId])

  const applyUpdatedRequest = (updatedRequest: ServiceRequest) => {
    preserveDetailScroll(() => {
      setRequests((current) => current.map((request) => (
        request.id === updatedRequest.id ? updatedRequest : request
      )))
      setSelectedListRequest((current) => (
        current?.id === updatedRequest.id ? updatedRequest : current
      ))
      setSelectedDetailRequest(updatedRequest)
    })
  }

  const handleAdminOverrideSubmit = async (payload: { field_key: string, new_value: unknown, reason: string, mode?: 'apply' | 'request' }) => {
    if (!selectedId) {
      return
    }

    setAdminOverrideLoading(true)
    setAdminOverrideError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/overrides`, {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest) {
        applyUpdatedRequest(updatedRequest)
      } else {
        await loadRequestDetail(selectedId)
      }

      await loadSummary()
    } catch (caught) {
      setAdminOverrideError(caught instanceof Error ? caught.message : 'Düzeltme kaydedilemedi.')
    } finally {
      setAdminOverrideLoading(false)
    }
  }

  const handleAdminOverrideReview = async (overrideId: number | string, action: 'approve' | 'reject', note?: string | null) => {
    if (!selectedId) {
      return
    }

    setAdminOverrideLoading(true)
    setAdminOverrideError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/overrides/${overrideId}/${action}`, {
        method: 'POST',
        body: JSON.stringify({ note: note ?? null }),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest) {
        applyUpdatedRequest(updatedRequest)
      } else {
        await loadRequestDetail(selectedId)
      }

      await loadSummary()
    } catch (caught) {
      setAdminOverrideError(caught instanceof Error ? caught.message : 'Düzeltme kararı kaydedilemedi.')
    } finally {
      setAdminOverrideLoading(false)
    }
  }

  const handlePriorityChange = async (priority: ServicePriority) => {
    if (!selectedId || modalRequest?.priority === priority) {
      return
    }

    setPriorityUpdateLoading(true)
    setPriorityUpdateError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}`, {
        method: 'PATCH',
        body: JSON.stringify({ priority }),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })
      } else {
        await loadRequestDetail(selectedId)
      }

      await loadSummary()
    } catch (caught) {
      setPriorityUpdateError(caught instanceof Error ? caught.message : 'Öncelik güncellenemedi.')
    } finally {
      setPriorityUpdateLoading(false)
    }
  }

  const handleOperationControlChange = async (payload: Partial<NonNullable<ServiceRequest['operationControl']>>) => {
    if (!selectedId) {
      return
    }

    setOperationControlUpdateLoading(true)
    setOperationControlUpdateError(null)

    let previousRequestsSnapshot: ServiceRequest[] | null = null
    const previousListRequest = selectedListRequest
    const previousDetailRequest = selectedDetailRequest
    const currentOperationControl = selectedDetailRequest?.operationControl
      ?? selectedListRequest?.operationControl
      ?? selectedRequest?.operationControl
      ?? {}
    const optimisticOperationControlUpdate: ApiOperationControlUpdate = {
      id: selectedId,
      operation_control: {
        ...currentOperationControl,
        ...payload,
      },
    }

    preserveDetailScroll(() => {
      setRequests((current) => {
        previousRequestsSnapshot = current

        return current.map((request) => applyOperationControlUpdate(request, optimisticOperationControlUpdate))
      })
      setSelectedListRequest((current) => (
        current && String(current.id) === String(selectedId)
          ? applyOperationControlUpdate(current, optimisticOperationControlUpdate)
          : current
      ))
      setSelectedDetailRequest((current) => (
        current && String(current.id) === String(selectedId)
          ? applyOperationControlUpdate(current, optimisticOperationControlUpdate)
          : current
      ))
    })

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/operation-control`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null
      const operationControlUpdate = response.operation_control_update as ApiOperationControlUpdate | undefined

      if (updatedRequest) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })
      } else if (operationControlUpdate) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => applyOperationControlUpdate(request, operationControlUpdate)))
          setSelectedListRequest((current) => (
            current && String(current.id) === String(operationControlUpdate.id)
              ? applyOperationControlUpdate(current, operationControlUpdate)
              : current
          ))
          setSelectedDetailRequest((current) => (
            current && String(current.id) === String(operationControlUpdate.id)
              ? applyOperationControlUpdate(current, operationControlUpdate)
              : current
          ))
        })
      } else {
        await loadRequestDetail(selectedId)
      }
    } catch (caught) {
      if (previousRequestsSnapshot) {
        preserveDetailScroll(() => {
          setRequests(previousRequestsSnapshot ?? [])
          setSelectedListRequest(previousListRequest)
          setSelectedDetailRequest(previousDetailRequest)
        })
      }

      setOperationControlUpdateError(caught instanceof Error ? caught.message : 'Operasyon kontrolü güncellenemedi.')
    } finally {
      setOperationControlUpdateLoading(false)
    }
  }

  const handleInvoiceSerialRecheck = async () => {
    if (!selectedId) {
      return
    }

    setInvoiceSerialRecheckLoading(true)
    setInvoiceSerialRecheckError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/invoice-serials/recheck`, {
        method: 'POST',
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })
      } else {
        await loadRequestDetail(selectedId)
      }
    } catch (caught) {
      setInvoiceSerialRecheckError(caught instanceof Error ? caught.message : 'Fatura seri kontrolü yenilenemedi.')
    } finally {
      setInvoiceSerialRecheckLoading(false)
    }
  }

  const handleInvoiceSerialAction = async (action: 'add' | 'remove' | 'add-all', serialId?: number | string) => {
    if (!selectedId) {
      return
    }

    const loadingKey = action === 'add-all' ? 'add-all' : `${action}:${serialId}`
    setInvoiceSerialActionLoading(loadingKey)
    setInvoiceSerialActionError(null)

    try {
      const path = action === 'add-all'
        ? `/api/technical-service/requests/${selectedId}/invoice-serials/add-all`
        : `/api/technical-service/requests/${selectedId}/invoice-serials/${serialId}/${action}`
      const response = await apiRequest(path, {
        method: action === 'remove' ? 'DELETE' : 'POST',
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })
      } else {
        await loadRequestDetail(selectedId)
      }
    } catch (caught) {
      setInvoiceSerialActionError(caught instanceof Error ? caught.message : 'Fatura seri aksiyonu uygulanamadı.')
    } finally {
      setInvoiceSerialActionLoading(null)
    }
  }

  const openFieldDialog = useCallback((action: string, request: ServiceRequest | null) => {
    setFieldAction(action)
    setFieldNote('')
    setFieldIncompleteReason(request?.incompleteReason ?? request?.pendingReason ?? '')
    setFieldIncompleteWorkflowStatus(action === 'parts_pending' ? 'Parça Bekleniyor' : 'Beklemede')
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
    setFieldDocumentStatus(request?.documentStatus ?? 'tamamlandı')
    setFieldApprovalMethod((request?.customerClosureApprovalMethod as (typeof FIELD_CLOSURE_METHODS)[number] | null) ?? 'otp')
    setFieldApprovalCode(request?.customerClosureApprovalCode ?? '')
    setFieldSignatureName(request?.customerSignatureName ?? '')
    setFieldError(null)
    setFieldDialogOpen(true)
  }, [])
  const selectedAssignTechnicianRecord = technicians.find((technician) => technician.id === assignTechnicianOption) ?? null
  const selectedAssignPartnerLinks = activeTechnicianPartnerLinks(selectedAssignTechnicianRecord)
  const modalRouteQuote = modalRequest?.routeQuote ?? null
  const modalFinanceSummary = modalRequest?.financeSummary ?? null
  const modalCurrentFinance = modalFinanceSummary?.current_visit ?? null
  const modalFinanceCustomerCollection = modalCurrentFinance?.customer_collection ?? null
  const modalFinancePayout = modalCurrentFinance?.locksmith_payout ?? null
  const modalFinanceNetMargin = modalCurrentFinance?.net_margin ?? null
  const selectedAssignmentTechnicianId = assignTechnicianOption && assignTechnicianOption !== 'other'
    ? String(assignTechnicianOption)
    : null
  const modalRequestTechnicianId = modalRequest?.technicianId !== null && modalRequest?.technicianId !== undefined
    ? String(modalRequest.technicianId)
    : null
  const modalFinancePayoutTechnicianId = modalFinancePayout?.technician_id !== null && modalFinancePayout?.technician_id !== undefined
    ? String(modalFinancePayout.technician_id)
    : null
  const modalFinancePayoutMatchesSelection = Boolean(
    modalFinancePayout
    && selectedAssignmentTechnicianId
    && (
      modalFinancePayoutTechnicianId === selectedAssignmentTechnicianId
      || (!modalFinancePayoutTechnicianId && modalRequestTechnicianId === selectedAssignmentTechnicianId)
      || (!modalFinancePayoutTechnicianId && !modalRequestTechnicianId)
    ),
  )
  const activeModalFinancePayout = modalFinancePayoutMatchesSelection ? modalFinancePayout : null
  const assignmentRouteQuote = routeQuoteActiveForSelection(modalRouteQuote, assignTechnicianOption, selectedAssignTechnicianRecord, modalRequest)
    ? modalRouteQuote
    : null
  const modalPayment = getServicePaymentInfo(
    modalRequest?.serviceType,
    assignmentRouteQuote?.round_trip_distance_km ?? assignmentRouteQuote?.distance_km ?? null,
    assignmentRouteQuote?.fee_amount ?? null,
    assignmentRouteQuote?.billable_km ?? assignmentRouteQuote?.extra_km ?? null,
    modalRequest?.technicianPaymentAmount,
  )
  const modalCollectedPaymentAmount = typeof modalFinanceCustomerCollection?.total_amount === 'number' && Number.isFinite(modalFinanceCustomerCollection.total_amount)
    ? modalFinanceCustomerCollection.total_amount
    : parseNullableNumber(modalRequest?.saleAndPayment?.paid_amount)
    ?? parseNullableNumber(modalRequest?.saleAndPayment?.payment_status?.amount)
    ?? null
  const modalFinanceHasRecordedCollection = modalFinanceCustomerCollection?.has_collection === true
  const modalZeroCollectionIsExpected = modalCurrentFinance?.is_service_visit === true || modalCurrentFinance?.warranty_covered === true
  const modalCollectedPaymentLabel = modalFinanceCustomerCollection?.total_amount_label && (modalFinanceHasRecordedCollection || modalZeroCollectionIsExpected)
    ? modalFinanceCustomerCollection.total_amount_label
    : modalFinanceCustomerCollection?.total_amount_label && modalCollectedPaymentAmount !== null && modalCollectedPaymentAmount > 0
    ? modalFinanceCustomerCollection.total_amount_label
    : modalRequest?.saleAndPayment?.paid_amount_label
    ?? (modalCollectedPaymentAmount !== null && modalCollectedPaymentAmount > 0 ? formatMoneyLabel(modalCollectedPaymentAmount) : 'Ödeme kaydı yok')
  const assignPaymentPreview = getServicePaymentInfo(
    modalRequest?.serviceType,
    assignmentRouteQuote?.round_trip_distance_km ?? assignmentRouteQuote?.distance_km ?? null,
    assignmentRouteQuote?.fee_amount ?? null,
    assignmentRouteQuote?.billable_km ?? assignmentRouteQuote?.extra_km ?? null,
  )
  const assignmentTechnicianLaborAmount = typeof activeModalFinancePayout?.labor_amount === 'number' && Number.isFinite(activeModalFinancePayout.labor_amount) && activeModalFinancePayout.labor_amount > 0
    ? activeModalFinancePayout.labor_amount
    : typeof modalRequest?.technicianPaymentAmount === 'number' && Number.isFinite(modalRequest.technicianPaymentAmount)
    ? modalRequest.technicianPaymentAmount
    : assignPaymentPreview.customerAmount
  const assignmentTechnicianLaborSourceLabel = activeModalFinancePayout
    ? (activeModalFinancePayout.payout_status === 'confirmed' ? 'Onaylanan hakediş kaydı' : 'Mevcut taslak hakediş kaydı')
    : modalRequest?.technicianPaymentAmount !== null && modalRequest?.technicianPaymentAmount !== undefined
      ? 'Talep üzerindeki hakediş kaydı'
      : assignPaymentPreview.technicianAmountSourceLabel
  const assignmentRouteFeeAmount = assignmentRouteQuote && typeof assignmentRouteQuote.fee_amount === 'number' && Number.isFinite(assignmentRouteQuote.fee_amount)
    ? assignmentRouteQuote.fee_amount
    : null
  const assignmentTravelAmountLabel = assignmentRouteQuote
    ? assignPaymentPreview.travelAmountLabel
    : 'Hesaplanmadı'
  const assignmentTotalTechnicianCostAmount = assignmentTechnicianLaborAmount !== null
    ? assignmentTechnicianLaborAmount + (assignmentRouteFeeAmount ?? 0)
    : null
  const assignmentTotalTechnicianCostLabel = assignmentTotalTechnicianCostAmount !== null
    ? formatMoneyLabel(assignmentTotalTechnicianCostAmount)
    : 'Belirlenmedi'
  const modalPayoutStatus = activeModalFinancePayout?.payout_status
    ?? (modalFinancePayoutMatchesSelection ? modalCurrentFinance?.payout_status : null)
    ?? (modalRequest?.assignmentOffer ? 'confirmed' : assignmentTotalTechnicianCostAmount !== null && assignmentTotalTechnicianCostAmount > 0 ? 'draft' : null)
  const modalPayoutStatusLabel = activeModalFinancePayout?.payout_status_label
    ?? (modalFinancePayoutMatchesSelection ? modalCurrentFinance?.payout_status_label : null)
    ?? (modalPayoutStatus === 'confirmed'
      ? 'Onaylanan usta hakedişi'
      : modalPayoutStatus === 'draft'
        ? 'Önerilen / taslak hakediş'
        : 'Hakediş yok')
  const assignmentPayoutSummaryLabel = modalPayoutStatus === 'confirmed'
    ? 'Onaylanan usta hakedişi'
    : modalPayoutStatus === 'draft'
      ? 'Önerilen / taslak hakediş'
      : 'Usta hakedişi'
  const modalNetDifferenceLabel = modalCurrentFinance?.warranty_covered
    ? 'Net operasyon farkı'
    : 'Net fark / kâr'
  const finalAssignmentLaborAmount = parseNullableNumber(assignOfferLaborAmount) ?? assignmentTechnicianLaborAmount ?? 0
  const finalAssignmentRouteAmount = parseNullableNumber(assignOfferRouteFeeAmount) ?? assignmentRouteFeeAmount ?? 0
  const finalAssignmentTotalAmount = roundTwo(finalAssignmentLaborAmount + finalAssignmentRouteAmount)
  const customerDirectPaymentDisabled = hasMountPaymentReceived(modalRequest)
  const finalAssignmentCustomerDirectDefault = customerDirectPaymentDisabled ? 0 : finalAssignmentTotalAmount
  const parsedAssignmentCustomerDirectAmount = parseNullableNumber(assignCustomerDirectAmount)
  const finalAssignmentCustomerDirectAmount = customerDirectPaymentDisabled
    ? 0
    : roundTwo(parsedAssignmentCustomerDirectAmount ?? finalAssignmentCustomerDirectDefault)
  const finalAssignmentCompanyPayableAmount = roundTwo(Math.max(finalAssignmentTotalAmount - finalAssignmentCustomerDirectAmount, 0))
  const finalAssignmentOverpayAmount = roundTwo(Math.max(finalAssignmentCustomerDirectAmount - finalAssignmentTotalAmount, 0))
  const finalAssignmentHasSmallDifference = finalAssignmentOverpayAmount === 0
    && finalAssignmentCompanyPayableAmount > 0
    && finalAssignmentCompanyPayableAmount <= 10
  const finalAssignmentNetDifference = modalCollectedPaymentAmount !== null
    ? roundTwo(modalCollectedPaymentAmount - finalAssignmentTotalAmount)
    : typeof modalFinanceNetMargin?.amount === 'number' && Number.isFinite(modalFinanceNetMargin.amount)
      ? modalFinanceNetMargin.amount
      : null
  const selectedAssignTechnicianName = assignTechnicianOption === 'other'
    ? assignOtherTechnician.trim()
    : selectedAssignTechnicianRecord ? technicianDisplayName(selectedAssignTechnicianRecord) : ''
  const selectedAssignTechnicianPartnerId = assignPartnerOption || (selectedAssignPartnerLinks.length === 1 ? String(selectedAssignPartnerLinks[0].partner_id) : null)
  const assignmentPartnerJobPath = modalRequest?.id && selectedAssignmentTechnicianId && selectedAssignTechnicianPartnerId
    ? `/partner/service-jobs?${new URLSearchParams({
      partner_id: selectedAssignTechnicianPartnerId,
      job_id: String(modalRequest.id),
    }).toString()}`
    : null
  const assignmentRouteRoundTripKm = typeof assignmentRouteQuote?.round_trip_distance_km === 'number'
    ? assignmentRouteQuote.round_trip_distance_km
    : typeof assignmentRouteQuote?.distance_km === 'number'
      ? assignmentRouteQuote.distance_km
      : null
  const assignmentRouteOneWayKm = typeof assignmentRouteQuote?.one_way_distance_km === 'number'
    ? assignmentRouteQuote.one_way_distance_km
    : typeof assignmentRouteRoundTripKm === 'number'
      ? Math.round((assignmentRouteRoundTripKm / 2) * 100) / 100
      : null
  const assignmentRouteDistanceLabel = typeof assignmentRouteOneWayKm === 'number'
    ? `${assignmentRouteOneWayKm.toLocaleString('tr-TR', { maximumFractionDigits: 2 })} km`
    : '-'
  const assignmentRouteRoundTripLabel = typeof assignmentRouteRoundTripKm === 'number'
    ? `${assignmentRouteRoundTripKm.toLocaleString('tr-TR', { maximumFractionDigits: 2 })} km`
    : '-'
  const assignmentRouteExtraKm = typeof assignmentRouteQuote?.billable_km === 'number'
    ? assignmentRouteQuote.billable_km
    : typeof assignmentRouteQuote?.extra_km === 'number'
      ? assignmentRouteQuote.extra_km
      : null
  const assignmentRouteExtraKmLabel = typeof assignmentRouteExtraKm === 'number'
    ? `${assignmentRouteExtraKm.toLocaleString('tr-TR', { maximumFractionDigits: 2 })} km`
    : '-'
  const assignmentRouteFeeLabel = typeof assignmentRouteQuote?.fee_amount === 'number'
    ? `${assignmentRouteQuote.fee_amount.toLocaleString('tr-TR', { maximumFractionDigits: 2 })} TL`
    : (assignmentRouteQuote?.travel_fee_required ? 'Km başı ücret ayarı eksik' : '-')
  const assignmentRouteFeeReason = (() => {
    if (!modalRequest) {
      return 'Talep seçilmedi.'
    }

    if (!assignmentRouteQuote) {
      const hasTechnicianCoordinates = selectedAssignTechnicianRecord ? technicianCoordinatePair(selectedAssignTechnicianRecord) !== null : false
      const hasRequestCoordinates = requestRouteCoordinatePair(modalRequest) !== null

      if (!selectedAssignTechnicianRecord && assignTechnicianOption !== 'other') {
        return 'Usta seçilmedi; yol hakedişi henüz hesaplanmadı.'
      }

      if (!hasTechnicianCoordinates) {
        return 'Usta konumu eksik; yol hakedişi manuel girilmeli.'
      }

      if (!hasRequestCoordinates) {
        return 'Müşteri konumu eksik; yol hakedişi manuel girilmeli.'
      }

      return 'Yol hakedişi henüz hesaplanmadı; popup tutarı kaydeder.'
    }

    if (assignmentRouteQuote.status && !['calculated', 'manual_override'].includes(assignmentRouteQuote.status)) {
      return assignmentRouteQuote.message ?? 'Yol hakedişi hesaplanamadı; manuel kontrol gerekli.'
    }

    if (finalAssignmentRouteAmount > 0) {
      return `Ücrete tabi km: ${assignmentRouteExtraKmLabel}.`
    }

    if (typeof assignmentRouteQuote.threshold_km === 'number') {
      return `${assignmentRouteQuote.threshold_km.toLocaleString('tr-TR', { maximumFractionDigits: 2 })} km ücretsiz sınır içinde; yol hakedişi 0 TL.`
    }

    return assignmentRouteQuote.message ?? 'Yol hakedişi 0 TL.'
  })()
  const assignmentDirectAmountError = (() => {
    if (assignCustomerDirectAmount.trim() === '') {
      return null
    }

    if (parsedAssignmentCustomerDirectAmount === null) {
      return 'Müşteriye bildirilecek ustaya ödeme tutarı sayısal olmalıdır.'
    }

    if (parsedAssignmentCustomerDirectAmount < 0) {
      return 'Müşteriye bildirilecek ustaya ödeme tutarı negatif olamaz.'
    }

    if (customerDirectPaymentDisabled && parsedAssignmentCustomerDirectAmount > 0) {
      return 'Müşteriden montaj ödemesi alındığı için ustaya doğrudan ödeme tutarı 0 olmalıdır.'
    }

    return null
  })()
  const assignmentFinalMessagePreview = [
    'EMAKS Prime Teknik Servis',
    '',
    'Yeni iş ataması yapıldı.',
    '',
    `MRN: ${modalRequest?.mrn ?? '-'}`,
    `Müşteri: ${modalRequest?.customer ?? '-'}`,
    `Telefon: ${modalRequest?.phone ?? '-'}`,
    `Adres: ${modalRequest?.address ?? '-'}`,
    modalRequest?.product || modalRequest?.model ? `Ürün: ${[modalRequest?.product, modalRequest?.model].filter(Boolean).join(' / ')}` : null,
    modalRequest?.serialNumber ? `Seri: ${modalRequest.serialNumber}` : null,
    modalRequest?.productInfo?.activation_code ? `Aktivasyon: ${modalRequest.productInfo.activation_code}` : null,
    modalRequest?.appointment && modalRequest.appointment !== 'Belirlenmedi' ? `Randevu: ${modalRequest.appointment}` : null,
    '',
    'Hakediş:',
    `İşçilik: ${formatMoneyLabel(finalAssignmentLaborAmount)}`,
    `Yol: ${formatMoneyLabel(finalAssignmentRouteAmount)}`,
    `Toplam: ${formatMoneyLabel(finalAssignmentTotalAmount)}`,
    '',
    'İş kartı:',
    assignmentPartnerJobPath ?? 'Atama sonrası partner bağlantısı üretilecek',
    assignOfferNote.trim() ? `Not: ${assignOfferNote.trim()}` : null,
  ].filter((line): line is string => line !== null).join('\n')
  const effectiveMountPaymentMissing = Boolean(
    modalRequest?.serviceType === 'Montaj'
    && isMountPaymentMissing(mikroMountCheck)
    && requiresCanonicalMountPayment(modalRequest),
  )
  const mountPaymentAccepted = modalRequest?.serviceType === 'Montaj' && isMountPaymentAccepted(mikroMountCheck)
  const mountExclusionAckRequired = requiresMountExclusionAcknowledgement(modalRequest)
  const legacyMountExclusionAckTouched = assignOverrideWithoutPayment || assignOverrideReason.trim().length > 0
  const mountExclusionAckComplete = !legacyMountExclusionAckTouched
    || (assignOverrideWithoutPayment && assignOverrideReason.trim().length >= 5)
  const assignmentPendingOnlinePaymentLink = Boolean(
    modalRequest?.saleAndPayment?.extra_mount_payment?.status === 'pending'
    && (modalRequest.saleAndPayment.extra_mount_payment.copy_url || modalRequest.saleAndPayment.extra_mount_payment.payment_url),
  )
  const assignmentCustomerPaysTechnician = !customerDirectPaymentDisabled && finalAssignmentCustomerDirectAmount > 0
  const preFormPaymentControlEnabledForModal = Boolean(modalRequest?.operationControl?.show_payment_control)
  const paymentNeededNoDecision = Boolean(
    preFormPaymentControlEnabledForModal
    && modalRequest?.serviceType === 'Montaj'
    && !hasMountPaymentReceived(modalRequest)
    && (effectiveMountPaymentMissing || mountExclusionAckRequired)
    && !assignmentPendingOnlinePaymentLink
    && !assignmentCustomerPaysTechnician,
  )
  const paymentDecisionRequiredMessage = 'Ödeme yöntemi netleşmeden atama güncellenemez. Ödeme linki oluşturun veya müşterinin ustaya ödeyeceği tutarı belirleyin.'
  const assignmentBlockerMessages = (modalRequest?.assignmentBlockers?.messages ?? []).filter((message) => {
    if (message !== paymentDecisionRequiredMessage) {
      return true
    }

    return !assignmentPendingOnlinePaymentLink && !assignmentCustomerPaysTechnician
  })
  const hasAssignmentBlockers = assignmentBlockerMessages.length > 0
  const canSubmitAssign = Boolean(
    !assignLoading &&
    assignTechnicianOption &&
    (assignTechnicianOption !== 'other' || assignOtherTechnician.trim()) &&
    (assignTechnicianOption === 'other' || selectedAssignPartnerLinks.length === 0 || Boolean(selectedAssignTechnicianPartnerId)) &&
    !hasAssignmentBlockers &&
    !paymentNeededNoDecision &&
    mountExclusionAckComplete
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
  const assignmentReferenceDateKey = (() => {
    if (modalRequest?.scheduledDate) {
      return modalRequest.scheduledDate
    }

    if (modalRequest?.customerPreferredDate) {
      return modalRequest.customerPreferredDate
    }

    return toDateKey(selectedDate)
  })()
  const assignmentReferenceRequests = (() => {
    return requests.filter((request) => {
      const requestDate = request.scheduledDate
        ?? (request.scheduledAt ? toDateKey(new Date(request.scheduledAt)) : null)

      return requestDate === assignmentReferenceDateKey
    })
  })()
  const technicianAssignmentInsights: TechnicianAssignmentInsight[] = (() => {
    const insights = technicianMatches.map((match) => {
      const technicianName = technicianDisplayName(match.technician)
      const hasTechnicianCoordinates = technicianCoordinatePair(match.technician) !== null
      const addressSummary = technicianAddressSummary(match.technician)
      const hasAddressInfo = addressSummary.trim() !== ''
      const hasPlusCodeInfo = [
        match.technician.location_code,
        match.technician.google_plus_code,
        match.technician.default_start_plus_code,
      ].some((value) => typeof value === 'string' && value.trim() !== '')
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
        null,
      )
      const costDelta = paymentPreview.customerAmount !== null && paymentPreview.totalTechnicianCostAmount !== null
        ? paymentPreview.customerAmount - paymentPreview.totalTechnicianCostAmount
        : null

      return {
        id: match.technician.id,
        name: technicianName,
        location: [match.technician.city, match.technician.district].filter(Boolean).join(' / ') || 'Konum bilgisi yok',
        phone: match.technician.phone_display ?? match.technician.phone_e164 ?? match.technician.phone ?? null,
        priority: match.technician.priority ?? null,
        latitude: match.technician.latitude ?? null,
        longitude: match.technician.longitude ?? null,
        startLatitude: match.technician.start_latitude ?? null,
        startLongitude: match.technician.start_longitude ?? null,
        needsReview: match.technician.needs_review === true,
        hasLocation: hasTechnicianCoordinates,
        hasAddressInfo,
        hasPlusCodeInfo,
        hasCoordinates: hasTechnicianCoordinates,
        routeReady: hasTechnicianCoordinates || hasPlusCodeInfo || hasAddressInfo,
        addressSummary,
        locationCode: match.technician.location_code ?? null,
        routeLocationMessage: hasTechnicianCoordinates
          ? match.technician.needs_review === true
            ? 'Usta koordinatı kontrol gerekli. Usta yol hakedişi otomatik onaylanmamalı.'
            : 'Yol hesabı için koordinat var.'
          : hasPlusCodeInfo || hasAddressInfo
            ? 'Usta adres/Plus Code bilgisi var; yol hesabında güvenli koordinat çözümü yapılacak.'
            : 'Usta adres bilgisi eksik.',
        distanceKmLabel: match.distanceKm !== null
          ? `Yaklaşık şehir/adres mesafesi ${match.distanceKm.toLocaleString('tr-TR')} km`
          : 'Mesafe yok',
        scheduledCount: scheduledJobs.length,
        availableSlots,
        technicianAmountLabel: paymentPreview.technicianAmountLabel,
        technicianAmountSourceLabel: paymentPreview.technicianAmountSourceLabel,
        travelAmountLabel: paymentPreview.travelAmountLabel,
        totalCostLabel: paymentPreview.totalTechnicianCostLabel,
        costDeltaLabel: costDelta === null
          ? '-'
          : costDelta > 0
            ? `+${costDelta.toLocaleString('tr-TR')} TL kâr`
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
  })()
  const visibleTechnicianAssignmentInsights = (() => {
    if (!assignTechnicianOption || assignTechnicianOption === 'other') {
      return technicianAssignmentInsights
    }

    const selectedInsight = technicianAssignmentInsights.find((insight) => insight.id === assignTechnicianOption)

    if (!selectedInsight) {
      return technicianAssignmentInsights
    }

    return [
      selectedInsight,
      ...technicianAssignmentInsights.filter((insight) => insight.id !== selectedInsight.id),
    ]
  })()
  const assignmentScheduleSupport = (() => {
    const currentSchedule = modalRequest?.scheduledDate
      ? [
          formatTechnicalServiceDate(modalRequest.scheduledDate),
          modalRequest.scheduledTime || null,
        ].filter(Boolean).join(' · ')
      : modalRequest?.appointment || '-'

    const preferredSchedule = formatPreferredAppointmentLabel(modalRequest)
    const recommendedSlots = technicianAssignmentInsights.find((insight) => insight.recommended)?.availableSlots
      ?? technicianAssignmentInsights[0]?.availableSlots
      ?? []

    return {
      scheduledLabel: currentSchedule || '-',
      preferredLabel: preferredSchedule,
      customerContactLabel: modalRequest?.customerContactStatus || 'Müşteri teyidi yok',
      slotSuggestions: recommendedSlots.slice(0, 3),
    }
  })()

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
    let completed = 0
    let cancelled = 0

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

      if (isCompletedRequest(request)) {
        completed += 1
      }

      if (isCancelledRequest(request)) {
        cancelled += 1
      }

      if (isOverdueRequest(request)) {
        overdue += 1
      }
    })

    return {
      weekPlanned,
      todayPlanned,
      overdue,
      unscheduled,
      completed,
      cancelled,
    }
  }, [isCancelledRequest, isCompletedRequest, isOverdueRequest, sortedRequests, todayDate, weekEndDate, weekStartDate])

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
    { id: 'week', label: 'Bu Hafta Planı', value: weeklyPlanSummary.weekPlanned, tone: 'blue' as const, isActive: planSummaryFilter === 'week' },
    { id: 'today', label: 'Bugün Planı', value: weeklyPlanSummary.todayPlanned, tone: 'emerald' as const, isActive: planSummaryFilter === 'today' },
    { id: 'overdue', label: 'Geciken', value: weeklyPlanSummary.overdue, tone: 'rose' as const, isActive: planSummaryFilter === 'overdue' },
    { id: 'unscheduled', label: 'Randevu Planlanmayan İşler', value: weeklyPlanSummary.unscheduled, tone: 'amber' as const, isActive: planSummaryFilter === 'unscheduled' },
    {
      id: 'closed',
      label: 'Tamamlanan / İptal',
      value: `${weeklyPlanSummary.completed}/${weeklyPlanSummary.cancelled}`,
      tone: 'slate' as const,
      isActive: planSummaryFilter === 'closed',
    },
  ]
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
    routeQuoteAutoRequestSeq.current += 1
    routeQuoteLastAutoKey.current = ''
    setAssignTechnicianOption(modalRequest?.technicianId ?? '')
    setAssignPartnerOption(modalRequest?.technicianJobCard?.partner_id ? String(modalRequest.technicianJobCard.partner_id) : '')
    setAssignOtherTechnician('')
    setAssignNote('')
    setTravelRoundTripKm(
      typeof modalRequest?.travelRoundTripKm === 'number' && Number.isFinite(modalRequest.travelRoundTripKm)
        ? String(modalRequest.travelRoundTripKm)
        : '',
    )
    setAssignOverrideWithoutPayment(false)
    setAssignOverrideReason('')
    setAssignOfferLaborAmount('')
    setAssignOfferRouteFeeAmount('')
    setAssignCustomerDirectAmount('')
    setAssignOfferNote('')
    setAssignmentConfirmDialogOpen(false)
    setShowNearbyTechnicians(false)
    setAssignError(null)
    setAssignSuccess(null)
    setRouteQuoteError(null)
    setExtraPaymentCreateError(null)
    setTechnicianEarningMessageError(null)
    setRouteQuoteLoading(false)
  }

  const openAssignmentDialog = () => {
    const currentRequestId = modalRequest?.id ?? selectedId ?? null

    if (assignmentDraftRequestId.current !== currentRequestId) {
      handleAssignReset()
      assignmentDraftRequestId.current = currentRequestId
    }

    setAssignError(null)
    setAssignmentConfirmDialogOpen(false)
    setAssignDialogOpen(true)
  }

  const closeAssignmentDialog = () => {
    assignmentDraftRequestId.current = null
    setAssignmentConfirmDialogOpen(false)
    setAssignDialogOpen(false)
    handleAssignReset()
  }

  const handleAssignDialogOpenChange = (open: boolean) => {
    if (open) {
      openAssignmentDialog()

      return
    }

    if (assignLoading || assignmentConfirmDialogOpen) {
      return
    }

    closeAssignmentDialog()
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
    setFieldDocumentStatus('tamamlandı')
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
    setCompletionReason(modalRequest?.serviceType === 'Montaj' ? 'Montaj tamamlandı' : 'Servis tamamlandı')
    setInstallationCompletedAt(toTechnicalServiceDateTimeInputValue(modalRequest?.scheduledAt ?? null))
    setCompleteDialogOpen(true)
  }

  const openRequestCancellationDialog = () => {
    setRequestCancellationReason('')
    setRequestCancellationNote('')
    setRequestCancellationError(null)
    setRequestCancellationDialogOpen(true)
  }

  const handleReopenReset = () => {
    setReopenType('service_request')
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
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/status`, {
        method: 'POST',
        body: JSON.stringify({
          status: 'Yeni',
          reopen_type: reopenType,
          reopen_reason: reopenReason,
          reopen_note: reopenNote || null,
          note: reopenNote || reopenReason,
        }),
      })
      const childRequest = response.child_request ? mapApiRequest(response.child_request) : null
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      setReopenDialogOpen(false)
      handleReopenReset()

      if (childRequest) {
        preserveDetailScroll(() => {
          setRequests((current) => {
            const withoutOldSelected = current.filter((request) => request.id !== selectedId)
            const withoutChild = withoutOldSelected.filter((request) => request.id !== childRequest.id)

            return [childRequest, ...withoutChild]
          })
          selectedIdRef.current = childRequest.id
          setSelectedId(childRequest.id)
          setSelectedListRequest(childRequest)
          setSelectedDetailRequest(childRequest)
          setSelectedEvents(Array.isArray(response.child_request?.events) ? response.child_request.events : [])
          setAssignTechnicianOption('')
        })
      } else if (updatedRequest) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (request.id === updatedRequest.id ? updatedRequest : request)))
          setSelectedListRequest(updatedRequest)
          setSelectedDetailRequest(updatedRequest)
        })
      }

      await loadRequests({ silent: true, preserveSelection: true })
      await loadSummary()
      await loadRequestDetail(childRequest?.id ?? updatedRequest?.id ?? selectedId)
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

    if ([
      'customer_called',
      'customer_unreachable',
      'customer_callback_scheduled',
      'customer_confirmation_pending',
      'customer_confirmed',
      'customer_rejected',
      'wrong_number',
      'customer_requested_cancel',
      'mark_missing_info',
    ].includes(action)) {
      setContactAction(action)
      setContactDialogOpen(true)
      setContactError(null)

      return
    }

    if (action === 'cancel') {
      openRequestCancellationDialog()

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
      setDetailError(caught instanceof Error ? caught.message : 'Workflow aksiyonu uygulanamadı.')
    } finally {
      setWorkflowActionLoading(null)
    }
  }

  const handleScheduleSubmit = async () => {
    if (!selectedId) {
      return
    }

    if (!scheduleDate || !scheduleTimeSlot) {
      setScheduleError('Lütfen randevu tarihi ve saat aralığı seçin.')

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
          scheduled_time_end: selectedTimeSlot?.end ?? null,
          note: scheduleNote || null,
        }),
      })

      setScheduleDialogOpen(false)
      handleScheduleReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setScheduleError(caught instanceof Error ? caught.message : 'Randevu planlama işlemi başarısız oldu.')
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

      if (contactAction === 'mark_missing_info') {
        await apiRequest(`/api/technical-service/requests/${selectedId}/workflow`, {
          method: 'PATCH',
          body: JSON.stringify({
            action: contactAction,
            missing_info_reason: contactNote || null,
            note: contactNote || null,
          }),
        })

        setContactDialogOpen(false)
        handleContactReset()
        await loadRequests()
        await loadSummary()
        await loadRequestDetail(selectedId)

        return
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
      setContactError(caught instanceof Error ? caught.message : 'Müşteri iletişimi kaydedilemedi.')
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
        workflow_status: fieldAction === 'parts_pending' ? 'Parça Bekleniyor' : fieldIncompleteWorkflowStatus,
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

  const handleRouteQuoteCalculate = async () => {
    if (!selectedId) {
      return
    }

    if (!assignTechnicianOption || assignTechnicianOption === 'other' || !selectedAssignTechnicianRecord) {
      setRouteQuoteError('Usta yol hakedişini hesaplamak için kayıtlı bir usta seçin.')

      return
    }

    const submittedRequestId = selectedId
    const submittedTechnicianId = assignTechnicianOption
    const requestSeq = ++routeQuoteAutoRequestSeq.current

    setRouteQuoteLoading(true)
    setRouteQuoteError(null)
    setRouteQuoteManualSaveError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${submittedRequestId}/technicians/${submittedTechnicianId}/route-quote`, {
        method: 'POST',
      })

      if (
        routeQuoteAutoRequestSeq.current !== requestSeq
        || routeQuoteLatestSelection.current.requestId !== submittedRequestId
        || routeQuoteLatestSelection.current.technicianId !== submittedTechnicianId
      ) {
        return
      }

      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (!updatedRequest) {
        setRouteQuoteError('Usta yol hakedişi hesaplandı ancak talep detayı güncellenemedi.')

        return
      }

      preserveDetailScroll(() => {
        setRequests((current) => current.map((request) => (
          request.id === updatedRequest.id ? updatedRequest : request
        )))
        setSelectedListRequest((current) => (
          current?.id === updatedRequest.id ? updatedRequest : current
        ))
        setSelectedDetailRequest(updatedRequest)
      })

      const responseRoundTripKm = typeof response.round_trip_distance_km === 'number' && Number.isFinite(response.round_trip_distance_km)
        ? response.round_trip_distance_km
        : typeof response.distance_km === 'number' && Number.isFinite(response.distance_km)
          ? response.distance_km
          : null

      if (responseRoundTripKm !== null) {
        setTravelRoundTripKm(String(responseRoundTripKm))
      }

      const responseStatus = typeof response.status === 'string' ? response.status : null
      const routeQuoteFailed = response.ok === false || (responseStatus !== null && responseStatus !== 'calculated')

      setRouteQuoteError(routeQuoteFailed
        ? routeQuoteFailureMessage(typeof response.message === 'string' ? response.message : null)
        : null)
    } catch (caught) {
      setRouteQuoteError(routeQuoteFailureMessage(caught instanceof Error ? caught.message : null))
    } finally {
      if (routeQuoteAutoRequestSeq.current === requestSeq) {
        setRouteQuoteLoading(false)
      }
    }
  }

  useEffect(() => {
    if (
      !selectedId
      || !routeQuoteAutoEnabled
      || !assignTechnicianOption
      || assignTechnicianOption === 'other'
      || !selectedAssignTechnicianRecord
      || !modalRequest
      || assignmentRouteQuote
    ) {
      return
    }

    const technicianCoordinates = technicianCoordinatePair(selectedAssignTechnicianRecord)
    const requestCoordinates = requestRouteCoordinatePair(modalRequest)
    const technicianHasAddressAuthority = [
      selectedAssignTechnicianRecord.default_start_address,
      selectedAssignTechnicianRecord.google_formatted_address,
      selectedAssignTechnicianRecord.address,
      selectedAssignTechnicianRecord.cari_address,
      selectedAssignTechnicianRecord.location_code,
      selectedAssignTechnicianRecord.google_plus_code,
      selectedAssignTechnicianRecord.default_start_plus_code,
    ].some((value) => typeof value === 'string' && value.trim() !== '')
    const requestHasAddressAuthority = [modalRequest.address, modalRequest.district, modalRequest.city]
      .some((value) => typeof value === 'string' && value.trim() !== '')
    const missingRouteReason = !technicianCoordinates && !technicianHasAddressAuthority
      ? 'Usta konumu eksik; yol hakedişi manuel girilmeli.'
      : !requestCoordinates && !requestHasAddressAuthority
        ? 'Müşteri konumu eksik; yol hakedişi manuel girilmeli.'
        : null

    const autoKey = [
      selectedId,
      assignTechnicianOption,
      technicianCoordinates?.latitude ?? 'no-technician-location',
      technicianCoordinates?.longitude ?? 'no-technician-location',
      technicianHasAddressAuthority ? technicianAddressSummary(selectedAssignTechnicianRecord) : '',
      requestCoordinates?.latitude ?? 'no-request-location',
      requestCoordinates?.longitude ?? 'no-request-location',
      requestHasAddressAuthority ? [modalRequest.address, modalRequest.district, modalRequest.city].filter(Boolean).join('|') : '',
      modalRequest.routeFeeConfig?.fee_per_km ?? '',
      modalRequest.routeFeeConfig?.threshold_km ?? '',
      missingRouteReason ?? '',
    ].join('|')

    if (routeQuoteLastAutoKey.current === autoKey) {
      return
    }

    let cancelled = false

    if (routeQuoteLatestSelection.current.requestId !== selectedId || routeQuoteLatestSelection.current.technicianId !== assignTechnicianOption) {
      return
    }

    routeQuoteLastAutoKey.current = autoKey

    if (missingRouteReason) {
      queueMicrotask(() => {
        if (!cancelled && routeQuoteLastAutoKey.current === autoKey) {
          setRouteQuoteError(missingRouteReason)
          setRouteQuoteManualSaveError(null)
        }
      })

      return () => {
        cancelled = true
      }
    }

    const submittedRequestId = selectedId
    const submittedTechnicianId = assignTechnicianOption
    const requestSeq = ++routeQuoteAutoRequestSeq.current

    queueMicrotask(() => {
      if (cancelled) {
        return
      }

      setRouteQuoteLoading(true)
      setRouteQuoteError(null)
      setRouteQuoteManualSaveError(null)

      void apiRequest(`/api/technical-service/requests/${submittedRequestId}/technicians/${submittedTechnicianId}/route-quote`, {
        method: 'POST',
      })
      .then((response) => {
        if (
          cancelled
          || routeQuoteAutoRequestSeq.current !== requestSeq
          || routeQuoteLatestSelection.current.requestId !== submittedRequestId
          || routeQuoteLatestSelection.current.technicianId !== submittedTechnicianId
        ) {
          return
        }

        const updatedRequest = response.request ? mapApiRequest(response.request) : null

        if (!updatedRequest) {
          setRouteQuoteError('Usta yol hakedişi hesaplandı ancak talep detayı güncellenemedi.')

          return
        }

        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })

        const responseRoundTripKm = typeof response.round_trip_distance_km === 'number' && Number.isFinite(response.round_trip_distance_km)
          ? response.round_trip_distance_km
          : typeof response.distance_km === 'number' && Number.isFinite(response.distance_km)
            ? response.distance_km
            : null

        if (responseRoundTripKm !== null) {
          setTravelRoundTripKm(String(responseRoundTripKm))
        }

        const responseStatus = typeof response.status === 'string' ? response.status : null
        const routeQuoteFailed = response.ok === false || (responseStatus !== null && responseStatus !== 'calculated')

        setRouteQuoteError(routeQuoteFailed
          ? routeQuoteFailureMessage(typeof response.message === 'string' ? response.message : null)
          : null)
      })
      .catch((caught: unknown) => {
        if (!cancelled && routeQuoteAutoRequestSeq.current === requestSeq) {
          setRouteQuoteError(routeQuoteFailureMessage(caught instanceof Error ? caught.message : null))
        }
      })
        .finally(() => {
          if (!cancelled && routeQuoteAutoRequestSeq.current === requestSeq) {
            setRouteQuoteLoading(false)
          }
        })
    })

    return () => {
      cancelled = true
    }
  }, [
    assignTechnicianOption,
    assignmentRouteQuote,
    modalRequest,
    preserveDetailScroll,
    routeQuoteAutoEnabled,
    selectedAssignTechnicianRecord,
    selectedId,
  ])

  const handleRouteQuoteManualSave = async (payload: ServiceRequestRouteQuoteManualPayload) => {
    if (!selectedId) {
      return
    }

    setRouteQuoteManualSaveLoading(true)
    setRouteQuoteManualSaveError(null)
    setRouteQuoteError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/route-quote/manual`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })
      }

      const responseRoundTripKm = typeof response.round_trip_distance_km === 'number' && Number.isFinite(response.round_trip_distance_km)
        ? response.round_trip_distance_km
        : typeof response.distance_km === 'number' && Number.isFinite(response.distance_km)
          ? response.distance_km
          : null

      if (responseRoundTripKm !== null) {
        setTravelRoundTripKm(String(responseRoundTripKm))
      }

      const responseStatus = typeof response.status === 'string' ? response.status : null
      const routeQuoteFailed = response.ok === false || (responseStatus !== null && responseStatus !== 'calculated')

      setRouteQuoteError(routeQuoteFailed
        ? (typeof response.message === 'string' ? response.message : 'Usta yol hakedişi kaydedilemedi.')
        : null)
    } catch (caught) {
      setRouteQuoteManualSaveError(caught instanceof Error ? caught.message : 'Usta yol hakedişi kaydedilemedi.')

      throw caught
    } finally {
      setRouteQuoteManualSaveLoading(false)
    }
  }

  const handleExtraMountPaymentCreate = async (payload: ServiceRequestExtraMountPaymentPayload & { terminal_retry_reason?: string | null }) => {
    if (!selectedId || extraPaymentCreateInFlightRef.current) {
      return
    }

    const requestId = selectedId
    extraPaymentCreateInFlightRef.current = true
    setExtraPaymentCreateLoading(true)
    setExtraPaymentCreateError(null)

    try {
      const transportPayload: Record<string, unknown> = { ...payload }

      for (const key of ['amount', 'service_amount', 'part_amount'] as const) {
        const value = payload[key]

        if (typeof value === 'number' && Number.isFinite(value)) {
          transportPayload[key] = value.toFixed(2)
        } else if (value == null) {
          delete transportPayload[key]
        }
      }

      const response = await apiRequest(`/api/technical-service/requests/${requestId}/payments/mount-extra-payment`, {
        method: 'POST',
        body: JSON.stringify(transportPayload),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (!updatedRequest && selectedIdRef.current === requestId) {
        setExtraPaymentCreateError('Ödeme linki oluşturuldu ancak talep detayı güncellenemedi.')

        return
      }

      if (updatedRequest && selectedIdRef.current === requestId) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })
      }
    } catch (caught) {
      if (selectedIdRef.current === requestId) {
        setExtraPaymentCreateError(caught instanceof Error ? caught.message : 'Ödeme linki oluşturulamadı.')
      }

      throw caught
    } finally {
      extraPaymentCreateInFlightRef.current = false
      setExtraPaymentCreateLoading(false)
    }
  }

  const handleMountPaymentCancel = async (paymentId: number | string, payload?: { reason?: string | null }) => {
    if (!selectedId) {
      return
    }

    setExtraPaymentCreateLoading(true)
    setExtraPaymentCreateError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/payments/${paymentId}/cancel`, {
        method: 'POST',
        body: JSON.stringify(payload ?? {}),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (!updatedRequest) {
        setExtraPaymentCreateError('Ödeme linki iptal edildi ancak talep detayı güncellenemedi.')

        return
      }

      preserveDetailScroll(() => {
        setRequests((current) => current.map((request) => (
          request.id === updatedRequest.id ? updatedRequest : request
        )))
        setSelectedListRequest((current) => (
          current?.id === updatedRequest.id ? updatedRequest : current
        ))
        setSelectedDetailRequest(updatedRequest)
      })
    } catch (caught) {
      setExtraPaymentCreateError(caught instanceof Error ? caught.message : 'Ödeme linki iptal edilemedi.')

      throw caught
    } finally {
      setExtraPaymentCreateLoading(false)
    }
  }

  const handleMountPaymentSync = async (paymentId: number | string) => {
    if (!selectedId) {
      return
    }

    setExtraPaymentCreateError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/payments/${paymentId}/status?sync_provider=1`)
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (!updatedRequest) {
        setExtraPaymentCreateError('Ödeme durumu kontrol edildi ancak talep detayı güncellenemedi.')

        return
      }

      preserveDetailScroll(() => {
        setRequests((current) => current.map((request) => (
          request.id === updatedRequest.id ? updatedRequest : request
        )))
        setSelectedListRequest((current) => (
          current?.id === updatedRequest.id ? updatedRequest : current
        ))
        setSelectedDetailRequest(updatedRequest)
      })
    } catch (caught) {
      setExtraPaymentCreateError(caught instanceof Error ? caught.message : 'Ödeme durumu kontrol edilemedi.')

      throw caught
    }
  }

  const handleMountPaymentSendContext = async (paymentId: number | string): Promise<PaymentLinkSendContext> => {
    if (!selectedId) {
      throw new Error('Gönderilecek ödeme bağlantısı belirlenemedi. Lütfen aktif ödeme kaydını seçin.')
    }

    const response = await apiRequest(`/api/technical-service/requests/${selectedId}/payments/${paymentId}/status`)

    if (!response.payment) {
      throw new Error('Gönderilecek ödeme bağlantısı belirlenemedi. Lütfen aktif ödeme kaydını seçin.')
    }

    return response.payment as PaymentLinkSendContext
  }

  const handleMountPaymentSend = async (paymentId: number | string, payload: PaymentLinkSendPayload): Promise<PaymentLinkSendResult> => {
    if (!selectedId) {
      throw new Error('Gönderilecek ödeme bağlantısı belirlenemedi. Lütfen aktif ödeme kaydını seçin.')
    }

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/payments/${paymentId}/send-link`, {
        method: 'POST',
        body: JSON.stringify(payload ?? {}),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (!updatedRequest) {
        setExtraPaymentCreateError('Ödeme linki kuyruğa alındı ancak talep detayı güncellenemedi.')
      } else {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })
      }

      return {
        message: response.message ?? null,
        payment: response.payment ?? null,
        idempotent_replay: response.idempotent_replay === true,
      }
    } catch (caught) {
      setExtraPaymentCreateError(caught instanceof Error ? caught.message : 'Ödeme linki mesaj kuyruğuna alınamadı.')

      throw caught
    }
  }

  const handlePartRequestManualPaymentConfirm = async (partRequestId: number | string, payload: { explanation: string }) => {
    if (!selectedId) {
      return
    }

    const requestId = selectedId
    setExtraPaymentCreateLoading(true)
    setExtraPaymentCreateError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${requestId}/part-requests/${partRequestId}/manual-payment`, {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (!updatedRequest || selectedIdRef.current !== requestId) {
        throw new Error('Manuel tahsilat kaydedildi ancak talep detayı güncellenemedi.')
      }

      preserveDetailScroll(() => {
        setRequests((current) => current.map((request) => (
          request.id === updatedRequest.id ? updatedRequest : request
        )))
        setSelectedListRequest((current) => (
          current?.id === updatedRequest.id ? updatedRequest : current
        ))
        setSelectedDetailRequest(updatedRequest)
      })
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : 'Manuel tahsilat kaydedilemedi.'
      setExtraPaymentCreateError(message)

      throw caught
    } finally {
      setExtraPaymentCreateLoading(false)
    }
  }

  const handleTechnicianEarningMessageCreate = async (payload: ServiceRequestTechnicianEarningMessagePayload) => {
    if (!selectedId) {
      return undefined
    }

    setTechnicianEarningMessageLoading(true)
    setTechnicianEarningMessageError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/technicians/${payload.technician_id}/earnings-message`, {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (!updatedRequest) {
        setTechnicianEarningMessageError('Hakediş bilgisi hazırlandı ancak talep detayı güncellenemedi.')

        return response
      }

      preserveDetailScroll(() => {
        setRequests((current) => current.map((request) => (
          request.id === updatedRequest.id ? updatedRequest : request
        )))
        setSelectedListRequest((current) => (
          current?.id === updatedRequest.id ? updatedRequest : current
        ))
        setSelectedDetailRequest(updatedRequest)
      })

      return response
    } catch (caught) {
      setTechnicianEarningMessageError(caught instanceof Error ? caught.message : 'Hakediş bilgisi gönderilemedi.')

      throw caught
    } finally {
      setTechnicianEarningMessageLoading(false)
    }
  }

  const handlePartnerAppointmentProposalApprove = async (actionId: number | string, payload?: { note?: string | null, selected_slot_index?: number }) => {
    const actionKey = String(actionId)

    if (!selectedId || appointmentApprovalInFlightRef.current !== null) {
      return
    }

    const requestId = selectedId
    appointmentApprovalInFlightRef.current = actionKey
    setAppointmentApprovalInFlight(actionKey)
    setAppointmentApprovalError(null)
    setAppointmentApprovalSuccess(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${requestId}/partner-appointment-proposals/${actionId}/approve`, {
        method: 'POST',
        body: JSON.stringify(payload ?? {}),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest && selectedIdRef.current === requestId) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })
      }

      if (selectedIdRef.current === requestId) {
        setAppointmentApprovalSuccess(response.status === 'duplicate_noop'
          ? 'Randevu daha önce onaylanmış; ikinci işlem oluşturulmadı.'
          : 'Randevu onaylandı; müşteri ve usta bildirimleri kuyruğa alındı.')
      }

    } catch (caught) {
      if (selectedIdRef.current === requestId) {
        setAppointmentApprovalError(caught instanceof Error ? caught.message : 'Randevu onaylanamadı.')
      }
    } finally {
      appointmentApprovalInFlightRef.current = null
      setAppointmentApprovalInFlight(null)
    }
  }

  const handlePartnerAppointmentProposalReject = async (actionId: number | string, payload: { note: string, status?: string }) => {
    if (!selectedId) {
      return
    }

    const response = await apiRequest(`/api/technical-service/requests/${selectedId}/partner-appointment-proposals/${actionId}/reject`, {
      method: 'POST',
      body: JSON.stringify(payload),
    })
    const updatedRequest = response.request ? mapApiRequest(response.request) : null

    if (updatedRequest) {
      preserveDetailScroll(() => {
        setRequests((current) => current.map((request) => (
          request.id === updatedRequest.id ? updatedRequest : request
        )))
        setSelectedListRequest((current) => (
          current?.id === updatedRequest.id ? updatedRequest : current
        ))
        setSelectedDetailRequest(updatedRequest)
      })
    }
  }

  const handlePartnerCompletionApprove = async (actionId: number | string, payload?: { note?: string | null, approved_visit_ids?: Array<number | string> }) => {
    if (!selectedId) {
      return
    }

    const response = await apiRequest(`/api/technical-service/requests/${selectedId}/partner-completions/${actionId}/approve`, {
      method: 'POST',
      body: JSON.stringify(payload ?? {}),
    })
    const updatedRequest = response.request ? mapApiRequest(response.request) : null

    if (updatedRequest) {
      preserveDetailScroll(() => {
        setRequests((current) => current.map((request) => (
          request.id === updatedRequest.id ? updatedRequest : request
        )))
        setSelectedListRequest((current) => (
          current?.id === updatedRequest.id ? updatedRequest : current
        ))
        setSelectedDetailRequest(updatedRequest)
      })
      await loadRequests({ silent: true, preserveSelection: true })
    }
  }

  const handlePartRequestTransition = async (
    partRequestId: number | string,
    payload: { status: string, note?: string | null, partner_message?: string | null, shipment_provider?: string | null, tracking_no?: string | null, charge_decision?: string | null, service_amount?: number | null, part_amount?: number | null, customer_message?: string | null },
  ) => {
    if (!selectedId) {
      return
    }

    const response = await apiRequest(`/api/technical-service/requests/${selectedId}/part-requests/${partRequestId}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    })
    const updatedRequest = response.request ? mapApiRequest(response.request) : null

    if (updatedRequest) {
      preserveDetailScroll(() => {
        setRequests((current) => current.map((request) => (
          request.id === updatedRequest.id ? updatedRequest : request
        )))
        setSelectedListRequest((current) => (
          current?.id === updatedRequest.id ? updatedRequest : current
        ))
        setSelectedDetailRequest(updatedRequest)
      })
      await loadRequests({ silent: true, preserveSelection: true })
    }
  }

  const handlePartRequestCreate = async (
    payload: { part_name: string, part_code?: string | null, quantity?: number | null, charge_decision: 'free' | 'chargeable', service_amount?: number | null, part_amount?: number | null, note?: string | null, partner_message?: string | null, customer_message?: string | null },
  ) => {
    if (!selectedId) {
      return
    }

    const response = await apiRequest(`/api/technical-service/requests/${selectedId}/part-requests`, {
      method: 'POST',
      body: JSON.stringify(payload),
    })
    const updatedRequest = response.request ? mapApiRequest(response.request) : null

    if (updatedRequest) {
      preserveDetailScroll(() => {
        setRequests((current) => current.map((request) => (
          request.id === updatedRequest.id ? updatedRequest : request
        )))
        setSelectedListRequest((current) => (
          current?.id === updatedRequest.id ? updatedRequest : current
        ))
        setSelectedDetailRequest(updatedRequest)
        setSelectedEvents(Array.isArray(response.request?.events) ? response.request.events : [])
      })
      await loadRequests({ silent: true, preserveSelection: true })
    }
  }

  const handlePartRequestServiceVisitCreate = async (
    partRequestId: number | string,
    payload?: { reason?: string | null },
  ) => {
    if (!selectedId) {
      return
    }

    const response = await apiRequest(`/api/technical-service/requests/${selectedId}/part-requests/${partRequestId}/service-visit`, {
      method: 'POST',
      body: JSON.stringify(payload ?? {}),
    })
    const updatedRequest = response.request ? mapApiRequest(response.request) : null
    const childRequest = response.child_request ? mapApiRequest(response.child_request) : null

    preserveDetailScroll(() => {
      setRequests((current) => {
        const parentId = updatedRequest?.id ?? selectedId
        const withoutParent = parentId
          ? current.filter((request) => request.id !== parentId)
          : current
        const withoutChild = childRequest
          ? withoutParent.filter((request) => request.id !== childRequest.id)
          : withoutParent

        return childRequest
          ? [childRequest, ...withoutChild]
          : withoutParent
      })

      if (childRequest) {
        selectedIdRef.current = childRequest.id
        setSelectedId(childRequest.id)
        setSelectedListRequest(childRequest)
        setSelectedDetailRequest(childRequest)
        setSelectedEvents(Array.isArray(response.child_request?.events) ? response.child_request.events : [])
      } else if (updatedRequest) {
        setSelectedListRequest(null)
        setSelectedDetailRequest(null)
        setSelectedEvents([])
      }
    })
    await loadRequests({ silent: true, preserveSelection: true })
  }

  const handleRevisitServiceVisitCreate = async (
    actionId: number | string,
    payload?: { note?: string | null },
  ) => {
    if (!selectedId) {
      return
    }

    const response = await apiRequest(`/api/technical-service/requests/${selectedId}/partner-revisits/${actionId}/service-visit`, {
      method: 'POST',
      body: JSON.stringify(payload ?? {}),
    })
    const updatedRequest = response.request ? mapApiRequest(response.request) : null
    const childRequest = response.child_request ? mapApiRequest(response.child_request) : null

    preserveDetailScroll(() => {
      setRequests((current) => {
        const parentId = updatedRequest?.id ?? selectedId
        const withoutParent = parentId
          ? current.filter((request) => request.id !== parentId)
          : current
        const withoutChild = childRequest
          ? withoutParent.filter((request) => request.id !== childRequest.id)
          : withoutParent

        return childRequest
          ? [childRequest, ...withoutChild]
          : withoutParent
      })

      if (childRequest) {
        selectedIdRef.current = childRequest.id
        setSelectedId(childRequest.id)
        setSelectedListRequest(childRequest)
        setSelectedDetailRequest(childRequest)
        setSelectedEvents(Array.isArray(response.child_request?.events) ? response.child_request.events : [])
      } else if (updatedRequest) {
        setSelectedListRequest(null)
        setSelectedDetailRequest(null)
        setSelectedEvents([])
      }
    })
    await loadRequests({ silent: true, preserveSelection: true })
  }

  const handleCustomerApprovalResend = async (payload?: { note?: string | null }) => {
    if (!selectedId) {
      return
    }

    setCustomerApprovalResendLoading(true)
    setCustomerApprovalResendError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/customer-approval-requests`, {
        method: 'POST',
        body: JSON.stringify(payload ?? {}),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
          setSelectedEvents(Array.isArray(response.request?.events) ? response.request.events : [])
        })
        await loadRequests({ silent: true, preserveSelection: true })
      } else {
        await loadRequestDetail(selectedId)
      }

      if (response.dispatch?.dispatch_status && response.dispatch.dispatch_status !== 'sent') {
        setCustomerApprovalResendError(response.message ?? 'Müşteri onay mesajı gönderilemedi.')
      }
    } catch (caught) {
      setCustomerApprovalResendError(caught instanceof Error ? caught.message : 'Müşteri onayı tekrar gönderilemedi.')
    } finally {
      setCustomerApprovalResendLoading(false)
    }
  }

  const handleFieldDocumentReview = async (uploadId: number | string, payload: { status: 'accepted' | 'rejected', note?: string | null }) => {
    if (!selectedId) {
      return
    }

    const uploadKey = String(uploadId)
    setFieldDocumentReviewLoading(uploadKey)
    setFieldDocumentReviewError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/field-documents/${uploadId}/review`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
          setSelectedEvents(Array.isArray(response.request?.events) ? response.request.events : [])
        })
        await loadRequests({ silent: true, preserveSelection: true })
      } else {
        await loadRequestDetail(selectedId)
      }
    } catch (caught) {
      setFieldDocumentReviewError(caught instanceof Error ? caught.message : 'Saha belgesi uygunluğu kaydedilemedi.')
    } finally {
      setFieldDocumentReviewLoading(null)
    }
  }

  const handleOpsExtraDocumentUpload = async (payload: { files: File[], note?: string | null, document_type?: string | null }) => {
    if (!selectedId) {
      return
    }

    const formData = new FormData()
    payload.files.forEach((file) => formData.append('ops_extra_documents[]', file))

    if (payload.note) {
      formData.append('note', payload.note)
    }

    if (payload.document_type) {
      formData.append('document_type', payload.document_type)
    }

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    const response = await fetch(`/api/technical-service/requests/${selectedId}/ops-extra-documents`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
      },
      body: formData,
    })

    if (!response.ok) {
      const detail = await response.text()
      let message = 'OPS ek görsel yüklenemedi.'

      try {
        const parsed = JSON.parse(detail) as { message?: string, error?: string }
        message = parsed.message || parsed.error || message
      } catch {
        // Keep proxy or HTML errors out of the panel message.
      }

      const error = new Error(message) as Error & { status?: number, detail?: string }
      error.status = response.status
      error.detail = detail

      throw error
    }

    const responsePayload = await response.json()
    const updatedRequest = responsePayload.request ? mapApiRequest(responsePayload.request) : null

    if (updatedRequest) {
      preserveDetailScroll(() => {
        setRequests((current) => current.map((request) => (
          request.id === updatedRequest.id ? updatedRequest : request
        )))
        setSelectedListRequest((current) => (
          current?.id === updatedRequest.id ? updatedRequest : current
        ))
        setSelectedDetailRequest(updatedRequest)
        setSelectedEvents(Array.isArray(responsePayload.request?.events) ? responsePayload.request.events : [])
      })
      await loadRequests({ silent: true, preserveSelection: true })
    } else {
      await loadRequestDetail(selectedId)
    }
  }

  const handleAssignmentOfferUpdate = async (offerId: number | string, payload: { labor_amount: number, route_fee_amount: number, total_amount?: number, note?: string | null, company_payment_decisions?: ServiceRequestCompanyPaymentDecisionSubmit[] }) => {
    if (!selectedId || assignmentOfferUpdateInFlightRef.current) {
      return
    }

    const requestId = selectedId
    assignmentOfferUpdateInFlightRef.current = true
    setAssignmentOfferUpdateInFlight(true)
    setAssignmentOfferUpdateError(null)
    setAssignmentOfferUpdateSuccess(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${requestId}/assignment-offers/${offerId}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest && selectedIdRef.current === requestId) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })
      }

      if (selectedIdRef.current === requestId) {
        setAssignmentOfferUpdateSuccess(response.status === 'duplicate_noop'
          ? 'Hakediş zaten güncel; ikinci kayıt oluşturulmadı.'
          : 'Hakediş ve mutabakat toplamları güncellendi.')
      }

      return response
    } catch (caught) {
      if (selectedIdRef.current === requestId) {
        setAssignmentOfferUpdateError(caught instanceof Error ? caught.message : 'Hakediş güncellenemedi.')
      }
    } finally {
      assignmentOfferUpdateInFlightRef.current = false
      setAssignmentOfferUpdateInFlight(false)
    }
  }

  const handleCompanyPaymentDecisionApprove = async (companyPaymentDecisions: ServiceRequestCompanyPaymentDecisionSubmit[]) => {
    if (!selectedId || assignmentOfferUpdateInFlightRef.current) {
      return
    }

    const requestId = selectedId
    assignmentOfferUpdateInFlightRef.current = true
    setAssignmentOfferUpdateInFlight(true)
    setAssignmentOfferUpdateError(null)
    setAssignmentOfferUpdateSuccess(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${requestId}/company-payment-decisions`, {
        method: 'POST',
        body: JSON.stringify({ company_payment_decisions: companyPaymentDecisions }),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest && selectedIdRef.current === requestId) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
          setSelectedEvents(Array.isArray(response.request?.events) ? response.request.events : [])
        })
      }

      if (selectedIdRef.current === requestId) {
        setAssignmentOfferUpdateSuccess(response.status === 'duplicate_noop'
          ? 'Dağıtım kararı zaten kayıtlı; ikinci kayıt oluşturulmadı.'
          : 'Dağıtım kararı kaydedildi.')
      }

      return updatedRequest ? {
        ...response,
        request: updatedRequest,
        earning_snapshot: updatedRequest.assignmentOffer?.earning_snapshot ?? null,
        message_preview: updatedRequest.assignmentOffer?.message_preview ?? null,
      } : response
    } catch (caught) {
      const error = caught instanceof Error ? caught : new Error('Dağıtım kararı kaydedilemedi.')

      if (selectedIdRef.current === requestId) {
        setAssignmentOfferUpdateError(error.message)
      }

      throw error
    } finally {
      assignmentOfferUpdateInFlightRef.current = false
      setAssignmentOfferUpdateInFlight(false)
    }
  }

  const handlePartnerActionReview = async (actionId: number | string, payload: { decision: 'reviewed' | 'resolved' | 'more_info' | 'rejected' | 'revision_requested', note?: string | null }) => {
    if (!selectedId) {
      return
    }

    setPartnerActionReviewLoading(String(actionId))
    setPartnerActionReviewError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/partner-actions/${actionId}/review`, {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      if (updatedRequest) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((item) => item.id === updatedRequest.id ? updatedRequest : item))
          setSelectedListRequest((current) => current?.id === updatedRequest.id ? updatedRequest : current)
          setSelectedDetailRequest(updatedRequest)
          setSelectedEvents(Array.isArray(response.request?.events) ? response.request.events : [])
        })
      }
    } catch (error) {
      setPartnerActionReviewError(error instanceof Error ? error.message : 'OPS kararı kaydedilemedi.')
    } finally {
      setPartnerActionReviewLoading(null)
    }
  }

  const handleAssignmentFinalConfirmOpen = () => {
    const isManualTechnician = assignTechnicianOption === 'other'
    const selectedTechnicianRecord = technicians.find((technician) => technician.id === assignTechnicianOption)
    const selectedTechnician = isManualTechnician
      ? assignOtherTechnician.trim()
      : selectedTechnicianRecord ? technicianDisplayName(selectedTechnicianRecord) : ''

    if (!selectedTechnician) {
      setAssignError('Lütfen bir usta seçin veya manuel isim girin.')

      return
    }

    if (!isManualTechnician && selectedAssignPartnerLinks.length > 0 && !selectedAssignTechnicianPartnerId) {
      setAssignError('Ustanın iş kartı için partner kapsamını açıkça seçin.')

      return
    }

    if (assignmentBlockerMessages.length > 0) {
      setAssignError(assignmentBlockerMessages.join(' '))

      return
    }

    if (paymentNeededNoDecision) {
      setAssignError('Ödeme yöntemi netleşmeden atama güncellenemez. Ödeme linki oluşturun veya müşterinin ustaya ödeyeceği tutarı belirleyin.')

      return
    }

    if (assignmentDirectAmountError) {
      setAssignError(assignmentDirectAmountError)

      return
    }

    if (!isManualTechnician && !assignmentRouteQuote) {
      setAssignError('Yol hakedişi henüz hesaplanmadı. Otomatik hesaplamayı tamamlayın veya açık neden ile manuel yol hakedişi kaydedin.')

      return
    }

    const parsedTravelRoundTripKm = typeof assignmentRouteRoundTripKm === 'number'
      ? assignmentRouteRoundTripKm
      : travelRoundTripKm.trim() === '' ? Number.NaN : Number(travelRoundTripKm)

    if (!Number.isFinite(parsedTravelRoundTripKm) || parsedTravelRoundTripKm < 0) {
      setAssignError('Lütfen gidiş-geliş km bilgisini girin.')

      return
    }

    setAssignError(null)
    setAssignmentConfirmDialogOpen(true)
  }

  const handleAssignSubmit = async () => {
    if (!selectedId || assignMutationInFlightRef.current) {
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

    if (!isManualTechnician && selectedAssignPartnerLinks.length > 0 && !selectedAssignTechnicianPartnerId) {
      setAssignError('Ustanın iş kartı için partner kapsamını açıkça seçin.')

      return
    }

    if (assignmentBlockerMessages.length > 0) {
      setAssignError(assignmentBlockerMessages.join(' '))

      return
    }

    if (paymentNeededNoDecision) {
      setAssignError('Ödeme yöntemi netleşmeden atama güncellenemez. Ödeme linki oluşturun veya müşterinin ustaya ödeyeceği tutarı belirleyin.')

      return
    }

    if (assignmentDirectAmountError) {
      setAssignError(assignmentDirectAmountError)

      return
    }

    if (!isManualTechnician && !assignmentRouteQuote) {
      setAssignError('Yol hakedişi henüz hesaplanmadı. Otomatik hesaplamayı tamamlayın veya açık neden ile manuel yol hakedişi kaydedin.')

      return
    }

    const parsedTravelRoundTripKm = typeof assignmentRouteRoundTripKm === 'number'
      ? assignmentRouteRoundTripKm
      : travelRoundTripKm.trim() === '' ? Number.NaN : Number(travelRoundTripKm)
    const submittedTechnicianOption = assignTechnicianOption
    const submittedTravelRoundTripKm = String(parsedTravelRoundTripKm)

    if (!Number.isFinite(parsedTravelRoundTripKm) || parsedTravelRoundTripKm < 0) {
      setAssignError('Lütfen gidiş-geliş km bilgisini girin.')

      return
    }

    const requestId = selectedId
    assignMutationInFlightRef.current = true
    setAssignLoading(true)
    setAssignError(null)
    setAssignSuccess(null)

    try {
      const offerLaborAmount = parseNullableNumber(assignOfferLaborAmount) ?? assignmentTechnicianLaborAmount ?? 0
      const offerRouteFeeAmount = parseNullableNumber(assignOfferRouteFeeAmount) ?? assignmentRouteFeeAmount ?? 0
      const offerTotalAmount = Math.round((offerLaborAmount + offerRouteFeeAmount) * 100) / 100
      const customerDirectAmount = customerDirectPaymentDisabled
        ? 0
        : roundTwo(parseNullableNumber(assignCustomerDirectAmount) ?? offerTotalAmount)
      const response = await apiRequest(`/api/technical-service/requests/${requestId}/assign`, {
        method: 'POST',
        body: JSON.stringify({
          ...(isManualTechnician
            ? { technician_name: selectedTechnician }
            : {
                technical_service_technician_id: assignTechnicianOption,
                b2b_partner_id: selectedAssignTechnicianPartnerId ? Number(selectedAssignTechnicianPartnerId) : null,
              }),
          route_quote_id: assignmentRouteQuote?.id ?? null,
          travel_round_trip_km: parsedTravelRoundTripKm,
          mount_payment_missing: paymentNeededNoDecision,
          override_without_payment: false,
          override_reason: null,
          mount_exclusion_acknowledged: legacyMountExclusionAckTouched ? assignOverrideWithoutPayment : false,
          mount_exclusion_note: legacyMountExclusionAckTouched ? assignOverrideReason.trim() || null : null,
          labor_amount: offerLaborAmount,
          travel_amount: offerRouteFeeAmount,
          customer_direct_to_technician_amount: customerDirectAmount,
          earning_note: assignOfferNote.trim() || assignNote || null,
          confirm_assignment: true,
          assignment_offer: {
            labor_amount: offerLaborAmount,
            route_fee_amount: offerRouteFeeAmount,
            total_amount: offerTotalAmount,
            customer_direct_to_technician_amount: customerDirectAmount,
            currency: 'TRY',
            note: assignOfferNote.trim() || assignNote || null,
          },
          note: assignNote || null,
        }),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      setAssignmentConfirmDialogOpen(false)
      setAssignTechnicianOption(submittedTechnicianOption)
      setTravelRoundTripKm(submittedTravelRoundTripKm)

      if (updatedRequest && selectedIdRef.current === requestId) {
        preserveDetailScroll(() => {
          setRequests((current) => current.map((request) => (
            request.id === updatedRequest.id ? updatedRequest : request
          )))
          setSelectedListRequest((current) => (
            current?.id === updatedRequest.id ? updatedRequest : current
          ))
          setSelectedDetailRequest(updatedRequest)
        })
        setAssignSuccess('Usta atandı; hakediş, iş kartı ve bildirim kaydı hazırlandı.')
      } else if (selectedIdRef.current === requestId) {
        await loadRequestDetail(requestId)
        setAssignSuccess('Usta atandı; talep detayı yenilendi.')
      }

      void Promise.allSettled([
        loadRequests({ silent: true, preserveSelection: true }),
        loadSummary(),
      ])
    } catch (caught) {
      if (selectedIdRef.current === requestId) {
        setAssignError(caught instanceof Error ? caught.message : 'Usta atama işlemi başarısız oldu.')
      }
    } finally {
      assignMutationInFlightRef.current = false
      setAssignLoading(false)
    }
  }

  const handleCompleteSubmit = async () => {
    if (!selectedId) {
      return
    }

    if (!completionReason) {
      setCompleteError('Tamamlama türü belirlenemedi.')

      return
    }

    const notes = completionOtherNote.trim() || completionReason
    const isCompletingInstallation = modalRequest?.serviceType === 'Montaj'

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
          status: 'Tamamlandı',
          resolution_notes: notes || null,
          note: completionOtherNote.trim() || notes || null,
          ...(isCompletingInstallation
            ? {
                installation_completed_at: installationCompletedAt,
                installation_completion_note: installationCompletionNote || null,
                note: installationCompletionNote || completionOtherNote.trim() || notes || null,
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

  const handleRequestCancellationSubmit = async () => {
    if (!selectedId) {
      return
    }

    const reason = requestCancellationReason === 'Diğer'
      ? requestCancellationNote.trim()
      : requestCancellationNote.trim()
        ? `${requestCancellationReason}: ${requestCancellationNote.trim()}`
        : requestCancellationReason

    if (!reason) {
      setRequestCancellationError('İptal nedeni zorunludur.')

      return
    }

    setRequestCancellationLoading(true)
    setRequestCancellationError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${selectedId}/status`, {
        method: 'POST',
        body: JSON.stringify({ status: 'İptal', note: reason }),
      })
      const updatedRequest = response.request ? mapApiRequest(response.request) : null

      setRequestCancellationDialogOpen(false)
      setRequestCancellationReason('')
      setRequestCancellationNote('')

      if (updatedRequest) {
        setRequests((current) => current.map((item) => item.id === updatedRequest.id ? updatedRequest : item))
        setSelectedListRequest(updatedRequest)
        setSelectedDetailRequest(updatedRequest)
      }

      await loadRequests({ silent: true, preserveSelection: true })
      await loadSummary()
      await loadRequestDetail(updatedRequest?.id ?? selectedId)
    } catch (caught) {
      setRequestCancellationError(caught instanceof Error ? caught.message : 'Talep iptal edilemedi.')
    } finally {
      setRequestCancellationLoading(false)
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
    setPlanSummaryFilter(null)
    setSelectedPlanDayKey((current) => current === key ? null : key)
  }, [])

  const handleSelectSummaryFilter = useCallback((filterId: string) => {
    const nextFilter = filterId as PlanSummaryFilter

    setSelectedPlanDayKey(null)
    setPlanSummaryFilter((current) => current === nextFilter ? null : nextFilter)
  }, [])

  const markRequestAsRead = useCallback((requestId: string) => {
    setReadRequestIds((current) => {
      if (current.has(requestId)) {
        return current
      }

      const next = new Set(current)
      next.add(requestId)
      writeStoredRequestIds(next)

      return next
    })
  }, [])

  const openRequestDetail = useCallback((request: ServiceRequest) => {
    markRequestAsRead(request.id)
    detailRequestTokenRef.current += 1
    setSelectedListRequest(request)
    setSelectedDetailRequest(null)
    setSelectedEvents([])
    setDetailError(null)
    setFinancialWorkspaceError(null)
    setFinancialWorkspaceLoading(true)
    setMikroMountCheck(null)
    setMikroMountError(null)
    setMikroMountLoading(false)
    setWarranty(null)
    setWarrantyError(null)
    setWarrantyLoading(false)
    setPriorityUpdateError(null)
    setPriorityUpdateLoading(false)
    setAssignTechnicianOption(request.technicianId ?? '')
    setRouteQuoteAutoEnabled(false)
    setTravelRoundTripKm('')
    setRouteQuoteError(null)
    setRouteQuoteManualSaveError(null)
    setExtraPaymentCreateError(null)
    setTechnicianEarningMessageError(null)
    setAssignSuccess(null)
    setAppointmentApprovalError(null)
    setAppointmentApprovalSuccess(null)
    setAssignmentOfferUpdateError(null)
    setAssignmentOfferUpdateSuccess(null)
    setShowNearbyTechnicians(false)
    setSelectedId(request.id)
    setIsDetailDialogOpen(true)
  }, [markRequestAsRead])

  return (
    <>
      <Head title="Teknik Servis Operasyon Merkezi" />

      <div className="relative min-h-screen w-full overflow-x-hidden bg-[#F3F7FB]">
        <div className="w-full max-w-none space-y-6 px-3 py-5 sm:px-4 md:px-5 xl:px-6 2xl:px-8">
        <section className="rounded-[32px] border border-white bg-white px-5 py-5 shadow-[0_18px_50px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 sm:px-6 sm:py-6 xl:px-7">
          <div className="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
            <div className="flex items-start gap-4">
              <div className="inline-flex h-16 w-16 shrink-0 items-center justify-center rounded-[24px] bg-[#06143A] text-white shadow-[0_16px_30px_rgba(6,20,58,0.22)]">
                <Wrench className="h-7 w-7" />
              </div>
              <div className="max-w-3xl">
                <h1 className="text-3xl font-semibold text-slate-950 md:text-4xl">Teknik Servis</h1>
                <p className="mt-2 max-w-2xl text-base leading-7 text-slate-600">
                  Montaj ve servis taleplerini aşama bazlı takip edin.
                </p>
              </div>
            </div>

            <div className="flex flex-col gap-3 xl:items-end">
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:min-w-[520px]">
                {[
                  { label: 'Açık İş', value: String(kanbanSummary.open) },
                  { label: 'Atanmış', value: String(kanbanSummary.assigned) },
                  { label: 'Geciken', value: String(kanbanSummary.overdue) },
                  { label: 'Toplam', value: String(kanbanSummary.total) },
                ].map((item) => (
                  <div key={item.label} className="rounded-[22px] border border-slate-200/80 bg-[#F8FAFD] px-4 py-3 shadow-[inset_0_1px_0_rgba(255,255,255,0.9)]">
                    <p className="text-[11px] font-semibold uppercase text-slate-500">{item.label}</p>
                    <p className="mt-2 text-3xl font-semibold text-[#06143A]">{item.value}</p>
                  </div>
                ))}
              </div>

              <div
                data-testid="global-execution-control-readonly"
                className="w-full border-l-4 border-[#06143A] bg-[#F8FAFD] px-4 py-3"
              >
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <ShieldCheck className="h-4 w-4 text-[#06143A]" />
                    <p className="text-sm font-semibold text-slate-950">Sistem: {executionControlLoading ? 'YÜKLENİYOR' : (executionControl?.state ?? 'local').toLocaleUpperCase('tr-TR')}</p>
                    <span
                      className={[
                        'inline-flex min-w-16 items-center justify-center rounded-full border px-2.5 py-1 text-xs font-semibold',
                        executionControl?.mode === 'live'
                          ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
                          : 'border-slate-300 bg-white text-slate-700',
                      ].join(' ')}
                    >
                      Salt okunur
                    </span>
                  </div>
                  <p className="mt-1 text-xs leading-5 text-slate-600">
                    {executionControl?.runtime_environment_label ?? 'Runtime doğrulanıyor'}
                    {' · '}
                    Epoch {executionControl?.epoch ?? '—'} / Rev {executionControl?.revision ?? '—'}
                    {' · '}
                    {executionControl?.readiness.required_ready_count ?? 0}/{executionControl?.readiness.required_count ?? 0} required hazır
                    {' · '}
                    Yönetim Paneli’nde yönetilir
                  </p>
                  {executionControlMessage ? (
                    <p className="mt-1 flex items-start gap-1.5 text-xs font-medium text-amber-800" role="status">
                      <TriangleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                      <span>{executionControlMessage}</span>
                    </p>
                  ) : null}
                </div>
              </div>

              <div className="flex flex-wrap items-center gap-2 xl:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  className="h-11 rounded-[18px] border-slate-200 bg-white px-4 font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                  onClick={() => {
                    void loadRequests()
                    void loadSummary()
                    void loadExecutionControl()
                                      }}
                >
                  <RefreshCw className="mr-2 h-4 w-4" />
                  Yenile
                </Button>

              <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
              <DialogTrigger asChild>
                <Button type="button" className="h-11 rounded-[18px] bg-[#06143A] px-5 font-semibold text-white shadow-[0_14px_26px_rgba(6,20,58,0.2)] hover:bg-[#0b1d51]">
                  <Plus className="mr-2 h-4 w-4" />
                  Yeni Talep
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
        </div>
      </section>

      <TechnicalServicePageLinks />

      <TechnicalServiceWeekNavigator
        weekLabel={selectedWeekLabel}
        selectedDateButtonLabel={selectedDateButtonLabel}
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
        onSelectSummaryItem={handleSelectSummaryFilter}
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
        <section className="rounded-[22px] border border-blue-200 bg-blue-50/80 px-5 py-3 shadow-sm sm:px-6">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm font-medium text-blue-900">
              Tarih filtresi: {activePlanDayLabel}
            </p>
            <button
              type="button"
              onClick={() => setSelectedPlanDayKey(null)}
              className="inline-flex items-center rounded-full border border-blue-200 bg-white px-3 py-1.5 text-sm font-semibold text-blue-700 transition hover:bg-blue-100"
            >
              Kaldır
            </button>
          </div>
        </section>
      ) : null}

      <section className="rounded-[28px] border border-white bg-white px-4 py-4 shadow-[0_14px_36px_rgba(15,23,42,0.06)] ring-1 ring-slate-200/70 sm:px-5">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-end">
          <label className="grid min-w-0 flex-1 gap-2 text-sm font-semibold text-slate-700">
            Arama
            <div className="relative">
              <Search className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <Input
              value={filters.search}
              onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
              placeholder="MRN, müşteri, ürün/model, seri no, teknisyen"
                className="h-12 rounded-[18px] border-slate-200 bg-[#F8FAFD] pl-10 text-slate-900 shadow-inner shadow-slate-200/40 placeholder:text-slate-400"
              />
            </div>
          </label>

          <div className="flex flex-col gap-2 sm:flex-row lg:pb-0">
          <button
            type="button"
            onClick={() => setFilters((current) => ({ ...current, onlyOpen: !current.onlyOpen }))}
            className={[
              'inline-flex h-11 items-center justify-center rounded-[16px] border px-4 text-sm font-semibold transition',
              filters.onlyOpen
                ? 'border-[#06143A] bg-[#06143A] text-white shadow-[0_10px_20px_rgba(6,20,58,0.16)]'
                : 'border-slate-200 bg-[#F8FAFD] text-slate-700 hover:border-slate-300 hover:bg-white',
            ].join(' ')}
          >
            Sadece açık işler
          </button>
          <button
            type="button"
            onClick={() => {
              setFilters(getTechnicalServiceInitialFilters())
              setSelectedPlanDayKey(null)
              setPlanSummaryFilter(null)
            }}
            className="inline-flex h-11 items-center justify-center rounded-[16px] border border-slate-200 bg-[#F8FAFD] px-4 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-white"
          >
            Filtreleri temizle
          </button>
          </div>
        </div>
      </section>

          <Dialog open={assignDialogOpen} onOpenChange={handleAssignDialogOpenChange}>
            <DialogContent className="!w-[88vw] max-w-5xl max-h-[92vh] overflow-y-auto rounded-[28px]">
              <DialogClose asChild>
                <button
                  type="button"
                  className="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                >
                  ×
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Usta / Çilingir Atama</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için ${modalRequest?.customer} adına usta atayın.` : 'Seçili talep yok.'}
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
              {assignSuccess ? (
                <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-800">
                  {assignSuccess}
                </div>
              ) : null}

              {assignmentBlockerMessages.length > 0 ? (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                  <p className="font-semibold">Usta ataması için operasyon kontrolü tamamlanmalı.</p>
                  <ul className="mt-2 list-disc space-y-1 pl-5">
                    {assignmentBlockerMessages.map((message) => (
                      <li key={message}>{message}</li>
                    ))}
                  </ul>
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                <div className="grid gap-3">
                  <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Kesin Randevu</p>
                    <p className="mt-2 font-semibold text-slate-900">{assignmentScheduleSupport.scheduledLabel}</p>
                    <p className="mt-1 text-xs text-slate-500">Usta ataması bu plan üzerinden değerlendirilir.</p>
                  </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Önerilen Slotlar</p>
                  <p className="mt-2 font-semibold text-slate-900">
                    {assignmentScheduleSupport.slotSuggestions.length > 0 ? assignmentScheduleSupport.slotSuggestions.join(' · ') : 'Boş slot bilgisi yok'}
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
                  {paymentNeededNoDecision ? (
                    <p>Ödeme yöntemi netleşmeden atama güncellenemez. Ödeme linki oluşturun veya müşterinin ustaya ödeyeceği tutarı belirleyin.</p>
                  ) : null}
                  {mikroMountCheck?.montaj_ek_aciklama ? <p>{mikroMountCheck.montaj_ek_aciklama}</p> : null}
                  {mikroMountCheck?.farkli_cari_uyarisi ? <p>Sonradan montaj carisi, son geçerli satış carisinden farklı.</p> : null}
                </div>

                {paymentNeededNoDecision ? (
                  <div className="grid gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <div>
                      <p className="font-semibold">Ödeme yöntemi netleşmeden atama güncellenemez.</p>
                      <p className="mt-1">Ödeme linki oluşturun veya müşterinin ustaya ödeyeceği tutarı belirleyin. Gizli montaj hariç onayı artık atama kapısı değildir.</p>
                    </div>
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
                              resetAssignmentDraftForTechnicianChange()
                              setRouteQuoteAutoEnabled(true)
                              setAssignTechnicianOption(match.technician.id)
                              const links = activeTechnicianPartnerLinks(match.technician)
                              setAssignPartnerOption(links.length === 1 ? String(links[0].partner_id) : '')
                              setTravelRoundTripKm('')
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
                            {(() => {
                              const insight = technicianAssignmentInsights.find((item) => item.id === match.technician.id)

                              if (!insight) {
                                return null
                              }

                              return (
                                <span className="mt-2 block text-xs font-normal text-slate-600">
                                  {[`${insight.scheduledCount} iş`, insight.availableSlots.length > 0 ? `Uygun: ${insight.availableSlots.slice(0, 2).join(' / ')}` : 'Boş slot görünmüyor'].filter(Boolean).join(' · ')}
                                </span>
                              )
                            })()}
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
                          resetAssignmentDraftForTechnicianChange()
                          setRouteQuoteAutoEnabled(false)
                          setAssignTechnicianOption('other')
                          setAssignPartnerOption('')
                          setTravelRoundTripKm('')
                          setShowNearbyTechnicians(false)
                        }}
                        className="mr-3 h-4 w-4 accent-primary"
                      />
                      Diğer
                      </label>
                  </div>
                </fieldset>

                {selectedAssignTechnicianRecord && selectedAssignPartnerLinks.length > 0 ? (
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Usta iş kartı partner kapsamı
                    <select
                      value={selectedAssignTechnicianPartnerId ?? ''}
                      onChange={(event) => setAssignPartnerOption(event.target.value)}
                      disabled={selectedAssignPartnerLinks.length === 1}
                      className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    >
                      {selectedAssignPartnerLinks.length > 1 ? <option value="">Partner seçin</option> : null}
                      {selectedAssignPartnerLinks.map((link) => (
                        <option key={String(link.id)} value={String(link.partner_id)}>
                          {link.partner?.display_name || `Partner #${link.partner_id}`}
                        </option>
                      ))}
                    </select>
                    <span className="text-xs font-normal text-slate-500">
                      Mesajdaki canonical iş kartı bağlantısı bu açık atama kapsamından üretilir.
                    </span>
                  </label>
                ) : selectedAssignTechnicianRecord ? (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                    Bu ustanın aktif partner iş kartı bağlantısı yok. Atama kaydedilebilir; usta mesajı güvenlik nedeniyle bloklanır.
                  </div>
                ) : null}

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

                <div className="grid gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-950">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <p className="font-semibold">Usta yol hakedişi hesabı</p>
                      <p className="mt-1 text-xs text-blue-800">30 km ücretsiz sınır gidiş-geliş mesafe üzerinden değerlendirilir.</p>
                    </div>
                    <Button
                      type="button"
                      variant="outline"
                      onClick={handleRouteQuoteCalculate}
                      disabled={routeQuoteLoading || !assignTechnicianOption || assignTechnicianOption === 'other'}
                      className="border-blue-200 bg-white text-blue-800 hover:bg-blue-100"
                    >
                      {routeQuoteLoading ? 'Hesaplanıyor...' : 'Yeniden hesapla'}
                    </Button>
                  </div>
                  {routeQuoteError ? (
                    <div className={[
                      'rounded-xl border px-3 py-2 text-xs font-semibold',
                      assignmentRouteQuote?.status === 'calculated'
                        ? assignmentRouteQuote.travel_fee_required ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-800'
                        : 'border-amber-200 bg-amber-50 text-amber-900',
                    ].join(' ')}>
                      {routeQuoteError}
                    </div>
                  ) : null}
                  <div className="grid gap-2 sm:grid-cols-2">
                    <div className="rounded-xl bg-white/80 p-3">
                      <p className="text-xs font-semibold text-blue-700">Tek yön yol mesafesi</p>
                      <p className="mt-1 font-semibold text-slate-950">{assignmentRouteDistanceLabel}</p>
                      {assignmentRouteQuote?.duration_text ? <p className="mt-1 text-xs text-slate-500">Tahmini süre: {assignmentRouteQuote.duration_text}</p> : null}
                    </div>
                    <div className="rounded-xl bg-white/80 p-3">
                      <p className="text-xs font-semibold text-blue-700">Gidiş-geliş mesafe</p>
                      <p className="mt-1 font-semibold text-slate-950">{assignmentRouteRoundTripLabel}</p>
                    </div>
                    <div className="rounded-xl bg-white/80 p-3">
                      <p className="text-xs font-semibold text-blue-700">Ücrete tabi km</p>
                      <p className="mt-1 font-semibold text-slate-950">{assignmentRouteExtraKmLabel}</p>
                    </div>
                    <div className="rounded-xl bg-white/80 p-3">
                      <p className="text-xs font-semibold text-blue-700">Tahmini usta yol hakedişi</p>
                      <p className="mt-1 font-semibold text-slate-950">{assignmentRouteFeeLabel}</p>
                    </div>
                    <div className="rounded-xl bg-white/80 p-3">
                      <p className="text-xs font-semibold text-blue-700">Durum</p>
                      <p className="mt-1 font-semibold text-slate-950">
                        {assignmentRouteQuote?.status === 'calculated'
                          ? assignmentRouteQuote.travel_fee_required ? 'Usta yol hakedişi gönderilmeli' : 'Usta yol hakedişi yok'
                          : assignmentRouteQuote ? 'Usta yol hakedişi hesaplanamadı' : 'Hesap bekliyor'}
                      </p>
                    </div>
                  </div>
                </div>

                <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
                  <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl bg-white p-3">
                      <p className="text-xs font-semibold text-slate-500">İşçilik / montaj hakedişi</p>
                      <p className="mt-1 font-semibold text-slate-950">
                        {assignmentTechnicianLaborAmount !== null ? formatMoneyLabel(assignmentTechnicianLaborAmount) : 'Belirlenmedi'}
                      </p>
                      <p className="mt-1 text-xs text-slate-500">Kaynak: {assignmentTechnicianLaborSourceLabel}</p>
                    </div>
                    <div className="rounded-xl bg-white p-3">
                      <p className="text-xs font-semibold text-slate-500">Usta yol hakedişi</p>
                      <p className="mt-1 font-semibold text-slate-950">{assignmentTravelAmountLabel}</p>
                    </div>
                    <div className="rounded-xl bg-white p-3">
                      <p className="text-xs font-semibold text-slate-500">İşçilik + yol toplamı</p>
                      <p className="mt-1 font-semibold text-slate-950">{assignmentTotalTechnicianCostLabel}</p>
                    </div>
                  </div>
                  {modalCurrentFinance?.warranty_note ? (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                      {modalCurrentFinance.warranty_note}
                      {modalCurrentFinance.operation_cost_note ? ` · ${modalCurrentFinance.operation_cost_note}` : ''}
                    </div>
                  ) : null}
                </div>

                <div className="grid gap-3 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-950">
                  <div>
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-semibold">{assignmentPayoutSummaryLabel} bilgisi</p>
                      <span className="rounded-full border border-emerald-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-emerald-800">
                        {modalPayoutStatusLabel}
                      </span>
                    </div>
                    <p className="mt-1 text-xs text-emerald-800">Bu tutar müşteri tahsilatı değildir; hakediş ve müşteriye bildirilecek doğrudan usta ödemesi assignment kaydıyla settlement ledger'a yazılır. Bu fazda müşteri/WhatsApp mesajı gönderilmez.</p>
                  </div>
                  <div className="grid gap-3 sm:grid-cols-4">
                    <label className="grid gap-1 text-xs font-semibold text-emerald-800">
                      İşçilik / montaj
                      <Input
                        type="number"
                        min="0"
                        step="1"
                        value={assignOfferLaborAmount}
                        onChange={(event) => setAssignOfferLaborAmount(event.target.value)}
                        placeholder={assignmentTechnicianLaborAmount !== null ? String(assignmentTechnicianLaborAmount) : '0'}
                      />
                    </label>
                    <label className="grid gap-1 text-xs font-semibold text-emerald-800">
                      Usta yol hakedişi
                      <Input
                        type="number"
                        min="0"
                        step="1"
                        value={assignOfferRouteFeeAmount}
                        onChange={(event) => setAssignOfferRouteFeeAmount(event.target.value)}
                        placeholder={assignmentRouteFeeAmount !== null ? String(assignmentRouteFeeAmount) : '0'}
                      />
                    </label>
                    <label className="grid gap-1 text-xs font-semibold text-emerald-800">
                      Müşteriye bildirilecek ustaya ödeme tutarı
                      <Input
                        type="number"
                        min="0"
                        step="1"
                        value={assignCustomerDirectAmount}
                        onChange={(event) => setAssignCustomerDirectAmount(event.target.value)}
                        placeholder={String(finalAssignmentCustomerDirectDefault)}
                        disabled={customerDirectPaymentDisabled}
                      />
                    </label>
                    <div className="rounded-xl bg-white/80 p-3">
                      <p className="text-xs font-semibold text-emerald-700">{assignmentPayoutSummaryLabel}</p>
                      <p className="mt-1 font-semibold text-slate-950">
                        {formatMoneyLabel(finalAssignmentTotalAmount)}
                      </p>
                    </div>
                  </div>
                  <div className="grid gap-2 rounded-xl border border-emerald-100 bg-white/80 p-3 text-xs text-emerald-900">
                    <p className="font-semibold">
                      {customerDirectPaymentDisabled
                        ? 'Müşteriden montaj ödemesi alındığı için ustaya doğrudan ödeme bildirilmeyecek.'
                        : 'Müşteriden montaj ödemesi alınmadıysa randevu mesajında bu tutar ustaya ödenecek olarak bildirilecek.'}
                    </p>
                    <div className="grid gap-2 sm:grid-cols-3">
                      <span>Kalan şirket ödemesi: <strong>{formatMoneyLabel(finalAssignmentCompanyPayableAmount)}</strong></span>
                      <span>Müşteriye bildirilecek tutar: <strong>{formatMoneyLabel(finalAssignmentCustomerDirectAmount)}</strong></span>
                      <span>Fark kaydı: <strong>{formatMoneyLabel(finalAssignmentOverpayAmount || finalAssignmentCompanyPayableAmount)}</strong></span>
                    </div>
                    {finalAssignmentOverpayAmount > 0 ? (
                      <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 font-semibold text-amber-900">
                        Müşteriye bildirilen tutar usta hakedişinden yüksek. Admin incelemesi gerekecek.
                      </p>
                    ) : finalAssignmentHasSmallDifference ? (
                      <p className="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 font-semibold text-blue-900">
                        10 TL altı fark da aynen kaydedilir; otomatik yuvarlama yapılmaz.
                      </p>
                    ) : null}
                  </div>
                  <label className="grid gap-1 text-xs font-semibold text-emerald-800">
                    Hakediş notu
                    <Input value={assignOfferNote} onChange={(event) => setAssignOfferNote(event.target.value)} placeholder="Ustaya gidecek bilgilendirme notu" />
                  </label>
                </div>
              </div>

              <DialogFooter className="gap-2">
                <Button variant="secondary" type="button" onClick={closeAssignmentDialog} disabled={assignLoading}>
                  İptal
                </Button>
                <Button
                  type="button"
                  onClick={handleAssignmentFinalConfirmOpen}
                  disabled={!canSubmitAssign}
                >
                  {assignLoading ? 'Kaydediliyor...' : 'Usta Ata'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={assignmentConfirmDialogOpen} onOpenChange={setAssignmentConfirmDialogOpen}>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto rounded-[28px]">
              <DialogHeader>
                <DialogTitle>Son hakediş onayı</DialogTitle>
                <DialogDescription>
                  Ustaya hazırlanacak son işçilik ve yol hakedişini onaylayın. Popup'taki tutar backend'e kaydedilir ve mesaj taslağında bu tutar kullanılır.
                </DialogDescription>
              </DialogHeader>

              {assignError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {assignError}
                </div>
              ) : null}

              <div className="grid gap-4">
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Usta</p>
                  <p className="mt-1 text-base font-semibold text-slate-950">
                    {selectedAssignTechnicianName || 'Usta seçilmedi'}
                  </p>
                  {selectedAssignTechnicianPartnerId ? (
                    <p className="mt-2 text-xs font-semibold text-blue-700">Canonical iş kartı kapsamı seçildi.</p>
                  ) : null}
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                  <label className="grid gap-1 text-sm font-semibold text-slate-700">
                    İşçilik / montaj hakedişi
                    <Input
                      type="number"
                      min="0"
                      step="1"
                      value={assignOfferLaborAmount}
                      onChange={(event) => setAssignOfferLaborAmount(event.target.value)}
                      placeholder={String(assignmentTechnicianLaborAmount ?? 0)}
                    />
                  </label>
                  <label className="grid gap-1 text-sm font-semibold text-slate-700">
                    Yol hakedişi
                    <Input
                      type="number"
                      min="0"
                      step="1"
                      value={assignOfferRouteFeeAmount}
                      onChange={(event) => setAssignOfferRouteFeeAmount(event.target.value)}
                      placeholder={String(assignmentRouteFeeAmount ?? 0)}
                    />
                    <span className="text-xs font-medium text-slate-500">{assignmentRouteFeeReason}</span>
                  </label>
                  <label className="grid gap-1 text-sm font-semibold text-slate-700 sm:col-span-2">
                    Müşteriye bildirilecek ustaya ödeme tutarı
                    <Input
                      type="number"
                      min="0"
                      step="1"
                      value={assignCustomerDirectAmount}
                      onChange={(event) => setAssignCustomerDirectAmount(event.target.value)}
                      placeholder={String(finalAssignmentCustomerDirectDefault)}
                      disabled={customerDirectPaymentDisabled}
                    />
                    <span className="text-xs font-medium text-slate-500">
                      {customerDirectPaymentDisabled
                        ? 'Müşteriden montaj ödemesi alındığı için ustaya doğrudan ödeme bildirilmeyecek.'
                        : 'Müşteriden montaj ödemesi alınmadıysa randevu mesajında bu tutar ustaya ödenecek olarak bildirilecek.'}
                    </span>
                  </label>
                </div>

                <div className="grid gap-2 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-950 sm:grid-cols-2">
                  <div>
                    <p className="text-xs font-semibold text-emerald-700">Onaylanacak usta hakedişi</p>
                    <p className="mt-1 text-lg font-semibold">{formatMoneyLabel(finalAssignmentTotalAmount)}</p>
                  </div>
                  <div>
                    <p className="text-xs font-semibold text-emerald-700">Müşteri tahsilatı</p>
                    <p className="mt-1 text-lg font-semibold">{modalCollectedPaymentLabel}</p>
                  </div>
                  <div>
                    <p className="text-xs font-semibold text-emerald-700">Müşteriye bildirilecek usta ödemesi</p>
                    <p className="mt-1 text-lg font-semibold">{formatMoneyLabel(finalAssignmentCustomerDirectAmount)}</p>
                  </div>
                  <div>
                    <p className="text-xs font-semibold text-emerald-700">Kalan şirket ödemesi</p>
                    <p className="mt-1 text-lg font-semibold">{formatMoneyLabel(finalAssignmentCompanyPayableAmount)}</p>
                  </div>
                  {modalCurrentFinance?.warranty_note ? (
                    <div className="rounded-xl border border-amber-200 bg-white/80 px-3 py-2 text-xs font-semibold text-amber-900 sm:col-span-2">
                      {modalCurrentFinance.warranty_note}
                      {modalCurrentFinance.operation_cost_note ? ` · ${modalCurrentFinance.operation_cost_note}` : ''}
                    </div>
                  ) : null}
                  <div className="sm:col-span-2">
                    <p className="text-xs font-semibold text-emerald-700">{modalNetDifferenceLabel}</p>
                    <p className="mt-1 text-lg font-semibold">{finalAssignmentNetDifference !== null ? formatMoneyLabel(finalAssignmentNetDifference) : '-'}</p>
                  </div>
                  {finalAssignmentOverpayAmount > 0 ? (
                    <div className="rounded-xl border border-amber-200 bg-white/80 px-3 py-2 text-xs font-semibold text-amber-900 sm:col-span-2">
                      Müşteriye bildirilen tutar usta hakedişinden yüksek. Admin incelemesi gerekecek. Fazla bildirim: {formatMoneyLabel(finalAssignmentOverpayAmount)}
                    </div>
                  ) : finalAssignmentCompanyPayableAmount > 0 ? (
                    <div className="rounded-xl border border-blue-200 bg-white/80 px-3 py-2 text-xs font-semibold text-blue-900 sm:col-span-2">
                      Kalan şirket ödemesi: {formatMoneyLabel(finalAssignmentCompanyPayableAmount)}. Fark, 10 TL altında olsa bile aynen kaydedilir.
                    </div>
                  ) : null}
                </div>

                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Operasyon notu / usta mesaj notu
                  <Input value={assignOfferNote} onChange={(event) => setAssignOfferNote(event.target.value)} placeholder="Ustaya gidecek bilgilendirme notu" />
                </label>

                <div className="grid gap-2 rounded-2xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-950">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-blue-700">Mesaj taslağı</p>
                      <p className="mt-1 text-xs font-medium text-blue-800">Atama sonrası mesaj durumu server ayarları ve kanal politikasıyla belirlenir; sonuç Kuyruk / Log ekranından izlenir.</p>
                    </div>
                    {assignmentPartnerJobPath ? <span className="text-xs font-semibold text-blue-700">Canonical link atama sonrası server tarafından doğrulanır.</span> : null}
                  </div>
                  <pre className="max-h-52 overflow-auto whitespace-pre-wrap rounded-xl border border-blue-100 bg-white p-3 text-xs leading-5 text-slate-800">
                    {assignmentFinalMessagePreview}
                  </pre>
                </div>
              </div>

              <DialogFooter className="gap-2">
                <Button type="button" variant="secondary" onClick={() => setAssignmentConfirmDialogOpen(false)}>
                  Vazgeç
                </Button>
                <Button type="button" onClick={handleAssignSubmit} disabled={!canSubmitAssign || assignLoading}>
                  {assignLoading ? 'Kaydediliyor...' : 'Atamayı onayla ve mesajı hazırla'}
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
                  {modalDisplayMrn ? `${modalDisplayMrn} için kesin randevu planlayın.` : 'Seçili talep yok.'}
                </DialogDescription>
              </DialogHeader>

              {scheduleError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {scheduleError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Randevu tarihi
                  <Input type="date" value={scheduleDate} onChange={(event) => setScheduleDate(event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Randevu saat aralığı
                  <select
                    value={scheduleTimeSlot}
                    onChange={(event) => setScheduleTimeSlot(event.target.value)}
                    className={selectClassName}
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
                  Planlama notu
                  <textarea
                    value={scheduleNote}
                    onChange={(event) => setScheduleNote(event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    placeholder="Randevu planı veya operasyon notu ekleyin"
                  />
                </label>
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleScheduleReset}>
                    İptal
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
                <DialogTitle>{({
                  customer_called: 'Müşteri Arandı',
                  customer_unreachable: 'Ulaşılamadı',
                  customer_callback_scheduled: 'Tekrar Arama Planla',
                  customer_confirmation_pending: 'Onay Bekliyor',
                  customer_confirmed: 'Müşteri Onayladı',
                  customer_rejected: 'Müşteri Reddetti',
                  wrong_number: 'Yanlış Numara',
                  customer_requested_cancel: 'İptal Talebi',
                  mark_missing_info: 'Eksik foto notunu düzelt',
                } as Record<string, string>)[contactAction ?? ''] ?? 'Müşteri İletişimi'}</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için müşteri iletişimi kaydı oluşturun.` : 'Seçili talep yok.'}
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
                    İletişim / onay yöntemi
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
                      Uygun gün
                      <Input type="date" value={contactPreferredDate} onChange={(event) => setContactPreferredDate(event.target.value)} />
                    </label>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Başlangıç saati
                        <Input type="time" value={contactPreferredTimeStart} onChange={(event) => setContactPreferredTimeStart(event.target.value)} />
                      </label>
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Bitiş saati
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
                    İptal nedeni
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
                    İptal
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
                  checklist_updated: 'Checklist Güncelle',
                  photos_updated: 'Fotoğraf Sayılarını Güncelle',
                  customer_closure_approved: 'Müşteri Kapanış Onayı Al',
                  field_marked_incomplete: 'Tamamlanamadı',
                  parts_pending: 'Parça Bekleniyor',
                  second_visit_required: 'İkinci Randevu Gerekli',
                  field_completed: 'İşi Tamamla',
                } as Record<string, string>)[fieldAction ?? ''] ?? 'İşlem'}</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için işlem bilgisini güncelleyin.` : 'Seçili talep yok.'}
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
                        Öncesi fotoğraf
                        <Input type="number" min="0" value={fieldBeforePhotoCount} onChange={(event) => setFieldBeforePhotoCount(event.target.value)} />
                      </label>
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Sonrası fotoğraf
                        <Input type="number" min="0" value={fieldAfterPhotoCount} onChange={(event) => setFieldAfterPhotoCount(event.target.value)} />
                      </label>
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        Genel fotoğraf
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
                        <option value="tamamlandı">Tamamlandı</option>
                        <option value="gerekli_degil">Belge gerekli değil</option>
                      </select>
                    </label>
                  </>
                ) : null}

                {fieldAction === 'customer_closure_approved' ? (
                  <>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Onay yöntemi
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
                        İmza adı
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
                          <option value="Müşteri Yerinde Yok">Müşteri Yerinde Yok</option>
                          <option value="Montaj Yeri Hazır Değil">Montaj Yeri Hazır Değil</option>
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
                      <span>İkinci randevu gerekli</span>
                    </label>

                    {(fieldAction === 'second_visit_required' || fieldRequiresSecondVisit) ? (
                      <label className="grid gap-2 text-sm font-medium text-slate-700">
                        İkinci randevu nedeni
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
                    İptal
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
                  ×
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Talebi tamamla</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için ${modalRequest?.customer} talebini tamamlayın.` : 'Seçili talep yok.'}
                </DialogDescription>
                {modalRequest?.serviceType ? (
                  <p className="text-sm leading-6 text-slate-600">
                    Müşteri tahsilatı: {modalCollectedPaymentLabel}
                  </p>
                ) : null}
              </DialogHeader>

              {completeError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {completeError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-950">
                  {completionReason || 'Tamamlama'}
                </div>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Kapanış notu
                  <textarea
                    value={completionOtherNote}
                    onChange={(event) => setCompletionOtherNote(event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    placeholder="Opsiyonel kapanış notu"
                  />
                </label>

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
                    (completionReason === 'Montaj tamamlandı' && modalRequest?.serviceType === 'Montaj' && !installationCompletedAt)
                  }
                >
                  {completeLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={requestCancellationDialogOpen} onOpenChange={(open) => {
            setRequestCancellationDialogOpen(open)

            if (!open) {
              setRequestCancellationReason('')
              setRequestCancellationNote('')
              setRequestCancellationError(null)
            }
          }}>
            <DialogContent className="max-w-lg">
              <DialogHeader>
                <DialogTitle>Talebi iptal et</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için iptal nedenini kaydedin. Bu işlem kartı aksiyona kapatır.` : 'Seçili talep yok.'}
                </DialogDescription>
              </DialogHeader>
              {requestCancellationError ? (
                <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-900">{requestCancellationError}</div>
              ) : null}
              <div className="grid gap-4">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  İptal nedeni
                  <select
                    className={selectClassName}
                    value={requestCancellationReason}
                    onChange={(event) => setRequestCancellationReason(event.target.value)}
                  >
                    <option value="">Neden seçin</option>
                    {CANCELLATION_REASONS.map((reason) => <option key={reason} value={reason}>{reason}</option>)}
                  </select>
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Açıklama {requestCancellationReason === 'Diğer' ? '(zorunlu)' : '(opsiyonel)'}
                  <textarea
                    value={requestCancellationNote}
                    onChange={(event) => setRequestCancellationNote(event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                  />
                </label>
              </div>
              <DialogFooter className="gap-2">
                <Button type="button" variant="secondary" onClick={() => setRequestCancellationDialogOpen(false)} disabled={requestCancellationLoading}>
                  Vazgeç
                </Button>
                <Button
                  type="button"
                  variant="destructive"
                  onClick={handleRequestCancellationSubmit}
                  disabled={requestCancellationLoading || !requestCancellationReason || (requestCancellationReason === 'Diğer' && !requestCancellationNote.trim())}
                >
                  {requestCancellationLoading ? 'İptal ediliyor...' : 'İptali onayla'}
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
                Yanlış kapanış geri alınır. Bu kapanışla başlayan garanti ve tamamlandı kayıtları geçersiz sayılır.
              </div>

              {modalRequest?.serviceType === 'Montaj' && modalRequest.completedAt ? (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-800">
                  Bu montaj talebi tamamlanmış görünüyor. Garanti başladıysa önerilen aksiyon yeni bağlı servis/takip talebi açmaktır.
                </div>
              ) : null}

              <fieldset className="grid gap-3">
                <legend className="text-sm font-medium text-slate-700">Açılış tipi</legend>
                <div className="grid gap-2 sm:grid-cols-2">
                  {[
                    { value: 'revisit' as const, label: 'Tekrar ziyaret', description: 'Aynı iş için yeniden gidilecek.' },
                    { value: 'service_request' as const, label: 'Servis talebi', description: 'Garanti/servis takibi olarak açılacak.' },
                  ].map((option) => (
                    <label
                      key={option.value}
                      className="flex cursor-pointer items-start rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition hover:border-slate-300"
                    >
                      <input
                        type="radio"
                        name="reopenType"
                        value={option.value}
                        checked={reopenType === option.value}
                        onChange={() => setReopenType(option.value)}
                        disabled={reopenReason === 'Yanlışlıkla tamamlandı'}
                        className="mr-3 mt-1 h-4 w-4 accent-primary disabled:opacity-40"
                      />
                      <span>
                        {option.label}
                        <span className="mt-1 block text-xs font-normal text-slate-500">{option.description}</span>
                      </span>
                    </label>
                  ))}
                </div>
                {reopenReason === 'Yanlışlıkla tamamlandı' ? (
                  <p className="rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-800">
                    Bu seçimde yeni SRV açılmaz; talep yanlış kapanıştan önceki aksiyona geri alınır.
                  </p>
                ) : null}
              </fieldset>

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
                Servis açıklaması / ustaya not
                <textarea
                  value={reopenNote}
                  onChange={(event) => setReopenNote(event.target.value)}
                  className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                  placeholder={reopenReason === 'Diğer' ? 'Açıklama zorunlu' : 'Yeni atanacak ustanın servis nedenini anlayacağı kısa not'}
                />
              </label>
              {modalRequest?.technician ? (
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                  <p className="font-semibold">Önerilen usta</p>
                  <p className="mt-1">{modalRequest.technician} sadece öneri olarak tutulur; yeni SRV otomatik atanmaz.</p>
                </div>
              ) : null}

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleReopenReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button type="button" onClick={handleReopenSubmit} disabled={reopenLoading || !reopenReason || (reopenReason === 'Diğer' && !reopenNote.trim())}>
                  {reopenReason === 'Yanlışlıkla tamamlandı' ? (reopenLoading ? 'Kaydediliyor...' : 'Yanlış kapanışı geri al') : (reopenLoading ? 'Kaydediliyor...' : 'Yeni SRV oluştur')}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        <TechnicalServiceKanbanBoard
          requests={kanbanFilteredRequests}
          selectedRequestId={selectedRequest?.id ?? ''}
          readRequestIds={readRequestIds}
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
            setFinancialWorkspaceError(null)
            setFinancialWorkspaceLoading(false)
            setWarranty(null)
            setWarrantyError(null)
            setWarrantyLoading(false)
            setPriorityUpdateError(null)
            setPriorityUpdateLoading(false)
          }
        }}>
          <DialogContent
            className="flex !w-[90vw] max-h-[90vh] flex-col overflow-hidden rounded-[28px] p-0 shadow-[0_30px_80px_rgba(15,23,42,0.2)] max-sm:!w-[calc(100vw-16px)] max-sm:!max-w-[calc(100vw-16px)] max-sm:!max-h-[calc(100vh-16px)]"
            style={{
              width: '90vw',
              maxWidth: '90vw',
              maxHeight: '90vh',
            }}
          >
            <div className="flex h-full min-h-[420px] flex-col overflow-hidden bg-white">
              <DialogHeader className="sticky top-0 z-20 border-b border-slate-200 bg-white px-4 py-3 md:px-6">
                <div className="flex items-start justify-between gap-4">
                  <div className="min-w-0">
                    <DialogTitle className="text-base font-semibold text-slate-900">Talep Detayı</DialogTitle>
                    <DialogDescription className="sr-only">
                      Seçili teknik servis talebinin operasyon, ödeme, usta atama ve saha tamamlama detayları.
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
              </DialogHeader>

              <div ref={detailScrollRef} className="flex-1 min-h-0 overflow-y-auto overscroll-contain px-4 py-4 md:px-6 md:py-5 xl:px-7">
                {(selectedDetailRequest || modalRequest) ? (
                  <ServiceRequestDetails
                    request={selectedDetailRequest ?? modalRequest!}
                    opsDetailVisibility={opsDetailVisibility}
                    displayMrn={modalDisplayMrn ?? undefined}
                    events={selectedEvents}
                    loading={detailLoading}
                    error={detailError}
                    financialWorkspaceLoading={financialWorkspaceLoading}
                    financialWorkspaceError={financialWorkspaceError}
                    onPaymentHistoryLoad={() => loadPaymentWorkspace((selectedDetailRequest ?? modalRequest!).id)}
                    mikroMountCheck={mikroMountCheck}
                    mikroMountLoading={mikroMountLoading}
                    mikroMountError={mikroMountError}
                    warranty={warranty}
                    warrantyLoading={warrantyLoading}
                    warrantyError={warrantyError}
                    onAssign={openAssignmentDialog}
                    onSchedule={() => setScheduleDialogOpen(true)}
                    onComplete={openCompleteDialog}
                    onCancel={openRequestCancellationDialog}
                    onReopen={() => setReopenDialogOpen(true)}
                    onPriorityChange={handlePriorityChange}
                    onWorkflowAction={handleWorkflowAction}
                    onOperationControlChange={handleOperationControlChange}
                    onAdminOverrideSubmit={handleAdminOverrideSubmit}
                    onAdminOverrideReview={handleAdminOverrideReview}
                    onInvoiceSerialRecheck={handleInvoiceSerialRecheck}
                    onInvoiceSerialAdd={(serialId) => handleInvoiceSerialAction('add', serialId)}
                    onInvoiceSerialRemove={(serialId) => handleInvoiceSerialAction('remove', serialId)}
                    onInvoiceSerialAddAll={() => handleInvoiceSerialAction('add-all')}
                    priorityUpdateInFlight={priorityUpdateLoading}
                    priorityUpdateError={priorityUpdateError}
                    workflowActionInFlight={workflowActionLoading}
                    operationControlUpdateInFlight={operationControlUpdateLoading}
                    operationControlUpdateError={operationControlUpdateError}
                    adminOverrideInFlight={adminOverrideLoading}
                    adminOverrideError={adminOverrideError}
                    invoiceSerialRecheckInFlight={invoiceSerialRecheckLoading}
                    invoiceSerialRecheckError={invoiceSerialRecheckError}
                    invoiceSerialActionInFlight={invoiceSerialActionLoading}
                    invoiceSerialActionError={invoiceSerialActionError}
                    technicianSuggestions={visibleTechnicianAssignmentInsights}
                    scheduleSupport={assignmentScheduleSupport}
                    selectedTechnicianId={assignTechnicianOption || null}
                    routeQuoteLoading={routeQuoteLoading}
                    routeQuoteError={routeQuoteError}
                    routeQuoteManualSaveLoading={routeQuoteManualSaveLoading}
                    routeQuoteManualSaveError={routeQuoteManualSaveError}
                    technicianEarningMessageLoading={technicianEarningMessageLoading}
                    technicianEarningMessageError={technicianEarningMessageError}
                    assignLoading={assignLoading}
                    canSubmitAssign={canSubmitAssign}
                    assignError={assignError}
                    assignSuccess={assignSuccess}
                    mountExclusionAcknowledged={assignOverrideWithoutPayment}
                    mountExclusionNote={assignOverrideReason}
                    onMountExclusionAcknowledgedChange={setAssignOverrideWithoutPayment}
                    onMountExclusionNoteChange={setAssignOverrideReason}
                    onTechnicianSelect={(technicianId) => {
                      resetAssignmentDraftForTechnicianChange()
                      setRouteQuoteAutoEnabled(true)
                      setAssignTechnicianOption(technicianId)
                      const technician = technicians.find((item) => item.id === technicianId) ?? null
                      const links = activeTechnicianPartnerLinks(technician)
                      setAssignPartnerOption(links.length === 1 ? String(links[0].partner_id) : '')
                      setExtraPaymentCreateError(null)
                      setTechnicianEarningMessageError(null)
                      setTravelRoundTripKm('')
                    }}
                    onRouteQuoteCalculate={handleRouteQuoteCalculate}
                    onRouteQuoteManualSave={handleRouteQuoteManualSave}
                    onExtraMountPaymentCreate={handleExtraMountPaymentCreate}
                    onMountPaymentCancel={handleMountPaymentCancel}
                    onMountPaymentSync={handleMountPaymentSync}
                    onMountPaymentSendContext={handleMountPaymentSendContext}
                    onMountPaymentSend={handleMountPaymentSend}
                    onTechnicianEarningMessageCreate={handleTechnicianEarningMessageCreate}
                    onPartnerAppointmentProposalApprove={handlePartnerAppointmentProposalApprove}
                    onPartnerAppointmentProposalReject={handlePartnerAppointmentProposalReject}
                    appointmentApprovalInFlight={appointmentApprovalInFlight}
                    appointmentApprovalError={appointmentApprovalError}
                    appointmentApprovalSuccess={appointmentApprovalSuccess}
                    onPartnerCompletionApprove={handlePartnerCompletionApprove}
                    onRevisitServiceVisitCreate={handleRevisitServiceVisitCreate}
                    onPartRequestCreate={handlePartRequestCreate}
                    onPartRequestTransition={handlePartRequestTransition}
                    onPartRequestServiceVisitCreate={handlePartRequestServiceVisitCreate}
                    onPartRequestManualPaymentConfirm={handlePartRequestManualPaymentConfirm}
                    onAssignmentOfferUpdate={handleAssignmentOfferUpdate}
                    onCompanyPaymentDecisionApprove={handleCompanyPaymentDecisionApprove}
                    assignmentOfferUpdateInFlight={assignmentOfferUpdateInFlight}
                    assignmentOfferUpdateError={assignmentOfferUpdateError}
                    assignmentOfferUpdateSuccess={assignmentOfferUpdateSuccess}
                    onPartnerActionReview={handlePartnerActionReview}
                    partnerActionReviewInFlight={partnerActionReviewLoading}
                    partnerActionReviewError={partnerActionReviewError}
                    onFieldDocumentReview={handleFieldDocumentReview}
                    onOpsExtraDocumentUpload={handleOpsExtraDocumentUpload}
                    onCustomerApprovalResend={handleCustomerApprovalResend}
                    fieldDocumentReviewInFlight={fieldDocumentReviewLoading}
                    fieldDocumentReviewError={fieldDocumentReviewError}
                    customerApprovalResendLoading={customerApprovalResendLoading}
                    customerApprovalResendError={customerApprovalResendError}
                    extraPaymentCreateLoading={extraPaymentCreateLoading}
                    extraPaymentCreateError={extraPaymentCreateError}
                    onAssignSelectedTechnician={handleAssignmentFinalConfirmOpen}
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

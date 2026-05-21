import { ChevronDown } from 'lucide-react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import type { MikroMountCheckResult, ServicePriority, ServiceRequest, ServiceRequestEvent, ServiceRequestExtraMountPaymentPayload, ServiceRequestInvoiceSerial, ServiceRequestRouteQuote, ServiceRequestRouteQuoteManualPayload, ServiceRequestTechnicianEarningMessagePayload, WarrantySerialResponse } from './types'
import { formatTechnicalServiceDate, formatTechnicalServiceDateTime, getServicePaymentInfo } from './utils'

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
  onInvoiceSerialRecheck?: () => void | Promise<void>
  onInvoiceSerialAdd?: (serialId: number | string) => void | Promise<void>
  onInvoiceSerialRemove?: (serialId: number | string) => void | Promise<void>
  onInvoiceSerialAddAll?: () => void | Promise<void>
  priorityUpdateInFlight?: boolean
  priorityUpdateError?: string | null
  workflowActionInFlight?: string | null
  operationControlUpdateInFlight?: boolean
  operationControlUpdateError?: string | null
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
  onTechnicianEarningMessageCreate?: (payload: ServiceRequestTechnicianEarningMessagePayload) => void | Promise<{ message_text?: string, whatsapp_url?: string, copy_text?: string } | void>
  onAssignSelectedTechnician?: () => void | Promise<void>
  onPartnerAppointmentProposalApprove?: (actionId: number | string, payload?: { note?: string | null, selected_slot_index?: number }) => void | Promise<void>
  onPartnerAppointmentProposalReject?: (actionId: number | string, payload: { note: string, status?: string }) => void | Promise<void>
  onPartnerCompletionApprove?: (actionId: number | string, payload?: { note?: string | null }) => void | Promise<void>
  onAssignmentOfferUpdate?: (offerId: number | string, payload: { labor_amount: number, route_fee_amount: number, total_amount?: number, note?: string | null }) => void | Promise<void>
}

const eventTime = (timestamp: string): string => {
  return formatTechnicalServiceDateTime(timestamp, 'Bilinmiyor')
}

const formatDisplayValue = (value: string | null | undefined): string => {
  const normalized = String(value ?? '').trim()

  return normalized !== '' ? normalized : '-'
}

const hasText = (value: string | null | undefined): boolean => String(value ?? '').trim() !== ''

const displayOrEmpty = (value: string | null | undefined, fallback: string): string => {
  const normalized = String(value ?? '').trim()

  return normalized !== '' ? normalized : fallback
}

const dateTimeOrEmpty = (value: string | null | undefined, fallback: string): string => (
  hasText(value) ? formatTechnicalServiceDateTime(value, fallback) : fallback
)

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

  return sameCoordinateValue(routeQuote.origin_latitude, technicianLatitude)
    && sameCoordinateValue(routeQuote.origin_longitude, technicianLongitude)
    && sameCoordinateValue(routeQuote.destination_latitude, locationInfo.latitude)
    && sameCoordinateValue(routeQuote.destination_longitude, locationInfo.longitude)
}

const routeQuoteMessage = (message: string | null | undefined): string => {
  if (message === 'Usta konumu eksik.') {
    return 'Usta konumu eksik. Yol ücreti hesaplanamadı.'
  }

  if (message === 'Müşteri konumu eksik.') {
    return 'Müşteri konumu eksik. Yol ücreti hesaplanamadı.'
  }

  return displayOrEmpty(message, 'Yol ücreti hesaplanamadı')
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
}: {
  title: string
  summary?: ReactNode
  children: ReactNode
  open?: boolean
  onOpenChange?: (open: boolean) => void
  tone?: DetailPanelTone
}) => (
  <details
    className={['group rounded-2xl border p-4 shadow-sm transition-colors', detailPanelToneClass(tone)].join(' ')}
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

const InvoiceSerialSection = ({
  title,
  items,
  onAdd,
  onRemove,
  actionInFlight,
}: {
  title: string
  items?: ServiceRequestInvoiceSerial[]
  onAdd?: (serialId: number | string) => void | Promise<void>
  onRemove?: (serialId: number | string) => void | Promise<void>
  actionInFlight?: string | null
}) => {
  if (!items || items.length === 0) {
    return null
  }

  return (
    <section className="grid gap-3">
      <p className="text-sm font-semibold text-slate-900">{title}</p>
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
    return String(request.workflowStatus)
  }

  return request.status === 'Yeni' ? 'Yeni Talep' : request.status
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
  displayMrn,
  events,
  loading,
  error,
  mikroMountCheck,
  mikroMountLoading = false,
  mikroMountError = null,
  warranty = null,
  onAssign,
  onSchedule,
  onComplete,
  onReopen,
  onPriorityChange,
  onWorkflowAction,
  onOperationControlChange,
  onInvoiceSerialRecheck,
  onInvoiceSerialAdd,
  onInvoiceSerialRemove,
  onInvoiceSerialAddAll,
  priorityUpdateInFlight = false,
  priorityUpdateError = null,
  workflowActionInFlight = null,
  operationControlUpdateInFlight = false,
  operationControlUpdateError = null,
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
  onTechnicianEarningMessageCreate,
  onAssignSelectedTechnician,
  onPartnerAppointmentProposalApprove,
  onPartnerAppointmentProposalReject,
  onPartnerCompletionApprove,
  onAssignmentOfferUpdate,
}: ServiceRequestDetailsProps) {
  const paymentInfo = getServicePaymentInfo(
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
  const mountPaymentLabel = paymentInfo.customerAmountLabel && paymentInfo.customerAmountLabel !== 'Belirlenmedi'
    ? paymentInfo.customerAmountLabel
    : '-'
  const productInfo = request.productInfo ?? null
  const saleAndPayment = request.saleAndPayment ?? null
  const documentInfo = request.documentInfo ?? null
  const invoiceSerials = request.invoiceSerials ?? null
  const [invoiceSerialsOpenByRequest, setInvoiceSerialsOpenByRequest] = useState<Record<string, boolean>>({})
  const invoiceSerialsOpen = invoiceSerialsOpenByRequest[request.id] ?? Boolean(invoiceSerials?.has_multi_product)
  const setInvoiceSerialsOpen = (open: boolean) => {
    setInvoiceSerialsOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [productInfoOpenByRequest, setProductInfoOpenByRequest] = useState<Record<string, boolean>>({})
  const productInfoOpen = productInfoOpenByRequest[request.id] ?? true
  const setProductInfoOpen = (open: boolean) => {
    setProductInfoOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [customerInfoOpenByRequest, setCustomerInfoOpenByRequest] = useState<Record<string, boolean>>({})
  const customerInfoOpen = customerInfoOpenByRequest[request.id] ?? false
  const setCustomerInfoOpen = (open: boolean) => {
    setCustomerInfoOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [assignmentInfoOpenByRequest, setAssignmentInfoOpenByRequest] = useState<Record<string, boolean>>({})
  const assignmentInfoOpen = assignmentInfoOpenByRequest[request.id] ?? true
  const setAssignmentInfoOpen = (open: boolean) => {
    setAssignmentInfoOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [finalCheckOpenByRequest, setFinalCheckOpenByRequest] = useState<Record<string, boolean>>({})
  const finalCheckOpen = finalCheckOpenByRequest[request.id] ?? false
  const setFinalCheckOpen = (open: boolean) => {
    setFinalCheckOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const [fieldCompletionOpen, setFieldCompletionOpen] = useState(false)
  const [serialQueryOpen, setSerialQueryOpen] = useState(false)
  const [routeFeeEditorOpen, setRouteFeeEditorOpen] = useState(false)
  const [routeFeeEditorMessage, setRouteFeeEditorMessage] = useState<string | null>(null)
  const [routeFeeNote, setRouteFeeNote] = useState('')
  const [routeFeeOneWayKmInput, setRouteFeeOneWayKmInput] = useState('')
  const [routeFeeRoundTripKmInput, setRouteFeeRoundTripKmInput] = useState('')
  const [routeFeeThresholdKmInput, setRouteFeeThresholdKmInput] = useState('')
  const [routeFeePerKmInput, setRouteFeePerKmInput] = useState('')
  const [routeFeeBillableKmInput, setRouteFeeBillableKmInput] = useState('')
  const [routeFeeAmountInput, setRouteFeeAmountInput] = useState('')
  const [routeFeeExtraPaymentInput, setRouteFeeExtraPaymentInput] = useState('')
  const [routeFeeManualAmountTouched, setRouteFeeManualAmountTouched] = useState(false)
  const [routeFeeEditorInitialSnapshot, setRouteFeeEditorInitialSnapshot] = useState('')
  const [earningTotalInput, setEarningTotalInput] = useState('')
  const [earningNoteInput, setEarningNoteInput] = useState('')
  const [earningMessageText, setEarningMessageText] = useState('')
  const [earningMessageUrl, setEarningMessageUrl] = useState('')
  const [appointmentReviewNote, setAppointmentReviewNote] = useState('')
  const [appointmentSelectedSlotByAction, setAppointmentSelectedSlotByAction] = useState<Record<string, number>>({})
  const [completionReviewNote, setCompletionReviewNote] = useState('')
  const [offerLaborInput, setOfferLaborInput] = useState('')
  const [offerRouteInput, setOfferRouteInput] = useState('')
  const [offerNoteInput, setOfferNoteInput] = useState('')
  const [differentAddressInfoOpen, setDifferentAddressInfoOpen] = useState(false)
  const locationInfo = request.location ?? null
  const doorPhotos = request.doorPhotos ?? []
  const routeQuote = request.routeQuote ?? null
  const partnerPortalActions = request.partnerPortalActions ?? []
  const openAppointmentProposals = partnerPortalActions.filter((action) => action.action === 'appointment_proposed' && action.status === 'ops_review')
  const jobRejections = partnerPortalActions.filter((action) => action.action === 'job_rejected')
  const supportRequests = partnerPortalActions.filter((action) => action.action === 'support_requested' && action.status === 'ops_review')
  const completionSubmissions = partnerPortalActions.filter((action) => action.action === 'completion_submitted' && action.status === 'ops_review')
  const assignmentOffer = request.assignmentOffer ?? null
  const selectedTechnician = technicianSuggestions.find((technician) => technician.id === selectedTechnicianId) ?? null
  const selectedTechnicianIdString = selectedTechnicianId ? String(selectedTechnicianId) : null
  const routeQuoteTechnicianIdString = routeQuote?.technician_id !== null && routeQuote?.technician_id !== undefined
    ? String(routeQuote.technician_id)
    : null
  const routeQuoteMatchesSelectedTechnician = Boolean(routeQuote && selectedTechnicianIdString && routeQuoteTechnicianIdString === selectedTechnicianIdString)
  const routeQuoteStaleForSelectedTechnician = Boolean(routeQuote && selectedTechnicianIdString && routeQuoteTechnicianIdString && routeQuoteTechnicianIdString !== selectedTechnicianIdString)
  const hasAssignmentChange = Boolean(selectedTechnicianId && selectedTechnicianId !== String(request.technicianId ?? ''))
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
  const routeFeeNeedsApproval = Boolean(activeRouteQuote?.travel_fee_required)
  const selectedTechnicianName = selectedTechnician?.name ?? request.technician ?? 'Seçili usta'
  const routeFeeNotCalculatedMessage = `${selectedTechnicianName} için yol ücreti henüz hesaplanmadı.`
  const routeFeeNotCalculatedHint = 'Yol ücretini hesaplamak için seçili usta ve müşteri konumu kullanılacak.'
  const shouldShowRouteFeeNotCalculatedMessage = Boolean(
    selectedTechnician && !routeQuoteLoading && !hasActiveRouteQuote,
  )
  const routeFeeCalculateButtonText = routeQuoteLoading
    ? 'Hesaplanıyor...'
    : 'Yeniden hesapla'
  const routeFeeStatusText = routeQuoteStaleForSelectedTechnician
    ? routeQuoteLoading ? 'Yol ücreti hesaplanıyor' : 'Yol ücreti hesaplanmadı'
    : hasActiveRouteQuote && activeRouteQuote
      ? activeRouteQuote.travel_fee_required ? 'Yol ücreti onayı gerekli' : 'Yol ücreti yok'
      : selectedTechnician ? 'Yol ücreti hesaplanmadı' : routeQuote ? 'Yol ücreti hesaplanamadı' : 'Yol ücreti hesaplanmadı'
  const routeRoundTripKm = hasActiveRouteQuote
    ? typeof activeRouteQuote?.round_trip_distance_km === 'number' && Number.isFinite(activeRouteQuote.round_trip_distance_km)
      ? activeRouteQuote.round_trip_distance_km
      : typeof activeRouteQuote?.distance_km === 'number' && Number.isFinite(activeRouteQuote.distance_km)
        ? activeRouteQuote.distance_km
        : null
    : null
  const routeOneWayKm = hasActiveRouteQuote
    ? typeof activeRouteQuote?.one_way_distance_km === 'number' && Number.isFinite(activeRouteQuote.one_way_distance_km)
      ? activeRouteQuote.one_way_distance_km
      : routeRoundTripKm !== null
        ? roundTwo(routeRoundTripKm / 2)
        : null
    : null
  const routeBillableKm = hasActiveRouteQuote
    ? typeof activeRouteQuote?.billable_km === 'number' && Number.isFinite(activeRouteQuote.billable_km)
      ? activeRouteQuote.billable_km
      : typeof activeRouteQuote?.extra_km === 'number' && Number.isFinite(activeRouteQuote.extra_km)
        ? activeRouteQuote.extra_km
        : null
    : null
  const routeFeePerKm = routeFeeConfigPerKm
  const routeFeeAmount = hasActiveRouteQuote && typeof activeRouteQuote?.fee_amount === 'number' && Number.isFinite(activeRouteQuote.fee_amount)
    ? activeRouteQuote.fee_amount
    : null
  const routeStraightLineKm = hasActiveRouteQuote && typeof activeRouteQuote?.straight_line_distance_km === 'number' && Number.isFinite(activeRouteQuote.straight_line_distance_km)
    ? activeRouteQuote.straight_line_distance_km
    : null
  const routeSuspicious = Boolean(hasActiveRouteQuote && activeRouteQuote?.suspicious_route)
  const extraMountPayment = saleAndPayment?.extra_mount_payment ?? null
  const technicianEarningMessage = saleAndPayment?.technician_earning_message ?? null
  const extraPaymentAmount = parseNumericInput(routeFeeExtraPaymentInput)
  const canCreateExtraPayment = Boolean(
    selectedTechnician
    && onExtraMountPaymentCreate
    && extraPaymentAmount !== null
    && extraPaymentAmount > 0,
  )
  const selectedTechnicianCoordinateLabel = formatCoordinatePair(
    selectedTechnician?.latitude ?? selectedTechnician?.startLatitude,
    selectedTechnician?.longitude ?? selectedTechnician?.startLongitude,
  )
  const selectedTechnicianMapHref = selectedTechnicianCoordinateLabel !== '-'
    ? `https://www.google.com/maps?q=${selectedTechnicianCoordinateLabel.replace(/\s/g, '')}`
    : null
  const customerCoordinateLabel = formatCoordinatePair(locationInfo?.latitude, locationInfo?.longitude)
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
  const paymentControlMissing = operationControl.payment_checked !== 'yes'
  const doorPhotoControlMissing = !operationControl.door_photos_checked || operationControl.door_photos_checked === 'unreviewed'
  const [operationInfoOpenByRequest, setOperationInfoOpenByRequest] = useState<Record<string, boolean>>({})
  const operationInfoOpen = operationInfoOpenByRequest[request.id] ?? (doorPhotoControlMissing || paymentControlMissing)
  const setOperationInfoOpen = (open: boolean) => {
    setOperationInfoOpenByRequest((current) => ({ ...current, [request.id]: open }))
  }
  const canonicalPaymentStatus = saleAndPayment?.payment_status ?? null
  const canonicalPaymentRequiresPayment = Boolean(canonicalPaymentStatus?.requires_payment && !canonicalPaymentStatus?.is_paid)
  const mountPaymentReceived = Boolean(
    canonicalPaymentStatus?.is_paid
    || saleAndPayment?.mount_payment_received
    || saleAndPayment?.mount_payment_status === 'paid'
    || extraMountPayment?.status === 'paid',
  )
  const mountPaymentStageLabel = displayOrEmpty(
    canonicalPaymentStatus?.stage_label ?? saleAndPayment?.payment_stage_label,
    mountPaymentReceived ? 'Ödeme onaylandı' : canonicalPaymentRequiresPayment ? 'Montaj ödemesi henüz alınmadı' : 'Montaj ödemesi gerekmiyor',
  )
  const mountPaymentAmountLabel = typeof canonicalPaymentStatus?.amount === 'number' && Number.isFinite(canonicalPaymentStatus.amount)
    ? formatMoneyValue(canonicalPaymentStatus.amount)
    : typeof saleAndPayment?.paid_amount === 'number' && Number.isFinite(saleAndPayment.paid_amount)
      ? formatMoneyValue(saleAndPayment.paid_amount)
      : extraMountPayment?.status === 'paid' && typeof extraMountPayment.amount === 'number' && Number.isFinite(extraMountPayment.amount)
        ? formatMoneyValue(extraMountPayment.amount)
        : '-'
  const mountPaymentHeaderLabel = mountPaymentReceived
    ? `Montaj ödeme: ${mountPaymentStageLabel}`
    : canonicalPaymentRequiresPayment
      ? 'Montaj ödeme: Alınmadı'
      : `Montaj ödeme: ${mountPaymentStageLabel}`
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
  const assignmentBlockerMessages = request.assignmentBlockers?.messages ?? []
  const assignmentUiBlockerMessages = [
    operationControl.payment_checked === 'yes' ? null : 'Önce ödeme kontrolünü tamamlayın.',
    operationControl.door_photos_checked === 'compatible' ? null : 'Önce kapı görsellerini uygun olarak işaretleyin.',
  ].filter((message): message is string => Boolean(message))
  const combinedAssignmentBlockerMessages = Array.from(new Set([...assignmentUiBlockerMessages, ...assignmentBlockerMessages]))
  const isAssignmentBlocked = combinedAssignmentBlockerMessages.length > 0
  const assignmentSubmitDisabled = assignLoading
    || !selectedTechnicianId
    || isAssignmentBlocked
    || !mountExclusionAckComplete
    || !canSubmitAssign
    || !onAssignSelectedTechnician
  const resolvedSaleMountLabel = saleAndPayment?.sale_mount_label ?? mikroMountCheck?.montaj_durumu ?? '-'
  const resolvedMountPaymentLabel = mountPaymentReceived
    ? mountPaymentStageLabel
    : canonicalPaymentRequiresPayment
      ? saleAndPayment?.mount_payment_label ?? mountPaymentStageLabel
      : mountPaymentStageLabel
  const paidExtraCustomerAmount = extraMountPayment?.status === 'paid' && typeof extraMountPayment.amount === 'number' && Number.isFinite(extraMountPayment.amount)
    ? extraMountPayment.amount
    : 0
  const totalCustomerCollectedAmount = paymentInfo.customerAmount !== null
    ? roundTwo(paymentInfo.customerAmount + paidExtraCustomerAmount)
    : paidExtraCustomerAmount > 0 ? paidExtraCustomerAmount : null
  const technicianLaborCostLabel = selectedTechnician?.technicianAmountLabel && selectedTechnician.technicianAmountLabel !== 'Belirlenmedi'
    ? selectedTechnician.technicianAmountLabel
    : paymentInfo.technicianAmountLabel && paymentInfo.technicianAmountLabel !== 'Belirlenmedi'
      ? paymentInfo.technicianAmountLabel
      : 'Hakediş ayarı eksik'
  const technicianLaborCostAmount = typeof request.technicianPaymentAmount === 'number' && Number.isFinite(request.technicianPaymentAmount)
    ? request.technicianPaymentAmount
    : paymentInfo.customerAmount
  const travelCostLabel = hasActiveRouteQuote
    ? routeFeeAmount === null && activeRouteQuote?.travel_fee_required
      ? 'Km başı ücret ayarı eksik'
      : formatMoneyValue(routeFeeAmount)
    : 'Hesaplanmadı'
  const totalTechnicianCostAmount = technicianLaborCostAmount !== null
    ? roundTwo(technicianLaborCostAmount + (hasActiveRouteQuote && routeFeeAmount !== null ? routeFeeAmount : 0))
    : null
  const earningTotalAmount = parseNumericInput(earningTotalInput) ?? totalTechnicianCostAmount
  const totalTechnicianCostLabel = totalTechnicianCostAmount !== null
    ? formatMoneyValue(totalTechnicianCostAmount)
    : 'Hakediş ayarı eksik'
  const netProfitLabel = totalCustomerCollectedAmount !== null && earningTotalAmount !== null
    ? formatMoneyValue(totalCustomerCollectedAmount - earningTotalAmount)
    : 'Hesaplanamadı'
  const canSendTechnicianEarning = Boolean(
    selectedTechnician
    && selectedTechnician.phone
    && onTechnicianEarningMessageCreate
    && earningTotalAmount !== null
    && earningTotalAmount >= 0,
  )
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

    const oneWay = hasActiveRouteQuote ? numericInputValue(routeOneWayKm) : ''
    const roundTrip = hasActiveRouteQuote ? numericInputValue(routeRoundTripKm) : ''
    const threshold = numericInputValue(activeRouteQuote?.threshold_km ?? routeFeeConfigThresholdKm)
    const feePerKm = numericInputValue(routeFeePerKm)
    const billable = hasActiveRouteQuote ? numericInputValue(routeBillableKm) : '0'
    const amount = hasActiveRouteQuote ? numericInputValue(routeFeeAmount) : '0'
    const extraPayment = hasActiveRouteQuote ? numericInputValue(routeFeeAmount) : '0'
    const manualTouched = Boolean(activeRouteQuote?.manual_override)
    const note = activeRouteQuote?.manual_note ?? ''

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
    if (!selectedTechnician || !onExtraMountPaymentCreate) {
      setRouteFeeEditorMessage('Önce usta seçin.')

      return
    }

    if (extraPaymentAmount === null || extraPaymentAmount <= 0) {
      setRouteFeeEditorMessage('Ek ödeme tutarı 0 TL ise ödeme linki gerekmez.')

      return
    }

    const selectedSerialIds = invoiceSerials?.selected_serials
      ?.map((serial) => serial.id)
      .filter((id): id is number | string => id !== null && id !== undefined) ?? []

    const payload: ServiceRequestExtraMountPaymentPayload = {
      route_quote_id: activeRouteQuote?.id ?? null,
      technician_id: selectedTechnician.id,
      selected_serial_ids: selectedSerialIds,
      amount: extraPaymentAmount,
      currency: 'TRY',
      reason: 'route_fee',
      note: routeFeeNote.trim() || null,
    }

    await onExtraMountPaymentCreate(payload)
    setRouteFeeEditorMessage('Ödeme linki oluşturuldu.')
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
      route_fee_amount: hasActiveRouteQuote ? routeFeeAmount : 0,
      total_amount: earningTotalAmount,
      note: earningNoteInput.trim() || null,
      message_text: earningMessageText.trim() || null,
      manual_override: parseNumericInput(earningTotalInput) !== null,
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
  const approvalState = technicianApprovalState(request, events)
  const operationSteps: Array<{ title: string, status: OperationStepStatus, message: string }> = [
    operationControl.door_photos_checked === 'compatible'
      ? { title: 'Kapı görselleri kontrolü', status: 'Tamamlandı', message: 'Kapı görselleri uygun işaretlendi.' }
      : { title: 'Kapı görselleri kontrolü', status: 'Engelleyici hata', message: 'Kapı görselleri kontrol edilmedi.' },
    mountPaymentReceived || !canonicalPaymentRequiresPayment || operationControl.payment_checked === 'yes'
      ? { title: 'Montaj ödeme kontrolü', status: 'Tamamlandı', message: mountPaymentReceived ? mountPaymentStageLabel : canonicalPaymentRequiresPayment ? 'Ödeme kontrolü tamamlandı.' : mountPaymentStageLabel }
      : mountExclusionAckRequired
        ? { title: 'Montaj ödeme kontrolü', status: 'Kontrol gerekli', message: 'Montaj hariç çoklu ürün onayı gerekiyor.' }
        : { title: 'Montaj ödeme kontrolü', status: 'Engelleyici hata', message: 'Montaj ödeme durumu net değil.' },
    operationControl.schedule_update_required === 'no' || Boolean(request.scheduledAt || request.scheduledDate)
      ? { title: 'Müşteri/randevu kontrolü', status: 'Tamamlandı', message: 'Randevu kontrolü tamamlandı.' }
      : { title: 'Müşteri/randevu kontrolü', status: 'Bekliyor', message: 'Randevu veya müşteri dönüşü bekliyor.' },
    selectedTechnician
      ? { title: 'Usta seçimi', status: 'Tamamlandı', message: `${selectedTechnician.name} seçildi.` }
      : { title: 'Usta seçimi', status: 'Bekliyor', message: 'Usta seçimi bekliyor.' },
    hasActiveRouteQuote
      ? { title: 'Yol ücreti kontrolü', status: 'Tamamlandı', message: 'Usta seçildi, yol ücreti hesaplandı.' }
      : selectedTechnician
        ? { title: 'Yol ücreti kontrolü', status: 'Kontrol gerekli', message: 'Seçili usta için yol ücreti bekliyor.' }
        : { title: 'Yol ücreti kontrolü', status: 'Bekliyor', message: 'Önce usta seçilmeli.' },
    !assignmentSubmitDisabled
      ? { title: 'Servis atama', status: 'Tamamlandı', message: 'Servis atanabilir.' }
      : { title: 'Servis atama', status: 'Bekliyor', message: combinedAssignmentBlockerMessages[0] ?? (mountExclusionAckRequired && !mountExclusionAckComplete ? 'Montaj hariç çoklu ürün onayı gerekiyor.' : 'Atama koşulları tamamlanmalı.') },
    approvalState.title.toLocaleLowerCase('tr-TR').includes('bek')
      ? { title: 'Usta onayı bekleme', status: 'Bekliyor', message: 'Usta onayı bekleniyor.' }
      : hasAssignedTechnician
        ? { title: 'Usta onayı bekleme', status: 'Tamamlandı', message: approvalState.title }
        : { title: 'Usta onayı bekleme', status: 'Bekliyor', message: 'Servis atanınca takip edilecek.' },
    request.status === 'Tamamlandı'
      ? { title: 'Tamamlama / saha süreci', status: 'Tamamlandı', message: 'Saha süreci tamamlandı.' }
      : { title: 'Tamamlama / saha süreci', status: 'Bekliyor', message: 'Saha tamamlaması bekliyor.' },
  ]
  const sortedEvents = [...events].sort((a, b) => parseEventTimestamp(b) - parseEventTimestamp(a))
  const workflowActions = Object.entries(request.allowedWorkflowActions ?? {})
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
  const checklistEntries = Object.entries(request.checklistPayload ?? {})
  const checklistCompletedCount = checklistEntries.filter(([, checked]) => checked).length
  const checklistTotalCount = checklistEntries.length
  const checklistMissingCount = Math.max(checklistTotalCount - checklistCompletedCount, 0)
  const photoCompletionLabel = `${request.beforePhotoCount ?? 0} / ${request.afterPhotoCount ?? 0} / ${request.generalPhotoCount ?? 0}`
  const scheduledDateLabel = request.scheduledDate
    ? formatTechnicalServiceDate(request.scheduledDate)
    : dateTimeOrEmpty(request.scheduledAt, 'Randevu planlanmadı')
  const scheduledTimeLabel = displayOrEmpty(request.scheduledTime || request.appointment, 'Saat planlanmadı')
  const documentStatusLabel = displayOrEmpty(request.documentStatus, 'Belge yüklenmedi')
  const closureApprovalLabel = displayOrEmpty(request.customerClosureApprovalStatus, 'Kapanış onayı yok')
  const nextActionLabel = displayOrEmpty(request.nextAction, 'Sıradaki aksiyon tanımlı değil')
  const nextActionPayload = request.nextActionPayload
  const nextActionTitle = displayOrEmpty(nextActionPayload?.title, nextActionLabel)
  const nextActionDescription = displayOrEmpty(nextActionPayload?.description, 'Operasyon akışı için sıradaki adım bekleniyor.')
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
  const notesLabel = displayOrEmpty(request.notes, 'Talep notu girilmedi')
  const currentStatusLabel = statusDisplayLabel(request)
  const currentPriorityLabel = priorityDisplayLabel(request.priority)
  const currentPriorityInOptions = PRIORITY_OPTIONS.some((option) => option.value === request.priority)
  const currentSlaLabel = slaStatusLabel(request.slaStatus)
  const slaDueLabel = dateTimeOrEmpty(request.slaDueAt, 'SLA hedefi yok')
  const slaTitle = `${slaStatusDescription(request.slaStatus)}. SLA hedefi: ${slaDueLabel}`
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

    if (action === 'complete') {
      onComplete?.()

      return
    }

    onWorkflowAction?.(action)
  }

  return (
    <Card className="w-full max-w-none min-w-0 border-0 bg-transparent shadow-none break-words">
      <CardContent className="space-y-4 p-0 pb-24 sm:pb-20">
        {error ? (
          <section className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p className="font-semibold">Bazı detay blokları yüklenemedi.</p>
            <p className="mt-1">{error}</p>
          </section>
        ) : null}

        <section className="rounded-3xl border border-slate-200 bg-gradient-to-br from-white via-slate-50 to-blue-50 p-4 text-slate-950 shadow-sm lg:p-5">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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
              <p className="text-[11px] font-semibold uppercase text-slate-400">Sıradaki Aksiyon</p>
              <p className="mt-1 line-clamp-2 text-sm font-semibold">{nextActionLabel}</p>
              <p className="mt-1 text-xs text-slate-300">SLA: {currentSlaLabel} / {slaDueLabel}</p>
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
            <span
              className={['inline-flex h-7 items-center rounded-full border px-2.5 py-1 text-xs font-semibold', slaTone(request.slaStatus)].join(' ')}
              title={slaTitle}
              aria-label={`SLA: ${currentSlaLabel}. ${slaTitle}`}
            >
              SLA: {currentSlaLabel}
            </span>
            {(request.qrSource?.source_channel ?? request.channel) === 'qr_mount_form' ? <Badge variant="outline">QR Montaj Formu</Badge> : null}
            {hasMultiProductRequest ? <Badge variant="warning">Çoklu ürün talebi</Badge> : null}
            {routeFeeNeedsApproval ? <Badge variant="warning">Yol ücreti onayı gerekli</Badge> : null}
          </div>
          <div className="mt-3 grid gap-2 rounded-2xl border border-slate-200/80 bg-white/70 p-3 text-sm sm:grid-cols-3">
            <div>
              <p className="text-[13px] font-medium text-slate-500">Montaj ödeme durumu</p>
              <p className="mt-1 font-semibold text-slate-950">{mountPaymentHeaderLabel}</p>
            </div>
            <div>
              <p className="text-[13px] font-medium text-slate-500">Ödeme tutarı</p>
              <p className="mt-1 font-semibold text-slate-950">{mountPaymentAmountLabel}</p>
            </div>
            <div>
              <p className="text-[13px] font-medium text-slate-500">Çoklu ürün</p>
              <p className="mt-1 font-semibold text-slate-950">
                {hasMultiProductRequest ? mountPaymentReceived ? 'Çoklu ürün ödemesi takipte' : canonicalPaymentRequiresPayment ? 'Ödeme operasyon tarafından netleştirilecek' : mountPaymentStageLabel : 'Yok'}
              </p>
            </div>
          </div>
          {priorityUpdateError ? (
            <p className="mt-2 text-xs font-medium text-rose-700">{priorityUpdateError}</p>
          ) : null}
        </section>

        <section className={['rounded-3xl border p-4 shadow-sm lg:p-5', nextActionTone(nextActionPayload?.severity)].join(' ')}>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-xs font-semibold uppercase tracking-[0.12em] opacity-70">Sıradaki Operasyon Aksiyonu</p>
              <h3 className="mt-1 text-lg font-bold">{nextActionTitle}</h3>
              <p className="mt-1 max-w-3xl text-sm leading-6 opacity-90">{nextActionDescription}</p>
            </div>
            {nextActionPayload?.primary_action ? (
              <Button
                type="button"
                size="sm"
                variant={nextActionPayload.blocking ? 'default' : 'outline'}
                onClick={() => {
                  const action = nextActionPayload.primary_action

                  if (action === 'assign_technician' || action === 'select_technician') {
                    setAssignmentInfoOpen(true)
                    void onAssignSelectedTechnician?.()

                    return
                  }

                  if (action === 'copy_payment_link') {
                    void navigator.clipboard?.writeText(extraMountPayment?.payment_url ?? '')

                    return
                  }

                  if (action === 'create_payment_link') {
                    setRouteFeeEditorOpen(true)
                    setAssignmentInfoOpen(true)

                    return
                  }

                  if (action === 'calculate_route_fee') {
                    void onRouteQuoteCalculate?.()

                    return
                  }

                  if (action === 'review_photos') {
                    setOperationInfoOpen(true)

                    return
                  }

                  if (action === 'acknowledge_mount_exclusion') {
                    setAssignmentInfoOpen(true)

                    return
                  }

                  if (action === 'plan_appointment') {
                    onSchedule?.()
                  }
                }}
              >
                {nextActionPayload.primary_action === 'assign_technician'
                  ? hasAssignedTechnician ? 'Atamayı Güncelle' : 'Servis Ata'
                  : nextActionPayload.primary_action === 'select_technician'
                    ? 'Usta Seç'
                    : nextActionPayload.primary_action === 'review_photos'
                      ? 'Kontrole Git'
                      : nextActionPayload.primary_action === 'create_payment_link'
                        ? 'Ödeme Linki Oluştur'
                        : nextActionPayload.primary_action === 'copy_payment_link'
                          ? 'Linki Kopyala'
                          : nextActionPayload.primary_action === 'plan_appointment'
                            ? 'Randevu Planla'
                            : 'Aksiyonu Aç'}
              </Button>
            ) : null}
          </div>
          <div className="mt-4 flex flex-wrap gap-2">
            {compactControlChips.map((chip) => (
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

        {serialQueryOpen ? (
          <section className="grid gap-3 rounded-3xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-950 lg:p-5">
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

        <DetailPanel
          title="Ürün / Seri Bilgisi"
          summary="Ürün adı, model, marka, seri ve belge numaraları"
          tone="product"
          open={productInfoOpen}
          onOpenChange={setProductInfoOpen}
        >
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <MiniMetric label="Ürün" value={displayOrEmpty(productInfo?.product_name ?? request.product, 'Bilgi yok')} />
            <MiniMetric label="Model" value={displayOrEmpty(productInfo?.product_model ?? request.model, '-')} />
            <MiniMetric label="Seri No" value={displayOrEmpty(productInfo?.serial_number ?? request.serialNumber, '-')} />
            <MiniMetric label="Marka" value={displayOrEmpty(productInfo?.brand, '-')} />
            <MiniMetric label="Aktivasyon Kodu" value={displayOrEmpty(productInfo?.activation_code, '-')} />
            <MiniMetric label="Fatura No" value={displayOrEmpty(documentInfo?.invoice_display_no, '-')} />
            <MiniMetric label="İrsaliye No" value={displayOrEmpty(documentInfo?.dispatch_display_no, '-')} />
            <MiniMetric label="Sipariş No" value={displayOrEmpty(documentInfo?.order_display_no, '-')} />
            <MiniMetric label="Montaj ödeme durumu" value={mountPaymentDetailLabel} />
            <MiniMetric label="Ödeme aşaması" value={mountPaymentStageLabel} />
            <MiniMetric label="Ödeme tutarı" value={mountPaymentAmountLabel} />
            {hasMultiProductRequest ? (
              <MiniMetric label="Çoklu ürün ödeme durumu" value={mountPaymentReceived ? 'Ödeme onaylandı' : canonicalPaymentRequiresPayment ? 'Ödeme operasyon tarafından netleştirilecek' : mountPaymentStageLabel} />
            ) : null}
          </div>
        </DetailPanel>

        <DetailPanel
          title="Müşteri Bilgisi"
          summary="Müşteri, telefon, adres ve paylaşılan konum bilgileri"
          tone="customer"
          open={customerInfoOpen}
          onOpenChange={setCustomerInfoOpen}
        >
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <MiniMetric label="Müşteri" value={displayOrEmpty(request.customer, 'Bilgi yok')} />
            <MiniMetric label="Telefon" value={displayOrEmpty(request.phone, 'Bilgi yok')} />
            <MiniMetric label="İl / İlçe" value={displayOrEmpty([request.city, request.district].filter(Boolean).join(' / '), 'Bilgi yok')} />
            <MiniMetric label="Adres" value={displayOrEmpty(request.address, 'Bilgi yok')} />
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
            <MiniMetric label="Bina / Daire / Kapı / Kat" value={[
              locationInfo?.building_no,
              locationInfo?.apartment_no,
              locationInfo?.door_no,
              locationInfo?.floor_no,
            ].filter(Boolean).join(' / ') || '-'} />
          </div>
        </DetailPanel>

        <DetailPanel
          title="Operasyon ve Montaj Kontrolü"
          summary="Kapı fotoğrafları, ödeme, adres, randevu ve montaj durumu tek yerde"
          tone={doorPhotoControlMissing || paymentControlMissing ? 'warning' : 'slate'}
          open={operationInfoOpen}
          onOpenChange={setOperationInfoOpen}
        >
          <div className="flex flex-wrap items-start justify-between gap-3">
            {routeQuote ? (
              <Badge variant={routeFeeNeedsApproval ? 'warning' : hasActiveRouteQuote ? 'positive' : 'outline'}>
                {routeFeeStatusText}
              </Badge>
            ) : null}
          </div>

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

          {operationControlUpdateError ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
              {operationControlUpdateError}
            </div>
          ) : null}

          <div className="grid gap-4 xl:grid-cols-2">
            <section className="grid gap-3 rounded-2xl border border-slate-200 bg-[#F8FAFD] p-4">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Ödeme / Montaj Bloğu</p>
                <p className="mt-1 text-sm text-slate-600">Ödeme bilgisi ve ödeme kontrolü aynı blokta takip edilir.</p>
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <MiniMetric label="Satış montaj durumu" value={resolvedSaleMountLabel} />
                <MiniMetric
                  label="Montaj ödeme durumu"
                  value={resolvedMountPaymentLabel}
                  hint={saleAndPayment?.mount_payment_status ?? undefined}
                />
                <MiniMetric label="Ödeme referansı" value={<span className="break-all" title={displayOrEmpty(saleAndPayment?.payment_reference, '-')}>{displayOrEmpty(saleAndPayment?.payment_reference, '-')}</span>} />
                <MiniMetric label="Ödeme tarihi" value={dateTimeOrEmpty(saleAndPayment?.paid_at, '-')} />
              </div>
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

          </div>

          <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <Badge variant={statusVariant(request.status)}>Durum: {currentStatusLabel}</Badge>
            <Badge variant="outline">Öncelik: {currentPriorityLabel}</Badge>
            <span className={['inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold', slaTone(request.slaStatus)].join(' ')} title={slaTitle}>
              SLA: {currentSlaLabel}
            </span>
            {hasActiveRouteQuote && activeRouteQuote ? (
              <Badge variant={activeRouteQuote.travel_fee_required ? 'warning' : 'positive'}>
                {activeRouteQuote.travel_fee_required ? 'Yol ücreti onayı gerekli' : 'Yol ücreti yok'}
              </Badge>
            ) : routeQuote ? (
              <Badge variant="warning">{routeQuoteStaleForSelectedTechnician ? 'Yol ücreti hesaplanmadı' : 'Yol ücreti hesaplanamadı'}</Badge>
            ) : null}
            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">
              Mevcut etiketler: {currentPriorityLabel} / {currentSlaLabel}
            </span>
            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">
              Kapı uygunluk durumu: {operationControl.door_photos_checked === 'compatible' ? 'Uyumlu' : operationControl.door_photos_checked === 'incompatible' ? 'Uyumsuz' : 'Kontrol edilmedi'}
            </span>
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
          summary="Usta seçimi, yol ücreti, hakediş ve servis bilgileri"
          tone="technician"
          open={assignmentInfoOpen}
          onOpenChange={setAssignmentInfoOpen}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
              <p className="max-w-3xl text-sm text-slate-600">
                Seçili usta, yol ücreti ve atama aksiyonları aynı akışta takip edilir.
              </p>
              <Button
                type="button"
                variant="outline"
                onClick={() => void onAssignSelectedTechnician?.()}
                disabled={assignmentSubmitDisabled}
                title={isAssignmentBlocked ? combinedAssignmentBlockerMessages.join(' ') : undefined}
              >
                {hasAssignedTechnician ? 'Atamayı Güncelle' : 'Servis Ata'}
              </Button>
            </div>
            {mountPaymentReceived ? (
              <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950">
                <p className="font-semibold">Montaj ödemesi alındı. Servis ataması yapılabilir.</p>
                <p className="mt-1">{mountPaymentStageLabel}{mountPaymentAmountLabel !== '-' ? ` · Alınan ödeme: ${mountPaymentAmountLabel}` : ''}</p>
              </div>
            ) : null}
            {mountExclusionAckRequired ? (
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
            {(openAppointmentProposals.length > 0 || jobRejections.length > 0 || supportRequests.length > 0 || completionSubmissions.length > 0 || assignmentOffer) ? (
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
                            const legacyLabel = String(slot.slot_label ?? '')
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
                {completionSubmissions.slice(0, 2).map((action) => (
                  <div key={String(action.id)} className="grid gap-2 rounded-xl border border-violet-100 bg-violet-50 p-3 text-violet-950">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <p className="font-semibold">Son kontrol bekliyor</p>
                        <p className="mt-1 text-xs">{String(action.note ?? 'Usta tamamlama gönderdi; core workflow operasyon onayı bekliyor.')}</p>
                      </div>
                      <Badge variant="warning">Tamamlama onayı</Badge>
                    </div>
                    <label className="grid gap-1 text-xs font-semibold text-violet-900">
                      Son kontrol notu
                      <Input value={completionReviewNote} onChange={(event) => setCompletionReviewNote(event.target.value)} placeholder="Operasyon son kontrol notu" />
                    </label>
                    <div className="flex justify-end">
                      <Button type="button" variant="outline" onClick={() => void onPartnerCompletionApprove?.(action.id, { note: completionReviewNote || null })}>
                        Core workflow ile tamamla
                      </Button>
                    </div>
                  </div>
                ))}
                {assignmentOffer ? (
                  <div className="grid gap-3 rounded-xl border border-emerald-100 bg-emerald-50 p-3 text-emerald-950">
                    <div className="grid gap-2 sm:grid-cols-4">
                      <MiniMetric label="İşçilik" value={formatMoneyValue(assignmentOffer.labor_amount)} />
                      <MiniMetric label="Yol" value={formatMoneyValue(assignmentOffer.route_fee_amount)} />
                      <MiniMetric label="Toplam" value={formatMoneyValue(assignmentOffer.total_amount)} />
                      <MiniMetric label="Durum" value={assignmentOffer.status} />
                    </div>
                    <div className="grid gap-2 sm:grid-cols-[140px_140px_minmax(0,1fr)]">
                      <Input type="number" min="0" step="0.01" value={offerLaborInput} onChange={(event) => setOfferLaborInput(event.target.value)} placeholder={String(assignmentOffer.labor_amount)} />
                      <Input type="number" min="0" step="0.01" value={offerRouteInput} onChange={(event) => setOfferRouteInput(event.target.value)} placeholder={String(assignmentOffer.route_fee_amount)} />
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
              </div>
            ) : null}
            <div className="grid gap-3 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
              <div className="grid gap-3 rounded-2xl border border-slate-200 bg-[#F8FAFD] p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-slate-900">Mesafe ve önceliğe göre önerilen ustalar</p>
                    <p className="mt-1 max-w-3xl truncate text-xs text-slate-500" title={customerOpenAddress || undefined}>
                      Müşteri açık adresi: {displayOrEmpty(customerOpenAddress, 'Bilgi yok')}
                    </p>
                  </div>
                  <Badge variant="outline">{technicianSuggestions.length > 0 ? `${technicianSuggestions.length} öneri` : 'Öneri yok'}</Badge>
                </div>
                {technicianSuggestions.length > 0 ? (
                  <div className="grid gap-2">
                    {technicianSuggestions.map((technician) => {
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
                          <p className="mt-1 truncate text-xs text-slate-500" title={technician.addressSummary ?? undefined}>
                            Adres: {displayOrEmpty(technician.addressSummary ?? '', 'Bilgi yok')}
                          </p>
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
                              onTechnicianSelect?.(technician.id, technician.estimatedRoundTripKm ?? null)
                            }}
                          >
                            {selected ? 'Seçildi' : 'Seç'}
                          </Button>
                        </div>
                      </div>
                      )
                    })}
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
                  <p className="font-semibold">Yol ücreti</p>
                  <div className="flex flex-wrap gap-2">
                    <Badge variant={routeFeeNeedsApproval ? 'warning' : hasActiveRouteQuote ? 'positive' : 'outline'}>{routeFeeStatusText}</Badge>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => void onRouteQuoteCalculate?.()}
                      disabled={routeQuoteLoading || !selectedTechnicianId || !onRouteQuoteCalculate}
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
                      Yol ücreti / fiyat düzenle
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
                {routeQuoteLoading ? (
                  <div className="rounded-xl border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-900">
                    Yol ücreti hesaplanıyor...
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
                  <MiniMetric label="Google Routes tek yön mesafesi" value={formatKmValue(routeOneWayKm)} hint={hasActiveRouteQuote && activeRouteQuote?.duration_text ? `Tahmini süre: ${activeRouteQuote.duration_text}` : 'Yol hesabı yapılınca gösterilir.'} />
                  <MiniMetric label="Gidiş-geliş mesafe" value={formatKmValue(routeRoundTripKm)} hint={hasActiveRouteQuote ? undefined : 'Yol hesabı sonucu yok.'} />
                  <MiniMetric
                    label="Tahmini yol ücreti"
                    value={hasActiveRouteQuote ? routeFeeAmount === null && activeRouteQuote?.travel_fee_required ? 'Km başı ücret ayarı eksik' : formatMoneyValue(routeFeeAmount) : '-'}
                    hint={hasActiveRouteQuote && activeRouteQuote ? routeQuoteMessage(activeRouteQuote.message) : routeFeeNotCalculatedHint}
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
                {routeFeeEditorOpen ? (
                  <div className="grid gap-3 rounded-2xl border border-blue-200 bg-white p-3 text-sm text-slate-700">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <p className="font-semibold text-slate-950">Yol ücreti / fiyat düzenle</p>
                        <p className="mt-1 text-xs text-slate-500">Bu panel hesaplanan yol bilgisini operasyon notuyla birlikte gözden geçirmek içindir.</p>
                      </div>
                      <Button type="button" size="sm" variant="ghost" onClick={() => setRouteFeeEditorOpen(false)}>
                        İptal
                      </Button>
                    </div>
                    {!selectedTechnician ? (
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
                        Yol ücreti tutarı
                        <Input type="number" min="0" step="0.01" value={routeFeeAmountInput} onChange={(event) => handleRouteFeeAmountChange(event.target.value)} />
                      </label>
                      <label className="grid gap-1 text-xs font-semibold text-slate-600">
                        Müşteriden istenecek ek ödeme tutarı
                        <Input type="number" min="0" step="0.01" value={routeFeeExtraPaymentInput} onChange={(event) => setRouteFeeExtraPaymentInput(event.target.value)} />
                      </label>
                    </div>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Not
                      <textarea
                        value={routeFeeNote}
                        onChange={(event) => setRouteFeeNote(event.target.value)}
                        className="min-h-[84px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                        placeholder="Yol ücreti veya müşteri onayı için operasyon notu"
                      />
                    </label>
                    {extraMountPayment?.payment_url ? (
                      <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-950">
                        <p className="font-semibold">
                          {extraMountPayment.status === 'paid' ? 'Ödeme onaylandı' : 'Ödeme linki oluşturuldu'}
                        </p>
                        <p className="mt-1 break-all">{extraMountPayment.payment_url}</p>
                        <div className="mt-2 flex flex-wrap gap-2">
                          <Button type="button" size="sm" variant="outline" onClick={() => void navigator.clipboard?.writeText(extraMountPayment.payment_url ?? '')}>
                            Linki kopyala
                          </Button>
                          <Button asChild type="button" size="sm" variant="outline">
                            <a href={`${whatsappHref || '#'}${whatsappHref ? `?text=${encodeURIComponent(`Ek montaj ödemeniz için link: ${extraMountPayment.payment_url}`)}` : ''}`} target="_blank" rel="noreferrer">
                              WhatsApp ile gönder
                            </a>
                          </Button>
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
                        {extraPaymentCreateLoading ? 'Link oluşturuluyor...' : 'Ödeme linki oluştur'}
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
            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-3">
              <div>
                <p className="text-sm font-semibold text-slate-950">Hakediş / Maliyet Özeti</p>
                <p className="mt-1 text-xs text-slate-500">Müşteri tahsilatı ve ustaya gönderilecek hakediş ayrı izlenir.</p>
              </div>
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <MiniMetric label="Müşteriden alınan montaj ücreti" value={mountPaymentLabel} />
                <MiniMetric label="Müşteriden alınan ek ödeme" value={paidExtraCustomerAmount > 0 ? formatMoneyValue(paidExtraCustomerAmount) : 'Yok'} />
                <MiniMetric label="Toplam müşteri tahsilatı" value={totalCustomerCollectedAmount !== null ? formatMoneyValue(totalCustomerCollectedAmount) : 'Belirlenmedi'} />
                <MiniMetric label="Montaj ödeme durumu" value={resolvedMountPaymentLabel} />
                <MiniMetric label="Usta işçilik hakedişi" value={technicianLaborCostLabel} />
                <MiniMetric label="Usta yol ücreti" value={travelCostLabel} />
                <MiniMetric label="Ustaya gönderilecek toplam hakediş" value={earningTotalAmount !== null ? formatMoneyValue(earningTotalAmount) : totalTechnicianCostLabel} />
                <MiniMetric label="Net fark / kâr" value={netProfitLabel} />
                <MiniMetric
                  label="Hakediş durumu"
                  value={technicianEarningMessage?.status === 'sent' ? 'Hakediş bilgisi gönderildi' : 'Hakediş bilgisi gönderilmedi'}
                  hint={technicianEarningMessage?.sent_at ? dateTimeOrEmpty(technicianEarningMessage.sent_at, '-') : undefined}
                />
              </div>
              {technicianEarningMessageError ? (
                <div className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                  {technicianEarningMessageError}
                </div>
              ) : null}
              <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div className="grid gap-3 sm:grid-cols-[220px_minmax(0,1fr)]">
                  <label className="grid gap-1 text-xs font-semibold text-slate-600">
                    Toplam hakediş
                    <Input
                      type="number"
                      min="0"
                      step="0.01"
                      value={earningTotalInput}
                      onChange={(event) => setEarningTotalInput(event.target.value)}
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
                {earningMessageText || technicianEarningMessage?.message_text ? (
                  <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-950">
                    <p className="font-semibold">Hakediş mesajı</p>
                    <pre className="mt-2 whitespace-pre-wrap break-words font-sans">{earningMessageText || technicianEarningMessage?.message_text}</pre>
                    <div className="mt-2 flex flex-wrap gap-2">
                      <Button type="button" size="sm" variant="outline" onClick={() => void navigator.clipboard?.writeText(earningMessageText || technicianEarningMessage?.message_text || '')}>
                        Mesajı kopyala
                      </Button>
                      {(earningMessageUrl || selectedTechnician?.phone) ? (
                        <Button asChild type="button" size="sm" variant="outline">
                          <a href={earningMessageUrl || whatsappHrefForPhone(selectedTechnician?.phone)} target="_blank" rel="noreferrer">
                            WhatsApp Aç
                          </a>
                        </Button>
                      ) : null}
                    </div>
                  </div>
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
            </div>
            {combinedAssignmentBlockerMessages.length > 0 ? (
              <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                <p className="font-semibold">Atama için tamamlanması gerekenler</p>
                <ul className="mt-2 list-disc space-y-1 pl-5">
                  {combinedAssignmentBlockerMessages.map((message) => (
                    <li key={message}>{message}</li>
                  ))}
                </ul>
              </div>
            ) : null}
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
            <div className="grid gap-3 sm:grid-cols-2">
              <MiniMetric label="Atanan servis" value={hasAssignedTechnician ? displayOrEmpty(request.technician, 'Bilgi yok') : 'Atanmadı'} />
              <MiniMetric label="Servis telefonu" value={hasAssignedTechnician ? displayOrEmpty(request.technicianPhone, 'Bilgi yok') : 'Bilgi yok'} />
              <MiniMetric label="Şehir" value={hasAssignedTechnician ? displayOrEmpty(selectedTechnician?.location, request.city || 'Bilgi yok') : 'Bilgi yok'} />
              <MiniMetric label="Yol ücreti durumu" value={routeFeeStatusText} />
              <MiniMetric label="Tahmini yol ücreti" value={travelCostLabel} />
              {hasAssignedTechnician && approvalState.title.toLocaleLowerCase('tr-TR').includes('bek') ? (
                <div className="sm:col-span-2">
                  <Badge variant="warning">Usta onayı bekleniyor</Badge>
                </div>
              ) : null}
              {hasAssignedTechnician ? (
                <>
                  <MiniMetric label="Servis onay durumu" value={approvalState.title} hint={approvalState.detail ?? undefined} />
                  <MiniMetric label="Kabul / red" value={approvalState.title} />
                  <MiniMetric label="Destek talebi" value={displayOrEmpty(request.technicianRevisionNote || request.pendingReason, 'Bilgi yok')} />
                  <MiniMetric label="Yedek parça" value={displayOrEmpty(request.pendingReason, 'Bilgi yok')} />
                  <MiniMetric label="Fiyat değişikliği" value={displayOrEmpty(request.technicianRevisionNote, 'Bilgi yok')} />
                  <MiniMetric label="Tekrar ziyaret" value={request.requiresSecondVisit ? 'Evet' : 'Hayır'} hint={displayOrEmpty(request.secondVisitReason, 'Bilgi yok')} />
                </>
              ) : (
                <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600 sm:col-span-2">
                  Servis atanınca onay, destek, yedek parça ve fiyat talepleri burada görünür.
                </div>
              )}
            </div>
          </DetailPanel>

          <DetailPanel
            title="İşlem Geçmişi / Notlar"
            summary="Operasyon onayı, karar alanı, not ve yorum özeti"
            tone="history"
            open={finalCheckOpen}
            onOpenChange={setFinalCheckOpen}
          >
            <div className="flex flex-wrap items-start justify-between gap-3">
              <p className="text-sm text-slate-600">Son kontrol kararları ve operasyon notları burada özetlenir.</p>
              <Button
                type="button"
                variant="outline"
                className="border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 hover:text-rose-800"
                onClick={() => onComplete?.()}
                disabled={isActionDisabled}
              >
                İşi İptal Et
              </Button>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <MiniMetric label="Operasyon onayı" value={closureApprovalLabel} hint={dateTimeOrEmpty(request.customerClosureApprovedAt, 'Bekliyor')} />
              <MiniMetric label="Tamamlandı / İnceleniyor / İptal" value={currentStatusLabel} />
              <MiniMetric label="Not / yorum alanı" value={notesLabel} />
              <MiniMetric label="İşlem geçmişi" value={(request.auditLogs ?? []).length > 0 || sortedEvents.length > 0 ? 'Var' : 'Yok'} hint={`${(request.auditLogs ?? []).length} audit / ${sortedEvents.length} olay`} />
              <MiniMetric label="Bekleme nedeni" value={displayOrEmpty(request.pendingReason, 'Bilgi yok')} />
              <MiniMetric label="İptal nedeni" value={displayOrEmpty(request.cancellationReason, 'Bilgi yok')} />
            </div>
          </DetailPanel>
        <DetailPanel
          title="Faturadaki diğer serileri gör"
          summary={invoiceSerials?.check_error ? 'Fatura seri kontrolü bekliyor' : 'Talep edilen, gizlenen ve iade seri hareketleri'}
          tone="serial"
          open={invoiceSerialsOpen}
          onOpenChange={setInvoiceSerialsOpen}
        >
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-sm text-slate-600">Fatura seri sorgusu operasyon için yenilenebilir; müşteriye gizli satırlar gösterilmez.</p>
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
                {invoiceSerialRecheckInFlight ? 'Kontrol ediliyor...' : 'Tekrar kontrol et'}
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
          <InvoiceSerialSection title="Talep edilen seriler" items={invoiceSerials?.selected_serials} onAdd={onInvoiceSerialAdd} onRemove={onInvoiceSerialRemove} actionInFlight={invoiceSerialActionInFlight} />
          <InvoiceSerialSection title="Aynı faturadaki diğer seriler" items={invoiceSerials?.other_serials} onAdd={onInvoiceSerialAdd} onRemove={onInvoiceSerialRemove} actionInFlight={invoiceSerialActionInFlight} />
          <InvoiceSerialSection title="Müşteriye gösterilmeyen seriler" items={invoiceSerials?.hidden_serials} onAdd={onInvoiceSerialAdd} onRemove={onInvoiceSerialRemove} actionInFlight={invoiceSerialActionInFlight} />
          <InvoiceSerialSection title="İade gelen seriler" items={invoiceSerials?.returned_serials} onAdd={onInvoiceSerialAdd} onRemove={onInvoiceSerialRemove} actionInFlight={invoiceSerialActionInFlight} />
          {!(invoiceSerials?.all_invoice_serials?.length) && !invoiceSerials?.check_error ? (
            <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
              Fatura seri hareketi henüz kaydedilmedi.
            </div>
          ) : null}
        </DetailPanel>

        <DetailPanel
          title="Saha Tamamlama Belgeleri"
          summary="Fotoğraf, garanti kartı, usta açıklaması ve checklist"
          tone="door"
          open={fieldCompletionOpen}
          onOpenChange={setFieldCompletionOpen}
        >
          <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 lg:p-5">
            <div className="grid gap-3 sm:grid-cols-2">
              <MiniMetric label="Montaj fotoğrafları" value={photoCompletionLabel} hint="Öncesi / sonrası / genel" />
              <MiniMetric label="Garanti kartı" value={documentStatusLabel} hint={warranty?.status ?? 'Kontrol edilmedi'} />
              <MiniMetric label="Usta açıklaması" value={displayOrEmpty(request.fieldCompletionNote, 'Bilgi yok')} />
              <MiniMetric label="Eksik / hatalı evrak" value={displayOrEmpty(request.completionBlockReason || request.incompleteReason, 'Yok')} />
              <MiniMetric
                label="Checklist"
                value={checklistTotalCount > 0 ? `${checklistCompletedCount}/${checklistTotalCount} tamam` : 'Yüklenmedi'}
                hint={checklistTotalCount > 0 ? `${checklistMissingCount} eksik adım` : 'Bilgi yok'}
              />
            </div>
            {checklistEntries.length > 0 ? (
              <div className="grid gap-2">
                {checklistEntries.map((item) => (
                  <div key={item.key} className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                    <span className="text-slate-700">{item.label}</span>
                    <Badge variant={item.completed ? 'secondary' : 'outline'}>
                      {item.completed ? 'Tamam' : 'Bekliyor'}
                    </Badge>
                  </div>
                ))}
              </div>
            ) : null}
          </section>
        </DetailPanel>

        <DetailPanel title="İşlem Geçmişi" summary="Audit kayıtları ve durum akışı" tone="history">
          <section className="rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">İşlem Kayıtları</p>
            <div className="mt-4 space-y-3">
              {(request.auditLogs ?? []).length === 0 ? (
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                  Audit kaydı bulunmuyor.
                </div>
              ) : (
                (request.auditLogs ?? []).map((log) => (
                  <div key={String(log.id)} className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="text-sm font-semibold text-slate-900">{log.action_type}</p>
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
                      <p className="font-semibold text-slate-900">{event.title}</p>
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

      </CardContent>

      <div
        className="sticky bottom-0 z-10 mt-2 flex justify-end bg-transparent px-2 py-2"
        style={{ paddingBottom: 'calc(0.5rem + env(safe-area-inset-bottom))' }}
      >
        <div className="grid max-w-full gap-2 rounded-2xl border border-slate-200 bg-white/95 p-2 shadow-[0_-10px_30px_rgba(15,23,42,0.08)] backdrop-blur sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-center lg:justify-end">
          {footerWorkflowActions.map(([actionKey, action]) => (
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
          <Button
            asChild
            className="h-9 w-full text-[0.72rem] sm:text-sm lg:w-auto"
            variant="secondary"
            disabled={!whatsappHref}
          >
            <a href={whatsappHref || '#'} target="_blank" rel="noreferrer">WhatsApp Aç</a>
          </Button>
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
    </Card>
  )
}

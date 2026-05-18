import { ChevronDown } from 'lucide-react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import type { MikroMountCheckResult, ServicePriority, ServiceRequest, ServiceRequestEvent, ServiceRequestInvoiceSerial, ServiceRequestRouteQuoteManualPayload, WarrantySerialResponse } from './types'
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
  assignLoading?: boolean
  canSubmitAssign?: boolean
  onTechnicianSelect?: (technicianId: string, estimatedRoundTripKm?: number | null) => void
  onRouteQuoteCalculate?: () => void | Promise<void>
  onRouteQuoteManualSave?: (payload: ServiceRequestRouteQuoteManualPayload) => void | Promise<void>
  onAssignSelectedTechnician?: () => void | Promise<void>
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

const routeQuoteMessage = (message: string | null | undefined): string => {
  if (message === 'Usta konumu eksik.') {
    return 'Usta konumu eksik. Yol ücreti hesaplanamadı.'
  }

  if (message === 'Müşteri konumu eksik.') {
    return 'Müşteri konumu eksik. Yol ücreti hesaplanamadı.'
  }

  return displayOrEmpty(message, 'Yol ücreti hesaplanamadı')
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
  <div className="min-w-0 rounded-2xl border border-slate-200 bg-[#F8FAFD] p-3 lg:p-3.5">
    <p className="text-[11px] font-medium text-slate-500">{label}</p>
    <div className="mt-1 text-sm font-semibold text-slate-950 break-words">{value}</div>
    {hint ? <div className="mt-1 text-xs leading-5 text-slate-500 break-words">{hint}</div> : null}
  </div>
)

const DetailPanel = ({
  title,
  summary,
  children,
  open,
  onOpenChange,
}: {
  title: string
  summary?: ReactNode
  children: ReactNode
  open?: boolean
  onOpenChange?: (open: boolean) => void
}) => (
  <details
    className="group rounded-2xl border border-slate-200 bg-slate-50 p-4"
    open={open}
    onToggle={(event) => onOpenChange?.(event.currentTarget.open)}
  >
    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-left">
      <span className="min-w-0">
        <span className="block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{title}</span>
        {summary ? <span className="mt-1 block text-sm text-slate-600">{summary}</span> : null}
      </span>
      <ChevronDown className="h-4 w-4 shrink-0 text-slate-500 transition-transform group-open:rotate-180" />
    </summary>
    <div className="mt-4 grid gap-4">
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
      <MiniMetric label="Stok kodu" value={displayOrEmpty(serial.stock_code, '-')} />
      <MiniMetric label="Durum etiketi" value={serialToneLabel(serial)} />
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
  assignLoading = false,
  canSubmitAssign = false,
  onTechnicianSelect,
  onRouteQuoteCalculate,
  onRouteQuoteManualSave,
  onAssignSelectedTechnician,
}: ServiceRequestDetailsProps) {
  const paymentInfo = getServicePaymentInfo(
    request.serviceType,
    request.travelRoundTripKm,
    request.travelFeeAmount,
    request.travelBillableKm,
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
  const [routeFeeManualAmountTouched, setRouteFeeManualAmountTouched] = useState(false)
  const [routeFeeEditorInitialSnapshot, setRouteFeeEditorInitialSnapshot] = useState('')
  const [differentAddressInfoOpen, setDifferentAddressInfoOpen] = useState(false)
  const locationInfo = request.location ?? null
  const doorPhotos = request.doorPhotos ?? []
  const routeQuote = request.routeQuote ?? null
  const selectedTechnician = technicianSuggestions.find((technician) => technician.id === selectedTechnicianId) ?? null
  const selectedTechnicianIdString = selectedTechnicianId ? String(selectedTechnicianId) : null
  const routeQuoteTechnicianIdString = routeQuote?.technician_id !== null && routeQuote?.technician_id !== undefined
    ? String(routeQuote.technician_id)
    : null
  const routeQuoteMatchesSelectedTechnician = Boolean(routeQuote && selectedTechnicianIdString && routeQuoteTechnicianIdString === selectedTechnicianIdString)
  const routeQuoteStaleForSelectedTechnician = Boolean(routeQuote && selectedTechnicianIdString && routeQuoteTechnicianIdString && routeQuoteTechnicianIdString !== selectedTechnicianIdString)
  const hasAssignmentChange = Boolean(selectedTechnicianId && selectedTechnicianId !== String(request.technicianId ?? ''))
  const hasMultiProductRequest = Boolean(invoiceSerials?.has_multi_product || (invoiceSerials?.selected_serials?.length ?? 0) > 1 || saleAndPayment?.mount_payment_status === 'skipped_multi_product')
  const hasCalculatedRouteQuote = routeQuote?.status === 'calculated' && routeQuoteMatchesSelectedTechnician
  const routeFeeNeedsApproval = hasCalculatedRouteQuote && routeQuote.travel_fee_required
  const routeFeeStatusText = routeQuoteStaleForSelectedTechnician
    ? 'Bu usta için yol hesabı yapılmadı'
    : hasCalculatedRouteQuote
    ? routeQuote.travel_fee_required ? 'Yol ücreti onayı gerekli' : 'Yol ücreti yok'
    : routeQuote ? 'Yol ücreti hesaplanamadı' : 'Yol ücreti hesaplanmadı'
  const routeRoundTripKm = hasCalculatedRouteQuote
    ? typeof routeQuote?.round_trip_distance_km === 'number' && Number.isFinite(routeQuote.round_trip_distance_km)
      ? routeQuote.round_trip_distance_km
      : typeof routeQuote?.distance_km === 'number' && Number.isFinite(routeQuote.distance_km)
        ? routeQuote.distance_km
        : null
    : null
  const routeOneWayKm = hasCalculatedRouteQuote
    ? typeof routeQuote?.one_way_distance_km === 'number' && Number.isFinite(routeQuote.one_way_distance_km)
      ? routeQuote.one_way_distance_km
      : routeRoundTripKm !== null
        ? roundTwo(routeRoundTripKm / 2)
        : null
    : null
  const routeBillableKm = hasCalculatedRouteQuote
    ? typeof routeQuote?.billable_km === 'number' && Number.isFinite(routeQuote.billable_km)
      ? routeQuote.billable_km
      : typeof routeQuote?.extra_km === 'number' && Number.isFinite(routeQuote.extra_km)
        ? routeQuote.extra_km
        : null
    : null
  const routeFeePerKm = hasCalculatedRouteQuote && typeof routeQuote?.fee_per_km === 'number' && Number.isFinite(routeQuote.fee_per_km)
    ? routeQuote.fee_per_km
    : null
  const routeFeeAmount = hasCalculatedRouteQuote && typeof routeQuote?.fee_amount === 'number' && Number.isFinite(routeQuote.fee_amount)
    ? routeQuote.fee_amount
    : null
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
    manualAmountTouched: routeFeeManualAmountTouched,
    note: routeFeeNote.trim(),
  })
  const routeFeeEditorHasChanges = routeFeeEditorInitialSnapshot !== '' && routeFeeEditorCurrentSnapshot !== routeFeeEditorInitialSnapshot
  const operationControl = request.operationControl ?? {}
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
    || !canSubmitAssign
    || !onAssignSelectedTechnician

  const resolvedSaleMountLabel = saleAndPayment?.sale_mount_label ?? mikroMountCheck?.montaj_durumu ?? '-'
  const resolvedMountPaymentLabel = saleAndPayment?.mount_payment_label ?? mountPaymentLabel
  const technicianLaborCostLabel = selectedTechnician?.technicianAmountLabel && selectedTechnician.technicianAmountLabel !== 'Belirlenmedi'
    ? selectedTechnician.technicianAmountLabel
    : paymentInfo.technicianAmountLabel && paymentInfo.technicianAmountLabel !== 'Belirlenmedi'
      ? paymentInfo.technicianAmountLabel
      : 'Hakediş ayarı eksik'
  const travelCostLabel = hasCalculatedRouteQuote
    ? routeFeeAmount === null && routeQuote?.travel_fee_required
      ? 'Km başı ücret ayarı eksik'
      : formatMoneyValue(routeFeeAmount)
    : 'Yol ücreti hesaplanamadı'
  const totalTechnicianCostLabel = paymentInfo.totalTechnicianCostLabel && paymentInfo.totalTechnicianCostLabel !== 'Belirlenmedi'
    ? paymentInfo.totalTechnicianCostLabel
    : 'Hakediş ayarı eksik'
  const netProfitLabel = paymentInfo.customerAmount !== null && paymentInfo.totalTechnicianCostAmount !== null
    ? formatMoneyValue(paymentInfo.customerAmount - paymentInfo.totalTechnicianCostAmount)
    : 'Hesaplanamadı'
  const routeFeeEditorSnapshot = (
    oneWay: string,
    roundTrip: string,
    threshold: string,
    feePerKm: string,
    billable: string,
    amount: string,
    manualAmountTouched: boolean,
    note: string,
  ) => JSON.stringify({
    oneWay,
    roundTrip,
    threshold,
    feePerKm,
    billable,
    amount,
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

    const oneWay = numericInputValue(routeOneWayKm)
    const roundTrip = numericInputValue(routeRoundTripKm)
    const threshold = numericInputValue(routeQuote?.threshold_km ?? 30)
    const feePerKm = numericInputValue(routeFeePerKm)
    const billable = numericInputValue(routeBillableKm)
    const amount = numericInputValue(routeFeeAmount)
    const manualTouched = Boolean(routeQuote?.manual_override)
    const note = routeQuote?.manual_note ?? ''

    setRouteFeeOneWayKmInput(oneWay)
    setRouteFeeRoundTripKmInput(roundTrip)
    setRouteFeeThresholdKmInput(threshold)
    setRouteFeePerKmInput(feePerKm)
    setRouteFeeBillableKmInput(billable)
    setRouteFeeAmountInput(amount)
    setRouteFeeManualAmountTouched(manualTouched)
    setRouteFeeNote(note)
    setRouteFeeEditorInitialSnapshot(routeFeeEditorSnapshot(oneWay, roundTrip, threshold, feePerKm, billable, amount, manualTouched, note))
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
  const operationControlChange = <K extends keyof NonNullable<ServiceRequest['operationControl']>>(
    key: K,
    value: NonNullable<ServiceRequest['operationControl']>[K],
  ) => {
    void onOperationControlChange?.({ [key]: value } as Partial<NonNullable<ServiceRequest['operationControl']>>)
  }
  const whatsappHref = whatsappHrefForPhone(request.phone)
  const approvalState = technicianApprovalState(request, events)
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
  const notesLabel = displayOrEmpty(request.notes, 'Talep notu girilmedi')
  const currentStatusLabel = statusDisplayLabel(request)
  const currentPriorityLabel = priorityDisplayLabel(request.priority)
  const currentPriorityInOptions = PRIORITY_OPTIONS.some((option) => option.value === request.priority)
  const currentSlaLabel = slaStatusLabel(request.slaStatus)
  const slaDueLabel = dateTimeOrEmpty(request.slaDueAt, 'SLA hedefi yok')
  const slaTitle = `${slaStatusDescription(request.slaStatus)}. SLA hedefi: ${slaDueLabel}`
  const handleWorkflowAction = (action: string) => {
    if (action === 'assign_technician') {
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
          {priorityUpdateError ? (
            <p className="mt-2 text-xs font-medium text-rose-700">{priorityUpdateError}</p>
          ) : null}
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

        <section className="grid gap-3 rounded-3xl border border-slate-200 bg-white p-4 lg:p-5">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Adres / Ürün</p>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <MiniMetric label="İl / İlçe" value={displayOrEmpty([request.city, request.district].filter(Boolean).join(' / '), 'Bilgi yok')} />
            <MiniMetric label="Adres" value={displayOrEmpty(request.address, 'Bilgi yok')} />
            <MiniMetric label="Ürün" value={displayOrEmpty(productInfo?.product_name ?? request.product, 'Bilgi yok')} />
            <MiniMetric label="Model" value={displayOrEmpty(productInfo?.product_model ?? request.model, '-')} />
            <MiniMetric label="Seri No" value={displayOrEmpty(productInfo?.serial_number ?? request.serialNumber, '-')} />
            <MiniMetric label="Marka" value={displayOrEmpty(productInfo?.brand, '-')} />
            <MiniMetric label="Stok Kodu" value={displayOrEmpty(productInfo?.stock_code, '-')} />
            <MiniMetric label="Aktivasyon Kodu" value={displayOrEmpty(productInfo?.activation_code, '-')} />
            <MiniMetric label="Fatura No" value={displayOrEmpty(documentInfo?.invoice_display_no, '-')} />
            <MiniMetric label="İrsaliye No" value={displayOrEmpty(documentInfo?.dispatch_display_no, '-')} />
            <MiniMetric label="Sipariş No" value={displayOrEmpty(documentInfo?.order_display_no, '-')} />
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
        </section>

        <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-4 lg:p-5">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Operasyon ve Montaj Kontrolü</p>
              <p className="mt-1 text-sm text-slate-600">Atama öncesi ödeme, adres, kapı görseli, randevu ve montaj durumu tek yerden kontrol edilir.</p>
            </div>
            {routeQuote ? (
              <Badge variant={routeFeeNeedsApproval ? 'warning' : hasCalculatedRouteQuote ? 'positive' : 'outline'}>
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

            <section className="grid gap-3 rounded-2xl border border-slate-200 bg-[#F8FAFD] p-4">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Ek Operasyon Kontrolleri</p>
                <p className="mt-1 text-sm text-slate-600">Eksik bilgi ve müşteri arama kararları aynı alanda tutulur.</p>
              </div>
              <OperationControlRow
                label="Eksik bilgi var mı?"
                value={operationControl.missing_info ?? 'unreviewed'}
                options={[
                  { value: 'no', label: 'Yok', tone: 'positive' },
                  { value: 'yes', label: 'Var', tone: 'problem' },
                  { value: 'unreviewed', label: 'Kontrol edilmedi', tone: 'neutral' },
                ]}
                disabled={operationControlUpdateInFlight}
                onChange={(value) => operationControlChange('missing_info', value as 'yes' | 'no' | 'unreviewed')}
              />
              <OperationControlRow
                label="Müşteri aranacak mı?"
                value={operationControl.customer_call_required ?? 'unreviewed'}
                options={[
                  { value: 'no', label: 'Hayır', tone: 'positive' },
                  { value: 'yes', label: 'Evet', tone: 'problem' },
                  { value: 'unreviewed', label: 'Kontrol edilmedi', tone: 'neutral' },
                ]}
                disabled={operationControlUpdateInFlight}
                onChange={(value) => operationControlChange('customer_call_required', value as 'yes' | 'no' | 'unreviewed')}
              />
            </section>
          </div>

          <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <Badge variant={statusVariant(request.status)}>Durum: {currentStatusLabel}</Badge>
            <Badge variant="outline">Öncelik: {currentPriorityLabel}</Badge>
            <span className={['inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold', slaTone(request.slaStatus)].join(' ')} title={slaTitle}>
              SLA: {currentSlaLabel}
            </span>
            {hasCalculatedRouteQuote ? (
              <Badge variant={routeQuote.travel_fee_required ? 'warning' : 'positive'}>
                {routeQuote.travel_fee_required ? 'Yol ücreti onayı gerekli' : 'Yol ücreti yok'}
              </Badge>
            ) : routeQuote ? (
              <Badge variant="warning">{routeQuoteStaleForSelectedTechnician ? 'Bu usta için yol hesabı yapılmadı' : 'Yol ücreti hesaplanamadı'}</Badge>
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
        </section>

        <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-4 lg:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Usta / Çilingir Atama</p>
                <p className="mt-1 text-sm text-slate-600">Usta seçimi, yol ücreti durumu, onay ve servis bilgileri.</p>
              </div>
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
            <div className="grid gap-3 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
              <div className="grid gap-3 rounded-2xl border border-slate-200 bg-[#F8FAFD] p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-semibold text-slate-900">Mesafe ve önceliğe göre önerilen ustalar</p>
                  <Badge variant="outline">{technicianSuggestions.length > 0 ? `${technicianSuggestions.length} öneri` : 'Öneri yok'}</Badge>
                </div>
                {technicianSuggestions.length > 0 ? (
                  <div className="grid gap-2">
                    {technicianSuggestions.map((technician) => {
                      const selected = selectedTechnicianId === technician.id
                      const hasAddressInfo = technician.hasAddressInfo ?? Boolean(technician.addressSummary || technician.locationCode || technician.location)
                      const hasPlusCodeInfo = technician.hasPlusCodeInfo ?? Boolean(technician.locationCode)
                      const hasCoordinates = technician.hasCoordinates ?? technician.hasLocation ?? false
                      const routeMismatch = selected
                        && hasCalculatedRouteQuote
                        && typeof technician.estimatedRoundTripKm === 'number'
                        && technician.estimatedRoundTripKm > 0
                        && typeof routeRoundTripKm === 'number'
                        && routeRoundTripKm > 0
                        && Math.max(routeRoundTripKm, technician.estimatedRoundTripKm) / Math.min(routeRoundTripKm, technician.estimatedRoundTripKm) > 3
                      const routeLocationMessage = technician.routeLocationMessage ?? (hasCoordinates
                        ? 'Routes hesabı için koordinat var.'
                        : hasPlusCodeInfo || hasAddressInfo
                          ? 'Usta adres/Plus Code var, gerçek koordinat eksik. Google Routes için lat/lng gerekli.'
                          : 'Usta adres bilgisi eksik.')

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
                            {technician.needsReview ? <Badge variant="warning">Kontrol gerekli</Badge> : null}
                            {routeMismatch ? <Badge variant="warning">Mesafe uyumsuzluğu - kontrol gerekli</Badge> : null}
                          </div>
                          <p className="mt-1 text-xs text-slate-500">
                            {[technician.phone, technician.location, technician.distanceKmLabel].filter(Boolean).join(' · ')}
                          </p>
                          <p className="mt-1 truncate text-xs text-slate-500" title={technician.addressSummary ?? undefined}>
                            {displayOrEmpty(technician.addressSummary ?? technician.locationCode ?? '', 'Adres bilgisi yok')}
                          </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2 text-xs text-slate-600 md:justify-end">
                          <span className="rounded-full bg-slate-100 px-2 py-1">Öncelik: {displayOrEmpty(String(technician.priority ?? ''), '-')}</span>
                          <span className={['rounded-full px-2 py-1', hasAddressInfo ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'].join(' ')}>
                            {hasAddressInfo ? 'Usta adresi var' : 'Usta adres bilgisi eksik'}
                          </span>
                          {hasPlusCodeInfo ? (
                            <span className="rounded-full bg-sky-50 px-2 py-1 text-sky-700">
                              Plus Code var
                            </span>
                          ) : null}
                          <span className={['rounded-full px-2 py-1', hasCoordinates ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'].join(' ')}>
                            {hasCoordinates ? 'Gerçek koordinat var' : hasPlusCodeInfo || hasAddressInfo ? 'Gerçek koordinat eksik' : 'Usta koordinatı eksik'}
                          </span>
                          <span className={['rounded-full px-2 py-1', technician.routeReady ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-700'].join(' ')} title={routeLocationMessage}>
                            {technician.routeReady ? 'Routes hazır' : 'Routes için koordinat eksik'}
                          </span>
                          <span className="rounded-full bg-slate-100 px-2 py-1">İş: {technician.scheduledCount}</span>
                          <Button
                            type="button"
                            size="sm"
                            variant={selected ? 'secondary' : 'outline'}
                            onClick={() => {
                              setRouteFeeEditorMessage(null)
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
                    <Badge variant={routeFeeNeedsApproval ? 'warning' : hasCalculatedRouteQuote ? 'positive' : 'outline'}>{routeFeeStatusText}</Badge>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => void onRouteQuoteCalculate?.()}
                      disabled={routeQuoteLoading || !selectedTechnicianId || !onRouteQuoteCalculate}
                      className="border-blue-200 bg-white text-blue-800 hover:bg-blue-100"
                    >
                      {routeQuoteLoading ? 'Hesaplanıyor...' : 'Yol ücreti hesapla'}
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
                {routeQuoteStaleForSelectedTechnician ? (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                    Bu usta için yol hesabı yapılmadı. Seçili usta değiştiği için eski quote gösterilmiyor.
                  </div>
                ) : null}
                <div className="grid gap-2 sm:grid-cols-2">
                  <MiniMetric label="Usta şehir/adres bilgisi" value={selectedTechnician ? (selectedTechnician.hasAddressInfo ? 'Var' : 'Usta adres bilgisi eksik') : 'Usta seçilmedi'} hint={selectedTechnician?.addressSummary ?? undefined} />
                  <MiniMetric label="Usta koordinatı" value={selectedTechnician ? (selectedTechnician.hasCoordinates ? 'Gerçek koordinat var' : 'Gerçek koordinat eksik') : 'Usta seçilmedi'} hint={selectedTechnician && !selectedTechnician.hasCoordinates && (selectedTechnician.hasPlusCodeInfo || selectedTechnician.hasAddressInfo) ? 'Usta adres/Plus Code var, gerçek koordinat eksik.' : undefined} />
                  <MiniMetric label="Routes hesap durumu" value={selectedTechnician ? (selectedTechnician.routeReady ? 'Hesaplanabilir' : 'Usta koordinatı eksik olduğu için Google Routes hesaplanamadı') : 'Usta seçilmedi'} hint={selectedTechnician?.routeLocationMessage ?? undefined} />
                  <MiniMetric label="Müşteri konumu var mı?" value={locationInfo?.shared ? 'Var' : 'Yok'} />
                  <MiniMetric label="Tek yön Google Routes mesafesi" value={formatKmValue(routeOneWayKm)} hint={hasCalculatedRouteQuote && routeQuote?.duration_text ? `Tahmini süre: ${routeQuote.duration_text}` : 'Google Routes hesaplanınca gösterilir.'} />
                  <MiniMetric label="Gidiş-geliş mesafe" value={formatKmValue(routeRoundTripKm)} hint={hasCalculatedRouteQuote ? undefined : 'Google Routes sonucu yok.'} />
                  <MiniMetric label="Ücretsiz sınır" value={formatKmValue(routeQuote?.threshold_km ?? 30)} />
                  <MiniMetric label="Ücrete tabi km" value={formatKmValue(routeBillableKm)} hint={hasCalculatedRouteQuote ? undefined : 'Routes hesaplanmadan ücrete tabi km hesaplanmaz.'} />
                  <MiniMetric label="Km başı ücret" value={hasCalculatedRouteQuote ? routeFeePerKm === null ? 'Km başı ücret ayarı eksik' : formatMoneyValue(routeFeePerKm) : '-'} />
                  <MiniMetric
                    label="Tahmini yol ücreti"
                    value={hasCalculatedRouteQuote ? routeFeeAmount === null && routeQuote?.travel_fee_required ? 'Km başı ücret ayarı eksik' : formatMoneyValue(routeFeeAmount) : '-'}
                    hint={routeQuoteStaleForSelectedTechnician ? 'Bu usta için yol hesabı yapılmadı' : routeQuote ? routeQuoteMessage(routeQuote.message) : 'Yol ücreti hesaplanamadı'}
                  />
                </div>
                <div className="grid gap-2 rounded-2xl border border-blue-100 bg-white/70 p-3 text-xs text-blue-950 sm:grid-cols-2 lg:grid-cols-3">
                  <MiniMetric
                    label="Usta koordinatı lat/lng"
                    value={selectedTechnicianCoordinateLabel}
                    hint={selectedTechnicianMapHref ? <a className="font-semibold text-blue-700 hover:underline" href={selectedTechnicianMapHref} target="_blank" rel="noreferrer">Haritada aç</a> : 'Gerçek koordinat yok'}
                  />
                  <MiniMetric
                    label="Müşteri koordinatı lat/lng"
                    value={customerCoordinateLabel}
                    hint={customerMapHref ? <a className="font-semibold text-blue-700 hover:underline" href={customerMapHref} target="_blank" rel="noreferrer">Haritada aç</a> : 'Müşteri konumu yok'}
                  />
                  <MiniMetric label="route quote id" value={routeQuote?.id ?? '-'} />
                  <MiniMetric label="quote technician_id" value={routeQuoteTechnicianIdString ?? '-'} />
                  <MiniMetric label="selectedTechnicianId" value={selectedTechnicianIdString ?? '-'} />
                  <MiniMetric label="Usta adı" value={selectedTechnician?.name ?? '-'} />
                </div>
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
                    <div className="flex flex-wrap justify-end gap-2">
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
                <p className="mt-1 text-xs text-slate-500">Müşteri tahsilatı, usta hakedişi ve yol maliyeti tek yerde izlenir.</p>
              </div>
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <MiniMetric label="Müşteriden alınan montaj ücreti" value={mountPaymentLabel} />
                <MiniMetric label="Montaj ödeme durumu" value={resolvedMountPaymentLabel} />
                <MiniMetric label="Usta hakedişi / işçilik" value={technicianLaborCostLabel} />
                <MiniMetric label="Yol ücreti" value={travelCostLabel} />
                <MiniMetric label="Toplam usta maliyeti" value={totalTechnicianCostLabel} />
                <MiniMetric label="Net fark / kâr" value={netProfitLabel} />
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
          </section>

          <section className="order-4 grid gap-4 rounded-3xl border border-slate-200 bg-white p-4 lg:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Son Kontrol</p>
                <p className="mt-1 text-sm text-slate-600">Operasyon onayı, karar alanı, not ve yorum özeti.</p>
              </div>
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
          </section>
        <DetailPanel
          title="Faturadaki diğer serileri gör"
          summary={invoiceSerials?.check_error ? 'Fatura seri kontrolü bekliyor' : 'Talep edilen, gizlenen ve iade seri hareketleri'}
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

        <DetailPanel title="İşlem Geçmişi" summary="Audit kayıtları ve durum akışı">
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

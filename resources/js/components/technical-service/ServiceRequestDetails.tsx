import { ChevronDown } from 'lucide-react'
import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import type { MikroMountCheckResult, ServicePriority, ServiceRequest, ServiceRequestEvent, WarrantySerialResponse } from './types'
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
  priorityUpdateInFlight?: boolean
  priorityUpdateError?: string | null
  workflowActionInFlight?: string | null
  technicianSuggestions?: Array<{
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
  }>
  scheduleSupport?: {
    scheduledLabel: string
    preferredLabel: string
    customerContactLabel: string
    slotSuggestions: string[]
  } | null
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
}: {
  title: string
  summary?: ReactNode
  children: ReactNode
}) => (
  <details className="group rounded-2xl border border-slate-200 bg-slate-50 p-4">
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

const mountPaymentState = (result: MikroMountCheckResult | null | undefined): string => {
  switch (result?.montaj_durumu) {
    case 'Montaj Dahil':
    case 'Montaj Sonradan Dahil':
      return 'Alındı'
    case 'Montaj Hariç':
      return 'Alınmadı'
    default:
      return 'Kontrol Edilemedi'
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
  warranty = null,
  onAssign,
  onSchedule,
  onComplete,
  onReopen,
  onPriorityChange,
  onWorkflowAction,
  priorityUpdateInFlight = false,
  priorityUpdateError = null,
  workflowActionInFlight = null,
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
  const phoneDigits = request.phone.replace(/[^\d+]/g, '')
  const whatsappHref = phoneDigits ? `https://wa.me/${phoneDigits.replace(/^\+/, '')}` : ''
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

        <section className="rounded-3xl border border-slate-200 bg-slate-950 p-4 text-white lg:p-5">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Talep Referansı</p>
              <p className="mt-1 text-lg font-semibold">{displayMrn ?? request.mrn}</p>
              <p className="mt-1 text-xs text-slate-300">Seri No: {displayOrEmpty(request.serialNumber, '-')}</p>
            </div>
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Müşteri</p>
              <p className="mt-1 truncate text-lg font-semibold">{request.customer}</p>
              <p className="mt-1 text-xs text-slate-300">{displayOrEmpty(request.phone, 'Bilgi yok')}</p>
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
          <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-white/10 pt-3">
            <Badge variant={statusVariant(request.status)}>Durum: {currentStatusLabel}</Badge>
            {onPriorityChange ? (
              <label
                className="inline-flex h-7 items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-2.5 py-1 text-xs font-semibold text-white"
                title="Öncelik düzenlenebilir"
              >
                <span>Öncelik:</span>
                <select
                  className="cursor-pointer bg-transparent text-xs font-semibold text-white outline-none disabled:cursor-wait disabled:opacity-60 [&_option]:text-slate-900"
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
          </div>
          {priorityUpdateError ? (
            <p className="mt-2 text-xs font-medium text-rose-200">{priorityUpdateError}</p>
          ) : null}
        </section>

        <section className="grid gap-3 rounded-3xl border border-slate-200 bg-white p-4 lg:p-5">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Adres / Ürün</p>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_1.8fr_1.2fr_1.2fr]">
            <MiniMetric label="İl / İlçe" value={displayOrEmpty([request.city, request.district].filter(Boolean).join(' / '), 'Bilgi yok')} />
            <MiniMetric label="Adres" value={displayOrEmpty(request.address, 'Bilgi yok')} />
            <MiniMetric label="Ürün" value={displayOrEmpty(request.product, 'Bilgi yok')} />
            <MiniMetric label="Model" value={displayOrEmpty(request.model, 'Bilgi yok')} />
          </div>
        </section>

        <div className="grid gap-5 xl:grid-cols-2">
          <section className="order-2 grid gap-4 rounded-3xl border border-slate-200 bg-white p-4 lg:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Montaj / Servis Durumu</p>
                <p className="mt-1 text-sm text-slate-600">Montaj ödemesi, kapı uygunluğu, randevu ve servis aşaması.</p>
              </div>
              <Button type="button" variant="outline" onClick={() => onSchedule?.()} disabled={isActionDisabled}>
                Randevu Planla
              </Button>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <MiniMetric label="Montaj durumu" value={mikroMountCheck?.montaj_durumu ?? 'Kontrol edilmedi'} />
              <MiniMetric label="Ödeme durumu" value={mountPaymentState(mikroMountCheck)} hint={mountPaymentLabel} />
              <MiniMetric label="Kapı uygunluk durumu" value={displayOrEmpty(request.missingInfoReason, 'Kontrol edilmedi')} />
              <MiniMetric label="Randevu tarihi" value={scheduledDateLabel} hint={scheduledTimeLabel} />
              <MiniMetric label="Servis aşaması" value={currentStatusLabel} />
              <MiniMetric label="Mevcut etiketler" value={`${currentPriorityLabel} / ${currentSlaLabel}`} />
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant={statusVariant(request.status)}>Durum: {currentStatusLabel}</Badge>
              <Badge variant="outline">Öncelik: {currentPriorityLabel}</Badge>
              <span className={['inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold', slaTone(request.slaStatus)].join(' ')} title={slaTitle}>
                SLA: {currentSlaLabel}
              </span>
            </div>
          </section>

          <section className="order-1 grid gap-4 rounded-3xl border border-slate-200 bg-white p-4 lg:p-5">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Operasyon Kontrolü</p>
              <p className="mt-1 text-sm text-slate-600">Atama öncesi kontrol edilmesi gereken operasyon maddeleri.</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <MiniMetric label="Ödeme kontrol edildi mi?" value={mountPaymentState(mikroMountCheck)} />
              <MiniMetric label="Adres kontrol edildi mi?" value={hasText(request.address) ? 'Kontrol edilecek' : 'Eksik bilgi var'} />
              <MiniMetric label="Kapı görselleri yeterli mi?" value={(request.beforePhotoCount ?? 0) + (request.generalPhotoCount ?? 0) > 0 ? 'Kontrol edilecek' : 'Yüklenmedi'} hint={photoCompletionLabel} />
              <MiniMetric label="Eksik bilgi var mı?" value={displayOrEmpty(request.missingInfoReason, 'Yok')} />
              <MiniMetric label="Müşteri aranacak mı?" value={displayOrEmpty(request.customerContactStatus, 'Kontrol edilmedi')} hint={dateTimeOrEmpty(request.customerCallbackAt, 'Geri arama tarihi yok')} />
              <MiniMetric label="Randevu tarihi güncellenecek mi?" value={request.rescheduleRequested || request.requiresReschedule ? 'Evet' : 'Kontrol edilmedi'} hint={displayOrEmpty(request.rescheduleReason, 'Bilgi yok')} />
            </div>
          </section>

          <section className="order-3 grid gap-4 rounded-3xl border border-slate-200 bg-white p-4 lg:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Servis / Usta</p>
                <p className="mt-1 text-sm text-slate-600">Atanan servis, onay durumu ve servis talepleri.</p>
              </div>
              <Button type="button" variant="outline" onClick={() => onAssign?.()} disabled={isActionDisabled}>
                Servis Ata
              </Button>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <MiniMetric label="Atanan servis" value={hasAssignedTechnician ? displayOrEmpty(request.technician, 'Bilgi yok') : 'Atanmadı'} />
              <MiniMetric label="Servis telefonu" value={hasAssignedTechnician ? displayOrEmpty(request.technicianPhone, 'Bilgi yok') : 'Bilgi yok'} />
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
        </div>

        <DetailPanel title="Saha Tamamlama Belgeleri" summary="Fotoğraf, garanti kartı, usta açıklaması ve checklist">
          <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 lg:p-5">
            <div className="grid gap-3 sm:grid-cols-2">
              <MiniMetric label="Montaj fotoğrafları" value={photoCompletionLabel} hint="Öncesi / sonrası / genel" />
              <MiniMetric
                label="Kapı fotoğrafları"
                value={(request.beforePhotoCount ?? 0) + (request.generalPhotoCount ?? 0) > 0 ? 'Yüklendi' : 'Yüklenmedi'}
              />
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
        className="sticky bottom-0 z-10 mt-2 border-t border-slate-200 bg-white/95 px-4 py-3 shadow-[0_-10px_30px_rgba(15,23,42,0.08)] backdrop-blur sm:px-6"
        style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom))' }}
      >
        <div className="grid gap-2 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-center lg:justify-end">
          {footerWorkflowActions.map(([actionKey, action]) => (
            <Button
              key={actionKey}
              className="h-9 w-full text-xs sm:text-sm lg:w-auto"
              variant={actionKey === 'complete' ? 'default' : actionKey === 'cancel' ? 'destructive' : 'outline'}
              type="button"
              onClick={() => handleWorkflowAction(actionKey)}
              disabled={isActionDisabled || workflowActionInFlight !== null}
              title={isActionDisabled ? disabledTitle : undefined}
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

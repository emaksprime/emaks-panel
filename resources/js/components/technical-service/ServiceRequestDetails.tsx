import { Link } from '@inertiajs/react'
import { ChevronDown, Info } from 'lucide-react'
import { useState } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { MikroMountCheckResult, ServiceRequest, ServiceRequestEvent, WarrantySerialResponse } from './types'
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
  onComplete?: () => void
  onReopen?: () => void
  onWorkflowAction?: (action: string) => void
  workflowActionInFlight?: string | null
}

const eventTime = (timestamp: string): string => {
  return formatTechnicalServiceDateTime(timestamp, 'Bilinmiyor')
}

const formatOptionalDate = (value: string | null | undefined): string => {
  return formatTechnicalServiceDate(value)
}

const formatDocument = (...parts: Array<string | null | undefined>): string => parts.filter(Boolean).join(' / ')

const formatDisplayValue = (value: string | null | undefined): string => {
  const normalized = String(value ?? '').trim()

  return normalized !== '' ? normalized : '-'
}

const formatCurrencyLabel = (amount: number): string => `${amount.toLocaleString('tr-TR')} TL`

const paymentBadgeLabel = (result: MikroMountCheckResult | null | undefined): string => {
  switch (result?.montaj_durumu) {
    case 'Montaj Dahil':
      return 'Montaj Ödemesi Alınmış'
    case 'Montaj Sonradan Dahil':
      return 'Montaj Ödemesi Sonradan Alınmış'
    case 'Montaj Hariç':
      return 'Montaj Ödemesi Alınmamış'
    default:
      return 'Kontrol Edilemedi'
  }
}

const mikroStatusClasses = (result: MikroMountCheckResult | null | undefined): string => {
  switch (result?.montaj_durumu) {
    case 'Montaj Dahil':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Montaj Sonradan Dahil':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Montaj Hariç':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

const warrantyStatusClasses = (status: WarrantySerialResponse['status'] | null | undefined): string => {
  switch (status) {
    case 'Garanti Aktif':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Garanti Başlamadı':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    case 'Garanti Bitti':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    case 'Değişimle Kapandı':
    case 'Yeni SN’ye Devredildi':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'Yeniden Satış Bekliyor':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

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

const warrantyStartedState = (warranty: WarrantySerialResponse | null | undefined): string => {
  if (!warranty) {
    return 'Kontrol Edilemedi'
  }

  return warranty.status === 'Garanti Başlamadı' ? 'Başlamadı' : 'Başladı'
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

const decisionSourceLabel = (
  result: MikroMountCheckResult | null | undefined,
  warranty: WarrantySerialResponse | null | undefined,
): string => {
  const sources: string[] = []

  if (result?.found) {
    sources.push('Son geçerli Mikro satışı')
  }

  if (result?.montaj_durumu === 'Montaj Sonradan Dahil') {
    sources.push('Sonradan montaj kaydı')
  }

  if (warranty?.card || (warranty?.source ?? '').toLocaleLowerCase('tr-TR').includes('panel')) {
    sources.push('Panel garanti kartı')
  }

  return sources.length > 0 ? sources.join(' + ') : 'Kontrol Edilemedi'
}

type TechnicianApprovalState = {
  tone: string
  title: string
  detail?: string | null
}

type OverrideDecisionInfo = {
  label: string
  value: string
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

const latestOverrideDecisionInfo = (
  events: ServiceRequestEvent[],
  result: MikroMountCheckResult | null | undefined,
  warranty: WarrantySerialResponse | null | undefined,
): OverrideDecisionInfo => {
  const latestAssignment = [...events]
    .sort((a, b) => parseEventTimestamp(b) - parseEventTimestamp(a))
    .find((event) => event.event_type === 'assignment')

  const metadata = latestAssignment?.metadata ?? {}
  const overrideWithoutPayment = Boolean(metadata.override_without_payment)
  const overrideReason = String(metadata.override_reason ?? '').trim()
  const mountPaymentMissing = Boolean(metadata.mount_payment_missing)

  if (overrideWithoutPayment && mountPaymentMissing) {
    return {
      label: 'Atama Onay Açıklaması',
      value: overrideReason !== '' ? overrideReason : 'Operasyon onayı var, açıklama bulunamadı',
    }
  }

  return {
    label: 'Karar kaynağı',
    value: decisionSourceLabel(result, warranty),
  }
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
  warrantyLoading = false,
  warrantyError = null,
  onAssign,
  onComplete,
  onReopen,
  onWorkflowAction,
  workflowActionInFlight = null,
}: ServiceRequestDetailsProps) {
  const [isMountReferenceOpen, setIsMountReferenceOpen] = useState(false)
  const [isWarrantyReferenceOpen, setIsWarrantyReferenceOpen] = useState(false)
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
  const hasSerialNumber = request.serialNumber.trim() !== ''
  const saleCustomerLabel = formatDisplayValue(
    [mikroMountCheck?.asil_cari_kodu, mikroMountCheck?.asil_cari_unvani].filter(Boolean).join(' - '),
  )
  const mountCustomerLabel = formatDisplayValue(
    [mikroMountCheck?.sonradan_montaj_cari_kodu, mikroMountCheck?.sonradan_montaj_cari_unvani].filter(Boolean).join(' - '),
  )
  const mountPaymentLabel = paymentInfo.customerAmountLabel && paymentInfo.customerAmountLabel !== 'Belirlenmedi'
    ? paymentInfo.customerAmountLabel
    : '-'
  const serialQueryHref = hasSerialNumber
    ? `/technical-service/serial-query?serial_no=${encodeURIComponent(request.serialNumber.trim())}`
    : '/technical-service/serial-query'
  const phoneDigits = request.phone.replace(/[^\d+]/g, '')
  const phoneHref = phoneDigits ? `tel:${phoneDigits}` : ''
  const whatsappHref = phoneDigits ? `https://wa.me/${phoneDigits.replace(/^\+/, '')}` : ''
  const summaryNote = warranty?.status === 'Garanti Başlamadı'
    ? warranty.warnings[0] ?? mikroMountCheck?.montaj_ek_aciklama ?? null
    : null
  const approvalState = technicianApprovalState(request, events)
  const overrideDecisionInfo = latestOverrideDecisionInfo(events, mikroMountCheck, warranty)
  const sortedEvents = [...events].sort((a, b) => parseEventTimestamp(b) - parseEventTimestamp(a))
  const workflowActions = Object.entries(request.allowedWorkflowActions ?? {})
  const isMountPositive = mikroMountCheck?.montaj_durumu === 'Montaj Dahil' || mikroMountCheck?.montaj_durumu === 'Montaj Sonradan Dahil'
  const costDelta = paymentInfo.customerAmount !== null && paymentInfo.totalTechnicianCostAmount !== null
    ? paymentInfo.customerAmount - paymentInfo.totalTechnicianCostAmount
    : null
  const costDeltaTone = costDelta === null
    ? 'border-slate-200 bg-slate-100 text-slate-900'
    : costDelta > 0
      ? 'border-green-200 bg-green-50 text-green-950'
      : costDelta < 0
        ? 'border-rose-200 bg-rose-50 text-rose-950'
        : 'border-slate-200 bg-slate-100 text-slate-900'
  const costDeltaLabel = costDelta === null
    ? '-'
    : costDelta > 0
      ? `+${formatCurrencyLabel(costDelta)} kâr`
      : costDelta < 0
        ? `-${formatCurrencyLabel(Math.abs(costDelta))} zarar`
        : '0 TL fark yok'
  const summaryTone = isMountPositive
    ? {
        wrapper: 'border-green-200 bg-green-50',
        icon: 'text-green-700',
        title: 'text-green-950',
        subtitle: 'text-green-900/80',
        card: 'border-green-200 bg-white/80',
        cardLabel: 'text-green-700',
        body: 'text-green-950',
        link: 'text-green-700 hover:text-green-900',
      }
    : {
        wrapper: 'border-rose-200 bg-rose-50',
        icon: 'text-rose-700',
        title: 'text-rose-950',
        subtitle: 'text-rose-900/80',
        card: 'border-rose-200 bg-white/80',
        cardLabel: 'text-rose-700',
        body: 'text-rose-950',
        link: 'text-rose-700 hover:text-rose-900',
      }

  const handleWorkflowAction = (action: string) => {
    if (action === 'assign_technician' || action === 'schedule_planned') {
      onAssign?.()
      return
    }

    if (action === 'complete') {
      onComplete?.()
      return
    }

    onWorkflowAction?.(action)
  }

  return (
    <Card className="rounded-3xl border-slate-200 bg-white shadow-sm break-words min-w-0">
      <CardHeader className="space-y-3 px-6 py-4 sm:py-6 min-w-0">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">Talep</p>
            <CardTitle className="mt-2 text-lg sm:text-xl text-slate-950">{displayMrn ?? request.mrn}</CardTitle>
            <p className="mt-2 text-sm text-slate-600">{request.customer}</p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={statusVariant(request.status)}>{request.status}</Badge>
          </div>
        </div>
        <p className="text-sm text-slate-600">Servis, randevu ve karar akışını tek ekranda takip edin.</p>
      </CardHeader>

      <CardContent className="space-y-6 px-6 pb-6">
        <section className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Müşteri</p>
            <p className="mt-3 text-sm font-semibold text-slate-900">{request.customer}</p>
            <p className="mt-1 text-sm text-slate-600">{request.phone}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 break-words">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Adres</p>
            <p className="mt-3 text-sm text-slate-900 break-words">{request.address}</p>
            <p className="mt-2 text-sm text-slate-600 break-words">{request.city} / {request.district}</p>
          </div>
        </section>

        <section className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">MRN Durumu</p>
            <p className="mt-3 text-sm font-semibold text-slate-950">{request.workflowStatus || request.status}</p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <Badge variant={statusVariant(request.status)}>{request.status}</Badge>
              <span className={['inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold', slaTone(request.slaStatus)].join(' ')}>
                SLA: {request.slaStatus || 'normal'}
              </span>
            </div>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Sıradaki Aksiyon</p>
            <p className="mt-3 text-sm font-semibold text-slate-950">{request.nextAction || '-'}</p>
            <p className="mt-2 text-xs text-slate-500">
              SLA hedefi: {request.slaDueAt ? formatTechnicalServiceDateTime(request.slaDueAt, '-') : '-'}
            </p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Müşteri İletişim Durumu</p>
            <p className="mt-3 text-sm font-semibold text-slate-950">{formatDisplayValue(request.customerContactStatus)}</p>
            <p className="mt-2 text-xs text-slate-500">
              Son temas: {request.customerContactedAt ? formatTechnicalServiceDateTime(request.customerContactedAt, '-') : '-'}
            </p>
            <p className="mt-2 text-xs text-slate-500 break-words">{formatDisplayValue(request.customerContactNote)}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Randevu Bilgisi</p>
            <p className="mt-3 text-sm font-semibold text-slate-950">{request.scheduledDate || '-'}</p>
            <p className="mt-1 text-sm text-slate-600">{request.scheduledTime || request.appointment}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Saha Durumu</p>
            <p className="mt-3 text-sm font-semibold text-slate-950">{formatDisplayValue(request.fieldStatus)}</p>
            <p className="mt-2 text-xs text-slate-500">
              Başlangıç: {request.fieldStartedAt ? formatTechnicalServiceDateTime(request.fieldStartedAt, '-') : '-'}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Varış: {request.fieldArrivedAt ? formatTechnicalServiceDateTime(request.fieldArrivedAt, '-') : '-'}
            </p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Belge / Fotoğraf Durumu</p>
            <p className="mt-3 text-sm font-semibold text-slate-950">
              {formatDisplayValue(request.documentStatus)} / {formatDisplayValue(request.photoStatus)}
            </p>
            <p className="mt-2 text-xs text-slate-500 break-words">
              Eksik bilgi: {formatDisplayValue(request.missingInfoReason)}
            </p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Kapanış Onayı Durumu</p>
            <p className="mt-3 text-sm font-semibold text-slate-950">{formatDisplayValue(request.customerClosureApprovalStatus)}</p>
            <p className="mt-2 text-xs text-slate-500">
              Onay zamanı: {request.customerClosureApprovedAt ? formatTechnicalServiceDateTime(request.customerClosureApprovedAt, '-') : '-'}
            </p>
            <p className="mt-2 text-xs text-slate-500 break-words">
              Bekleme nedeni: {formatDisplayValue(request.pendingReason || request.cancellationReason || request.rescheduleReason)}
            </p>
          </div>
        </section>

        <section className="grid gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Cihaz / Seri No</p>
          <div className="grid gap-2 text-sm text-slate-700">
            <div className="flex justify-between">
              <span className="font-semibold">Ürün</span>
              <span>{request.product}</span>
            </div>
            <div className="flex justify-between">
              <span className="font-semibold">Model</span>
              <span>{request.model}</span>
            </div>
            <div className="flex justify-between">
              <span className="font-semibold">Seri No</span>
              <span>{request.serialNumber}</span>
            </div>
          </div>
        </section>

        <section className="grid gap-4 lg:grid-cols-2">
          {!hasSerialNumber ? (
            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 lg:col-span-2">
              Seri no olmadığı için montaj ve garanti sorgulanamaz.
            </div>
          ) : null}

          <div className={`rounded-2xl border p-5 lg:col-span-2 ${summaryTone.wrapper}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="flex items-start gap-3">
                <span className={`mt-0.5 inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-white shadow-sm ${summaryTone.icon}`}>
                  <Info className="h-5 w-5" />
                </span>
                <div>
                  <p className={`text-sm font-semibold ${summaryTone.title}`}>Montaj ve Garanti Özeti</p>
                  <p className={`mt-1 text-sm ${summaryTone.subtitle}`}>Operasyon kararını etkileyen montaj ve garanti bilgileri tek blokta özetlenir.</p>
                </div>
              </div>
              <Link className={`text-sm font-semibold ${summaryTone.link}`} href={serialQueryHref}>
                Seri No Sorgu ekranında aç
              </Link>
            </div>

            <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
              {[
                ['Montaj durumu', mikroMountCheck?.montaj_durumu ?? 'Kontrol Edilemedi'],
                ['Montaj ödemesi', mountPaymentState(mikroMountCheck)],
                ['Garanti durumu', warrantyStartedState(warranty)],
                [overrideDecisionInfo.label, overrideDecisionInfo.value],
              ].map(([label, value]) => (
                <div key={label} className={`rounded-2xl border p-3 ${summaryTone.card}`}>
                  <p className={`text-xs font-semibold uppercase tracking-[0.12em] ${summaryTone.cardLabel}`}>{label}</p>
                  <p className={`mt-2 text-sm font-semibold ${summaryTone.body}`}>{value || '-'}</p>
                </div>
              ))}
            </div>

            <div className={`mt-4 grid gap-2 text-sm ${summaryTone.body}`}>
              {mikroMountCheck?.farkli_cari_uyarisi ? (
                <p>Sonradan montaj carisi, son geçerli satış carisinden farklı.</p>
              ) : null}
              {summaryNote ? (
                <p>Garanti otomatik başlamadıysa neden: {summaryNote}</p>
              ) : null}
            </div>
          </div>

          <div id="garanti-belge-durumu" className="rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-slate-300">
            <button
              type="button"
              onClick={() => setIsMountReferenceOpen((current) => !current)}
              className="flex w-full flex-wrap items-start justify-between gap-3 text-left"
              aria-expanded={isMountReferenceOpen}
            >
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Montaj referansı</p>
                <p className="mt-2 text-sm text-slate-600">Mikro seri geçmişindeki son geçerli satış ve sonradan montaj izi.</p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant="outline" className={mikroStatusClasses(mikroMountCheck)}>
                  {mikroMountCheck?.found ? paymentBadgeLabel(mikroMountCheck) : 'Kontrol Edilemedi'}
                </Badge>
                <ChevronDown className={`h-4 w-4 text-slate-500 transition-transform ${isMountReferenceOpen ? 'rotate-180' : ''}`} />
              </div>
            </button>

            {mikroMountLoading ? (
              <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">Montaj bilgisi sorgulanıyor...</div>
            ) : null}

            {mikroMountError ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{mikroMountError}</div>
            ) : null}

            {mikroMountCheck && isMountReferenceOpen ? (
              <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                {[
                  ['Montaj ödemesi Kayıt tarihi', formatOptionalDate(mikroMountCheck.sonradan_montaj_tarihi)],
                  ['Sonradan montaj carisi', mountCustomerLabel],
                  ['Satış Tarihi', formatOptionalDate(mikroMountCheck.irsaliye_tarihi)],
                  ['Satış Cari', saleCustomerLabel],
                  ['Montaj Ödemesi', mountPaymentLabel],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
                    <p className="mt-2 whitespace-pre-wrap break-words text-slate-900">{formatDisplayValue(value)}</p>
                  </div>
                ))}
              </div>
            ) : null}
          </div>

          <div className="rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-slate-300">
            <button
              type="button"
              onClick={() => setIsWarrantyReferenceOpen((current) => !current)}
              className="flex w-full flex-wrap items-start justify-between gap-3 text-left"
              aria-expanded={isWarrantyReferenceOpen}
            >
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Garanti referansı</p>
                <p className="mt-2 text-sm text-slate-600">Panel garanti kartı ve son geçerli satıştan türetilen garanti kararı.</p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant="outline" className={warrantyStatusClasses(warranty?.status)}>
                  {warranty?.status ?? 'Kontrol Edilemedi'}
                </Badge>
                <ChevronDown className={`h-4 w-4 text-slate-500 transition-transform ${isWarrantyReferenceOpen ? 'rotate-180' : ''}`} />
              </div>
            </button>

            {warrantyLoading ? (
              <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">Garanti bilgisi sorgulanıyor...</div>
            ) : null}

            {warrantyError ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{warrantyError}</div>
            ) : null}

            {warranty && isWarrantyReferenceOpen ? (
              <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                {[
                  ['Garanti başlangıcı', formatOptionalDate(warranty.warranty_started_at)],
                  ['Garanti bitişi', formatOptionalDate(warranty.warranty_ends_at)],
                  ['Kalan gün', warranty.remaining_days === null || warranty.remaining_days === undefined ? '-' : String(warranty.remaining_days)],
                  ['Garanti süresi', `${warranty.warranty_period_months} ay`],
                  ['Fiili montaj tarihi', formatOptionalDate(warranty.installation.completed_at)],
                  ['Montajı Yapan Usta', request.technician],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
                    <p className="mt-2 whitespace-pre-wrap break-words text-slate-900">{formatDisplayValue(value)}</p>
                  </div>
                ))}
              </div>
            ) : null}
          </div>
        </section>

        <section className="grid gap-4 rounded-2xl border border-slate-200 p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Usta / Çilingir</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{request.technician}</p>
            </div>
            <div className="flex items-center gap-2">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Servis Tipi</p>
              <Badge variant="secondary">{request.serviceType}</Badge>
            </div>
          </div>
          <div className="grid gap-3">
            <div className={`rounded-2xl border p-3 ${approvalState.tone}`}>
              <p className="text-xs uppercase tracking-[0.14em] opacity-75">Usta Onay Durumu</p>
              <p className="mt-2 text-sm font-semibold">{approvalState.title}</p>
              {approvalState.detail ? (
                <p className="mt-1 text-sm opacity-90">{approvalState.detail}</p>
              ) : null}
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-3">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Randevu</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{request.appointment}</p>
            </div>
          </div>
        </section>

        <section className="grid gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Ödeme / Maliyet</p>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">İşlem tipi</p>
              <p className="mt-2 text-lg font-semibold text-slate-900">{paymentInfo.serviceTypeLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Müşteri tahsilatı</p>
              <p className="mt-2 text-lg font-semibold text-slate-900">{paymentInfo.customerAmountLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Usta ödemesi</p>
              <p className="mt-2 text-lg font-semibold text-slate-900">{paymentInfo.technicianAmountLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Gidiş-geliş km</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{paymentInfo.roundTripKmLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Ücretsiz km</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{paymentInfo.freeKmLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Ücretli km</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{paymentInfo.billableKmLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Yol ücreti</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{paymentInfo.travelAmountLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4 sm:col-span-2">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Toplam usta maliyeti</p>
              <p className="mt-2 text-lg font-semibold text-slate-900">{paymentInfo.totalTechnicianCostLabel}</p>
            </div>
            <div className={`rounded-2xl border p-4 sm:col-span-2 ${costDeltaTone}`}>
              <p className="text-xs uppercase tracking-[0.14em] opacity-75">Müşteri / Usta Maliyet Farkı</p>
              <p className="mt-2 text-lg font-semibold">{costDeltaLabel}</p>
              {costDelta !== null && costDelta < 0 ? (
                <p className="mt-2 text-sm opacity-90">Usta maliyeti müşteriden alınan tutardan yüksek.</p>
              ) : null}
            </div>
          </div>
        </section>

        <section id="talep-notlari" className="grid gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 break-words">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Notlar</p>
          <p className="mt-2 text-sm leading-6 text-slate-700 break-words whitespace-pre-wrap">{request.notes}</p>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Audit / İşlem Geçmişi</p>
          <div className="mt-4 space-y-3">
            {(request.auditLogs ?? []).length === 0 ? (
              <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500">
                Audit kaydı bulunmuyor.
              </div>
            ) : (
              (request.auditLogs ?? []).map((log) => (
                <div key={String(log.id)} className="rounded-2xl border border-slate-200 bg-white p-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm font-semibold text-slate-900">{log.action_type}</p>
                    <span className="text-xs text-slate-500">{formatTechnicalServiceDateTime(log.created_at, '-')}</span>
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

        <section className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Durum Timeline</p>
          <div className="mt-4 space-y-4">
            {loading ? (
              <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500">
                Detay yükleniyor...
              </div>
            ) : error ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                {error}
              </div>
            ) : sortedEvents.length === 0 ? (
              <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500">
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

      </CardContent>

      <div
        className="mt-2 border-t border-slate-200 px-3 pt-4 sm:px-6"
        style={{ paddingBottom: 'calc(0.5rem + env(safe-area-inset-bottom))' }}
      >
        <div className="grid grid-cols-2 gap-2 md:grid-cols-3">
          {workflowActions.map(([actionKey, action]) => (
            <Button
              key={actionKey}
              className="h-9 text-xs sm:text-sm"
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
            className="h-9 text-xs sm:text-sm"
            variant="outline"
            disabled={!phoneHref}
          >
            <a href={phoneHref || '#'}>Müşteriyi Ara</a>
          </Button>
          <Button
            asChild
            className="h-9 text-[0.72rem] sm:text-sm"
            variant="secondary"
            disabled={!whatsappHref}
          >
            <a href={whatsappHref || '#'} target="_blank" rel="noreferrer">WhatsApp Aç</a>
          </Button>
          <Button
            className="h-9 text-xs sm:text-sm"
            variant="outline"
            type="button"
            onClick={() => document.getElementById('talep-notlari')?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
          >
            Not Ekle
          </Button>
          <Button
            className="h-9 text-xs sm:text-sm"
            variant="outline"
            type="button"
            onClick={() => document.getElementById('garanti-belge-durumu')?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
          >
            Belge Kontrol Et
          </Button>
          {isReopenVisible ? (
            <Button
              className="h-9 text-xs sm:text-sm"
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


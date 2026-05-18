import { AlertTriangle, Boxes, CalendarDays, CheckCircle2, MapPin, MoreHorizontal, Package, Phone, QrCode } from 'lucide-react'
import {
  getTechnicalServiceKanbanColumn,
  hasTechnicalServiceTechnician,
  isTechnicalServiceCustomerApproved,
  isTechnicalServiceTechnicianApproved,
} from './technicalServiceKanban'
import type { ServiceRequest } from './types'
import { formatTechnicalServiceDateTime, formatTechnicalServiceMrn, normalizeTechnicalServiceText } from './utils'

type BadgeTone = 'neutral' | 'blue' | 'green' | 'amber' | 'rose' | 'purple'
type BadgeIcon = 'multi' | 'warning' | 'paid' | 'qr'
type RequestBadge = { label: string, tone: BadgeTone, icon?: BadgeIcon, important?: boolean }

const badgeClassName = 'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11px] font-semibold leading-none'
const importantBadgeClassName = 'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-bold leading-none shadow-sm'

const badgeTone = (tone: BadgeTone) => {
  switch (tone) {
    case 'blue':
      return 'border-blue-200 bg-blue-100 text-blue-900'
    case 'green':
      return 'border-emerald-200 bg-emerald-100 text-emerald-900'
    case 'amber':
      return 'border-orange-300 bg-orange-100 text-orange-950'
    case 'rose':
      return 'border-rose-300 bg-rose-100 text-rose-950'
    case 'purple':
      return 'border-violet-200 bg-violet-100 text-violet-900'
    default:
      return 'border-slate-200 bg-slate-50 text-slate-700'
  }
}

const badgeClass = (badge: RequestBadge) => [
  badge.important ? importantBadgeClassName : badgeClassName,
  badgeTone(badge.tone),
].join(' ')

const BadgeIconMark = ({ icon }: { icon?: BadgeIcon }) => {
  switch (icon) {
    case 'multi':
      return <Boxes className="h-3.5 w-3.5" />
    case 'warning':
      return <AlertTriangle className="h-3.5 w-3.5" />
    case 'paid':
      return <CheckCircle2 className="h-3.5 w-3.5" />
    case 'qr':
      return <QrCode className="h-3.5 w-3.5" />
    default:
      return null
  }
}

const truncateText = (value: string, fallback = '-'): string => {
  const trimmed = value.trim()

  return trimmed === '' ? fallback : trimmed
}

const readFirstText = (...values: Array<string | null | undefined>): string | null => {
  for (const value of values) {
    const trimmed = String(value ?? '').trim()

    if (trimmed !== '') {
      return trimmed
    }
  }

  return null
}

const resolveCustomerPhone = (request: ServiceRequest): string | null => {
  const requestWithFallbacks = request as ServiceRequest & Record<string, string | null | undefined>

  return readFirstText(
    requestWithFallbacks.customer_phone,
    requestWithFallbacks.customerPhone,
    request.phone,
    requestWithFallbacks.telefon,
    requestWithFallbacks.customer_mobile_phone,
    requestWithFallbacks.customerMobilePhone,
    requestWithFallbacks.customer_gsm,
    requestWithFallbacks.customerGsm,
  )
}

const resolveTechnicianPhone = (request: ServiceRequest): string | null => {
  const requestWithFallbacks = request as ServiceRequest & Record<string, unknown>
  const technicianRecord = typeof requestWithFallbacks.technician === 'object' && requestWithFallbacks.technician !== null
    ? requestWithFallbacks.technician as Record<string, unknown>
    : null
  const technicalServiceTechnicianRecord = typeof requestWithFallbacks.technicalServiceTechnician === 'object' && requestWithFallbacks.technicalServiceTechnician !== null
    ? requestWithFallbacks.technicalServiceTechnician as Record<string, unknown>
    : null
  const snakeCaseTechnicalServiceTechnicianRecord = typeof requestWithFallbacks.technical_service_technician === 'object' && requestWithFallbacks.technical_service_technician !== null
    ? requestWithFallbacks.technical_service_technician as Record<string, unknown>
    : null

  return readFirstText(
    request.technicianPhone,
    typeof requestWithFallbacks.technician_phone === 'string' ? requestWithFallbacks.technician_phone : null,
    typeof requestWithFallbacks.technicianPhone === 'string' ? requestWithFallbacks.technicianPhone : null,
    typeof requestWithFallbacks.technical_service_phone === 'string' ? requestWithFallbacks.technical_service_phone : null,
    typeof requestWithFallbacks.technicalServicePhone === 'string' ? requestWithFallbacks.technicalServicePhone : null,
    typeof requestWithFallbacks.technical_service_technician_phone === 'string' ? requestWithFallbacks.technical_service_technician_phone : null,
    typeof requestWithFallbacks.technicalServiceTechnicianPhone === 'string' ? requestWithFallbacks.technicalServiceTechnicianPhone : null,
    typeof requestWithFallbacks.technician_mobile_phone === 'string' ? requestWithFallbacks.technician_mobile_phone : null,
    typeof requestWithFallbacks.technicianMobilePhone === 'string' ? requestWithFallbacks.technicianMobilePhone : null,
    typeof requestWithFallbacks.technician_gsm === 'string' ? requestWithFallbacks.technician_gsm : null,
    typeof requestWithFallbacks.technicianGsm === 'string' ? requestWithFallbacks.technicianGsm : null,
    typeof technicianRecord?.phone === 'string' ? technicianRecord.phone : null,
    typeof technicianRecord?.mobile_phone === 'string' ? technicianRecord.mobile_phone : null,
    typeof technicianRecord?.mobilePhone === 'string' ? technicianRecord.mobilePhone : null,
    typeof technicianRecord?.gsm === 'string' ? technicianRecord.gsm : null,
    typeof snakeCaseTechnicalServiceTechnicianRecord?.phone === 'string' ? snakeCaseTechnicalServiceTechnicianRecord.phone : null,
    typeof snakeCaseTechnicalServiceTechnicianRecord?.mobile_phone === 'string' ? snakeCaseTechnicalServiceTechnicianRecord.mobile_phone : null,
    typeof technicalServiceTechnicianRecord?.phone === 'string' ? technicalServiceTechnicianRecord.phone : null,
    typeof technicalServiceTechnicianRecord?.mobilePhone === 'string' ? technicalServiceTechnicianRecord.mobilePhone : null,
  )
}

const includesAny = (value: string, keywords: string[]) =>
  keywords.some((keyword) => value.includes(normalizeTechnicalServiceText(keyword)))

const buildBadges = (request: ServiceRequest): RequestBadge[] => {
  const badges: RequestBadge[] = []
  const closureText = normalizeTechnicalServiceText(request.customerClosureApprovalStatus)
  const auditText = (request.auditLogs ?? [])
    .flatMap((log) => [log.action_type, log.note])
    .join(' ')
  const sourceText = normalizeTechnicalServiceText([
    request.workflowStatus,
    request.nextAction,
    request.latestEvent,
    request.customerContactStatus,
    request.customerContactNote,
    request.customerRejectionReason,
    request.technicianApprovalStatus,
    request.technicianConfirmationStatus,
    request.technicianRevisionNote,
    request.fieldStatus,
    request.fieldCompletionNote,
    request.missingInfoReason,
    request.pendingReason,
    request.rescheduleReason,
    request.documentStatus,
    request.photoStatus,
    request.completionBlockReason,
    request.incompleteReason,
    request.secondVisitReason,
    request.cancellationReason,
    request.slaStatus,
    auditText,
  ].filter(Boolean).join(' '))

  const addBadge = (badge: RequestBadge) => {
    if (!badges.some((current) => current.label === badge.label)) {
      badges.push(badge)
    }
  }
  const qrSourceChannel = request.qrSource?.source_channel ?? request.channel
  const mountPaymentStatus = request.saleAndPayment?.mount_payment_status
  const mountPaymentLabel = request.saleAndPayment?.mount_payment_label ?? ''
  const currentSerialState = request.qrSource?.current_serial_state
  const selectedInvoiceSerialCount = request.invoiceSerials?.selected_serials?.length ?? 0
  const extraSelectedSerialCount = Math.max(0, selectedInvoiceSerialCount - 1)
  const addedSerialCount = request.invoiceSerials?.added_serial_count ?? extraSelectedSerialCount
  const addableSerialCount = request.invoiceSerials?.addable_serial_count ?? 0
  const hiddenSerialCount = (request.invoiceSerials?.hidden_serials ?? []).length
  const returnedSerialCount = request.invoiceSerials?.returned_serial_count ?? (request.invoiceSerials?.returned_serials ?? []).length
  const routeQuote = request.routeQuote
  const routeQuoteMatchesAssignedTechnician = Boolean(
    routeQuote
    && request.technicianId
    && routeQuote.technician_id !== null
    && routeQuote.technician_id !== undefined
    && String(routeQuote.technician_id) === String(request.technicianId),
  )
  const routeQuoteHasCurrentFee = routeQuote?.fee_per_km_matches_current !== false

  const hasTechnician = hasTechnicalServiceTechnician(request)
  const technicianApproved = isTechnicalServiceTechnicianApproved(request)
  const customerApproved = isTechnicalServiceCustomerApproved(request)
  const technicianRejected = includesAny(sourceText, [
    'servis reddetti',
    'usta reddetti',
    'technician rejected',
    'technician declined',
  ])
  const customerRejected = Boolean(request.customerRejectionReason) || includesAny(sourceText, [
    'musteri reddetti',
    'müşteri reddetti',
    'musteri reddedildi',
    'müşteri reddedildi',
    'customer_rejected',
    'customer rejected',
  ])
  const technicianRevisionRequested = Boolean(request.technicianRevisionRequestedAt) || includesAny(sourceText, [
    'tarih revize',
    'tarih revizesi',
    'randevu revize',
    'randevu degisikligi',
    'randevu değişikliği',
    'servis randevu degisikligi',
    'servis randevu değişikliği',
  ])

  if (currentSerialState === 'in_stock_or_center' || request.saleAndPayment?.sale_mount_status === 'check_failed') {
    addBadge({ label: 'Kontrol bekliyor', tone: 'amber', icon: 'warning', important: true })
  }

  if ((request.assignmentBlockers?.messages ?? []).length > 0) {
    addBadge({ label: 'Atama engeli var', tone: 'rose', icon: 'warning', important: true })
  }

  if (request.operationControl?.payment_checked !== 'yes') {
    addBadge({ label: 'Ödeme kontrol edilmedi', tone: 'rose', icon: 'warning', important: true })
  }

  if (!request.operationControl?.door_photos_checked || request.operationControl.door_photos_checked === 'unreviewed') {
    addBadge({ label: 'Kapı görseli kontrol edilmedi', tone: 'rose', icon: 'warning', important: true })
  }

  if (routeQuote?.status === 'calculated' && routeQuoteMatchesAssignedTechnician && routeQuoteHasCurrentFee) {
    addBadge(routeQuote.travel_fee_required
      ? { label: 'Yol ücreti onayı gerekli', tone: 'amber', icon: 'warning', important: true }
      : { label: 'Yol ücreti yok', tone: 'green', icon: 'paid' })
  } else if (routeQuote && routeQuoteMatchesAssignedTechnician) {
    addBadge({ label: 'Yol ücreti hesaplanamadı', tone: 'amber', icon: 'warning' })
  }

  if (mountPaymentStatus === 'skipped_multi_product' || request.invoiceSerials?.has_multi_product || extraSelectedSerialCount > 0) {
    addBadge({ label: 'Çoklu ürün talebi', tone: 'amber', icon: 'multi', important: true })
  }

  if (addedSerialCount > 0) {
    addBadge({ label: `Montaja eklenen: ${addedSerialCount}`, tone: 'green', icon: 'paid', important: true })
  }

  if (addableSerialCount > 0) {
    addBadge({ label: `Eklenebilir seri: ${addableSerialCount}`, tone: 'amber', icon: 'multi', important: true })
  }

  if (returnedSerialCount > 0 || request.invoiceSerials?.has_returned) {
    addBadge({ label: 'İade seri var', tone: 'rose', icon: 'warning', important: true })
  } else if (hiddenSerialCount > 0) {
    addBadge({ label: 'Gizli seri var', tone: 'amber', icon: 'warning', important: true })
  }

  if (mountPaymentStatus === 'paid' || mountPaymentLabel === 'Montaj ödemesi alındı') {
    addBadge({ label: 'Montaj ödemesi alındı', tone: 'green', icon: 'paid', important: true })
  }

  if (qrSourceChannel === 'qr_mount_form') {
    addBadge({ label: 'QR Montaj Formu', tone: 'blue', icon: 'qr' })
  }

  if (hasTechnician) {
    addBadge({ label: 'Usta Atandı', tone: 'blue' })
  }

  if (hasTechnician && !technicianApproved && !technicianRejected) {
    addBadge({ label: 'Usta Onayı Bekleniyor', tone: 'amber' })
  }

  if (technicianApproved) {
    addBadge({ label: 'Usta Onayladı', tone: 'green' })
  }

  if (hasTechnician && technicianApproved && !customerApproved && !customerRejected) {
    addBadge({ label: 'Müşteri Onayı Bekleniyor', tone: 'amber' })
  }

  if (customerApproved) {
    addBadge({ label: 'Müşteri Onayladı', tone: 'green' })
  }

  if (customerRejected) {
    addBadge({ label: 'Müşteri Reddetti', tone: 'rose' })
  }

  if (request.customerClosureApprovalStatus && closureText !== 'onaylandi') {
    addBadge({ label: 'Ödeme Bekleniyor', tone: 'rose' })
  }

  if (includesAny(sourceText, ['kabul edildi', 'usta kabul', 'approved'])) {
    addBadge({ label: 'Kabul Edildi', tone: 'green' })
  }

  if (includesAny(sourceText, ['kapı uyumsuz', 'kapi uyumsuz', 'montaj yeri hazır değil', 'montaj yeri hazir degil'])) {
    addBadge({ label: 'Kapı Uyumsuz', tone: 'rose' })
  }

  if (request.rescheduleRequested || request.requiresReschedule || includesAny(sourceText, ['randevu tarihi güncellenmeli', 'randevu tarihi guncellenmeli', 'tarih revize', 'randevu değişikliği', 'randevu degisikligi'])) {
    addBadge({ label: 'Randevu Tarihi Güncellenmeli', tone: 'amber' })
  }

  if (technicianRevisionRequested) {
    addBadge({ label: 'Tarih Revizesi Talep Etti', tone: 'amber' })
  }

  if (!customerRejected && !technicianRejected && includesAny(sourceText, ['reddedildi', 'red edildi', 'rejected'])) {
    addBadge({ label: 'Reddedildi', tone: 'rose' })
  }

  if (includesAny(sourceText, ['teknik destek', 'teknik onay', 'servis problemi', 'cihaz değişimi', 'cihaz degisimi'])) {
    addBadge({ label: 'Teknik Destek Gerekli', tone: 'purple' })
  }

  if (request.requiresSecondVisit || includesAny(sourceText, ['tekrar ziyaret', 'ikinci ziyaret', 'ikinci randevu'])) {
    addBadge({ label: 'Tekrar Ziyaret Edilecek', tone: 'amber' })
  }

  if (includesAny(sourceText, ['kapı onaylandı', 'kapi onaylandi', 'kapı uygun', 'kapi uygun'])) {
    addBadge({ label: 'Kapı Onaylandı', tone: 'green' })
  }

  if (includesAny(sourceText, ['whatsapp', 'wp mesaj', 'wp'])) {
    addBadge({ label: 'WP Mesajı Gönderildi', tone: 'blue' })
  }

  if (includesAny(sourceText, ['yorum', 'comment', 'not eklendi'])) {
    addBadge({ label: 'Yorum Eklendi', tone: 'neutral' })
  }

  if (request.technicianRevisionRequestedAt || includesAny(sourceText, ['servis destek', 'yedek parça', 'yedek parca', 'fiyat değişikliği', 'fiyat degisikligi'])) {
    addBadge({ label: 'Servis Destek Talep Etti', tone: 'purple' })
  }

  if (includesAny(sourceText, ['servis randevu değişikliği', 'servis randevu degisikligi'])) {
    addBadge({ label: 'Servis Randevu Değişikliği', tone: 'amber' })
  }

  const column = getTechnicalServiceKanbanColumn(request)

  if (column === 'completed') {
    addBadge({ label: 'Tamamlandı', tone: 'green' })
  }

  if (column === 'cancelled') {
    addBadge({ label: 'İptal', tone: 'rose' })
  }

  if (technicianRejected) {
    addBadge({ label: 'Servis Reddetti', tone: 'rose' })
  }

  return badges.slice(0, 8)
}

export function TechnicalServiceKanbanCard({
  request,
  selected = false,
  isUnread = false,
  onClick,
}: {
  request: ServiceRequest
  selected?: boolean
  isUnread?: boolean
  onClick: () => void
}) {
  const displayMrn = formatTechnicalServiceMrn(request)
  const serialLabel = truncateText(request.serialNumber)
  const appointmentLabel = formatTechnicalServiceDateTime(request.scheduledAt ?? request.scheduledDate ?? request.createdAt ?? null, '-')
  const locationLabel = [request.city, request.district].filter((value) => value.trim() !== '').join(' / ') || '-'
  const productLabel = [request.product, request.model].filter((value) => value.trim() !== '').join(' / ') || '-'
  const badges = buildBadges(request)
  const customerPhone = resolveCustomerPhone(request)
  const technicianPhone = resolveTechnicianPhone(request)

  const technicianLabel = request.technician && normalizeTechnicalServiceText(request.technician) !== 'atanmadi'
    ? `TS - ${request.technician} - ${request.city || '-'}`
    : null

  return (
    <button
      type="button"
      onClick={onClick}
      className={[
        'group relative min-w-0 w-full overflow-hidden rounded-[24px] border p-4 text-left shadow-[0_10px_28px_rgba(15,23,42,0.06)] transition hover:-translate-y-0.5 hover:shadow-[0_16px_34px_rgba(15,23,42,0.1)]',
        selected
          ? 'border-[#06143A] bg-white ring-2 ring-[#06143A]/15'
          : isUnread
            ? 'border-amber-300 bg-amber-50/80 hover:border-amber-400 hover:bg-amber-50'
            : 'border-slate-200 bg-white hover:border-slate-300',
      ].join(' ')}
    >
      {isUnread ? <span className="absolute inset-x-0 top-0 h-1 bg-amber-400" aria-hidden="true" /> : null}
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="grid min-w-0 gap-1 rounded-[16px] border border-slate-100 bg-[#F8FAFD] px-3 py-2">
            <p className="flex min-w-0 items-center justify-between gap-2 text-[11px] font-semibold uppercase text-slate-500">
              <span>MRN</span>
              <span className="truncate text-slate-950">{displayMrn}</span>
            </p>
            <p className="flex min-w-0 items-center justify-between gap-2 text-[11px] font-semibold uppercase text-slate-500">
              <span>Seri No</span>
              <span className="truncate text-slate-700">{serialLabel}</span>
            </p>
          </div>

          <div className="mt-3 flex flex-wrap items-center gap-2">
            {isUnread ? (
              <span className="rounded-full border border-amber-200 bg-amber-100 px-2.5 py-1 text-[11px] font-bold uppercase text-amber-900">
                Yeni
              </span>
            ) : null}
            <span className="rounded-full bg-[#06143A] px-2.5 py-1 text-[11px] font-semibold uppercase text-white">
              {request.serviceType}
            </span>
            <span className={[badgeClassName, badgeTone(request.priority === 'Kritik' || request.priority === 'Yüksek' ? 'rose' : request.priority === 'Orta' ? 'amber' : 'neutral')].join(' ')}>
              {request.priority}
            </span>
            {badges.map((badge) => (
              <span key={badge.label} className={badgeClass(badge)}>
                <BadgeIconMark icon={badge.icon} />
                {badge.label}
              </span>
            ))}
          </div>
        </div>
        <span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-slate-50 text-slate-500 ring-1 ring-slate-200 transition group-hover:bg-slate-100">
          <MoreHorizontal className="h-4 w-4" />
        </span>
      </div>

      <div className="mt-4 rounded-[20px] border border-slate-100 bg-[#F8FAFD] p-3">
        <p className="truncate text-base font-semibold text-slate-950">{truncateText(request.customer)}</p>
        {customerPhone ? (
          <p className="mt-2 flex items-center gap-1.5 text-xs font-medium text-slate-500">
            <Phone className="h-3.5 w-3.5 text-slate-400" />
            <span className="truncate">{customerPhone}</span>
          </p>
        ) : null}
        <div className="mt-3 grid gap-2 text-xs text-slate-600">
          <p className="flex min-w-0 items-center gap-2">
            <MapPin className="h-3.5 w-3.5 shrink-0 text-slate-400" />
            <span className="truncate">{locationLabel}</span>
          </p>
          <p className="flex min-w-0 items-start gap-2">
            <Package className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
            <span className="line-clamp-2">{productLabel}</span>
          </p>
        </div>
      </div>

      {technicianLabel ? (
        <div className="mt-4 space-y-2">
          <span className="inline-flex max-w-full rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">
            <span className="truncate">{technicianLabel}</span>
          </span>
          {technicianPhone ? (
            <p className="flex items-center gap-1.5 text-xs text-slate-500">
              <Phone className="h-3.5 w-3.5 text-slate-400" />
              <span className="truncate">{technicianPhone}</span>
            </p>
          ) : null}
        </div>
      ) : null}

      <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs font-medium text-slate-500">
        <span>#{request.id}</span>
        <span className="inline-flex min-w-0 items-center gap-1.5">
          <CalendarDays className="h-3.5 w-3.5 shrink-0 text-slate-400" />
          <span className="truncate">{appointmentLabel}</span>
        </span>
        <span className={[badgeClassName, badgeTone(request.priority === 'Kritik' ? 'rose' : request.priority === 'Yüksek' ? 'amber' : 'neutral')].join(' ')}>
          {request.priority}
        </span>
      </div>
    </button>
  )
}

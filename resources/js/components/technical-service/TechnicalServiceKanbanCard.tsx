import { AlertTriangle, Boxes, CalendarDays, MapPin, MoreHorizontal, Package, Phone } from 'lucide-react'
import {
  getTechnicalServiceKanbanColumn,
  hasTechnicalServiceTechnician,
  isTechnicalServiceTechnicianApproved,
} from './technicalServiceKanban'
import type { ServiceRequest } from './types'
import { formatTechnicalServiceDateTime, formatTechnicalServiceMrn, normalizeTechnicalServiceText } from './utils'

type BadgeTone = 'neutral' | 'blue' | 'green' | 'amber' | 'rose' | 'purple'
type BadgeIcon = 'multi' | 'warning'
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

const fallbackActionLabel = (column: ReturnType<typeof getTechnicalServiceKanbanColumn>): string | null => ({
  new: 'Operasyon kontrolü',
  assignment_pending: 'Usta/randevu onayı',
  assigned: 'Saha takibi',
  final_check: 'Son kontrol',
  review: 'Ops inceleme',
  completed: 'Hakediş kontrolü',
  cancelled: 'İptal kaydı',
}[column] ?? null)

const actionBadgeLabel = (request: ServiceRequest, column: ReturnType<typeof getTechnicalServiceKanbanColumn>): string | null => {
  const rawAction = readFirstText(request.nextActionPayload?.title, request.nextAction)
    ?? fallbackActionLabel(column)

  if (!rawAction) {
    return null
  }

  return `Aksiyon: ${rawAction.length > 36 ? `${rawAction.slice(0, 33)}...` : rawAction}`
}

const buildBadges = (request: ServiceRequest): RequestBadge[] => {
  const badges: RequestBadge[] = []
  const column = getTechnicalServiceKanbanColumn(request)

  const addBadge = (badge: RequestBadge) => {
    if (!badges.some((current) => current.label === badge.label)) {
      badges.push(badge)
    }
  }

  const latestPortalOpsAction = latestPortalOpsActionForCard(request)

  if (latestPortalOpsAction?.action === 'job_rejected') {
    addBadge({ label: 'Usta reddetti', tone: 'rose', icon: 'warning', important: true })
  } else if (latestPortalOpsAction?.action === 'completion_submitted') {
    addBadge({ label: 'Son kontrol bekliyor', tone: 'purple', icon: 'warning', important: true })
  } else if (latestPortalOpsAction?.action === 'appointment_proposed') {
    addBadge({ label: 'Randevu önerisi bekliyor', tone: 'amber', icon: 'warning', important: true })
  } else if (latestPortalOpsAction?.action === 'support_requested') {
    addBadge({ label: 'Ek talep', tone: 'purple', icon: 'warning', important: true })
  } else if (latestPortalOpsAction?.action === 'revisit_requested') {
    addBadge({ label: 'Tekrar ziyaret talebi', tone: 'amber', icon: 'warning', important: true })
  }

  const currentActionBadge = actionBadgeLabel(request, column)

  if (currentActionBadge && !latestPortalOpsAction) {
    addBadge({ label: currentActionBadge, tone: 'blue', important: true })
  }

  const canonicalPaymentStatus = request.saleAndPayment?.payment_status ?? null
  const mountPaymentStatus = request.saleAndPayment?.mount_payment_status
  const mountPaymentPaid = Boolean(
    canonicalPaymentStatus?.is_paid
    || request.saleAndPayment?.mount_payment_received
    || mountPaymentStatus === 'paid'
  )
  const currentSerialState = request.qrSource?.current_serial_state
  const selectedInvoiceSerialCount = request.invoiceSerials?.selected_serials?.length ?? 0
  const extraSelectedSerialCount = Math.max(0, selectedInvoiceSerialCount - 1)
  const addableSerialCount = request.invoiceSerials?.addable_serial_count ?? 0
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
  const customerClosureText = normalizeTechnicalServiceText(request.customerClosureApprovalStatus)
  const customerClosureApproved = includesAny(customerClosureText, ['onaylandi', 'onaylandı', 'approved'])
  const fieldDocumentCount = new Set(
    (request.fieldCompletionDocuments ?? [])
      .map((document) => document.field_code)
      .filter((field): field is string => ['before_photo', 'after_photo', 'warranty_document_photo'].includes(String(field))),
  ).size

  if (column === 'new' && (currentSerialState === 'in_stock_or_center' || request.saleAndPayment?.sale_mount_status === 'check_failed')) {
    addBadge({ label: 'Seri kontrol bekliyor', tone: 'amber', icon: 'warning', important: true })
  }

  if (column === 'new' && (returnedSerialCount > 0 || request.invoiceSerials?.has_returned)) {
    addBadge({ label: 'İade seri var', tone: 'rose', icon: 'warning', important: true })
  }

  if (column === 'new' && !mountPaymentPaid && (canonicalPaymentStatus?.requires_payment || request.operationControl?.payment_checked !== 'yes')) {
    addBadge({ label: 'Ödeme gerekli', tone: 'rose', icon: 'warning', important: true })
  }

  if (column === 'new' && !hasTechnician) {
    addBadge({ label: 'Usta seçilmeli', tone: 'amber', icon: 'warning', important: true })
  }

  if (routeQuote?.status === 'calculated' && routeQuoteMatchesAssignedTechnician && routeQuoteHasCurrentFee) {
    if (routeQuote.travel_fee_required && column !== 'completed') {
      addBadge({ label: 'Yol ücreti onayı gerekli', tone: 'amber', icon: 'warning', important: true })
    } else if (column !== 'completed') {
      addBadge({ label: 'Yol ücreti yok', tone: 'neutral' })
    }
  } else if (routeQuote && routeQuoteMatchesAssignedTechnician) {
    addBadge({ label: 'Yol ücreti hesaplanamadı', tone: 'amber', icon: 'warning' })
  }

  if (column === 'new' && (mountPaymentStatus === 'skipped_multi_product' || request.invoiceSerials?.has_multi_product || extraSelectedSerialCount > 0)) {
    addBadge({ label: 'Çoklu ürün talebi', tone: 'amber', icon: 'multi', important: true })
  }

  if (column === 'new' && addableSerialCount > 0) {
    addBadge({ label: `Eklenebilir seri: ${addableSerialCount}`, tone: 'amber', icon: 'multi', important: true })
  }

  if (column === 'assignment_pending' && hasTechnician && !technicianApproved) {
    addBadge({ label: 'Usta onayı bekliyor', tone: 'amber', icon: 'warning', important: true })
  }

  if (column === 'assigned' && request.requiresSecondVisit) {
    addBadge({ label: 'Tekrar Ziyaret Edilecek', tone: 'amber' })
  }

  if (column === 'final_check') {
    if (fieldDocumentCount < 3) {
      addBadge({ label: 'Fotoğraf eksik', tone: 'rose', icon: 'warning', important: true })
    }

    if (!customerClosureApproved) {
      addBadge({ label: 'Müşteri onayı bekliyor', tone: 'amber', icon: 'warning', important: true })
    }
  }

  if (column === 'completed') {
    addBadge({ label: 'Tamamlandı', tone: 'green' })
  }

  if (column === 'cancelled') {
    addBadge({ label: 'İptal', tone: 'rose' })
  }

  if (column === 'review' && badges.length === 0) {
    addBadge({ label: 'Ops inceleme', tone: 'purple', icon: 'warning', important: true })
  }

  return badges.slice(0, 4)
}

const latestPortalOpsActionForCard = (request: ServiceRequest) =>
  (request.partnerPortalActions ?? []).find((action) => action.status === 'ops_review') ?? null

const portalActionLabel = (action: string) => ({
  appointment_proposed: 'Randevu önerisi',
  job_rejected: 'Usta reddetti',
  support_requested: 'Ek talep',
  revisit_requested: 'Tekrar ziyaret',
  completion_submitted: 'Tamamlama gönderildi',
}[action] ?? action)

const columnDetailRows = (
  request: ServiceRequest,
  column: ReturnType<typeof getTechnicalServiceKanbanColumn>,
  technicianPhone: string | null,
): Array<{ label: string, value: string }> => {
  const portalAction = latestPortalOpsActionForCard(request)

  if (column === 'new') {
    return [
      { label: 'Müşteri', value: truncateText(request.customer) },
      { label: 'Ürün', value: truncateText([request.product, request.model].filter(Boolean).join(' / ')) },
      { label: 'Sıradaki', value: truncateText(request.nextAction ?? 'Operasyon kontrolü') },
    ]
  }

  if (column === 'assignment_pending') {
    return [
      { label: 'Usta', value: truncateText(request.technician || 'Atama bekliyor') },
      { label: 'Telefon', value: truncateText(technicianPhone ?? '') },
      { label: 'Onay', value: portalAction ? portalActionLabel(portalAction.action) : truncateText(request.technicianApprovalStatus ?? 'Usta/onay bekliyor') },
    ]
  }

  if (column === 'assigned') {
    return [
      { label: 'Randevu', value: formatTechnicalServiceDateTime(request.scheduledAt ?? request.scheduledDate ?? null, '-') },
      { label: 'Usta', value: truncateText(request.technician || 'Usta') },
      { label: 'Müşteri tel', value: truncateText(resolveCustomerPhone(request) ?? '') },
    ]
  }

  if (column === 'final_check') {
    return [
      { label: 'Son kontrol', value: portalAction ? portalActionLabel(portalAction.action) : 'Operasyon onayı bekliyor' },
      { label: 'Fotoğraf', value: truncateText(request.photoStatus ?? 'Kontrol edilecek') },
      { label: 'Kapanış', value: truncateText(request.customerClosureApprovalStatus ?? 'Onay kontrolü') },
    ]
  }

  if (column === 'completed') {
    return [
      { label: 'Tamamlanma', value: formatTechnicalServiceDateTime(request.completedAt ?? null, '-') },
      { label: 'Usta', value: truncateText(request.technician || '-') },
      { label: 'Hakediş', value: truncateText(request.assignmentOffer?.status ?? 'Kontrol edilecek') },
    ]
  }

  return [
    { label: 'İnceleme', value: portalAction ? portalActionLabel(portalAction.action) : truncateText(request.pendingReason ?? request.incompleteReason ?? 'Operasyon incelemesi') },
    { label: 'Usta', value: truncateText(request.technician || '-') },
    { label: 'Not', value: truncateText(portalAction?.note ?? request.nextAction ?? '-') },
  ]
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
  const column = getTechnicalServiceKanbanColumn(request)
  const detailRows = columnDetailRows(request, column, technicianPhone)

  const technicianLabel = request.technician && normalizeTechnicalServiceText(request.technician) !== 'atanmadi'
    ? `TS - ${request.technician} - ${request.city || '-'}`
    : null

  return (
    <button
      type="button"
      onClick={onClick}
      className={[
        'group relative min-w-0 w-full overflow-hidden rounded-[18px] border p-2.5 text-left shadow-[0_8px_20px_rgba(15,23,42,0.05)] transition hover:-translate-y-0.5 hover:shadow-[0_12px_26px_rgba(15,23,42,0.09)] xl:p-3',
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
          <div className="grid min-w-0 gap-1 rounded-[14px] border border-slate-100 bg-[#F8FAFD] px-2.5 py-2">
            <p className="flex min-w-0 items-center justify-between gap-2 text-[11px] font-semibold uppercase text-slate-500">
              <span>MRN</span>
              <span className="truncate text-slate-950">{displayMrn}</span>
            </p>
            <p className="flex min-w-0 items-center justify-between gap-2 text-[11px] font-semibold uppercase text-slate-500">
              <span>Seri No</span>
              <span className="truncate text-slate-700">{serialLabel}</span>
            </p>
          </div>

          <div className="mt-2 flex flex-wrap items-center gap-1.5">
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

      <div className="mt-3 grid gap-1.5 rounded-[14px] border border-slate-100 bg-[#F8FAFD] p-2.5 text-[11px] text-slate-600 xl:text-xs">
        <p className="truncate text-sm font-semibold text-slate-950">{truncateText(request.customer)}</p>
        {customerPhone ? (
          <p className="flex items-center gap-1.5 font-medium text-slate-500">
            <Phone className="h-3.5 w-3.5 text-slate-400" />
            <span className="truncate">{customerPhone}</span>
          </p>
        ) : null}
        <p className="flex min-w-0 items-center gap-2">
          <MapPin className="h-3.5 w-3.5 shrink-0 text-slate-400" />
          <span className="truncate">{locationLabel}</span>
        </p>
        <p className="flex min-w-0 items-start gap-2">
          <Package className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
          <span className="line-clamp-1">{productLabel}</span>
        </p>
      </div>

      {technicianLabel ? (
        <div className="mt-3 space-y-1.5">
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

      <div className="mt-3 grid gap-1.5 rounded-[16px] border border-slate-100 bg-white/80 p-2.5 text-[11px] xl:text-xs">
        {detailRows.map((row) => (
          <p key={row.label} className="flex min-w-0 items-center justify-between gap-2 text-slate-500">
            <span className="shrink-0 font-semibold">{row.label}</span>
            <span className="truncate text-right text-slate-800">{row.value}</span>
          </p>
        ))}
      </div>

      <div className="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-2.5 text-[11px] font-medium text-slate-500 xl:text-xs">
        <span>#{request.id}</span>
        <span className="inline-flex min-w-0 items-center gap-1.5">
          <CalendarDays className="h-3.5 w-3.5 shrink-0 text-slate-400" />
          <span className="truncate">{appointmentLabel}</span>
        </span>
      </div>
    </button>
  )
}

import { MoreHorizontal, Phone } from 'lucide-react'
import { getTechnicalServiceKanbanColumn } from './technicalServiceKanban'
import type { ServiceRequest } from './types'
import { formatTechnicalServiceDateTime, formatTechnicalServiceMrn, getServicePaymentInfo, normalizeTechnicalServiceText } from './utils'

const badgeClassName = 'inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold'

const badgeTone = (tone: 'neutral' | 'blue' | 'green' | 'amber' | 'rose') => {
  switch (tone) {
    case 'blue':
      return `${badgeClassName} border-blue-200 bg-blue-50 text-blue-700`
    case 'green':
      return `${badgeClassName} border-emerald-200 bg-emerald-50 text-emerald-700`
    case 'amber':
      return `${badgeClassName} border-amber-200 bg-amber-50 text-amber-800`
    case 'rose':
      return `${badgeClassName} border-rose-200 bg-rose-50 text-rose-700`
    default:
      return `${badgeClassName} border-slate-200 bg-slate-100 text-slate-700`
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

const buildBadges = (request: ServiceRequest): Array<{ label: string, tone: 'neutral' | 'blue' | 'green' | 'amber' | 'rose' }> => {
  const badges: Array<{ label: string, tone: 'neutral' | 'blue' | 'green' | 'amber' | 'rose' }> = []
  const paymentInfo = getServicePaymentInfo(
    request.serviceType,
    request.travelRoundTripKm,
    request.travelFeeAmount,
    request.travelBillableKm,
    request.technicianPaymentAmount,
  )
  const workflowText = normalizeTechnicalServiceText(request.workflowStatus)
  const contactText = normalizeTechnicalServiceText(request.customerContactStatus)
  const closureText = normalizeTechnicalServiceText(request.customerClosureApprovalStatus)

  if (request.serviceType === 'Montaj') {
    if (paymentInfo.customerAmount !== null && paymentInfo.customerAmount > 0) {
      badges.push({ label: 'Montaj Hariç', tone: 'amber' })
    } else {
      badges.push({ label: 'Montaj Dahil', tone: 'green' })
    }
  }

  if (request.customerClosureApprovalStatus && closureText !== 'onaylandi') {
    badges.push({ label: 'Ödeme Bekleniyor', tone: 'rose' })
  }

  if (contactText.includes('arandi')) {
    badges.push({ label: 'Müşteri Arandı', tone: 'blue' })
  }

  if (workflowText.includes('onay')) {
    badges.push({ label: 'Kabul Edildi', tone: 'green' })
  }

  if (request.scheduledAt || request.scheduledDate) {
    badges.push({ label: 'Randevu Tarihi Verildi', tone: 'blue' })
  }

  const column = getTechnicalServiceKanbanColumn(request)

  if (column === 'completed') {
    badges.push({ label: 'Tamamlandı', tone: 'green' })
  }

  if (column === 'cancelled') {
    badges.push({ label: 'İptal', tone: 'rose' })
  }

  return badges.slice(0, 4)
}

export function TechnicalServiceKanbanCard({
  request,
  selected = false,
  onClick,
}: {
  request: ServiceRequest
  selected?: boolean
  onClick: () => void
}) {
  const displayMrn = formatTechnicalServiceMrn(request)
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
        'group w-full rounded-[22px] border p-4 text-left shadow-sm transition',
        selected
          ? 'border-[#0f2a56] bg-white ring-2 ring-[#0f2a56]/15'
          : 'border-slate-200 bg-white/95 hover:border-slate-300 hover:bg-white',
      ].join(' ')}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{displayMrn}</p>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <span className="rounded-full bg-slate-950 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-white">
              {request.serviceType}
            </span>
            <span className={badgeTone(request.priority === 'Kritik' || request.priority === 'Yüksek' ? 'rose' : request.priority === 'Orta' ? 'amber' : 'neutral')}>
              {request.priority}
            </span>
          </div>
        </div>
        <span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-500 transition group-hover:bg-slate-200">
          <MoreHorizontal className="h-4 w-4" />
        </span>
      </div>

      <div className="mt-4 space-y-2">
        <p className="truncate text-sm font-semibold text-slate-950">{truncateText(request.customer)}</p>
        {customerPhone ? (
          <p className="flex items-center gap-1.5 text-xs text-slate-500">
            <Phone className="h-3.5 w-3.5 text-slate-400" />
            <span>{customerPhone}</span>
          </p>
        ) : null}
        <p className="text-xs text-slate-500">{locationLabel}</p>
        <p className="line-clamp-2 text-sm text-slate-700">{productLabel}</p>
      </div>

      {badges.length > 0 ? (
        <div className="mt-4 flex flex-wrap gap-2">
          {badges.map((badge) => (
            <span key={badge.label} className={badgeTone(badge.tone)}>
              {badge.label}
            </span>
          ))}
        </div>
      ) : null}

      {technicianLabel ? (
        <div className="mt-4 space-y-2">
          <span className="inline-flex max-w-full rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">
            <span className="truncate">{technicianLabel}</span>
          </span>
          {technicianPhone ? (
            <p className="flex items-center gap-1.5 text-xs text-slate-500">
              <Phone className="h-3.5 w-3.5 text-slate-400" />
              <span>{technicianPhone}</span>
            </p>
          ) : null}
        </div>
      ) : null}

      <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 pt-3 text-xs text-slate-500">
        <span>#{request.id}</span>
        <span>{appointmentLabel}</span>
        <span className={badgeTone(request.priority === 'Kritik' ? 'rose' : request.priority === 'Yüksek' ? 'amber' : 'neutral')}>
          {request.priority}
        </span>
      </div>
    </button>
  )
}

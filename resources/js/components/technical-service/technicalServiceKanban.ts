import type { ServiceRequest } from './types'
import { normalizeTechnicalServiceText } from './utils'

export const TECHNICAL_SERVICE_KANBAN_COLUMNS = [
  { id: 'new', label: 'Yeni' },
  { id: 'assignment_pending', label: 'Onay Bekleniyor' },
  { id: 'assigned', label: 'Servis Atandı' },
  { id: 'final_check', label: 'Son Kontrol' },
  { id: 'completed', label: 'Tamamlandı' },
  { id: 'review', label: 'İnceleniyor' },
  { id: 'cancelled', label: 'İptal' },
] as const

export type TechnicalServiceKanbanColumnId = (typeof TECHNICAL_SERVICE_KANBAN_COLUMNS)[number]['id']

const includesAny = (value: string, keywords: string[]) =>
  keywords.some((keyword) => value.includes(normalizeTechnicalServiceText(keyword)))

const requestSignalText = (request: ServiceRequest) => normalizeTechnicalServiceText([
  request.status,
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
  request.pendingReason,
  request.rescheduleReason,
  request.documentStatus,
  request.photoStatus,
  request.completionBlockReason,
  request.incompleteReason,
  request.cancellationReason,
  ...(request.auditLogs ?? []).flatMap((log) => [log.action_type, log.note]),
].filter(Boolean).join(' '))

export const hasTechnicalServiceTechnician = (request: ServiceRequest) =>
  Boolean(request.technicianId || (request.technician && normalizeTechnicalServiceText(request.technician) !== 'atanmadi'))

export const isTechnicalServiceTechnicianApproved = (request: ServiceRequest) => {
  if (request.technicianApprovedAt) {
    return true
  }

  const approvalFieldText = normalizeTechnicalServiceText([
    request.technicianApprovalStatus,
    request.technicianConfirmationStatus,
  ].filter(Boolean).join(' '))
  const signalText = requestSignalText(request)

  if (
    includesAny(approvalFieldText, ['onaylandi', 'onayladı', 'kabul', 'accepted', 'approved']) &&
    !includesAny(approvalFieldText, ['bekleniyor', 'pending', 'redd', 'reject', 'revize'])
  ) {
    return true
  }

  return includesAny(signalText, [
    'usta onaylandi',
    'usta onayladı',
    'usta kabul',
    'servis onaylandi',
    'servis onayladı',
    'servis kabul',
    'teknisyen onaylandi',
    'teknisyen onayladı',
    'technician approved',
    'technician accepted',
  ])
}

export const isTechnicalServiceCustomerApproved = (request: ServiceRequest) => {
  if (request.customerConfirmedAt || request.customerConfirmationMethod) {
    return true
  }

  const customerFieldText = normalizeTechnicalServiceText([
    request.customerContactStatus,
    request.customerClosureApprovalStatus,
  ].filter(Boolean).join(' '))
  const signalText = requestSignalText(request)

  if (
    includesAny(customerFieldText, ['customer_confirmed', 'onaylandi', 'onayladı', 'confirmed', 'approved']) &&
    !includesAny(customerFieldText, ['bekleniyor', 'pending', 'redd', 'reject', 'revize'])
  ) {
    return true
  }

  return includesAny(signalText, [
    'musteri onaylandi',
    'musteri onayladı',
    'müşteri onaylandi',
    'müşteri onayladı',
    'musteri onayi alindi',
    'musteri onayi alındı',
    'müşteri onayı alindi',
    'müşteri onayı alındı',
    'customer confirmed',
    'customer approved',
  ])
}

const hasPlanningSignal = (request: ServiceRequest, workflowText: string) => {
  if (request.scheduledAt || request.scheduledDate || request.customerPreferredDate) {
    return true
  }

  return includesAny(workflowText, [
    'müşteri onayı',
    'musteri onayi',
    'müşteri onayladı',
    'musteri onayladi',
    'randevu',
    'planlı',
    'planli',
    'schedule',
  ])
}

export function getTechnicalServiceKanbanColumn(request: ServiceRequest): TechnicalServiceKanbanColumnId {
  const statusText = normalizeTechnicalServiceText(request.status)
  const workflowText = requestSignalText(request)
  const combinedText = [statusText, workflowText].filter(Boolean).join(' ')
  const hasTechnician = hasTechnicalServiceTechnician(request)
  const technicianApproved = isTechnicalServiceTechnicianApproved(request)
  const customerApproved = isTechnicalServiceCustomerApproved(request)

  if (includesAny(combinedText, ['iptal'])) {
    return 'cancelled'
  }

  if (includesAny(combinedText, ['tamamlandı', 'tamamlandi'])) {
    return 'completed'
  }

  if (includesAny(combinedText, [
    'son kontrol',
    'kontrol bekliyor',
    'operasyon kontrol',
    'saha tamamlandi',
    'saha tamamlandı',
    'field completed',
    'field_completed',
    'evrak kontrol',
    'belge kontrol',
    'fotograf kontrol',
    'fotoğraf kontrol',
    'checklist tamamlandi',
    'checklist tamamlandı',
    'belge tamamlandi',
    'belge tamamlandı',
    'fotograf tamamlandi',
    'fotoğraf tamamlandı',
  ])) {
    return 'final_check'
  }

  if (includesAny(combinedText, ['inceleniyor', 'eksik', 'parça', 'parca', 'beklemede', 'revizyon', 'ikinci ziyaret'])) {
    return 'review'
  }

  if (hasTechnician && technicianApproved && customerApproved) {
    return 'assigned'
  }

  if (hasTechnician || hasPlanningSignal(request, combinedText)) {
    return 'assignment_pending'
  }

  return 'new'
}

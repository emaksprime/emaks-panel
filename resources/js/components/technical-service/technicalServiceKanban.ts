import type { ServiceRequest } from './types'
import { normalizeTechnicalServiceText } from './utils'

export const TECHNICAL_SERVICE_KANBAN_COLUMNS = [
  { id: 'new', label: 'Yeni' },
  { id: 'assignment_pending', label: 'Servis Atanacak' },
  { id: 'assigned', label: 'Servis Atandı' },
  { id: 'final_check', label: 'Son Kontrol' },
  { id: 'completed', label: 'Tamamlandı' },
  { id: 'review', label: 'İnceleniyor' },
  { id: 'cancelled', label: 'İptal' },
] as const

export type TechnicalServiceKanbanColumnId = (typeof TECHNICAL_SERVICE_KANBAN_COLUMNS)[number]['id']

const includesAny = (value: string, keywords: string[]) =>
  keywords.some((keyword) => value.includes(normalizeTechnicalServiceText(keyword)))

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
  const workflowText = normalizeTechnicalServiceText(request.workflowStatus)
  const combinedText = [statusText, workflowText].filter(Boolean).join(' ')
  const hasTechnician = Boolean(request.technicianId || (request.technician && normalizeTechnicalServiceText(request.technician) !== 'atanmadi'))

  if (includesAny(combinedText, ['iptal'])) {
    return 'cancelled'
  }

  if (includesAny(combinedText, ['tamamlandı', 'tamamlandi'])) {
    return 'completed'
  }

  if (includesAny(combinedText, ['son kontrol', 'kontrol', 'kapanış', 'kapanis', 'onay', 'evrak', 'fotoğraf', 'fotograf'])) {
    return 'final_check'
  }

  if (includesAny(combinedText, ['inceleniyor', 'eksik', 'parça', 'parca', 'beklemede', 'revizyon', 'ikinci ziyaret'])) {
    return 'review'
  }

  if (hasTechnician && !includesAny(combinedText, ['tamamlandı', 'tamamlandi', 'iptal'])) {
    return 'assigned'
  }

  if (!hasTechnician && hasPlanningSignal(request, combinedText)) {
    return 'assignment_pending'
  }

  return 'new'
}

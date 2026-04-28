export type ServiceType = 'Montaj' | 'Arıza' | 'Kontrol'

export type ServiceStatus = 'Yeni' | 'Atandı' | 'Randevulu' | 'Devam Ediyor' | 'Tamamlandı' | 'İptal'

export type ServicePriority = 'Düşük' | 'Orta' | 'Yüksek' | 'Kritik'
export type ServiceRiskLevel = 'Düşük' | 'Orta' | 'Yüksek' | 'Kritik'

export type ServiceRequest = {
  id: string
  mrn: string
  customer: string
  phone: string
  city: string
  district: string
  product: string
  serialNumber: string
  serviceType: ServiceType
  priority: ServicePriority
  technician: string
  appointment: string
  status: ServiceStatus
  sla: string
  address: string
  model: string
  channel: string
  notes: string
  riskLevel: ServiceRiskLevel
}

export type ServiceRequestEvent = {
  id: string
  event_type: string
  title: string
  note?: string | null
  from_status?: string | null
  to_status?: string | null
  author_user_id?: number | null
  created_at: string
}

export type ServiceFilters = {
  search: string
  serviceType: ServiceType | ''
  status: ServiceStatus | ''
}

export type SummaryItem = {
  label: string
  value: string
  tone: 'default' | 'accent' | 'warning' | 'positive'
  description: string
}

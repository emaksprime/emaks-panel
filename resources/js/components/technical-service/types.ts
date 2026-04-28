export type ServiceType = 'Montaj' | 'Arıza' | 'Kontrol'

export type ServiceStatus = 'Yeni' | 'Atandı' | 'Randevulu' | 'Devam Ediyor' | 'Tamamlandı'

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
  technician: string
  appointment: string
  status: ServiceStatus
  sla: string
  address: string
  model: string
  channel: string
  notes: string
  riskLevel: 'Yüksek' | 'Orta' | 'Düşük'
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

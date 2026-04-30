export type ServiceType = 'Montaj' | 'Arıza' | 'Kontrol'

export type ServiceStatus = 'Yeni' | 'Atandı' | 'Randevulu' | 'Devam Ediyor' | 'Tamamlandı' | 'İptal'
export type ServiceStatusFilter = '' | 'unassigned' | 'today_installations' | 'scheduled' | 'Tamamlandı' | 'İptal'

export type ServicePriority = 'Düşük' | 'Orta' | 'Yüksek' | 'Kritik'

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
  address: string
  model: string
  channel: string
  notes: string
  scheduledAt?: string | null
  createdAt?: string | null
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
  status: ServiceStatusFilter
}

export type SummaryItem = {
  label: string
  value: string
  tone: 'default' | 'accent' | 'warning' | 'positive'
  description: string
}

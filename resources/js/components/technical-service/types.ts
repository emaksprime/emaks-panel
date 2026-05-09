export type ServiceType = 'Montaj' | 'Arıza' | 'Kontrol'

export type ServiceStatus = string
export type ServiceStatusFilter = '' | 'unassigned' | 'today_installations' | 'scheduled' | 'Tamamlandı' | 'İptal'

export type ServicePriority = 'Düşük' | 'Orta' | 'Yüksek' | 'Kritik'
export type WorkflowStatus = string
export type WorkflowActionKey = string

export type ServiceAuditLog = {
  id: string | number
  entity_type: string
  entity_id: string | number
  action_type: string
  old_value?: Record<string, unknown> | null
  new_value?: Record<string, unknown> | null
  user_id?: number | null
  user_name?: string | null
  note?: string | null
  created_at: string
}

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
  technicianId?: string | null
  appointment: string
  status: ServiceStatus
  address: string
  model: string
  channel: string
  notes: string
  scheduledAt?: string | null
  scheduledDate?: string | null
  scheduledTime?: string | null
  createdAt?: string | null
  completedAt?: string | null
  travelRoundTripKm?: number | null
  travelBillableKm?: number | null
  travelFeeAmount?: number | null
  technicianPaymentAmount?: number | null
  travelCalculationSource?: string | null
  travelCalculatedAt?: string | null
  technicianApprovalStatus?: string | null
  technicianApprovedAt?: string | null
  technicianRevisionRequestedAt?: string | null
  technicianRevisionNote?: string | null
  technicianConfirmationStatus?: string | null
  revisionRequested?: boolean | null
  rescheduleRequested?: boolean | null
  workflowStatus?: WorkflowStatus | null
  nextAction?: string | null
  slaDueAt?: string | null
  slaStatus?: 'normal' | 'yaklaşan' | 'geciken' | string | null
  customerContactStatus?: string | null
  customerContactedAt?: string | null
  customerContactNote?: string | null
  customerConfirmedAt?: string | null
  customerConfirmationMethod?: string | null
  fieldStatus?: string | null
  fieldStartedAt?: string | null
  fieldArrivedAt?: string | null
  fieldCompletedAt?: string | null
  missingInfoReason?: string | null
  pendingReason?: string | null
  requiresReschedule?: boolean | null
  rescheduleReason?: string | null
  documentStatus?: string | null
  photoStatus?: string | null
  customerClosureApprovalStatus?: string | null
  customerClosureApprovedAt?: string | null
  cancellationReason?: string | null
  latestEvent?: string | null
  allowedWorkflowActions?: Record<WorkflowActionKey, { label: string, target: WorkflowStatus }> | null
  allowedWorkflowTransitions?: WorkflowStatus[] | null
  auditLogs?: ServiceAuditLog[] | null
  document?: unknown
  documents?: unknown
  photo?: unknown
  photos?: unknown
}

export type ServiceTechnician = {
  id: string
  name: string
  first_name?: string | null
  last_name?: string | null
  phone?: string | null
  city?: string | null
  district?: string | null
  address?: string | null
  google_plus_code?: string | null
  google_formatted_address?: string | null
  default_start_address?: string | null
  default_start_plus_code?: string | null
  active: boolean
  note?: string | null
  latitude?: number | string | null
  longitude?: number | string | null
  start_latitude?: number | string | null
  start_longitude?: number | string | null
  mikro_cari_kodu?: string | null
  mikro_cari_adi?: string | null
}

export type ServiceRequestEvent = {
  id: string
  event_type: string
  title: string
  note?: string | null
  from_status?: string | null
  to_status?: string | null
  author_user_id?: number | null
  metadata?: Record<string, unknown> | null
  updated_at?: string
  created_at: string
}

export type MikroMountStatus = 'Montaj Dahil' | 'Montaj Hariç' | 'Montaj Sonradan Dahil' | 'Seri No Bulunamadı'

export type MikroSerialHistoryEvent = {
  event_type: string
  event_date?: string | null
  title: string
  description?: string | null
  stok_adi?: string | null
  cari_kodu?: string | null
  cari_unvani?: string | null
  evrak_seri?: string | null
  evrak_sira?: string | null
  siparis_seri?: string | null
  siparis_sira?: string | null
  fatura_seri?: string | null
  fatura_sira?: string | null
  hareket_grup_kodu_1?: string | null
  sorumluluk_kodu?: string | null
  is_latest_valid_sale: boolean
}

export type MikroMountCheckResult = {
  found: boolean
  montaj_durumu: MikroMountStatus
  montaj_ek_aciklama?: string | null
  cihaz_seri_no?: string | null
  stok_kodu?: string | null
  stok_adi?: string | null
  irsaliye_tarihi?: string | null
  irsaliye_seri?: string | null
  irsaliye_sira?: string | null
  fatura_tarihi?: string | null
  fatura_seri?: string | null
  fatura_sira?: string | null
  siparis_tarihi?: string | null
  siparis_seri?: string | null
  siparis_sira?: string | null
  asil_cari_kodu?: string | null
  asil_cari_unvani?: string | null
  sonradan_montaj_kaynagi?: string | null
  sonradan_montaj_tarihi?: string | null
  sonradan_montaj_aciklamasi?: string | null
  sonradan_montaj_cari_kodu?: string | null
  sonradan_montaj_cari_unvani?: string | null
  farkli_cari_uyarisi: boolean
  history?: MikroSerialHistoryEvent[]
}

export type MikroSerialHistoryResponse = {
  serial_no: string
  decision: MikroMountCheckResult
  items: MikroSerialHistoryEvent[]
}

export type WarrantyStatus =
  | 'Garanti Başlamadı'
  | 'Garanti Aktif'
  | 'Garanti Bitti'
  | 'Değişimle Kapandı'
  | 'Yeni SN’ye Devredildi'
  | 'Yeniden Satış Bekliyor'

export type WarrantySerialResponse = {
  serial_no: string
  status: WarrantyStatus
  warranty_started_at?: string | null
  warranty_ends_at?: string | null
  remaining_days?: number | null
  warranty_period_months: number
  source?: string | null
  last_sale?: {
    date?: string | null
    customer_code?: string | null
    customer_name?: string | null
    document_no?: string | null
    fingerprint?: string | null
  } | null
  installation: {
    completed_at?: string | null
    source?: string | null
  }
  warnings: string[]
  card?: Record<string, unknown> | null
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

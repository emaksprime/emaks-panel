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
  technicianId?: string | null
  appointment: string
  status: ServiceStatus
  address: string
  model: string
  channel: string
  notes: string
  scheduledAt?: string | null
  createdAt?: string | null
  completedAt?: string | null
  travelRoundTripKm?: number | null
  travelBillableKm?: number | null
  travelFeeAmount?: number | null
  travelCalculationSource?: string | null
  travelCalculatedAt?: string | null
  qrSource?: ServiceRequestQrSource | null
  saleAndPayment?: ServiceRequestSaleAndPayment | null
  productInfo?: ServiceRequestProductInfo | null
  documentInfo?: ServiceRequestDocumentInfo | null
  operationControl?: ServiceRequestOperationControl | null
  assignmentBlockers?: ServiceRequestAssignmentBlockers | null
  invoiceSerials?: ServiceRequestInvoiceSerials | null
  location?: ServiceRequestLocation | null
  doorPhotos?: ServiceRequestDoorPhoto[]
  routeQuote?: ServiceRequestRouteQuote | null
}

export type ServiceRequestQrSource = {
  source_channel?: string | null
  qr_link_id?: number | string | null
  mount_session_id?: number | string | null
  current_serial_state?: string | null
  has_current_sale?: boolean | null
  invoice_customer_type?: string | null
}

export type ServiceRequestProductInfo = {
  serial_number?: string | null
  product_name?: string | null
  product_model?: string | null
  brand?: string | null
  stock_code?: string | null
  activation_code?: string | null
}

export type ServiceRequestSaleAndPayment = {
  sale_mount_status?: string | null
  sale_mount_label?: string | null
  mount_payment_status?: string | null
  mount_payment_label?: string | null
  payment_reference?: string | null
  payment_provider?: string | null
  paid_at?: string | null
}

export type ServiceRequestDocumentInfo = {
  invoice_display_no?: string | null
  dispatch_display_no?: string | null
  order_display_no?: string | null
}

export type ServiceRequestOperationControl = {
  payment_checked?: 'yes' | 'no' | 'unreviewed' | null
  address_checked?: 'yes' | 'no' | 'unreviewed' | null
  door_photos_checked?: 'compatible' | 'incompatible' | 'unreviewed' | null
  missing_info?: 'yes' | 'no' | 'unreviewed' | null
  customer_call_required?: 'yes' | 'no' | 'unreviewed' | null
  schedule_update_required?: 'yes' | 'no' | 'unreviewed' | null
  note?: string | null
  checked_by_user_id?: number | string | null
  checked_at?: string | null
}

export type ServiceRequestAssignmentBlockers = {
  payment_check_required?: boolean
  door_photo_check_required?: boolean
  messages?: string[]
}

export type ServiceRequestInvoiceSerial = {
  id?: number | string | null
  serial_number?: string | null
  product_name?: string | null
  product_model?: string | null
  brand?: string | null
  stock_code?: string | null
  invoice_series?: string | null
  invoice_number?: string | null
  customer_selected?: boolean
  customer_selectable?: boolean
  customer_visible?: boolean
  hidden_reason?: string | null
  hidden_reason_label?: string | null
  responsibility_code?: string | null
  normalized_responsibility_code?: string | null
  is_responsibility_blocked?: boolean
  operation_added?: boolean
  operation_added_by?: number | string | null
  operation_added_at?: string | null
  customer_phone?: string | null
  linked_mrn?: string | null
  operation_note?: string | null
  is_primary?: boolean
  is_returned?: boolean
  return_note?: string | null
  return_date?: string | null
  return_document_no?: string | null
  is_current_latest_sale?: boolean | null
  latest_sale_conflict?: boolean
  operation_warning?: string | null
  warning_labels?: string[]
  invoice_customer_type?: string | null
  color_status?: 'green' | 'orange' | 'red' | string | null
}

export type ServiceRequestInvoiceSerials = {
  selected_serials?: ServiceRequestInvoiceSerial[]
  other_serials?: ServiceRequestInvoiceSerial[]
  hidden_serials?: ServiceRequestInvoiceSerial[]
  returned_serials?: ServiceRequestInvoiceSerial[]
  all_invoice_serials?: ServiceRequestInvoiceSerial[]
  added_serial_count?: number
  addable_serial_count?: number
  returned_serial_count?: number
  has_returned?: boolean
  has_multi_product?: boolean
  check_error?: string | null
}

export type ServiceRequestLocation = {
  latitude?: number | string | null
  longitude?: number | string | null
  place_id?: string | null
  formatted_address?: string | null
  map_url?: string | null
  source?: string | null
  accuracy?: string | null
  note?: string | null
  building_no?: string | null
  apartment_no?: string | null
  door_no?: string | null
  floor_no?: string | null
  site_name?: string | null
  shared?: boolean
}

export type ServiceRequestDoorPhoto = {
  id?: number | string | null
  field_code?: string | null
  category?: string | null
  original_name?: string | null
  mime?: string | null
  size?: number | string | null
  url?: string | null
  preview_url?: string | null
  download_url?: string | null
}

export type ServiceRequestRouteQuote = {
  ok?: boolean
  id?: number | string | null
  status?: 'calculated' | 'failed' | 'missing_location' | 'missing_api_key' | string | null
  distance_km?: number | null
  distance_meters?: number | null
  duration_seconds?: number | null
  duration_text?: string | null
  threshold_km?: number | null
  extra_km?: number | null
  travel_fee_required?: boolean
  fee_per_km?: number | null
  fee_amount?: number | null
  provider?: string | null
  calculated_at?: string | null
  message?: string | null
}

export type ServiceTechnician = {
  id: string
  name: string
  first_name?: string | null
  last_name?: string | null
  technician_type?: string | null
  city_plate_code?: string | null
  priority?: number | string | null
  phone?: string | null
  phone_e164?: string | null
  phone_display?: string | null
  city?: string | null
  district?: string | null
  address?: string | null
  location_code?: string | null
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
  location_source?: string | null
  route_note?: string | null
  mikro_cari_kodu?: string | null
  mikro_cari_adi?: string | null
  cari_code?: string | null
  cari_title?: string | null
  cari_address?: string | null
  cari_city_district_country?: string | null
  display_name?: string | null
  import_status?: string | null
  import_note?: string | null
  needs_review?: boolean | null
  source_key?: string | null
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

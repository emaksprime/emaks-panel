export type ServiceType = 'Montaj' | 'Servis' | 'Arıza' | 'Kontrol'

export type ServiceStatus = 'Yeni' | 'Atandı' | 'Randevulu' | 'Devam Ediyor' | 'Tamamlandı' | 'İptal'
export type ServiceStatusFilter = '' | 'unassigned' | 'today_installations' | 'scheduled' | 'Tamamlandı' | 'İptal'
export type ServiceRequestOpsColumn = 'new' | 'assignment_pending' | 'assigned' | 'final_check' | 'completed' | 'review' | 'cancelled' | string | null
export type ServiceRequestActionOwner = 'ops' | 'technician' | 'customer' | 'system' | 'completed' | string | null
export type ServiceRequestActionBucket = 'ops_action' | 'technician_action' | 'customer_waiting' | 'part_or_repeat' | 'scheduled' | 'completed' | 'system' | string | null
export type ServiceRequestCardTone = 'warning' | 'neutral' | 'info' | 'success' | 'muted' | 'danger' | string | null

export type ServiceRequestVisibleSections = {
  warranty?: boolean
  warranty_mode?: 'hidden' | 'compact' | 'full' | string | null
  service_part_charge?: boolean
  part_request_decision?: boolean
  earnings_breakdown?: boolean
  is_service_visit?: boolean
  operation_mount_controls?: boolean
  payment_control?: boolean
  door_photo_control?: boolean
  address_control?: boolean
  schedule_control?: boolean
  manual_checks?: Array<{
    code: string
    label: string
  }>
}

export type ServiceRequestCancelContext = {
  exists: boolean
  is_cancelled?: boolean
  is_cancel_review?: boolean
  is_reopened?: boolean
  cancelled_request_id?: number | string | null
  cancelled_code?: string | null
  previous_cancelled_request_id?: number | string | null
  previous_cancelled_code?: string | null
  root_mrn?: string | null
  customer_name?: string | null
  phone?: string | null
  city?: string | null
  district?: string | null
  product_name?: string | null
  product_model?: string | null
  serial_no?: string | null
  activation_code?: string | null
  last_technician_name?: string | null
  last_technician_phone?: string | null
  last_appointment_at?: string | null
  cancel_reason?: string | null
  previous_cancel_reason?: string | null
  cancelled_at?: string | null
  previous_cancelled_at?: string | null
  cancelled_by_label?: string | null
  earning_excluded?: boolean
  earning_excluded_label?: string | null
  previous_stage_label?: string | null
  current_stage_label?: string | null
  current_request_id?: number | string | null
  current_code?: string | null
  related_current_request_id?: number | string | null
  related_current_code?: string | null
  next_ops_message?: string | null
  summary?: string | null
}

export type ServiceRequestCurrentStageSummary = {
  label?: string | null
  summary?: string | null
}

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
  technicianPhone?: string | null
  technicianApprovalStatus?: string | null
  technicianApprovedAt?: string | null
  technicianRevisionRequestedAt?: string | null
  technicianRevisionNote?: string | null
  technicianConfirmationStatus?: string | null
  appointment: string
  status: ServiceStatus
  workflowStatus?: string | null
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
  technicianPaymentAmount?: number | null
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
  fieldCompletionDocuments?: ServiceRequestFieldCompletionDocument[]
  previousFieldCompletionDocuments?: ServiceRequestFieldCompletionDocument[]
  routeFeeConfig?: ServiceRequestRouteFeeConfig | null
  routeQuote?: ServiceRequestRouteQuote | null
  nextActionPayload?: ServiceRequestNextAction | null
  assignmentOffer?: ServiceRequestAssignmentOffer | null
  technicianJobCard?: ServiceRequestTechnicianJobCard | null
  settlement?: ServiceRequestSettlement | null
  technicianRevisionOffer?: ServiceRequestTechnicianRevisionOffer | null
  earningBreakdown?: ServiceRequestEarningBreakdown | null
  financeSummary?: ServiceRequestFinanceSummary | null
  partnerPortalActions?: ServiceRequestPartnerPortalAction[]
  partRequests?: ServiceRequestPartRequest[]
  activePartRequest?: ServiceRequestPartRequest | null
  kanbanColumn?: ServiceRequestOpsColumn
  displayActionLabel?: string | null
  displayTags?: ServiceRequestDisplayTag[]
  actionOwner?: ServiceRequestActionOwner
  actionOwnerLabel?: string | null
  actionPriority?: number | null
  actionBucket?: ServiceRequestActionBucket
  cardTone?: ServiceRequestCardTone
  actionTitle?: string | null
  actionReason?: string | null
  actionFilterKeys?: string[]
  operationalState?: ServiceRequestOperationalState | null
  cancelContext?: ServiceRequestCancelContext | null
  currentStageSummary?: ServiceRequestCurrentStageSummary | null
  visibleSections?: ServiceRequestVisibleSections | null
  serviceVisitHistory?: ServiceRequestServiceVisitHistory | null
  auditLogs?: ServiceRequestAuditLog[] | null
  adminOverrides?: ServiceRequestAdminOverride[] | null
  adminOverrideSummary?: {
    pending_count?: number | null
    applied_count?: number | null
    rejected_count?: number | null
  } | null
  fieldCorrectionPolicy?: {
    fields?: Array<{
      key: string
      label: string
      group?: string | null
      sensitive?: boolean | null
      recompute_flags?: string[] | null
    }>
    blocked_fields?: string[]
  } | null
  attention?: {
    sort_priority?: number | null
    attention_level?: 'normal' | 'info' | 'warning' | 'critical' | string | null
    attention_reason?: string | null
    last_action_at?: string | null
    action?: string | null
  } | null
}

export type ServiceRequestSettlement = {
  id: number | string
  technical_service_request_id?: number | string | null
  technical_service_assignment_offer_id?: number | string | null
  technical_service_technician_id?: number | string | null
  b2b_partner_id?: number | string | null
  currency?: string | null
  labor_earning_amount?: number | null
  route_earning_amount?: number | null
  technician_earning_total?: number | null
  customer_collection_amount?: number | null
  customer_direct_to_technician_amount?: number | null
  customer_direct_assumed_paid_amount?: number | null
  company_payable_amount?: number | null
  company_paid_amount?: number | null
  company_remaining_amount?: number | null
  overpay_warning_amount?: number | null
  overpay_requires_review?: boolean
  review_reason?: string | null
  status?: string | null
  status_label?: string | null
  payer_state_key?: string | null
  payer_state_label?: string | null
  payer_state_description?: string | null
  payment_instruction_for_customer?: string | null
  customer_should_pay_technician?: boolean
  company_collected_amount?: number | null
  company_collected_source?: string | null
  active_customer_direct_to_technician_amount?: number | null
  pending_payment_total?: number | null
  cancelled_payment_total?: number | null
  wp_payment_message_trigger?: string | null
  wp_payment_message_ready?: boolean
  review_decision?: {
    decision?: string | null
    decision_label?: string | null
    reason?: string | null
    reviewed_at?: string | null
    reviewed_by?: number | string | null
    reviewed_by_name?: string | null
    customer_direct_to_technician_amount?: number | null
    company_payable_amount?: number | null
    overpay_warning_amount?: number | null
    requires_review_after_decision?: boolean | null
  } | null
  settlement_source?: string | null
  metadata?: Record<string, unknown> | null
  created_at?: string | null
  updated_at?: string | null
}

export type ServiceRequestAuditLog = {
  id: number | string
  entity_type?: string | null
  entity_id?: number | string | null
  action_type: string
  action_label?: string | null
  old_value?: Record<string, unknown> | null
  new_value?: Record<string, unknown> | null
  user_id?: number | null
  user_name?: string | null
  note?: string | null
  created_at: string
}

export type ServiceRequestAdminOverride = {
  id: number | string
  request_id?: number | string | null
  root_request_id?: number | string | null
  request_code?: string | null
  root_mrn?: string | null
  field_key: string
  field_label: string
  field_group?: string | null
  source: string
  source_label?: string | null
  status: 'pending' | 'applied' | 'rejected' | string
  status_label?: string | null
  old_value?: { value?: unknown, display?: string | null } | null
  requested_value?: { value?: unknown, display?: string | null } | null
  new_value?: { value?: unknown, display?: string | null } | null
  recompute_flags?: string[] | null
  recompute_flag_labels?: string[] | null
  reason?: string | null
  approval_note?: string | null
  rejection_reason?: string | null
  requested_by?: number | null
  requested_actor_label?: string | null
  approved_by?: number | null
  approved_actor_label?: string | null
  applied_by?: number | null
  applied_actor_label?: string | null
  rejected_by?: number | null
  rejected_actor_label?: string | null
  requested_at?: string | null
  approved_at?: string | null
  applied_at?: string | null
  rejected_at?: string | null
  created_at?: string | null
}

export type ServiceRequestServiceVisitSummary = {
  id: number | string
  mrn: string
  root_mrn?: string | null
  service_code?: string | null
  service_visit_reason?: string | null
  service_visit_reason_label?: string | null
  status?: string | null
  workflow_status?: string | null
  code?: string | null
  type?: 'root_mrn' | 'srv' | string | null
  label?: string | null
  reason?: string | null
  status_label?: string | null
  is_current?: boolean | null
  sequence?: number | string | null
  completed_at?: string | null
  created_at?: string | null
  latest_event?: string | null
}

export type ServiceRequestServiceVisitHistoryRecord = ServiceRequestServiceVisitSummary & {
  customer_name?: string | null
  technician_name?: string | null
  technician_phone?: string | null
  scheduled_at?: string | null
  field_started_at?: string | null
  technician_arrived_at?: string | null
  field_completed_at?: string | null
  technician_completed_at?: string | null
  completion_note?: string | null
  documents?: ServiceRequestFieldCompletionDocument[]
  events?: ServiceRequestEvent[]
}

export type ServiceRequestServiceVisitHistory = {
  root_mrn?: string | null
  service_code?: string | null
  reason?: string | null
  reason_label?: string | null
  parent_request?: ServiceRequestServiceVisitSummary | null
  root_request?: ServiceRequestServiceVisitSummary | null
  direct_parent_request?: ServiceRequestServiceVisitSummary | null
  current_request?: ServiceRequestServiceVisitSummary | null
  items?: ServiceRequestServiceVisitSummary[]
  parent_events?: ServiceRequestEvent[]
  parent_part_requests?: ServiceRequestPartRequest[]
  sibling_service_visits?: ServiceRequestServiceVisitSummary[]
  history_records?: ServiceRequestServiceVisitHistoryRecord[]
}

export type ServiceRequestDisplayTag = {
  label: string
  tone?: 'neutral' | 'blue' | 'green' | 'amber' | 'rose' | 'purple' | string | null
  icon?: 'multi' | 'warning' | string | null
  important?: boolean | null
}

export type ServiceRequestOperationalState = {
  canonical_stage?: string | null
  ops_column?: ServiceRequestOpsColumn
  partner_column?: string | null
  display_status_label?: string | null
  display_action_label?: string | null
  display_tags?: ServiceRequestDisplayTag[]
  attention_level?: string | null
  attention_reason?: string | null
  sort_priority?: number | null
  active_action_required?: boolean | null
  action_owner?: 'ops' | 'technician' | 'customer' | 'system' | 'none' | string | null
  action_priority?: 'critical' | 'high' | 'normal' | 'low' | string | null
  dashboard_action_owner?: ServiceRequestActionOwner
  action_owner_label?: string | null
  action_priority_score?: number | null
  action_bucket?: ServiceRequestActionBucket
  card_tone?: ServiceRequestCardTone
  action_title?: string | null
  action_reason?: string | null
  action_filter_keys?: string[]
  requires_ops_action?: boolean | null
  requires_technician_action?: boolean | null
  requires_customer_action?: boolean | null
  action_label?: string | null
  action_hint?: string | null
  is_completed?: boolean | null
  is_pending_final_check?: boolean | null
  is_appointment_confirmed?: boolean | null
}

export type ServiceRequestAssignmentOffer = {
  id: number | string
  technical_service_request_id?: number | string | null
  technical_service_technician_id?: number | string | null
  technician_name?: string | null
  route_quote_id?: number | string | null
  labor_amount: number
  route_fee_amount: number
  total_amount: number
  currency: string
  status: string
  note?: string | null
  sent_at?: string | null
  metadata?: Record<string, unknown>
  message_payload?: Record<string, unknown>
  message_text?: string | null
  job_link?: string | null
  dispatch_status?: string | null
}

export type ServiceRequestTechnicianJobCard = {
  ready: boolean
  blocker_code?: string | null
  blocker_message?: string | null
  partner_id?: number | string | null
  technician_id?: number | string | null
  partner_technician_link_id?: number | string | null
  canonical_url?: string | null
  ops_support_url?: string | null
  preview_url?: string | null
}

export type ServiceRequestTechnicianRevisionOffer = {
  exists: boolean
  id?: number | string | null
  status: 'pending' | 'accepted' | 'rejected' | 'countered' | 'resolved' | string
  status_label?: string | null
  technician_id?: number | string | null
  technician_name?: string | null
  labor_earning?: number | null
  route_earning?: number | null
  total_earning?: number | null
  note?: string | null
  requested_at?: string | null
  resolved_at?: string | null
  resolved_by?: number | string | null
  source?: 'partner_portal' | 'ops_update' | 'assignment_offer' | 'event_payload' | string | null
}

export type ServiceRequestEarningBreakdownRow = {
  id: number | string
  mrn: string
  display_mrn?: string | null
  service_code?: string | null
  kind?: 'mount' | 'service' | string | null
  kind_label?: string | null
  is_current?: boolean
  is_parent?: boolean
  technician_id?: number | string | null
  technician_name?: string | null
  technician_source?: string | null
  labor_amount: number
  route_fee_amount: number
  total_amount: number
  labor_amount_label?: string | null
  route_fee_amount_label?: string | null
  total_amount_label?: string | null
  status?: string | null
  status_label?: string | null
  payout_included?: boolean
  payout_approval_status?: string | null
  payout_approval_status_label?: string | null
  completed_at?: string | null
}

export type ServiceRequestEarningBreakdown = {
  root_request_id?: number | string | null
  root_mrn?: string | null
  current_visit?: ServiceRequestEarningBreakdownRow | null
  rows: ServiceRequestEarningBreakdownRow[]
  root_total: {
    labor_amount: number
    route_fee_amount: number
    total_amount: number
    labor_amount_label?: string | null
    route_fee_amount_label?: string | null
    total_amount_label?: string | null
    job_count?: number
    technician_count?: number
    technician_names?: string[]
    is_multi_technician?: boolean
    payout_approval_required?: boolean
    payout_approval_status?: string | null
    payout_approval_status_label?: string | null
    included_job_count?: number
    approved_job_count?: number
    excluded_job_count?: number
  }
}

export type ServiceRequestFinanceCollection = {
  mount_amount: number
  service_amount: number
  part_amount: number
  extra_amount: number
  total_amount: number
  mount_amount_label?: string | null
  service_amount_label?: string | null
  part_amount_label?: string | null
  extra_amount_label?: string | null
  total_amount_label?: string | null
  has_collection?: boolean
  has_mount_collection?: boolean
  has_service_charge?: boolean
  has_part_charge?: boolean
  has_extra_charge?: boolean
}

export type ServiceRequestFinancePayout = {
  labor_amount: number
  route_fee_amount: number
  total_amount: number
  labor_amount_label?: string | null
  route_fee_amount_label?: string | null
  total_amount_label?: string | null
  status?: string | null
  status_label?: string | null
  payout_status?: string | null
  payout_status_label?: string | null
  payment_status?: string | null
  payment_status_label?: string | null
  paid_at?: string | null
  technician_id?: number | string | null
  technician_name?: string | null
  is_confirmed?: boolean
  is_draft?: boolean
  source?: string | null
  job_count?: number
  technician_count?: number
  technician_names?: string[]
  is_multi_technician?: boolean
}

export type ServiceRequestFinanceNetMargin = {
  amount: number
  amount_label?: string | null
  is_negative?: boolean
}

export type ServiceRequestFinanceSummary = {
  currency?: string | null
  current_visit_customer_collection?: ServiceRequestFinanceCollection
  current_visit_locksmith_payout?: ServiceRequestFinancePayout
  current_visit_operation_cost?: ServiceRequestFinancePayout & { applies?: boolean }
  root_total_customer_collection?: ServiceRequestFinanceCollection
  root_total_locksmith_payout?: ServiceRequestFinancePayout
  current_visit: {
    is_service_visit?: boolean
    technician_id?: number | string | null
    technician_name?: string | null
    customer_collection: ServiceRequestFinanceCollection
    locksmith_payout: ServiceRequestFinancePayout
    operation_cost?: (ServiceRequestFinancePayout & { applies?: boolean }) | null
    warranty_customer_charge?: {
      service_amount: number
      part_amount: number
      total_amount: number
      service_amount_label?: string | null
      part_amount_label?: string | null
      total_amount_label?: string | null
      covered_by_warranty?: boolean
    } | null
    confirmed_locksmith_payout?: ServiceRequestFinancePayout | null
    draft_locksmith_payout?: ServiceRequestFinancePayout | null
    payout_status?: string | null
    payout_status_label?: string | null
    payment_status?: string | null
    payment_status_label?: string | null
    paid_at?: string | null
    completed_earning_snapshot?: Record<string, unknown> | null
    net_margin: ServiceRequestFinanceNetMargin
    warranty_covered?: boolean
    warranty_note?: string | null
    operation_cost_note?: string | null
  }
  root_total: {
    customer_collection: ServiceRequestFinanceCollection
    locksmith_payout: ServiceRequestFinancePayout
    net_margin: ServiceRequestFinanceNetMargin
  }
}

export type ServiceRequestPartnerPortalAction = {
  id: number | string
  partner_id?: number | string | null
  user_id?: number | string | null
  technical_service_technician_id?: number | string | null
  action: string
  action_label?: string | null
  status: string
  status_label?: string | null
  note?: string | null
  payload?: Record<string, unknown>
  created_at?: string | null
  updated_at?: string | null
}

export type ServiceRequestPartRequest = {
  id: number | string
  technical_service_request_id?: number | string | null
  root_request_id?: number | string | null
  request_serial_id?: number | string | null
  source_partner_action_id?: number | string | null
  status: string
  status_label: string
  next_action_label?: string | null
  part_name: string
  part_code?: string | null
  quantity: number
  reason?: string | null
  technician_note?: string | null
  ops_note?: string | null
  partner_message?: string | null
  shipment_provider?: string | null
  tracking_no?: string | null
  sent_at?: string | null
  received_at?: string | null
  requires_service_visit?: boolean
  service_visit_request_id?: number | string | null
  charge_decision?: 'free' | 'chargeable' | string | null
  charge_decision_label?: string | null
  service_amount?: number | null
  service_amount_label?: string | null
  part_amount?: number | null
  part_amount_label?: string | null
  total_amount?: number | null
  total_amount_label?: string | null
  customer_message?: string | null
  charge_status?: string | null
  is_payment_required?: boolean
  is_payment_paid?: boolean
  can_ship?: boolean
  can_create_service_visit?: boolean
  payment_id?: number | string | null
  payment_url?: string | null
  copy_url?: string | null
  payment_reference?: string | null
  provider_reference?: string | null
  payment_provider?: string | null
  paid_at?: string | null
  paid_amount?: number | null
  paid_amount_label?: string | null
  customer_charge?: {
    id?: number | string | null
    status?: string | null
    status_label?: string | null
    amount?: number | null
    service_amount?: number | null
    service_amount_label?: string | null
    part_amount?: number | null
    part_amount_label?: string | null
    total_amount?: number | null
    total_amount_label?: string | null
    payment_url?: string | null
    provider?: string | null
    provider_reference?: string | null
    payment_reference?: string | null
    paid_at?: string | null
    currency?: string | null
    message_send_count?: number | null
    last_message_sent_at?: string | null
  } | null
  created_at?: string | null
  updated_at?: string | null
}

export type ServiceRequestNextAction = {
  code?: string | null
  title?: string | null
  description?: string | null
  severity?: 'warning' | 'success' | 'danger' | 'info' | string | null
  primary_action?: string | null
  secondary_actions?: string[]
  blocking?: boolean
}

export type ServiceRequestRouteFeeConfig = {
  threshold_km?: number | null
  fee_per_km?: number | null
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
  mount_payment_received?: boolean
  payment_stage_label?: string | null
  paid_amount?: number | null
  paid_amount_label?: string | null
  payment_reference?: string | null
  payment_provider?: string | null
  paid_at?: string | null
  payment_status_label?: string | null
  payment_paid_at?: string | null
  ops_payment_check_label?: string | null
  payment_status?: ServiceRequestPaymentStatus | null
  payment_ownership?: ServiceRequestPaymentOwnership | null
  extra_mount_payment?: ServiceRequestExtraMountPayment | null
  mount_payments?: ServiceRequestMountPaymentSummary | null
  customer_charges?: ServiceRequestCustomerChargeSummary | null
  payment_summary?: ServiceRequestPaymentSummary | null
  technician_earning_message?: ServiceRequestTechnicianEarningMessage | null
}

export type ServiceRequestPaymentSummaryLine = {
  status?: string | null
  status_label?: string | null
  amount?: number | null
  amount_label?: string | null
  source?: string | null
}

export type ServiceRequestPaymentSummary = {
  mount?: ServiceRequestPaymentSummaryLine | null
  service?: ServiceRequestPaymentSummaryLine | null
  part?: ServiceRequestPaymentSummaryLine | null
  extra?: ServiceRequestPaymentSummaryLine | null
  total_customer_collection?: number | null
  total_customer_collection_label?: string | null
  has_mount_collection?: boolean
  has_service_charge?: boolean
  has_part_charge?: boolean
  has_extra_charge?: boolean
  paid_customer_payment_total?: number | null
  paid_customer_payment_total_label?: string | null
  pending_customer_payment_total?: number | null
  pending_customer_payment_total_label?: string | null
}

export type ServiceRequestPaymentOwnership = {
  payer_state_key?: string | null
  payer_state_label?: string | null
  payer_state_description?: string | null
  payment_instruction_for_customer?: string | null
  customer_should_pay_technician?: boolean
  company_collected_amount?: number | null
  company_collected_source?: string | null
  customer_direct_to_technician_amount?: number | null
  active_customer_direct_to_technician_amount?: number | null
  customer_direct_assumed_paid_amount?: number | null
  company_payable_amount?: number | null
  company_remaining_amount?: number | null
  pending_payment_total?: number | null
  cancelled_payment_total?: number | null
  paid_payment_count?: number
  pending_payment_count?: number
  cancelled_payment_count?: number
  wp_payment_message_trigger?: string | null
  wp_payment_message_ready?: boolean
}

export type ServiceRequestPaymentStatus = {
  is_paid?: boolean
  requires_payment?: boolean
  source?: string | null
  stage_label?: string | null
  amount?: number | null
  paid_at?: string | null
  pending_payment_id?: number | string | null
  latest_payment_id?: number | string | null
  message?: string | null
}

export type ServiceRequestExtraMountPayment = {
  id?: number | string | null
  request_id?: number | string | null
  mrn?: string | null
  request_code?: string | null
  root_mrn?: string | null
  serial_number?: string | null
  serial_no?: string | null
  customer_name?: string | null
  customer_phone?: string | null
  customer_email?: string | null
  status?: string | null
  status_label?: string | null
  amount?: number | null
  amount_label?: string | null
  currency?: string | null
  payment_url?: string | null
  copy_url?: string | null
  provider?: string | null
  provider_mode?: string | null
  provider_transport?: string | null
  provider_token?: string | null
  provider_reference?: string | null
  provider_payment_reference?: string | null
  provider_transaction_reference?: string | null
  provider_receipt_reference?: string | null
  provider_status?: string | null
  provider_last_synced_at?: string | null
  provider_sync_attempts?: number | null
  provider_last_sync_status?: string | null
  provider_last_sync_error?: string | null
  provider_sync_locked_at?: string | null
  provider_paid_confirmed_at?: string | null
  provider_sync_waiting?: boolean
  provider_sync_message?: string | null
  provider_label?: string | null
  provider_display_label?: string | null
  is_fake_provider?: boolean
  is_external_provider?: boolean
  can_open_payment_url?: boolean
  can_copy_payment_url?: boolean
  can_fake_complete_payment?: boolean
  can_cancel_payment?: boolean
  payment_action_kind?: 'fake_complete' | 'open_provider_url' | 'none' | string | null
  payment_action_label?: string | null
  payment_action_disabled_reason?: string | null
  copy_disabled_reason?: string | null
  fake_approve_url?: string | null
  paid_at?: string | null
  cancelled_at?: string | null
  cancelled_by_name?: string | null
  cancellation_reason?: string | null
  source?: string | null
  amount_source?: string | null
  reason?: string | null
  purpose?: string | null
  note?: string | null
  is_extra_payment?: boolean
  readonly?: boolean
  can_cancel?: boolean
  message_send_count?: number | null
  last_message_sent_at?: string | null
  selected_serial_ids?: Array<number | string>
}

export type ServiceRequestMountPaymentSummary = {
  rows?: ServiceRequestExtraMountPayment[]
  paid_rows?: ServiceRequestExtraMountPayment[]
  pending_rows?: ServiceRequestExtraMountPayment[]
  cancelled_rows?: ServiceRequestExtraMountPayment[]
  latest?: ServiceRequestExtraMountPayment | null
  latest_paid?: ServiceRequestExtraMountPayment | null
  latest_pending?: ServiceRequestExtraMountPayment | null
  latest_cancelled?: ServiceRequestExtraMountPayment | null
  paid_total_amount?: number
  paid_total_amount_label?: string
  pending_total_amount?: number
  pending_total_amount_label?: string
  cancelled_total_amount?: number
  cancelled_total_amount_label?: string
  paid_mount_amount?: number
  paid_mount_amount_label?: string
  paid_extra_amount?: number
  paid_extra_amount_label?: string
  has_paid?: boolean
  has_pending?: boolean
  has_cancelled?: boolean
}

export type ServiceRequestCustomerCharge = {
  id?: number | string | null
  request_id?: number | string | null
  mrn?: string | null
  service_code?: string | null
  status?: string | null
  status_label?: string | null
  amount?: number | null
  amount_label?: string | null
  service_amount?: number | null
  service_amount_label?: string | null
  part_amount?: number | null
  part_amount_label?: string | null
  currency?: string | null
  payment_url?: string | null
  provider?: string | null
  provider_reference?: string | null
  paid_at?: string | null
  purpose?: string | null
  purpose_label?: string | null
  note?: string | null
  message_template?: string | null
  message_text?: string | null
  message_send_count?: number | null
  last_message_sent_at?: string | null
}

export type ServiceRequestCustomerChargeSummary = {
  rows: ServiceRequestCustomerCharge[]
  latest?: ServiceRequestCustomerCharge | null
  total_service_amount?: number
  total_part_amount?: number
  total_amount?: number
  paid_service_amount?: number
  paid_part_amount?: number
  paid_total_amount?: number
  pending_total_amount?: number
}

export type ServiceRequestTechnicianEarningMessage = {
  status?: string | null
  sent_at?: string | null
  technician_id?: number | string | null
  technician_name?: string | null
  technician_phone?: string | null
  labor_amount?: number | null
  route_fee_amount?: number | null
  total_amount?: number | null
  manual_override?: boolean
  note?: string | null
  message_text?: string | null
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
  is_service_visit?: boolean
  applies_to_assignment?: boolean
  payment_required_for_assignment?: boolean
  pre_form_payment_control_enabled?: boolean
  show_mount_controls?: boolean
  show_payment_control?: boolean
  show_door_photo_control?: boolean
  show_address_control?: boolean
  show_schedule_control?: boolean
  context_mode?: 'service_visit_context' | 'mount_operation' | string | null
  mount_exclusion_acknowledgement?: {
    required?: boolean
    payment_received?: boolean
    acknowledged?: boolean
    note?: string | null
    acknowledged_at?: string | null
    acknowledged_by_user_id?: number | string | null
  } | null
}

export type ServiceRequestAssignmentBlockers = {
  payment_check_required?: boolean
  payment_required_for_assignment?: boolean
  door_photo_check_required?: boolean
  mount_exclusion_ack_required?: boolean
  mount_payment_received?: boolean
  applies_to_assignment?: boolean
  payment_decision?: string | null
  messages?: string[]
}

export type ServiceRequestInvoiceSerial = {
  id?: number | string | null
  serial_number?: string | null
  normalized_serial?: string | null
  product_name?: string | null
  product_model?: string | null
  model?: string | null
  brand?: string | null
  color?: string | null
  stock_code?: string | null
  invoice_series?: string | null
  invoice_number?: string | null
  invoice_display_no?: string | null
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
  current_latest_sale_invoice_series?: string | null
  current_latest_sale_invoice_number?: string | null
  latest_sale_conflict?: boolean
  operation_warning?: string | null
  warning_labels?: string[]
  mount_payment_status?: string | null
  mount_status_label?: string | null
  extra_mount_payment_id?: number | string | null
  invoice_customer_type?: string | null
  color_status?: 'green' | 'orange' | 'red' | string | null
}

export type ServiceRequestInvoiceSerials = {
  selected_serials?: ServiceRequestInvoiceSerial[]
  other_serials?: ServiceRequestInvoiceSerial[]
  hidden_serials?: ServiceRequestInvoiceSerial[]
  returned_serials?: ServiceRequestInvoiceSerial[]
  all_invoice_serials?: ServiceRequestInvoiceSerial[]
  selected_serial_count?: number
  other_serial_count?: number
  hidden_serial_count?: number
  added_serial_count?: number
  addable_serial_count?: number
  returned_serial_count?: number
  all_invoice_serial_count?: number
  display_limit?: number
  has_returned?: boolean
  has_multi_product?: boolean
  check_error?: string | null
}

export type ServiceRequestLocation = {
  latitude?: number | string | null
  longitude?: number | string | null
  route_latitude?: number | string | null
  route_longitude?: number | string | null
  route_source?: 'request' | 'parent_request' | string | null
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

export type ServiceRequestFieldCompletionDocument = ServiceRequestDoorPhoto & {
  label?: string | null
  review_status?: string | null
  review_note?: string | null
  created_at?: string | null
  reviewed_at?: string | null
}

export type ServiceRequestRouteQuote = {
  ok?: boolean
  id?: number | string | null
  technician_id?: number | string | null
  status?: 'calculated' | 'failed' | 'missing_location' | 'missing_api_key' | string | null
  origin_latitude?: number | null
  origin_longitude?: number | null
  destination_latitude?: number | null
  destination_longitude?: number | null
  one_way_distance_km?: number | null
  round_trip_distance_km?: number | null
  distance_km?: number | null
  distance_meters?: number | null
  duration_seconds?: number | null
  duration_text?: string | null
  threshold_km?: number | null
  billable_km?: number | null
  extra_km?: number | null
  straight_line_distance_km?: number | null
  suspicious_route?: boolean
  travel_fee_required?: boolean
  fee_per_km?: number | null
  current_fee_per_km?: number | null
  fee_per_km_matches_current?: boolean
  fee_amount?: number | null
  provider?: string | null
  source?: 'google_routes' | 'manual_override' | string | null
  manual_override?: boolean
  manual_note?: string | null
  calculated_at?: string | null
  message?: string | null
}

export type ServiceRequestRouteQuoteManualPayload = {
  technical_service_technician_id?: number | string | null
  one_way_distance_km?: number | null
  round_trip_distance_km?: number | null
  threshold_km?: number | null
  billable_km?: number | null
  fee_per_km?: number | null
  fee_amount?: number | null
  manual_override?: boolean
  manual_note?: string | null
}

export type ServiceRequestExtraMountPaymentPayload = {
  route_quote_id?: number | string | null
  technician_id?: number | string | null
  selected_serial_ids?: Array<number | string>
  amount?: number | null
  service_amount?: number | null
  part_amount?: number | null
  currency?: string
  reason?: 'route_fee' | 'montage_difference' | 'multi_product' | 'manual_extra' | 'service_payment' | 'part_payment' | 'service_and_part_payment'
  purpose?: 'mount_extra' | 'multi_product_mount' | 'manual_mount_payment' | 'service_payment' | 'part_payment' | 'service_and_part_payment' | 'route_fee' | 'montage_difference' | 'multi_product' | 'manual_extra'
  note?: string | null
  message_template?: string | null
}

export type ServiceRequestTechnicianEarningMessagePayload = {
  technician_id: number | string
  labor_amount?: number | null
  route_fee_amount?: number | null
  total_amount: number
  note?: string | null
  message_text?: string | null
  manual_override?: boolean
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
  review_status?: string | null
  review_reason?: string | null
  review_reasons?: string[] | null
  reviewed_at?: string | null
  reviewed_by?: number | string | null
  geocode_status?: string | null
  geocode_source?: string | null
  geocode_confidence?: number | string | null
  geocoded_at?: string | null
  source_key?: string | null
  b2b_partner_links?: Array<{
    id: number | string
    partner_id: number | string
    relationship_type?: string | null
    is_primary?: boolean | null
    active?: boolean | null
    service_city?: string | null
    service_district?: string | null
    service_region_note?: string | null
    priority?: number | string | null
    needs_review?: boolean | null
    review_reason?: string | null
    review_reasons?: string[] | null
    partner?: {
      id: number | string
      display_name?: string | null
      partner_code?: string | null
      mikro_cari_kodu?: string | null
      active?: boolean | null
    } | null
  }>
}

export type ServiceRequestEvent = {
  id: string
  event_type: string
  event_type_label?: string | null
  title: string
  title_label?: string | null
  note?: string | null
  from_status?: string | null
  from_status_label?: string | null
  to_status?: string | null
  to_status_label?: string | null
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
  warranty_started_at_datetime?: string | null
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
    completed_at_datetime?: string | null
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

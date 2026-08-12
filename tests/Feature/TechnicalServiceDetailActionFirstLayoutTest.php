<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class TechnicalServiceDetailActionFirstLayoutTest extends TestCase
{
    public function test_ops_detail_uses_central_default_open_section_rules(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const getOpsActiveSection', $source);
        $this->assertStringContainsString('const getOpsDefaultOpenSections', $source);
        $this->assertStringContainsString('const opsSectionClass', $source);
        $this->assertStringContainsString("context.kanbanColumn === 'final_check'", $source);
        $this->assertStringContainsString("return new Set(['fieldCompletion'])", $source);
        $this->assertStringContainsString('return new Set([activeSection])', $source);
    }

    public function test_ops_active_section_is_ordered_before_information_sections(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;

        $this->assertStringContainsString("return 'order-30'", $source);
        $this->assertStringContainsString("product: 'order-60'", $source);
        $this->assertStringContainsString("customer: 'order-65'", $source);
        $this->assertStringContainsString("product: 'order-30'", $source);
        $this->assertStringContainsString("customer: 'order-35'", $source);
        $this->assertStringContainsString("assignment: 'order-40'", $source);
        $this->assertStringContainsString("operation: 'order-70'", $source);
        $this->assertStringContainsString("history: 'order-[90]'", $source);
        $this->assertMatchesRegularExpression("/className=\\{opsSectionClass\\('fieldCompletion',activeOpsSection,?\\)\\}/", $compactSource);
        $this->assertMatchesRegularExpression("/className=\\{opsSectionClass\\('assignment',activeOpsSection,?\\)\\}/", $compactSource);
    }

    public function test_ops_assigned_jobs_use_neutral_info_order_instead_of_operation_control(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;
        $nextActionService = $this->source('app/Services/TechnicalService/TechnicalServiceNextActionService.php');

        $this->assertStringContainsString('const isAssignedTechnicianStage', $source);
        $this->assertStringContainsString("return isAssignedTechnicianStage(context) ? null : 'assignment'", $source);
        $this->assertStringNotContainsString("return new Set(['product', 'customer', 'assignment'])", $source);
        $this->assertStringContainsString('constassignmentDetailsExpandedByDefault=!hasAssignedTechnician||hasAssignmentChange||shouldShowRouteQuoteLoading', $compactSource);
        $this->assertStringContainsString('Usta fotoğrafları ve müşteri onayını tamamlayacak.', $source);
        $this->assertStringContainsString("displayedNextActionHeader=isAssignedPartnerActionStage?'SüreçBilgisi'", $compactSource);
        $this->assertStringContainsString("displayedNextActionSeverity=isAssignedPartnerActionStage?'neutral'", $compactSource);
        $this->assertStringContainsString('consthasSupportRequestDetail=supportRequests.length>0', $compactSource);
        $this->assertStringContainsString('consthasSparePartDetail=partRequests.length>0', $compactSource);
        $this->assertStringContainsString("'field_process'", $nextActionService);
        $this->assertStringContainsString("'İş ustada'", $nextActionService);
        $this->assertStringContainsString("'neutral'", $nextActionService);
    }

    public function test_partner_detail_uses_stage_based_collapsed_panels(): void
    {
        $source = $this->source('resources/js/pages/partner/portal-shell.tsx');

        $this->assertStringContainsString('const getPartnerDefaultOpenSections', $source);
        $this->assertStringContainsString("return new Set(['earnings'])", $source);
        $this->assertStringContainsString("return new Set(['appointment'])", $source);
        $this->assertStringContainsString("return new Set(['photos'])", $source);
        $this->assertStringContainsString('function PartnerDetailPanel', $source);
        $this->assertStringContainsString('defaultOpen={defaultOpenPartnerSections.has(\'photos\')}', $source);
    }

    public function test_partner_completed_and_final_check_jobs_do_not_default_open_action_forms(): void
    {
        $source = $this->source('resources/js/pages/partner/portal-shell.tsx');

        $this->assertStringContainsString("if (job.kanban_column === 'completed')", $source);
        $this->assertStringContainsString("if (job.kanban_column === 'final_check' || job.action_state === 'final_check_waiting')", $source);
        $this->assertStringContainsString('return new Set()', $source);
        $this->assertStringContainsString('{completionReady && (', $source);
        $this->assertStringContainsString('{job.can_request_customer_otp && (', $source);
    }

    public function test_partner_appointment_confirmed_prioritizes_photo_and_customer_approval_flow(): void
    {
        $source = $this->source('resources/js/pages/partner/portal-shell.tsx');
        $service = $this->source('app/Services/B2B/B2BPartnerPortalDataService.php');

        $this->assertStringContainsString('field_action_hint?: string | null', $source);
        $this->assertStringContainsString('className="order-[35] mt-5"', $source);
        $this->assertStringContainsString('const canRequestCustomerApproval = Boolean(job.can_request_customer_otp && photosReady)', $source);
        $this->assertStringContainsString('Müşteri onayı için önce 3 fotoğrafı yükleyin.', $source);
        $this->assertStringContainsString('disabled={readOnly || !canRequestCustomerApproval}', $source);
        $this->assertStringContainsString('disabled={readOnly || !canRequestCustomerApproval || otpMessageText.trim().length < 3', $source);
        $this->assertStringContainsString('{completionReady && (', $source);
        $this->assertStringContainsString('const [panelState, setPanelState] = useState({ panelKey, defaultOpen, open: defaultOpen })', $source);
        $this->assertStringContainsString('open={panelOpen}', $source);
        $this->assertStringContainsString('appointmentConfirmedPartnerBadges', $service);
        $this->assertStringContainsString('Fotoğraf bekleniyor', $service);
        $this->assertStringContainsString('Müşteri onayı bekleniyor', $service);
        $this->assertStringContainsString('İş sonrası 3 fotoğrafı yükleyin, ardından müşteri onayı alın.', $service);

        $photosSectionIndex = strpos($source, "if (job.kanban_column === 'appointment_confirmed' && job.can_upload_photos)");
        $completionSectionIndex = strpos($source, 'if (completionReady)');

        $this->assertIsInt($photosSectionIndex);
        $this->assertIsInt($completionSectionIndex);
        $this->assertLessThan($completionSectionIndex, $photosSectionIndex);
    }

    public function test_ops_detail_hides_empty_service_part_payment_blocks_when_no_charge(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertSame(1, substr_count($source, 'FİNANS VE HAKEDİŞ'));
        $this->assertStringContainsString('data-testid="technical-service-financial-workspace"', $source);
        $this->assertStringContainsString('selectedFinancialCollection?.total_amount_label', $source);
        $this->assertStringContainsString('selectedFinancialCollection?.part_amount_label', $source);
        $this->assertStringContainsString('const showServicePartPaymentSummary', $source);
        $this->assertStringContainsString('const showPaymentTechnicalDetails', $source);
        $this->assertStringContainsString('{showServicePartPaymentSummary ? (', $source);
        $this->assertStringContainsString('{showPaymentTechnicalDetails ? (', $source);
    }

    public function test_srv_financial_workspace_renders_scope_route_suggestion_and_compact_payment_details(): void
    {
        $detail = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $page = $this->source('resources/js/pages/panel/technical-service.tsx');
        $scheduleHandler = Str::between($page, 'const handleScheduleSubmit = async () => {', 'const handleContactActionSubmit = async () => {');
        $primaryCards = Str::betweenFirst($detail, 'data-testid="financial-primary-cards"', '</div>');

        $this->assertStringContainsString('data-testid="technical-service-financial-workspace"', $detail);
        $this->assertStringContainsString('Bu SRV', $detail);
        $this->assertStringContainsString('Kök MRN toplamı', $detail);
        $this->assertStringNotContainsString('>\n                    Bu iş\n', $detail);
        $this->assertStringContainsString('data-testid="route-earning-suggestion"', $detail);
        $this->assertStringContainsString('Yeni yol hakedişi önerisi:', $detail);
        $this->assertStringContainsString('Mevcut onaylı yol hakedişi:', $detail);
        $this->assertStringContainsString('Hakedişte kullan', $detail);
        $this->assertStringContainsString('updateAssignmentEarningDraft({ routeFeeAmount:', $detail);
        $this->assertStringContainsString('data-testid="financial-primary-cards"', $detail);
        $this->assertSame(1, substr_count($primaryCards, 'label="Müşteriden alınan"'));
        $this->assertSame(1, substr_count($primaryCards, 'label="Usta hakedişi"'));
        $this->assertSame(1, substr_count($primaryCards, 'label="Şirket ödemesi"'));
        $this->assertSame(1, substr_count($primaryCards, 'label="Operasyon farkı"'));
        $this->assertStringContainsString('<summary className="cursor-pointer font-semibold text-slate-950">Tahsilat kırılımı</summary>', $detail);
        $this->assertStringContainsString('<summary className="cursor-pointer font-semibold text-slate-950">Hakediş kırılımı</summary>', $detail);
        $this->assertStringContainsString('data-testid="financial-payment-records"', $detail);
        $this->assertStringContainsString('data-testid="related-payment-scope-notice"', $detail);
        $this->assertStringContainsString('component_split_persisted', $detail);
        $this->assertStringContainsString('scheduleMutationInFlightRef.current', $scheduleHandler);
        $this->assertStringContainsString('applyUpdatedRequest(updatedRequest)', $scheduleHandler);
        $this->assertStringNotContainsString('loadRequests()', $scheduleHandler);
        $this->assertStringNotContainsString('loadSummary()', $scheduleHandler);
        $this->assertStringNotContainsString('loadRequestDetail(', $scheduleHandler);
    }

    public function test_part_payment_copy_feedback_is_rendered_next_to_visible_actions(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('function renderCustomerChargeCopyFeedback', $source);
        $this->assertStringContainsString('{renderCustomerChargeCopyFeedback(latestCustomerCharge.payment_url)}', $source);
        $this->assertStringContainsString('{renderCustomerChargeCopyFeedback(partRequest.payment_url)}', $source);
        $this->assertStringContainsString('Otomatik kopyalanamadı; metni manuel kopyalayın.', $source);
        $this->assertStringContainsString('message_send_count: partRequestPayment?.message_send_count', $source);
    }

    public function test_ops_detail_hides_address_check_when_not_actionable(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $types = $this->source('resources/js/components/technical-service/types.ts');
        $workflow = $this->source('app/Services/TechnicalService/TechnicalServiceWorkflowService.php');

        $this->assertStringContainsString('const showAddressControl', $source);
        $this->assertStringContainsString('const showAddressControlBlock', $source);
        $this->assertStringContainsString('{showAddressControl && showAddressControlBlock ? (', $source);
        $this->assertStringContainsString('show_address_control?: boolean', $types);
        $this->assertStringContainsString("\$result['show_address_control'] = \$addressControlActionable", $workflow);
    }

    public function test_ops_detail_hides_mount_operation_controls_for_srv_unless_manual_check_needed(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $types = $this->source('resources/js/components/technical-service/types.ts');
        $workflow = $this->source('app/Services/TechnicalService/TechnicalServiceWorkflowService.php');

        $this->assertStringContainsString('const showMountOperationControls', $source);
        $this->assertStringContainsString('SRV Bağlamı', $source);
        $this->assertStringContainsString('{showDoorPhotoControl ? (', $source);
        $this->assertStringContainsString('show_mount_controls?: boolean', $types);
        $this->assertStringContainsString("\$result['context_mode'] = \$isServiceVisit ? 'service_visit_context' : 'mount_operation'", $workflow);
    }

    public function test_partner_actions_refresh_job_cards_without_manual_reload(): void
    {
        $source = $this->source('resources/js/pages/partner/portal-shell.tsx');

        $this->assertStringContainsString('const refreshJobs = useCallback(async (silent = true, force = false)', $source);
        $this->assertStringContainsString('onJobsRefresh={() => refreshJobs(true, true)}', $source);
        $this->assertStringContainsString('await onJobsRefresh?.()', $source);
        $this->assertStringContainsString('onJobsRefresh?: () => Promise<void>', $source);
    }

    public function test_partner_jobs_page_job_deep_link_hydrates_exact_authorized_job_without_strict_mode_timer_race(): void
    {
        $source = $this->source('resources/js/pages/partner/portal-shell.tsx');
        $controller = $this->source('app/Http/Controllers/PartnerPortalController.php');

        $this->assertStringContainsString("'requestedJobId' => \$requestedJobId", $controller);
        $this->assertStringContainsString('const initialRequestedJob = requestedJobId === null', $source);
        $this->assertStringContainsString('useState<number | null>(initialRequestedJob?.id ?? null)', $source);
        $this->assertStringContainsString('useState(initialRequestedJob !== null)', $source);
        $this->assertStringContainsString('scopedServiceJobApiUrl(apiBase, `/${deepLinkJobId}`', $source);
        $this->assertStringContainsString("url.searchParams.delete('job_id')", $source);
        $this->assertStringContainsString('props: (props) => ({ ...props, requestedJobId: null })', $source);
        $this->assertStringContainsString('initialRequestedJob?.id === deepLinkJobId', $source);
        $this->assertStringNotContainsString('requestedJobAppliedRef', $source);
    }

    public function test_earning_summary_displays_selected_locksmith_name(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const earningSummaryTechnicianName', $source);
        $this->assertStringContainsString('const earningDispatchStatus = technicianEarningMessage?.status ?? assignmentOfferDispatchStatus', $source);
        $this->assertStringContainsString('{earningSummaryTechnicianName} · {displayOrEmpty(financeSummary?.scope?.request_code ?? request.mrn', $source);
        $this->assertStringContainsString('Usta toplam hakedişi', $source);
        $this->assertStringContainsString("selectedTechnician?.name || request.technicianName || 'Usta seçilmedi'", $source);
    }

    public function test_timeline_label_fallbacks_do_not_render_unknown_operation(): void
    {
        $opsSource = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $partnerSource = $this->source('resources/js/pages/partner/portal-shell.tsx');
        $labelService = $this->source('app/Services/TechnicalService/TechnicalServiceUiLabelService.php');

        foreach ([$opsSource, $partnerSource, $labelService] as $source) {
            $this->assertStringNotContainsString('Bilinmeyen işlem', $source);
        }

        $genericFallback = 'Operasyon '.'kaydı';
        $this->assertStringContainsString('Kayıt detayı', $opsSource);
        $this->assertStringContainsString($genericFallback, $partnerSource);
        $this->assertStringContainsString($genericFallback, $labelService);
        $this->assertStringContainsString('schedule_updated', $opsSource);
        $this->assertStringContainsString('assignment_offer_sent', $opsSource);
        $this->assertStringContainsString('partner_portal_support_requested', $partnerSource);
        $this->assertStringContainsString('assignment_offer_sent', $labelService);
    }

    public function test_detail_ui_hides_empty_cards(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;

        $this->assertStringContainsString('const optionalMetricValue =', $source);
        $this->assertStringContainsString('constshouldRenderProductInfoPanel=hasProductIdentityDetail||shouldRenderHeaderPaymentSummary||hasMultiProductRequest', $compactSource);
        $this->assertStringContainsString('const hasCustomerDetail = Boolean(', $source);
        $this->assertStringContainsString('{shouldRenderProductInfoPanel ? (', $source);
        $this->assertStringContainsString('{hasCustomerDetail ? (', $source);
        $this->assertStringNotContainsString("displayOrEmpty(productInfo?.product_name ?? request.product, 'Bilgi yok')", $source);
        $this->assertStringNotContainsString("Müşteri açık adresi: {displayOrEmpty(customerOpenAddress, 'Bilgi yok')}", $source);
    }

    public function test_detail_ui_hides_missing_part_section(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;

        $this->assertStringContainsString('constshouldShowPartCreateAction=canCreatePartRequest&&(partRequests.length>0||servicePartChargeSectionVisible||activePartRequests.length>0)', $compactSource);
        $this->assertStringContainsString('{shouldShowPartCreateAction ? (', $source);
        $this->assertStringContainsString('{partRequests.length > 0 ? (', $source);
        $this->assertStringNotContainsString('{canCreatePartRequest ? (', $source);
    }

    public function test_detail_ui_collapses_history_by_default(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;

        $this->assertStringContainsString('constshouldRenderHistoryPanel=Boolean((request.auditLogs??[]).length>0||events.length>0)', $compactSource);
        $this->assertStringContainsString('{shouldRenderHistoryPanel ? (', $source);
        $this->assertStringContainsString('<DetailPaneltitle="OperasyonGeçmişi"', $compactSource);
        $this->assertStringContainsString("history:'order-[90]'", $compactSource);
    }

    public function test_assigned_request_collapses_suggestions(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;

        $this->assertStringContainsString('constassignmentDetailsExpandedByDefault=!hasAssignedTechnician||hasAssignmentChange||shouldShowRouteQuoteLoading', $compactSource);
        $this->assertStringContainsString("return isAssignedTechnicianStage(context) ? null : 'assignment'", $source);
        $this->assertStringNotContainsString("return new Set(['product', 'customer', 'assignment'])", $source);
    }

    public function test_completed_request_is_read_only_compact(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;

        $this->assertStringContainsString('constvisibleFooterWorkflowActions=isActionDisabled?[]:footerWorkflowActions', $compactSource);
        $this->assertStringContainsString('!isActionDisabled && finalCheckCompletionAction', $source);
        $this->assertStringContainsString('!isActionDisabled && canReassignAfterReview', $source);
        $this->assertStringContainsString('Salt okunur belge özeti', $source);
        $this->assertStringContainsString("isActionDisabled?'Kayıtyok':'Bekliyor'", $compactSource);
    }

    public function test_srv_child_does_not_show_parent_gate(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $compactSource = preg_replace('/\s+/', '', $source) ?? $source;

        $this->assertStringContainsString('constisServiceVisitDetail=visibleSections?.is_service_visit===true||operationControl.is_service_visit===true||Boolean(serviceVisitHistory?.service_code||serviceVisitHistory?.reason)', $compactSource);
        $this->assertStringContainsString('constshowMountOperationControls=(visibleSections?.operation_mount_controls??operationControl.show_mount_controls??!isServiceVisitDetail)===true', $compactSource);
        $this->assertStringContainsString('SRVkaydıparentmontajkapı/ödemekontrolünüdevralmaz.', $compactSource);
        $this->assertStringContainsString('order-[85] rounded-3xl border border-violet-100', $source);
    }

    public function test_payment_earning_summary_uses_canonical_values(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('selectedFinancialCollection?.service_total_amount_label', $source);
        $this->assertStringContainsString('selectedFinancialPayout?.total_amount_label', $source);
        $this->assertStringContainsString('selectedFinancialDifferenceLabel', $source);
        $this->assertStringContainsString("financialScope === 'root' ? financeRootTotal : financeCurrentVisit", $source);
        $this->assertStringNotContainsString('totalCustomerCollectionDisplayLabel', $source);
    }

    public function test_no_generic_islem_kaydi_in_detail_history(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $genericFallback = 'İşlem '.'kaydı';

        $this->assertStringContainsString('Kayıt detayı', $source);
        $this->assertStringNotContainsString("return '{$genericFallback}'", $source);
        $this->assertStringNotContainsString("? '{$genericFallback}' : value", $source);
    }

    public function test_cancel_action_uses_the_dedicated_reason_dialog(): void
    {
        $detailSource = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $pageSource = $this->source('resources/js/pages/panel/technical-service.tsx');

        $this->assertStringContainsString('onClick={() => onCancel?.()}', $detailSource);
        $this->assertStringContainsString("if (action === 'cancel')", $detailSource);
        $this->assertStringContainsString('onCancel={openRequestCancellationDialog}', $pageSource);
        $this->assertStringContainsString('<DialogTitle>Talebi iptal et</DialogTitle>', $pageSource);
    }

    public function test_positive_flow_mutations_use_synchronous_in_flight_guards_and_preserve_selected_detail(): void
    {
        $source = $this->source('resources/js/pages/panel/technical-service.tsx');

        foreach ([
            'assignMutationInFlightRef',
            'extraPaymentCreateInFlightRef',
            'appointmentApprovalInFlightRef',
            'assignmentOfferUpdateInFlightRef',
        ] as $guard) {
            $this->assertStringContainsString($guard, $source);
        }

        $this->assertStringContainsString('if (!selectedId || assignMutationInFlightRef.current)', $source);
        $this->assertStringContainsString('if (!selectedId || extraPaymentCreateInFlightRef.current)', $source);
        $this->assertStringContainsString('if (!selectedId || appointmentApprovalInFlightRef.current !== null)', $source);
        $this->assertStringContainsString('if (!selectedId || assignmentOfferUpdateInFlightRef.current)', $source);
        $this->assertStringContainsString('selectedIdRef.current === requestId', $source);
        $this->assertStringContainsString('const requestId = selectedId', $source);
        $this->assertStringContainsString('preserveDetailScroll(() => {', $source);
        $this->assertStringContainsString('setSelectedDetailRequest(updatedRequest)', $source);
        $this->assertStringContainsString("setAssignSuccess('Usta atandı; hakediş, iş kartı ve bildirim kaydı hazırlandı.')", $source);
        $this->assertStringContainsString("setAppointmentApprovalSuccess(response.status === 'duplicate_noop'", $source);
        $this->assertStringContainsString("setAssignmentOfferUpdateSuccess(response.status === 'duplicate_noop'", $source);
        $this->assertStringContainsString('setAppointmentApprovalSuccess(null)', $source);
        $this->assertStringContainsString('setAssignmentOfferUpdateSuccess(null)', $source);

        $appointmentStart = strpos($source, 'const handlePartnerAppointmentProposalApprove = async');
        $appointmentEnd = strpos($source, 'const handlePartnerAppointmentProposalReject = async', $appointmentStart);
        $earningStart = strpos($source, 'const handleAssignmentOfferUpdate = async');
        $earningEnd = strpos($source, 'const handlePartnerActionReview = async', $earningStart);
        $this->assertIsInt($appointmentStart);
        $this->assertIsInt($appointmentEnd);
        $this->assertIsInt($earningStart);
        $this->assertIsInt($earningEnd);

        foreach ([
            substr($source, $appointmentStart, $appointmentEnd - $appointmentStart),
            substr($source, $earningStart, $earningEnd - $earningStart),
        ] as $responseAuthoritativeHandler) {
            $this->assertStringNotContainsString('loadRequests(', $responseAuthoritativeHandler);
            $this->assertStringNotContainsString('loadSummary(', $responseAuthoritativeHandler);
            $this->assertStringContainsString('setSelectedDetailRequest(updatedRequest)', $responseAuthoritativeHandler);
        }
    }

    public function test_appointment_success_feedback_survives_the_consumed_proposal_row(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $successIndex = strpos($source, '{appointmentApprovalSuccess ? (');
        $portalBlockIndex = strpos($source, '{showAssignmentPortalActionBlock ? (');
        $proposalLoopIndex = strpos($source, '{openAppointmentProposals.map((action) => {');

        $this->assertIsInt($successIndex);
        $this->assertIsInt($portalBlockIndex);
        $this->assertIsInt($proposalLoopIndex);
        $this->assertLessThan($portalBlockIndex, $successIndex);
        $this->assertLessThan($proposalLoopIndex, $successIndex);
        $this->assertSame(1, substr_count($source, '{appointmentApprovalSuccess ? ('));
    }

    public function test_assignment_job_card_actions_are_sticky_and_outside_earning_message_preview(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $opsActionIndex = strpos($source, 'Usta İş Kartını OPS Olarak Yönet');
        $previewActionIndex = strpos($source, 'Usta Portalını Önizle');
        $messagePreviewIndex = strpos($source, "? 'Taslak hakediş mesajını göster' : 'Hakediş mesajını göster'");

        $this->assertIsInt($opsActionIndex);
        $this->assertIsInt($previewActionIndex);
        $this->assertIsInt($messagePreviewIndex);
        $this->assertLessThan($messagePreviewIndex, $opsActionIndex);
        $this->assertLessThan($messagePreviewIndex, $previewActionIndex);
        $this->assertStringContainsString('sticky bottom-0 z-10', $source);
        $this->assertStringContainsString('border-t border-slate-200 bg-white/95', $source);
        $this->assertStringContainsString('grid w-full max-w-full grid-cols-2', $source);
        $this->assertStringContainsString('İş kartı aksiyonları usta ataması tamamlandığında kullanılabilir.', $source);
        $this->assertStringContainsString("{technicianEarningMessageLoading ? 'Hazırlanıyor...' : 'Hakediş bilgisini gönder'}", $source);
    }

    public function test_earning_draft_uses_one_state_for_inputs_summary_and_preview(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const [assignmentEarningDraftByRequest, setAssignmentEarningDraftByRequest]', $source);
        $this->assertStringContainsString('value={earningLaborInput}', $source);
        $this->assertStringContainsString('value={earningRouteInput}', $source);
        $this->assertStringContainsString('<MiniMetric label="İşçilik" value={formatMoneyValue(earningLaborAmount)} />', $source);
        $this->assertStringContainsString('<MiniMetric label="Yol" value={formatMoneyValue(earningRouteAmount)} />', $source);
        $this->assertStringContainsString('`Montaj işçilik: ${formatMoneyValue(earningLaborAmount)}`', $source);
        $this->assertStringContainsString('`Usta yol hakedişi: ${formatMoneyValue(earningRouteAmount)}`', $source);
        $this->assertStringContainsString('`Toplam hakediş: ${formatMoneyValue(earningTotalAmount)}`', $source);
        $this->assertStringNotContainsString('earningTotalOverrideByRequest', $source);
        $this->assertStringNotContainsString('offerLaborInput', $source);
    }

    public function test_dirty_earning_is_labelled_and_cannot_be_sent(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString("'Taslak — henüz kaydedilmedi'", $source);
        $this->assertStringContainsString("setRouteFeeEditorMessage('Önce hakediş değişikliklerini kaydedin.')", $source);
        $this->assertStringContainsString('&& !earningDraftDirty', $source);
        $this->assertStringContainsString('earning_revision: persistedEarningRevision', $source);
        $this->assertStringNotContainsString('message_text: earningMessageText.trim()', $source);
    }

    public function test_successful_earning_save_uses_returned_canonical_snapshot_and_preview(): void
    {
        $detailsSource = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $pageSource = $this->source('resources/js/pages/panel/technical-service.tsx');

        $this->assertStringContainsString('setEarningMessageText(response.message_preview ??', $detailsSource);
        $this->assertStringContainsString('delete next[requestStateKey]', $detailsSource);
        $this->assertStringContainsString('return response', $pageSource);
        $this->assertStringContainsString('onClick={() => void handleAssignmentSave()}', $detailsSource);
    }

    public function test_corrective_earning_send_renders_two_channels_and_uses_one_targeted_request(): void
    {
        $detailsSource = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $pageSource = $this->source('resources/js/pages/panel/technical-service.tsx');
        $handlerStart = strpos($pageSource, 'const handleTechnicianEarningMessageCreate = async');
        $handlerEnd = strpos($pageSource, 'const handlePartnerAppointmentProposalApprove = async', $handlerStart ?: 0);

        $this->assertStringContainsString('data-testid="earning-corrective-resend-notice"', $detailsSource);
        $this->assertStringContainsString('data-corrective-channels="whatsapp,sms"', $detailsSource);
        $this->assertStringContainsString('Kanallar: WhatsApp ve SMS', $detailsSource);
        $this->assertStringContainsString('Hakediş mesajı metin ve satır düzeni düzeltmesi', $detailsSource);
        $this->assertStringContainsString('technicianEarningMessageSubmitLock.current', $detailsSource);
        $this->assertStringContainsString('response?.dispatches?.length', $detailsSource);
        $this->assertStringContainsString('Düzeltici WhatsApp ve SMS hakediş mesajları kuyruğa alındı.', $detailsSource);
        $this->assertIsInt($handlerStart);
        $this->assertIsInt($handlerEnd);

        $handler = substr($pageSource, $handlerStart, $handlerEnd - $handlerStart);
        $this->assertSame(1, substr_count($handler, "method: 'POST'"));
        $this->assertStringContainsString('setSelectedDetailRequest(updatedRequest)', $handler);
        $this->assertStringNotContainsString('loadRequests(', $handler);
        $this->assertStringNotContainsString('setIsDetailDialogOpen(false)', $handler);
    }

    public function test_terminal_payment_retry_ui_requires_reason_and_never_renders_raw_contract_code(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const terminalPaymentRetryRequired = cancelledOnlinePaymentLink && !pendingOnlinePaymentLink', $source);
        $this->assertStringContainsString('terminalPaymentRetryReason.trim().length >= 3', $source);
        $this->assertStringContainsString('Yeni ödeme bağlantısı oluştur', $source);
        $this->assertStringContainsString('Yeniden deneme nedeni', $source);
        $this->assertStringNotContainsString('TERMINAL_PAYMENT_NOT_REUSABLE', $source);
    }

    public function test_approval_state_is_not_overwritten_by_message_status(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('<MiniMetric label="Müşteri onayı" value={closureApprovalLabel}', $source);
        $this->assertStringContainsString('const latestCustomerApprovalMessageStatusLabel = (() =>', $source);
        $this->assertStringContainsString('<p className="font-semibold">Son mesaj durumu</p>', $source);
        $this->assertStringContainsString('{latestCustomerApprovalMessageStatusLabel}', $source);
        $this->assertStringContainsString("return 'Bastırıldı'", $source);
        $this->assertStringNotContainsString('<p className="font-semibold">Müşteri onayı</p>\n                  <p className="mt-1 text-xs text-violet-800">', $source);
    }

    public function test_current_srv_earning_must_be_selected_before_completion(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const currentVisitPayoutSelected = !finalPayoutApprovalRequired', $source);
        $this->assertStringContainsString('Kapanacak SRV açıkça seçili olmalıdır.', $source);
        $this->assertStringContainsString('Mevcut SRV hakedişi seçilmeden iş kapatılamaz.', $source);
        $this->assertStringContainsString('finalPayoutSelectedRows.length === 0 || !currentVisitPayoutSelected', $source);
        $this->assertStringContainsString('action.payload?.ops_final_check_required === true', $source);
        $this->assertStringContainsString('action.payload?.ops_final_check === undefined || action.payload?.ops_final_check === null', $source);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return file_get_contents($path) ?: '';
    }
}

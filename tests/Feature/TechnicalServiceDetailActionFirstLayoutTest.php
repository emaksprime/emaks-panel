<?php

namespace Tests\Feature;

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

        $this->assertStringContainsString("return 'order-30'", $source);
        $this->assertStringContainsString("product: 'order-60'", $source);
        $this->assertStringContainsString("customer: 'order-65'", $source);
        $this->assertStringContainsString("product: 'order-30'", $source);
        $this->assertStringContainsString("customer: 'order-35'", $source);
        $this->assertStringContainsString("assignment: 'order-40'", $source);
        $this->assertStringContainsString("operation: 'order-70'", $source);
        $this->assertStringContainsString("history: 'order-[90]'", $source);
        $this->assertStringContainsString('className={opsSectionClass(\'fieldCompletion\', activeOpsSection)}', $source);
        $this->assertStringContainsString('className={opsSectionClass(\'assignment\', activeOpsSection)}', $source);
    }

    public function test_ops_assigned_jobs_use_neutral_info_order_instead_of_operation_control(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $nextActionService = $this->source('app/Services/TechnicalService/TechnicalServiceNextActionService.php');

        $this->assertStringContainsString('const isAssignedTechnicianStage', $source);
        $this->assertStringContainsString("return isAssignedTechnicianStage(context) ? null : 'assignment'", $source);
        $this->assertStringNotContainsString("return new Set(['product', 'customer', 'assignment'])", $source);
        $this->assertStringContainsString('const assignmentDetailsExpandedByDefault = !hasAssignedTechnician || hasAssignmentChange || shouldShowRouteQuoteLoading', $source);
        $this->assertStringContainsString("Usta fotoğrafları ve müşteri onayını tamamlayacak.", $source);
        $this->assertStringContainsString("displayedNextActionHeader = isAssignedPartnerActionStage ? 'Süreç Bilgisi'", $source);
        $this->assertStringContainsString("displayedNextActionSeverity = isAssignedPartnerActionStage ? 'neutral'", $source);
        $this->assertStringContainsString('const hasSupportRequestDetail = supportRequests.length > 0', $source);
        $this->assertStringContainsString('const hasSparePartDetail = partRequests.length > 0', $source);
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

        $this->assertStringContainsString('const showServicePartPaymentSummary', $source);
        $this->assertStringContainsString('const showPaymentTechnicalDetails', $source);
        $this->assertStringContainsString('{showServicePartPaymentSummary ? (', $source);
        $this->assertStringContainsString('{showPaymentTechnicalDetails ? (', $source);
        $this->assertStringContainsString('hasServiceCustomerPayment ? (', $source);
        $this->assertStringContainsString('hasPartCustomerPayment ? (', $source);
        $this->assertStringNotContainsString("paidServiceCustomerAmount > 0 ? formatMoneyValue(paidServiceCustomerAmount) : 'Yok'", $source);
        $this->assertStringNotContainsString("paidPartCustomerAmount > 0 ? formatMoneyValue(paidPartCustomerAmount) : 'Yok'", $source);
    }

    public function test_ops_detail_hides_address_check_when_not_actionable(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $types = $this->source('resources/js/components/technical-service/types.ts');
        $workflow = $this->source('app/Services/TechnicalService/TechnicalServiceWorkflowService.php');

        $this->assertStringContainsString('const showAddressControl', $source);
        $this->assertStringContainsString('{showAddressControl ? (', $source);
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

    public function test_earning_summary_displays_selected_locksmith_name(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const earningSummaryTechnicianName', $source);
        $this->assertStringContainsString('Usta Hakedişi / Operasyon Maliyeti', $source);
        $this->assertStringContainsString('{financeSummaryTitle} — {earningSummaryTechnicianName}', $source);
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

        $genericFallback = 'İşlem ' . 'kaydı';
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

        $this->assertStringContainsString('const optionalMetricValue =', $source);
        $this->assertStringContainsString('const shouldRenderProductInfoPanel = hasProductIdentityDetail || shouldRenderHeaderPaymentSummary || hasMultiProductRequest', $source);
        $this->assertStringContainsString('const hasCustomerDetail = Boolean(', $source);
        $this->assertStringContainsString('{shouldRenderProductInfoPanel ? (', $source);
        $this->assertStringContainsString('{hasCustomerDetail ? (', $source);
        $this->assertStringNotContainsString("displayOrEmpty(productInfo?.product_name ?? request.product, 'Bilgi yok')", $source);
        $this->assertStringNotContainsString("Müşteri açık adresi: {displayOrEmpty(customerOpenAddress, 'Bilgi yok')}", $source);
    }

    public function test_detail_ui_hides_missing_part_section(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const shouldShowPartCreateAction = canCreatePartRequest && (partRequests.length > 0 || servicePartChargeSectionVisible || activePartRequests.length > 0)', $source);
        $this->assertStringContainsString('{shouldShowPartCreateAction ? (', $source);
        $this->assertStringContainsString('{partRequests.length > 0 ? (', $source);
        $this->assertStringNotContainsString('{canCreatePartRequest ? (', $source);
    }

    public function test_detail_ui_collapses_history_by_default(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const shouldRenderHistoryPanel = Boolean((request.auditLogs ?? []).length > 0 || events.length > 0)', $source);
        $this->assertStringContainsString('{shouldRenderHistoryPanel ? (', $source);
        $this->assertStringContainsString('<DetailPanel title="İşlem Geçmişi"', $source);
        $this->assertStringContainsString('order-[85] rounded-3xl border border-violet-100', $source);
    }

    public function test_assigned_request_collapses_suggestions(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const assignmentDetailsExpandedByDefault = !hasAssignedTechnician || hasAssignmentChange || shouldShowRouteQuoteLoading', $source);
        $this->assertStringContainsString("return isAssignedTechnicianStage(context) ? null : 'assignment'", $source);
        $this->assertStringNotContainsString("return new Set(['product', 'customer', 'assignment'])", $source);
    }

    public function test_completed_request_is_read_only_compact(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const visibleFooterWorkflowActions = isActionDisabled ? [] : footerWorkflowActions', $source);
        $this->assertStringContainsString('!isActionDisabled && finalCheckCompletionAction', $source);
        $this->assertStringContainsString('!isActionDisabled && canReassignAfterReview', $source);
        $this->assertStringContainsString('Salt okunur belge özeti', $source);
        $this->assertStringContainsString("isActionDisabled ? 'Kayıt yok' : 'Bekliyor'", $source);
    }

    public function test_srv_child_does_not_show_parent_gate(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('const isServiceVisitDetail = visibleSections?.is_service_visit === true', $source);
        $this->assertStringContainsString('const showMountOperationControls = (visibleSections?.operation_mount_controls ?? operationControl.show_mount_controls ?? !isServiceVisitDetail) === true', $source);
        $this->assertStringContainsString('SRV kaydı parent montaj kapı/ödeme kontrolünü devralmaz.', $source);
        $this->assertStringContainsString('order-[85] rounded-3xl border border-violet-100', $source);
    }

    public function test_payment_earning_summary_uses_canonical_values(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');

        $this->assertStringContainsString('totalCustomerCollectionDisplayLabel', $source);
        $this->assertStringContainsString('financeRootCustomerCollectionDisplayLabel', $source);
        $this->assertStringContainsString('financeSummaryTitle', $source);
        $this->assertStringContainsString('locksmithPayoutTotalMetricLabel', $source);
        $this->assertStringContainsString('netDifferenceMetricLabel', $source);
    }

    public function test_no_generic_islem_kaydi_in_detail_history(): void
    {
        $source = $this->source('resources/js/components/technical-service/ServiceRequestDetails.tsx');
        $genericFallback = 'İşlem ' . 'kaydı';

        $this->assertStringContainsString('Kayıt detayı', $source);
        $this->assertStringNotContainsString("return '{$genericFallback}'", $source);
        $this->assertStringNotContainsString("? '{$genericFallback}' : value", $source);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return file_get_contents($path) ?: '';
    }
}

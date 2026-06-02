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
        $this->assertStringContainsString("history: 'order-[90]'", $source);
        $this->assertStringContainsString('className={opsSectionClass(\'fieldCompletion\', activeOpsSection)}', $source);
        $this->assertStringContainsString('className={opsSectionClass(\'assignment\', activeOpsSection)}', $source);
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
        $this->assertStringContainsString('{job.can_submit_completion && (', $source);
        $this->assertStringContainsString('{job.can_request_customer_otp && (', $source);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return file_get_contents($path) ?: '';
    }
}

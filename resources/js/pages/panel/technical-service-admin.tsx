import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const adminItems = [
    [
        'Ekran yetkileri',
        'Teknik servis ekranları ayrı panel resource kodlarıyla Admin > Kullanıcı Yönetimi üzerinden atanır.',
    ],
    [
        'Aksiyon yetkileri',
        'Talep yönetimi, hakediş ödeme ve teknisyen yönetimi ayrı API resource kontrolleriyle korunur.',
    ],
    [
        'Mikro sorguları',
        'Seri no ve garanti sorguları technical_service_serial_query yetkisine bağlıdır.',
    ],
];

type AdminSectionKey =
    | 'overview'
    | 'payment'
    | 'mail'
    | 'messaging'
    | 'templates'
    | 'queue'
    | 'integrations';
type MessagingSectionKey =
    | 'general'
    | 'provider_tests'
    | 'evo'
    | 'nac_sms'
    | 'voibot'
    | 'message_types';
type TemplateSectionKey = 'whatsapp' | 'sms' | 'voice' | 'variables';
type IntegrationSectionKey =
    | 'manual_e2e_portal'
    | 'mikro_api'
    | 'provider_credentials'
    | 'operation_catalog';

const adminSectionTabs: Array<{
    key: AdminSectionKey;
    label: string;
    summary: string;
}> = [
    {
        key: 'overview',
        label: 'Genel / Panel',
        summary: 'QR, OPS görünürlük ve yetki özeti',
    },
    {
        key: 'payment',
        label: 'Ödeme',
        summary: 'Iyzico, bildirim ve reconcile hazırlığı',
    },
    { key: 'mail', label: 'Mail', summary: 'SMTP ve IMAP/POP3 ayarları' },
    {
        key: 'messaging',
        label: 'Mesajlaşma',
        summary: 'Provider, test modu, NAC ve mesaj tipleri',
    },
    {
        key: 'templates',
        label: 'Şablonlar',
        summary: 'WhatsApp, SMS ve voice preview',
    },
    {
        key: 'queue',
        label: 'Kuyruk / Loglar',
        summary: 'Outbox, provider sonucu ve güvenlik logları',
    },
    {
        key: 'integrations',
        label: 'Entegrasyonlar',
        summary: 'Portal, Mikro ve provider hazırlığı',
    },
];

const messagingSectionTabs: Array<{ key: MessagingSectionKey; label: string }> =
    [
        { key: 'general', label: 'Genel Ayarlar' },
        { key: 'provider_tests', label: 'Provider Testleri' },
        { key: 'evo', label: 'Evo / WhatsApp' },
        { key: 'nac_sms', label: 'SMS API / NAC' },
        { key: 'voibot', label: 'Voibot' },
        { key: 'message_types', label: 'Mesaj Tipleri' },
    ];

const templateSectionTabs: Array<{ key: TemplateSectionKey; label: string }> = [
    { key: 'whatsapp', label: 'WhatsApp Şablonları' },
    { key: 'sms', label: 'SMS Şablonları' },
    { key: 'voice', label: 'Sesli Arama Scriptleri' },
    { key: 'variables', label: 'Değişkenler / Önizleme' },
];

const integrationSectionTabs: Array<{
    key: IntegrationSectionKey;
    label: string;
}> = [
    { key: 'manual_e2e_portal', label: 'Portal / Manuel E2E' },
    { key: 'mikro_api', label: 'Mikro API' },
    { key: 'provider_credentials', label: 'Provider Credentials' },
    { key: 'operation_catalog', label: 'Operation Catalog' },
];

type QrPublicFlowSettings = {
    pre_form_payment_for_mount_excluded_enabled: boolean;
    key: string;
    label: string;
    ops_detail_visibility: {
        show_mount_excluded_approval_block: boolean;
        show_payment_mount_control_block: boolean;
        show_address_control_block: boolean;
        keys?: Record<string, string>;
    };
};

type PaymentProviderSettings = {
    real_provider_enabled: boolean;
    provider: 'fake' | 'iyzico' | string;
    configured_provider: string;
    provider_mode: 'sandbox' | 'live';
    provider_transport: string;
    provider_transport_label: string;
    live_send_approved: boolean;
    selected_provider_mode_label: string;
    effective_mode: string;
    effective_mode_label: string;
    fake_active: boolean;
    iyzico_urls: {
        sandbox_base_url: string;
        live_base_url: string;
        authorization_scheme: string;
        endpoints: Record<string, string>;
    };
    ip_whitelist: {
        source: string;
        source_label: string;
        status: string;
        label: string;
        outbound_ip_value: string | null;
        ready: boolean;
        manual_check_command: string;
        message: string;
    };
    back_url: {
        status: string;
        label: string;
        public_base_url: string | null;
        public_https_ready: boolean;
        payment_return_route_exists: boolean;
        payment_return_url: string | null;
        callback_url: string | null;
        global_back_url: string | null;
        callback_route_exists: boolean;
        callback_route_name: string | null;
        identification_rule: string;
        ready: boolean;
        message: string;
    };
    gateway: {
        url_configured: boolean;
        token_configured: boolean;
        health_verified: boolean;
        http_enabled: boolean;
        provider_send_enabled: boolean;
        provider_send_ready: boolean;
        ready: boolean;
        mode: 'sandbox' | 'live' | string;
        webhook_path: string;
    };
    credentials: {
        ready: boolean;
        source: string;
        source_label: string;
        api_key_status: string;
        secret_key_status: string;
        masked_api_key: string | null;
        masked_secret_key: string | null;
        entry_supported: boolean;
        entry_status: string;
        entry_message: string;
        last_updated_at: string | null;
        last_verified_at: string | null;
        last_verification_status: string | null;
        last_verification_message: string | null;
    };
    credential_bridge: {
        source: string;
        source_label: string;
        laravel_encrypted_credentials_saved: boolean;
        n8n_env_credentials_ready: boolean;
        credentials_ready_for_selected_source: boolean;
        safe_for_provider_send: boolean;
        status: string;
        message: string;
        normal_item_json_secret_allowed: boolean;
    };
    legacy_n8n_adapter: {
        active: boolean;
        status: string;
        message: string;
    };
    readiness: {
        effective_mode: string;
        selected_provider: string;
        selected_mode: 'sandbox' | 'live' | string;
        real_provider_enabled: boolean;
        provider_transport: string;
        credential_source: string;
        credentials_saved: boolean;
        credentials_ready_for_selected_source: boolean;
        gateway_url_configured: boolean;
        gateway_token_configured: boolean;
        gateway_ready: boolean;
        provider_send_enabled: boolean;
        provider_send_ready: boolean;
        live_send_approved: boolean;
        sandbox_base_url: string;
        live_base_url: string;
        ip_whitelist_confirmed: boolean;
        ip_whitelist_source: string;
        back_url_ready: boolean;
        callback_route_ready: boolean;
        live_readiness_ready: boolean;
        can_enable_real_provider: boolean;
        disabled_reason: string | null;
        next_required_action: string;
    };
    automatic_reconcile: {
        sandbox: {
            ready: boolean;
            label: string;
            message: string;
        };
        live: {
            ready: boolean;
            label: string;
            message: string;
        };
        back_url_status: string;
        callback_verified: boolean;
        accepted_fallback: string;
        live_release_requirement: string;
    };
    sandbox_activation_checklist: Array<{
        key: string;
        label: string;
        ready: boolean;
    }>;
    can_enable_real_provider: boolean;
    disabled_reason: string | null;
    health_status: {
        status: string;
        label: string;
        message: string;
    };
    payment_notification: {
        enabled: boolean;
        recipients: string[];
        recipients_text: string;
        smtp_ready: boolean;
        ready: boolean;
        status_label: string;
        helper_text: string;
    };
    company_recipient: {
        company_title: string | null;
        tax_office: string | null;
        tax_number: string | null;
        trade_registry_no: string | null;
        company_address: string | null;
        company_phone: string | null;
        company_email: string | null;
        iban_try: string | null;
        iban_usd: string | null;
        ready: boolean;
        missing_fields: string[];
        status_label: string;
        message: string;
        helper_text: string;
    };
    secret_source: string;
    warning: string;
};

type MessagingMessageType = {
    key: string;
    label: string;
    recipient_role: string;
    description: string;
    future: boolean;
    enabled: boolean;
    real_send_allowed: boolean;
    test_send_allowed: boolean;
    channel_policy: string;
    whatsapp_mode: string;
    sms_mode: string;
    whatsapp_provider: string;
    sms_provider: string;
    template_key: string | null;
    notes: string | null;
};

type MessagingProvider = {
    key: string;
    label: string;
    channel: string;
    description: string;
    status_label: string;
    enabled: boolean;
    real_send_allowed: boolean;
    test_send_allowed: boolean;
    contract_confirmed: boolean;
    current_practical: boolean;
    active: boolean;
    default: boolean;
    fallback: boolean;
    real_ready: boolean;
    ready_reason: string | null;
    capabilities: Record<string, boolean>;
    notes: string | null;
};

type MessagingEvoWhatsAppSettings = {
    direct_api_enabled: boolean;
    direct_api_base_url: string | null;
    direct_api_instance_name: string | null;
    endpoint_url: string | null;
    delay: number;
    link_preview: boolean;
    credentials_ready: boolean;
    api_key_mask: string | null;
    token_mask: string | null;
    direct_api_ready: boolean;
    queue_ready: boolean;
    test_ready: boolean;
    live_ready: boolean;
    legacy_webhook_configured: boolean;
    transport: string;
    last_test_status: string | null;
    last_error_redacted: string | null;
    blocking_reasons: string[];
};

type MessagingNacSmsSettings = {
    enabled: boolean;
    profile: 'docs_https_9588' | 'legacy_working_http_9587' | 'custom';
    scheme: 'http' | 'https';
    host: string | null;
    port: number;
    path: string;
    request_shape: 'legacy_working_minimal' | 'docs_full';
    base_url: string;
    endpoint_url: string;
    sender: string | null;
    title: string | null;
    gateway_uuid: string | null;
    encoding: number;
    commercial: boolean;
    skip_ahs_query: boolean;
    recipient_type: number;
    validity: number;
    report_push_url: string | null;
    use_shared_test_phone: boolean;
    test_phone: string | null;
    test_phone_masked: string | null;
    real_send_allowed: boolean;
    credentials_ready: boolean;
    username_mask: string | null;
    password_mask: string | null;
    test_ready: boolean;
    live_ready: boolean;
    queue_ready: boolean;
    blocking_reasons: string[];
};

type MessagingMikroOperation = {
    operation_key: string;
    display_name: string;
    category: string;
    mode: 'READ' | 'WRITE';
    capability_status: string;
    contract_status: string;
    evidence_status: string;
    implementation_status: string;
    runtime_eligible: boolean;
    runtime_enabled: boolean;
    adapter_type: 'DIRECT_ENDPOINT' | 'FIXED_QUERY' | 'CONTRACT_BLOCKED';
    endpoint: string | null;
    method: string | null;
    mikro_method: string | null;
    fixed_query_id: string | null;
    request_type: string;
    response_type: string;
    source_mode: 'mikro' | 'n8n' | 'shadow_compare' | 'disabled';
    approval_required: boolean;
    parity_status: string;
    last_success_at: string | null;
    last_error_code: string | null;
    circuit_state: string;
    blocker: string | null;
    official_doc_reference: string | null;
    official_method: string | null;
    exact_path: string | null;
    exact_path_casing: string | null;
    installed_server_canary: string;
    evidence_hash: string;
};

type MessagingMikroApiSettings = {
    enabled: boolean;
    base_url: string | null;
    api_version: string | null;
    application_code: string | null;
    application_name: string | null;
    company_code: string | null;
    branch_code: string | null;
    workstation_code: string | null;
    fiscal_year: string | null;
    timeout_seconds: number;
    server_timezone: string;
    license_status: string | null;
    app_customer_license_status: string | null;
    read_sync_enabled: boolean;
    write_enabled: boolean;
    write_approval_required: boolean;
    operation_catalog_status: string | null;
    operation_catalog: {
        status: string;
        read_count: number;
        write_count: number;
        implemented_read_count: number;
        enabled_read_count: number;
        enabled_write_count: number;
        direct_endpoint_count: number;
        fixed_query_count: number;
        contract_blocked_count: number;
        server_verified_read_count: number;
        server_unverified_count: number;
        runtime_eligible_read_count: number;
        matrix_complete: boolean;
        enabled_keys: string[];
        operations: MessagingMikroOperation[];
    };
    operation_controls: Record<
        string,
        {
            runtime_enabled?: boolean;
            source_mode?: 'mikro' | 'n8n' | 'shadow_compare' | 'disabled';
            approval_required?: boolean;
        }
    >;
    read_operation_count: number;
    write_operation_count: number;
    implemented_read_operation_count: number;
    enabled_read_operation_count: number;
    enabled_write_operation_count: number;
    direct_endpoint_operation_count: number;
    fixed_query_operation_count: number;
    contract_blocked_operation_count: number;
    server_verified_read_operation_count: number;
    server_unverified_operation_count: number;
    runtime_eligible_read_operation_count: number;
    contract_ready: boolean;
    live_configuration_ready: boolean;
    readiness_status:
        | 'CONTRACT_READY'
        | 'LIVE_CONNECTIVITY_PENDING'
        | 'READY'
        | 'BLOCKED';
    live_activation_status:
        | 'LIVE_CONFIGURATION_MISSING'
        | 'LIVE_CONNECTIVITY_PENDING'
        | 'READY';
    live_activation_blocker: string | null;
    connection_test_allowed: boolean;
    credentials_ready: boolean;
    user_code_mask: string | null;
    password_mask: string | null;
    api_key_mask: string | null;
    token_mask: string | null;
    read_ready: boolean;
    write_ready: boolean;
    blocker_codes: string[];
    blocking_reasons: string[];
};

type MessagingAdminSection = {
    key: string;
    label: string;
    ready: boolean;
    summary: string;
};

type ManualE2EReadiness = {
    eligible: boolean;
    blockers: Array<{ code: string; message: string }>;
    warnings: Array<{ code: string; message: string }>;
    evo_ready: boolean;
    nac_ready: boolean;
    allowlisted_phone_masks: string[];
    customer_allowlisted_phone_masks: string[];
    ops_whatsapp_phone_mask: string | null;
    ops_whatsapp_enabled: boolean;
    ops_sms_enabled: boolean;
    pending_external_count: number;
    unsafe_external_count: number;
    worker_lock_available: boolean;
    worker_lock_raw_available: boolean;
    worker_state: 'none' | 'active' | 'stale' | 'invalidated';
    worker_run_id: string | null;
    worker_heartbeat_at: string | null;
    worker_stale_recoverable: boolean;
    lifecycle_lock_available: boolean;
    active_run_id: string | null;
    active_run_status: string;
    ttl_seconds: number;
    channel_policies: Array<{
        message_type: string;
        channel_policy: string;
        whatsapp_mode: string;
        sms_mode: string;
    }>;
};

type MessagingExecutionMode = {
    mode: 'local' | 'live';
    state: 'local' | 'activating' | 'live' | 'freezing' | 'blocked';
    epoch: number;
    revision: number;
    runtime_environment: 'local' | 'staging' | 'production' | string;
    runtime_environment_label: string;
    classification: string;
    real_send_enabled: boolean;
    queue_paused: boolean;
    manual_e2e_enabled: boolean;
    manual_e2e_phase: 'frozen' | 'prepared' | 'window_open' | string;
    changed_at: string | null;
    changed_by: { id: number; name: string } | null;
    reason: string | null;
    release_sha: string | null;
    readiness: {
        eligible: boolean;
        blocker_count: number;
        required_count: number;
        required_adapted_count: number;
        required_ready_count: number;
        registered_count: number;
        target_mode: 'live';
        runtime_environment: string;
        classification: string;
        blockers: Array<{ code: string; message: string }>;
        evo_ready: boolean;
        nac_ready: boolean;
        queue_worker_ready: boolean;
        queue_worker_state: string;
        queue_worker_heartbeat_at: string | null;
        scheduler_topology_accepted: boolean;
        public_https_ready: boolean;
        manual_e2e_origin_ready: boolean;
        pending_external_count: number;
        unsafe_external_count: number;
        manual_e2e_frozen: boolean;
        normal_outbound_claim_clear: boolean;
        normal_queue_closed: boolean;
        provider_kill_switch_consistent: boolean;
        allowlist_count: number;
        release_sha: string | null;
    };
};

type MessagingSettings = {
    global: {
        messaging_enabled: boolean;
        real_send_enabled: boolean;
        test_mode_enabled: boolean;
        manual_e2e_enabled: boolean;
        manual_e2e_phase: 'frozen' | 'prepared' | 'window_open';
        manual_e2e_active_run_id: string | null;
        manual_e2e_started_at: string | null;
        manual_e2e_created_after: string | null;
        manual_e2e_expires_at: string | null;
        manual_e2e_last_run_id: string | null;
        manual_e2e_last_stopped_at: string | null;
        manual_e2e_ttl_seconds: number;
        manual_e2e_allowlisted_phones: string[];
        manual_e2e_partner_portal_origin_enabled: boolean;
        manual_e2e_partner_portal_origin: string | null;
        test_phone: string | null;
        test_phone_masked: string | null;
        queue_paused: boolean;
        provider_key: string;
        active_provider: string;
        default_provider: string;
        fallback_provider: string;
        provider_priority: string[];
        send_delay_seconds: number;
        duplicate_cooldown_minutes: number;
        hourly_limit: number;
        daily_limit: number;
        max_auto_retries: number;
        allow_browser_smoke_send: boolean;
        allow_test_fixture_send: boolean;
    };
    execution_mode: MessagingExecutionMode;
    readiness: {
        messaging_enabled: boolean;
        real_send_enabled: boolean;
        test_mode_enabled: boolean;
        test_phone_configured: boolean;
        provider_webhook_configured: boolean;
        provider_secret_configured: boolean;
        evo_direct_api_enabled: boolean;
        evo_direct_api_ready: boolean;
        evo_direct_api_credentials_ready: boolean;
        evo_direct_api_base_url_configured: boolean;
        evo_direct_api_instance_configured: boolean;
        active_provider: string;
        active_provider_label: string;
        default_provider: string;
        fallback_provider: string;
        provider_priority: string[];
        active_provider_enabled: boolean;
        active_provider_supports_text: boolean;
        active_provider_contract_confirmed: boolean;
        active_provider_credentials_ready: boolean;
        active_provider_real_ready: boolean;
        queue_ready: boolean;
        manual_e2e_active: boolean;
        manual_e2e_worker_command_ready: boolean;
        manual_e2e_blocker_code: string | null;
        manual_e2e_partner_portal_ready: boolean;
        live_public_partner_portal_ready: boolean;
        can_send_test: boolean;
        can_send_real: boolean;
        effective_mode: string;
        disabled_reasons: string[];
        real_allowed_message_types: string[];
        test_allowed_message_types: string[];
    };
    portal_origins: {
        manual_e2e: {
            enabled: boolean;
            origin: string | null;
            valid: boolean;
            private_lan: boolean;
            loopback: boolean;
            phone_ready: boolean;
            ready: boolean;
            status: 'ready' | 'missing' | 'invalid';
            status_label: 'Ready' | 'Missing' | 'Invalid';
            source: string;
            warning: string | null;
        };
        live_public: {
            origin: string | null;
            ready: boolean;
            status: 'ready' | 'missing';
            status_label: 'Ready' | 'Missing';
            source: string;
        };
    };
    manual_e2e: {
        enabled: boolean;
        active: boolean;
        phase: 'frozen' | 'prepared' | 'window_open' | 'invalid';
        status: 'active' | 'expired' | 'not_started' | 'inactive';
        status_label: string;
        active_run_id: string | null;
        started_at: string | null;
        created_after: string | null;
        expires_at: string | null;
        remaining_ttl_seconds: number;
        worker_command_ready: boolean;
        worker_command: string | null;
        allowlisted_phone_count: number;
        open_window: {
            id: string;
            status: string;
            run_id: string;
            dispatch_id: number;
            provider: string;
            channel: string;
            role_target: string;
            request_id: number;
            offer_cycle_id: number | null;
            opened_at: string;
            expires_at: string;
            maximum_attempts: number;
        } | null;
        active_claim: {
            id: string;
            status: string;
            run_id: string;
            dispatch_id: number;
            provider: string;
            channel: string;
            role_target: string;
            request_id: number;
            offer_cycle_id: number | null;
            opened_at: string;
            expires_at: string;
            claimed_at?: string;
            http_started_at?: string;
            maximum_attempts: number;
        } | null;
        blocker_code: string | null;
        blocker_message: string | null;
        last_run_id: string | null;
        last_stopped_at: string | null;
    };
    provider: {
        active_provider: string;
        default_provider: string;
        fallback_provider: string;
        provider_priority: string[];
        webhook_url_configured: boolean;
        provider_secret_configured: boolean;
        webhook_url_value: string | null;
        secret_value: null;
        webhook_path: string | null;
        direct_api_ready: boolean;
        direct_api_endpoint: string | null;
        router: string;
    };
    providers: MessagingProvider[];
    capability_map: Record<string, Record<string, boolean>>;
    evo_whatsapp: MessagingEvoWhatsAppSettings;
    nac_sms: MessagingNacSmsSettings;
    mikro_api: MessagingMikroApiSettings;
    admin_sections: MessagingAdminSection[];
    message_types: MessagingMessageType[];
    warnings: string[];
    helper_texts: {
        secrets: string;
        queue: string;
        test_phone: string;
    };
};

type MessageDispatchQueuePayload = {
    summary: Record<
        | 'queued'
        | 'sending'
        | 'sent'
        | 'failed'
        | 'duplicate_blocked'
        | 'rate_limited'
        | 'cancelled',
        number
    >;
    recent: MessageDispatchRow[];
    filters: {
        providers: QueueFilterOption[];
        channels: QueueFilterOption[];
        recipient_roles: QueueFilterOption[];
        statuses: QueueFilterOption[];
        message_types: QueueFilterOption[];
    };
    labels: Record<string, Record<string, string>>;
    pagination: {
        current_page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
    warnings: string[];
};

type QueueFilterOption = {
    value: string;
    label: string;
};

type QueueDatePayload = {
    utc: string | null;
    local: string | null;
    human: string | null;
};

type MessageDispatchRow = {
    id: number;
    status: string;
    status_label: string;
    status_badge_tone: string;
    provider_key: string | null;
    provider_label: string;
    channel: string | null;
    channel_label: string;
    message_type: string | null;
    message_type_label: string;
    recipient_role: string | null;
    recipient_role_label: string;
    target_masked: string | null;
    reference: string | number | null;
    idempotency_key_short: string | null;
    attempt_count: number;
    max_attempts: number;
    provider_message_id: string | null;
    template_key: string | null;
    payload_hash_short: string | null;
    message_preview: string;
    last_error_redacted: string | null;
    force_resend_reason: string | null;
    created_by: number | null;
    created_at: QueueDatePayload | null;
    queued_at: QueueDatePayload | null;
    sent_at: QueueDatePayload | null;
    failed_at: QueueDatePayload | null;
    display_time: QueueDatePayload | null;
};

type MessageDispatchDetail = MessageDispatchRow & {
    target_phone_full: string | null;
    target_phone_masked: string | null;
    original_recipient_phone_full: string | null;
    original_recipient_phone_masked: string | null;
    test_redirect_applied: boolean;
    request_id: number | null;
    template_key: string | null;
    template_label: string;
    template_version: number | null;
    channel_policy: string | null;
    idempotency_label: string;
    payload_hash: string | null;
    payload_hash_short: string | null;
    rendered_body_hash: string | null;
    sending_started_at: QueueDatePayload | null;
    provider_status: string | null;
    provider_response_redacted: unknown;
    provider_payload_body_hash: string | null;
    provider_payload_body_matches_dispatch: boolean | null;
    provider_request_target_phone: string | null;
    provider_request_target_type: string | null;
    provider_request_recipient_role: string | null;
    provider_request_preview: string | null;
    provider_payload_warning: string | null;
    last_error_code: string | null;
    parent_dispatch_id: number | null;
    force_resend: boolean;
    triggered_by: string | null;
    rendered_message_content: string;
    message_content_missing_reason: string | null;
    message_content_source: string | null;
    message_preview: string;
    sms_footer_note: string | null;
    technical_keys: Record<string, string | number | null>;
};

type QueueFilterState = {
    status: string[];
    provider: string[];
    channel: string[];
    recipient_role: string[];
    message_type: string[];
    date_from: string;
    date_to: string;
    q: string;
    phone: string;
    only_failed: boolean;
    only_queued: boolean;
    only_test: boolean;
    only_business: boolean;
    only_duplicate_blocked: boolean;
};

type MessagingTypeInputs = Record<
    string,
    {
        enabled: boolean;
        real_send_allowed: boolean;
        test_send_allowed: boolean;
        channel_policy: string;
        whatsapp_mode: string;
        sms_mode: string;
        whatsapp_provider: string;
        sms_provider: string;
        template_key: string;
        notes: string;
    }
>;

type MessageTemplateRecord = {
    id: number | null;
    template_key: string;
    message_type: string;
    channel: string;
    provider_key: string | null;
    title: string;
    body: string;
    active: boolean;
    locale: string;
    version: number;
    required_variables: string[];
    optional_variables: string[];
    validation_rules: Record<string, unknown>;
    metadata: Record<string, unknown>;
    is_default: boolean;
    no_send: boolean;
};

type MessageTemplateVariable = {
    key: string;
    label: string;
    description: string;
    sample: string | number | boolean | null;
    source: string;
};

type MessageTemplatePreview = {
    rendered_body: string;
    missing_variables: string[];
    unresolved_variables: string[];
    forbidden_variables: string[];
    warnings: string[];
    blockers: string[];
    sms: {
        characters: number;
        segments: number;
        encoding: string;
        contains_link: boolean;
        line_count: number;
    } | null;
    preview_ready: boolean;
    send_ready: boolean;
    payer_state_key: string | null;
    recipient_role: string;
    recipient_phone: string | null;
    context?: Record<string, unknown>;
    no_send: boolean;
};

type TemplateTestSendResult = {
    message: string;
    dispatch: {
        id: number;
        event: string;
        status: string;
        target_type: string;
        target_phone_masked: string | null;
        response_status: string | number | null;
        provider_reference: string | null;
        error_message: string | null;
        test_type: string;
        content_preview: string | null;
        encoding: string | number | null;
        test_code: string | null;
        custom_id: string | null;
        payload_hash: string | null;
        previous_payload_hash: string | null;
        duplicate: boolean;
    };
};

type MessageTemplateSettings = {
    message_types: Array<{
        key?: string;
        label: string;
        recipient_role: string;
        description: string;
        allowed_channels: string[];
        required_variables: string[];
        optional_variables: string[];
    }>;
    channels: Array<{ key: string; label: string }>;
    providers: Array<{
        key: string;
        label: string;
        channel: string;
        contract_confirmed: boolean;
    }>;
    variables: MessageTemplateVariable[];
    templates: MessageTemplateRecord[];
    forbidden_variables: string[];
    no_send: boolean;
    helper_text: string;
};

function smsPreviewMetadataFromText(
    renderedBody: string,
): NonNullable<MessageTemplatePreview['sms']> {
    const characters = Array.from(renderedBody).length;
    const hasUnicode = Array.from(renderedBody).some(
        (character) => character.charCodeAt(0) > 127,
    );
    const singleLimit = hasUnicode ? 70 : 160;
    const multiLimit = hasUnicode ? 67 : 153;

    return {
        characters,
        segments:
            characters <= singleLimit ? 1 : Math.ceil(characters / multiLimit),
        encoding: hasUnicode ? 'unicode' : 'gsm',
        contains_link: /https?:\/\//i.test(renderedBody),
        line_count: renderedBody.split(/\r?\n/).filter(Boolean).length,
    };
}

type TemplateSampleInputs = {
    job_type: 'montaj' | 'servis';
    payer_state_key:
        | 'company_collected_online'
        | 'customer_pays_technician'
        | 'pending_online_payment'
        | 'no_payment_required';
    event:
        | 'appointment'
        | 'job_rejected'
        | 'support_request'
        | 'price_revision'
        | 'completion_submitted';
    appointment_time_case:
        | 'morning_1200'
        | 'boundary_1300'
        | 'afternoon_1400_1600'
        | 'missing_exact_time';
};

function templateSampleContextFromInputs(
    inputs: TemplateSampleInputs,
): Record<string, unknown> {
    const isService = inputs.job_type === 'servis';
    const base: Record<string, unknown> = {
        job_type: inputs.job_type,
        customer_name: isService
            ? 'PR88 Servis Müşteri'
            : 'PR88 Montaj Müşteri',
        customer_phone: '905555555555',
        mrn: isService ? 'MRN-REL4C6-INTERNAL' : 'MRN-REL4C6-MONTAJ',
        srv: isService ? 'SRV-REL4C6-SERVIS' : '',
        request_code: isService ? 'SRV-REL4C6-SERVIS' : 'MRN-REL4C6-MONTAJ',
        product_name: 'EMAKS kilit sistemi',
        serial_no: 'PR88-REL4C6-SERIAL',
        appointment_date: '2026-07-03',
        appointment_time: '14:00 - 16:00',
        appointment_date_formatted: '03.07.2026',
        customer_payment_amount:
            inputs.payer_state_key === 'customer_pays_technician' ? 1250 : 0,
        customer_payment_amount_formatted:
            inputs.payer_state_key === 'customer_pays_technician'
                ? '1.250,00 TL'
                : '',
        payer_state_key: inputs.payer_state_key,
        payment_link: 'https://sandbox.iyzi.link/rel4c6',
        payment_link_sms: 'https://e.ms/pay/R4C6',
        payment_amount_formatted: '1.250,00 TL',
        confirmation_link: 'https://panel.example.test/onay/REL4C6',
        confirmation_link_sms: 'https://e.ms/onay/R4C6',
        survey_link: 'https://e.ms/anket/R4C6',
        technician_name: 'PR88 Test Usta',
        technician_phone: '905444444444',
        address: 'Test Mah. Örnek Sok. No:1 Kadıköy / İstanbul',
        city: 'İstanbul',
        district: 'Kadıköy',
        maps_url:
            'https://www.google.com/maps/search/?api=1&query=40.987654,29.123456',
        technician_job_card_url:
            'https://panel.example.test/partner/jobs/PR88-REL4C6',
        technician_job_card_short_url: 'https://e.ms/job/R4C6',
        labor_amount_formatted: '900,00 TL',
        route_fee_formatted: '350,00 TL',
        technician_earning_total_formatted: '1.250,00 TL',
        internal_job_reference: isService
            ? 'SRV: SRV-REL4C6-SERVIS / MRN: MRN-REL4C6-INTERNAL'
            : 'MRN: MRN-REL4C6-MONTAJ',
        actor_name: 'PR88 Test Usta',
        support_subject: 'Parça teyidi gerekiyor',
        support_note: 'Müşteri ek parça talep etti; OPS onayı gerekiyor.',
        created_at_formatted: '03.07.2026 14:30',
        rejection_reason: 'Usta belirtilen saat aralığında uygun değil.',
        rejected_at_formatted: '03.07.2026 14:35',
        old_amount_formatted: '1.250,00 TL',
        requested_amount_formatted: '1.650,00 TL',
        revision_reason: 'Adres uzaklığı ve ek işçilik eklendi.',
        completed_at_formatted: '03.07.2026 18:10',
        next_action_text: 'OPS son kontrol / müşteri onayı',
    };

    if (inputs.payer_state_key !== 'customer_pays_technician') {
        base.customer_payment_amount = 0;
        base.customer_payment_amount_formatted = '';
    }

    if (inputs.appointment_time_case === 'morning_1200') {
        base.appointment_time = '12:00';
    }

    if (inputs.appointment_time_case === 'boundary_1300') {
        base.appointment_time = '13:00';
    }

    if (inputs.appointment_time_case === 'afternoon_1400_1600') {
        base.appointment_time = '14:00 - 16:00';
    }

    if (inputs.appointment_time_case === 'missing_exact_time') {
        delete base.appointment_time;
    }

    delete base.appointment_time_range;
    delete base.appointment_exact_time_range;
    delete base.appointment_customer_window;
    delete base.appointment_window_for_technician;

    return base;
}

type MailTransportSettings = {
    outgoing: {
        enabled: boolean;
        mailer: string;
        host: string | null;
        port: number | null;
        encryption: 'tls' | 'ssl' | 'none';
        username_mask: string | null;
        password_mask: string | null;
        from_address: string | null;
        from_name: string | null;
        ready: boolean;
        status_label: string;
        readiness_message: string;
        last_tested_at: string | null;
        last_test_status: string | null;
        last_test_message: string | null;
    };
    incoming: {
        enabled: boolean;
        protocol: 'imap' | 'pop3';
        host: string | null;
        port: number | null;
        encryption: 'tls' | 'ssl' | 'none';
        username_mask: string | null;
        password_mask: string | null;
        mailbox: string | null;
        ready: boolean;
        status_label: string;
        readiness_message: string;
        last_tested_at: string | null;
        last_test_status: string | null;
        last_test_message: string | null;
    };
    payment_notification_ready: boolean;
    helper_texts: {
        outgoing: string;
        incoming: string;
        secrets: string;
    };
};

function messageTypeInputsFromSettings(
    settings: MessagingSettings,
): MessagingTypeInputs {
    return Object.fromEntries(
        settings.message_types.map((item) => [
            item.key,
            {
                enabled: item.enabled,
                real_send_allowed: item.real_send_allowed,
                test_send_allowed: item.test_send_allowed,
                channel_policy: item.channel_policy,
                whatsapp_mode: item.whatsapp_mode,
                sms_mode: item.sms_mode,
                whatsapp_provider: item.whatsapp_provider,
                sms_provider: item.sms_provider,
                template_key: item.template_key ?? '',
                notes: item.notes ?? '',
            },
        ]),
    );
}

function templateInputsFromTemplate(template?: MessageTemplateRecord) {
    return {
        template_key: template?.template_key ?? '',
        message_type: template?.message_type ?? 'appointment_approved_customer',
        channel: template?.channel ?? 'whatsapp',
        provider_key: template?.provider_key ?? '',
        title: template?.title ?? '',
        body: template?.body ?? '',
        active: template?.active ?? true,
        required_variables: template?.required_variables?.join(', ') ?? '',
        optional_variables: template?.optional_variables?.join(', ') ?? '',
    };
}

function nacSmsInputsFromSettings(settings: MessagingSettings) {
    return {
        enabled: settings.nac_sms.enabled,
        profile: settings.nac_sms.profile,
        scheme: settings.nac_sms.scheme,
        host: settings.nac_sms.host ?? '',
        port: String(settings.nac_sms.port),
        path: settings.nac_sms.path,
        request_shape: settings.nac_sms.request_shape,
        sender: settings.nac_sms.sender ?? '',
        title: settings.nac_sms.title ?? '',
        gateway_uuid: settings.nac_sms.gateway_uuid ?? '',
        encoding: String(settings.nac_sms.encoding),
        commercial: settings.nac_sms.commercial,
        skip_ahs_query: settings.nac_sms.skip_ahs_query,
        recipient_type: String(settings.nac_sms.recipient_type),
        validity: String(settings.nac_sms.validity),
        report_push_url: settings.nac_sms.report_push_url ?? '',
        use_shared_test_phone: settings.nac_sms.use_shared_test_phone,
        test_phone: settings.nac_sms.test_phone ?? '',
        real_send_allowed: settings.nac_sms.real_send_allowed,
    };
}

function evoWhatsappInputsFromSettings(settings: MessagingSettings) {
    return {
        direct_api_enabled: settings.evo_whatsapp.direct_api_enabled,
        direct_api_base_url: settings.evo_whatsapp.direct_api_base_url ?? '',
        direct_api_instance_name:
            settings.evo_whatsapp.direct_api_instance_name ?? '',
        delay: String(settings.evo_whatsapp.delay),
        link_preview: settings.evo_whatsapp.link_preview,
    };
}

function mikroApiInputsFromSettings(settings: MessagingSettings) {
    return {
        enabled: settings.mikro_api.enabled,
        base_url: settings.mikro_api.base_url ?? '',
        api_version: settings.mikro_api.api_version ?? 'V17',
        application_code: settings.mikro_api.application_code ?? '',
        application_name: settings.mikro_api.application_name ?? '',
        company_code: settings.mikro_api.company_code ?? '',
        branch_code: settings.mikro_api.branch_code ?? '',
        workstation_code: settings.mikro_api.workstation_code ?? '',
        fiscal_year: settings.mikro_api.fiscal_year ?? '',
        timeout_seconds: String(settings.mikro_api.timeout_seconds),
        server_timezone:
            settings.mikro_api.server_timezone ?? 'Europe/Istanbul',
        license_status: settings.mikro_api.license_status ?? 'unknown',
        app_customer_license_status:
            settings.mikro_api.app_customer_license_status ?? 'unknown',
        read_sync_enabled: settings.mikro_api.read_sync_enabled,
        write_enabled: settings.mikro_api.write_enabled,
        write_approval_required: settings.mikro_api.write_approval_required,
        operation_catalog_status:
            settings.mikro_api.operation_catalog_status ?? 'missing',
        operation_controls: settings.mikro_api.operation_controls ?? {},
    };
}

function formatManualE2ERunDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('tr-TR', {
        dateStyle: 'short',
        timeStyle: 'medium',
    }).format(date);
}

function messagingRuntimeHeadline(settings: MessagingSettings): string {
    if (settings.execution_mode.mode === 'local') {
        return 'Lokal mod; dış provider çağrıları kapalı.';
    }

    if (
        settings.global.manual_e2e_enabled &&
        settings.global.real_send_enabled &&
        !settings.global.queue_paused &&
        settings.manual_e2e.active
    ) {
        return 'Manual E2E açık; gerçek gönderim kontrollü.';
    }

    if (settings.global.real_send_enabled) {
        return settings.global.queue_paused
            ? 'Gerçek gönderim açık; provider kuyruğu duraklatıldı.'
            : 'Gerçek gönderim açık.';
    }

    if (settings.global.test_mode_enabled) {
        return 'Gerçek gönderim kapalı; test modu açık.';
    }

    return 'Gönderimler donduruldu.';
}

function messagingEffectiveModeLabel(mode: string): string {
    const labels: Record<string, string> = {
        disabled: 'Mesaj sistemi kapalı',
        blocked_provider_disabled: 'Sağlayıcı kapalı',
        blocked_provider_contract_pending: 'Sağlayıcı sözleşmesi bekliyor',
        blocked_missing_test_phone: 'Test telefonu eksik',
        test_redirect: 'Kontrollü test yönlendirmesi',
        blocked_provider_missing: 'Sağlayıcı ayarları eksik',
        blocked_real_send_disabled: 'Gerçek gönderim kapalı',
        blocked_provider_not_ready: 'Sağlayıcı hazır değil',
        real_ready: 'Kontrollü gerçek gönderim',
    };

    return labels[mode] ?? mode;
}

function messagingQueueStatus(settings: MessagingSettings): {
    label: string;
    ready: boolean;
} {
    if (
        settings.execution_mode.mode === 'live' &&
        settings.execution_mode.runtime_environment === 'production'
    ) {
        return settings.execution_mode.readiness.queue_worker_ready
            ? { label: 'Production worker hazır', ready: true }
            : {
                  label:
                      settings.execution_mode.readiness.queue_worker_state ||
                      'Worker hazır değil',
                  ready: false,
              };
    }

    if (settings.global.queue_paused) {
        return { label: 'Duraklatıldı', ready: false };
    }

    if (
        settings.global.manual_e2e_enabled &&
        settings.manual_e2e.active &&
        settings.readiness.queue_ready
    ) {
        return { label: 'Manual E2E queue açık', ready: true };
    }

    return { label: 'Aktif run context eksik', ready: false };
}

function csrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function errorMessageFromResponse(
    response: Response,
    fallback: string,
): Promise<string> {
    try {
        const payload = await response.json();
        const firstError =
            payload?.errors && typeof payload.errors === 'object'
                ? Object.values(payload.errors).flat().find(Boolean)
                : null;

        return String(
            firstError || payload?.message || payload?.error || fallback,
        );
    } catch {
        return fallback;
    }
}

export default function TechnicalServiceAdmin({
    qrPublicFlowSettings,
    messagingSettings,
    messageTemplateSettings,
    messageDispatchQueue,
    paymentProviderSettings,
    mailTransportSettings,
}: {
    qrPublicFlowSettings: QrPublicFlowSettings;
    messagingSettings: MessagingSettings;
    messageTemplateSettings: MessageTemplateSettings;
    messageDispatchQueue: MessageDispatchQueuePayload;
    paymentProviderSettings: PaymentProviderSettings;
    mailTransportSettings: MailTransportSettings;
}) {
    const [preFormPaymentEnabled, setPreFormPaymentEnabled] = useState(
        qrPublicFlowSettings.pre_form_payment_for_mount_excluded_enabled,
    );
    const [messaging, setMessaging] = useState(messagingSettings);
    const messagingSettingsLocked =
        messaging.execution_mode.mode === 'live' ||
        messaging.global.manual_e2e_enabled ||
        messaging.manual_e2e.active_run_id !== null;
    const messagingQueueState = messagingQueueStatus(messaging);
    const [messageTemplates, setMessageTemplates] = useState(
        messageTemplateSettings,
    );
    const [dispatchQueue, setDispatchQueue] = useState(messageDispatchQueue);
    const [queueFilters, setQueueFilters] = useState<QueueFilterState>({
        status: [],
        provider: [],
        channel: [],
        recipient_role: [],
        message_type: [],
        date_from: '',
        date_to: '',
        q: '',
        phone: '',
        only_failed: false,
        only_queued: false,
        only_test: false,
        only_business: false,
        only_duplicate_blocked: false,
    });
    const [queueLoading, setQueueLoading] = useState(false);
    const [queueBackgroundRefreshing, setQueueBackgroundRefreshing] =
        useState(false);
    const [queueAutoRefreshEnabled, setQueueAutoRefreshEnabled] =
        useState(true);
    const [queueLastRefreshedAt, setQueueLastRefreshedAt] = useState('');
    const [queueRefreshError, setQueueRefreshError] = useState('');
    const [queueDetailLoading, setQueueDetailLoading] = useState(false);
    const [selectedDispatchDetail, setSelectedDispatchDetail] =
        useState<MessageDispatchDetail | null>(null);
    const queueRefreshAbortRef = useRef<AbortController | null>(null);
    const [paymentSettings, setPaymentSettings] = useState(
        paymentProviderSettings,
    );
    const [mailSettings, setMailSettings] = useState(mailTransportSettings);
    const [opsDetailVisibility, setOpsDetailVisibility] = useState({
        show_mount_excluded_approval_block: Boolean(
            qrPublicFlowSettings.ops_detail_visibility
                ?.show_mount_excluded_approval_block,
        ),
        show_payment_mount_control_block: Boolean(
            qrPublicFlowSettings.ops_detail_visibility
                ?.show_payment_mount_control_block,
        ),
        show_address_control_block: Boolean(
            qrPublicFlowSettings.ops_detail_visibility
                ?.show_address_control_block,
        ),
    });
    const [saving, setSaving] = useState(false);
    const [paymentSaving, setPaymentSaving] = useState(false);
    const [credentialSaving, setCredentialSaving] = useState(false);
    const [healthChecking, setHealthChecking] = useState(false);
    const [mailSaving, setMailSaving] = useState(false);
    const [mailTesting, setMailTesting] = useState(false);
    const [messagingSaving, setMessagingSaving] = useState(false);
    const [manualE2EReadinessChecking, setManualE2EReadinessChecking] =
        useState(false);
    const [manualE2ELifecycleBusy, setManualE2ELifecycleBusy] = useState(false);
    const manualE2ELifecycleBusyRef = useRef(false);
    const [manualE2EReadiness, setManualE2EReadiness] =
        useState<ManualE2EReadiness | null>(null);
    const [manualE2EEnableConfirmationOpen, setManualE2EEnableConfirmationOpen] =
        useState(false);
    const [templateSaving, setTemplateSaving] = useState(false);
    const [templatePreviewing, setTemplatePreviewing] = useState(false);
    const [templateTestSending, setTemplateTestSending] = useState(false);
    const [messagingPhoneChecking, setMessagingPhoneChecking] = useState(false);
    const [integrationCredentialSaving, setIntegrationCredentialSaving] =
        useState(false);
    const [mikroConnectionTesting, setMikroConnectionTesting] = useState(false);
    const [mikroCircuitResetting, setMikroCircuitResetting] = useState<
        string | null
    >(null);
    const [activeAdminSection, setActiveAdminSection] =
        useState<AdminSectionKey>('overview');
    const [activeMessagingSection, setActiveMessagingSection] =
        useState<MessagingSectionKey>('general');
    const [activeTemplateSection, setActiveTemplateSection] =
        useState<TemplateSectionKey>('whatsapp');
    const [activeIntegrationSection, setActiveIntegrationSection] =
        useState<IntegrationSectionKey>('mikro_api');
    const [message, setMessage] = useState('');
    const [paymentMessage, setPaymentMessage] = useState('');
    const [mailMessage, setMailMessage] = useState('');
    const [messagingMessage, setMessagingMessage] = useState('');
    const [templateMessage, setTemplateMessage] = useState('');
    const firstTemplate = messageTemplateSettings.templates[0];
    const [selectedTemplateKey, setSelectedTemplateKey] = useState(
        firstTemplate?.template_key ?? '',
    );
    const [templateInputs, setTemplateInputs] = useState(() =>
        templateInputsFromTemplate(firstTemplate),
    );
    const [templateSampleInputs, setTemplateSampleInputs] =
        useState<TemplateSampleInputs>({
            job_type: 'montaj',
            payer_state_key: 'customer_pays_technician',
            event: 'appointment',
            appointment_time_case: 'afternoon_1400_1600',
        });
    const [templatePreview, setTemplatePreview] =
        useState<MessageTemplatePreview | null>(null);
    const [templateTestSendResult, setTemplateTestSendResult] =
        useState<TemplateTestSendResult | null>(null);
    const [providerTestSending, setProviderTestSending] = useState(false);
    const [providerTestSendResult, setProviderTestSendResult] =
        useState<TemplateTestSendResult | null>(null);
    const [credentialInputs, setCredentialInputs] = useState({
        api_key: '',
        secret_key: '',
    });
    const [messagingInputs, setMessagingInputs] = useState({
        messaging_enabled: messagingSettings.global.messaging_enabled,
        test_mode_enabled: messagingSettings.global.test_mode_enabled,
        test_phone: messagingSettings.global.test_phone ?? '',
        active_provider: messagingSettings.global.active_provider,
        default_provider: messagingSettings.global.default_provider,
        fallback_provider: messagingSettings.global.fallback_provider,
        send_delay_seconds: String(messagingSettings.global.send_delay_seconds),
        duplicate_cooldown_minutes: String(
            messagingSettings.global.duplicate_cooldown_minutes,
        ),
        hourly_limit: String(messagingSettings.global.hourly_limit),
        daily_limit: String(messagingSettings.global.daily_limit),
        max_auto_retries: String(messagingSettings.global.max_auto_retries),
        allow_browser_smoke_send:
            messagingSettings.global.allow_browser_smoke_send,
        allow_test_fixture_send:
            messagingSettings.global.allow_test_fixture_send,
        manual_e2e_partner_portal_origin_enabled:
            messagingSettings.global
                .manual_e2e_partner_portal_origin_enabled,
        manual_e2e_partner_portal_origin:
            messagingSettings.global.manual_e2e_partner_portal_origin ?? '',
    });
    const [nacSmsInputs, setNacSmsInputs] = useState(() =>
        nacSmsInputsFromSettings(messagingSettings),
    );
    const [nacSmsCredentialInputs, setNacSmsCredentialInputs] = useState({
        username: '',
        password: '',
    });
    const [evoWhatsappInputs, setEvoWhatsappInputs] = useState(() =>
        evoWhatsappInputsFromSettings(messagingSettings),
    );
    const [evoWhatsappCredentialInputs, setEvoWhatsappCredentialInputs] =
        useState({
            api_key: '',
            token: '',
        });
    const [mikroApiInputs, setMikroApiInputs] = useState(() =>
        mikroApiInputsFromSettings(messagingSettings),
    );
    const [mikroApiCredentialInputs, setMikroApiCredentialInputs] = useState({
        api_key: '',
        user_code: '',
        password: '',
        token: '',
    });
    const [messageTypeInputs, setMessageTypeInputs] = useState(() =>
        messageTypeInputsFromSettings(messagingSettings),
    );
    const [notificationInputs, setNotificationInputs] = useState({
        enabled: paymentProviderSettings.payment_notification.enabled,
        recipients:
            paymentProviderSettings.payment_notification.recipients_text ?? '',
    });
    const [companyRecipientInputs, setCompanyRecipientInputs] = useState({
        company_title:
            paymentProviderSettings.company_recipient.company_title ?? '',
        tax_office: paymentProviderSettings.company_recipient.tax_office ?? '',
        tax_number: paymentProviderSettings.company_recipient.tax_number ?? '',
        trade_registry_no:
            paymentProviderSettings.company_recipient.trade_registry_no ?? '',
        company_address:
            paymentProviderSettings.company_recipient.company_address ?? '',
        company_phone:
            paymentProviderSettings.company_recipient.company_phone ?? '',
        company_email:
            paymentProviderSettings.company_recipient.company_email ?? '',
        iban_try: paymentProviderSettings.company_recipient.iban_try ?? '',
        iban_usd: paymentProviderSettings.company_recipient.iban_usd ?? '',
    });
    const [outgoingMailInputs, setOutgoingMailInputs] = useState({
        enabled: mailTransportSettings.outgoing.enabled,
        host: mailTransportSettings.outgoing.host ?? '',
        port: mailTransportSettings.outgoing.port
            ? String(mailTransportSettings.outgoing.port)
            : '',
        encryption: mailTransportSettings.outgoing.encryption,
        username: '',
        password: '',
        from_address: mailTransportSettings.outgoing.from_address ?? '',
        from_name: mailTransportSettings.outgoing.from_name ?? '',
        test_recipient: '',
    });
    const [incomingMailInputs, setIncomingMailInputs] = useState({
        enabled: mailTransportSettings.incoming.enabled,
        protocol: mailTransportSettings.incoming.protocol,
        host: mailTransportSettings.incoming.host ?? '',
        port: mailTransportSettings.incoming.port
            ? String(mailTransportSettings.incoming.port)
            : '',
        encryption: mailTransportSettings.incoming.encryption,
        username: '',
        password: '',
        mailbox: mailTransportSettings.incoming.mailbox ?? 'INBOX',
    });
    const selectedTemplate =
        messageTemplates.templates.find(
            (template) => template.template_key === selectedTemplateKey,
        ) ?? messageTemplates.templates[0];
    const updateQueueFilter = (
        key:
            | 'date_from'
            | 'date_to'
            | 'q'
            | 'phone'
            | 'only_failed'
            | 'only_queued'
            | 'only_test'
            | 'only_business'
            | 'only_duplicate_blocked',
        value: string | boolean,
    ) => {
        setQueueFilters((current) => ({
            ...current,
            [key]: value,
        }));
    };
    const toggleQueueMultiFilter = (
        key:
            | 'status'
            | 'provider'
            | 'channel'
            | 'recipient_role'
            | 'message_type',
        value: string,
    ) => {
        setQueueFilters((current) => {
            const values = current[key].includes(value)
                ? current[key].filter((item) => item !== value)
                : [...current[key], value];

            return {
                ...current,
                [key]: values,
            };
        });
    };
    const emptyQueueFilters = (): QueueFilterState => ({
        status: [],
        provider: [],
        channel: [],
        recipient_role: [],
        message_type: [],
        date_from: '',
        date_to: '',
        q: '',
        phone: '',
        only_failed: false,
        only_queued: false,
        only_test: false,
        only_business: false,
        only_duplicate_blocked: false,
    });
    const clearQueueFilters = () => {
        const empty = emptyQueueFilters();
        setQueueFilters(empty);
        void loadDispatchQueue(empty);
    };
    const queueFilterOptionLabel = (
        key:
            | 'status'
            | 'provider'
            | 'channel'
            | 'recipient_role'
            | 'message_type',
        value: string,
    ): string => {
        const optionMap = {
            status: dispatchQueue.filters.statuses,
            provider: dispatchQueue.filters.providers,
            channel: dispatchQueue.filters.channels,
            recipient_role: dispatchQueue.filters.recipient_roles,
            message_type: dispatchQueue.filters.message_types,
        }[key];

        return (
            optionMap.find((option) => option.value === value)?.label ?? value
        );
    };
    const activeQueueFilterChips = [
        ...(
            [
                ['status', 'Durum'],
                ['provider', 'Sağlayıcı'],
                ['channel', 'Kanal'],
                ['recipient_role', 'Rol'],
                ['message_type', 'Mesaj'],
            ] as const
        ).flatMap(([key, label]) =>
            queueFilters[key].map((value) => ({
                key: `${key}:${value}`,
                label: `${label}: ${queueFilterOptionLabel(key, value)}`,
            })),
        ),
        ...(
            [
                ['date_from', 'Başlangıç'],
                ['date_to', 'Bitiş'],
                ['q', 'Arama'],
                ['phone', 'Telefon'],
            ] as const
        )
            .filter(([key]) => queueFilters[key].trim() !== '')
            .map(([key, label]) => ({
                key,
                label: `${label}: ${queueFilters[key]}`,
            })),
        ...(
            [
                ['only_failed', 'Sadece hatalılar'],
                ['only_queued', 'Sadece kuyrukta'],
                ['only_test', 'Sadece test'],
                ['only_business', 'Sadece iş akışı'],
                ['only_duplicate_blocked', 'Tekrar engellenenler'],
            ] as const
        )
            .filter(([key]) => queueFilters[key])
            .map(([key, label]) => ({ key, label })),
    ];
    const queueFilterParams = useCallback((filters: QueueFilterState) => {
        const params = new URLSearchParams();

        (
            [
                'status',
                'provider',
                'channel',
                'recipient_role',
                'message_type',
            ] as const
        ).forEach((key) => {
            filters[key].forEach((value) => params.append(`${key}[]`, value));
        });

        (['date_from', 'date_to', 'q', 'phone'] as const).forEach((key) => {
            const value = filters[key].trim();

            if (value !== '') {
                params.set(key, value);
            }
        });

        (
            [
                'only_failed',
                'only_queued',
                'only_test',
                'only_business',
                'only_duplicate_blocked',
            ] as const
        ).forEach((key) => {
            if (filters[key]) {
                params.set(key, '1');
            }
        });

        return params;
    }, []);

    const loadDispatchQueue = useCallback(
        async (
            filters: QueueFilterState = queueFilters,
            options: { silent?: boolean } = {},
        ) => {
            const silent = options.silent === true;
            queueRefreshAbortRef.current?.abort();
            const controller = new AbortController();
            queueRefreshAbortRef.current = controller;

            if (silent) {
                setQueueBackgroundRefreshing(true);
            } else {
                setQueueLoading(true);
            }

            setQueueRefreshError('');

            try {
                const params = queueFilterParams(filters);
                const suffix = params.toString() ? `?${params.toString()}` : '';
                const response = await fetch(
                    `/api/technical-service/message-dispatches${suffix}`,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );

                if (!response.ok) {
                    throw new Error(
                        await errorMessageFromResponse(
                            response,
                            'Kuyruk logları alınamadı.',
                        ),
                    );
                }

                const payload = await response.json();
                setDispatchQueue(payload.message_dispatch_queue);
                setQueueLastRefreshedAt(
                    new Intl.DateTimeFormat('tr-TR', {
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: false,
                    }).format(new Date()),
                );

                if (!silent) {
                    setMessage('Kuyruk logları güncellendi.');
                }
            } catch (error) {
                if (
                    error instanceof DOMException &&
                    error.name === 'AbortError'
                ) {
                    return;
                }

                const nextMessage =
                    error instanceof Error
                        ? error.message
                        : 'Kuyruk logları alınamadı.';
                setQueueRefreshError(nextMessage);

                if (!silent) {
                    setMessage(nextMessage);
                }
            } finally {
                if (queueRefreshAbortRef.current === controller) {
                    queueRefreshAbortRef.current = null;
                }

                if (silent) {
                    setQueueBackgroundRefreshing(false);
                } else {
                    setQueueLoading(false);
                }
            }
        },
        [queueFilterParams, queueFilters],
    );
    useEffect(() => {
        if (activeAdminSection !== 'queue' || !queueAutoRefreshEnabled) {
            return;
        }

        const intervalId = window.setInterval(() => {
            void loadDispatchQueue(queueFilters, { silent: true });
        }, 15000);

        return () => {
            window.clearInterval(intervalId);
        };
    }, [
        activeAdminSection,
        loadDispatchQueue,
        queueAutoRefreshEnabled,
        queueFilters,
    ]);
    const openDispatchDetail = async (dispatchId: number) => {
        setQueueDetailLoading(true);

        try {
            const response = await fetch(
                `/api/technical-service/message-dispatches/${dispatchId}`,
                { headers: { Accept: 'application/json' } },
            );

            if (!response.ok) {
                throw new Error(
                    await errorMessageFromResponse(
                        response,
                        'Dispatch detayı alınamadı.',
                    ),
                );
            }

            const payload = await response.json();
            setSelectedDispatchDetail(payload.dispatch);
        } catch (error) {
            setMessage(
                error instanceof Error
                    ? error.message
                    : 'Dispatch detayı alınamadı.',
            );
        } finally {
            setQueueDetailLoading(false);
        }
    };
    const templatePreviewStatus =
        !templatePreview && templateMessage.includes('önizlemesi alınamadı')
            ? 'error'
            : !templatePreview
              ? 'idle'
              : templatePreview.blockers.length > 0
                ? 'blocked'
                : templatePreview.warnings.length > 0
                  ? 'warning'
                  : 'ready';
    const templatePreviewStatusLabel = {
        idle: 'Önizleme alınmadı',
        ready: 'Önizleme hazır',
        blocked: 'Blok var',
        warning: 'Önizleme hazır, uyarı var',
        error: 'Önizleme oluşturulamadı',
    }[templatePreviewStatus];
    const templatePreviewBadgeClass = {
        idle: 'border-slate-200 bg-white text-slate-700',
        ready: 'border-emerald-100 bg-emerald-50 text-emerald-900',
        blocked: 'border-red-100 bg-red-50 text-red-900',
        warning: 'border-amber-100 bg-amber-50 text-amber-900',
        error: 'border-red-100 bg-red-50 text-red-900',
    }[templatePreviewStatus];
    const templateBlockMessages =
        templatePreviewStatus === 'idle'
            ? ['Henüz önizleme alınmadı.']
            : templatePreview?.blockers?.length
              ? templatePreview.blockers
              : ['Blok yok.'];
    const templateBlockPanelClass = templatePreview?.blockers?.length
        ? 'border-red-100 bg-red-50 text-red-900'
        : templatePreviewStatus === 'idle'
          ? 'border-slate-200 bg-white text-slate-700'
          : 'border-emerald-100 bg-emerald-50 text-emerald-900';
    const templateBlockListClass = templatePreview?.blockers?.length
        ? 'text-red-800'
        : templatePreviewStatus === 'idle'
          ? 'text-slate-600'
          : 'text-emerald-800';
    const templateWarningMessages = templatePreview?.warnings?.length
        ? templatePreview.warnings
        : ['Uyarı yok.'];
    const templateWarningPanelClass = templatePreview?.warnings?.length
        ? 'border-amber-100 bg-amber-50 text-amber-900'
        : 'border-slate-200 bg-white text-slate-700';
    const templateWarningListClass = templatePreview?.warnings?.length
        ? 'text-amber-800'
        : 'text-slate-600';
    const templateTestResultClass = templateTestSendResult
        ? templateTestSendResult.dispatch.status === 'sent'
            ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
            : templateTestSendResult.dispatch.duplicate
              ? 'border-amber-100 bg-amber-50 text-amber-900'
              : 'border-red-100 bg-red-50 text-red-900'
        : 'border-slate-200 bg-slate-50 text-slate-700';
    const smsPreviewMetadata = templatePreview?.sms
        ? templatePreview.sms
        : templatePreview && templateInputs.channel === 'sms'
          ? smsPreviewMetadataFromText(templatePreview.rendered_body)
          : null;
    const hiddenInternalReferenceKeys =
        templatePreview?.context?.customer_hidden_internal_references &&
        typeof templatePreview.context.customer_hidden_internal_references ===
            'object'
            ? Object.keys(
                  templatePreview.context
                      .customer_hidden_internal_references as Record<
                      string,
                      unknown
                  >,
              )
            : [];
    const templateVariableList = messageTemplates.variables.filter(
        (variable) => {
            const required = templateInputs.required_variables
                .split(',')
                .map((item) => item.trim())
                .filter(Boolean);
            const optional = templateInputs.optional_variables
                .split(',')
                .map((item) => item.trim())
                .filter(Boolean);

            return (
                required.includes(variable.key) ||
                optional.includes(variable.key)
            );
        },
    );

    const applyMessagingSettings = (nextSettings: MessagingSettings) => {
        setMessaging(nextSettings);
        setMessagingInputs({
            messaging_enabled: nextSettings.global.messaging_enabled,
            test_mode_enabled: nextSettings.global.test_mode_enabled,
            test_phone: nextSettings.global.test_phone ?? '',
            active_provider: nextSettings.global.active_provider,
            default_provider: nextSettings.global.default_provider,
            fallback_provider: nextSettings.global.fallback_provider,
            send_delay_seconds: String(nextSettings.global.send_delay_seconds),
            duplicate_cooldown_minutes: String(
                nextSettings.global.duplicate_cooldown_minutes,
            ),
            hourly_limit: String(nextSettings.global.hourly_limit),
            daily_limit: String(nextSettings.global.daily_limit),
            max_auto_retries: String(nextSettings.global.max_auto_retries),
            allow_browser_smoke_send:
                nextSettings.global.allow_browser_smoke_send,
            allow_test_fixture_send:
                nextSettings.global.allow_test_fixture_send,
            manual_e2e_partner_portal_origin_enabled:
                nextSettings.global
                    .manual_e2e_partner_portal_origin_enabled,
            manual_e2e_partner_portal_origin:
                nextSettings.global.manual_e2e_partner_portal_origin ?? '',
        });
        setNacSmsInputs(nacSmsInputsFromSettings(nextSettings));
        setEvoWhatsappInputs(evoWhatsappInputsFromSettings(nextSettings));
        setMikroApiInputs(mikroApiInputsFromSettings(nextSettings));
        setMessageTypeInputs(messageTypeInputsFromSettings(nextSettings));
    };

    const applyManualE2ELifecycle = (nextSettings: {
        global: Partial<MessagingSettings['global']>;
        manual_e2e: MessagingSettings['manual_e2e'];
        readiness: Partial<MessagingSettings['readiness']>;
    }) => {
        setMessaging((current) => ({
            ...current,
            global: { ...current.global, ...nextSettings.global },
            manual_e2e: nextSettings.manual_e2e,
            readiness: { ...current.readiness, ...nextSettings.readiness },
        }));
        setMessagingInputs((current) => ({
            ...current,
            messaging_enabled:
                nextSettings.global.messaging_enabled ??
                current.messaging_enabled,
            test_mode_enabled:
                nextSettings.global.test_mode_enabled ??
                current.test_mode_enabled,
        }));
    };

    const applyMessageTemplatePayload = (
        payload:
            | { templates?: MessageTemplateRecord[] }
            | MessageTemplateSettings,
    ) => {
        if ('templates' in payload && Array.isArray(payload.templates)) {
            setMessageTemplates((current) => ({
                ...current,
                templates: payload.templates ?? current.templates,
            }));
        }
    };

    const csvList = (value: string): string[] =>
        value
            .split(',')
            .map((item) => item.trim())
            .filter(Boolean);

    const templateRequestPayload = () => ({
        template_key: templateInputs.template_key,
        message_type: templateInputs.message_type,
        channel: templateInputs.channel,
        provider_key:
            templateInputs.provider_key === ''
                ? null
                : templateInputs.provider_key,
        title: templateInputs.title,
        body: templateInputs.body,
        active: templateInputs.active,
        required_variables: csvList(templateInputs.required_variables),
        optional_variables: csvList(templateInputs.optional_variables),
        sample_context: true,
        context: templateSampleContextFromInputs(templateSampleInputs),
    });

    const selectTemplate = (templateKey: string) => {
        const template = messageTemplates.templates.find(
            (item) => item.template_key === templateKey,
        );
        setSelectedTemplateKey(templateKey);
        setTemplateInputs(templateInputsFromTemplate(template));
        setTemplatePreview(null);
        setTemplateTestSendResult(null);
        setTemplateMessage('');
    };

    const previewTemplate = async () => {
        setTemplatePreviewing(true);
        setTemplateMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/message-templates/preview',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(templateRequestPayload()),
                },
            );

            if (!response.ok) {
                setTemplateMessage(
                    await errorMessageFromResponse(
                        response,
                        'Şablon önizlemesi alınamadı.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            setTemplatePreview(responsePayload.preview);
            setTemplateMessage(
                responsePayload.preview?.preview_ready
                    ? 'Önizleme hazır; provider gönderimi yapılmadı.'
                    : 'Önizleme bloklandı; değişkenleri kontrol et.',
            );
        } catch {
            setTemplateMessage('Şablon önizlemesi alınamadı.');
        } finally {
            setTemplatePreviewing(false);
        }
    };

    const saveTemplate = async () => {
        setTemplateSaving(true);
        setTemplateMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/message-templates',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(templateRequestPayload()),
                },
            );

            if (!response.ok) {
                setTemplateMessage(
                    await errorMessageFromResponse(
                        response,
                        'Şablon kaydedilemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMessageTemplatePayload(responsePayload.message_templates);
            setTemplatePreview(
                responsePayload.message_templates?.preview ?? null,
            );
            setTemplateMessage(
                responsePayload.message ??
                    'Şablon kaydedildi; provider gönderimi yapılmadı.',
            );
        } catch {
            setTemplateMessage('Şablon kaydedilemedi.');
        } finally {
            setTemplateSaving(false);
        }
    };

    const restoreTemplateDefault = async () => {
        setTemplateSaving(true);
        setTemplateMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/message-templates/restore-default',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        message_type: templateInputs.message_type,
                        channel: templateInputs.channel,
                        provider_key:
                            templateInputs.provider_key === ''
                                ? null
                                : templateInputs.provider_key,
                    }),
                },
            );

            if (!response.ok) {
                setTemplateMessage(
                    await errorMessageFromResponse(
                        response,
                        'Şablon varsayılana döndürülemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMessageTemplatePayload(responsePayload.message_templates);
            const template = responsePayload.message_templates?.template as
                | MessageTemplateRecord
                | undefined;
            setTemplateInputs(templateInputsFromTemplate(template));
            setTemplatePreview(null);
            setTemplateMessage(
                responsePayload.message ?? 'Şablon varsayılana döndürüldü.',
            );
        } catch {
            setTemplateMessage('Şablon varsayılana döndürülemedi.');
        } finally {
            setTemplateSaving(false);
        }
    };

    const sendTemplateTestMessage = async () => {
        if (templateInputs.channel === 'voice_script') {
            setTemplateMessage(
                'Voibot voice test gönderimi sözleşme kesinleşene kadar kapalı.',
            );

            return;
        }

        if (
            typeof window !== 'undefined' &&
            !window.confirm(
                templateInputs.channel === 'sms'
                    ? 'Seçili SMS şablonu no-send audit kaydı olarak oluşturulacak; NAC provider çağrısı yapılmayacak. Onaylıyor musun?'
                    : 'Seçili WhatsApp şablonu no-send audit kaydı olarak oluşturulsun mu? Provider çağrısı yapılmayacak.',
            )
        ) {
            return;
        }

        setTemplateTestSending(true);
        setTemplateMessage('');
        setTemplateTestSendResult(null);

        try {
            const response = await fetch(
                '/api/technical-service/message-templates/test-send',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        ...templateRequestPayload(),
                        confirmed: true,
                        real_sms_confirmed: templateInputs.channel === 'sms',
                    }),
                },
            );

            if (!response.ok) {
                setTemplateMessage(
                    await errorMessageFromResponse(
                        response,
                        'No-send template test kaydı oluşturulamadı.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            const result = responsePayload.test_send as TemplateTestSendResult;
            setTemplateTestSendResult(result);
            setTemplatePreview(responsePayload.test_send?.preview ?? null);
            setTemplateMessage(
                result.message ?? 'Template test işlemi tamamlandı.',
            );
        } catch {
            setTemplateMessage('No-send template test kaydı oluşturulamadı.');
        } finally {
            setTemplateTestSending(false);
        }
    };

    const sendNacProviderTestMessage = async () => {
        if (
            typeof window !== 'undefined' &&
            !window.confirm(
                'NAC altyapı testi no-send audit kaydı olarak oluşturulacak; gerçek SMS gönderilmeyecek. Onaylıyor musun?',
            )
        ) {
            return;
        }

        setProviderTestSending(true);
        setMessagingMessage('');
        setProviderTestSendResult(null);

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/nac-sms/test-send',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        real_sms_confirmed: true,
                    }),
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'NAC no-send test kaydı oluşturulamadı.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            const result =
                responsePayload.provider_test as TemplateTestSendResult;
            setProviderTestSendResult(result);
            setMessagingMessage(
                result.message ?? 'NAC altyapı test işlemi tamamlandı.',
            );
        } catch {
            setMessagingMessage('NAC no-send test kaydı oluşturulamadı.');
        } finally {
            setProviderTestSending(false);
        }
    };

    const saveMessagingSettings = async () => {
        setMessagingSaving(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings',
                {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        messaging_enabled: messagingInputs.messaging_enabled,
                        test_mode_enabled: messagingInputs.test_mode_enabled,
                        test_phone: messagingInputs.test_phone,
                        provider_key: messagingInputs.active_provider,
                        active_provider: messagingInputs.active_provider,
                        default_provider: messagingInputs.default_provider,
                        fallback_provider: messagingInputs.fallback_provider,
                        send_delay_seconds: Number(
                            messagingInputs.send_delay_seconds,
                        ),
                        duplicate_cooldown_minutes: Number(
                            messagingInputs.duplicate_cooldown_minutes,
                        ),
                        hourly_limit: Number(messagingInputs.hourly_limit),
                        daily_limit: Number(messagingInputs.daily_limit),
                        max_auto_retries: Number(
                            messagingInputs.max_auto_retries,
                        ),
                        allow_browser_smoke_send:
                            messagingInputs.allow_browser_smoke_send,
                        allow_test_fixture_send:
                            messagingInputs.allow_test_fixture_send,
                        manual_e2e_partner_portal_origin_enabled:
                            messagingInputs.manual_e2e_partner_portal_origin_enabled,
                        manual_e2e_partner_portal_origin:
                            messagingInputs.manual_e2e_partner_portal_origin,
                        shared_test_phone: messagingInputs.test_phone,
                        nac_sms: {
                            enabled: nacSmsInputs.enabled,
                            profile: nacSmsInputs.profile,
                            scheme: nacSmsInputs.scheme,
                            host: nacSmsInputs.host,
                            port: Number(nacSmsInputs.port),
                            path: nacSmsInputs.path,
                            request_shape: nacSmsInputs.request_shape,
                            sender: nacSmsInputs.sender,
                            title: nacSmsInputs.title,
                            gateway_uuid: nacSmsInputs.gateway_uuid,
                            encoding: Number(nacSmsInputs.encoding),
                            commercial: nacSmsInputs.commercial,
                            skip_ahs_query: nacSmsInputs.skip_ahs_query,
                            recipient_type: Number(nacSmsInputs.recipient_type),
                            validity: Number(nacSmsInputs.validity),
                            report_push_url: nacSmsInputs.report_push_url,
                            use_shared_test_phone:
                                nacSmsInputs.use_shared_test_phone,
                            test_phone: nacSmsInputs.test_phone,
                            real_send_allowed: nacSmsInputs.real_send_allowed,
                        },
                        evo_whatsapp: {
                            direct_api_enabled:
                                evoWhatsappInputs.direct_api_enabled,
                            direct_api_base_url:
                                evoWhatsappInputs.direct_api_base_url,
                            direct_api_instance_name:
                                evoWhatsappInputs.direct_api_instance_name,
                            delay: Number(evoWhatsappInputs.delay),
                            link_preview: evoWhatsappInputs.link_preview,
                        },
                        mikro_api: {
                            enabled: mikroApiInputs.enabled,
                            base_url: mikroApiInputs.base_url,
                            api_version: mikroApiInputs.api_version,
                            application_code: mikroApiInputs.application_code,
                            application_name: mikroApiInputs.application_name,
                            company_code: mikroApiInputs.company_code,
                            branch_code: mikroApiInputs.branch_code,
                            workstation_code: mikroApiInputs.workstation_code,
                            fiscal_year: mikroApiInputs.fiscal_year,
                            timeout_seconds: Number(
                                mikroApiInputs.timeout_seconds,
                            ),
                            server_timezone: mikroApiInputs.server_timezone,
                            license_status: mikroApiInputs.license_status,
                            app_customer_license_status:
                                mikroApiInputs.app_customer_license_status,
                            read_sync_enabled: mikroApiInputs.read_sync_enabled,
                            write_enabled: mikroApiInputs.write_enabled,
                            write_approval_required:
                                mikroApiInputs.write_approval_required,
                            operation_catalog_status:
                                mikroApiInputs.operation_catalog_status,
                            operation_controls:
                                mikroApiInputs.operation_controls,
                        },
                        message_types: messageTypeInputs,
                    }),
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Mesajlaşma sağlayıcı ayarları kaydedilemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMessagingSettings(responsePayload.messaging_settings);
            setMessagingMessage(
                'Mesajlaşma sağlayıcı ayarları kaydedildi. Gerçek mesaj gönderilmedi.',
            );
        } catch {
            setMessagingMessage(
                'Mesajlaşma sağlayıcı ayarları kaydedilemedi. Bağlantı durumunu kontrol edin.',
            );
        } finally {
            setMessagingSaving(false);
        }
    };

    const checkManualE2EReadiness = async (): Promise<ManualE2EReadiness | null> => {
        setManualE2EReadinessChecking(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/manual-e2e/readiness',
                {
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Manual E2E readiness kontrolü tamamlanamadı.',
                    ),
                );

                return null;
            }

            const payload = await response.json();
            const readiness =
                payload.manual_e2e_readiness as ManualE2EReadiness;
            setManualE2EReadiness(readiness);
            setMessagingMessage(
                readiness.eligible
                    ? 'Manual E2E readiness kontrolleri tamamlandı.'
                    : 'Manual E2E açılamaz; readiness bloklarını giderin.',
            );

            return readiness;
        } catch {
            setMessagingMessage(
                'Manual E2E readiness kontrolü tamamlanamadı.',
            );

            return null;
        } finally {
            setManualE2EReadinessChecking(false);
        }
    };

    const prepareManualE2E = async () => {
        if (!manualE2EReadiness?.eligible) {
            setMessagingMessage(
                'Manual E2E hazırlanmadan önce readiness kontrolü başarılı olmalı.',
            );

            return;
        }

        if (manualE2ELifecycleBusyRef.current) {
            return;
        }

        manualE2ELifecycleBusyRef.current = true;
        setManualE2ELifecycleBusy(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/manual-e2e/enable',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        operation: 'prepare',
                    }),
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Manual E2E güvenli hazırlık durumuna alınamadı.',
                    ),
                );

                return;
            }

            const payload = await response.json();
            applyManualE2ELifecycle(payload.messaging_settings);
            setManualE2EEnableConfirmationOpen(false);
            setManualE2EReadiness(null);
            setMessagingMessage(
                payload.message ?? 'Manual E2E run context hazırlandı.',
            );
        } catch {
            setMessagingMessage(
                'Manual E2E güvenli hazırlık durumuna alınamadı; otomatik tekrar yapılmadı.',
            );
        } finally {
            manualE2ELifecycleBusyRef.current = false;
            setManualE2ELifecycleBusy(false);
        }
    };

    const changeManualE2ESendWindow = async (
        operation: 'open_send_window' | 'close_send_window',
        dispatchId: number,
    ) => {
        if (manualE2ELifecycleBusyRef.current) {
            return;
        }

        const runId = messaging.manual_e2e.active_run_id;

        if (!runId) {
            setMessagingMessage('Aktif Manual E2E run bulunamadı.');

            return;
        }

        manualE2ELifecycleBusyRef.current = true;
        setManualE2ELifecycleBusy(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/manual-e2e/enable',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        operation,
                        active_run_id: runId,
                        dispatch_id: dispatchId,
                    }),
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Exact dispatch gönderim penceresi değiştirilemedi.',
                    ),
                );

                return;
            }

            const payload = await response.json();
            applyManualE2ELifecycle(payload.messaging_settings);
            setMessagingMessage(payload.message);
        } catch {
            setMessagingMessage(
                'Exact dispatch gönderim penceresi değiştirilemedi; otomatik tekrar yapılmadı.',
            );
        } finally {
            manualE2ELifecycleBusyRef.current = false;
            setManualE2ELifecycleBusy(false);
        }
    };

    const freezeManualE2E = async () => {
        if (
            typeof window !== 'undefined' &&
            !window.confirm('Manual E2E ve gerçek gönderimler dondurulsun mu?')
        ) {
            return;
        }

        if (manualE2ELifecycleBusyRef.current) {
            return;
        }

        manualE2ELifecycleBusyRef.current = true;
        setManualE2ELifecycleBusy(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/manual-e2e/freeze',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ operation: 'freeze' }),
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Gönderimler dondurulamadı.',
                    ),
                );

                return;
            }

            const payload = await response.json();
            applyManualE2ELifecycle(payload.messaging_settings);
            setManualE2EEnableConfirmationOpen(false);
            setManualE2EReadiness(null);
            setMessagingMessage(payload.message ?? 'Gönderimler donduruldu.');
        } catch {
            setMessagingMessage('Gönderimler dondurulamadı.');
        } finally {
            manualE2ELifecycleBusyRef.current = false;
            setManualE2ELifecycleBusy(false);
        }
    };

    const resetMessagingSettings = async () => {
        if (
            typeof window !== 'undefined' &&
            !window.confirm(
                'Mesajlaşma sağlayıcı ayarları güvenli varsayılanlara dönsün mü?',
            )
        ) {
            return;
        }

        setMessagingSaving(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/reset',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Mesajlaşma sağlayıcı ayarları sıfırlanamadı.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMessagingSettings(responsePayload.messaging_settings);
            setMessagingMessage(
                responsePayload.message ??
                    'Mesajlaşma sağlayıcı ayarları güvenli varsayılanlara döndürüldü.',
            );
        } catch {
            setMessagingMessage('Mesajlaşma sağlayıcı ayarları sıfırlanamadı.');
        } finally {
            setMessagingSaving(false);
        }
    };

    const validateMessagingPhone = async () => {
        setMessagingPhoneChecking(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/validate-phone',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        test_phone: messagingInputs.test_phone,
                    }),
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Test telefonu geçerli değil.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            setMessagingInputs((current) => ({
                ...current,
                test_phone:
                    responsePayload.phone?.normalized ?? current.test_phone,
            }));
            setMessagingMessage(
                responsePayload.message ?? 'Test telefon numarası geçerli.',
            );
        } catch {
            setMessagingMessage('Test telefonu doğrulanamadı.');
        } finally {
            setMessagingPhoneChecking(false);
        }
    };

    const saveNacSmsCredentials = async () => {
        setIntegrationCredentialSaving(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/nac-sms/credentials',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(nacSmsCredentialInputs),
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'NAC SMS bilgileri kaydedilemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMessagingSettings(responsePayload.messaging_settings);
            setNacSmsCredentialInputs({ username: '', password: '' });
            setMessagingMessage(
                responsePayload.message ??
                    'NAC SMS bilgileri encrypted olarak kaydedildi.',
            );
        } catch {
            setMessagingMessage(
                'NAC SMS bilgileri kaydedilemedi. Bağlantı durumunu kontrol edin.',
            );
        } finally {
            setIntegrationCredentialSaving(false);
        }
    };

    const clearNacSmsCredentials = async () => {
        if (
            typeof window !== 'undefined' &&
            !window.confirm('NAC SMS credential bilgileri temizlensin mi?')
        ) {
            return;
        }

        setIntegrationCredentialSaving(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/nac-sms/credentials/clear',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'NAC SMS bilgileri temizlenemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMessagingSettings(responsePayload.messaging_settings);
            setNacSmsCredentialInputs({ username: '', password: '' });
            setMessagingMessage(
                responsePayload.message ??
                    'NAC SMS credential bilgileri temizlendi.',
            );
        } catch {
            setMessagingMessage('NAC SMS bilgileri temizlenemedi.');
        } finally {
            setIntegrationCredentialSaving(false);
        }
    };

    const saveEvoWhatsappCredentials = async () => {
        setIntegrationCredentialSaving(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/evo-whatsapp/credentials',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(evoWhatsappCredentialInputs),
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Evo Direct API bilgileri kaydedilemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMessagingSettings(responsePayload.messaging_settings);
            setEvoWhatsappCredentialInputs({ api_key: '', token: '' });
            setMessagingMessage(
                responsePayload.message ??
                    'Evo Direct API bilgileri encrypted olarak kaydedildi.',
            );
        } catch {
            setMessagingMessage(
                'Evo Direct API bilgileri kaydedilemedi. Bağlantı durumunu kontrol edin.',
            );
        } finally {
            setIntegrationCredentialSaving(false);
        }
    };

    const clearEvoWhatsappCredentials = async () => {
        if (
            typeof window !== 'undefined' &&
            !window.confirm(
                'Evo Direct API credential bilgileri temizlensin mi?',
            )
        ) {
            return;
        }

        setIntegrationCredentialSaving(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/evo-whatsapp/credentials/clear',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Evo Direct API bilgileri temizlenemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMessagingSettings(responsePayload.messaging_settings);
            setEvoWhatsappCredentialInputs({ api_key: '', token: '' });
            setMessagingMessage(
                responsePayload.message ??
                    'Evo Direct API credential bilgileri temizlendi.',
            );
        } catch {
            setMessagingMessage('Evo Direct API bilgileri temizlenemedi.');
        } finally {
            setIntegrationCredentialSaving(false);
        }
    };

    const saveMikroApiCredentials = async () => {
        setIntegrationCredentialSaving(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/mikro-api/credentials',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(mikroApiCredentialInputs),
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Mikro API bilgileri kaydedilemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMessagingSettings(responsePayload.messaging_settings);
            setMikroApiCredentialInputs({
                api_key: '',
                user_code: '',
                password: '',
                token: '',
            });
            setMessagingMessage(
                responsePayload.message ??
                    'Mikro API bilgileri encrypted olarak kaydedildi.',
            );
        } catch {
            setMessagingMessage(
                'Mikro API bilgileri kaydedilemedi. Bağlantı durumunu kontrol edin.',
            );
        } finally {
            setIntegrationCredentialSaving(false);
        }
    };

    const clearMikroApiCredentials = async () => {
        if (
            typeof window !== 'undefined' &&
            !window.confirm('Mikro API credential bilgileri temizlensin mi?')
        ) {
            return;
        }

        setIntegrationCredentialSaving(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/mikro-api/credentials/clear',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                setMessagingMessage(
                    await errorMessageFromResponse(
                        response,
                        'Mikro API bilgileri temizlenemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMessagingSettings(responsePayload.messaging_settings);
            setMikroApiCredentialInputs({
                api_key: '',
                user_code: '',
                password: '',
                token: '',
            });
            setMessagingMessage(
                responsePayload.message ??
                    'Mikro API credential bilgileri temizlendi.',
            );
        } catch {
            setMessagingMessage('Mikro API bilgileri temizlenemedi.');
        } finally {
            setIntegrationCredentialSaving(false);
        }
    };

    const testMikroApiConnection = async () => {
        setMikroConnectionTesting(true);
        setMessagingMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/messaging-settings/mikro-api/connection-test',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );
            const responsePayload = await response.json();

            if (!response.ok) {
                setMessagingMessage(
                    responsePayload.message ??
                        'Mikro bağlantı testi güvenli biçimde tamamlanamadı.',
                );

                return;
            }

            setMessagingMessage(
                responsePayload.message ?? 'Mikro HealthCheck başarılı.',
            );
        } catch {
            setMessagingMessage(
                'Mikro bağlantı testi güvenli biçimde tamamlanamadı.',
            );
        } finally {
            setMikroConnectionTesting(false);
        }
    };

    const resetMikroOperationCircuit = async (operationKey: string) => {
        setMikroCircuitResetting(operationKey);
        setMessagingMessage('');

        try {
            const response = await fetch(
                `/api/technical-service/messaging-settings/mikro-api/operations/${encodeURIComponent(operationKey)}/circuit/reset`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );
            const payload = await response.json();
            setMessagingMessage(
                payload.message ??
                    (response.ok
                        ? 'Mikro circuit sıfırlandı.'
                        : 'Mikro circuit sıfırlanamadı.'),
            );
        } catch {
            setMessagingMessage('Mikro circuit sıfırlanamadı.');
        } finally {
            setMikroCircuitResetting(null);
        }
    };

    const updateSettings = async (
        payload: {
            pre_form_payment_for_mount_excluded_enabled?: boolean;
            ops_detail_visibility?: Partial<typeof opsDetailVisibility>;
        },
        rollback: () => void,
        successMessage: string,
    ) => {
        setSaving(true);
        setMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/qr-flow-settings',
                {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                },
            );

            if (!response.ok) {
                setMessage(
                    await errorMessageFromResponse(
                        response,
                        'Ayar kaydedilemedi. Yetki veya oturum durumunu kontrol edin.',
                    ),
                );
                rollback();

                return;
            }

            const responsePayload = await response.json();
            setPreFormPaymentEnabled(
                Boolean(
                    responsePayload.settings
                        ?.pre_form_payment_for_mount_excluded_enabled,
                ),
            );
            setOpsDetailVisibility({
                show_mount_excluded_approval_block: Boolean(
                    responsePayload.settings?.ops_detail_visibility
                        ?.show_mount_excluded_approval_block,
                ),
                show_payment_mount_control_block: Boolean(
                    responsePayload.settings?.ops_detail_visibility
                        ?.show_payment_mount_control_block,
                ),
                show_address_control_block: Boolean(
                    responsePayload.settings?.ops_detail_visibility
                        ?.show_address_control_block,
                ),
            });
            setMessage(successMessage);
        } catch {
            setMessage('Ayar kaydedilemedi. Bağlantı durumunu kontrol edin.');
            rollback();
        } finally {
            setSaving(false);
        }
    };

    const updatePaymentSettings = async (
        payload: {
            real_provider_enabled?: boolean;
            provider_mode?: 'sandbox' | 'live';
            payment_notification_enabled?: boolean;
            payment_notification_recipients?: string;
            company_recipient?: typeof companyRecipientInputs;
        },
        rollback: () => void,
        successMessage: string,
    ) => {
        setPaymentSaving(true);
        setPaymentMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/payment-provider-settings',
                {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                },
            );

            if (!response.ok) {
                setPaymentMessage(
                    await errorMessageFromResponse(
                        response,
                        'Ödeme sağlayıcı ayarı kaydedilemedi.',
                    ),
                );
                rollback();

                return;
            }

            const responsePayload = await response.json();
            setPaymentSettings(responsePayload.settings);
            setPaymentMessage(successMessage);
        } catch {
            setPaymentMessage(
                'Ödeme sağlayıcı ayarı kaydedilemedi. Bağlantı durumunu kontrol edin.',
            );
            rollback();
        } finally {
            setPaymentSaving(false);
        }
    };

    const updateRealPaymentToggle = async (enabled: boolean) => {
        const previous = paymentSettings;

        setPaymentSettings({
            ...paymentSettings,
            real_provider_enabled: enabled,
        });
        await updatePaymentSettings(
            { real_provider_enabled: enabled },
            () => setPaymentSettings(previous),
            enabled
                ? 'Gerçek ödeme ayarı etkinleştirildi.'
                : 'Gerçek ödeme kapatıldı; fake/local ödeme aktif.',
        );
    };

    const updateProviderMode = async (mode: 'sandbox' | 'live') => {
        const previous = paymentSettings;

        setPaymentSettings({
            ...paymentSettings,
            provider_mode: mode,
            selected_provider_mode_label:
                mode === 'live' ? 'Iyzico Live' : 'Iyzico Sandbox',
        });
        await updatePaymentSettings(
            { provider_mode: mode },
            () => setPaymentSettings(previous),
            mode === 'live'
                ? 'Iyzico live modu kaydedildi.'
                : 'Iyzico sandbox modu kaydedildi.',
        );
    };

    const resetToSafePaymentMode = async () => {
        const previous = paymentSettings;

        setPaymentSettings({
            ...paymentSettings,
            real_provider_enabled: false,
            provider: 'fake',
            configured_provider: 'fake',
            provider_mode: 'sandbox',
            selected_provider_mode_label: 'Iyzico Sandbox',
            effective_mode: 'fake',
            effective_mode_label: 'Fake / Yerel',
            fake_active: true,
        });
        await updatePaymentSettings(
            { real_provider_enabled: false, provider_mode: 'sandbox' },
            () => setPaymentSettings(previous),
            'Fake/Yerel moda dönüldü. Iyzico hazırlık modu sandbox olarak kaydedildi.',
        );
    };

    const saveCredentials = async () => {
        setCredentialSaving(true);
        setPaymentMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/payment-provider-settings/credentials',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        mode: paymentSettings.provider_mode,
                        api_key: credentialInputs.api_key,
                        secret_key: credentialInputs.secret_key,
                    }),
                },
            );

            if (!response.ok) {
                setPaymentMessage(
                    await errorMessageFromResponse(
                        response,
                        'API bilgileri kaydedilemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            setPaymentSettings(responsePayload.settings);
            setCredentialInputs({ api_key: '', secret_key: '' });
            setPaymentMessage(
                'API bilgileri encrypted olarak kaydedildi. Tam değerler tekrar gösterilmez.',
            );
        } catch {
            setPaymentMessage(
                'API bilgileri kaydedilemedi. Bağlantı durumunu kontrol edin.',
            );
        } finally {
            setCredentialSaving(false);
        }
    };

    const clearCredentials = async () => {
        if (
            typeof window !== 'undefined' &&
            !window.confirm('Seçili modun API bilgileri silinsin mi?')
        ) {
            return;
        }

        setCredentialSaving(true);
        setPaymentMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/payment-provider-settings/credentials/clear',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        mode: paymentSettings.provider_mode,
                    }),
                },
            );

            if (!response.ok) {
                setPaymentMessage(
                    await errorMessageFromResponse(
                        response,
                        'API bilgileri temizlenemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            setPaymentSettings(responsePayload.settings);
            setCredentialInputs({ api_key: '', secret_key: '' });
            setPaymentMessage('API bilgileri temizlendi.');
        } catch {
            setPaymentMessage(
                'API bilgileri temizlenemedi. Bağlantı durumunu kontrol edin.',
            );
        } finally {
            setCredentialSaving(false);
        }
    };

    const runPaymentHealthCheck = async () => {
        setHealthChecking(true);
        setPaymentMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/payment-provider-settings/health-check',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                setPaymentMessage(
                    await errorMessageFromResponse(
                        response,
                        'Bağlantı durumu okunamadı.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            setPaymentSettings(responsePayload.settings);
            setPaymentMessage(
                responsePayload.health_check?.message ??
                    'Bağlantı durumu okundu.',
            );
        } catch {
            setPaymentMessage('Bağlantı durumu okunamadı.');
        } finally {
            setHealthChecking(false);
        }
    };

    const savePaymentNotificationSettings = async () => {
        const previous = paymentSettings;

        await updatePaymentSettings(
            {
                payment_notification_enabled: notificationInputs.enabled,
                payment_notification_recipients: notificationInputs.recipients,
            },
            () => setPaymentSettings(previous),
            'Ödeme bildirimi mail ayarı kaydedildi.',
        );
    };

    const saveCompanyRecipientSettings = async () => {
        const previous = paymentSettings;

        await updatePaymentSettings(
            {
                company_recipient: companyRecipientInputs,
            },
            () => setPaymentSettings(previous),
            'Firma tahsilat bilgileri kaydedildi.',
        );
    };

    const applyMailResponse = (payload: unknown) => {
        const nextSettings = (
            payload as { mail_transport_settings?: MailTransportSettings }
        ).mail_transport_settings;

        if (!nextSettings) {
            return;
        }

        setMailSettings(nextSettings);
        setPaymentSettings((current) => ({
            ...current,
            payment_notification: {
                ...current.payment_notification,
                smtp_ready: nextSettings.payment_notification_ready,
                ready:
                    current.payment_notification.enabled &&
                    current.payment_notification.recipients.length > 0 &&
                    nextSettings.payment_notification_ready,
                status_label: !current.payment_notification.enabled
                    ? 'Kapalı'
                    : !nextSettings.payment_notification_ready
                      ? 'SMTP eksik'
                      : current.payment_notification.recipients.length > 0
                        ? 'Aktif'
                        : 'Alıcı bekliyor',
            },
        }));
        setOutgoingMailInputs((current) => ({
            ...current,
            enabled: nextSettings.outgoing.enabled,
            host: nextSettings.outgoing.host ?? '',
            port: nextSettings.outgoing.port
                ? String(nextSettings.outgoing.port)
                : '',
            encryption: nextSettings.outgoing.encryption,
            username: '',
            password: '',
            from_address: nextSettings.outgoing.from_address ?? '',
            from_name: nextSettings.outgoing.from_name ?? '',
        }));
        setIncomingMailInputs((current) => ({
            ...current,
            enabled: nextSettings.incoming.enabled,
            protocol: nextSettings.incoming.protocol,
            host: nextSettings.incoming.host ?? '',
            port: nextSettings.incoming.port
                ? String(nextSettings.incoming.port)
                : '',
            encryption: nextSettings.incoming.encryption,
            username: '',
            password: '',
            mailbox: nextSettings.incoming.mailbox ?? 'INBOX',
        }));
    };

    const postMailSettings = async (
        path: string,
        payload: Record<string, unknown>,
        successMessage: string,
    ) => {
        setMailSaving(true);
        setMailMessage('');

        try {
            const response = await fetch(path, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                setMailMessage(
                    await errorMessageFromResponse(
                        response,
                        'Mail ayarı kaydedilemedi.',
                    ),
                );

                return;
            }

            const responsePayload = await response.json();
            applyMailResponse(responsePayload);
            setMailMessage(successMessage);
        } catch {
            setMailMessage(
                'Mail ayarı kaydedilemedi. Bağlantı durumunu kontrol edin.',
            );
        } finally {
            setMailSaving(false);
        }
    };

    const saveOutgoingMailSettings = async () =>
        postMailSettings(
            '/api/technical-service/mail-transport-settings/outgoing',
            {
                enabled: outgoingMailInputs.enabled,
                host: outgoingMailInputs.host,
                port: outgoingMailInputs.port,
                encryption: outgoingMailInputs.encryption,
                username: outgoingMailInputs.username,
                password: outgoingMailInputs.password,
                from_address: outgoingMailInputs.from_address,
                from_name: outgoingMailInputs.from_name,
            },
            'SMTP ayarları encrypted olarak kaydedildi. Tam şifre tekrar gösterilmez.',
        );

    const clearOutgoingMailSettings = async () => {
        if (
            typeof window !== 'undefined' &&
            !window.confirm('SMTP ayarları temizlensin mi?')
        ) {
            return;
        }

        await postMailSettings(
            '/api/technical-service/mail-transport-settings/outgoing/clear',
            {},
            'SMTP ayarları temizlendi.',
        );
    };

    const sendOutgoingTestMail = async () => {
        setMailTesting(true);
        setMailMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/mail-transport-settings/outgoing/test',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        recipient: outgoingMailInputs.test_recipient,
                    }),
                },
            );
            const responsePayload = await response.json().catch(() => ({}));

            applyMailResponse(responsePayload);

            if (!response.ok) {
                setMailMessage(
                    String(
                        responsePayload.message || 'Test mail gönderilemedi.',
                    ),
                );

                return;
            }

            setMailMessage(responsePayload.message ?? 'Test mail gönderildi.');
        } catch {
            setMailMessage(
                'Test mail gönderilemedi. Bağlantı durumunu kontrol edin.',
            );
        } finally {
            setMailTesting(false);
        }
    };

    const saveIncomingMailSettings = async () =>
        postMailSettings(
            '/api/technical-service/mail-transport-settings/incoming',
            {
                enabled: incomingMailInputs.enabled,
                protocol: incomingMailInputs.protocol,
                host: incomingMailInputs.host,
                port: incomingMailInputs.port,
                encryption: incomingMailInputs.encryption,
                username: incomingMailInputs.username,
                password: incomingMailInputs.password,
                mailbox: incomingMailInputs.mailbox,
            },
            'IMAP/POP3 ayarları encrypted olarak kaydedildi. Tam şifre tekrar gösterilmez.',
        );

    const clearIncomingMailSettings = async () => {
        if (
            typeof window !== 'undefined' &&
            !window.confirm('IMAP/POP3 ayarları temizlensin mi?')
        ) {
            return;
        }

        await postMailSettings(
            '/api/technical-service/mail-transport-settings/incoming/clear',
            {},
            'IMAP/POP3 ayarları temizlendi.',
        );
    };

    const testIncomingMailSettings = async () => {
        setMailTesting(true);
        setMailMessage('');

        try {
            const response = await fetch(
                '/api/technical-service/mail-transport-settings/incoming/test',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );
            const responsePayload = await response.json().catch(() => ({}));

            applyMailResponse(responsePayload);
            setMailMessage(
                responsePayload.message ??
                    (response.ok
                        ? 'Gelen kutu bağlantı testi tamamlandı.'
                        : 'Gelen kutu bağlantı testi başarısız.'),
            );
        } catch {
            setMailMessage('Gelen kutu bağlantı testi başarısız.');
        } finally {
            setMailTesting(false);
        }
    };

    const updatePreFormPayment = async (enabled: boolean) =>
        updateSettings(
            { pre_form_payment_for_mount_excluded_enabled: enabled },
            () => setPreFormPaymentEnabled(!enabled),
            'QR ödeme yönlendirme ayarı kaydedildi.',
        );

    const updateOpsDetailVisibility = async (
        key: keyof typeof opsDetailVisibility,
        enabled: boolean,
    ) => {
        const previous = { ...opsDetailVisibility };
        const next = { ...opsDetailVisibility, [key]: enabled };

        setOpsDetailVisibility(next);
        await updateSettings(
            { ops_detail_visibility: next },
            () => setOpsDetailVisibility(previous),
            'OPS detay görünürlük ayarı kaydedildi.',
        );
    };

    const opsDetailToggles: Array<{
        key: keyof typeof opsDetailVisibility;
        label: string;
        description: string;
    }> = [
        {
            key: 'show_mount_excluded_approval_block',
            label: 'Montaj hariç / çoklu ürün onayı bloğunu göster',
            description:
                'Kapalıyken eski onay bloğu OPS detayında görünmez; gerekli ödeme bilgisi atama alanında sade kart olarak kalır.',
        },
        {
            key: 'show_payment_mount_control_block',
            label: 'Ödeme / montaj kontrol bloğunu göster',
            description:
                'Kapalıyken büyük ödeme kontrol bloğu gizlenir; ödeme linki aksiyonu sade modal üzerinden yürür.',
        },
        {
            key: 'show_address_control_block',
            label: 'Adres kontrol bloğunu göster',
            description:
                'Kapalıyken büyük adres kontrol bloğu gizlenir. Müşteri adresi normal müşteri bilgi alanında kalır.',
        },
    ];

    const providerStatusCards = [
        {
            label: 'Adaptör',
            value: paymentSettings.provider_transport_label,
            ok: paymentSettings.provider_transport === 'direct_laravel',
        },
        {
            label: 'Seçili mod',
            value: paymentSettings.selected_provider_mode_label,
            ok:
                paymentSettings.provider_mode === 'sandbox' ||
                paymentSettings.live_send_approved,
        },
        {
            label: 'API bilgileri',
            value: paymentSettings.credentials.ready
                ? 'API bilgileri tanımlı'
                : 'API bilgileri tanımlı değil',
            ok: paymentSettings.credentials.ready,
        },
        {
            label: 'Fake ödeme',
            value: paymentSettings.fake_active ? 'Aktif' : 'Kapalı',
            ok: paymentSettings.fake_active,
        },
        {
            label: 'Hazırlık',
            value: paymentSettings.health_status.label,
            ok: paymentSettings.health_status.status === 'ready',
        },
        {
            label: 'Gerçek gönderim',
            value: paymentSettings.gateway.provider_send_ready
                ? 'Hazır'
                : paymentSettings.gateway.provider_send_enabled
                  ? 'İzin var, hazır değil'
                  : 'Kapalı',
            ok: paymentSettings.gateway.provider_send_ready,
        },
        {
            label: 'IP whitelist',
            value: paymentSettings.ip_whitelist.label,
            ok:
                paymentSettings.provider_mode !== 'live' ||
                paymentSettings.ip_whitelist.ready,
        },
        {
            label: 'Back URL',
            value: paymentSettings.back_url.label,
            ok:
                paymentSettings.provider_mode !== 'live' ||
                paymentSettings.back_url.ready,
        },
        {
            label: 'Firma adresi',
            value: paymentSettings.company_recipient.status_label,
            ok: paymentSettings.company_recipient.ready,
        },
    ];
    const providerModeDisabled = paymentSaving;
    const paymentNextRequiredAction =
        paymentSettings.readiness.next_required_action;
    const iyzicoSandboxBaseUrl = paymentSettings.iyzico_urls.sandbox_base_url;
    const iyzicoLiveBaseUrl = paymentSettings.iyzico_urls.live_base_url;
    const paymentBackUrlCallbackRouteExists =
        paymentSettings.back_url.callback_route_exists;
    const paymentNotificationHelperText =
        paymentSettings.payment_notification.helper_text;
    const companyRecipientFields: Array<{
        key: keyof typeof companyRecipientInputs;
        label: string;
        type?: 'text' | 'email' | 'textarea';
        placeholder?: string;
    }> = [
        { key: 'company_title', label: 'Ünvan' },
        { key: 'tax_office', label: 'Vergi dairesi' },
        { key: 'tax_number', label: 'VKN' },
        { key: 'trade_registry_no', label: 'Ticaret sicil no' },
        {
            key: 'company_address',
            label: 'Firma tahsilat adresi',
            type: 'textarea',
            placeholder: 'Ödeme alan EMAKS Prime firma adresi',
        },
        { key: 'company_phone', label: 'Firma telefonu' },
        { key: 'company_email', label: 'Firma e-posta', type: 'email' },
        { key: 'iban_try', label: 'IBAN TRY' },
        { key: 'iban_usd', label: 'IBAN USD' },
    ];
    const updateCompanyRecipientInput = (
        key: keyof typeof companyRecipientInputs,
        value: string,
    ) =>
        setCompanyRecipientInputs((current) => ({
            ...current,
            [key]: value,
        }));

    return (
        <>
            <Head title="Teknik Servis Admin" />

            <div className="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 md:px-6 lg:px-12">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <Heading
                        title="Teknik Servis Admin"
                        description="Teknik servis modül yetkileri, yönetim ekranları ve güvenli aksiyon ayrımları."
                    />
                    <div className="flex flex-wrap gap-2">
                        <TechnicalServicePageLinks />
                    </div>
                </div>

                <nav
                    className="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm"
                    aria-label="Teknik servis admin bölümleri"
                >
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                        {adminSectionTabs.map((section) => (
                            <button
                                key={section.key}
                                type="button"
                                onClick={() =>
                                    setActiveAdminSection(section.key)
                                }
                                className={[
                                    'rounded-xl border px-3 py-3 text-left text-sm transition',
                                    activeAdminSection === section.key
                                        ? 'border-slate-900 bg-slate-950 text-white shadow-sm'
                                        : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-slate-300 hover:bg-white',
                                ].join(' ')}
                            >
                                <span className="block font-bold">
                                    {section.label}
                                </span>
                                <span
                                    className={[
                                        'mt-1 block text-xs leading-5',
                                        activeAdminSection === section.key
                                            ? 'text-slate-200'
                                            : 'text-slate-500',
                                    ].join(' ')}
                                >
                                    {section.summary}
                                </span>
                            </button>
                        ))}
                    </div>
                </nav>

                {activeAdminSection === 'overview' ? (
                    <>
                        <section className="grid gap-4 md:grid-cols-3">
                            {adminItems.map(([title, description]) => (
                                <article
                                    key={title}
                                    className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                                >
                                    <p className="text-sm font-semibold text-slate-950">
                                        {title}
                                    </p>
                                    <p className="mt-2 text-sm leading-6 text-slate-600">
                                        {description}
                                    </p>
                                </article>
                            ))}
                        </section>
                    </>
                ) : null}

                {activeAdminSection === 'templates' ? (
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div className="max-w-4xl">
                                <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                                    Şablonlar
                                </p>
                                <h2 className="mt-2 text-lg font-bold text-slate-950">
                                    Template / preview / değişken doğrulama
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    {messageTemplates.helper_text} WhatsApp, SMS
                                    ve gelecekteki Voibot voice script aynı
                                    değişken doğrulama hattından geçer. Manuel
                                    no-send test kaydı shared test phone
                                    kapsamını doğrular; provider çağrısı ve
                                    müşteri/usta iş akışı tetiklenmez.
                                    Müşteri mesajları teknik alan isimleriyle
                                    değil, doğal randevu cümleleriyle
                                    oluşturulur. MRN/SRV müşteri mesajında iş
                                    tipine göre cümle içinde kullanılır;
                                    servis/SRV mesajında müşteriye MRN
                                    gösterilmez. SMS kısa ve segment kontrollü
                                    tutulur.
                                </p>
                                {templateMessage ? (
                                    <p className="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">
                                        {templateMessage}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    disabled={templatePreviewing}
                                    onClick={() => {
                                        void previewTemplate();
                                    }}
                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {templatePreviewing
                                        ? 'Önizleniyor'
                                        : 'Önizle'}
                                </button>
                                <button
                                    type="button"
                                    disabled={templateSaving}
                                    onClick={() => {
                                        void saveTemplate();
                                    }}
                                    className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {templateSaving ? 'Kaydediliyor' : 'Kaydet'}
                                </button>
                                <button
                                    type="button"
                                    disabled={templateSaving}
                                    onClick={() => {
                                        void restoreTemplateDefault();
                                    }}
                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Varsayılana dön
                                </button>
                                <button
                                    type="button"
                                    disabled={
                                        templateTestSending ||
                                        templateInputs.channel ===
                                            'voice_script'
                                    }
                                    onClick={() => {
                                        void sendTemplateTestMessage();
                                    }}
                                    className="rounded-lg border border-emerald-700 bg-emerald-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {templateTestSending
                                        ? 'Kayıt oluşturuluyor'
                                        : 'No-send test kaydı'}
                                </button>
                            </div>
                            <p className="text-xs font-semibold text-slate-500 xl:text-right">
                                {templateInputs.channel === 'sms'
                                    ? 'SMS şablonu NAC payload doğrulamasıyla no-send audit kaydı olarak tutulur; provider çağrısı yapılmaz.'
                                    : templateInputs.channel === 'whatsapp'
                                      ? 'Seçili şablon no-send audit kaydı olarak tutulur; provider çağrısı yapılmaz.'
                                      : 'Voibot sözleşme bekleniyor; bu kanalda test gönderimi kapalı.'}
                            </p>
                        </div>

                        <div className="mt-5 flex flex-wrap gap-2">
                            {templateSectionTabs.map((section) => (
                                <button
                                    key={section.key}
                                    type="button"
                                    onClick={() => {
                                        setActiveTemplateSection(section.key);

                                        const channel =
                                            section.key === 'sms'
                                                ? 'sms'
                                                : section.key === 'voice'
                                                  ? 'voice_script'
                                                  : 'whatsapp';
                                        const nextTemplate =
                                            messageTemplates.templates.find(
                                                (template) =>
                                                    template.channel ===
                                                    channel,
                                            );

                                        if (nextTemplate) {
                                            selectTemplate(
                                                nextTemplate.template_key,
                                            );
                                        }
                                    }}
                                    className={[
                                        'rounded-lg border px-3 py-2 text-sm font-semibold transition',
                                        activeTemplateSection === section.key
                                            ? 'border-slate-900 bg-slate-950 text-white'
                                            : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-white',
                                    ].join(' ')}
                                >
                                    {section.label}
                                </button>
                            ))}
                        </div>

                        <div className="mt-5 grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
                            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                    <span>Şablon seç</span>
                                    <select
                                        value={
                                            selectedTemplate?.template_key ??
                                            selectedTemplateKey
                                        }
                                        onChange={(event) =>
                                            selectTemplate(event.target.value)
                                        }
                                        className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                    >
                                        {messageTemplates.templates.map(
                                            (template) => (
                                                <option
                                                    key={template.template_key}
                                                    value={
                                                        template.template_key
                                                    }
                                                >
                                                    {template.title} /{' '}
                                                    {template.channel}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>

                                <div className="mt-4 grid gap-3 md:grid-cols-2">
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Mesaj tipi</span>
                                        <select
                                            value={templateInputs.message_type}
                                            onChange={(event) =>
                                                setTemplateInputs({
                                                    ...templateInputs,
                                                    message_type:
                                                        event.target.value,
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        >
                                            {messageTemplates.message_types.map(
                                                (type) => (
                                                    <option
                                                        key={
                                                            type.key ??
                                                            type.label
                                                        }
                                                        value={type.key}
                                                    >
                                                        {type.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Kanal</span>
                                        <select
                                            value={templateInputs.channel}
                                            onChange={(event) =>
                                                setTemplateInputs({
                                                    ...templateInputs,
                                                    channel: event.target.value,
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        >
                                            {messageTemplates.channels.map(
                                                (channel) => (
                                                    <option
                                                        key={channel.key}
                                                        value={channel.key}
                                                    >
                                                        {channel.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Provider</span>
                                        <select
                                            value={templateInputs.provider_key}
                                            onChange={(event) =>
                                                setTemplateInputs({
                                                    ...templateInputs,
                                                    provider_key:
                                                        event.target.value,
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        >
                                            <option value="">
                                                Provider bağımsız
                                            </option>
                                            {messageTemplates.providers.map(
                                                (provider) => (
                                                    <option
                                                        key={provider.key}
                                                        value={provider.key}
                                                    >
                                                        {provider.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Başlık</span>
                                        <input
                                            type="text"
                                            value={templateInputs.title}
                                            onChange={(event) =>
                                                setTemplateInputs({
                                                    ...templateInputs,
                                                    title: event.target.value,
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                </div>

                                <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                                    <p className="text-sm font-bold text-slate-950">
                                        Preview sample senaryosu
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-slate-600">
                                        Montaj/SRV ve payer state değiştirerek
                                        müşteri dilini, SMS kısalığını ve OPS
                                        event değişkenlerini kontrol et.
                                    </p>
                                    <div className="mt-3 grid gap-3 md:grid-cols-4">
                                        <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                            <span>İş tipi</span>
                                            <select
                                                value={
                                                    templateSampleInputs.job_type
                                                }
                                                onChange={(event) => {
                                                    setTemplateSampleInputs({
                                                        ...templateSampleInputs,
                                                        job_type: event.target
                                                            .value as TemplateSampleInputs['job_type'],
                                                    });
                                                    setTemplatePreview(null);
                                                }}
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            >
                                                <option value="montaj">
                                                    Montaj / MRN
                                                </option>
                                                <option value="servis">
                                                    Servis / SRV
                                                </option>
                                            </select>
                                        </label>
                                        <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                            <span>Ödeme durumu</span>
                                            <select
                                                value={
                                                    templateSampleInputs.payer_state_key
                                                }
                                                onChange={(event) => {
                                                    setTemplateSampleInputs({
                                                        ...templateSampleInputs,
                                                        payer_state_key: event
                                                            .target
                                                            .value as TemplateSampleInputs['payer_state_key'],
                                                    });
                                                    setTemplatePreview(null);
                                                }}
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            >
                                                <option value="customer_pays_technician">
                                                    Müşteri ustaya ödeyecek
                                                </option>
                                                <option value="company_collected_online">
                                                    Şirket online tahsil etti
                                                </option>
                                                <option value="pending_online_payment">
                                                    Online ödeme bekliyor
                                                </option>
                                                <option value="no_payment_required">
                                                    Ödeme gerekmez
                                                </option>
                                            </select>
                                        </label>
                                        <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                            <span>OPS event</span>
                                            <select
                                                value={
                                                    templateSampleInputs.event
                                                }
                                                onChange={(event) => {
                                                    setTemplateSampleInputs({
                                                        ...templateSampleInputs,
                                                        event: event.target
                                                            .value as TemplateSampleInputs['event'],
                                                    });
                                                    setTemplatePreview(null);
                                                }}
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            >
                                                <option value="appointment">
                                                    Normal randevu
                                                </option>
                                                <option value="job_rejected">
                                                    İş reddi
                                                </option>
                                                <option value="support_request">
                                                    Destek talebi
                                                </option>
                                                <option value="price_revision">
                                                    Fiyat revizyonu
                                                </option>
                                                <option value="completion_submitted">
                                                    Tamamlandı bildirimi
                                                </option>
                                            </select>
                                        </label>
                                        <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                            <span>Randevu saati</span>
                                            <select
                                                value={
                                                    templateSampleInputs.appointment_time_case
                                                }
                                                onChange={(event) => {
                                                    setTemplateSampleInputs({
                                                        ...templateSampleInputs,
                                                        appointment_time_case:
                                                            event.target
                                                                .value as TemplateSampleInputs['appointment_time_case'],
                                                    });
                                                    setTemplatePreview(null);
                                                }}
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            >
                                                <option value="morning_1200">
                                                    12:00 - müşteri sabah
                                                </option>
                                                <option value="boundary_1300">
                                                    13:00 - müşteri öğleden
                                                    sonra
                                                </option>
                                                <option value="afternoon_1400_1600">
                                                    14:00 - 16:00 net usta saati
                                                </option>
                                                <option value="missing_exact_time">
                                                    Eksik saat / blok testi
                                                </option>
                                            </select>
                                        </label>
                                    </div>
                                </div>

                                <label className="mt-4 grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                    <span>Şablon gövdesi</span>
                                    <textarea
                                        value={templateInputs.body}
                                        onChange={(event) =>
                                            setTemplateInputs({
                                                ...templateInputs,
                                                body: event.target.value,
                                            })
                                        }
                                        rows={8}
                                        className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                    />
                                </label>

                                <div className="mt-4 grid gap-3 md:grid-cols-2">
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Zorunlu değişkenler</span>
                                        <input
                                            type="text"
                                            value={
                                                templateInputs.required_variables
                                            }
                                            onChange={(event) =>
                                                setTemplateInputs({
                                                    ...templateInputs,
                                                    required_variables:
                                                        event.target.value,
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Opsiyonel değişkenler</span>
                                        <input
                                            type="text"
                                            value={
                                                templateInputs.optional_variables
                                            }
                                            onChange={(event) =>
                                                setTemplateInputs({
                                                    ...templateInputs,
                                                    optional_variables:
                                                        event.target.value,
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                </div>

                                <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                                    <p className="text-sm font-bold text-slate-950">
                                        Değişken ekle
                                    </p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {templateVariableList
                                            .slice(0, 18)
                                            .map((variable) => (
                                                <button
                                                    key={variable.key}
                                                    type="button"
                                                    onClick={() =>
                                                        setTemplateInputs({
                                                            ...templateInputs,
                                                            body: `${templateInputs.body} {${variable.key}}`,
                                                        })
                                                    }
                                                    className="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-bold text-slate-700 hover:bg-slate-100"
                                                    title={variable.description}
                                                >
                                                    {'{'}
                                                    {variable.key}
                                                    {'}'}
                                                </button>
                                            ))}
                                    </div>
                                    <p className="mt-2 text-xs leading-5 text-slate-600">
                                        Yasak değişkenler:{' '}
                                        {messageTemplates.forbidden_variables.join(
                                            ', ',
                                        )}
                                    </p>
                                </div>
                            </div>

                            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <p className="text-sm font-bold text-slate-950">
                                            Önizleme
                                        </p>
                                        <p className="mt-1 text-sm leading-6 text-slate-600">
                                            Preview sample context ile çalışır
                                            ve gönderime hazır sayılmaz. Manuel
                                            test gönderimi ayrı butondur ve
                                            sadece ortak test telefonunu
                                            hedefler.
                                        </p>
                                    </div>
                                    <span
                                        className={[
                                            'rounded-lg border px-3 py-2 text-xs font-bold',
                                            templatePreviewBadgeClass,
                                        ].join(' ')}
                                    >
                                        {templatePreviewStatusLabel}
                                    </span>
                                </div>

                                <div className="mt-4 min-h-[180px] rounded-lg border border-slate-200 bg-white p-4 font-sans text-sm leading-6 break-words whitespace-pre-wrap text-slate-800">
                                    {templatePreview?.rendered_body ??
                                        'Önizle butonuyla render çıktısı burada görünecek.'}
                                </div>

                                {smsPreviewMetadata ? (
                                    <div className="mt-3 grid gap-2 sm:grid-cols-4">
                                        {[
                                            [
                                                'Karakter',
                                                String(
                                                    smsPreviewMetadata.characters,
                                                ),
                                            ],
                                            [
                                                'Segment',
                                                String(
                                                    smsPreviewMetadata.segments,
                                                ),
                                            ],
                                            [
                                                'Encoding',
                                                smsPreviewMetadata.encoding,
                                            ],
                                            [
                                                'Satır',
                                                String(
                                                    smsPreviewMetadata.line_count,
                                                ),
                                            ],
                                        ].map(([label, value]) => (
                                            <div
                                                key={label}
                                                className="rounded-lg border border-slate-200 bg-white px-3 py-2"
                                            >
                                                <p className="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                                    {label}
                                                </p>
                                                <p className="mt-1 text-sm font-bold text-slate-950">
                                                    {value}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                ) : null}

                                <div className="mt-3 grid gap-3 lg:grid-cols-2">
                                    <div
                                        className={[
                                            'rounded-lg border p-3',
                                            templateBlockPanelClass,
                                        ].join(' ')}
                                    >
                                        <p className="text-sm font-bold">
                                            Bloklar
                                        </p>
                                        <ul
                                            className={[
                                                'mt-2 space-y-1 text-xs font-semibold',
                                                templateBlockListClass,
                                            ].join(' ')}
                                        >
                                            {templateBlockMessages.map(
                                                (item) => (
                                                    <li key={item}>- {item}</li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                    <div
                                        className={[
                                            'rounded-lg border p-3',
                                            templateWarningPanelClass,
                                        ].join(' ')}
                                    >
                                        <p className="text-sm font-bold">
                                            Uyarılar
                                        </p>
                                        <ul
                                            className={[
                                                'mt-2 space-y-1 text-xs font-semibold',
                                                templateWarningListClass,
                                            ].join(' ')}
                                        >
                                            {templateWarningMessages.map(
                                                (item) => (
                                                    <li key={item}>- {item}</li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                </div>

                                <div className="mt-3 rounded-lg border border-slate-200 bg-white p-3 text-xs leading-5 text-slate-600">
                                    <p>
                                        <span className="font-bold text-slate-900">
                                            Payer state:
                                        </span>{' '}
                                        {templatePreview?.payer_state_key ??
                                            'sample'}
                                    </p>
                                    <p>
                                        <span className="font-bold text-slate-900">
                                            Alıcı rolü:
                                        </span>{' '}
                                        {templatePreview?.recipient_role ?? '-'}
                                    </p>
                                    <p>
                                        <span className="font-bold text-slate-900">
                                            İş tipi:
                                        </span>{' '}
                                        {String(
                                            templatePreview?.context
                                                ?.customer_job_type_label ??
                                                '-',
                                        )}
                                    </p>
                                    <p>
                                        <span className="font-bold text-slate-900">
                                            Müşteri referansı:
                                        </span>{' '}
                                        {String(
                                            templatePreview?.context
                                                ?.customer_reference_code ??
                                                '-',
                                        )}
                                    </p>
                                    <p>
                                        <span className="font-bold text-slate-900">
                                            Müşteri aralığı:
                                        </span>{' '}
                                        {String(
                                            templatePreview?.context
                                                ?.appointment_customer_window ??
                                                '-',
                                        )}
                                    </p>
                                    <p>
                                        <span className="font-bold text-slate-900">
                                            Usta net saati:
                                        </span>{' '}
                                        {String(
                                            templatePreview?.context
                                                ?.appointment_exact_time_range ??
                                                '-',
                                        )}
                                    </p>
                                    {hiddenInternalReferenceKeys.length > 0 ? (
                                        <p>
                                            <span className="font-bold text-slate-900">
                                                Gizlenen iç referans:
                                            </span>{' '}
                                            SRV müşteri mesajında root MRN
                                            gösterilmez.
                                        </p>
                                    ) : null}
                                    <p>
                                        <span className="font-bold text-slate-900">
                                            Gönderim:
                                        </span>{' '}
                                        Business gönderim kapalı; test butonu
                                        sadece shared test phone kullanır.
                                    </p>
                                </div>
                                {templateTestSendResult ? (
                                    <div
                                        className={`mt-3 rounded-lg border p-3 text-xs leading-5 ${templateTestResultClass}`}
                                    >
                                        <p className="font-bold">
                                            Son test sonucu
                                        </p>
                                        <p>
                                            Test tipi:{' '}
                                            {
                                                templateTestSendResult.dispatch
                                                    .test_type
                                            }
                                        </p>
                                        <p>
                                            Durum:{' '}
                                            {
                                                templateTestSendResult.dispatch
                                                    .status
                                            }
                                        </p>
                                        <p>
                                            Hedef:{' '}
                                            {
                                                templateTestSendResult.dispatch
                                                    .target_phone_masked
                                            }{' '}
                                            (
                                            {
                                                templateTestSendResult.dispatch
                                                    .target_type
                                            }
                                            )
                                        </p>
                                        {templateTestSendResult.dispatch
                                            .error_message ? (
                                            <p>
                                                Not:{' '}
                                                {
                                                    templateTestSendResult
                                                        .dispatch.error_message
                                                }
                                            </p>
                                        ) : null}
                                        {templateTestSendResult.dispatch
                                            .content_preview ? (
                                            <p>
                                                Gönderilen içerik:{' '}
                                                {
                                                    templateTestSendResult
                                                        .dispatch
                                                        .content_preview
                                                }
                                            </p>
                                        ) : null}
                                        {templateTestSendResult.dispatch
                                            .encoding !== null ? (
                                            <p>
                                                NAC encoding:{' '}
                                                {
                                                    templateTestSendResult
                                                        .dispatch.encoding
                                                }
                                            </p>
                                        ) : null}
                                        {templateTestSendResult.dispatch
                                            .test_code ? (
                                            <p>
                                                Paket kodu:{' '}
                                                {
                                                    templateTestSendResult
                                                        .dispatch.test_code
                                                }
                                            </p>
                                        ) : null}
                                        {templateTestSendResult.dispatch
                                            .custom_id ? (
                                            <p>
                                                Custom ID:{' '}
                                                {
                                                    templateTestSendResult
                                                        .dispatch.custom_id
                                                }
                                            </p>
                                        ) : null}
                                        {templateTestSendResult.dispatch
                                            .duplicate ? (
                                            <p className="font-semibold">
                                                Şablon metni değiştirilmeden
                                                benzersiz title/customID ile
                                                denendi; NAC yine duplicate
                                                döndürdüyse alıcı/sender için 30
                                                dakikalık provider kuralı
                                                olabilir.
                                            </p>
                                        ) : null}
                                        {templateTestSendResult.dispatch
                                            .payload_hash ? (
                                            <p>
                                                Payload hash:{' '}
                                                {
                                                    templateTestSendResult
                                                        .dispatch.payload_hash
                                                }
                                            </p>
                                        ) : null}
                                        {templateTestSendResult.dispatch
                                            .previous_payload_hash ? (
                                            <p>
                                                Önceki payload hash:{' '}
                                                {
                                                    templateTestSendResult
                                                        .dispatch
                                                        .previous_payload_hash
                                                }
                                            </p>
                                        ) : null}
                                        {templateTestSendResult.dispatch
                                            .provider_reference ? (
                                            <p>
                                                Provider ref:{' '}
                                                {
                                                    templateTestSendResult
                                                        .dispatch
                                                        .provider_reference
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    </section>
                ) : null}

                {activeAdminSection === 'overview' ? (
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                            QR müşteri akışı
                        </p>
                        <div className="mt-3 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div className="max-w-3xl">
                                <h2 className="text-lg font-bold text-slate-950">
                                    {qrPublicFlowSettings.label}
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    Kapalıyken müşteri formu montaj dahil /
                                    hariç sonucundan bağımsız açılır; ödeme
                                    gerekiyorsa OPS daha sonra link oluşturur.
                                    Açıkken montaj hariç, stok/depo veya ödeme
                                    gerektiren QR akışları formdan önce public
                                    ödeme sayfasına yönlendirilir.
                                </p>
                                <p className="mt-2 text-xs font-semibold text-slate-500">
                                    Ayar anahtarı: {qrPublicFlowSettings.key}
                                </p>
                                {message ? (
                                    <p className="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">
                                        {message}
                                    </p>
                                ) : null}
                            </div>
                            <label className="inline-flex shrink-0 cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">
                                <input
                                    type="checkbox"
                                    checked={preFormPaymentEnabled}
                                    disabled={saving}
                                    onChange={(event) => {
                                        const enabled = event.target.checked;

                                        setPreFormPaymentEnabled(enabled);
                                        void updatePreFormPayment(enabled);
                                    }}
                                    className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                />
                                {preFormPaymentEnabled ? 'Açık' : 'Kapalı'}
                            </label>
                        </div>
                    </section>
                ) : null}

                {activeAdminSection === 'payment' ? (
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div className="max-w-3xl">
                                <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                                    Ödeme sağlayıcı
                                </p>
                                <h2 className="mt-2 text-lg font-bold text-slate-950">
                                    {paymentSettings.effective_mode_label}
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    {paymentSettings.fake_active
                                        ? 'Fake/local ödeme aktif. Iyzico devre dışı.'
                                        : 'Iyzico Laravel Direct aktif. Fake ödeme kullanılmaz.'}
                                </p>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    Ödeme adaptörü:{' '}
                                    <span className="font-semibold text-slate-900">
                                        {
                                            paymentSettings.provider_transport_label
                                        }
                                    </span>
                                </p>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    Planlanan Iyzico modu:{' '}
                                    <span className="font-semibold text-slate-900">
                                        {
                                            paymentSettings.selected_provider_mode_label
                                        }
                                    </span>
                                    {paymentSettings.fake_active
                                        ? ' — Seçilen Iyzico modu sadece hazırlık ayarıdır; gerçek ödeme kapalı.'
                                        : null}
                                </p>
                                <p className="mt-2 text-sm font-semibold text-amber-700">
                                    {paymentSettings.warning}
                                </p>
                                <p className="mt-1 text-sm text-slate-600">
                                    {paymentSettings.credentials.entry_message}
                                </p>
                                {paymentMessage ? (
                                    <p className="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">
                                        {paymentMessage}
                                    </p>
                                ) : null}
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2 lg:min-w-[360px]">
                                <label className="inline-flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">
                                    <span>Gerçek ödeme alınsın</span>
                                    <input
                                        type="checkbox"
                                        checked={
                                            paymentSettings.real_provider_enabled
                                        }
                                        disabled={paymentSaving}
                                        onChange={(event) => {
                                            void updateRealPaymentToggle(
                                                event.target.checked,
                                            );
                                        }}
                                        className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                    />
                                </label>
                                <label className="grid gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">
                                    <span>Provider modu</span>
                                    <select
                                        value={paymentSettings.provider_mode}
                                        disabled={providerModeDisabled}
                                        onChange={(event) => {
                                            void updateProviderMode(
                                                event.target.value === 'live'
                                                    ? 'live'
                                                    : 'sandbox',
                                            );
                                        }}
                                        className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                    >
                                        <option value="sandbox">
                                            Iyzico Sandbox
                                        </option>
                                        <option value="live">
                                            Iyzico Live
                                        </option>
                                    </select>
                                </label>
                                <button
                                    type="button"
                                    disabled={paymentSaving}
                                    onClick={() => {
                                        void resetToSafePaymentMode();
                                    }}
                                    className="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60 sm:col-span-2"
                                >
                                    Fake/Yerel moda dön
                                </button>
                            </div>
                        </div>

                        <div className="mt-5 grid gap-3 md:grid-cols-5">
                            {providerStatusCards.map((item) => (
                                <div
                                    key={item.label}
                                    className={[
                                        'rounded-xl border px-4 py-3',
                                        item.ok
                                            ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                            : 'border-amber-100 bg-amber-50 text-amber-900',
                                    ].join(' ')}
                                >
                                    <p className="text-xs font-semibold tracking-[0.12em] uppercase opacity-75">
                                        {item.label}
                                    </p>
                                    <p className="mt-1 text-sm font-bold">
                                        {item.value}
                                    </p>
                                </div>
                            ))}
                        </div>

                        <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p className="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        Firma / Tahsilat Bilgileri
                                    </p>
                                    <h3 className="mt-1 text-base font-bold text-slate-950">
                                        Ödeme alan firma adresi
                                    </h3>
                                    <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                        {
                                            paymentSettings.company_recipient
                                                .helper_text
                                        }
                                    </p>
                                    <p
                                        className={[
                                            'mt-3 rounded-lg border px-3 py-2 text-sm font-semibold',
                                            paymentSettings.company_recipient
                                                .ready
                                                ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                : 'border-amber-100 bg-amber-50 text-amber-900',
                                        ].join(' ')}
                                    >
                                        {
                                            paymentSettings.company_recipient
                                                .message
                                        }
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    disabled={paymentSaving}
                                    onClick={() => {
                                        void saveCompanyRecipientSettings();
                                    }}
                                    className="rounded-lg border border-slate-900 bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {paymentSaving
                                        ? 'Kaydediliyor'
                                        : 'Firma bilgilerini kaydet'}
                                </button>
                            </div>
                            <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {companyRecipientFields.map((field) => (
                                    <label
                                        key={field.key}
                                        className={
                                            field.type === 'textarea'
                                                ? 'grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase md:col-span-2 xl:col-span-3'
                                                : 'grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase'
                                        }
                                    >
                                        <span>{field.label}</span>
                                        {field.type === 'textarea' ? (
                                            <textarea
                                                value={
                                                    companyRecipientInputs[
                                                        field.key
                                                    ]
                                                }
                                                onChange={(event) =>
                                                    updateCompanyRecipientInput(
                                                        field.key,
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder={field.placeholder}
                                                className="min-h-20 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            />
                                        ) : (
                                            <input
                                                type={
                                                    field.type === 'email'
                                                        ? 'email'
                                                        : 'text'
                                                }
                                                value={
                                                    companyRecipientInputs[
                                                        field.key
                                                    ]
                                                }
                                                onChange={(event) =>
                                                    updateCompanyRecipientInput(
                                                        field.key,
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder={field.placeholder}
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            />
                                        )}
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div className="mt-5 grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                                <p className="font-semibold text-slate-950">
                                    Hazırlık durumu
                                </p>
                                <p className="mt-2 leading-6">
                                    {paymentSettings.health_status.message}
                                </p>
                                {paymentSettings.disabled_reason ? (
                                    <p className="mt-2 font-semibold text-rose-700">
                                        {paymentSettings.disabled_reason}
                                    </p>
                                ) : null}
                                <p className="mt-3 font-semibold text-slate-950">
                                    Credential kaynağı
                                </p>
                                <p className="mt-1 leading-6">
                                    n8n ödeme adaptörü aktif ödeme yolundan
                                    çıkarıldı. Iyzico imzası ve HTTP çağrısı
                                    Laravel Direct içinde yapılır.
                                </p>
                                <p className="mt-1 leading-6">
                                    {paymentSettings.credential_bridge.message}
                                </p>
                                <p className="mt-1 text-xs font-semibold text-slate-500">
                                    Kaynak:{' '}
                                    {
                                        paymentSettings.credential_bridge
                                            .source_label
                                    }
                                </p>
                                <p className="mt-2 text-sm font-semibold text-amber-700">
                                    Sonraki aksiyon: {paymentNextRequiredAction}
                                </p>
                                <div className="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-3">
                                    <div>
                                        <p className="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                            Iyzico API URL
                                        </p>
                                        <p className="mt-1 text-sm font-semibold break-all text-slate-900">
                                            Sandbox: {iyzicoSandboxBaseUrl}
                                        </p>
                                        <p className="mt-1 text-sm font-semibold break-all text-slate-900">
                                            Live: {iyzicoLiveBaseUrl}
                                        </p>
                                        <p className="mt-1 text-xs font-semibold text-slate-500">
                                            Authorization:{' '}
                                            {
                                                paymentSettings.iyzico_urls
                                                    .authorization_scheme
                                            }
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                            IP whitelist
                                        </p>
                                        <p className="mt-1 text-sm font-semibold text-slate-900">
                                            {paymentSettings.ip_whitelist.label}
                                        </p>
                                        <p className="mt-1 leading-6">
                                            {
                                                paymentSettings.ip_whitelist
                                                    .message
                                            }
                                        </p>
                                        <p className="mt-1 text-xs font-semibold text-slate-500">
                                            Kaynak:{' '}
                                            {
                                                paymentSettings.ip_whitelist
                                                    .source_label
                                            }
                                        </p>
                                        <p className="mt-1 text-xs font-semibold break-all text-slate-500">
                                            Manuel kontrol:{' '}
                                            {
                                                paymentSettings.ip_whitelist
                                                    .manual_check_command
                                            }
                                        </p>
                                        {paymentSettings.ip_whitelist
                                            .outbound_ip_value ? (
                                            <p className="mt-1 text-sm font-semibold text-emerald-800">
                                                Public IP:{' '}
                                                {
                                                    paymentSettings.ip_whitelist
                                                        .outbound_ip_value
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                    <div>
                                        <p className="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                            Back URL / callback
                                        </p>
                                        <p className="mt-1 text-sm font-semibold text-slate-900">
                                            {paymentSettings.back_url.label}
                                        </p>
                                        <p className="mt-1 leading-6">
                                            {paymentSettings.back_url.message}
                                        </p>
                                        {paymentSettings.back_url
                                            .global_back_url ? (
                                            <p className="mt-1 text-xs font-semibold break-all text-slate-500">
                                                Iyzico Back URL:{' '}
                                                {
                                                    paymentSettings.back_url
                                                        .global_back_url
                                                }
                                            </p>
                                        ) : null}
                                        {paymentSettings.back_url
                                            .payment_return_url ? (
                                            <p className="mt-1 text-xs font-semibold break-all text-slate-500">
                                                Müşteri ödeme URL şablonu:{' '}
                                                {
                                                    paymentSettings.back_url
                                                        .payment_return_url
                                                }
                                            </p>
                                        ) : null}
                                        <p className="mt-1 text-xs font-semibold text-slate-500">
                                            {
                                                paymentSettings.back_url
                                                    .identification_rule
                                            }
                                        </p>
                                        <p className="mt-1 text-xs font-semibold text-slate-500">
                                            Callback route:{' '}
                                            {paymentBackUrlCallbackRouteExists
                                                ? paymentSettings.back_url
                                                      .callback_route_name
                                                : 'Eksik'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                            Otomatik ödeme kontrolü
                                        </p>
                                        <p className="mt-1 text-sm font-semibold text-slate-900">
                                            Sandbox otomatik kontrol:{' '}
                                            {
                                                paymentSettings
                                                    .automatic_reconcile.sandbox
                                                    .label
                                            }
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-slate-600">
                                            {
                                                paymentSettings
                                                    .automatic_reconcile.sandbox
                                                    .message
                                            }
                                        </p>
                                        <p className="mt-2 text-sm font-semibold text-slate-900">
                                            Live otomatik kontrol:{' '}
                                            {
                                                paymentSettings
                                                    .automatic_reconcile.live
                                                    .label
                                            }
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-slate-600">
                                            {
                                                paymentSettings
                                                    .automatic_reconcile.live
                                                    .message
                                            }
                                        </p>
                                        <p className="mt-2 text-xs font-semibold text-slate-500">
                                            Callback verified:{' '}
                                            {paymentSettings.automatic_reconcile
                                                .callback_verified
                                                ? 'Evet'
                                                : 'Hayır'}
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-slate-600">
                                            {
                                                paymentSettings
                                                    .automatic_reconcile
                                                    .accepted_fallback
                                            }
                                        </p>
                                        <p className="mt-1 text-xs font-semibold text-amber-700">
                                            {
                                                paymentSettings
                                                    .automatic_reconcile
                                                    .live_release_requirement
                                            }
                                        </p>
                                    </div>
                                </div>
                                <p className="mt-2 text-xs font-semibold text-slate-500">
                                    Eski n8n adaptörü:{' '}
                                    {paymentSettings.legacy_n8n_adapter.message}
                                </p>
                                <p className="mt-2 text-sm font-semibold text-rose-700">
                                    {paymentSettings.provider_mode === 'live' &&
                                    !paymentSettings.live_send_approved
                                        ? 'Canlı mod gerçek para hareketi oluşturur. Canlı onay gerektirir.'
                                        : 'Sandbox test ortamıdır; gerçek para hareketi oluşturmaz.'}
                                </p>
                                <div className="mt-4 grid gap-2">
                                    {paymentSettings.sandbox_activation_checklist.map(
                                        (item) => (
                                            <div
                                                key={item.key}
                                                className={[
                                                    'rounded-lg border px-3 py-2 text-xs font-semibold',
                                                    item.ready
                                                        ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                        : 'border-amber-100 bg-amber-50 text-amber-900',
                                                ].join(' ')}
                                            >
                                                {item.ready
                                                    ? 'Hazır'
                                                    : 'Bekliyor'}{' '}
                                                — {item.label}
                                            </div>
                                        ),
                                    )}
                                </div>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                                <p className="font-semibold text-slate-950">
                                    API bilgileri
                                </p>
                                <p className="mt-2">
                                    Kaynak:{' '}
                                    {paymentSettings.credentials.source_label}
                                </p>
                                <p className="mt-1">
                                    {paymentSettings.credentials.api_key_status}
                                </p>
                                <p className="mt-1">
                                    {
                                        paymentSettings.credentials
                                            .secret_key_status
                                    }
                                </p>
                                <p className="mt-1 font-semibold text-amber-700">
                                    {paymentSettings.credentials.entry_status}
                                </p>
                                <p className="mt-2 leading-6">
                                    {paymentSettings.credentials.entry_message}
                                </p>
                                {paymentSettings.credentials.masked_api_key ? (
                                    <p className="mt-3 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 font-semibold text-emerald-900">
                                        API Key:{' '}
                                        {
                                            paymentSettings.credentials
                                                .masked_api_key
                                        }
                                    </p>
                                ) : null}
                                {paymentSettings.credentials
                                    .masked_secret_key ? (
                                    <p className="mt-2 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 font-semibold text-emerald-900">
                                        Secret Key:{' '}
                                        {
                                            paymentSettings.credentials
                                                .masked_secret_key
                                        }
                                    </p>
                                ) : null}
                                <div className="mt-4 grid gap-3">
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>
                                            {
                                                paymentSettings.selected_provider_mode_label
                                            }{' '}
                                            API Key
                                        </span>
                                        <input
                                            type="password"
                                            value={credentialInputs.api_key}
                                            onChange={(event) =>
                                                setCredentialInputs({
                                                    ...credentialInputs,
                                                    api_key: event.target.value,
                                                })
                                            }
                                            placeholder="Değiştirmek için yeni değer girin"
                                            autoComplete="off"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>
                                            {
                                                paymentSettings.selected_provider_mode_label
                                            }{' '}
                                            Secret Key
                                        </span>
                                        <input
                                            type="password"
                                            value={credentialInputs.secret_key}
                                            onChange={(event) =>
                                                setCredentialInputs({
                                                    ...credentialInputs,
                                                    secret_key:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="Değiştirmek için yeni değer girin"
                                            autoComplete="off"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <div className="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            disabled={
                                                credentialSaving ||
                                                credentialInputs.api_key.trim() ===
                                                    '' ||
                                                credentialInputs.secret_key.trim() ===
                                                    ''
                                            }
                                            onClick={() => {
                                                void saveCredentials();
                                            }}
                                            className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {credentialSaving
                                                ? 'Kaydediliyor'
                                                : 'API bilgilerini kaydet'}
                                        </button>
                                        <button
                                            type="button"
                                            disabled={
                                                credentialSaving ||
                                                !paymentSettings.credentials
                                                    .ready
                                            }
                                            onClick={() => {
                                                void clearCredentials();
                                            }}
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            API bilgilerini temizle
                                        </button>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    disabled={healthChecking}
                                    onClick={() => {
                                        void runPaymentHealthCheck();
                                    }}
                                    className="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {healthChecking
                                        ? 'Kontrol ediliyor'
                                        : 'Bağlantıyı doğrula'}
                                </button>
                                <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                                    <p className="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        Ödeme bildirimi maili
                                    </p>
                                    <label className="mt-3 flex items-center gap-3 text-sm font-semibold text-slate-800">
                                        <input
                                            type="checkbox"
                                            checked={notificationInputs.enabled}
                                            onChange={(event) =>
                                                setNotificationInputs({
                                                    ...notificationInputs,
                                                    enabled:
                                                        event.target.checked,
                                                })
                                            }
                                            className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                        />
                                        Ödeme bildirimi maili gönderilsin
                                    </label>
                                    <label className="mt-3 grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Alıcı e-posta adresleri</span>
                                        <input
                                            type="text"
                                            value={
                                                notificationInputs.recipients
                                            }
                                            onChange={(event) =>
                                                setNotificationInputs({
                                                    ...notificationInputs,
                                                    recipients:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="payment-audit@example.com"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <p className="mt-2 text-xs leading-5 text-slate-600">
                                        {paymentNotificationHelperText}
                                    </p>
                                    <p className="mt-1 text-xs font-semibold text-slate-500">
                                        Durum:{' '}
                                        {
                                            paymentSettings.payment_notification
                                                .status_label
                                        }
                                    </p>
                                    <p className="mt-1 text-xs font-semibold text-slate-500">
                                        SMTP:{' '}
                                        {paymentSettings.payment_notification
                                            .smtp_ready
                                            ? 'Hazır'
                                            : 'Eksik'}
                                    </p>
                                    <button
                                        type="button"
                                        disabled={paymentSaving}
                                        onClick={() => {
                                            void savePaymentNotificationSettings();
                                        }}
                                        className="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Mail ayarını kaydet
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                ) : null}

                {activeAdminSection === 'messaging' ||
                activeAdminSection === 'integrations' ? (
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div className="max-w-4xl">
                                <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                                    {activeAdminSection === 'integrations'
                                        ? 'Entegrasyonlar'
                                        : 'Mesajlaşma Sağlayıcı Ayarları'}
                                </p>
                                 <h2 className="mt-2 text-lg font-bold text-slate-950">
                                     {activeAdminSection === 'integrations'
                                         ? 'Portal, Mikro ve provider hazırlığı ayrı yönetilir.'
                                         : messagingRuntimeHeadline(messaging)}
                                 </h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                     {activeAdminSection === 'integrations'
                                         ? 'Yerel Manual E2E portalı, Mikro API, provider credentials ve operation catalog hazırlığı ayrı readiness kurallarıyla yönetilir.'
                                         : 'Provider readiness, kanal politikası ve Manual E2E yaşam döngüsü ayrı durumlar olarak server verisinden gösterilir. Randevu mesajı usta seçildiğinde değil OPS randevu onayında kuyruğa alınır.'}
                                </p>
                                {messagingMessage ? (
                                    <p className="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">
                                        {messagingMessage}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    disabled={
                                        messagingSaving ||
                                        messagingSettingsLocked
                                    }
                                    onClick={() => {
                                        void saveMessagingSettings();
                                    }}
                                    className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {messagingSaving
                                        ? 'Kaydediliyor'
                                        : 'Kaydet'}
                                </button>
                                <button
                                    type="button"
                                    disabled={
                                        messagingSaving ||
                                        messagingSettingsLocked
                                    }
                                    onClick={() => {
                                        void resetMessagingSettings();
                                    }}
                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Ayarları varsayılana döndür
                                </button>
                            </div>
                        </div>

                        {activeAdminSection === 'messaging' ? (
                            <div
                                data-testid="messaging-global-execution-mode-readonly"
                                className="mt-5 border-y border-slate-200 py-4"
                            >
                                <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                    <div className="min-w-0">
                                        <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                                            Global Sistem Çalışma Modu
                                        </p>
                                        <div className="mt-2 flex flex-wrap items-center gap-2">
                                            <h3 className="text-base font-bold text-slate-950">
                                                {messaging.execution_mode.state.toLocaleUpperCase(
                                                    'tr-TR',
                                                )}
                                            </h3>
                                            <span className="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-bold text-slate-700">
                                                {
                                                    messaging.execution_mode
                                                        .runtime_environment_label
                                                }
                                            </span>
                                            <span className="rounded border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-600">
                                                Epoch #{
                                                    messaging.execution_mode
                                                        .epoch
                                                } / Revision #{
                                                    messaging.execution_mode
                                                        .revision
                                                }
                                            </span>
                                        </div>
                                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                            Mesajlaşma bu global otoritenin bir
                                            capability adapter’ıdır. Bu ekran
                                            provider profile ve readiness
                                            gösterir; çalışma modu yalnız
                                            Yönetim Paneli’nde yönetilir.
                                        </p>
                                    </div>

                                    <span
                                        className="inline-flex min-h-10 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700"
                                    >
                                        Salt okunur
                                    </span>
                                </div>

                                <dl className="mt-4 grid gap-x-4 gap-y-3 text-xs sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                                    {[
                                        [
                                            'Evo readiness',
                                            messaging.execution_mode.readiness
                                                .evo_ready
                                                ? 'Hazır'
                                                : 'Eksik',
                                        ],
                                        [
                                            'NAC readiness',
                                            messaging.execution_mode.readiness
                                                .nac_ready
                                                ? 'Hazır'
                                                : 'Eksik',
                                        ],
                                        [
                                            'Global required',
                                            `${messaging.execution_mode.readiness.required_ready_count} / ${messaging.execution_mode.readiness.required_count}`,
                                        ],
                                        [
                                            'Global blocker',
                                            String(
                                                messaging.execution_mode
                                                    .readiness.blocker_count,
                                            ),
                                        ],
                                        [
                                            'Kayıtlı capability',
                                            String(
                                                messaging.execution_mode
                                                    .readiness.registered_count,
                                            ),
                                        ],
                                        [
                                            'Son değişiklik',
                                            messaging.execution_mode.changed_at
                                                ? formatManualE2ERunDate(
                                                      messaging.execution_mode
                                                          .changed_at,
                                                  )
                                                : 'Henüz değiştirilmedi',
                                        ],
                                    ].map(([label, value]) => (
                                        <div key={label} className="min-w-0">
                                            <dt className="font-semibold tracking-[0.08em] text-slate-500 uppercase">
                                                {label}
                                            </dt>
                                            <dd className="mt-1 font-bold break-words text-slate-900">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>

                                <div className="mt-3 flex flex-col gap-1 text-xs leading-5 text-slate-600 sm:flex-row sm:flex-wrap sm:gap-x-5">
                                    <span>
                                        Son değiştiren:{' '}
                                        <strong className="text-slate-800">
                                            {messaging.execution_mode.changed_by
                                                ?.name ?? 'Sistem varsayılanı'}
                                        </strong>
                                    </span>
                                    <span className="min-w-0 break-words">
                                        Gerekçe:{' '}
                                        <strong className="text-slate-800">
                                            {messaging.execution_mode.reason ??
                                                'Güvenli varsayılan'}
                                        </strong>
                                    </span>
                                </div>
                            </div>
                        ) : null}

                        {activeAdminSection === 'messaging' ? (
                            <div className="mt-5 flex flex-wrap gap-2">
                                {messagingSectionTabs.map((section) => (
                                    <button
                                        key={section.key}
                                        type="button"
                                        onClick={() =>
                                            setActiveMessagingSection(
                                                section.key,
                                            )
                                        }
                                        className={[
                                            'rounded-lg border px-3 py-2 text-sm font-semibold transition',
                                            activeMessagingSection ===
                                            section.key
                                                ? 'border-slate-900 bg-slate-950 text-white'
                                                : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-white',
                                        ].join(' ')}
                                    >
                                        {section.label}
                                    </button>
                                ))}
                            </div>
                        ) : (
                            <div className="mt-5 flex flex-wrap gap-2">
                                {integrationSectionTabs.map((section) => (
                                    <button
                                        key={section.key}
                                        type="button"
                                        onClick={() =>
                                            setActiveIntegrationSection(
                                                section.key,
                                            )
                                        }
                                        className={[
                                            'rounded-lg border px-3 py-2 text-sm font-semibold transition',
                                            activeIntegrationSection ===
                                            section.key
                                                ? 'border-slate-900 bg-slate-950 text-white'
                                                : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-white',
                                        ].join(' ')}
                                    >
                                        {section.label}
                                    </button>
                                ))}
                            </div>
                        )}

                        {activeAdminSection === 'messaging' &&
                        ['general', 'provider_tests', 'evo', 'voibot'].includes(
                            activeMessagingSection,
                        ) ? (
                            <div className="mt-5 grid gap-3 md:grid-cols-5">
                                {[
                                     {
                                         label: 'Etkin mod',
                                         value: messagingEffectiveModeLabel(
                                             messaging.readiness.effective_mode,
                                         ),
                                        ok:
                                            messaging.readiness.can_send_test ||
                                            messaging.readiness.can_send_real,
                                    },
                                    {
                                        label: 'Test telefonu',
                                        value:
                                            messaging.global
                                                .test_phone_masked ?? 'Eksik',
                                        ok: messaging.readiness
                                            .test_phone_configured,
                                    },
                                    {
                                        label: 'Aktif sağlayıcı',
                                        value: messaging.readiness
                                            .active_provider_label,
                                        ok:
                                            messaging.readiness
                                                .active_provider_enabled &&
                                            messaging.readiness
                                                .active_provider_supports_text,
                                    },
                                    {
                                        label: 'Evo Direct API',
                                        value: messaging.evo_whatsapp
                                            .direct_api_ready
                                            ? 'Hazır'
                                            : 'Eksik',
                                        ok: messaging.evo_whatsapp
                                            .direct_api_ready,
                                    },
                                     {
                                         label: 'Güvenli queue',
                                         value: messagingQueueState.label,
                                         ok: messagingQueueState.ready,
                                     },
                                ].map((item) => (
                                    <div
                                        key={item.label}
                                        className={[
                                            'rounded-xl border px-4 py-3',
                                            item.ok
                                                ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                : 'border-amber-100 bg-amber-50 text-amber-900',
                                        ].join(' ')}
                                    >
                                        <p className="text-xs font-semibold tracking-[0.12em] uppercase opacity-75">
                                            {item.label}
                                        </p>
                                        <p className="mt-1 text-sm font-bold">
                                            {item.value}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        ) : null}

                        {activeAdminSection === 'messaging' &&
                        ['provider_tests', 'evo', 'voibot'].includes(
                            activeMessagingSection,
                        ) ? (
                            <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <p className="text-sm font-bold text-slate-950">
                                            Provider readiness
                                        </p>
                                        <p className="mt-1 text-sm leading-6 text-slate-600">
                                            Message type ayarları provider
                                            bağımsız kalır; gönderim mevcut
                                            provider router ve güvenli queue
                                            guard üzerinden işlenir.
                                        </p>
                                    </div>
                                    <p className="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900">
                                        Voibot sözleşme bekliyor
                                    </p>
                                </div>

                                <div className="mt-4 grid gap-3 lg:grid-cols-3">
                                    {[
                                        [
                                            'active_provider',
                                            'Öncelikli sağlayıcı',
                                        ],
                                        [
                                            'default_provider',
                                            'Varsayılan test sağlayıcısı',
                                        ],
                                        [
                                            'fallback_provider',
                                            'Hata yedeği sağlayıcı',
                                        ],
                                    ].map(([key, label]) => (
                                        <label
                                            key={key}
                                            className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase"
                                        >
                                            <span>{label}</span>
                                            <select
                                                value={String(
                                                    messagingInputs[
                                                        key as keyof typeof messagingInputs
                                                    ],
                                                )}
                                                onChange={(event) =>
                                                    setMessagingInputs({
                                                        ...messagingInputs,
                                                        [key]: event.target
                                                            .value,
                                                    })
                                                }
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            >
                                                {messaging.providers.map(
                                                    (provider) => (
                                                        <option
                                                            key={provider.key}
                                                            value={provider.key}
                                                        >
                                                            {provider.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </label>
                                    ))}
                                </div>

                                <div className="mt-4 grid gap-3 lg:grid-cols-5">
                                    {messaging.providers.map((provider) => (
                                        <article
                                            key={provider.key}
                                            className={[
                                                'rounded-xl border bg-white p-3 text-sm',
                                                provider.active
                                                    ? 'border-slate-900'
                                                    : 'border-slate-200',
                                            ].join(' ')}
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="font-bold text-slate-950">
                                                        {provider.label}
                                                    </p>
                                                    <p className="mt-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                                        {provider.channel}
                                                    </p>
                                                </div>
                                                <span
                                                    className={[
                                                        'rounded-lg border px-2 py-1 text-xs font-bold',
                                                        provider.contract_confirmed &&
                                                        provider.enabled
                                                            ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                            : 'border-amber-100 bg-amber-50 text-amber-900',
                                                    ].join(' ')}
                                                >
                                                    {provider.status_label}
                                                </span>
                                            </div>
                                            <p className="mt-2 text-xs leading-5 text-slate-600">
                                                {provider.description}
                                            </p>
                                            {provider.ready_reason ? (
                                                <p className="mt-2 text-xs font-semibold text-amber-700">
                                                    {provider.ready_reason}
                                                </p>
                                            ) : null}
                                            <div className="mt-3 flex flex-wrap gap-1">
                                                {provider.active ? (
                                                    <span className="rounded bg-slate-900 px-2 py-1 text-xs font-bold text-white">
                                                        Öncelikli
                                                    </span>
                                                ) : null}
                                                {provider.default ? (
                                                    <span className="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700">
                                                        Test varsayılan
                                                    </span>
                                                ) : null}
                                                {provider.fallback ? (
                                                    <span className="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700">
                                                        Hata yedeği
                                                    </span>
                                                ) : null}
                                            </div>
                                        </article>
                                    ))}
                                </div>

                                <p className="mt-3 text-xs font-semibold text-slate-500">
                                    Provider önceliği:{' '}
                                    {messaging.provider.provider_priority.join(
                                        ' > ',
                                    )}
                                </p>
                            </div>
                        ) : null}

                        {activeAdminSection === 'messaging' &&
                        activeMessagingSection === 'provider_tests' ? (
                            <div className="mt-5 grid gap-4">
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                    {messaging.admin_sections.map((section) => (
                                        <article
                                            key={section.key}
                                            className={[
                                                'rounded-xl border p-3 text-sm',
                                                section.ready
                                                    ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                    : 'border-slate-200 bg-slate-50 text-slate-700',
                                            ].join(' ')}
                                        >
                                            <p className="font-bold">
                                                {section.label}
                                            </p>
                                            <p className="mt-1 text-xs leading-5 opacity-80">
                                                {section.summary}
                                            </p>
                                        </article>
                                    ))}
                                </div>

                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                                    {[
                                        {
                                            title: 'Evo No-send Test Kaydı',
                                            detail: 'Şablonlar bölümündeki no-send kontrolü shared test phone kapsamını doğrular; provider çağrısı yapmaz.',
                                            action: 'Şablon test kaydı',
                                            enabled: true,
                                        },
                                        {
                                            title: 'NAC No-send Test Kaydı',
                                            detail: 'Generic payload ve readiness audit kaydı üretir; gerçek SMS göndermez ve template body kullanmaz.',
                                            action: providerTestSending
                                                ? 'Kayıt oluşturuluyor'
                                                : 'No-send kayıt oluştur',
                                            enabled:
                                                messaging.nac_sms.test_ready &&
                                                !providerTestSending,
                                        },
                                        {
                                            title: 'Mikro Bağlantı Testi',
                                            detail: 'Mikro bağlantı testi read-only hazırlık ekranında görünür; güvenli endpoint olmadan çalışmaz.',
                                            action: 'Read-only test bekliyor',
                                            enabled: false,
                                        },
                                        {
                                            title: 'Voibot',
                                            detail: 'Voibot API sözleşmesi ve webhook contract bekleniyor.',
                                            action: 'Kapalı',
                                            enabled: false,
                                        },
                                        {
                                            title: 'Null Local Dry-run',
                                            detail: 'Provider çağrısı yapmadan template/render doğrulaması için güvenli varsayılan.',
                                            action: 'Dry-run',
                                            enabled: false,
                                        },
                                    ].map((item) => (
                                        <article
                                            key={item.title}
                                            className="rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700"
                                        >
                                            <p className="font-bold text-slate-950">
                                                {item.title}
                                            </p>
                                            <p className="mt-1 min-h-[56px] text-xs leading-5 text-slate-600">
                                                {item.detail}
                                            </p>
                                            <button
                                                type="button"
                                                disabled={!item.enabled}
                                                onClick={() => {
                                                    if (
                                                        item.title ===
                                                        'NAC No-send Test Kaydı'
                                                    ) {
                                                        void sendNacProviderTestMessage();

                                                        return;
                                                    }

                                                    setActiveAdminSection(
                                                        'templates',
                                                    );
                                                    const channel =
                                                        item.title ===
                                                        'NAC No-send Test Kaydı'
                                                            ? 'sms'
                                                            : 'whatsapp';
                                                    setActiveTemplateSection(
                                                        channel === 'sms'
                                                            ? 'sms'
                                                            : 'whatsapp',
                                                    );
                                                    const nextTemplate =
                                                        messageTemplates.templates.find(
                                                            (template) =>
                                                                template.channel ===
                                                                channel,
                                                        );

                                                    if (nextTemplate) {
                                                        selectTemplate(
                                                            nextTemplate.template_key,
                                                        );
                                                    }
                                                }}
                                                className="mt-3 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-white disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {item.action}
                                            </button>
                                        </article>
                                    ))}
                                </div>

                                {providerTestSendResult ? (
                                    <div
                                        className={`rounded-lg border p-3 text-xs leading-5 ${
                                            providerTestSendResult.dispatch
                                                .status === 'sent'
                                                ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                : providerTestSendResult
                                                        .dispatch.duplicate
                                                  ? 'border-amber-100 bg-amber-50 text-amber-900'
                                                  : 'border-red-100 bg-red-50 text-red-900'
                                        }`}
                                    >
                                        <p className="font-bold">
                                            NAC altyapı test sonucu
                                        </p>
                                        <p>
                                            Test tipi:{' '}
                                            {
                                                providerTestSendResult.dispatch
                                                    .test_type
                                            }
                                        </p>
                                        <p>
                                            Durum:{' '}
                                            {
                                                providerTestSendResult.dispatch
                                                    .status
                                            }
                                        </p>
                                        <p>
                                            Hedef:{' '}
                                            {
                                                providerTestSendResult.dispatch
                                                    .target_phone_masked
                                            }
                                        </p>
                                        {providerTestSendResult.dispatch
                                            .content_preview ? (
                                            <p>
                                                İçerik:{' '}
                                                {
                                                    providerTestSendResult
                                                        .dispatch
                                                        .content_preview
                                                }
                                            </p>
                                        ) : null}
                                        {providerTestSendResult.dispatch
                                            .provider_reference ? (
                                            <p>
                                                Provider ref:{' '}
                                                {
                                                    providerTestSendResult
                                                        .dispatch
                                                        .provider_reference
                                                }
                                            </p>
                                        ) : null}
                                        {providerTestSendResult.dispatch
                                            .error_message ? (
                                            <p>
                                                Not:{' '}
                                                {
                                                    providerTestSendResult
                                                        .dispatch.error_message
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

                        {activeAdminSection === 'messaging' &&
                        activeMessagingSection === 'nac_sms' ? (
                            <div className="mt-5 grid gap-4 xl:grid-cols-2">
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <p className="text-sm font-bold text-slate-950">
                                                SMS API / NAC
                                            </p>
                                            <p className="mt-1 text-sm leading-6 text-slate-600">
                                                Basic Auth encrypted saklanır.
                                                Runtime yol doğrudan Laravel →
                                                NAC SMS API olarak çalışır.
                                                Business SMS gönderimi
                                                kapalıdır; Şablonlar bölümünden
                                                yalnızca açık onaylı shared test
                                                phone NAC test SMS çalışır.
                                            </p>
                                        </div>
                                        <span
                                            className={[
                                                'rounded-lg border px-3 py-2 text-xs font-bold',
                                                messaging.nac_sms.test_ready
                                                    ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                    : 'border-amber-100 bg-amber-50 text-amber-900',
                                            ].join(' ')}
                                        >
                                            {messaging.nac_sms.test_ready
                                                ? 'Test hazır'
                                                : 'Hazırlık eksik'}
                                        </span>
                                    </div>

                                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                                        <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                            <span>Endpoint profili</span>
                                            <select
                                                value={nacSmsInputs.profile}
                                                onChange={(event) =>
                                                    setNacSmsInputs({
                                                        ...nacSmsInputs,
                                                        profile: event.target
                                                            .value as
                                                            | 'docs_https_9588'
                                                            | 'legacy_working_http_9587'
                                                            | 'custom',
                                                    })
                                                }
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            >
                                                <option value="legacy_working_http_9587">
                                                    Legacy working HTTP 9587
                                                </option>
                                                <option value="docs_https_9588">
                                                    Docs HTTPS 9588
                                                </option>
                                                <option value="custom">
                                                    Custom
                                                </option>
                                            </select>
                                        </label>
                                        <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                            <span>Request shape</span>
                                            <select
                                                value={
                                                    nacSmsInputs.request_shape
                                                }
                                                onChange={(event) =>
                                                    setNacSmsInputs({
                                                        ...nacSmsInputs,
                                                        request_shape: event
                                                            .target.value as
                                                            | 'legacy_working_minimal'
                                                            | 'docs_full',
                                                    })
                                                }
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            >
                                                <option value="legacy_working_minimal">
                                                    Legacy working minimal
                                                </option>
                                                <option value="docs_full">
                                                    Docs full
                                                </option>
                                            </select>
                                        </label>
                                        {(
                                            [
                                                ['enabled', 'NAC aktif'],
                                                [
                                                    'use_shared_test_phone',
                                                    'Ortak test telefonu',
                                                ],
                                                [
                                                    'commercial',
                                                    'Ticari gönderim',
                                                ],
                                                [
                                                    'real_send_allowed',
                                                    'Gerçek gönderim izni',
                                                ],
                                            ] as const
                                        ).map(([key, label]) => (
                                            <label
                                                key={key}
                                                className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={Boolean(
                                                        nacSmsInputs[key],
                                                    )}
                                                    onChange={(event) =>
                                                        setNacSmsInputs({
                                                            ...nacSmsInputs,
                                                            [key]: event.target
                                                                .checked,
                                                        })
                                                    }
                                                    className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                                />
                                                {label}
                                            </label>
                                        ))}
                                        {(
                                            [
                                                ['path', 'Path'],
                                                ['host', 'Host'],
                                                ['port', 'Port'],
                                                ['sender', 'Gönderen Başlığı'],
                                                ['title', 'Paket başlığı'],
                                                [
                                                    'gateway_uuid',
                                                    'Gateway UUID',
                                                ],
                                                [
                                                    'validity',
                                                    'Single SMS geçerlilik',
                                                ],
                                                [
                                                    'test_phone',
                                                    'NAC test telefonu',
                                                ],
                                                [
                                                    'report_push_url',
                                                    'Report push URL',
                                                ],
                                            ] as const
                                        ).map(([key, label]) => (
                                            <label
                                                key={key}
                                                className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase"
                                            >
                                                <span>{label}</span>
                                                <input
                                                    type={
                                                        [
                                                            'port',
                                                            'validity',
                                                        ].includes(key)
                                                            ? 'number'
                                                            : 'text'
                                                    }
                                                    value={String(
                                                        nacSmsInputs[key],
                                                    )}
                                                    disabled={
                                                        key === 'test_phone' &&
                                                        nacSmsInputs.use_shared_test_phone
                                                    }
                                                    onChange={(event) =>
                                                        setNacSmsInputs({
                                                            ...nacSmsInputs,
                                                            [key]: event.target
                                                                .value,
                                                        })
                                                    }
                                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none disabled:bg-slate-100"
                                                />
                                                {key === 'validity' ? (
                                                    <span className="text-[11px] leading-4 tracking-normal text-slate-500 normal-case">
                                                        Single SMS geçerlilik
                                                        süresi 60-1440
                                                        aralığındadır.
                                                    </span>
                                                ) : null}
                                                {key === 'title' ? (
                                                    <span className="text-[11px] leading-4 tracking-normal text-slate-500 normal-case">
                                                        Paket başlığı; test
                                                        gönderimde boşsa EMAKS
                                                        TEST kullanılır.
                                                    </span>
                                                ) : null}
                                            </label>
                                        ))}
                                    </div>
                                    <div className="mt-3 rounded-lg border border-slate-200 bg-white p-3 text-xs leading-5 text-slate-600">
                                        <span className="font-bold text-slate-900">
                                            Direct endpoint:
                                        </span>{' '}
                                        {messaging.nac_sms.endpoint_url}
                                    </div>

                                    <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                                        <p className="text-sm font-bold text-slate-950">
                                            NAC credential
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-slate-600">
                                            Kullanıcı:{' '}
                                            {messaging.nac_sms.username_mask ??
                                                'Eksik'}{' '}
                                            / Şifre:{' '}
                                            {messaging.nac_sms.password_mask ??
                                                'Eksik'}
                                        </p>
                                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                                            <input
                                                type="text"
                                                value={
                                                    nacSmsCredentialInputs.username
                                                }
                                                onChange={(event) =>
                                                    setNacSmsCredentialInputs({
                                                        ...nacSmsCredentialInputs,
                                                        username:
                                                            event.target.value,
                                                    })
                                                }
                                                placeholder="NAC kullanıcı adı"
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            />
                                            <input
                                                type="password"
                                                value={
                                                    nacSmsCredentialInputs.password
                                                }
                                                onChange={(event) =>
                                                    setNacSmsCredentialInputs({
                                                        ...nacSmsCredentialInputs,
                                                        password:
                                                            event.target.value,
                                                    })
                                                }
                                                placeholder="NAC şifre"
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            />
                                        </div>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                disabled={
                                                    integrationCredentialSaving ||
                                                    nacSmsCredentialInputs.username.trim() ===
                                                        '' ||
                                                    nacSmsCredentialInputs.password ===
                                                        ''
                                                }
                                                onClick={() => {
                                                    void saveNacSmsCredentials();
                                                }}
                                                className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                NAC bilgilerini kaydet
                                            </button>
                                            <button
                                                type="button"
                                                disabled={
                                                    integrationCredentialSaving ||
                                                    !messaging.nac_sms
                                                        .credentials_ready
                                                }
                                                onClick={() => {
                                                    void clearNacSmsCredentials();
                                                }}
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                NAC bilgilerini temizle
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 xl:col-span-2">
                                    <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <p className="text-sm font-bold text-slate-950">
                                                ERP operasyon kontrolü
                                            </p>
                                            <p className="mt-1 text-sm leading-6 text-slate-600">
                                                {
                                                    messaging.mikro_api
                                                        .implemented_read_operation_count
                                                }{' '}
                                                read adapter hazır,{' '}
                                                {
                                                    messaging.mikro_api
                                                        .contract_blocked_operation_count
                                                }{' '}
                                                contract satırı bloklu. Write
                                                executor kapalıdır.
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-2 text-xs font-bold">
                                            <span className="rounded-lg border border-emerald-100 bg-emerald-50 px-2 py-1 text-emerald-900">
                                                READ enabled:{' '}
                                                {
                                                    messaging.mikro_api
                                                        .enabled_read_operation_count
                                                }
                                            </span>
                                            <span className="rounded-lg border border-red-100 bg-red-50 px-2 py-1 text-red-900">
                                                WRITE enabled:{' '}
                                                {
                                                    messaging.mikro_api
                                                        .enabled_write_operation_count
                                                }
                                            </span>
                                        </div>
                                    </div>

                                    <div className="mt-4 max-h-[34rem] overflow-auto border-y border-slate-200">
                                        <table className="w-full min-w-[1120px] border-collapse text-left text-xs">
                                            <thead className="sticky top-0 z-10 bg-slate-100 text-slate-700">
                                                <tr>
                                                    <th className="px-3 py-2 font-bold">
                                                        Operasyon
                                                    </th>
                                                    <th className="px-3 py-2 font-bold">
                                                        Mod / adapter
                                                    </th>
                                                    <th className="px-3 py-2 font-bold">
                                                        Contract
                                                    </th>
                                                    <th className="px-3 py-2 font-bold">
                                                        Runtime
                                                    </th>
                                                    <th className="px-3 py-2 font-bold">
                                                        Source
                                                    </th>
                                                    <th className="px-3 py-2 font-bold">
                                                        Parity / circuit
                                                    </th>
                                                    <th className="px-3 py-2 font-bold">
                                                        İşlem
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100">
                                                {messaging.mikro_api.operation_catalog.operations.map(
                                                    (operation) => {
                                                        const control =
                                                            mikroApiInputs
                                                                .operation_controls[
                                                                operation
                                                                    .operation_key
                                                            ] ?? {};
                                                        const runtimeEnabled =
                                                            control.runtime_enabled ??
                                                            operation.runtime_enabled;
                                                        const sourceMode =
                                                            control.source_mode ??
                                                            operation.source_mode;

                                                        return (
                                                            <tr
                                                                key={
                                                                    operation.operation_key
                                                                }
                                                                className="align-top text-slate-700"
                                                            >
                                                                <td className="px-3 py-3">
                                                                    <p className="font-bold text-slate-950">
                                                                        {
                                                                            operation.operation_key
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-slate-500">
                                                                        {
                                                                            operation.display_name
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {
                                                                            operation.category
                                                                        }
                                                                    </p>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <span className="font-bold">
                                                                        {
                                                                            operation.mode
                                                                        }
                                                                    </span>
                                                                    <p className="mt-1 text-slate-500">
                                                                        {
                                                                            operation.adapter_type
                                                                        }
                                                                    </p>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <p className="font-semibold">
                                                                        {
                                                                            operation.contract_status
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-slate-500">
                                                                        {
                                                                            operation.implementation_status
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-slate-500">
                                                                        {
                                                                            operation.evidence_status
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 max-w-64 break-all text-slate-500">
                                                                        {
                                                                            operation.exact_path_casing
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-slate-500">
                                                                        Canary:{' '}
                                                                        {
                                                                            operation.installed_server_canary
                                                                        }
                                                                    </p>
                                                                    {operation.blocker ? (
                                                                        <p className="mt-1 max-w-64 text-amber-800">
                                                                            {
                                                                                operation.blocker
                                                                            }
                                                                        </p>
                                                                    ) : null}
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <input
                                                                        type="checkbox"
                                                                        aria-label={`${operation.operation_key} runtime`}
                                                                        checked={
                                                                            runtimeEnabled
                                                                        }
                                                                        disabled={
                                                                            !operation.runtime_eligible
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            setMikroApiInputs(
                                                                                {
                                                                                    ...mikroApiInputs,
                                                                                    operation_controls:
                                                                                        {
                                                                                            ...mikroApiInputs.operation_controls,
                                                                                            [operation.operation_key]:
                                                                                                {
                                                                                                    ...control,
                                                                                                    runtime_enabled:
                                                                                                        event
                                                                                                            .target
                                                                                                            .checked,
                                                                                                },
                                                                                        },
                                                                                },
                                                                            )
                                                                        }
                                                                        className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                                                    />
                                                                    {operation.mode ===
                                                                    'WRITE' ? (
                                                                        <p className="mt-1 text-red-700">
                                                                            Onay zorunlu
                                                                        </p>
                                                                    ) : null}
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <select
                                                                        value={
                                                                            sourceMode
                                                                        }
                                                                        disabled={
                                                                            operation.mode ===
                                                                            'WRITE'
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            setMikroApiInputs(
                                                                                {
                                                                                    ...mikroApiInputs,
                                                                                    operation_controls:
                                                                                        {
                                                                                            ...mikroApiInputs.operation_controls,
                                                                                            [operation.operation_key]:
                                                                                                {
                                                                                                    ...control,
                                                                                                    source_mode:
                                                                                                        event
                                                                                                            .target
                                                                                                            .value as MessagingMikroOperation['source_mode'],
                                                                                                },
                                                                                        },
                                                                                },
                                                                            )
                                                                        }
                                                                        className="rounded border border-slate-300 bg-white px-2 py-1 font-semibold"
                                                                    >
                                                                        {[
                                                                            'mikro',
                                                                            'n8n',
                                                                            'shadow_compare',
                                                                            'disabled',
                                                                        ].map(
                                                                            (
                                                                                mode,
                                                                            ) => (
                                                                                <option
                                                                                    key={
                                                                                        mode
                                                                                    }
                                                                                    value={
                                                                                        mode
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        mode
                                                                                    }
                                                                                </option>
                                                                            ),
                                                                        )}
                                                                    </select>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <p>
                                                                        {
                                                                            operation.parity_status
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 font-semibold">
                                                                        {
                                                                            operation.circuit_state
                                                                        }
                                                                    </p>
                                                                    {operation.last_error_code ? (
                                                                        <p className="mt-1 text-red-700">
                                                                            {
                                                                                operation.last_error_code
                                                                            }
                                                                        </p>
                                                                    ) : null}
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <div className="flex flex-wrap gap-2">
                                                                        {operation.operation_key ===
                                                                        'health.check' ? (
                                                                            <button
                                                                                type="button"
                                                                                disabled={
                                                                                    mikroConnectionTesting
                                                                                }
                                                                                onClick={() =>
                                                                                    void testMikroApiConnection()
                                                                                }
                                                                                className="rounded border border-sky-200 bg-sky-50 px-2 py-1 font-semibold text-sky-900 disabled:opacity-60"
                                                                            >
                                                                                Test
                                                                            </button>
                                                                        ) : null}
                                                                        {operation.mode ===
                                                                        'READ' ? (
                                                                            <button
                                                                                type="button"
                                                                                disabled={
                                                                                    mikroCircuitResetting ===
                                                                                    operation.operation_key
                                                                                }
                                                                                onClick={() =>
                                                                                    void resetMikroOperationCircuit(
                                                                                        operation.operation_key,
                                                                                    )
                                                                                }
                                                                                className="rounded border border-slate-300 bg-white px-2 py-1 font-semibold text-slate-800 disabled:opacity-60"
                                                                            >
                                                                                Reset
                                                                            </button>
                                                                        ) : null}
                                                                    </div>
                                                                    <details className="mt-2">
                                                                        <summary className="cursor-pointer font-semibold text-slate-700">
                                                                            Contract
                                                                        </summary>
                                                                        <div className="mt-1 max-w-72 break-words text-slate-500">
                                                                            <p>
                                                                                {operation.method ??
                                                                                    'N/A'}{' '}
                                                                                {operation.endpoint ??
                                                                                    operation.fixed_query_id ??
                                                                                    'BLOCKED'}
                                                                            </p>
                                                                            <p>
                                                                                {
                                                                                    operation.request_type
                                                                                }
                                                                            </p>
                                                                            <p>
                                                                                {
                                                                                    operation.response_type
                                                                                }
                                                                            </p>
                                                                        </div>
                                                                    </details>
                                                                </td>
                                                            </tr>
                                                        );
                                                    },
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        ) : null}

                        {activeAdminSection === 'integrations' &&
                        activeIntegrationSection === 'evo' ? (
                            <div className="mt-5 grid gap-4 xl:grid-cols-2">
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <p className="text-sm font-bold text-slate-950">
                                                Evo / WhatsApp Direct API
                                            </p>
                                            <p className="mt-1 text-sm leading-6 text-slate-600">
                                                Queue mesajları n8n yerine
                                                Direct Evolution API ile
                                                gönderilir. Endpoint:
                                                /message/sendText/&#123;instance&#125;.
                                                Hedef numara dispatch kaydından
                                                alınır.
                                            </p>
                                        </div>
                                        <span
                                            className={[
                                                'rounded-lg border px-3 py-2 text-xs font-bold',
                                                messaging.evo_whatsapp
                                                    .direct_api_ready
                                                    ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                    : 'border-amber-100 bg-amber-50 text-amber-900',
                                            ].join(' ')}
                                        >
                                            {messaging.evo_whatsapp
                                                .direct_api_ready
                                                ? 'Direct API hazır'
                                                : 'Hazırlık eksik'}
                                        </span>
                                    </div>

                                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                                        <label className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800">
                                            <input
                                                type="checkbox"
                                                checked={
                                                    evoWhatsappInputs.direct_api_enabled
                                                }
                                                onChange={(event) =>
                                                    setEvoWhatsappInputs({
                                                        ...evoWhatsappInputs,
                                                        direct_api_enabled:
                                                            event.target
                                                                .checked,
                                                    })
                                                }
                                                className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                            />
                                            Direct API aktif
                                        </label>
                                        <label className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800">
                                            <input
                                                type="checkbox"
                                                checked={
                                                    evoWhatsappInputs.link_preview
                                                }
                                                onChange={(event) =>
                                                    setEvoWhatsappInputs({
                                                        ...evoWhatsappInputs,
                                                        link_preview:
                                                            event.target
                                                                .checked,
                                                    })
                                                }
                                                className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                            />
                                            Link preview
                                        </label>
                                        {(
                                            [
                                                [
                                                    'direct_api_base_url',
                                                    'Direct API base URL',
                                                ],
                                                [
                                                    'direct_api_instance_name',
                                                    'Instance adı',
                                                ],
                                                ['delay', 'Delay (sn)'],
                                            ] as const
                                        ).map(([key, label]) => (
                                            <label
                                                key={key}
                                                className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase"
                                            >
                                                <span>{label}</span>
                                                <input
                                                    type={
                                                        key === 'delay'
                                                            ? 'number'
                                                            : 'text'
                                                    }
                                                    value={String(
                                                        evoWhatsappInputs[key],
                                                    )}
                                                    onChange={(event) =>
                                                        setEvoWhatsappInputs({
                                                            ...evoWhatsappInputs,
                                                            [key]: event.target
                                                                .value,
                                                        })
                                                    }
                                                    placeholder={
                                                        key ===
                                                        'direct_api_base_url'
                                                            ? 'http://10.0.26.110:8086'
                                                            : key ===
                                                                'direct_api_instance_name'
                                                              ? 'instance'
                                                              : '0'
                                                    }
                                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                />
                                            </label>
                                        ))}
                                    </div>

                                    <div className="mt-3 rounded-lg border border-slate-200 bg-white p-3 text-xs leading-5 text-slate-600">
                                        <span className="font-bold text-slate-900">
                                            Direct endpoint:
                                        </span>{' '}
                                        {messaging.evo_whatsapp.endpoint_url ??
                                            'Base URL ve instance eksik'}
                                    </div>
                                    {messaging.evo_whatsapp.blocking_reasons
                                        .length ? (
                                        <div className="mt-3 grid gap-2">
                                            {messaging.evo_whatsapp.blocking_reasons.map(
                                                (reason) => (
                                                    <p
                                                        key={reason}
                                                        className="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900"
                                                    >
                                                        {reason}
                                                    </p>
                                                ),
                                            )}
                                        </div>
                                    ) : null}
                                </div>

                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p className="text-sm font-bold text-slate-950">
                                        Evo Direct API credential
                                    </p>
                                    <p className="mt-1 text-sm leading-6 text-slate-600">
                                        API key/token encrypted saklanır ve tam
                                        değer response veya loglarda
                                        gösterilmez. Queue gönderiminde target
                                        dispatch effective target telefonudur.
                                    </p>
                                    <p className="mt-2 text-xs leading-5 text-slate-600">
                                        API key:{' '}
                                        {messaging.evo_whatsapp.api_key_mask ??
                                            'Eksik'}{' '}
                                        / Token:{' '}
                                        {messaging.evo_whatsapp.token_mask ??
                                            'Eksik'}
                                    </p>
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <input
                                            type="password"
                                            value={
                                                evoWhatsappCredentialInputs.api_key
                                            }
                                            onChange={(event) =>
                                                setEvoWhatsappCredentialInputs({
                                                    ...evoWhatsappCredentialInputs,
                                                    api_key: event.target.value,
                                                })
                                            }
                                            placeholder="Evo API key"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                        <input
                                            type="password"
                                            value={
                                                evoWhatsappCredentialInputs.token
                                            }
                                            onChange={(event) =>
                                                setEvoWhatsappCredentialInputs({
                                                    ...evoWhatsappCredentialInputs,
                                                    token: event.target.value,
                                                })
                                            }
                                            placeholder="Evo token (opsiyonel)"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            disabled={
                                                integrationCredentialSaving ||
                                                (evoWhatsappCredentialInputs.api_key.trim() ===
                                                    '' &&
                                                    evoWhatsappCredentialInputs.token.trim() ===
                                                        '')
                                            }
                                            onClick={() => {
                                                void saveEvoWhatsappCredentials();
                                            }}
                                            className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            Evo bilgilerini kaydet
                                        </button>
                                        <button
                                            type="button"
                                            disabled={
                                                integrationCredentialSaving ||
                                                !messaging.evo_whatsapp
                                                    .credentials_ready
                                            }
                                            onClick={() => {
                                                void clearEvoWhatsappCredentials();
                                            }}
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            Evo bilgilerini temizle
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ) : null}

                        {activeAdminSection === 'integrations' &&
                        activeIntegrationSection === 'manual_e2e_portal' ? (
                            <div
                                data-testid="manual-e2e-portal-origin-panel"
                                className="mt-5 border-y border-slate-200 py-5"
                            >
                                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div className="max-w-3xl">
                                        <h3 className="text-base font-bold text-slate-950">
                                            Manuel E2E Yerel Usta Portalı
                                        </h3>
                                        <p className="mt-1 text-sm leading-6 text-slate-600">
                                            Bu adres yalnız kontrollü ve
                                            allowlist&apos;li yerel test
                                            gönderimlerinde kullanılır. Canlı
                                            mesajlarda public HTTPS
                                            zorunluluğunu kaldırmaz.
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2 text-xs font-bold">
                                        <span
                                            data-testid="manual-e2e-portal-readiness"
                                            className={[
                                                'rounded-lg border px-3 py-2',
                                                messaging.portal_origins
                                                    .manual_e2e.ready
                                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                                                    : messaging.portal_origins
                                                            .manual_e2e
                                                            .status ===
                                                        'invalid'
                                                      ? 'border-red-200 bg-red-50 text-red-900'
                                                      : 'border-amber-200 bg-amber-50 text-amber-900',
                                            ].join(' ')}
                                        >
                                            Local Manual E2E portal:{' '}
                                            {
                                                messaging.portal_origins
                                                    .manual_e2e.status_label
                                            }
                                        </span>
                                        <span
                                            data-testid="live-public-portal-readiness"
                                            className={[
                                                'rounded-lg border px-3 py-2',
                                                messaging.portal_origins
                                                    .live_public.ready
                                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                                                    : 'border-amber-200 bg-amber-50 text-amber-900',
                                            ].join(' ')}
                                        >
                                            Live public portal HTTPS:{' '}
                                            {
                                                messaging.portal_origins
                                                    .live_public.status_label
                                            }
                                        </span>
                                    </div>
                                </div>

                                <div className="mt-4 grid gap-3 lg:grid-cols-[minmax(240px,0.7fr)_minmax(320px,1.3fr)]">
                                    <label className="flex items-center gap-3 border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800">
                                        <input
                                            data-testid="manual-e2e-portal-origin-enabled"
                                            type="checkbox"
                                            disabled={messagingSettingsLocked}
                                            checked={
                                                messagingInputs.manual_e2e_partner_portal_origin_enabled
                                            }
                                            onChange={(event) =>
                                                setMessagingInputs({
                                                    ...messagingInputs,
                                                    manual_e2e_partner_portal_origin_enabled:
                                                        event.target.checked,
                                                })
                                            }
                                            className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                        />
                                        Yerel portal adresini kullan
                                    </label>
                                    <label className="grid gap-1 text-sm font-semibold text-slate-800">
                                        Yerel portal origin
                                        <input
                                            data-testid="manual-e2e-portal-origin-input"
                                            type="url"
                                            disabled={messagingSettingsLocked}
                                            value={
                                                messagingInputs.manual_e2e_partner_portal_origin
                                            }
                                            onChange={(event) =>
                                                setMessagingInputs({
                                                    ...messagingInputs,
                                                    manual_e2e_partner_portal_origin:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="http://10.0.28.64:8000"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                </div>

                                {messaging.portal_origins.manual_e2e.warning ? (
                                    <p className="mt-3 border-l-4 border-amber-400 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
                                        {
                                            messaging.portal_origins.manual_e2e
                                                .warning
                                        }
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

                        {activeAdminSection === 'integrations' &&
                        activeIntegrationSection === 'mikro_api' ? (
                            <div className="mt-5 grid gap-4 xl:grid-cols-2">
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <p className="text-sm font-bold text-slate-950">
                                                Mikro API
                                            </p>
                                            <p className="mt-1 text-sm leading-6 text-slate-600">
                                                Mikro ERP operasyon sözleşmesi
                                                bu panelden yönetilir. Write
                                                capability satırları kayıtlıdır;
                                                canlı executor ve tüm write
                                                anahtarları kapalıdır.
                                            </p>
                                        </div>
                                        <span
                                            className={[
                                                'rounded-lg border px-3 py-2 text-xs font-bold',
                                                messaging.mikro_api.readiness_status ===
                                                'READY'
                                                    ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                    : messaging.mikro_api
                                                            .contract_ready
                                                      ? 'border-sky-100 bg-sky-50 text-sky-900'
                                                      : 'border-amber-100 bg-amber-50 text-amber-900',
                                            ].join(' ')}
                                        >
                                            {messaging.mikro_api
                                                .readiness_status === 'READY'
                                                ? 'Read hazır'
                                                : messaging.mikro_api
                                                        .contract_ready
                                                  ? 'Sözleşme hazır'
                                                  : 'Hazırlık eksik'}
                                        </span>
                                    </div>

                                    <p className="mt-3 text-sm font-semibold text-slate-700">
                                        {messaging.mikro_api
                                            .live_activation_status === 'READY'
                                            ? 'Canlı bağlantı hazır.'
                                            : messaging.mikro_api
                                                    .live_activation_status ===
                                                'LIVE_CONNECTIVITY_PENDING'
                                              ? 'Canlı HealthCheck bekleniyor.'
                                              : 'Canlı bağlantı bilgileri bekleniyor.'}
                                    </p>
                                    <p className="mt-1 text-xs text-slate-500">
                                        Read kataloğu:{' '}
                                        {
                                            messaging.mikro_api
                                                .read_operation_count
                                        }{' '}
                                        / write capability:{' '}
                                        {
                                            messaging.mikro_api
                                                .write_operation_count
                                        }
                                    </p>

                                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                                        {(
                                            [
                                                ['enabled', 'Mikro aktif'],
                                                [
                                                    'read_sync_enabled',
                                                    'Read sync açık',
                                                ],
                                                ['write_enabled', 'Write açık'],
                                                [
                                                    'write_approval_required',
                                                    'Write onayı zorunlu',
                                                ],
                                            ] as const
                                        ).map(([key, label]) => (
                                            <label
                                                key={key}
                                                className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={Boolean(
                                                        mikroApiInputs[key],
                                                    )}
                                                    onChange={(event) =>
                                                        setMikroApiInputs({
                                                            ...mikroApiInputs,
                                                            [key]: event.target
                                                                .checked,
                                                        })
                                                    }
                                                    className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                                />
                                                {label}
                                            </label>
                                        ))}
                                        {(
                                            [
                                                ['base_url', 'Base URL'],
                                                ['api_version', 'API version'],
                                                [
                                                    'application_code',
                                                    'Uygulama kodu',
                                                ],
                                                [
                                                    'application_name',
                                                    'Uygulama adı',
                                                ],
                                                ['company_code', 'Firma kodu'],
                                                ['branch_code', 'Şube kodu'],
                                                [
                                                    'workstation_code',
                                                    'Terminal kodu',
                                                ],
                                                ['fiscal_year', 'Mali yıl'],
                                                [
                                                    'timeout_seconds',
                                                    'Timeout saniye',
                                                ],
                                                [
                                                    'server_timezone',
                                                    'Mikro saat dilimi',
                                                ],
                                                [
                                                    'operation_catalog_status',
                                                    'Operasyon kataloğu',
                                                ],
                                            ] as const
                                        ).map(([key, label]) => (
                                            <label
                                                key={key}
                                                className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase"
                                            >
                                                <span>{label}</span>
                                                <input
                                                    type={
                                                        key ===
                                                        'timeout_seconds'
                                                            ? 'number'
                                                            : 'text'
                                                    }
                                                    value={String(
                                                        mikroApiInputs[key],
                                                    )}
                                                    readOnly={
                                                        key ===
                                                        'operation_catalog_status'
                                                    }
                                                    onChange={(event) =>
                                                        setMikroApiInputs({
                                                            ...mikroApiInputs,
                                                            [key]: event.target
                                                                .value,
                                                        })
                                                    }
                                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                />
                                            </label>
                                        ))}
                                    </div>

                                    <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                                        <p className="text-sm font-bold text-slate-950">
                                            Mikro credential
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-slate-600">
                                            Kullanıcı:{' '}
                                            {messaging.mikro_api
                                                .user_code_mask ?? 'Eksik'}{' '}
                                            / Parola:{' '}
                                            {messaging.mikro_api.password_mask ??
                                                'Eksik'}{' '}
                                            /{' '}
                                            API key:{' '}
                                            {messaging.mikro_api.api_key_mask ??
                                                'Eksik'}{' '}
                                            / Token:{' '}
                                            {messaging.mikro_api.token_mask ??
                                                'Eksik'}
                                        </p>
                                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                                            <input
                                                type="text"
                                                value={
                                                    mikroApiCredentialInputs.user_code
                                                }
                                                onChange={(event) =>
                                                    setMikroApiCredentialInputs(
                                                        {
                                                            ...mikroApiCredentialInputs,
                                                            user_code:
                                                                event.target
                                                                    .value,
                                                        },
                                                    )
                                                }
                                                placeholder="Mikro kullanıcı kodu"
                                                autoComplete="off"
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            />
                                            <input
                                                type="password"
                                                value={
                                                    mikroApiCredentialInputs.password
                                                }
                                                onChange={(event) =>
                                                    setMikroApiCredentialInputs(
                                                        {
                                                            ...mikroApiCredentialInputs,
                                                            password:
                                                                event.target
                                                                    .value,
                                                        },
                                                    )
                                                }
                                                placeholder="Mikro parola"
                                                autoComplete="new-password"
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            />
                                            <input
                                                type="password"
                                                value={
                                                    mikroApiCredentialInputs.api_key
                                                }
                                                onChange={(event) =>
                                                    setMikroApiCredentialInputs(
                                                        {
                                                            ...mikroApiCredentialInputs,
                                                            api_key:
                                                                event.target
                                                                    .value,
                                                        },
                                                    )
                                                }
                                                placeholder="Mikro API key"
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            />
                                            <input
                                                type="password"
                                                value={
                                                    mikroApiCredentialInputs.token
                                                }
                                                onChange={(event) =>
                                                    setMikroApiCredentialInputs(
                                                        {
                                                            ...mikroApiCredentialInputs,
                                                            token: event.target
                                                                .value,
                                                        },
                                                    )
                                                }
                                                placeholder="Mikro token"
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                            />
                                        </div>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                disabled={
                                                    integrationCredentialSaving ||
                                                    mikroApiCredentialInputs.api_key.trim() ===
                                                        '' ||
                                                    mikroApiCredentialInputs.user_code.trim() ===
                                                        '' ||
                                                    mikroApiCredentialInputs.password.trim() ===
                                                        ''
                                                }
                                                onClick={() => {
                                                    void saveMikroApiCredentials();
                                                }}
                                                className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                Mikro bilgilerini kaydet
                                            </button>
                                            <button
                                                type="button"
                                                disabled={
                                                    integrationCredentialSaving ||
                                                    !messaging.mikro_api
                                                        .credentials_ready
                                                }
                                                onClick={() => {
                                                    void clearMikroApiCredentials();
                                                }}
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                Mikro bilgilerini temizle
                                            </button>
                                            <button
                                                type="button"
                                                disabled={mikroConnectionTesting}
                                                onClick={() => {
                                                    void testMikroApiConnection();
                                                }}
                                                className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 disabled:cursor-not-allowed disabled:opacity-80"
                                                title="Yalnız read-only Mikro HealthCheck çalıştırılır. Eksik config ağ çağrısından önce bloklanır."
                                            >
                                                {mikroConnectionTesting
                                                    ? 'Mikro bağlantısı kontrol ediliyor...'
                                                    : 'Mikro bağlantı testi (read-only)'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ) : null}

                        {activeAdminSection === 'messaging' &&
                        activeMessagingSection === 'general' ? (
                            <div className="mt-5 grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
                                <div
                                    data-testid="manual-e2e-lifecycle-panel"
                                    className="border-y border-slate-200 bg-white py-4 xl:col-span-2"
                                >
                                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <p className="text-sm font-bold text-slate-950">
                                                Manual E2E kontrol paneli
                                            </p>
                                            <p className="mt-1 text-sm leading-6 text-slate-600">
                                                Önce provider kapalı güvenli run
                                                hazırlanır. Gerçek gönderim
                                                yalnız seçilen exact dispatch
                                                için ayrı ve tek kullanımlık
                                                pencereyle açılır.
                                            </p>
                                            {messaging.execution_mode.mode !==
                                            'live' ? (
                                                <p className="mt-2 text-sm font-semibold text-amber-800">
                                                    Manual E2E hazırlığı için
                                                    önce Canlı çalışma modu
                                                    readiness kapısından
                                                    geçmelidir.
                                                </p>
                                            ) : null}
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                data-testid="manual-e2e-readiness-button"
                                                disabled={
                                                    manualE2EReadinessChecking ||
                                                    manualE2ELifecycleBusy
                                                }
                                                onClick={() => {
                                                    void checkManualE2EReadiness();
                                                }}
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {manualE2EReadinessChecking
                                                    ? 'Kontrol ediliyor'
                                                    : 'Manual E2E Readiness Kontrolü'}
                                            </button>
                                            <button
                                                type="button"
                                                data-testid="manual-e2e-enable-button"
                                                disabled={
                                                    manualE2ELifecycleBusy ||
                                                    messaging.manual_e2e
                                                        .active ||
                                                    messaging.execution_mode
                                                        .mode !== 'live' ||
                                                    !manualE2EReadiness?.eligible
                                                }
                                                onClick={() =>
                                                    setManualE2EEnableConfirmationOpen(
                                                        true,
                                                    )
                                                }
                                                className="rounded-lg border border-red-700 bg-red-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-800 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                Güvenli Run Hazırla
                                            </button>
                                            <button
                                                type="button"
                                                data-testid="manual-e2e-freeze-button"
                                                disabled={
                                                    manualE2ELifecycleBusy
                                                }
                                                onClick={() => {
                                                    void freezeManualE2E();
                                                }}
                                                className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                Manual E2E’yi Dondur
                                            </button>
                                        </div>
                                    </div>

                                    <dl className="mt-4 grid gap-x-4 gap-y-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                        {[
                                            [
                                                'Lifecycle phase',
                                                messaging.global
                                                    .manual_e2e_phase,
                                            ],
                                            [
                                                'Manual E2E',
                                                messaging.global
                                                    .manual_e2e_enabled
                                                    ? 'Açık'
                                                    : 'Kapalı',
                                            ],
                                            [
                                                'Gerçek gönderim',
                                                messaging.global
                                                    .real_send_enabled
                                                    ? 'Açık'
                                                    : 'Kapalı',
                                            ],
                                            [
                                                'Queue',
                                                messaging.global.queue_paused
                                                    ? 'Duraklatıldı'
                                                    : 'Açık',
                                            ],
                                            [
                                                'Aktif run id',
                                                messaging.manual_e2e
                                                    .active_run_id ??
                                                    'Aktif run yok',
                                            ],
                                            [
                                                'Exact pencere',
                                                messaging.manual_e2e.open_window
                                                    ? `Dispatch #${messaging.manual_e2e.open_window.dispatch_id} / ${messaging.manual_e2e.open_window.provider}`
                                                    : messaging.manual_e2e
                                                            .active_claim
                                                      ? `Claimed dispatch #${messaging.manual_e2e.active_claim.dispatch_id}`
                                                      : 'Kapalı',
                                            ],
                                            [
                                                'Başlangıç',
                                                formatManualE2ERunDate(
                                                    messaging.manual_e2e
                                                        .started_at,
                                                ),
                                            ],
                                            [
                                                'Bitiş',
                                                formatManualE2ERunDate(
                                                    messaging.manual_e2e
                                                        .expires_at,
                                                ),
                                            ],
                                            [
                                                'Pending provider',
                                                String(
                                                    manualE2EReadiness?.pending_external_count ??
                                                        0,
                                                ),
                                            ],
                                            [
                                                'Unsafe dispatch',
                                                String(
                                                    manualE2EReadiness?.unsafe_external_count ??
                                                        0,
                                                ),
                                            ],
                                            [
                                                'Worker',
                                                !manualE2EReadiness
                                                    ? 'Kontrol edilmedi'
                                                    : manualE2EReadiness.worker_state ===
                                                        'active'
                                                      ? 'Aktif'
                                                      : manualE2EReadiness.worker_stale_recoverable
                                                        ? 'Stale lock / güvenli temizlenebilir'
                                                        : manualE2EReadiness.worker_state ===
                                                            'invalidated'
                                                          ? 'Geçersiz kılındı'
                                                          : 'Yok',
                                            ],
                                        ].map(([label, value]) => (
                                            <div
                                                key={label}
                                                className="min-w-0"
                                            >
                                                <dt className="font-semibold text-slate-500">
                                                    {label}
                                                </dt>
                                                <dd className="mt-1 font-semibold break-all text-slate-950">
                                                    {value}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>

                                    {manualE2EReadiness ? (
                                        <div
                                            data-testid="manual-e2e-readiness-result"
                                            className={`mt-4 border-l-4 px-4 py-3 text-sm ${
                                                manualE2EReadiness.eligible
                                                    ? 'border-emerald-500 bg-emerald-50 text-emerald-950'
                                                    : 'border-amber-500 bg-amber-50 text-amber-950'
                                            }`}
                                        >
                                            <p className="font-bold">
                                                {manualE2EReadiness.eligible
                                                    ? 'Readiness hazır'
                                                    : 'Readiness bloklu'}
                                            </p>
                                            <p className="mt-1">
                                                Evo:{' '}
                                                {manualE2EReadiness.evo_ready
                                                    ? 'Hazır'
                                                    : 'Eksik'}{' '}
                                                / NAC:{' '}
                                                {manualE2EReadiness.nac_ready
                                                    ? 'Hazır'
                                                    : 'Eksik'}{' '}
                                                / OPS SMS: Kapalı / TTL:{' '}
                                                {manualE2EReadiness.ttl_seconds}{' '}
                                                saniye
                                            </p>
                                            <p className="mt-1">
                                                Worker:{' '}
                                                {manualE2EReadiness.worker_state ===
                                                'active'
                                                    ? 'Aktif'
                                                    : manualE2EReadiness.worker_stale_recoverable
                                                      ? 'Stale lock; güvenli açma sırasında owner/run doğrulanarak temizlenecek'
                                                      : 'Yok'}
                                                {manualE2EReadiness.worker_heartbeat_at
                                                    ? ` / Son heartbeat: ${formatManualE2ERunDate(manualE2EReadiness.worker_heartbeat_at)}`
                                                    : ''}
                                            </p>
                                            <p className="mt-1 break-words">
                                                Allowlist:{' '}
                                                {manualE2EReadiness
                                                    .allowlisted_phone_masks
                                                    .length > 0
                                                    ? manualE2EReadiness.allowlisted_phone_masks.join(
                                                          ', ',
                                                      )
                                                    : 'Yok'}
                                            </p>
                                            {manualE2EReadiness.blockers.map(
                                                (blocker) => (
                                                    <p
                                                        key={blocker.code}
                                                        className="mt-1"
                                                    >
                                                        {blocker.message}
                                                    </p>
                                                ),
                                            )}
                                            {manualE2EReadiness.warnings.map(
                                                (warning) => (
                                                    <p
                                                        key={warning.code}
                                                        className="mt-1"
                                                    >
                                                        Uyarı: {warning.message}
                                                    </p>
                                                ),
                                            )}
                                        </div>
                                    ) : null}
                                </div>

                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p className="text-sm font-bold text-slate-950">
                                        Genel ayarlar
                                    </p>
                                    <p className="mt-1 text-sm leading-6 text-slate-600">
                                        {messaging.helper_texts.secrets}{' '}
                                        {messaging.helper_texts.test_phone}{' '}
                                        {messaging.helper_texts.queue}
                                    </p>

                                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                                        {[
                                            [
                                                'messaging_enabled',
                                                'Mesaj sistemi aktif',
                                            ],
                                            ['test_mode_enabled', 'Test modu'],
                                            [
                                                'allow_browser_smoke_send',
                                                'Browser smoke gönderimine izin',
                                            ],
                                            [
                                                'allow_test_fixture_send',
                                                'Test fixture gönderimine izin',
                                            ],
                                        ].map(([key, label]) => (
                                            <label
                                                key={key}
                                                className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800"
                                            >
                                                <input
                                                    type="checkbox"
                                                    disabled={
                                                        messagingSettingsLocked
                                                    }
                                                    checked={Boolean(
                                                        messagingInputs[
                                                            key as keyof typeof messagingInputs
                                                        ],
                                                    )}
                                                    onChange={(event) =>
                                                        setMessagingInputs({
                                                            ...messagingInputs,
                                                            [key]: event.target
                                                                .checked,
                                                        })
                                                    }
                                                    className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                                />
                                                {label}
                                            </label>
                                        ))}
                                    </div>

                                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                                        <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase md:col-span-2">
                                            <span>Test telefon numarası</span>
                                            <div className="flex flex-col gap-2 sm:flex-row">
                                                <input
                                                    type="text"
                                                    disabled={
                                                        messagingSettingsLocked
                                                    }
                                                    value={
                                                        messagingInputs.test_phone
                                                    }
                                                    onChange={(event) =>
                                                        setMessagingInputs({
                                                            ...messagingInputs,
                                                            test_phone:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    placeholder="905467647428"
                                                    className="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                />
                                                <button
                                                    type="button"
                                                    disabled={
                                                        messagingSettingsLocked ||
                                                        messagingPhoneChecking ||
                                                        messagingInputs.test_phone.trim() ===
                                                            ''
                                                    }
                                                    onClick={() => {
                                                        void validateMessagingPhone();
                                                    }}
                                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-800 normal-case shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {messagingPhoneChecking
                                                        ? 'Kontrol ediliyor'
                                                        : 'Test telefonu doğrula'}
                                                </button>
                                            </div>
                                        </label>
                                        {[
                                            [
                                                'send_delay_seconds',
                                                'Gönderim aralığı saniye',
                                                30,
                                            ],
                                            [
                                                'duplicate_cooldown_minutes',
                                                'Duplicate cooldown dakika',
                                                1,
                                            ],
                                            [
                                                'hourly_limit',
                                                'Saatlik limit',
                                                1,
                                            ],
                                            ['daily_limit', 'Günlük limit', 1],
                                            [
                                                'max_auto_retries',
                                                'Maksimum otomatik retry',
                                                0,
                                            ],
                                        ].map(([key, label, min]) => (
                                            <label
                                                key={key}
                                                className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase"
                                            >
                                                <span>{label}</span>
                                                <input
                                                    type="number"
                                                    disabled={
                                                        messagingSettingsLocked
                                                    }
                                                    min={Number(min)}
                                                    value={String(
                                                        messagingInputs[
                                                            key as keyof typeof messagingInputs
                                                        ],
                                                    )}
                                                    onChange={(event) =>
                                                        setMessagingInputs({
                                                            ...messagingInputs,
                                                            [key]: event.target
                                                                .value,
                                                        })
                                                    }
                                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                />
                                            </label>
                                        ))}
                                    </div>

                                    <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700">
                                        <p className="font-semibold text-slate-950">
                                            Readiness nedenleri
                                        </p>
                                        <ul className="mt-2 space-y-1">
                                            {messaging.readiness.disabled_reasons.map(
                                                (reason) => (
                                                    <li key={reason}>
                                                        - {reason}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                </div>

                                <div className="hidden">
                                    <p className="text-sm font-bold text-slate-950">
                                        Mesaj tipi ayarları
                                    </p>
                                    <p className="mt-1 text-sm leading-6 text-slate-600">
                                        Mesaj tipi ayarları provider
                                        bağımsızdır. Gerçek gönderim varsayılan
                                        kapalıdır; test modu açıkken hedef
                                        numara test telefonuna çevrilir.
                                    </p>
                                    <div className="mt-4 overflow-x-auto">
                                        <table className="min-w-[1280px] text-left text-sm">
                                            <thead className="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                                <tr>
                                                    <th className="px-3 py-2">
                                                        Mesaj tipi
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Aktif
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Test
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Gerçek
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Kanal politikası
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        WhatsApp
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        SMS
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Template key
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-200">
                                                {messaging.message_types.map(
                                                    (type) => {
                                                        const input =
                                                            messageTypeInputs[
                                                                type.key
                                                            ] ?? {
                                                                enabled: false,
                                                                test_send_allowed: false,
                                                                real_send_allowed: false,
                                                                channel_policy:
                                                                    'whatsapp_only',
                                                                whatsapp_mode:
                                                                    'test',
                                                                sms_mode:
                                                                    'disabled',
                                                                whatsapp_provider:
                                                                    'evo_whatsapp',
                                                                sms_provider:
                                                                    'nac_sms',
                                                                template_key:
                                                                    '',
                                                                notes: '',
                                                            };

                                                        return (
                                                            <tr
                                                                key={type.key}
                                                                className="align-top"
                                                            >
                                                                <td className="px-3 py-3">
                                                                    <p className="font-semibold text-slate-950">
                                                                        {
                                                                            type.label
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-xs leading-5 text-slate-600">
                                                                        {
                                                                            type.description
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-xs font-semibold text-slate-500">
                                                                        {
                                                                            type.recipient_role
                                                                        }
                                                                        {type.future
                                                                            ? ' / future'
                                                                            : ''}
                                                                    </p>
                                                                </td>
                                                                {(
                                                                    [
                                                                        'enabled',
                                                                        'test_send_allowed',
                                                                        'real_send_allowed',
                                                                    ] as const
                                                                ).map((key) => (
                                                                    <td
                                                                        key={
                                                                            key
                                                                        }
                                                                        className="px-3 py-3"
                                                                    >
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={Boolean(
                                                                                input[
                                                                                    key
                                                                                ],
                                                                            )}
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                enabled:
                                                                                                    Boolean(
                                                                                                        input.enabled,
                                                                                                    ),
                                                                                                test_send_allowed:
                                                                                                    Boolean(
                                                                                                        input.test_send_allowed,
                                                                                                    ),
                                                                                                real_send_allowed:
                                                                                                    Boolean(
                                                                                                        input.real_send_allowed,
                                                                                                    ),
                                                                                                template_key:
                                                                                                    input.template_key ??
                                                                                                    '',
                                                                                                notes:
                                                                                                    input.notes ??
                                                                                                    '',
                                                                                                [key]: event
                                                                                                    .target
                                                                                                    .checked,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                                                        />
                                                                    </td>
                                                                ))}
                                                                <td className="px-3 py-3">
                                                                    <select
                                                                        value={
                                                                            input.channel_policy
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            setMessageTypeInputs(
                                                                                {
                                                                                    ...messageTypeInputs,
                                                                                    [type.key]:
                                                                                        {
                                                                                            ...input,
                                                                                            channel_policy:
                                                                                                event
                                                                                                    .target
                                                                                                    .value,
                                                                                        },
                                                                                },
                                                                            )
                                                                        }
                                                                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                    >
                                                                        <option value="whatsapp_only">
                                                                            Sadece
                                                                            WhatsApp
                                                                        </option>
                                                                        <option value="sms_only">
                                                                            Sadece
                                                                            SMS
                                                                        </option>
                                                                        <option value="whatsapp_and_sms">
                                                                            WhatsApp
                                                                            +
                                                                            SMS
                                                                        </option>
                                                                        <option value="whatsapp_primary_sms_fallback">
                                                                            WhatsApp
                                                                            öncelik
                                                                            /
                                                                            SMS
                                                                            fallback
                                                                        </option>
                                                                        <option value="disabled">
                                                                            Kapalı
                                                                        </option>
                                                                    </select>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <div className="grid gap-2">
                                                                        <select
                                                                            value={
                                                                                input.whatsapp_mode
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                whatsapp_mode:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="disabled">
                                                                                Kapalı
                                                                            </option>
                                                                            <option value="test">
                                                                                Test
                                                                            </option>
                                                                            <option value="live">
                                                                                Live
                                                                                (bloklu)
                                                                            </option>
                                                                        </select>
                                                                        <select
                                                                            value={
                                                                                input.whatsapp_provider
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                whatsapp_provider:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="evo_whatsapp">
                                                                                Evo
                                                                                WhatsApp
                                                                            </option>
                                                                            <option value="null_local">
                                                                                Null
                                                                                Local
                                                                            </option>
                                                                        </select>
                                                                    </div>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <div className="grid gap-2">
                                                                        <select
                                                                            value={
                                                                                input.sms_mode
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                sms_mode:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="disabled">
                                                                                Kapalı
                                                                            </option>
                                                                            <option value="test">
                                                                                Test
                                                                            </option>
                                                                            <option value="live">
                                                                                Live
                                                                                (bloklu)
                                                                            </option>
                                                                        </select>
                                                                        <select
                                                                            value={
                                                                                input.sms_provider
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                sms_provider:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="nac_sms">
                                                                                NAC
                                                                                SMS
                                                                            </option>
                                                                            <option value="future_sms_provider">
                                                                                Future
                                                                                SMS
                                                                            </option>
                                                                            <option value="null_local">
                                                                                Null
                                                                                Local
                                                                            </option>
                                                                        </select>
                                                                    </div>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <select
                                                                        value={
                                                                            input.channel_policy
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            setMessageTypeInputs(
                                                                                {
                                                                                    ...messageTypeInputs,
                                                                                    [type.key]:
                                                                                        {
                                                                                            ...input,
                                                                                            channel_policy:
                                                                                                event
                                                                                                    .target
                                                                                                    .value,
                                                                                        },
                                                                                },
                                                                            )
                                                                        }
                                                                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                    >
                                                                        <option value="whatsapp_only">
                                                                            Sadece
                                                                            WhatsApp
                                                                        </option>
                                                                        <option value="sms_only">
                                                                            Sadece
                                                                            SMS
                                                                        </option>
                                                                        <option value="whatsapp_and_sms">
                                                                            WhatsApp
                                                                            +
                                                                            SMS
                                                                        </option>
                                                                        <option value="whatsapp_primary_sms_fallback">
                                                                            WhatsApp
                                                                            öncelik
                                                                            /
                                                                            SMS
                                                                            fallback
                                                                        </option>
                                                                        <option value="disabled">
                                                                            Kapalı
                                                                        </option>
                                                                    </select>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <div className="grid gap-2">
                                                                        <select
                                                                            value={
                                                                                input.whatsapp_mode
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                whatsapp_mode:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="disabled">
                                                                                Kapalı
                                                                            </option>
                                                                            <option value="test">
                                                                                Test
                                                                            </option>
                                                                            <option value="live">
                                                                                Live
                                                                                (bloklu)
                                                                            </option>
                                                                        </select>
                                                                        <select
                                                                            value={
                                                                                input.whatsapp_provider
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                whatsapp_provider:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="evo_whatsapp">
                                                                                Evo
                                                                                WhatsApp
                                                                            </option>
                                                                            <option value="null_local">
                                                                                Null
                                                                                Local
                                                                            </option>
                                                                        </select>
                                                                    </div>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <div className="grid gap-2">
                                                                        <select
                                                                            value={
                                                                                input.sms_mode
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                sms_mode:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="disabled">
                                                                                Kapalı
                                                                            </option>
                                                                            <option value="test">
                                                                                Test
                                                                            </option>
                                                                            <option value="live">
                                                                                Live
                                                                                (bloklu)
                                                                            </option>
                                                                        </select>
                                                                        <select
                                                                            value={
                                                                                input.sms_provider
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                sms_provider:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="nac_sms">
                                                                                NAC
                                                                                SMS
                                                                            </option>
                                                                            <option value="future_sms_provider">
                                                                                Future
                                                                                SMS
                                                                            </option>
                                                                            <option value="null_local">
                                                                                Null
                                                                                Local
                                                                            </option>
                                                                        </select>
                                                                    </div>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            input.template_key ??
                                                                            ''
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            setMessageTypeInputs(
                                                                                {
                                                                                    ...messageTypeInputs,
                                                                                    [type.key]:
                                                                                        {
                                                                                            ...input,
                                                                                            enabled:
                                                                                                Boolean(
                                                                                                    input.enabled,
                                                                                                ),
                                                                                            test_send_allowed:
                                                                                                Boolean(
                                                                                                    input.test_send_allowed,
                                                                                                ),
                                                                                            real_send_allowed:
                                                                                                Boolean(
                                                                                                    input.real_send_allowed,
                                                                                                ),
                                                                                            template_key:
                                                                                                event
                                                                                                    .target
                                                                                                    .value,
                                                                                            notes:
                                                                                                input.notes ??
                                                                                                '',
                                                                                        },
                                                                                },
                                                                            )
                                                                        }
                                                                        placeholder="REL-4C"
                                                                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                    />
                                                                </td>
                                                            </tr>
                                                        );
                                                    },
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        ) : null}

                        {activeAdminSection === 'messaging' &&
                        activeMessagingSection === 'message_types' ? (
                            <div className="mt-5 grid gap-4">
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p className="text-sm font-bold text-slate-950">
                                        Mesaj tipi ayarları
                                    </p>
                                    <p className="mt-1 text-sm leading-6 text-slate-600">
                                        Mesaj tipi ayarları provider
                                        bağımsızdır. Gerçek gönderim varsayılan
                                        kapalıdır; test modu açıkken hedef
                                        numara test telefonuna çevrilir.
                                    </p>
                                    <div className="mt-4 overflow-x-auto">
                                        <table className="min-w-[1280px] text-left text-sm">
                                            <thead className="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                                <tr>
                                                    <th className="px-3 py-2">
                                                        Mesaj tipi
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Aktif
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Test
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Gerçek
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Kanal politikası
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        WhatsApp
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        SMS
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Template key
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-200">
                                                {messaging.message_types.map(
                                                    (type) => {
                                                        const input =
                                                            messageTypeInputs[
                                                                type.key
                                                            ] ?? {
                                                                enabled: false,
                                                                test_send_allowed: false,
                                                                real_send_allowed: false,
                                                                channel_policy:
                                                                    'whatsapp_only',
                                                                whatsapp_mode:
                                                                    'test',
                                                                sms_mode:
                                                                    'disabled',
                                                                whatsapp_provider:
                                                                    'evo_whatsapp',
                                                                sms_provider:
                                                                    'nac_sms',
                                                                template_key:
                                                                    '',
                                                                notes: '',
                                                            };

                                                        return (
                                                            <tr
                                                                key={type.key}
                                                                className="align-top"
                                                            >
                                                                <td className="px-3 py-3">
                                                                    <p className="font-semibold text-slate-950">
                                                                        {
                                                                            type.label
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-xs leading-5 text-slate-600">
                                                                        {
                                                                            type.description
                                                                        }
                                                                    </p>
                                                                    <p className="mt-1 text-xs font-semibold text-slate-500">
                                                                        {
                                                                            type.recipient_role
                                                                        }
                                                                        {type.future
                                                                            ? ' / future'
                                                                            : ''}
                                                                    </p>
                                                                </td>
                                                                {(
                                                                    [
                                                                        'enabled',
                                                                        'test_send_allowed',
                                                                        'real_send_allowed',
                                                                    ] as const
                                                                ).map((key) => (
                                                                    <td
                                                                        key={
                                                                            key
                                                                        }
                                                                        className="px-3 py-3"
                                                                    >
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={Boolean(
                                                                                input[
                                                                                    key
                                                                                ],
                                                                            )}
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                enabled:
                                                                                                    Boolean(
                                                                                                        input.enabled,
                                                                                                    ),
                                                                                                test_send_allowed:
                                                                                                    Boolean(
                                                                                                        input.test_send_allowed,
                                                                                                    ),
                                                                                                real_send_allowed:
                                                                                                    Boolean(
                                                                                                        input.real_send_allowed,
                                                                                                    ),
                                                                                                template_key:
                                                                                                    input.template_key ??
                                                                                                    '',
                                                                                                notes:
                                                                                                    input.notes ??
                                                                                                    '',
                                                                                                [key]: event
                                                                                                    .target
                                                                                                    .checked,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                                                        />
                                                                    </td>
                                                                ))}
                                                                <td className="px-3 py-3">
                                                                    <select
                                                                        value={
                                                                            input.channel_policy
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            setMessageTypeInputs(
                                                                                {
                                                                                    ...messageTypeInputs,
                                                                                    [type.key]:
                                                                                        {
                                                                                            ...input,
                                                                                            channel_policy:
                                                                                                event
                                                                                                    .target
                                                                                                    .value,
                                                                                        },
                                                                                },
                                                                            )
                                                                        }
                                                                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                    >
                                                                        <option value="whatsapp_only">
                                                                            Sadece
                                                                            WhatsApp
                                                                        </option>
                                                                        <option value="sms_only">
                                                                            Sadece
                                                                            SMS
                                                                        </option>
                                                                        <option value="whatsapp_and_sms">
                                                                            WhatsApp
                                                                            +
                                                                            SMS
                                                                        </option>
                                                                        <option value="whatsapp_primary_sms_fallback">
                                                                            WhatsApp
                                                                            öncelik
                                                                            /
                                                                            SMS
                                                                            fallback
                                                                        </option>
                                                                        <option value="disabled">
                                                                            Kapalı
                                                                        </option>
                                                                    </select>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <div className="grid gap-2">
                                                                        <select
                                                                            value={
                                                                                input.whatsapp_mode
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                whatsapp_mode:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="disabled">
                                                                                Kapalı
                                                                            </option>
                                                                            <option value="test">
                                                                                Test
                                                                            </option>
                                                                            <option value="live">
                                                                                Live
                                                                                (bloklu)
                                                                            </option>
                                                                        </select>
                                                                        <select
                                                                            value={
                                                                                input.whatsapp_provider
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                whatsapp_provider:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="evo_whatsapp">
                                                                                Evo
                                                                                WhatsApp
                                                                            </option>
                                                                            <option value="null_local">
                                                                                Null
                                                                                Local
                                                                            </option>
                                                                        </select>
                                                                    </div>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <div className="grid gap-2">
                                                                        <select
                                                                            value={
                                                                                input.sms_mode
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                sms_mode:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="disabled">
                                                                                Kapalı
                                                                            </option>
                                                                            <option value="test">
                                                                                Test
                                                                            </option>
                                                                            <option value="live">
                                                                                Live
                                                                                (bloklu)
                                                                            </option>
                                                                        </select>
                                                                        <select
                                                                            value={
                                                                                input.sms_provider
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setMessageTypeInputs(
                                                                                    {
                                                                                        ...messageTypeInputs,
                                                                                        [type.key]:
                                                                                            {
                                                                                                ...input,
                                                                                                sms_provider:
                                                                                                    event
                                                                                                        .target
                                                                                                        .value,
                                                                                            },
                                                                                    },
                                                                                )
                                                                            }
                                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                        >
                                                                            <option value="nac_sms">
                                                                                NAC
                                                                                SMS
                                                                            </option>
                                                                            <option value="future_sms_provider">
                                                                                Future
                                                                                SMS
                                                                            </option>
                                                                            <option value="null_local">
                                                                                Null
                                                                                Local
                                                                            </option>
                                                                        </select>
                                                                    </div>
                                                                </td>
                                                                <td className="px-3 py-3">
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            input.template_key ??
                                                                            ''
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            setMessageTypeInputs(
                                                                                {
                                                                                    ...messageTypeInputs,
                                                                                    [type.key]:
                                                                                        {
                                                                                            ...input,
                                                                                            enabled:
                                                                                                Boolean(
                                                                                                    input.enabled,
                                                                                                ),
                                                                                            test_send_allowed:
                                                                                                Boolean(
                                                                                                    input.test_send_allowed,
                                                                                                ),
                                                                                            real_send_allowed:
                                                                                                Boolean(
                                                                                                    input.real_send_allowed,
                                                                                                ),
                                                                                            template_key:
                                                                                                event
                                                                                                    .target
                                                                                                    .value,
                                                                                            notes:
                                                                                                input.notes ??
                                                                                                '',
                                                                                        },
                                                                                },
                                                                            )
                                                                        }
                                                                        placeholder="REL-4C"
                                                                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                                                    />
                                                                </td>
                                                            </tr>
                                                        );
                                                    },
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        ) : null}

                        {activeAdminSection === 'messaging' &&
                        activeMessagingSection === 'voibot' ? (
                            <div className="mt-5 rounded-xl border border-amber-100 bg-amber-50 p-4 text-sm text-amber-900">
                                <p className="font-bold">
                                    Voibot sözleşme / API bekleniyor.
                                </p>
                                <p className="mt-1 leading-6">
                                    Bu fazda Voibot gerçek çağrısı yok. Voice
                                    script preview REL-4C şablon altyapısında
                                    kalır; sözleşme doğrulanırsa mevcut provider
                                    router’a açık bir provider olarak bağlanır.
                                </p>
                            </div>
                        ) : null}

                        {activeAdminSection === 'integrations' &&
                        !['evo', 'mikro_api', 'manual_e2e_portal'].includes(
                            activeIntegrationSection,
                        ) ? (
                            <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                                <p className="font-bold text-slate-950">
                                    {activeIntegrationSection ===
                                    'provider_credentials'
                                        ? 'Provider Credentials'
                                        : 'Operation Catalog'}
                                </p>
                                <p className="mt-1 leading-6">
                                    {activeIntegrationSection ===
                                    'provider_credentials'
                                        ? 'Provider credential yönetimi ilgili sağlayıcı kartlarında encrypted ve maskeli kalır. Secret değerleri bu ekranda tam gösterilmez.'
                                        : 'Operation Catalog INT/Mikro fazında bağlanacak. Bu fazda gerçek Mikro write veya provider proxy yok.'}
                                </p>
                            </div>
                        ) : null}

                        <div className="mt-4 grid gap-2 md:grid-cols-2">
                            {messaging.warnings.map((warning) => (
                                <p
                                    key={warning}
                                    className="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900"
                                >
                                    {warning}
                                </p>
                            ))}
                        </div>
                    </section>
                ) : null}

                {activeAdminSection === 'mail' ? (
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                                    Mail ayarları
                                </p>
                                <h2 className="mt-2 text-lg font-bold text-slate-950">
                                    SMTP gönderim, IMAP/POP3 gelen kutu bağlantı
                                    ayarları
                                </h2>
                                <p className="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                                    {mailSettings.helper_texts.outgoing}{' '}
                                    {mailSettings.helper_texts.incoming}{' '}
                                    {mailSettings.helper_texts.secrets}
                                </p>
                                {mailMessage ? (
                                    <p className="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">
                                        {mailMessage}
                                    </p>
                                ) : null}
                            </div>
                            <div
                                className={[
                                    'rounded-xl border px-4 py-3 text-sm font-semibold',
                                    mailSettings.payment_notification_ready
                                        ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                        : 'border-amber-100 bg-amber-50 text-amber-900',
                                ].join(' ')}
                            >
                                Ödeme maili:{' '}
                                {mailSettings.payment_notification_ready
                                    ? 'SMTP hazır'
                                    : 'SMTP eksik'}
                            </div>
                        </div>

                        <div className="mt-5 grid gap-4 xl:grid-cols-2">
                            <article className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p className="text-sm font-bold text-slate-950">
                                            Giden Mail / SMTP
                                        </p>
                                        <p className="mt-1 text-sm leading-6 text-slate-600">
                                            SMTP ödeme bildirimi ve test maili
                                            göndermek için kullanılır.
                                        </p>
                                    </div>
                                    <span
                                        className={[
                                            'rounded-lg border px-3 py-2 text-xs font-bold',
                                            mailSettings.outgoing.ready
                                                ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                : 'border-amber-100 bg-amber-50 text-amber-900',
                                        ].join(' ')}
                                    >
                                        {mailSettings.outgoing.status_label}
                                    </span>
                                </div>

                                <div className="mt-4 grid gap-3 md:grid-cols-2">
                                    <label className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 md:col-span-2">
                                        <input
                                            type="checkbox"
                                            checked={outgoingMailInputs.enabled}
                                            onChange={(event) =>
                                                setOutgoingMailInputs({
                                                    ...outgoingMailInputs,
                                                    enabled:
                                                        event.target.checked,
                                                })
                                            }
                                            className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                        />
                                        SMTP gönderimi aktif
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>SMTP host</span>
                                        <input
                                            type="text"
                                            value={outgoingMailInputs.host}
                                            onChange={(event) =>
                                                setOutgoingMailInputs({
                                                    ...outgoingMailInputs,
                                                    host: event.target.value,
                                                })
                                            }
                                            placeholder="smtp.example.com"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Port</span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="65535"
                                            value={outgoingMailInputs.port}
                                            onChange={(event) =>
                                                setOutgoingMailInputs({
                                                    ...outgoingMailInputs,
                                                    port: event.target.value,
                                                })
                                            }
                                            placeholder="587"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Şifreleme</span>
                                        <select
                                            value={
                                                outgoingMailInputs.encryption
                                            }
                                            onChange={(event) =>
                                                setOutgoingMailInputs({
                                                    ...outgoingMailInputs,
                                                    encryption: event.target
                                                        .value as
                                                        | 'tls'
                                                        | 'ssl'
                                                        | 'none',
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        >
                                            <option value="tls">TLS</option>
                                            <option value="ssl">SSL</option>
                                            <option value="none">None</option>
                                        </select>
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>From adresi</span>
                                        <input
                                            type="email"
                                            value={
                                                outgoingMailInputs.from_address
                                            }
                                            onChange={(event) =>
                                                setOutgoingMailInputs({
                                                    ...outgoingMailInputs,
                                                    from_address:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="no-reply@example.com"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Kullanıcı adı</span>
                                        <input
                                            type="text"
                                            value={outgoingMailInputs.username}
                                            onChange={(event) =>
                                                setOutgoingMailInputs({
                                                    ...outgoingMailInputs,
                                                    username:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder={
                                                mailSettings.outgoing
                                                    .username_mask ??
                                                'Değiştirmek için girin'
                                            }
                                            autoComplete="off"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Şifre / token</span>
                                        <input
                                            type="password"
                                            value={outgoingMailInputs.password}
                                            onChange={(event) =>
                                                setOutgoingMailInputs({
                                                    ...outgoingMailInputs,
                                                    password:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder={
                                                mailSettings.outgoing
                                                    .password_mask ??
                                                'Değiştirmek için girin'
                                            }
                                            autoComplete="new-password"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>From adı</span>
                                        <input
                                            type="text"
                                            value={outgoingMailInputs.from_name}
                                            onChange={(event) =>
                                                setOutgoingMailInputs({
                                                    ...outgoingMailInputs,
                                                    from_name:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="EMAKS Teknik Servis"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Test alıcısı</span>
                                        <input
                                            type="email"
                                            value={
                                                outgoingMailInputs.test_recipient
                                            }
                                            onChange={(event) =>
                                                setOutgoingMailInputs({
                                                    ...outgoingMailInputs,
                                                    test_recipient:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="payment-audit@example.com"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                </div>

                                <div className="mt-4 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        disabled={mailSaving}
                                        onClick={() => {
                                            void saveOutgoingMailSettings();
                                        }}
                                        className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {mailSaving
                                            ? 'Kaydediliyor'
                                            : 'SMTP ayarlarını kaydet'}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={
                                            mailTesting ||
                                            !outgoingMailInputs.test_recipient.trim()
                                        }
                                        onClick={() => {
                                            void sendOutgoingTestMail();
                                        }}
                                        className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {mailTesting
                                            ? 'Test ediliyor'
                                            : 'Test mail gönder'}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={mailSaving}
                                        onClick={() => {
                                            void clearOutgoingMailSettings();
                                        }}
                                        className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        SMTP bilgilerini temizle
                                    </button>
                                </div>
                                <p className="mt-3 text-xs leading-5 text-slate-600">
                                    {mailSettings.outgoing.readiness_message}
                                </p>
                                {mailSettings.outgoing.last_test_status ? (
                                    <p className="mt-1 text-xs font-semibold text-slate-500">
                                        Son test:{' '}
                                        {mailSettings.outgoing.last_test_status}{' '}
                                        —{' '}
                                        {
                                            mailSettings.outgoing
                                                .last_test_message
                                        }
                                    </p>
                                ) : null}
                            </article>

                            <article className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p className="text-sm font-bold text-slate-950">
                                            Gelen Mail / IMAP-POP3
                                        </p>
                                        <p className="mt-1 text-sm leading-6 text-slate-600">
                                            Bu ayarlar sadece gelen kutu
                                            bağlantı testi içindir; ödeme
                                            bildirimi SMTP ile gönderilir.
                                        </p>
                                    </div>
                                    <span
                                        className={[
                                            'rounded-lg border px-3 py-2 text-xs font-bold',
                                            mailSettings.incoming.ready
                                                ? 'border-emerald-100 bg-emerald-50 text-emerald-900'
                                                : 'border-amber-100 bg-amber-50 text-amber-900',
                                        ].join(' ')}
                                    >
                                        {mailSettings.incoming.status_label}
                                    </span>
                                </div>

                                <div className="mt-4 grid gap-3 md:grid-cols-2">
                                    <label className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 md:col-span-2">
                                        <input
                                            type="checkbox"
                                            checked={incomingMailInputs.enabled}
                                            onChange={(event) =>
                                                setIncomingMailInputs({
                                                    ...incomingMailInputs,
                                                    enabled:
                                                        event.target.checked,
                                                })
                                            }
                                            className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                        />
                                        Gelen kutu bağlantısı aktif
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Protokol</span>
                                        <select
                                            value={incomingMailInputs.protocol}
                                            onChange={(event) =>
                                                setIncomingMailInputs({
                                                    ...incomingMailInputs,
                                                    protocol: event.target
                                                        .value as
                                                        | 'imap'
                                                        | 'pop3',
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        >
                                            <option value="imap">IMAP</option>
                                            <option value="pop3">POP3</option>
                                        </select>
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Host</span>
                                        <input
                                            type="text"
                                            value={incomingMailInputs.host}
                                            onChange={(event) =>
                                                setIncomingMailInputs({
                                                    ...incomingMailInputs,
                                                    host: event.target.value,
                                                })
                                            }
                                            placeholder={
                                                incomingMailInputs.protocol ===
                                                'imap'
                                                    ? 'imap.example.com'
                                                    : 'pop3.example.com'
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Port</span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="65535"
                                            value={incomingMailInputs.port}
                                            onChange={(event) =>
                                                setIncomingMailInputs({
                                                    ...incomingMailInputs,
                                                    port: event.target.value,
                                                })
                                            }
                                            placeholder={
                                                incomingMailInputs.protocol ===
                                                'imap'
                                                    ? '993'
                                                    : '995'
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Şifreleme</span>
                                        <select
                                            value={
                                                incomingMailInputs.encryption
                                            }
                                            onChange={(event) =>
                                                setIncomingMailInputs({
                                                    ...incomingMailInputs,
                                                    encryption: event.target
                                                        .value as
                                                        | 'tls'
                                                        | 'ssl'
                                                        | 'none',
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        >
                                            <option value="tls">TLS</option>
                                            <option value="ssl">SSL</option>
                                            <option value="none">None</option>
                                        </select>
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Kullanıcı adı</span>
                                        <input
                                            type="text"
                                            value={incomingMailInputs.username}
                                            onChange={(event) =>
                                                setIncomingMailInputs({
                                                    ...incomingMailInputs,
                                                    username:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder={
                                                mailSettings.incoming
                                                    .username_mask ??
                                                'Değiştirmek için girin'
                                            }
                                            autoComplete="off"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">
                                        <span>Şifre / token</span>
                                        <input
                                            type="password"
                                            value={incomingMailInputs.password}
                                            onChange={(event) =>
                                                setIncomingMailInputs({
                                                    ...incomingMailInputs,
                                                    password:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder={
                                                mailSettings.incoming
                                                    .password_mask ??
                                                'Değiştirmek için girin'
                                            }
                                            autoComplete="new-password"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase md:col-span-2">
                                        <span>Mailbox / klasör</span>
                                        <input
                                            type="text"
                                            value={incomingMailInputs.mailbox}
                                            onChange={(event) =>
                                                setIncomingMailInputs({
                                                    ...incomingMailInputs,
                                                    mailbox: event.target.value,
                                                })
                                            }
                                            placeholder="INBOX"
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold tracking-normal text-slate-900 normal-case focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                                        />
                                    </label>
                                </div>

                                <div className="mt-4 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        disabled={mailSaving}
                                        onClick={() => {
                                            void saveIncomingMailSettings();
                                        }}
                                        className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {mailSaving
                                            ? 'Kaydediliyor'
                                            : 'IMAP/POP3 ayarlarını kaydet'}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={mailTesting}
                                        onClick={() => {
                                            void testIncomingMailSettings();
                                        }}
                                        className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {mailTesting
                                            ? 'Test ediliyor'
                                            : 'Bağlantıyı test et'}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={mailSaving}
                                        onClick={() => {
                                            void clearIncomingMailSettings();
                                        }}
                                        className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Gelen mail bilgilerini temizle
                                    </button>
                                </div>
                                <p className="mt-3 text-xs leading-5 text-slate-600">
                                    {mailSettings.incoming.readiness_message}
                                </p>
                                {mailSettings.incoming.last_test_status ? (
                                    <p className="mt-1 text-xs font-semibold text-slate-500">
                                        Son test:{' '}
                                        {mailSettings.incoming.last_test_status}{' '}
                                        —{' '}
                                        {
                                            mailSettings.incoming
                                                .last_test_message
                                        }
                                    </p>
                                ) : null}
                            </article>
                        </div>
                    </section>
                ) : null}

                {activeAdminSection === 'queue' ? (
                    <section className="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                            Kuyruk / Loglar
                        </p>
                        <h2 className="mt-2 text-lg font-bold text-slate-950">
                            Mesaj kuyruğu ve işlem logları
                        </h2>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            Modal aksiyonları burada dispatch olarak izlenir.
                            Provider gönderimi queue processor üzerinden yürür;
                            tablo maskeli, detay kaydı admin odaklıdır.
                        </p>
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div className="space-y-1">
                                <p className="text-xs font-bold text-slate-700">
                                    {queueBackgroundRefreshing
                                        ? 'Güncelleniyor...'
                                        : 'Kuyruk logları hazır'}
                                </p>
                                <p className="text-xs font-semibold text-slate-500">
                                    Son yenileme:{' '}
                                    {queueLastRefreshedAt || 'Henüz yok'}
                                </p>
                                {queueRefreshError ? (
                                    <p className="text-xs font-semibold text-rose-700">
                                        {queueRefreshError}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <label className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={queueAutoRefreshEnabled}
                                        onChange={(event) =>
                                            setQueueAutoRefreshEnabled(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    <span>Otomatik yenile</span>
                                </label>
                                <button
                                    type="button"
                                    onClick={() =>
                                        void loadDispatchQueue(queueFilters)
                                    }
                                    disabled={queueLoading}
                                    className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:bg-slate-100"
                                >
                                    {queueLoading ? 'Yükleniyor' : 'Yenile'}
                                </button>
                            </div>
                        </div>
                        <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-7">
                            {[
                                ['Kuyrukta', dispatchQueue.summary.queued],
                                ['Gönderiliyor', dispatchQueue.summary.sending],
                                ['Gönderildi', dispatchQueue.summary.sent],
                                ['Başarısız', dispatchQueue.summary.failed],
                                [
                                    'Tekrar engellendi',
                                    dispatchQueue.summary.duplicate_blocked,
                                ],
                                [
                                    'Limit bekliyor',
                                    dispatchQueue.summary.rate_limited,
                                ],
                                [
                                    'İptal edildi',
                                    dispatchQueue.summary.cancelled,
                                ],
                            ].map(([label, value]) => (
                                <div
                                    key={String(label)}
                                    className="rounded-xl border border-slate-200 bg-slate-50 p-3"
                                >
                                    <p className="text-xs font-semibold text-slate-500">
                                        {label}
                                    </p>
                                    <p className="mt-1 text-2xl font-bold text-slate-950">
                                        {value}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                                {(
                                    [
                                        [
                                            'Durum',
                                            'status',
                                            dispatchQueue.filters.statuses,
                                        ],
                                        [
                                            'Sağlayıcı',
                                            'provider',
                                            dispatchQueue.filters.providers,
                                        ],
                                        [
                                            'Kanal',
                                            'channel',
                                            dispatchQueue.filters.channels,
                                        ],
                                        [
                                            'Hedef Rol',
                                            'recipient_role',
                                            dispatchQueue.filters
                                                .recipient_roles,
                                        ],
                                        [
                                            'Mesaj',
                                            'message_type',
                                            dispatchQueue.filters.message_types,
                                        ],
                                    ] as const
                                ).map(([label, key, options]) => (
                                    <fieldset
                                        key={key}
                                        className="grid content-start gap-2 rounded-lg border border-slate-200 bg-white p-3"
                                    >
                                        <legend className="text-xs font-bold text-slate-700">
                                            {label}
                                        </legend>
                                        <div className="grid max-h-44 gap-1 overflow-y-auto pr-1">
                                            {options.map((option) => (
                                                <label
                                                    key={option.value}
                                                    className={[
                                                        'flex cursor-pointer items-center gap-2 rounded-md border px-2 py-1.5 text-xs font-semibold',
                                                        queueFilters[
                                                            key
                                                        ].includes(option.value)
                                                            ? 'border-slate-900 bg-slate-900 text-white'
                                                            : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-slate-300',
                                                    ].join(' ')}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={queueFilters[
                                                            key
                                                        ].includes(
                                                            option.value,
                                                        )}
                                                        onChange={() =>
                                                            toggleQueueMultiFilter(
                                                                key,
                                                                option.value,
                                                            )
                                                        }
                                                        className="h-3.5 w-3.5"
                                                    />
                                                    {option.label}
                                                </label>
                                            ))}
                                        </div>
                                    </fieldset>
                                ))}
                            </div>
                            {activeQueueFilterChips.length > 0 ? (
                                <div className="flex flex-wrap gap-2">
                                    {activeQueueFilterChips.map((chip) => (
                                        <span
                                            key={chip.key}
                                            className="rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-700"
                                        >
                                            {chip.label}
                                        </span>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-xs font-semibold text-slate-500">
                                    Aktif filtre yok. Varsayılan görünüm son 50
                                    dispatch kaydını listeler.
                                </p>
                            )}
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <label className="grid gap-1 text-xs font-semibold text-slate-600">
                                    <span>Başlangıç tarihi</span>
                                    <input
                                        type="date"
                                        value={queueFilters.date_from}
                                        onChange={(event) =>
                                            updateQueueFilter(
                                                'date_from',
                                                event.target.value,
                                            )
                                        }
                                        className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal text-slate-900"
                                    />
                                </label>
                                <label className="grid gap-1 text-xs font-semibold text-slate-600">
                                    <span>Bitiş tarihi</span>
                                    <input
                                        type="date"
                                        value={queueFilters.date_to}
                                        onChange={(event) =>
                                            updateQueueFilter(
                                                'date_to',
                                                event.target.value,
                                            )
                                        }
                                        className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal text-slate-900"
                                    />
                                </label>
                                <label className="grid gap-1 text-xs font-semibold text-slate-600">
                                    <span>MRN / SRV / pkgID / anahtar</span>
                                    <input
                                        type="search"
                                        value={queueFilters.q}
                                        onChange={(event) =>
                                            updateQueueFilter(
                                                'q',
                                                event.target.value,
                                            )
                                        }
                                        className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal text-slate-900"
                                        placeholder="MRN, SRV, provider id"
                                    />
                                </label>
                                <label className="grid gap-1 text-xs font-semibold text-slate-600">
                                    <span>Telefon arama</span>
                                    <input
                                        type="search"
                                        value={queueFilters.phone}
                                        onChange={(event) =>
                                            updateQueueFilter(
                                                'phone',
                                                event.target.value,
                                            )
                                        }
                                        className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal text-slate-900"
                                        placeholder="905..."
                                    />
                                </label>
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                {(
                                    [
                                        ['only_failed', 'Sadece hatalılar'],
                                        ['only_queued', 'Sadece kuyrukta'],
                                        ['only_test', 'Sadece test'],
                                        ['only_business', 'Sadece iş akışı'],
                                        [
                                            'only_duplicate_blocked',
                                            'Tekrar engellenenler',
                                        ],
                                    ] as const
                                ).map(([key, label]) => (
                                    <label
                                        key={key}
                                        className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={queueFilters[key]}
                                            onChange={(event) =>
                                                updateQueueFilter(
                                                    key,
                                                    event.target.checked,
                                                )
                                            }
                                        />
                                        <span>{label}</span>
                                    </label>
                                ))}
                                <button
                                    type="button"
                                    onClick={() =>
                                        void loadDispatchQueue(queueFilters)
                                    }
                                    disabled={queueLoading}
                                    className="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400"
                                >
                                    {queueLoading ? 'Yükleniyor' : 'Filtrele'}
                                </button>
                                <button
                                    type="button"
                                    onClick={clearQueueFilters}
                                    disabled={queueLoading}
                                    className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:bg-slate-100"
                                >
                                    Filtreleri temizle
                                </button>
                            </div>
                        </div>
                        <div className="overflow-hidden rounded-xl border border-slate-200">
                            <div className="grid grid-cols-11 gap-2 bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600">
                                <span>Tarih / Saat</span>
                                <span>Durum</span>
                                <span>Kanal</span>
                                <span>Sağlayıcı</span>
                                <span>Mesaj</span>
                                <span>Rol</span>
                                <span>Hedef</span>
                                <span>Referans</span>
                                <span>Deneme</span>
                                <span>Son hata</span>
                                <span>Detay</span>
                            </div>
                            {dispatchQueue.recent.length > 0 ? (
                                dispatchQueue.recent.map((dispatch) => (
                                    <div
                                        key={dispatch.id}
                                        className="grid grid-cols-11 gap-2 border-t border-slate-100 px-3 py-2 text-xs text-slate-700"
                                    >
                                        <span>
                                            {dispatch.display_time?.human ??
                                                '-'}
                                        </span>
                                        <span>
                                            <span
                                                className={[
                                                    'inline-flex rounded-full px-2 py-0.5 font-semibold',
                                                    dispatch.status_badge_tone ===
                                                    'success'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : dispatch.status_badge_tone ===
                                                            'danger'
                                                          ? 'bg-rose-100 text-rose-800'
                                                          : dispatch.status_badge_tone ===
                                                              'warning'
                                                            ? 'bg-amber-100 text-amber-800'
                                                            : dispatch.status_badge_tone ===
                                                                'info'
                                                              ? 'bg-sky-100 text-sky-800'
                                                              : 'bg-slate-100 text-slate-700',
                                                ].join(' ')}
                                            >
                                                {dispatch.status_label}
                                            </span>
                                        </span>
                                        <span>{dispatch.channel_label}</span>
                                        <span>{dispatch.provider_label}</span>
                                        <span className="min-w-0">
                                            <span className="block font-semibold text-slate-900">
                                                {dispatch.message_type_label}
                                            </span>
                                            {dispatch.message_preview ? (
                                                <span className="mt-1 block truncate text-[11px] font-medium text-slate-500">
                                                    {dispatch.message_preview}
                                                </span>
                                            ) : null}
                                        </span>
                                        <span>
                                            {dispatch.recipient_role_label}
                                        </span>
                                        <span>
                                            {dispatch.target_masked ?? '-'}
                                        </span>
                                        <span>{dispatch.reference ?? '-'}</span>
                                        <span>
                                            {dispatch.attempt_count}/
                                            {dispatch.max_attempts}
                                        </span>
                                        <span className="truncate">
                                            {dispatch.last_error_redacted ??
                                                '-'}
                                        </span>
                                        <span>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    void openDispatchDetail(
                                                        dispatch.id,
                                                    )
                                                }
                                                className="rounded-md border border-slate-300 px-2 py-1 font-semibold text-slate-700 hover:bg-slate-50"
                                            >
                                                Detay
                                            </button>
                                        </span>
                                    </div>
                                ))
                            ) : (
                                <p className="border-t border-slate-100 px-3 py-4 text-sm text-slate-600">
                                    Henüz dispatch log kaydı yok.
                                </p>
                            )}
                        </div>
                        <p className="text-xs font-semibold text-slate-500">
                            Toplam {dispatchQueue.pagination.total} kayıt, sayfa{' '}
                            {dispatchQueue.pagination.current_page}/
                            {dispatchQueue.pagination.last_page}. Liste
                            varsayılan olarak son{' '}
                            {dispatchQueue.pagination.per_page} kayıtla
                            sınırlıdır.
                        </p>
                        <div className="grid gap-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            {dispatchQueue.warnings.map((warning) => (
                                <p key={warning}>{warning}</p>
                            ))}
                            <p>
                                Force resend API nedeni zorunlu tutar;
                                telefonlar maskeli, provider kimlik doğrulama
                                bilgileri gizlidir.
                            </p>
                        </div>
                        {selectedDispatchDetail ? (
                            <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 p-4">
                                <div className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                                                Dispatch detayı
                                            </p>
                                            <h3 className="mt-1 text-lg font-bold text-slate-950">
                                                {
                                                    selectedDispatchDetail.message_type_label
                                                }
                                            </h3>
                                            <p className="mt-1 text-sm text-slate-600">
                                                {
                                                    selectedDispatchDetail
                                                        .display_time?.human
                                                }{' '}
                                                /{' '}
                                                {
                                                    selectedDispatchDetail.status_label
                                                }
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap justify-end gap-2">
                                            {messaging.manual_e2e.phase ===
                                                'prepared' &&
                                            messaging.manual_e2e.open_window ===
                                                null &&
                                            messaging.manual_e2e
                                                .active_claim === null &&
                                            selectedDispatchDetail.status ===
                                                'queued' &&
                                            selectedDispatchDetail.attempt_count ===
                                                0 ? (
                                                <button
                                                    type="button"
                                                    data-testid="manual-e2e-open-window-button"
                                                    disabled={
                                                        manualE2ELifecycleBusy
                                                    }
                                                    onClick={() => {
                                                        if (
                                                            typeof window ===
                                                                'undefined' ||
                                                            window.confirm(
                                                                `Yalnız dispatch #${selectedDispatchDetail.id} için kısa tek kullanımlık gönderim penceresi açılsın mı? Worker otomatik başlamaz.`,
                                                            )
                                                        ) {
                                                            void changeManualE2ESendWindow(
                                                                'open_send_window',
                                                                selectedDispatchDetail.id,
                                                            );
                                                        }
                                                    }}
                                                    className="rounded-lg border border-amber-700 bg-amber-700 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    Exact pencereyi aç
                                                </button>
                                            ) : null}
                                            {messaging.manual_e2e.open_window
                                                ?.dispatch_id ===
                                            selectedDispatchDetail.id ? (
                                                <button
                                                    type="button"
                                                    data-testid="manual-e2e-close-window-button"
                                                    disabled={
                                                        manualE2ELifecycleBusy
                                                    }
                                                    onClick={() => {
                                                        void changeManualE2ESendWindow(
                                                            'close_send_window',
                                                            selectedDispatchDetail.id,
                                                        );
                                                    }}
                                                    className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    Pencereyi kapat
                                                </button>
                                            ) : null}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setSelectedDispatchDetail(
                                                        null,
                                                    )
                                                }
                                                className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700"
                                            >
                                                Kapat
                                            </button>
                                        </div>
                                    </div>
                                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                                        {[
                                            [
                                                'Dispatch ID',
                                                selectedDispatchDetail.id,
                                            ],
                                            [
                                                'Durum',
                                                selectedDispatchDetail.status_label,
                                            ],
                                            [
                                                'Sağlayıcı',
                                                selectedDispatchDetail.provider_label,
                                            ],
                                            [
                                                'Kanal',
                                                selectedDispatchDetail.channel_label,
                                            ],
                                            [
                                                'Hedef rol',
                                                selectedDispatchDetail.recipient_role_label,
                                            ],
                                            [
                                                'Hedef telefon',
                                                selectedDispatchDetail.target_phone_masked ??
                                                    '-',
                                            ],
                                            [
                                                'Orijinal alıcı',
                                                selectedDispatchDetail.original_recipient_phone_masked ??
                                                    '-',
                                            ],
                                            [
                                                'Test yönlendirme',
                                                selectedDispatchDetail.test_redirect_applied
                                                    ? 'Uygulandı'
                                                    : 'Yok',
                                            ],
                                            [
                                                'Referans',
                                                selectedDispatchDetail.reference ??
                                                    '-',
                                            ],
                                            [
                                                'Oluşturulma',
                                                selectedDispatchDetail
                                                    .created_at?.human ?? '-',
                                            ],
                                            [
                                                'Kuyruğa alınma',
                                                selectedDispatchDetail.queued_at
                                                    ?.human ?? '-',
                                            ],
                                            [
                                                'Gönderim başladı',
                                                selectedDispatchDetail
                                                    .sending_started_at
                                                    ?.human ?? '-',
                                            ],
                                            [
                                                'Gönderildi',
                                                selectedDispatchDetail.sent_at
                                                    ?.human ?? '-',
                                            ],
                                            [
                                                'Başarısız oldu',
                                                selectedDispatchDetail.failed_at
                                                    ?.human ?? '-',
                                            ],
                                            [
                                                'Template',
                                                selectedDispatchDetail.template_label,
                                            ],
                                            [
                                                'Provider ID / pkgID',
                                                selectedDispatchDetail.provider_message_id ??
                                                    '-',
                                            ],
                                            [
                                                'Idempotency',
                                                selectedDispatchDetail.idempotency_label,
                                            ],
                                            [
                                                'Payload hash',
                                                selectedDispatchDetail.payload_hash_short ??
                                                    'Eski kayıt / payload hash yok',
                                            ],
                                            [
                                                'Provider body hash',
                                                selectedDispatchDetail.provider_payload_body_hash ??
                                                    'Provider body hash yok',
                                            ],
                                            [
                                                'Provider payload eşleşmesi',
                                                selectedDispatchDetail.provider_payload_warning ??
                                                    (selectedDispatchDetail.provider_payload_body_matches_dispatch ===
                                                    true
                                                        ? 'Dispatch body ile eşleşiyor'
                                                        : selectedDispatchDetail.provider_payload_body_matches_dispatch ===
                                                            false
                                                          ? 'Provider payload uyuşmazlığı'
                                                          : 'Eski kayıt / payload karşılaştırması yok'),
                                            ],
                                            [
                                                'Provider hedef rolü',
                                                [
                                                    selectedDispatchDetail.provider_request_recipient_role,
                                                    selectedDispatchDetail.provider_request_target_type,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' / ') || '-',
                                            ],
                                            [
                                                'Deneme',
                                                `${selectedDispatchDetail.attempt_count}/${selectedDispatchDetail.max_attempts}`,
                                            ],
                                            [
                                                'Force resend nedeni',
                                                selectedDispatchDetail.force_resend_reason ??
                                                    '-',
                                            ],
                                            [
                                                'Son hata',
                                                selectedDispatchDetail.last_error_redacted ??
                                                    '-',
                                            ],
                                        ].map(([label, value]) => (
                                            <div
                                                key={String(label)}
                                                className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm"
                                            >
                                                <p className="text-xs font-semibold text-slate-500">
                                                    {label}
                                                </p>
                                                <p className="mt-1 break-words text-slate-900">
                                                    {String(value ?? '-')}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="mt-4 grid gap-3">
                                        <div className="rounded-lg border border-slate-200 bg-white p-3">
                                            <p className="text-xs font-semibold text-slate-500">
                                                Mesaj içeriği
                                            </p>
                                            <pre className="mt-2 rounded-lg bg-slate-950 p-3 text-xs leading-5 whitespace-pre-wrap text-white">
                                                {
                                                    selectedDispatchDetail.rendered_message_content
                                                }
                                            </pre>
                                            {selectedDispatchDetail.message_content_missing_reason ? (
                                                <p className="mt-2 text-xs font-semibold text-amber-700">
                                                    {
                                                        selectedDispatchDetail.message_content_missing_reason
                                                    }
                                                </p>
                                            ) : null}
                                            {selectedDispatchDetail.message_content_source ? (
                                                <p className="mt-2 text-xs text-slate-500">
                                                    Kaynak:{' '}
                                                    {
                                                        selectedDispatchDetail.message_content_source
                                                    }
                                                </p>
                                            ) : null}
                                        </div>
                                        {selectedDispatchDetail.provider_request_preview ? (
                                            <div className="rounded-lg border border-slate-200 bg-white p-3">
                                                <p className="text-xs font-semibold text-slate-500">
                                                    Provider request önizleme
                                                </p>
                                                <p className="mt-2 text-sm leading-6 whitespace-pre-wrap text-slate-800">
                                                    {
                                                        selectedDispatchDetail.provider_request_preview
                                                    }
                                                </p>
                                            </div>
                                        ) : null}
                                        <div className="rounded-lg border border-slate-200 bg-white p-3">
                                            <p className="text-xs font-semibold text-slate-500">
                                                Redakte provider yanıtı
                                            </p>
                                            <pre className="mt-2 max-h-52 overflow-auto rounded-lg bg-slate-100 p-3 text-xs leading-5 text-slate-800">
                                                {JSON.stringify(
                                                    selectedDispatchDetail.provider_response_redacted ??
                                                        {},
                                                    null,
                                                    2,
                                                )}
                                            </pre>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 bg-white p-3">
                                            <p className="text-xs font-semibold text-slate-500">
                                                Teknik anahtar
                                            </p>
                                            <pre className="mt-2 max-h-44 overflow-auto rounded-lg bg-slate-100 p-3 text-xs leading-5 text-slate-800">
                                                {JSON.stringify(
                                                    selectedDispatchDetail.technical_keys,
                                                    null,
                                                    2,
                                                )}
                                            </pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ) : null}
                        {queueDetailLoading ? (
                            <p className="text-sm font-semibold text-slate-600">
                                Dispatch detayı yükleniyor.
                            </p>
                        ) : null}
                    </section>
                ) : null}

                {activeAdminSection === 'overview' ? (
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                            OPS detay görünürlüğü
                        </p>
                        <h2 className="mt-2 text-lg font-bold text-slate-950">
                            Gelişmiş kontrol blokları varsayılan olarak gizli.
                        </h2>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            Bu ayarlar sadece operasyon detay ekranındaki eski
                            büyük kontrol bloklarının görünürlüğünü belirler. İş
                            kuralı ve backend doğrulaması bu görünürlükten
                            bağımsız kalır.
                        </p>
                        <div className="mt-4 grid gap-3 lg:grid-cols-3">
                            {opsDetailToggles.map((item) => (
                                <label
                                    key={item.key}
                                    className="grid cursor-pointer gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-800"
                                >
                                    <span className="flex items-start gap-3">
                                        <input
                                            type="checkbox"
                                            checked={
                                                opsDetailVisibility[item.key]
                                            }
                                            disabled={saving}
                                            onChange={(event) => {
                                                void updateOpsDetailVisibility(
                                                    item.key,
                                                    event.target.checked,
                                                );
                                            }}
                                            className="mt-0.5 h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                        />
                                        <span>
                                            <span className="block font-semibold">
                                                {item.label}
                                            </span>
                                            <span className="mt-1 block leading-5 text-slate-600">
                                                {item.description}
                                            </span>
                                        </span>
                                    </span>
                                    <span className="text-xs font-semibold text-slate-500">
                                        {opsDetailVisibility[item.key]
                                            ? 'Açık'
                                            : 'Kapalı'}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </section>
                ) : null}

                {activeAdminSection === 'overview' ? (
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">
                            Yetki merkezi
                        </p>
                        <h2 className="mt-2 text-lg font-bold text-slate-950">
                            Teknik servis erişimleri kullanıcı bazında atanır.
                        </h2>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            Bu ekran sadece technical_service_admin yetkisi olan
                            kullanıcılar için görünür. Kullanıcı ekran
                            erişimleri, hakediş ödeme aksiyonu ve teknisyen
                            yönetimi {'Admin > Kullanıcı Yönetimi'} içinde ayrı
                            kaynaklar olarak seçilebilir.
                        </p>
                    </section>
                ) : null}


                <Dialog
                    open={manualE2EEnableConfirmationOpen}
                    onOpenChange={setManualE2EEnableConfirmationOpen}
                >
                    <DialogContent
                        data-testid="manual-e2e-enable-confirmation"
                        className="max-h-[90vh] overflow-y-auto sm:max-w-xl"
                    >
                        <DialogHeader>
                            <DialogTitle>Manual E2E run hazırlığı</DialogTitle>
                            <DialogDescription>
                                Bu işlem yalnız güvenli run context oluşturur.
                                Gerçek gönderim kapalı, queue duraklatılmış ve
                                provider worker kapalı kalır.
                            </DialogDescription>
                        </DialogHeader>

                        {manualE2EReadiness ? (
                            <div className="grid gap-4">
                                <div className="border-l-4 border-emerald-600 bg-emerald-50 px-4 py-3 text-sm leading-6 font-semibold text-emerald-950">
                                    Hazırlık provider kapılarını açmaz. Her dış
                                    gönderim için Queue / Logs içinden exact
                                    dispatch ayrıca seçilmeli ve kısa pencere
                                    server tarafından doğrulanmalıdır.
                                </div>

                                <dl className="grid gap-x-4 gap-y-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="font-semibold text-slate-500">
                                            Müşteri allowlist
                                        </dt>
                                        <dd className="mt-1 font-semibold break-words text-slate-950">
                                            {manualE2EReadiness
                                                .customer_allowlisted_phone_masks
                                                .length > 0
                                                ? manualE2EReadiness.customer_allowlisted_phone_masks.join(
                                                      ', ',
                                                  )
                                                : 'Yok'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="font-semibold text-slate-500">
                                            Test Usta / OPS telefonu
                                        </dt>
                                        <dd className="mt-1 font-semibold break-words text-slate-950">
                                            {manualE2EReadiness.ops_whatsapp_phone_mask ??
                                                'Yok'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="font-semibold text-slate-500">
                                            Provider readiness
                                        </dt>
                                        <dd className="mt-1 font-semibold text-slate-950">
                                            Evo:{' '}
                                            {manualE2EReadiness.evo_ready
                                                ? 'Hazır'
                                                : 'Eksik'}{' '}
                                            / NAC:{' '}
                                            {manualE2EReadiness.nac_ready
                                                ? 'Hazır'
                                                : 'Eksik'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="font-semibold text-slate-500">
                                            OPS SMS
                                        </dt>
                                        <dd className="mt-1 font-semibold text-slate-950">
                                            Kapalı
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="font-semibold text-slate-500">
                                            TTL
                                        </dt>
                                        <dd className="mt-1 font-semibold text-slate-950">
                                            {manualE2EReadiness.ttl_seconds}{' '}
                                            saniye
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="font-semibold text-slate-500">
                                            Pending provider
                                        </dt>
                                        <dd className="mt-1 font-semibold text-slate-950">
                                            {
                                                manualE2EReadiness.pending_external_count
                                            }
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="font-semibold text-slate-500">
                                            Unsafe dispatch
                                        </dt>
                                        <dd className="mt-1 font-semibold text-slate-950">
                                            {
                                                manualE2EReadiness.unsafe_external_count
                                            }
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="font-semibold text-slate-500">
                                            Worker durumu
                                        </dt>
                                        <dd className="mt-1 font-semibold text-slate-950">
                                            {manualE2EReadiness.worker_state ===
                                            'active'
                                                ? 'Aktif'
                                                : manualE2EReadiness.worker_stale_recoverable
                                                  ? 'Stale lock; owner/run kontrolüyle temizlenecek'
                                                  : 'Worker yok'}
                                        </dd>
                                    </div>
                                </dl>

                                <div>
                                    <p className="text-sm font-semibold text-slate-950">
                                        Aktif kanal politikaları
                                    </p>
                                    <div className="mt-2 max-h-32 overflow-y-auto border-y border-slate-200 py-2 text-xs leading-5 text-slate-700">
                                        {manualE2EReadiness.channel_policies
                                            .length > 0
                                            ? manualE2EReadiness.channel_policies.map(
                                                  (policy) => (
                                                      <p
                                                          key={
                                                              policy.message_type
                                                          }
                                                      >
                                                          {policy.message_type}:{' '}
                                                          {
                                                              policy.channel_policy
                                                          }
                                                      </p>
                                                  ),
                                              )
                                            : 'Aktif mesaj politikası yok.'}
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <p className="text-sm font-semibold text-amber-800">
                                Readiness sonucu yok. Modalı kapatıp önce
                                readiness kontrolünü çalıştırın.
                            </p>
                        )}

                        <DialogFooter>
                            <button
                                type="button"
                                disabled={manualE2ELifecycleBusy}
                                onClick={() =>
                                    setManualE2EEnableConfirmationOpen(false)
                                }
                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Vazgeç
                            </button>
                            <button
                                type="button"
                                data-testid="manual-e2e-confirm-enable-button"
                                disabled={
                                    manualE2ELifecycleBusy ||
                                    !manualE2EReadiness?.eligible
                                }
                                onClick={() => {
                                    void prepareManualE2E();
                                }}
                                className="rounded-lg border border-red-700 bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {manualE2ELifecycleBusy
                                    ? 'Hazırlanıyor'
                                    : 'Güvenli run hazırlığını başlat'}
                            </button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}

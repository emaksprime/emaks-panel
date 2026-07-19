<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceRequest;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Support\PartnerPortalPublicUrl;

class TechnicalServiceTechnicianPortalLinkResolver
{
    public function __construct(
        private readonly B2BPartnerServiceJobScopeService $jobScope,
        private readonly TechnicalServiceMessageIdempotencyService $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $dispatchMetadata
     * @return array<string, mixed>
     */
    public function resolveForDispatch(
        TechnicalServiceRequest $request,
        array $settings,
        string $recipientRole,
        string $targetPhone,
        array $dispatchMetadata = [],
    ): array {
        $scope = $this->jobScope->technicianJobCardContext($request);
        if (! (bool) ($scope['ready'] ?? false)) {
            return $this->blocked(
                $scope,
                (string) ($scope['blocker_code'] ?? 'active_assignment_partner_missing'),
                (string) ($scope['blocker_message'] ?? 'Aktif atamaya bağlı usta iş kartı bulunamadı.'),
            );
        }

        $global = (array) ($settings['global'] ?? []);
        if ((bool) ($global['test_mode_enabled'] ?? false)) {
            $origin = PartnerPortalPublicUrl::normalizeBaseUrl(PartnerPortalPublicUrl::baseUrl());

            return $origin !== null
                ? $this->ready($scope, $origin, 'configured_test_portal_origin', 'test_preview')
                : $this->blocked($scope, 'public_url_missing', 'Test önizleme için partner portal adresi bulunamadı.');
        }

        if ($this->manualE2ELanOverrideAllowed($global, $dispatchMetadata, $recipientRole, $targetPhone)) {
            $origin = PartnerPortalPublicUrl::normalizeOrigin((string) ($global['manual_e2e_partner_portal_origin'] ?? ''));
            if ($origin !== null && PartnerPortalPublicUrl::isPrivateLanOrigin($origin)) {
                return $this->ready($scope, $origin, 'admin_manual_e2e_partner_portal_origin', 'manual_e2e_local');
            }
        }

        $publicOrigin = PartnerPortalPublicUrl::baseUrl();
        if (PartnerPortalPublicUrl::isPublicHttpsUrl($publicOrigin)) {
            return $this->ready($scope, $publicOrigin, 'configured_partner_portal_public_url', 'public_live');
        }

        return $this->blocked(
            $scope,
            'public_url_missing',
            'Usta iş kartı için public HTTPS veya guarded Manual E2E LAN origin hazır değil.',
        );
    }

    /**
     * @param  array<string, mixed>  $global
     * @param  array<string, mixed>  $metadata
     */
    private function manualE2ELanOverrideAllowed(
        array $global,
        array $metadata,
        string $recipientRole,
        string $targetPhone,
    ): bool {
        if (! app()->environment('local', 'testing')
            || $recipientRole !== 'technician'
            || ! (bool) ($global['manual_e2e_enabled'] ?? false)
            || ! (bool) ($global['manual_e2e_partner_portal_origin_enabled'] ?? false)
            || ! (bool) ($metadata['manual_e2e'] ?? false)
            || ! (bool) ($metadata['allowlisted_target'] ?? false)
            || ($metadata['recipient_role_expected'] ?? null) !== 'technician'
        ) {
            return false;
        }

        $runContext = TechnicalServiceManualE2ERunContext::fromSettings($global);
        $normalizedTarget = $this->idempotency->normalizePhone($targetPhone);
        $metadataTarget = $this->idempotency->normalizePhone((string) ($metadata['effective_target_phone'] ?? ''));

        return $runContext->isActive()
            && TechnicalServiceManualE2ERunContext::dispatchRunId($metadata) === $runContext->activeRunId()
            && $runContext->dispatchBlockingReason($normalizedTarget) === null
            && $normalizedTarget !== ''
            && $metadataTarget === $normalizedTarget;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function ready(array $scope, string $origin, string $source, string $mode): array
    {
        return [
            ...$scope,
            'ready' => true,
            'origin' => rtrim($origin, '/'),
            'source' => $source,
            'mode' => $mode,
            'canonical_url' => $this->url($origin, (string) $scope['canonical_path']),
            'short_url' => $this->url($origin, (string) $scope['short_path']),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function blocked(array $scope, string $code, string $message): array
    {
        return [
            ...$scope,
            'ready' => false,
            'blocker_code' => $code,
            'blocker_message' => $message,
            'origin' => null,
            'source' => null,
            'mode' => null,
            'canonical_url' => null,
            'short_url' => null,
        ];
    }

    private function url(string $origin, string $path): string
    {
        return rtrim($origin, '/').'/'.ltrim($path, '/');
    }
}

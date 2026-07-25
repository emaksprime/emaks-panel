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
        $profile = PartnerPortalPublicUrl::profile();
        $origin = PartnerPortalPublicUrl::normalizeOrigin(is_string($profile['origin'] ?? null) ? $profile['origin'] : null);
        if ((bool) ($profile['ready'] ?? false) && $origin !== null) {
            $manualE2E = $this->manualE2ELanOverrideAllowed($global, $dispatchMetadata, $recipientRole, $targetPhone);
            $mode = $manualE2E
                ? 'manual_e2e_local'
                : (PartnerPortalPublicUrl::isPublicHttpsUrl($origin) ? 'public_live' : 'local_preview');

            return $this->ready(
                $scope,
                $origin,
                (string) ($profile['origin_source'] ?? 'public_origin_profile'),
                $mode,
            );
        }

        return $this->blocked(
            $scope,
            (string) ($profile['blocker_code'] ?? 'public_url_missing'),
            (string) ($profile['blocker_message'] ?? 'Usta iş kartı için environment-bound public origin hazır değil.'),
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

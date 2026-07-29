<?php

namespace Tests\Support;

use App\Models\PageConfig;
use App\Models\User;
use App\Services\ExternalEffects\ExternalEffectCapabilityRegistry;
use App\Services\ExternalEffects\ExternalExecutionControlPlaneService;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

trait InteractsWithExternalExecutionControlPlane
{
    private const MANUAL_E2E_LOCAL_PUBLIC_ORIGIN = 'http://127.0.0.1:8000';

    private bool $manualE2ELocalPublicProfileConfigured = false;

    /**
     * Establishes the parent precondition for messaging adapter contract tests.
     * Global transition/default-deny behavior is tested separately through its API.
     *
     * @return array<string, mixed>
     */
    protected function activateGlobalLiveForMessagingAdapterFixture(
        TechnicalServiceMessagingSettingsService $settings,
        ?User $actor = null,
        bool $withLocalPublicProfile = true,
    ): array {
        if ($withLocalPublicProfile) {
            $this->configureAcceptedLocalPublicProfileFixture();
        }

        $controlPlane = app(ExternalExecutionControlPlaneService::class);
        $current = $controlPlane->state();
        $profileFingerprint = (string) data_get(
            $controlPlane->payload(),
            'readiness.profile_fingerprint',
            '',
        );

        $settings->withGlobalExecutionControlLock(function () use (
            $settings,
            $actor,
            $controlPlane,
            $current,
            $profileFingerprint,
        ): void {
            DB::transaction(function () use (
                $settings,
                $actor,
                $controlPlane,
                $current,
                $profileFingerprint,
            ): void {
                $page = PageConfig::query()->firstOrCreate(
                    ['page_code' => ExternalExecutionControlPlaneService::PAGE_CODE],
                    ['layout_json' => []],
                );
                $page = PageConfig::query()->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
                $layout = is_array($page->layout_json) ? $page->layout_json : [];
                Arr::set($layout, ExternalExecutionControlPlaneService::ROOT_KEY, [
                    'operator_mode' => ExternalExecutionControlPlaneService::MODE_LIVE,
                    'transition_state' => ExternalExecutionControlPlaneService::STATE_LIVE,
                    'epoch' => (int) $current['epoch'] + 1,
                    'revision' => (int) $current['revision'] + 1,
                    'environment' => $controlPlane->runtimeEnvironment(),
                    'profile_fingerprint' => $profileFingerprint,
                    'registry_version' => ExternalEffectCapabilityRegistry::VERSION,
                    'changed_at' => now('UTC')->toIso8601String(),
                    'changed_by' => $actor?->getKey(),
                    'reason' => 'Messaging adapter contract fixture.',
                    'correlation_id' => 'TEST-MESSAGING-ADAPTER-FIXTURE',
                ]);
                $page->forceFill(['layout_json' => $layout])->save();

                $settings->applyGlobalExecutionModeWithinTransaction(
                    ExternalExecutionControlPlaneService::MODE_LIVE,
                );
            });
        });

        return $controlPlane->payload();
    }

    private function configureAcceptedLocalPublicProfileFixture(): void
    {
        if ($this->manualE2ELocalPublicProfileConfigured) {
            return;
        }

        $key = 'services.partner_portal.public_url';
        $previousOrigin = config($key);
        $configuredOrigin = trim((string) $previousOrigin);

        config()->set(
            $key,
            $configuredOrigin !== '' ? $configuredOrigin : self::MANUAL_E2E_LOCAL_PUBLIC_ORIGIN,
        );
        $this->manualE2ELocalPublicProfileConfigured = true;

        $this->beforeApplicationDestroyed(function () use ($key, $previousOrigin): void {
            config()->set($key, $previousOrigin);
            $this->manualE2ELocalPublicProfileConfigured = false;
        });
    }
}

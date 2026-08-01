<?php

namespace App\Services\ExternalEffects;

use App\Models\AuditLog;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Support\PartnerPortalPublicUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ExternalExecutionControlPlaneService
{
    public const PAGE_CODE = 'external_execution_control_plane';

    public const ROOT_KEY = 'external_execution.control_plane';

    public const MODE_LOCAL = 'local';

    public const MODE_LIVE = 'live';

    public const STATE_LOCAL = 'local';

    public const STATE_ACTIVATING = 'activating';

    public const STATE_LIVE = 'live';

    public const STATE_FREEZING = 'freezing';

    public const STATE_BLOCKED = 'blocked';

    public function __construct(
        private readonly ExternalEffectCapabilityRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $state = $this->state();
        $readiness = $this->readiness($state);
        $scopedLocalUat = $this->scopedLocalUatReadiness();
        $publicOrigin = $this->publicOriginProfile($state);
        $publicOrigin['legacy_stale_url_count'] = $this->legacyStalePublicUrlCount();
        $changedById = is_numeric($state['changed_by'] ?? null) ? (int) $state['changed_by'] : null;
        $changedBy = $changedById === null ? null : User::query()->find($changedById);

        return [
            'mode' => $state['operator_mode'],
            'state' => $state['transition_state'],
            'epoch' => $state['epoch'],
            'revision' => $state['revision'],
            'runtime_environment' => $this->runtimeEnvironment(),
            'runtime_environment_label' => match ($this->runtimeEnvironment()) {
                'production' => 'Production',
                'staging' => 'UAT / Staging',
                default => 'Lokal',
            },
            'profile_fingerprint' => $state['profile_fingerprint'],
            'registry_version' => ExternalEffectCapabilityRegistry::VERSION,
            'changed_at' => $state['changed_at'],
            'changed_by' => $changedBy === null ? null : [
                'id' => $changedBy->getKey(),
                'name' => (string) $changedBy->full_name,
            ],
            'reason' => $state['reason'],
            'correlation_id' => $state['correlation_id'],
            'public_origin' => $publicOrigin,
            'readiness' => $readiness,
            'readiness_profiles' => [
                'global_live' => [
                    ...$readiness,
                    'ready' => (bool) $readiness['eligible'],
                    'production_ready' => $this->runtimeEnvironment() === 'production'
                        && (bool) $readiness['eligible'],
                ],
                'local_allowlisted_uat' => $scopedLocalUat,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transition(
        string $mode,
        string $reason,
        User $actor,
        int $expectedRevision,
        ?string $confirmation = null,
        ?string $correlationId = null,
    ): array {
        $mode = trim(strtolower($mode));
        $reason = $this->sanitizeReason($reason);
        if (! in_array($mode, [self::MODE_LOCAL, self::MODE_LIVE], true)) {
            throw ValidationException::withMessages([
                'mode' => 'Sistem çalışma modu yalnız local veya live olabilir.',
            ]);
        }
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Çalışma modu değişikliği için açıklama zorunlu.',
            ]);
        }
        if ($mode === self::MODE_LIVE && $confirmation !== 'CANLI MODU AÇ') {
            throw ValidationException::withMessages([
                'confirmation' => 'Canlı mod için CANLI MODU AÇ onayı zorunlu.',
            ]);
        }

        $messaging = app(TechnicalServiceMessagingSettingsService::class);
        $correlationId = $this->safeCorrelationId($correlationId);
        $activeRunId = null;

        $messaging->withGlobalExecutionControlLock(function () use (
            $mode,
            $reason,
            $actor,
            $expectedRevision,
            $correlationId,
            $messaging,
            &$activeRunId,
        ): void {
            DB::transaction(function () use (
                $mode,
                $reason,
                $actor,
                $expectedRevision,
                $correlationId,
                $messaging,
                &$activeRunId,
            ): void {
                $page = $this->lockedPageConfig();
                $current = $this->stateFromLayout((array) $page->layout_json);
                if ($expectedRevision !== (int) $current['revision']) {
                    throw new ConflictHttpException('Çalışma modu başka bir yönetici tarafından değiştirildi. Güncel durumu yeniden yükleyip kararınızı tekrar verin.');
                }

                $readiness = $this->readiness($current, false);
                if ($mode === self::MODE_LIVE && ! (bool) $readiness['eligible']) {
                    throw ValidationException::withMessages([
                        'mode' => collect($readiness['blockers'])
                            ->map(fn (array $blocker): string => '['.$blocker['code'].'] '.$blocker['message'])
                            ->all(),
                    ]);
                }

                $changedAt = CarbonImmutable::now()->toIso8601String();
                $next = [
                    'operator_mode' => $mode,
                    'transition_state' => $mode === self::MODE_LIVE ? self::STATE_LIVE : self::STATE_LOCAL,
                    'epoch' => (int) $current['epoch'] + 1,
                    'revision' => (int) $current['revision'] + 1,
                    'environment' => $this->runtimeEnvironment(),
                    'profile_fingerprint' => (string) $readiness['profile_fingerprint'],
                    'registry_version' => ExternalEffectCapabilityRegistry::VERSION,
                    'changed_at' => $changedAt,
                    'changed_by' => $actor->getKey(),
                    'reason' => $reason,
                    'correlation_id' => $correlationId,
                ];

                $layout = is_array($page->layout_json) ? $page->layout_json : [];
                Arr::set($layout, self::ROOT_KEY, $next);
                $page->forceFill(['layout_json' => $layout])->save();

                $adapterTransition = $messaging->applyGlobalExecutionModeWithinTransaction($mode);
                $activeRunId = $adapterTransition['active_run_id'] ?? null;

                AuditLog::query()->create([
                    'user_id' => $actor->getKey(),
                    'action' => 'external_execution_control.mode.changed',
                    'payload' => [
                        'runtime_environment' => $this->runtimeEnvironment(),
                        'previous_mode' => $current['operator_mode'],
                        'previous_state' => $current['transition_state'],
                        'previous_epoch' => $current['epoch'],
                        'previous_revision' => $current['revision'],
                        'new_mode' => $next['operator_mode'],
                        'new_state' => $next['transition_state'],
                        'new_epoch' => $next['epoch'],
                        'new_revision' => $next['revision'],
                        'reason' => $reason,
                        'correlation_id' => $correlationId,
                        'required_blocker_codes' => array_column((array) $readiness['blockers'], 'code'),
                        'changed_at' => $changedAt,
                        'redacted_adapter_diff' => $adapterTransition['redacted_diff'] ?? [],
                    ],
                    'created_at' => now('UTC'),
                ]);
            });
        });

        if ($mode === self::MODE_LOCAL && is_string($activeRunId) && $activeRunId !== '') {
            $messaging->invalidateGlobalExecutionRunLease($activeRunId);
        }

        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $layout = PageConfig::query()
            ->where('page_code', self::PAGE_CODE)
            ->value('layout_json');

        return $this->stateFromLayout(is_array($layout) ? $layout : []);
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>
     */
    public function publicOriginProfile(?array $state = null): array
    {
        $state ??= $this->state();

        return PartnerPortalPublicUrl::resolveProfile(
            $this->runtimeEnvironment(),
            (string) ($state['operator_mode'] ?? self::MODE_LOCAL),
            (string) ($state['transition_state'] ?? self::STATE_LOCAL),
        );
    }

    /**
     * A separate, non-production readiness profile. It does not change or
     * satisfy the global LOCAL-to-LIVE transition contract.
     *
     * @return array<string, mixed>
     */
    public function scopedLocalUatReadiness(bool $checkLifecycleLock = true): array
    {
        $state = $this->state();
        $profile = $this->registry->localAllowlistedUatProfile();
        $inputs = app(TechnicalServiceMessagingSettingsService::class)
            ->scopedLocalUatControlPlaneState($checkLifecycleLock);
        $capabilities = (array) ($inputs['capabilities'] ?? []);
        $blockers = array_values((array) ($inputs['invariant_blockers'] ?? []));

        if ($this->runtimeEnvironment() === 'production') {
            $blockers[] = [
                'code' => 'scoped_uat_production_forbidden',
                'message' => 'Allowlistli Yerel UAT production ortamında açılamaz.',
            ];
        }
        if (($state['operator_mode'] ?? null) !== self::MODE_LOCAL
            || ($state['transition_state'] ?? null) !== self::STATE_LOCAL) {
            $blockers[] = [
                'code' => 'scoped_uat_requires_global_local',
                'message' => 'Allowlistli Yerel UAT yalnız global Lokal durumda hazırlanabilir.',
            ];
        }

        $required = array_values((array) $profile['required_capabilities']);
        $ready = [];
        $missing = [];
        foreach ($required as $code) {
            $status = is_array($capabilities[$code] ?? null) ? $capabilities[$code] : [];
            if ((bool) ($status['ready'] ?? false)) {
                $ready[] = $code;

                continue;
            }

            $missing[] = $code;
            $blockers[] = [
                'code' => 'scoped_capability_not_ready:'.$code,
                'capability' => $code,
                'message' => $code.' allowlistli Yerel UAT için hazır değil.',
            ];
        }

        $global = $this->readiness($state, false);
        $unrelatedGlobalBlockers = collect((array) ($global['blockers'] ?? []))
            ->reject(function (array $blocker) use ($required): bool {
                $capability = is_scalar($blocker['capability'] ?? null)
                    ? (string) $blocker['capability']
                    : '';

                return in_array($capability, $required, true);
            })
            ->values()
            ->all();

        return [
            'profile_id' => $profile['id'],
            'profile_version' => $profile['version'],
            'profile_fingerprint' => $profile['profile_fingerprint'],
            'ready' => $blockers === [],
            'eligible' => $blockers === [],
            'production_ready' => false,
            'classification' => $blockers === []
                ? 'Allowlistli Yerel UAT için hazır'
                : 'Allowlistli Yerel UAT blockerları',
            'required_capabilities' => $required,
            'ready_capabilities' => $ready,
            'missing_capabilities' => $missing,
            'capabilities' => $capabilities,
            'invariant_blockers' => array_values((array) ($inputs['invariant_blockers'] ?? [])),
            'blockers' => $blockers,
            'unrelated_global_blockers' => $unrelatedGlobalBlockers,
            'global_live_ready' => (bool) ($global['eligible'] ?? false),
            'global_live_blocker_count' => count((array) ($global['blockers'] ?? [])),
            'active_run_eligibility' => $blockers === [],
            'security_fingerprint' => (string) ($inputs['security_fingerprint'] ?? ''),
            'limits' => $profile['limits'],
            'ops_sms' => false,
            'sandbox_payment' => true,
            'real_payment' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function scopedLocalUatSnapshot(): array
    {
        $state = $this->state();
        $profile = $this->registry->localAllowlistedUatProfile();
        $inputs = app(TechnicalServiceMessagingSettingsService::class)
            ->scopedLocalUatControlPlaneState(false);
        $capabilitySnapshots = [];
        foreach ((array) $profile['required_capabilities'] as $code) {
            $status = is_array($inputs['capabilities'][$code] ?? null)
                ? $inputs['capabilities'][$code]
                : [];
            $capabilitySnapshots[$code] = [
                'revision' => (int) ($status['capability_revision'] ?? 0),
                'profile_fingerprint' => (string) ($status['profile_fingerprint'] ?? ''),
            ];
        }

        return [
            'scoped_local_uat_profile_id' => $profile['id'],
            'scoped_local_uat_profile_version' => $profile['version'],
            'scoped_local_uat_profile_fingerprint' => $profile['profile_fingerprint'],
            'scoped_local_uat_security_fingerprint' => (string) ($inputs['security_fingerprint'] ?? ''),
            'scoped_local_uat_capability_snapshots' => $capabilitySnapshots,
            'scoped_local_uat_production_ready' => false,
            'scoped_local_uat_sandbox_payment' => true,
            'scoped_local_uat_real_payment' => false,
            'scoped_local_uat_ops_sms' => false,
            'global_execution_mode' => $state['operator_mode'],
            'global_execution_state' => $state['transition_state'],
            'global_execution_epoch' => $state['epoch'],
            'global_execution_revision' => $state['revision'],
            'global_runtime_environment' => $this->runtimeEnvironment(),
            'global_profile_fingerprint' => $state['profile_fingerprint'],
            'scoped_local_uat_snapshot_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messagingSnapshot(?string $provider = null): array
    {
        $state = $this->state();
        $publicOrigin = $this->publicOriginProfile($state);
        $capabilities = [];
        foreach ([ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND, ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND] as $code) {
            $capability = $this->capabilityStatus($code);
            $capabilities[$code] = [
                'revision' => (int) $capability['capability_revision'],
                'profile_fingerprint' => (string) $capability['profile_fingerprint'],
            ];
        }
        $capabilityCode = $this->messagingCapabilityCode($provider);

        return [
            'global_execution_mode' => $state['operator_mode'],
            'global_execution_state' => $state['transition_state'],
            'global_execution_epoch' => $state['epoch'],
            'global_execution_revision' => $state['revision'],
            'global_runtime_environment' => $this->runtimeEnvironment(),
            'global_profile_fingerprint' => $state['profile_fingerprint'],
            'global_public_origin_profile_fingerprint' => (string) ($publicOrigin['profile_fingerprint'] ?? ''),
            'global_execution_snapshot_at' => CarbonImmutable::now()->toIso8601String(),
            'external_capability_code' => $capabilityCode,
            'external_capability_revision' => $capabilityCode === null ? null : $capabilities[$capabilityCode]['revision'],
            'external_capability_profile_fingerprint' => $capabilityCode === null ? null : $capabilities[$capabilityCode]['profile_fingerprint'],
            'external_capability_snapshots' => $capabilities,
            // Compatibility fields are derived from the global state and are never authoritative.
            'outbound_execution_mode' => $state['operator_mode'],
            'outbound_mode_revision' => $state['revision'],
            'runtime_environment' => $this->runtimeEnvironment(),
            'outbound_mode_snapshot_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{allowed:bool,code:string|null,message:string|null}
     */
    public function authorizeCapabilitySnapshot(string $capabilityCode, array $metadata): array
    {
        $definition = $this->registry->find($capabilityCode);
        if ($definition === null) {
            return $this->blocked('external_capability_unknown', 'Kayitsiz dis etki capability kaydi varsayilan olarak kapalidir.');
        }

        $state = $this->state();
        if ($state['operator_mode'] !== self::MODE_LIVE || $state['transition_state'] !== self::STATE_LIVE) {
            return $this->blocked('global_execution_mode_local', 'Sistem çalışma modu Lokal; dış etki kapalı.');
        }

        $publicOrigin = $this->publicOriginProfile($state);
        if (! (bool) ($publicOrigin['ready'] ?? false)) {
            return $this->blocked('public_origin_not_ready', 'Environment-bound public origin profile hazır değil.');
        }

        $status = $this->capabilityStatus($capabilityCode);
        if (! (bool) $status['adapted'] || ! (bool) $status['ready']) {
            return $this->blocked('external_capability_not_ready', 'Capability adapter veya readiness geçerli değil.');
        }

        $snapshot = is_array($metadata['external_capability_snapshots'][$capabilityCode] ?? null)
            ? $metadata['external_capability_snapshots'][$capabilityCode]
            : [];
        if (($metadata['global_execution_mode'] ?? null) !== self::MODE_LIVE
            || ($metadata['global_execution_state'] ?? null) !== self::STATE_LIVE
            || (int) ($metadata['global_execution_epoch'] ?? 0) !== (int) $state['epoch']
            || (int) ($metadata['global_execution_revision'] ?? 0) !== (int) $state['revision']
            || ($metadata['global_runtime_environment'] ?? null) !== $this->runtimeEnvironment()
            || ! hash_equals((string) $state['profile_fingerprint'], (string) ($metadata['global_profile_fingerprint'] ?? ''))
            || ! hash_equals((string) ($publicOrigin['profile_fingerprint'] ?? ''), (string) ($metadata['global_public_origin_profile_fingerprint'] ?? ''))
            || (int) ($snapshot['revision'] ?? 0) !== (int) $status['capability_revision']
            || ! hash_equals((string) $status['profile_fingerprint'], (string) ($snapshot['profile_fingerprint'] ?? ''))) {
            return $this->blocked('global_execution_snapshot_stale', 'Dış etki snapshotı current epoch, revision veya environment profile ile eşleşmiyor.');
        }

        return ['allowed' => true, 'code' => null, 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{allowed:bool,code:string|null,message:string|null}
     */
    public function authorizeScopedLocalUatCapabilitySnapshot(string $capabilityCode, array $metadata): array
    {
        $profile = $this->registry->localAllowlistedUatProfile();
        if (! in_array($capabilityCode, (array) $profile['required_capabilities'], true)) {
            return $this->blocked(
                'scoped_uat_unauthorized_capability',
                'Capability allowlistli Yerel UAT profiline ait değil.',
            );
        }

        $state = $this->state();
        if ($this->runtimeEnvironment() === 'production'
            || $state['operator_mode'] !== self::MODE_LOCAL
            || $state['transition_state'] !== self::STATE_LOCAL) {
            return $this->blocked('scoped_uat_environment_invalid', 'Allowlistli Yerel UAT yalnız global Lokal ve non-production ortamda çalışabilir.');
        }

        $current = $this->scopedLocalUatSnapshot();
        $storedCapability = is_array($metadata['scoped_local_uat_capability_snapshots'][$capabilityCode] ?? null)
            ? $metadata['scoped_local_uat_capability_snapshots'][$capabilityCode]
            : [];
        $currentCapability = is_array($current['scoped_local_uat_capability_snapshots'][$capabilityCode] ?? null)
            ? $current['scoped_local_uat_capability_snapshots'][$capabilityCode]
            : [];
        $inputs = app(TechnicalServiceMessagingSettingsService::class)
            ->scopedLocalUatControlPlaneState(false);
        $status = is_array($inputs['capabilities'][$capabilityCode] ?? null)
            ? $inputs['capabilities'][$capabilityCode]
            : [];

        if (! (bool) ($status['ready'] ?? false)) {
            return $this->blocked('scoped_uat_capability_not_ready', 'Allowlistli Yerel UAT capability readiness geçerli değil.');
        }

        if (($metadata['scoped_local_uat_profile_id'] ?? null) !== $profile['id']
            || (int) ($metadata['scoped_local_uat_profile_version'] ?? 0) !== (int) $profile['version']
            || ! hash_equals((string) $profile['profile_fingerprint'], (string) ($metadata['scoped_local_uat_profile_fingerprint'] ?? ''))
            || ! hash_equals((string) $current['scoped_local_uat_security_fingerprint'], (string) ($metadata['scoped_local_uat_security_fingerprint'] ?? ''))
            || (bool) ($metadata['scoped_local_uat_production_ready'] ?? true)
            || ! (bool) ($metadata['scoped_local_uat_sandbox_payment'] ?? false)
            || (bool) ($metadata['scoped_local_uat_real_payment'] ?? true)
            || (bool) ($metadata['scoped_local_uat_ops_sms'] ?? true)
            || ($metadata['global_execution_mode'] ?? null) !== self::MODE_LOCAL
            || ($metadata['global_execution_state'] ?? null) !== self::STATE_LOCAL
            || (int) ($metadata['global_execution_epoch'] ?? 0) !== (int) $state['epoch']
            || (int) ($metadata['global_execution_revision'] ?? 0) !== (int) $state['revision']
            || ($metadata['global_runtime_environment'] ?? null) !== $this->runtimeEnvironment()
            || ! hash_equals((string) $state['profile_fingerprint'], (string) ($metadata['global_profile_fingerprint'] ?? ''))
            || (int) ($storedCapability['revision'] ?? 0) !== (int) ($currentCapability['revision'] ?? 0)
            || ! hash_equals((string) ($currentCapability['profile_fingerprint'] ?? ''), (string) ($storedCapability['profile_fingerprint'] ?? ''))) {
            return $this->blocked('scoped_uat_snapshot_stale', 'Allowlistli Yerel UAT snapshotı current profile, capability veya güvenlik bağlamıyla eşleşmiyor.');
        }

        return ['allowed' => true, 'code' => null, 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function messagingRunSnapshotIsCurrent(array $snapshot): bool
    {
        if (array_key_exists('scoped_local_uat_profile_id', $snapshot)) {
            return $this->scopedLocalUatRunSnapshotIsCurrent($snapshot);
        }

        $state = $this->state();
        $publicOrigin = $this->publicOriginProfile($state);
        if (($snapshot['global_execution_mode'] ?? null) !== self::MODE_LIVE
            || ($snapshot['global_execution_state'] ?? null) !== self::STATE_LIVE
            || (int) ($snapshot['global_execution_epoch'] ?? 0) !== (int) $state['epoch']
            || (int) ($snapshot['global_execution_revision'] ?? 0) !== (int) $state['revision']
            || ($snapshot['global_runtime_environment'] ?? null) !== $this->runtimeEnvironment()
            || ! hash_equals((string) $state['profile_fingerprint'], (string) ($snapshot['global_profile_fingerprint'] ?? ''))
            || ! (bool) ($publicOrigin['ready'] ?? false)
            || ! hash_equals((string) ($publicOrigin['profile_fingerprint'] ?? ''), (string) ($snapshot['global_public_origin_profile_fingerprint'] ?? ''))) {
            return false;
        }

        $identities = app(TechnicalServiceMessagingSettingsService::class)
            ->globalExecutionCapabilityIdentities();
        foreach ([ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND, ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND] as $code) {
            $identity = (array) ($identities[$code] ?? []);
            $stored = is_array($snapshot['external_capability_snapshots'][$code] ?? null)
                ? $snapshot['external_capability_snapshots'][$code]
                : [];
            if ((int) ($stored['revision'] ?? 0) !== (int) ($identity['capability_revision'] ?? 0)
                || ! hash_equals((string) ($identity['profile_fingerprint'] ?? ''), (string) ($stored['profile_fingerprint'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function scopedLocalUatRunSnapshotIsCurrent(array $snapshot): bool
    {
        $profile = $this->registry->localAllowlistedUatProfile();
        foreach ((array) $profile['required_capabilities'] as $code) {
            $authorization = $this->authorizeScopedLocalUatCapabilitySnapshot((string) $code, $snapshot);
            if (! $authorization['allowed']) {
                return false;
            }
        }

        return true;
    }

    public function messagingCapabilityCode(?string $provider): ?string
    {
        return match (trim((string) $provider)) {
            'evo_whatsapp' => ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND,
            'nac_sms' => ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND,
            default => null,
        };
    }

    public function runtimeEnvironment(): string
    {
        if (app()->environment('production')) {
            return 'production';
        }
        if (app()->environment('staging')) {
            return 'staging';
        }

        return 'local';
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function readiness(array $state, bool $checkLifecycleLock = true): array
    {
        $capabilities = [];
        $requiredBlockers = [];
        $optionalBlockers = [];
        foreach (array_keys($this->registry->definitions()) as $code) {
            $status = $this->capabilityStatus($code, $checkLifecycleLock);
            $capabilities[] = $status;
            if (! (bool) $status['mode_gated'] || ((bool) $status['adapted'] && (bool) $status['ready'])) {
                continue;
            }

            $blocker = [
                'code' => 'capability_not_ready:'.$code,
                'capability' => $code,
                'message' => $code.' adapter/readiness tamamlanmadı; capability LOCAL/OFF kalır.',
            ];
            if ((bool) $status['required']) {
                $requiredBlockers[] = $blocker;
            } else {
                $optionalBlockers[] = $blocker;
            }
        }

        $messagingTransition = app(TechnicalServiceMessagingSettingsService::class)
            ->globalExecutionTransitionReadiness($checkLifecycleLock);
        foreach ((array) ($messagingTransition['blockers'] ?? []) as $blocker) {
            $code = is_scalar($blocker['code'] ?? null) ? (string) $blocker['code'] : 'unknown';
            $requiredBlockers[] = [
                'code' => 'messaging_transition_not_ready:'.$code,
                'capability' => 'messaging',
                'message' => is_scalar($blocker['message'] ?? null)
                    ? (string) $blocker['message']
                    : 'Messaging activation readiness tamamlanmadi.',
            ];
        }

        $publicOrigin = PartnerPortalPublicUrl::resolveProfile(
            $this->runtimeEnvironment(),
            self::MODE_LIVE,
            self::STATE_LIVE,
        );
        if (! (bool) ($publicOrigin['ready'] ?? false)) {
            $requiredBlockers[] = [
                'code' => 'public_origin_not_ready:'.($publicOrigin['blocker_code'] ?? 'unknown'),
                'capability' => 'public.route.origin',
                'message' => (string) ($publicOrigin['blocker_message'] ?? 'Public origin profile hazır değil.'),
            ];
        }

        $profileFingerprint = $this->aggregateProfileFingerprint($capabilities, $publicOrigin);

        return [
            'eligible' => $requiredBlockers === [],
            'target_mode' => self::MODE_LIVE,
            'runtime_environment' => $this->runtimeEnvironment(),
            'blockers' => $requiredBlockers,
            'optional_blockers' => $optionalBlockers,
            'blocker_count' => count($requiredBlockers),
            'required_count' => collect($capabilities)->where('required', true)->count(),
            'required_adapted_count' => collect($capabilities)->where('required', true)->where('adapted', true)->count(),
            'required_ready_count' => collect($capabilities)->where('required', true)->where('ready', true)->count(),
            'registered_count' => count($capabilities),
            'profile_fingerprint' => $profileFingerprint,
            'capabilities' => $capabilities,
            'current_mode' => $state['operator_mode'],
            'current_state' => $state['transition_state'],
            'public_origin' => $publicOrigin,
        ];
    }

    private function legacyStalePublicUrlCount(): int
    {
        $count = TechnicalServiceMountPayment::query()
            ->whereNotNull('payment_url')
            ->pluck('payment_url')
            ->filter(fn (mixed $value): bool => is_string($value) && PartnerPortalPublicUrl::isLocalUrl($value))
            ->count();

        foreach (TechnicalServicePartnerJobAction::query()->select(['id', 'payload'])->lazyById() as $action) {
            $count += $this->legacyUrlOccurrences($action->payload);
        }
        foreach (TechnicalServiceMessageDispatch::query()->select(['id', 'request_payload', 'response_payload'])->lazyById() as $dispatch) {
            $count += $this->legacyUrlOccurrences($dispatch->request_payload);
            $count += $this->legacyUrlOccurrences($dispatch->response_payload);
        }

        return $count;
    }

    private function legacyUrlOccurrences(mixed $value): int
    {
        if (is_string($value)) {
            return preg_match('#^https?://#i', $value) === 1 && PartnerPortalPublicUrl::isLocalUrl($value) ? 1 : 0;
        }
        if (! is_array($value)) {
            return 0;
        }

        return array_sum(array_map(fn (mixed $item): int => $this->legacyUrlOccurrences($item), $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilityStatus(string $code, bool $checkLifecycleLock = false): array
    {
        $definition = $this->registry->find($code);
        if ($definition === null) {
            return [
                'code' => $code,
                'classification' => 'UNKNOWN',
                'owner_track' => null,
                'activation_class' => 'required',
                'required' => true,
                'adapted' => false,
                'ready' => false,
                'mode_gated' => true,
                'capability_revision' => 0,
                'profile_fingerprint' => '',
                'readiness_blockers' => ['external_capability_unknown'],
                'last_verified_at' => null,
                'safe_default' => 'deny',
            ];
        }

        $ready = (bool) $definition['adapted'];
        $blockers = [];
        $profileFingerprint = hash('sha256', implode('|', [
            $this->runtimeEnvironment(),
            $code,
            (string) $definition['capability_revision'],
            'unadapted',
        ]));
        if (in_array($code, [ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND, ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND], true)) {
            $messaging = app(TechnicalServiceMessagingSettingsService::class)
                ->globalExecutionCapabilityReadiness();
            $adapter = (array) ($messaging[$code] ?? []);
            $ready = (bool) ($adapter['ready'] ?? false);
            $blockers = array_values((array) ($adapter['blockers'] ?? []));
            $profileFingerprint = (string) ($adapter['profile_fingerprint'] ?? '');
        } elseif (! (bool) $definition['adapted']) {
            $blockers = ['capability_unadapted'];
        }

        return [
            ...$definition,
            'ready' => $ready,
            'profile_fingerprint' => $profileFingerprint,
            'readiness_blockers' => $blockers,
            'last_verified_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $capabilities
     * @param  array<string, mixed>  $publicOrigin
     */
    private function aggregateProfileFingerprint(array $capabilities, array $publicOrigin): string
    {
        $parts = collect($capabilities)
            ->map(fn (array $capability): string => implode(':', [
                $capability['code'],
                $capability['capability_revision'],
                $capability['profile_fingerprint'],
            ]))
            ->sort()
            ->values()
            ->all();

        return hash('sha256', implode('|', [
            $this->runtimeEnvironment(),
            'registry:'.ExternalEffectCapabilityRegistry::VERSION,
            'public-origin:'.(string) ($publicOrigin['profile_fingerprint'] ?? ''),
            ...$parts,
        ]));
    }

    private function lockedPageConfig(): PageConfig
    {
        $layout = [];
        Arr::set($layout, self::ROOT_KEY, $this->defaultState());
        $page = PageConfig::query()->firstOrCreate(
            ['page_code' => self::PAGE_CODE],
            ['layout_json' => $layout],
        );

        return PageConfig::query()->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    private function stateFromLayout(array $layout): array
    {
        $stored = Arr::get($layout, self::ROOT_KEY);
        if (! is_array($stored)) {
            return $this->defaultState();
        }

        $mode = ($stored['operator_mode'] ?? null) === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_LOCAL;
        $state = is_string($stored['transition_state'] ?? null) ? $stored['transition_state'] : '';
        $validStates = [self::STATE_LOCAL, self::STATE_ACTIVATING, self::STATE_LIVE, self::STATE_FREEZING, self::STATE_BLOCKED];
        $valid = in_array($state, $validStates, true)
            && (($mode === self::MODE_LOCAL && $state !== self::STATE_LIVE)
                || ($mode === self::MODE_LIVE && $state === self::STATE_LIVE))
            && (($stored['environment'] ?? $this->runtimeEnvironment()) === $this->runtimeEnvironment());
        if (! $valid) {
            $mode = self::MODE_LOCAL;
            $state = self::STATE_LOCAL;
        }

        return [
            'operator_mode' => $mode,
            'transition_state' => $state,
            'epoch' => max(1, (int) ($stored['epoch'] ?? 1)),
            'revision' => max(1, (int) ($stored['revision'] ?? 1)),
            'environment' => $this->runtimeEnvironment(),
            'profile_fingerprint' => is_string($stored['profile_fingerprint'] ?? null)
                ? $stored['profile_fingerprint']
                : $this->defaultState()['profile_fingerprint'],
            'registry_version' => max(1, (int) ($stored['registry_version'] ?? 1)),
            'changed_at' => is_scalar($stored['changed_at'] ?? null) ? (string) $stored['changed_at'] : null,
            'changed_by' => is_numeric($stored['changed_by'] ?? null) ? (int) $stored['changed_by'] : null,
            'reason' => is_scalar($stored['reason'] ?? null) ? (string) $stored['reason'] : null,
            'correlation_id' => is_scalar($stored['correlation_id'] ?? null) ? (string) $stored['correlation_id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultState(): array
    {
        return [
            'operator_mode' => self::MODE_LOCAL,
            'transition_state' => self::STATE_LOCAL,
            'epoch' => 1,
            'revision' => 1,
            'environment' => $this->runtimeEnvironment(),
            'profile_fingerprint' => hash('sha256', $this->runtimeEnvironment().'|external-control-default-local|'.ExternalEffectCapabilityRegistry::VERSION),
            'registry_version' => ExternalEffectCapabilityRegistry::VERSION,
            'changed_at' => null,
            'changed_by' => null,
            'reason' => null,
            'correlation_id' => null,
        ];
    }

    /**
     * @return array{allowed:false,code:string,message:string}
     */
    private function blocked(string $code, string $message): array
    {
        return ['allowed' => false, 'code' => $code, 'message' => $message];
    }

    private function sanitizeReason(string $reason): string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $reason = (string) preg_replace(
            '/\bAuthorization\s*:\s*[^\s,;]+(?:\s+[^\s,;]+)?/iu',
            'Authorization: [redacted]',
            $reason,
        );
        $reason = (string) preg_replace(
            '/\b(api[_ -]?key|x-api-key|apikey|access[_ -]?token|refresh[_ -]?token|token|client[_ -]?secret|password|secret)(?:_encrypted)?\s*[:=]\s*[^\s,;]+/iu',
            '$1=[redacted]',
            $reason,
        );
        $reason = (string) preg_replace('/\bBearer\s+[^\s,;]+/iu', 'Bearer [redacted]', $reason);
        $reason = (string) preg_replace(
            '~\b([a-z][a-z0-9+.-]*://)[^/@\s]+@~iu',
            '$1[redacted]@',
            $reason,
        );
        $reason = (string) preg_replace(
            '/([?&](?:api[_-]?key|apikey|access_token|refresh_token|token|client_secret|password|secret|signature|sig|key)(?:_encrypted)?=)[^&#\s]+/iu',
            '$1[redacted]',
            $reason,
        );
        $reason = (string) preg_replace('/\+?\d[\d\s().-]{8,}\d/u', '[redacted-phone]', $reason);

        return Str::limit($reason, 500, '');
    }

    private function safeCorrelationId(?string $correlationId): string
    {
        $candidate = trim((string) $correlationId);

        return preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $candidate) === 1
            ? $candidate
            : (string) Str::uuid();
    }
}

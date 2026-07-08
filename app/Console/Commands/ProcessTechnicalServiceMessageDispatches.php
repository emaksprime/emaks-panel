<?php

namespace App\Console\Commands;

use App\Services\Messaging\TechnicalServiceMessageDispatchProcessor;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ProcessTechnicalServiceMessageDispatches extends Command
{
    private const MANUAL_E2E_SMOKE_RUN_ID = 'MANUAL-E2E-LIVE-TEST';

    protected $signature = 'technical-service:process-message-dispatches
        {--limit=10 : Maximum dispatch count}
        {--provider= : Provider key filter, comma separated when needed}
        {--channel= : Channel filter}
        {--dry-run : List candidates without writes or provider calls}
        {--dispatch-id= : Process a single dispatch id}
        {--only-test : Only process test recipient dispatches}
        {--no-external : Do not call external providers}
        {--allowlisted-phone=* : Effective target phones allowed for controlled real smoke}
        {--role-target=* : Expected role target phone as role:phone for controlled real smoke}
        {--smoke-run-id= : Required current smoke run id for controlled real smoke}
        {--smoke-started-at= : ISO timestamp; dispatch must be created after this for controlled real smoke}
        {--expected-body-token= : Required token that must be present in dispatch body for controlled real smoke}
        {--manual-e2e-only : Process only dispatches tagged metadata.manual_e2e=true}
        {--created-after= : ISO timestamp; dispatch must be created at or after this time}
        {--worker-loop : Run a bounded worker loop}
        {--sleep-seconds=10 : Worker loop sleep seconds}
        {--stop-after-idle-cycles=0 : Stop worker after this many idle cycles; 0 disables idle stop}
        {--require-real-send-enabled : Stop unless real_send_enabled=true}
        {--require-queue-not-paused : Stop unless queue_paused=false}
        {--print-start-command : Print the safe manual E2E worker command and exit}
        {--max-seconds=0 : Enforced worker runtime ceiling in seconds}';

    protected $description = 'Process technical service message dispatch outbox safely.';

    public function handle(
        TechnicalServiceMessageDispatchProcessor $processor,
        TechnicalServiceMessagingSettingsService $settings,
    ): int {
        $options = $this->processorOptions();

        if ((bool) $this->option('print-start-command')) {
            $this->line(json_encode($this->startCommandPreview(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ((bool) $this->option('worker-loop')) {
            return $this->runWorkerLoop($processor, $settings, $options);
        }

        $runtimeBlock = $this->runtimeBlockReason($settings, $options, (bool) $this->option('dry-run'));
        if ($runtimeBlock !== null) {
            $this->line(json_encode([
                'dry_run' => (bool) $this->option('dry-run'),
                'blocked' => true,
                'stop_reason' => $runtimeBlock,
                'dispatches' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $result = $this->option('dry-run')
            ? $processor->dryRun($options)
            : $processor->process($options);

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function processorOptions(): array
    {
        $providerKeys = $this->csvValues($this->option('provider') ?: null);
        $manualE2eOnly = (bool) $this->option('manual-e2e-only');
        $smokeRunId = trim((string) ($this->option('smoke-run-id') ?: ''));
        if ($manualE2eOnly && $smokeRunId === '') {
            $smokeRunId = self::MANUAL_E2E_SMOKE_RUN_ID;
        }

        return [
            'limit' => (int) $this->option('limit'),
            'provider' => $providerKeys[0] ?? null,
            'provider_keys' => $providerKeys,
            'channel' => $this->option('channel') ?: null,
            'dispatch_id' => $this->option('dispatch-id') ? (int) $this->option('dispatch-id') : null,
            'only_test' => (bool) $this->option('only-test'),
            'no_external' => (bool) $this->option('no-external'),
            'allowlisted_phones' => $this->csvValues((array) $this->option('allowlisted-phone')),
            'role_target_phones' => $this->roleTargetPhones((array) $this->option('role-target')),
            'smoke_run_id' => $smokeRunId !== '' ? $smokeRunId : null,
            'smoke_started_at' => $this->option('smoke-started-at') ?: null,
            'expected_body_token' => $this->option('expected-body-token') ?: null,
            'manual_e2e_only' => $manualE2eOnly,
            'created_after' => $this->option('created-after') ?: null,
            'guarded_batch' => (bool) $this->option('worker-loop'),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runWorkerLoop(
        TechnicalServiceMessageDispatchProcessor $processor,
        TechnicalServiceMessagingSettingsService $settings,
        array $options,
    ): int {
        $maxSeconds = (int) $this->option('max-seconds');
        if ($maxSeconds <= 0) {
            $maxSeconds = 14400;
        }

        $sleepSeconds = max(0, min(60, (int) $this->option('sleep-seconds')));
        $idleLimitOption = (int) $this->option('stop-after-idle-cycles');
        $idleLimit = $idleLimitOption > 0 ? $idleLimitOption : null;
        $startedAt = CarbonImmutable::now();
        $expiresAt = $startedAt->addSeconds($maxSeconds);
        $idleCycles = 0;
        $cycles = 0;
        $processed = 0;
        $stopReason = 'ttl_expired';

        $this->line(json_encode([
            'manual_e2e_worker_started_at' => $startedAt->toIso8601String(),
            'manual_e2e_worker_expires_at' => $expiresAt->toIso8601String(),
            'allowlist' => $this->maskPhones((array) ($options['allowlisted_phones'] ?? [])),
            'filters' => $this->filterSummary($options),
            'idle_stop' => $idleLimit === null ? 'disabled' : $idleLimit,
            'stop_command' => 'Stop-Process -Id <PID>',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        while (CarbonImmutable::now()->lt($expiresAt)) {
            $runtimeBlock = $this->runtimeBlockReason($settings, $options, (bool) $this->option('dry-run'));
            if ($runtimeBlock !== null) {
                $stopReason = $runtimeBlock;
                break;
            }

            $result = $this->option('dry-run')
                ? $processor->dryRun($options)
                : $processor->process($options);
            $count = (int) ($result['count'] ?? 0);
            $processed += $count;
            $cycles++;

            if ($count === 0) {
                $idleCycles++;
                if ($idleLimit !== null && $idleCycles >= $idleLimit) {
                    $stopReason = 'idle_limit_reached';
                    break;
                }
            } else {
                $idleCycles = 0;
            }

            if ($sleepSeconds > 0 && CarbonImmutable::now()->lt($expiresAt)) {
                sleep($sleepSeconds);
            }
        }

        $this->line(json_encode([
            'manual_e2e_worker_stopped_at' => CarbonImmutable::now()->toIso8601String(),
            'stop_reason' => $stopReason,
            'cycles' => $cycles,
            'processed' => $processed,
            'allowlist' => $this->maskPhones((array) ($options['allowlisted_phones'] ?? [])),
            'filters' => $this->filterSummary($options),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runtimeBlockReason(
        TechnicalServiceMessagingSettingsService $settings,
        array $options,
        bool $dryRun,
    ): ?string {
        $allowlist = array_filter((array) ($options['allowlisted_phones'] ?? []));
        $manualE2eOnly = (bool) ($options['manual_e2e_only'] ?? false);
        $externalWorker = ! $dryRun && ! (bool) ($options['no_external'] ?? false) && $manualE2eOnly;

        if ((bool) $this->option('worker-loop') && $allowlist === []) {
            return 'allowlist_missing';
        }

        if ((bool) $this->option('worker-loop') && trim((string) ($options['created_after'] ?? '')) === '') {
            return 'created_after_missing';
        }

        $providers = (array) ($options['provider_keys'] ?? []);
        if ((bool) $this->option('worker-loop') && ! $this->providersAreManualE2eSafe($providers)) {
            return 'provider_filter_missing_or_unsafe';
        }

        $global = (array) ($settings->payload()['global'] ?? []);

        if ($externalWorker && ! (bool) ($global['manual_e2e_enabled'] ?? false)) {
            return 'manual_e2e_disabled';
        }

        if (((bool) $this->option('require-real-send-enabled') || $externalWorker)
            && ! (bool) ($global['real_send_enabled'] ?? false)) {
            return 'real_send_disabled';
        }

        if (((bool) $this->option('require-queue-not-paused') || $externalWorker)
            && (bool) ($global['queue_paused'] ?? false)) {
            return 'queue_paused';
        }

        return null;
    }

    /**
     * @param  array<int, string>  $providers
     */
    private function providersAreManualE2eSafe(array $providers): bool
    {
        $providers = array_values(array_filter($providers));
        if ($providers === []) {
            return false;
        }

        $allowed = ['evo_whatsapp', 'nac_sms'];

        return array_diff($providers, $allowed) === [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function filterSummary(array $options): array
    {
        return [
            'manual_e2e_only' => (bool) ($options['manual_e2e_only'] ?? false),
            'created_after' => $options['created_after'] ?? null,
            'provider_keys' => $options['provider_keys'] ?? [],
            'smoke_run_id' => $options['smoke_run_id'] ?? null,
            'channel' => $options['channel'] ?? null,
            'limit' => $options['limit'] ?? null,
            'dry_run' => (bool) $this->option('dry-run'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function startCommandPreview(): array
    {
        $createdAfter = CarbonImmutable::now()->toIso8601String();

        return [
            'dry_run' => true,
            'command' => implode(' ', [
                'php artisan technical-service:process-message-dispatches',
                '--worker-loop',
                '--manual-e2e-only',
                '--created-after="'.$createdAfter.'"',
                '--smoke-run-id='.self::MANUAL_E2E_SMOKE_RUN_ID,
                '--allowlisted-phone=905372081633',
                '--allowlisted-phone=905467647428',
                '--provider=evo_whatsapp,nac_sms',
                '--require-real-send-enabled',
                '--require-queue-not-paused',
                '--max-seconds=14400',
                '--sleep-seconds=10',
                '--stop-after-idle-cycles=0',
            ]),
        ];
    }

    /**
     * @param  array<int, string>  $pairs
     * @return array<string, string>
     */
    private function roleTargetPhones(array $pairs): array
    {
        $targets = [];

        foreach ($pairs as $pair) {
            if (! str_contains($pair, ':')) {
                continue;
            }

            [$role, $phone] = array_map('trim', explode(':', $pair, 2));
            if ($role !== '' && $phone !== '') {
                $targets[$role] = $phone;
            }
        }

        return $targets;
    }

    /**
     * @param  string|array<int, string>|null  $value
     * @return array<int, string>
     */
    private function csvValues(string|array|null $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $items = [];

        foreach ($values as $entry) {
            foreach (explode(',', (string) $entry) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param  array<int, string>  $phones
     * @return array<int, string>
     */
    private function maskPhones(array $phones): array
    {
        return array_map(function (string $phone): string {
            $digits = preg_replace('/\D+/', '', $phone) ?: '';
            if (strlen($digits) < 6) {
                return '***';
            }

            return substr($digits, 0, 4).'***'.substr($digits, -3);
        }, $phones);
    }
}

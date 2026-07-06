<?php

namespace App\Console\Commands;

use App\Services\Messaging\TechnicalServiceMessageDispatchProcessor;
use Illuminate\Console\Command;

class ProcessTechnicalServiceMessageDispatches extends Command
{
    protected $signature = 'technical-service:process-message-dispatches
        {--limit=10 : Maximum dispatch count}
        {--provider= : Provider key filter}
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
        {--max-seconds=0 : Reserved worker runtime ceiling}';

    protected $description = 'Process technical service message dispatch outbox safely.';

    public function handle(TechnicalServiceMessageDispatchProcessor $processor): int
    {
        $options = [
            'limit' => (int) $this->option('limit'),
            'provider' => $this->option('provider') ?: null,
            'channel' => $this->option('channel') ?: null,
            'dispatch_id' => $this->option('dispatch-id') ? (int) $this->option('dispatch-id') : null,
            'only_test' => (bool) $this->option('only-test'),
            'no_external' => (bool) $this->option('no-external'),
            'allowlisted_phones' => (array) $this->option('allowlisted-phone'),
            'role_target_phones' => $this->roleTargetPhones((array) $this->option('role-target')),
            'smoke_run_id' => $this->option('smoke-run-id') ?: null,
            'smoke_started_at' => $this->option('smoke-started-at') ?: null,
            'expected_body_token' => $this->option('expected-body-token') ?: null,
        ];

        $result = $this->option('dry-run')
            ? $processor->dryRun($options)
            : $processor->process($options);

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
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
}

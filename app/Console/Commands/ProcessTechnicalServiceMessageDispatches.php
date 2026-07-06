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
        ];

        $result = $this->option('dry-run')
            ? $processor->dryRun($options)
            : $processor->process($options);

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

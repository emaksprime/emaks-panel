<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use Illuminate\Support\Facades\DB;

class TechnicalServiceMessageDispatchProcessor
{
    public function __construct(
        private readonly TechnicalServiceMessageRateLimitService $rateLimiter,
        private readonly TechnicalServiceMessageProviderRouter $router,
        private readonly TechnicalServiceMessageDispatchQueue $queue,
    ) {}

    /**
     * @param  array{limit?:int,provider?:string|null,channel?:string|null,dispatch_id?:int|null,only_test?:bool,no_external?:bool}  $options
     * @return array<string, mixed>
     */
    public function dryRun(array $options = []): array
    {
        $candidates = $this->candidateQuery($options)->limit(max(1, (int) ($options['limit'] ?? 10)))->get();

        return [
            'dry_run' => true,
            'count' => $candidates->count(),
            'dispatches' => $candidates->map(fn (TechnicalServiceMessageDispatch $dispatch): array => [
                'id' => $dispatch->id,
                'status' => $dispatch->status,
                'provider_key' => $dispatch->provider_key,
                'channel' => $dispatch->channel,
                'message_type' => $dispatch->message_type,
                'target' => $dispatch->effective_target_phone_mask,
            ])->all(),
        ];
    }

    /**
     * @param  array{limit?:int,provider?:string|null,channel?:string|null,dispatch_id?:int|null,only_test?:bool,no_external?:bool}  $options
     * @return array<string, mixed>
     */
    public function process(array $options = []): array
    {
        $processed = [];
        $limit = max(1, (int) ($options['limit'] ?? 10));
        $ids = $this->candidateQuery($options)->limit($limit)->pluck('id')->all();

        foreach ($ids as $id) {
            $processed[] = $this->processOne((int) $id, (bool) ($options['no_external'] ?? false));
        }

        return [
            'dry_run' => false,
            'count' => count($processed),
            'dispatches' => $processed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processOne(int $dispatchId, bool $noExternal = false): array
    {
        return DB::transaction(function () use ($dispatchId, $noExternal): array {
            /** @var TechnicalServiceMessageDispatch|null $dispatch */
            $dispatch = TechnicalServiceMessageDispatch::query()
                ->whereKey($dispatchId)
                ->lockForUpdate()
                ->first();

            if (! $dispatch instanceof TechnicalServiceMessageDispatch) {
                return ['id' => $dispatchId, 'status' => 'missing'];
            }

            if (! in_array($dispatch->status, [TechnicalServiceMessageDispatch::STATUS_QUEUED, TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED], true)) {
                return ['id' => $dispatch->id, 'status' => $dispatch->status, 'skipped' => true];
            }

            $rateLimit = $this->rateLimiter->evaluateBeforeProcessing($dispatch);
            if (! $rateLimit['allowed']) {
                $dispatch->forceFill([
                    'status' => $rateLimit['status'],
                    'next_attempt_at' => $rateLimit['next_attempt_at'],
                    'last_error_code' => $rateLimit['status'],
                    'last_error_message_redacted' => $rateLimit['reason'],
                ])->save();

                return ['id' => $dispatch->id, 'status' => $dispatch->status, 'reason' => $rateLimit['reason']];
            }

            $dispatch->forceFill([
                'status' => TechnicalServiceMessageDispatch::STATUS_SENDING,
                'sending_started_at' => now(),
                'attempt_count' => (int) $dispatch->attempt_count + 1,
            ])->save();

            $result = $this->router->dispatch($dispatch, $noExternal);
            $status = (string) $result['status'];

            $dispatch->forceFill([
                'status' => $status,
                'provider_status' => $result['provider_status'] ?? null,
                'provider_message_id' => $result['provider_message_id'] ?? null,
                'provider_response_redacted' => $result['response'] ?? null,
                'response_payload' => $result['response'] ?? null,
                'last_error_code' => $result['provider_status'] ?? null,
                'last_error_message_redacted' => $result['error'] ?? null,
                'error_message' => $result['error'] ?? null,
                'sent_at' => in_array($status, TechnicalServiceMessageDispatch::SUCCESS_STATUSES, true) ? now() : null,
                'failed_at' => in_array($status, [TechnicalServiceMessageDispatch::STATUS_FAILED, TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR], true) ? now() : null,
            ])->save();

            $this->queue->recordEvent(
                $dispatch,
                in_array($status, TechnicalServiceMessageDispatch::SUCCESS_STATUSES, true) ? 'message_sent' : 'message_failed',
                in_array($status, TechnicalServiceMessageDispatch::SUCCESS_STATUSES, true) ? 'Mesaj provider tarafından kabul edildi.' : 'Mesaj provider tarafından gönderilemedi.',
            );

            return [
                'id' => $dispatch->id,
                'status' => $dispatch->status,
                'provider_status' => $dispatch->provider_status,
                'provider_message_id' => $dispatch->provider_message_id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function candidateQuery(array $options)
    {
        return TechnicalServiceMessageDispatch::query()
            ->whereIn('status', [TechnicalServiceMessageDispatch::STATUS_QUEUED, TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED])
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->when($options['dispatch_id'] ?? null, fn ($query, $id) => $query->whereKey((int) $id))
            ->when($options['provider'] ?? null, fn ($query, $provider) => $query->where('provider_key', (string) $provider))
            ->when($options['channel'] ?? null, fn ($query, $channel) => $query->where('channel', (string) $channel))
            ->when((bool) ($options['only_test'] ?? false), fn ($query) => $query->where('recipient_role', 'test'))
            ->orderBy('next_attempt_at')
            ->orderBy('id');
    }
}

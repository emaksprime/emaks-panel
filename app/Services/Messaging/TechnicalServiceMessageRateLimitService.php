<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use Carbon\CarbonInterface;

class TechnicalServiceMessageRateLimitService
{
    public function __construct(
        private readonly TechnicalServiceMessagingSettingsService $settings,
    ) {}

    /**
     * @return array{allowed:bool,status:string|null,reason:string|null,next_attempt_at:CarbonInterface|null}
     */
    public function evaluateBeforeProcessing(TechnicalServiceMessageDispatch $dispatch): array
    {
        $global = (array) ($this->settings->payload()['global'] ?? []);

        if ((bool) ($global['queue_paused'] ?? false)) {
            return $this->blocked(TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED, 'Mesaj kuyruğu admin tarafından duraklatılmış.', now()->addMinute());
        }

        $delay = max(30, (int) ($global['send_delay_seconds'] ?? 90));
        $latestProviderSend = TechnicalServiceMessageDispatch::query()
            ->where('provider_key', $dispatch->provider_key)
            ->whereIn('status', TechnicalServiceMessageDispatch::SUCCESS_STATUSES)
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->first();

        if ($latestProviderSend instanceof TechnicalServiceMessageDispatch && $latestProviderSend->sent_at instanceof CarbonInterface) {
            $next = $latestProviderSend->sent_at->copy()->addSeconds($delay);
            if ($next->isFuture()) {
                return $this->blocked(TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED, "Provider gönderim aralığı {$delay} saniye.", $next);
            }
        }

        $cooldown = $this->cooldownBlock($dispatch, max(1, (int) ($global['duplicate_cooldown_minutes'] ?? 10)));
        if ($cooldown !== null) {
            return $cooldown;
        }

        $hourly = max(1, (int) ($global['hourly_limit'] ?? 30));
        $daily = max(1, (int) ($global['daily_limit'] ?? 200));

        if ($this->sentCount($dispatch, now()->subHour()) >= $hourly) {
            return $this->blocked(TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED, "Provider saatlik limitine ulaşıldı ({$hourly}).", now()->addHour());
        }

        if ($this->sentCount($dispatch, now()->subDay()) >= $daily) {
            return $this->blocked(TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED, "Provider günlük limitine ulaşıldı ({$daily}).", now()->addDay());
        }

        return [
            'allowed' => true,
            'status' => null,
            'reason' => null,
            'next_attempt_at' => null,
        ];
    }

    /**
     * @return array{allowed:bool,status:string|null,reason:string|null,next_attempt_at:CarbonInterface|null}
     */
    private function cooldownBlock(TechnicalServiceMessageDispatch $dispatch, int $minutes): ?array
    {
        if ($dispatch->effective_target_phone_hash === null || $dispatch->message_type === null) {
            return null;
        }

        $latest = TechnicalServiceMessageDispatch::query()
            ->where('id', '!=', $dispatch->id)
            ->where('effective_target_phone_hash', $dispatch->effective_target_phone_hash)
            ->where('message_type', $dispatch->message_type)
            ->whereIn('status', TechnicalServiceMessageDispatch::SUCCESS_STATUSES)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->latest('id')
            ->first();

        if (! $latest instanceof TechnicalServiceMessageDispatch) {
            return null;
        }

        return $this->blocked(
            TechnicalServiceMessageDispatch::STATUS_COOLDOWN_BLOCKED,
            "Aynı hedef ve mesaj tipi için {$minutes} dakika cooldown aktif.",
            now()->addMinutes($minutes),
        );
    }

    private function sentCount(TechnicalServiceMessageDispatch $dispatch, CarbonInterface $since): int
    {
        return TechnicalServiceMessageDispatch::query()
            ->where('provider_key', $dispatch->provider_key)
            ->whereIn('status', TechnicalServiceMessageDispatch::SUCCESS_STATUSES)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * @return array{allowed:bool,status:string,reason:string,next_attempt_at:CarbonInterface}
     */
    private function blocked(string $status, string $reason, CarbonInterface $nextAttemptAt): array
    {
        return [
            'allowed' => false,
            'status' => $status,
            'reason' => $reason,
            'next_attempt_at' => $nextAttemptAt,
        ];
    }
}

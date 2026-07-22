<?php

namespace App\Console\Commands;

use App\Models\TechnicalServiceMountPayment;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\TechnicalServicePaymentProviderReconciliationService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

class ReconcileTechnicalServiceIyzicoPayments extends Command
{
    protected $signature = 'technical-service:reconcile-iyzico-payments
        {--limit=50 : Maximum pending payments to inspect}
        {--payment-id= : Reconcile one local mount payment id}
        {--dry-run : List candidates without provider calls or writes}
        {--only-sandbox : Reconcile sandbox-mode Iyzico payments}
        {--only-live : Reconcile live-mode Iyzico payments only when live readiness is explicitly enabled}
        {--older-than-minutes=2 : Skip freshly created links}
        {--max-attempts=5 : Skip payments with this many sync attempts}';

    protected $description = 'Reconcile pending technical service Iyzico payment links from trusted provider status.';

    public function handle(
        PaymentProviderManager $paymentProviderManager,
        TechnicalServicePaymentProviderReconciliationService $reconciliationService,
        TechnicalServicePaymentProviderSettingsService $settings,
    ): int {
        if ($this->option('only-sandbox') && $this->option('only-live')) {
            $this->error('Use only one mode filter: --only-sandbox or --only-live.');
            return self::FAILURE;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $maxAttempts = max(0, (int) $this->option('max-attempts'));
        $olderThanMinutes = max(0, (int) $this->option('older-than-minutes'));
        $paymentId = $this->option('payment-id');

        $query = TechnicalServiceMountPayment::query()
            ->where('provider', 'iyzico')
            ->where('status', TechnicalServiceMountPayment::STATUS_PENDING)
            ->whereNotNull('provider_reference')
            ->where('provider_reference', '<>', '')
            ->when($paymentId !== null && $paymentId !== '', fn ($query) => $query->whereKey($paymentId))
            ->when($olderThanMinutes > 0, fn ($query) => $query->where('created_at', '<=', now()->subMinutes($olderThanMinutes)))
            ->when($maxAttempts > 0, function ($query) use ($maxAttempts) {
                $query->where(function ($query) use ($maxAttempts): void {
                    $query->whereNull('provider_sync_attempts')
                        ->orWhere('provider_sync_attempts', '<', $maxAttempts);
                });
            })
            ->orderByRaw('provider_last_synced_at is null desc')
            ->orderBy('provider_last_synced_at')
            ->orderBy('id')
            ->limit($limit * 4);

        $skipped = [];
        $candidates = $query->get()
            ->filter(function (TechnicalServiceMountPayment $payment) use (&$skipped, $settings): bool {
                $mode = $this->paymentMode($payment);
                if ($this->option('only-sandbox') && $mode !== 'sandbox') {
                    $skipped[] = [$payment, 'mode_filter_live'];

                    return false;
                }

                if ($this->option('only-live') && $mode !== 'live') {
                    $skipped[] = [$payment, 'mode_filter_sandbox'];

                    return false;
                }

                if (! $settings->providerReconcileReady($mode)) {
                    $skipped[] = [$payment, $settings->providerReconcileDisabledReason($mode)];

                    return false;
                }

                return true;
            })
            ->take($limit)
            ->values();

        if ($this->option('dry-run')) {
            $this->info('Dry run: '.$candidates->count().' Iyzico payment(s) would be reconciled; '.count($skipped).' skipped by mode/readiness.');
            foreach ($candidates as $payment) {
                $this->line('payment_id='.$payment->id.' mode='.$this->paymentMode($payment).' token='.$this->maskedReference($payment->provider_reference));
            }

            foreach ($skipped as [$payment, $reason]) {
                $this->warn('skipped payment_id='.$payment->id.' mode='.$this->paymentMode($payment).' reason='.$reason);
            }

            return self::SUCCESS;
        }

        foreach ($skipped as [$payment, $reason]) {
            $this->warn('skipped payment_id='.$payment->id.' mode='.$this->paymentMode($payment).' reason='.$reason);
        }

        $processed = 0;
        $failed = 0;

        foreach ($candidates as $payment) {
            try {
                $paymentProviderManager->syncPayment($payment->fresh());
                $processed++;

                $fresh = $payment->fresh();
                $this->line('payment_id='.$payment->id.' status='.$fresh->status.' sync_status='.(string) $fresh->provider_last_sync_status);
            } catch (Throwable $exception) {
                $failed++;
                $fresh = $reconciliationService->recordSyncFailure($payment->fresh(), $exception, 'scheduled_reconcile');
                $this->warn('payment_id='.$payment->id.' sync_status='.(string) $fresh->provider_last_sync_status.' error='.$fresh->provider_last_sync_error);
            }
        }

        $this->info('Reconciled '.$processed.' payment(s); '.$failed.' failed.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function paymentMode(TechnicalServiceMountPayment $payment): string
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $mode = Arr::get($payload, 'provider_mode')
            ?? Arr::get($payload, 'provider_decision.provider_mode')
            ?? Arr::get($payload, 'provider_gateway.mode')
            ?? 'sandbox';

        return strtolower((string) $mode) === 'live' ? 'live' : 'sandbox';
    }

    private function maskedReference(?string $reference): string
    {
        $value = trim((string) $reference);
        if ($value === '') {
            return '-';
        }

        return strlen($value) <= 8
            ? str_repeat('*', strlen($value))
            : substr($value, 0, 4).str_repeat('*', max(4, strlen($value) - 8)).substr($value, -4);
    }
}

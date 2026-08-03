<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class PaymentProviderManager
{
    public const CREATE_OUTCOME_NEW_PENDING = 'new_pending';

    public const CREATE_OUTCOME_REUSED_PENDING = 'reused_pending';

    public const CREATE_OUTCOME_ALREADY_PAID = 'already_paid';

    public const CREATE_OUTCOME_TERMINAL_NOT_REUSABLE = 'terminal_not_reusable';

    public const MUTATION_OUTCOME_APPLIED = 'mutation_applied';

    public const MUTATION_OUTCOME_TERMINAL_CONFLICT = 'terminal_conflict';

    public function __construct(
        private readonly TechnicalServicePaymentProviderModeResolver $modeResolver,
        private readonly TechnicalServicePaymentProviderTransportResolver $transportResolver,
        private readonly TechnicalServiceMessagingSettingsService $messagingSettings,
        private readonly TechnicalServicePaymentProviderReconciliationService $reconciliationService,
    ) {}

    public function createPayment(TechnicalServiceMountPayment $payment): array
    {
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $provider = $this->canonicalProviderKey($this->providerName());
        $providerMode = $this->providerModeForFamily($provider);
        $scopedProvider = $this->canonicalProviderIdentity($provider, $providerMode);
        $claim = $this->messagingSettings->claimScopedLocalUatSandboxPaymentEffect(
            $payment,
            TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CREATE,
            $scopedProvider,
        );
        if ($claim['required'] && $claim['duplicate']) {
            $existing = TechnicalServiceMountPayment::query()
                ->findOrFail((int) ($claim['duplicate_payment_id'] ?? $payment->getKey()));
            $result = $this->existingPaymentResponse(
                $existing,
                is_string($claim['outcome'] ?? null)
                    ? $claim['outcome']
                    : self::CREATE_OUTCOME_REUSED_PENDING,
            );
            if ($this->createOutcome($result) === self::CREATE_OUTCOME_REUSED_PENDING) {
                $this->canonicalPaymentFromCreateResult($result);
            }

            return $result;
        }

        if (! $claim['required']) {
            return $this->createCanonicalPayment($payment, $provider);
        }

        try {
            $payment = $payment->refresh();
            if (is_string($claim['claim_nonce'])) {
                $payment = $this->bindScopedLocalUatProviderIdentity(
                    $payment,
                    $provider,
                    $providerMode,
                    $scopedProvider,
                );
                $this->messagingSettings->beginScopedLocalUatEffectDispatch($claim['claim_nonce']);
                $payment = $payment->refresh();
            }
            $this->stampProviderDecision($payment, $provider, $providerMode);
            $this->messagingSettings->assertProviderHttpOutsideTransaction();
            $this->providerForName($scopedProvider)->createPayment($payment->refresh());
            if (is_string($claim['claim_nonce'])) {
                $this->messagingSettings->completeScopedLocalUatEffect($claim['claim_nonce']);
            }

            return $this->existingPaymentResponse($payment->refresh(), self::CREATE_OUTCOME_NEW_PENDING);
        } catch (Throwable $exception) {
            if (is_string($claim['claim_nonce'])) {
                $this->messagingSettings->failScopedLocalUatEffect($claim['claim_nonce'], $exception);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function canonicalPaymentFromCreateResult(array $result): TechnicalServiceMountPayment
    {
        $outcome = $this->createOutcome($result);
        if ($outcome === self::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE) {
            throw new ConflictHttpException('TERMINAL_PAYMENT_NOT_REUSABLE: Terminal odeme explicit retry sozlesmesi olmadan yeniden kullanilamaz.');
        }

        $paymentId = $result['payment_id'] ?? null;
        if (! is_numeric($paymentId) || (int) $paymentId < 1) {
            throw new InvalidArgumentException('Kanonik odeme kaydi create sonucunda bulunamadi.');
        }

        $payment = TechnicalServiceMountPayment::query()->findOrFail((int) $paymentId);
        if ($outcome === self::CREATE_OUTCOME_ALREADY_PAID) {
            if ($payment->status !== TechnicalServiceMountPayment::STATUS_PAID) {
                throw new ConflictHttpException('FAIL_CLOSED_PAYMENT_OUTCOME_STATE_MISMATCH: already_paid sonucu canonical paid state taşımıyor.');
            }

            return $payment;
        }
        if ($payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            return $payment;
        }
        if (! $this->isActionablePending($payment)) {
            throw new ConflictHttpException('PENDING_WITHOUT_SUCCESSFUL_SESSION_NOT_REUSABLE: Canonical pending payment successful session authority taşımıyor.');
        }

        return $payment;
    }

    /** @param array<string, mixed> $result */
    public function canonicalPaymentFromMutationResult(array $result): TechnicalServiceMountPayment
    {
        $paymentId = $result['payment_id'] ?? null;
        $status = is_scalar($result['status'] ?? null) ? (string) $result['status'] : '';
        if (! is_numeric($paymentId) || (int) $paymentId < 1 || $status === '') {
            throw new InvalidArgumentException('FAIL_CLOSED_PAYMENT_MUTATION_RESULT_INVALID: Manager canonical payment sonucu eksik.');
        }

        $payment = TechnicalServiceMountPayment::query()->findOrFail((int) $paymentId);
        if (! hash_equals((string) $payment->status, $status)) {
            throw new ConflictHttpException('FAIL_CLOSED_PAYMENT_MUTATION_RESULT_STALE: Manager sonucu canonical payment state ile eşleşmiyor.');
        }

        return $payment;
    }

    /** @param array<string, mixed> $result */
    public function createOutcome(array $result): string
    {
        $outcome = (string) ($result['outcome'] ?? '');
        if (! in_array($outcome, [
            self::CREATE_OUTCOME_NEW_PENDING,
            self::CREATE_OUTCOME_REUSED_PENDING,
            self::CREATE_OUTCOME_ALREADY_PAID,
            self::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE,
        ], true)) {
            throw new InvalidArgumentException('FAIL_CLOSED_UNKNOWN_PAYMENT_OUTCOME: Kanonik odeme create sonucu typed outcome tasimiyor.');
        }

        return $outcome;
    }

    public function canonicalPaymentForPresentation(
        TechnicalServiceMountPayment $payment,
    ): ?TechnicalServiceMountPayment {
        $payment = $this->messagingSettings->canonicalScopedLocalUatPaymentForPresentation($payment);
        $payment = $this->canonicalPaymentFromDuplicatePointer($payment);

        if ($payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            return $payment;
        }

        return $this->isActionablePending($payment) ? $payment : null;
    }

    public function discardFailedCreatePaymentUnlessAudited(TechnicalServiceMountPayment $payment): void
    {
        $fresh = $payment->fresh();
        if (! $fresh instanceof TechnicalServiceMountPayment) {
            return;
        }
        $history = data_get($fresh->raw_payload, 'scoped_local_uat_effect_history', []);
        $preservedTerminalAudit = is_array(data_get($fresh->raw_payload, 'scoped_local_uat_duplicate_payment'))
            || is_array(data_get($fresh->raw_payload, 'canonical_payment_duplicate'));
        $auditedScopedFailure = $fresh->status === TechnicalServiceMountPayment::STATUS_FAILED
            && is_array($history)
            && collect($history)->contains(fn (mixed $entry): bool => is_array($entry)
                && (string) ($entry['operation'] ?? '') === TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CREATE
                && (string) ($entry['status'] ?? '') === 'failed');

        $auditedCanonicalFailure = $fresh->status === TechnicalServiceMountPayment::STATUS_FAILED
            && collect((array) data_get($fresh->raw_payload, 'canonical_payment_create_history', []))
                ->contains(fn (mixed $entry): bool => is_array($entry)
                    && (string) ($entry['status'] ?? '') === 'failed');

        if (! $auditedScopedFailure && ! $auditedCanonicalFailure && ! $preservedTerminalAudit) {
            $fresh->delete();
        }
    }

    public function updatePayment(TechnicalServiceMountPayment $payment): array
    {
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $this->messagingSettings->assertScopedLocalUatUnsupportedPaymentEffect($payment, 'payment_update');
        $current = $payment->fresh() ?? $payment;
        if ($this->isTerminalStatus((string) $current->status)) {
            return $this->existingPaymentResponse($current, self::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE);
        }
        $this->stampProviderDecision($payment, $this->providerNameForPayment($payment), $this->paymentModeForExistingPayment($payment));
        $before = $payment->refresh();
        $statusBeforeProvider = (string) $before->status;
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $response = $this->providerForPayment($before)->updatePayment($before);
        $preserved = $this->reconciliationService->preserveTerminalStateAfterProviderMutation($before, $statusBeforeProvider);

        return [...$response, 'status' => (string) $preserved->status];
    }

    /** @param array<string, mixed> $audit */
    public function cancelPayment(TechnicalServiceMountPayment $payment, array $audit = []): array
    {
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $this->messagingSettings->assertScopedLocalUatUnsupportedPaymentEffect($payment, 'payment_cancel');
        $current = $payment->fresh() ?? $payment;
        if ($this->isTerminalStatus((string) $current->status)) {
            return $this->existingPaymentResponse($current, self::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE);
        }
        $this->stampProviderDecision($payment, $this->providerNameForPayment($payment), $this->paymentModeForExistingPayment($payment));
        $before = $payment->refresh();
        $statusBeforeProvider = (string) $before->status;
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $response = $this->providerForPayment($before)->cancelPayment($before);
        $preserved = $this->reconciliationService->preserveTerminalStateAfterProviderMutation($before, $statusBeforeProvider);

        $canonical = $this->finalizeCanonicalCancellation($preserved, $audit);
        $outcome = $canonical->status === TechnicalServiceMountPayment::STATUS_CANCELLED
            ? self::MUTATION_OUTCOME_APPLIED
            : self::MUTATION_OUTCOME_TERMINAL_CONFLICT;

        return [...$response, ...$this->existingPaymentResponse($canonical, $outcome)];
    }

    public function syncPayment(TechnicalServiceMountPayment $payment): array
    {
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $this->messagingSettings->assertScopedLocalUatPaymentReconciliationAllowed($payment);
        $current = $payment->fresh() ?? $payment;
        if ($this->isTerminalStatus((string) $current->status)) {
            return $this->existingPaymentResponse($current, self::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE);
        }
        $this->stampProviderDecision($payment, $this->providerNameForPayment($payment), $this->paymentModeForExistingPayment($payment));
        $before = $payment->refresh();
        $statusBeforeProvider = (string) $before->status;
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $response = $this->providerForPayment($before)->syncPayment($before);
        $preserved = $this->reconciliationService->preserveTerminalStateAfterProviderMutation($before, $statusBeforeProvider);

        return [...$response, 'status' => (string) $preserved->status];
    }

    /**
     * Normal production and local fake flows use the same canonical create
     * authority as scoped UAT, without borrowing scoped run state.
     *
     * @return array{payment_id:int,provider_reference:string|null,payment_url:string|null,status:string,outcome:string}
     */
    private function createCanonicalPayment(TechnicalServiceMountPayment $payment, string $provider): array
    {
        $claim = $this->claimCanonicalPaymentCreate($payment, $provider);
        if ($claim['duplicate_payment_id'] !== null) {
            $canonical = TechnicalServiceMountPayment::query()->findOrFail($claim['duplicate_payment_id']);

            return $this->existingPaymentResponse($canonical, $claim['outcome']);
        }

        try {
            $payment = TechnicalServiceMountPayment::query()->findOrFail((int) $payment->getKey());
            $this->stampProviderDecision($payment, $provider);
            $this->messagingSettings->assertProviderHttpOutsideTransaction();
            $this->providerForName($provider)->createPayment($payment->refresh());
            $payment = $this->completeCanonicalPaymentCreate(
                $payment,
                $claim['idempotency_hash'],
                $claim['business_identity_hash'],
                $provider,
            );

            return $this->existingPaymentResponse($payment, self::CREATE_OUTCOME_NEW_PENDING);
        } catch (Throwable $exception) {
            $this->failCanonicalPaymentCreate($payment, $claim['idempotency_hash'], $exception);

            throw $exception;
        }
    }

    /**
     * @return array{duplicate_payment_id:int|null,outcome:string,idempotency_hash:string,business_identity_hash:string}
     */
    private function claimCanonicalPaymentCreate(TechnicalServiceMountPayment $payment, string $provider): array
    {
        return DB::transaction(function () use ($payment, $provider): array {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $identity = $this->messagingSettings->canonicalPaymentBusinessIdentity($locked);
            $amountMinor = $this->messagingSettings->canonicalPaymentAmountMinorUnits($locked);
            $currency = $this->messagingSettings->canonicalPaymentCurrency($locked);
            $provider = $this->canonicalProviderKey($provider);
            $idempotencyHash = $this->canonicalCreateIdempotencyHash(
                $identity['identity_hash'],
                $amountMinor,
                $currency,
                $provider,
            );

            $candidates = $this->paymentCandidates($locked)
                ->where('id', '<', (int) $locked->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($candidates as $candidate) {
                if (! $candidate instanceof TechnicalServiceMountPayment
                    || ! $this->sameCanonicalBusinessEffect($candidate, $identity['identity_hash'], $amountMinor, $currency, $provider)) {
                    continue;
                }

                $outcome = $this->canonicalCandidateOutcome($candidate, $idempotencyHash);
                $this->markCanonicalDuplicate($locked, $candidate, $idempotencyHash, $identity['identity_hash'], $provider);

                return [
                    'duplicate_payment_id' => (int) $candidate->getKey(),
                    'outcome' => $outcome,
                    'idempotency_hash' => $idempotencyHash,
                    'business_identity_hash' => $identity['identity_hash'],
                ];
            }

            $payload = is_array($locked->raw_payload) ? $locked->raw_payload : [];
            $payload['canonical_payment_create_claim'] = [
                'schema_version' => 1,
                'status' => 'claimed',
                'idempotency_hash' => $idempotencyHash,
                'business_identity_hash' => $identity['identity_hash'],
                'provider' => $provider,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'claimed_at' => now()->toIso8601String(),
            ];
            $locked->forceFill(['raw_payload' => $payload])->save();

            return [
                'duplicate_payment_id' => null,
                'outcome' => self::CREATE_OUTCOME_NEW_PENDING,
                'idempotency_hash' => $idempotencyHash,
                'business_identity_hash' => $identity['identity_hash'],
            ];
        });
    }

    private function completeCanonicalPaymentCreate(
        TechnicalServiceMountPayment $payment,
        string $idempotencyHash,
        string $businessIdentityHash,
        string $provider,
    ): TechnicalServiceMountPayment {
        return DB::transaction(function () use ($payment, $idempotencyHash, $businessIdentityHash, $provider): TechnicalServiceMountPayment {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $payload = is_array($locked->raw_payload) ? $locked->raw_payload : [];
            $claim = is_array($payload['canonical_payment_create_claim'] ?? null)
                ? $payload['canonical_payment_create_claim']
                : [];
            if ((string) ($claim['status'] ?? '') !== 'claimed'
                || ! hash_equals((string) ($claim['idempotency_hash'] ?? ''), $idempotencyHash)
                || ! hash_equals((string) ($claim['business_identity_hash'] ?? ''), $businessIdentityHash)) {
                throw new ConflictHttpException('PAYMENT_CREATE_CLAIM_MISMATCH: Provider sonucu current canonical create claim ile eşleşmiyor.');
            }
            $providerReference = trim((string) $locked->provider_reference);
            $paymentUrl = trim((string) $locked->payment_url);
            if ($providerReference === '' || $paymentUrl === '') {
                throw new ConflictHttpException('PENDING_WITHOUT_SUCCESSFUL_SESSION_NOT_REUSABLE: Provider başarılı session/reference/link authority döndürmedi.');
            }
            if (! in_array($locked->status, [
                TechnicalServiceMountPayment::STATUS_PENDING,
                TechnicalServiceMountPayment::STATUS_PAID,
            ], true)) {
                throw new ConflictHttpException('TERMINAL_PAYMENT_NOT_REUSABLE: Provider create sonucu terminal state ile çelişiyor.');
            }

            $authority = [
                'schema_version' => 1,
                'create_status' => 'completed',
                'payment_id' => (int) $locked->getKey(),
                'idempotency_hash' => $idempotencyHash,
                'business_identity_hash' => $businessIdentityHash,
                'provider' => $this->canonicalProviderKey($provider),
                'provider_reference_hash' => hash('sha256', $providerReference),
                'payment_url_hash' => hash('sha256', $paymentUrl),
                'amount_minor' => $this->messagingSettings->canonicalPaymentAmountMinorUnits($locked),
                'currency' => $this->messagingSettings->canonicalPaymentCurrency($locked),
                'completed_at' => now()->toIso8601String(),
            ];
            $history = is_array($payload['canonical_payment_create_history'] ?? null)
                ? $payload['canonical_payment_create_history']
                : [];
            $history[] = [
                ...$authority,
                'operation' => 'payment_create',
                'status' => 'completed',
            ];
            $payload['canonical_payment_create_claim'] = null;
            $payload['canonical_payment_create_history'] = $history;
            $payload['canonical_payment_session_authority'] = $authority;
            $locked->forceFill(['raw_payload' => $payload])->save();

            return $locked->fresh();
        });
    }

    private function failCanonicalPaymentCreate(
        TechnicalServiceMountPayment $payment,
        string $idempotencyHash,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($payment, $idempotencyHash, $exception): void {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof TechnicalServiceMountPayment) {
                return;
            }
            $payload = is_array($locked->raw_payload) ? $locked->raw_payload : [];
            $claim = is_array($payload['canonical_payment_create_claim'] ?? null)
                ? $payload['canonical_payment_create_claim']
                : null;
            if ($claim === null || ! hash_equals((string) ($claim['idempotency_hash'] ?? ''), $idempotencyHash)) {
                return;
            }
            $history = is_array($payload['canonical_payment_create_history'] ?? null)
                ? $payload['canonical_payment_create_history']
                : [];
            $history[] = [
                ...$claim,
                'status' => 'failed',
                'failed_at' => now()->toIso8601String(),
                'error_class' => class_basename($exception),
                'replay_blocked' => true,
            ];
            $payload['canonical_payment_create_claim'] = null;
            $payload['canonical_payment_create_history'] = $history;
            $updates = ['raw_payload' => $payload];
            if ($locked->status === TechnicalServiceMountPayment::STATUS_PENDING) {
                $updates['status'] = TechnicalServiceMountPayment::STATUS_FAILED;
            }
            $locked->forceFill($updates)->save();
        });
    }

    private function sameCanonicalBusinessEffect(
        TechnicalServiceMountPayment $candidate,
        string $businessIdentityHash,
        string $amountMinor,
        string $currency,
        string $provider,
    ): bool {
        try {
            $identity = $this->messagingSettings->canonicalPaymentBusinessIdentity($candidate);

            return hash_equals($identity['identity_hash'], $businessIdentityHash)
                && $this->messagingSettings->canonicalPaymentAmountMinorUnits($candidate) === $amountMinor
                && $this->messagingSettings->canonicalPaymentCurrency($candidate) === $currency
                && $this->canonicalProviderKey((string) $candidate->provider) === $provider;
        } catch (Throwable) {
            return false;
        }
    }

    private function canonicalCandidateOutcome(TechnicalServiceMountPayment $candidate, string $idempotencyHash): string
    {
        if ($candidate->status === TechnicalServiceMountPayment::STATUS_PAID) {
            return self::CREATE_OUTCOME_ALREADY_PAID;
        }
        if ($this->isTerminalStatus((string) $candidate->status)) {
            return self::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE;
        }
        if ($candidate->status !== TechnicalServiceMountPayment::STATUS_PENDING) {
            throw new ConflictHttpException('PENDING_WITHOUT_SUCCESSFUL_SESSION_NOT_REUSABLE: Candidate payment actionable pending değil.');
        }
        $claim = data_get($candidate->raw_payload, 'canonical_payment_create_claim');
        if (is_array($claim) && hash_equals((string) ($claim['idempotency_hash'] ?? ''), $idempotencyHash)) {
            throw new ConflictHttpException('PAYMENT_CREATE_IN_PROGRESS: Aynı business payment effecti provider sonucu bekliyor.');
        }
        if (! $this->isActionablePending($candidate)) {
            throw new ConflictHttpException('PENDING_WITHOUT_SUCCESSFUL_SESSION_NOT_REUSABLE: Pending payment successful provider session authority taşımıyor.');
        }

        return self::CREATE_OUTCOME_REUSED_PENDING;
    }

    private function markCanonicalDuplicate(
        TechnicalServiceMountPayment $payment,
        TechnicalServiceMountPayment $canonical,
        string $idempotencyHash,
        string $businessIdentityHash,
        string $provider,
    ): void {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['canonical_payment_duplicate'] = [
            'schema_version' => 1,
            'status' => 'superseded',
            'canonical_payment_id' => (int) $canonical->getKey(),
            'idempotency_hash' => $idempotencyHash,
            'business_identity_hash' => $businessIdentityHash,
            'provider' => $provider,
            'resolved_at' => now()->toIso8601String(),
        ];
        $payment->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_CANCELLED,
            'raw_payload' => $payload,
        ])->save();
    }

    private function canonicalPaymentFromDuplicatePointer(TechnicalServiceMountPayment $payment): TechnicalServiceMountPayment
    {
        $pointer = data_get($payment->raw_payload, 'canonical_payment_duplicate');
        if (! is_array($pointer)) {
            return $payment;
        }
        $canonicalId = $pointer['canonical_payment_id'] ?? null;
        if ((int) ($pointer['schema_version'] ?? 0) !== 1
            || (string) ($pointer['status'] ?? '') !== 'superseded'
            || $payment->status !== TechnicalServiceMountPayment::STATUS_CANCELLED
            || ! is_numeric($canonicalId)
            || (int) $canonicalId < 1
            || (int) $canonicalId === (int) $payment->getKey()) {
            throw new ConflictHttpException('CANONICAL_PAYMENT_POINTER_INVALID: Superseded payment canonical authority taşımıyor.');
        }

        $canonical = TechnicalServiceMountPayment::query()->findOrFail((int) $canonicalId);
        $identity = $this->messagingSettings->canonicalPaymentBusinessIdentity($canonical);
        if (! hash_equals((string) ($pointer['business_identity_hash'] ?? ''), $identity['identity_hash'])
            || $this->canonicalProviderKey((string) $pointer['provider']) !== $this->canonicalProviderKey((string) $canonical->provider)) {
            throw new ConflictHttpException('CANONICAL_PAYMENT_POINTER_INVALID: Superseded payment pointer stored business authority ile eşleşmiyor.');
        }

        return $canonical;
    }

    private function isActionablePending(TechnicalServiceMountPayment $payment): bool
    {
        if ($payment->status !== TechnicalServiceMountPayment::STATUS_PENDING
            || trim((string) $payment->provider_reference) === ''
            || trim((string) $payment->payment_url) === '') {
            return false;
        }
        $canonicalFailed = collect((array) data_get($payment->raw_payload, 'canonical_payment_create_history', []))
            ->contains(fn (mixed $entry): bool => is_array($entry) && (string) ($entry['status'] ?? '') === 'failed');
        $scopedFailed = collect((array) data_get($payment->raw_payload, 'scoped_local_uat_effect_history', []))
            ->contains(fn (mixed $entry): bool => is_array($entry)
                && (string) ($entry['operation'] ?? '') === TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CREATE
                && (string) ($entry['status'] ?? '') === 'failed');
        if ($canonicalFailed || $scopedFailed) {
            return false;
        }
        $authority = data_get($payment->raw_payload, 'canonical_payment_session_authority');
        if (is_array($authority)) {
            return $this->canonicalPendingAuthorityIsCurrent($payment, $authority);
        }

        $scopedAuthority = data_get($payment->raw_payload, 'scoped_local_uat_payment_session_authority');

        return is_array($scopedAuthority)
            && (string) ($scopedAuthority['create_status'] ?? '') === 'completed'
            && (int) ($scopedAuthority['payment_id'] ?? 0) === (int) $payment->getKey()
            && hash_equals((string) ($scopedAuthority['provider_reference'] ?? ''), trim((string) $payment->provider_reference))
            && $this->messagingSettings->scopedLocalUatPaymentSessionIsCurrent($payment);
    }

    /** @param array<string, mixed> $authority */
    private function canonicalPendingAuthorityIsCurrent(
        TechnicalServiceMountPayment $payment,
        array $authority,
    ): bool {
        try {
            $identity = $this->messagingSettings->canonicalPaymentBusinessIdentity($payment);
            $amountMinor = $this->messagingSettings->canonicalPaymentAmountMinorUnits($payment);
            $currency = $this->messagingSettings->canonicalPaymentCurrency($payment);
            $provider = $this->canonicalProviderKey((string) $payment->provider);
            $idempotencyHash = $this->canonicalCreateIdempotencyHash(
                $identity['identity_hash'],
                $amountMinor,
                $currency,
                $provider,
            );
            $providerReferenceHash = hash('sha256', trim((string) $payment->provider_reference));
            $paymentUrlHash = hash('sha256', trim((string) $payment->payment_url));
            $authorityMatches = (int) ($authority['schema_version'] ?? 0) === 1
                && (string) ($authority['create_status'] ?? '') === 'completed'
                && (int) ($authority['payment_id'] ?? 0) === (int) $payment->getKey()
                && hash_equals((string) ($authority['idempotency_hash'] ?? ''), $idempotencyHash)
                && hash_equals((string) ($authority['business_identity_hash'] ?? ''), $identity['identity_hash'])
                && (string) ($authority['provider'] ?? '') === $provider
                && hash_equals((string) ($authority['provider_reference_hash'] ?? ''), $providerReferenceHash)
                && hash_equals((string) ($authority['payment_url_hash'] ?? ''), $paymentUrlHash)
                && (string) ($authority['amount_minor'] ?? '') === $amountMinor
                && (string) ($authority['currency'] ?? '') === $currency;
            if (! $authorityMatches) {
                return false;
            }

            return collect((array) data_get($payment->raw_payload, 'canonical_payment_create_history', []))
                ->contains(fn (mixed $entry): bool => is_array($entry)
                    && (string) ($entry['operation'] ?? '') === 'payment_create'
                    && (string) ($entry['status'] ?? '') === 'completed'
                    && (int) ($entry['payment_id'] ?? 0) === (int) $payment->getKey()
                    && hash_equals((string) ($entry['idempotency_hash'] ?? ''), $idempotencyHash)
                    && hash_equals((string) ($entry['business_identity_hash'] ?? ''), $identity['identity_hash'])
                    && (string) ($entry['provider'] ?? '') === $provider
                    && hash_equals((string) ($entry['provider_reference_hash'] ?? ''), $providerReferenceHash)
                    && hash_equals((string) ($entry['payment_url_hash'] ?? ''), $paymentUrlHash)
                    && (string) ($entry['amount_minor'] ?? '') === $amountMinor
                    && (string) ($entry['currency'] ?? '') === $currency);
        } catch (Throwable) {
            return false;
        }
    }

    private function canonicalCreateIdempotencyHash(
        string $businessIdentityHash,
        string $amountMinor,
        string $currency,
        string $provider,
    ): string {
        return hash('sha256', json_encode([
            'schema_version' => 1,
            'operation' => 'payment_create',
            'business_identity_hash' => $businessIdentityHash,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'provider' => $provider,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $audit */
    private function finalizeCanonicalCancellation(
        TechnicalServiceMountPayment $payment,
        array $audit,
    ): TechnicalServiceMountPayment {
        return DB::transaction(function () use ($payment, $audit): TechnicalServiceMountPayment {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $paidEvidence = $locked->status === TechnicalServiceMountPayment::STATUS_PAID
                || $locked->paid_at !== null
                || $locked->provider_paid_confirmed_at !== null;
            if ($paidEvidence) {
                if ($locked->status !== TechnicalServiceMountPayment::STATUS_PAID) {
                    $locked->forceFill(['status' => TechnicalServiceMountPayment::STATUS_PAID])->save();
                }

                return $locked->fresh();
            }
            if (in_array($locked->status, [
                TechnicalServiceMountPayment::STATUS_FAILED,
                TechnicalServiceMountPayment::STATUS_EXPIRED,
            ], true)) {
                return $locked;
            }
            if (! in_array($locked->status, [
                TechnicalServiceMountPayment::STATUS_PENDING,
                TechnicalServiceMountPayment::STATUS_CANCELLED,
            ], true)) {
                throw new ConflictHttpException('PAYMENT_CANCEL_STATE_CONFLICT: Canonical payment cancel için uygun state taşımıyor.');
            }

            $payload = is_array($locked->raw_payload) ? $locked->raw_payload : [];
            $payload = array_replace($payload, Arr::only($audit, [
                'cancelled_at',
                'cancelled_by_user_id',
                'cancelled_by_name',
                'cancellation_reason',
                'cancel_source',
            ]));
            $locked->forceFill([
                'status' => TechnicalServiceMountPayment::STATUS_CANCELLED,
                'raw_payload' => $payload,
            ])->save();

            return $locked->fresh();
        });
    }

    private function paymentCandidates(TechnicalServiceMountPayment $payment): Builder
    {
        $query = TechnicalServiceMountPayment::query();
        if (is_numeric($payment->technical_service_mount_session_id)) {
            return $query->where('technical_service_mount_session_id', (int) $payment->technical_service_mount_session_id);
        }
        if (is_numeric($payment->technical_service_request_id)) {
            return $query->where('technical_service_request_id', (int) $payment->technical_service_request_id);
        }

        throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: Payment canonical lock authority çözülemedi.');
    }

    private function canonicalProviderKey(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'fake', 'fake_payment' => 'fake',
            'iyzico', 'iyzico_sandbox', 'iyzico_live' => 'iyzico',
            default => throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: Payment provider canonical değil.'),
        };
    }

    private function isTerminalStatus(?string $status): bool
    {
        return in_array($status, [
            TechnicalServiceMountPayment::STATUS_PAID,
            TechnicalServiceMountPayment::STATUS_FAILED,
            TechnicalServiceMountPayment::STATUS_CANCELLED,
            TechnicalServiceMountPayment::STATUS_EXPIRED,
        ], true);
    }

    public function provider(): PaymentProviderInterface
    {
        return $this->providerForName($this->configuredProviderName());
    }

    private function providerForName(string $provider): PaymentProviderInterface
    {
        return match (strtolower(trim($provider))) {
            'fake' => app(FakePaymentProvider::class),
            'fake_payment' => app(FakePaymentProvider::class),
            'iyzico', 'iyzico_sandbox', 'iyzico_live' => app(IyzicoPaymentProvider::class),
            default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi.'),
        };
    }

    public function providerName(): string
    {
        return $this->modeResolver->activeProviderName();
    }

    public function environment(): string
    {
        return $this->modeResolver->environment();
    }

    private function configuredProviderName(): string
    {
        return $this->modeResolver->activeProviderName();
    }

    private function providerNameForPayment(TechnicalServiceMountPayment $payment): string
    {
        return strtolower((string) ($payment->provider ?: $this->configuredProviderName()));
    }

    private function providerModeForFamily(string $provider): string
    {
        return match ($this->canonicalProviderKey($provider)) {
            'fake' => 'local',
            'iyzico' => match ($this->modeResolver->gatewayMode()) {
                'sandbox' => 'sandbox',
                'live' => 'live',
                default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi modu.'),
            },
            default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi.'),
        };
    }

    private function canonicalProviderIdentity(string $provider, ?string $providerMode = null): string
    {
        $providerFamily = $this->canonicalProviderKey($provider);
        $providerMode = strtolower(trim($providerMode ?? $this->providerModeForFamily($providerFamily)));

        return match ([$providerFamily, $providerMode]) {
            ['fake', 'local'] => 'fake_payment',
            ['iyzico', 'sandbox'] => 'iyzico_sandbox',
            ['iyzico', 'live'] => 'iyzico_live',
            default => throw new ConflictHttpException('scoped_uat_provider_identity_invalid: Provider family ve mode authority canonical değil.'),
        };
    }

    private function bindScopedLocalUatProviderIdentity(
        TechnicalServiceMountPayment $payment,
        string $providerFamily,
        string $providerMode,
        string $providerIdentity,
    ): TechnicalServiceMountPayment {
        return DB::transaction(function () use ($payment, $providerFamily, $providerMode, $providerIdentity): TechnicalServiceMountPayment {
            $locked = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $storedProviderFamily = strtolower(trim((string) $locked->provider));
            if (! hash_equals($providerFamily, $storedProviderFamily)) {
                throw new ConflictHttpException('scoped_uat_provider_snapshot_mismatch: provider_family_mismatch; payment provider family stored authority ile eşleşmiyor.');
            }

            $expectedIdentity = $this->canonicalProviderIdentity($providerFamily, $providerMode);
            if (! hash_equals($expectedIdentity, $providerIdentity)) {
                throw new ConflictHttpException('scoped_uat_provider_identity_mismatch: Payment provider identity immutable run authority ile eşleşmiyor.');
            }

            $payload = is_array($locked->raw_payload) ? $locked->raw_payload : [];
            $storedMode = Arr::get($payload, 'provider_mode')
                ?? Arr::get($payload, 'provider_decision.provider_mode')
                ?? Arr::get($payload, 'provider_gateway.mode')
                ?? Arr::get($payload, 'provider_gateway.provider_mode');
            if ($storedMode !== null && (! is_scalar($storedMode)
                || ! hash_equals($providerIdentity, $this->canonicalProviderIdentity($storedProviderFamily, (string) $storedMode)))) {
                throw new ConflictHttpException('scoped_uat_provider_mode_mismatch: Payment provider mode stored authority ile eşleşmiyor.');
            }

            $payload['provider_mode'] = $providerMode;
            $locked->forceFill(['raw_payload' => $payload])->save();

            return $locked->fresh();
        });
    }

    /**
     * @return array{payment_id:int,provider_reference:string|null,payment_url:string|null,status:string,outcome:string}
     */
    private function existingPaymentResponse(TechnicalServiceMountPayment $payment, string $outcome): array
    {
        return [
            'payment_id' => (int) $payment->getKey(),
            'provider_reference' => $payment->provider_reference,
            'payment_url' => $payment->payment_url,
            'status' => (string) $payment->status,
            'outcome' => $outcome,
        ];
    }

    private function providerForPayment(TechnicalServiceMountPayment $payment): PaymentProviderInterface
    {
        return match ($this->providerNameForPayment($payment)) {
            'fake' => app(FakePaymentProvider::class),
            'iyzico', 'iyzico_sandbox', 'iyzico_live' => app(IyzicoPaymentProvider::class),
            default => throw new InvalidArgumentException('Desteklenmeyen odeme saglayicisi.'),
        };
    }

    private function stampProviderDecision(TechnicalServiceMountPayment $payment, string $provider, ?string $providerMode = null): void
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $provider = $this->canonicalProviderKey($provider);
        $transport = $provider === 'fake'
            ? 'fake_local'
            : $this->transportResolver->activeTransport();
        $providerMode = $providerMode ?? $this->providerModeForFamily($provider);
        $this->canonicalProviderIdentity($provider, $providerMode);

        $payload['provider_decision'] = [
            'provider' => $provider,
            'provider_mode' => $providerMode,
            'provider_transport' => $transport,
            'environment' => $this->environment(),
            'real_provider_enabled' => $this->modeResolver->realProviderEnabled(),
            'decided_at' => now()->toIso8601String(),
        ];
        $payload['provider_mode'] = $payload['provider_decision']['provider_mode'];
        $payload['provider_transport'] = $transport;
        $payload['provider_environment'] = $this->environment();

        $payment->forceFill([
            'provider' => $provider,
            'raw_payload' => $payload,
        ])->save();
    }

    private function paymentModeForExistingPayment(TechnicalServiceMountPayment $payment): ?string
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $mode = Arr::get($payload, 'provider_mode')
            ?? Arr::get($payload, 'provider_decision.provider_mode')
            ?? Arr::get($payload, 'provider_gateway.mode')
            ?? Arr::get($payload, 'provider_gateway.provider_mode');

        if ($mode === null || $mode === '') {
            return null;
        }

        return match (strtolower(trim((string) $mode))) {
            'local' => 'local',
            'sandbox' => 'sandbox',
            'live' => 'live',
            default => throw new ConflictHttpException('scoped_uat_provider_mode_invalid: Stored payment provider mode canonical değil.'),
        };
    }
}

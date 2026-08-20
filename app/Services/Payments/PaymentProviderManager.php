<?php

namespace App\Services\Payments;

use App\Models\TechnicalServiceMountPayment;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Support\PartnerPortalPublicUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
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

    /**
     * @param  array{provider_family:string,provider_mode:string,provider_transport:string,provider_environment:string,provider_identity:string,profile_fingerprint:string}|null  $resolvedProfile
     */
    public function createPayment(TechnicalServiceMountPayment $payment, ?array $resolvedProfile = null): array
    {
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $profile = $this->validatedCreateProfile($resolvedProfile ?? $this->resolvedCreateProfile());
        $provider = $profile['provider_family'];
        $providerMode = $profile['provider_mode'];
        $scopedProvider = $this->messagingSettings->canonicalScopedLocalUatProviderIdentity(
            $provider,
            $providerMode,
        );
        $this->stampProviderDecision($payment, $provider, $providerMode, $profile);
        $payment = $payment->refresh();
        $businessIdentityBefore = $this->messagingSettings->canonicalPaymentBusinessIdentity($payment)['identity_hash'];
        $claim = $this->messagingSettings->claimScopedLocalUatSandboxPaymentEffect(
            $payment,
            TechnicalServiceMessagingSettingsService::SCOPED_EFFECT_PAYMENT_CREATE,
            $provider,
            $providerMode,
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
            return $this->createCanonicalPayment($payment, $provider, $providerMode, $businessIdentityBefore);
        }

        try {
            $payment = $payment->refresh();
            if (is_string($claim['claim_nonce'])) {
                $this->messagingSettings->beginScopedLocalUatEffectDispatch($claim['claim_nonce']);
                $payment = $payment->refresh();
            }
            $this->messagingSettings->assertProviderHttpOutsideTransaction();
            $this->providerForName($scopedProvider)->createPayment($payment->refresh());
            $payment = $this->assertProviderCreateAttached(
                $payment->refresh(),
                $provider,
                $providerMode,
                $businessIdentityBefore,
            );
            if (is_string($claim['claim_nonce'])) {
                $this->messagingSettings->completeScopedLocalUatEffect($claim['claim_nonce']);
            }

            return $this->existingPaymentResponse($payment->refresh(), self::CREATE_OUTCOME_NEW_PENDING);
        } catch (Throwable $exception) {
            $this->recordProviderCreateFailure($payment, $exception);
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
            $this->markProviderCreateOutcome(
                $fresh,
                'provider_effect_ambiguous',
                false,
                true,
                'Canonical create audit kesinleştirilemedi; yerel ödeme sahibi korundu.',
            );
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

    /** @return array<string, mixed> */
    public function verifyExactPaymentReconciliation(
        TechnicalServiceMountPayment $payment,
        string $providerPaymentReference,
    ): array {
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $this->messagingSettings->assertScopedLocalUatPaymentReconciliationAllowed($payment);

        return $this->reconciliationService->verifyExactProviderPaymentResult(
            $payment->fresh() ?? $payment,
            $providerPaymentReference,
        );
    }

    /** @return array<string, mixed> */
    public function reconcileExactPayment(
        TechnicalServiceMountPayment $payment,
        string $providerPaymentReference,
    ): array {
        $this->messagingSettings->assertProviderHttpOutsideTransaction();
        $this->messagingSettings->assertScopedLocalUatPaymentReconciliationAllowed($payment);
        $result = $this->reconciliationService->reconcileExactProviderPaymentResult(
            $payment->fresh() ?? $payment,
            $providerPaymentReference,
        );
        $paid = $result['payment'];

        return [
            'payment_id' => (int) $paid->id,
            'status' => (string) $paid->status,
            'provider_payment_reference' => $paid->provider_payment_reference,
            'provider_transaction_reference' => $paid->provider_transaction_reference,
            'provider_receipt_reference' => $paid->provider_receipt_reference,
            'verification' => $result['proof'],
        ];
    }

    /**
     * Normal production and local fake flows use the same canonical create
     * authority as scoped UAT, without borrowing scoped run state.
     *
     * @return array{payment_id:int,provider_reference:string|null,payment_url:string|null,status:string,outcome:string}
     */
    private function createCanonicalPayment(
        TechnicalServiceMountPayment $payment,
        string $provider,
        string $providerMode,
        string $businessIdentityBefore,
    ): array {
        $claim = $this->claimCanonicalPaymentCreate($payment, $provider);
        if ($claim['duplicate_payment_id'] !== null) {
            $canonical = TechnicalServiceMountPayment::query()->findOrFail($claim['duplicate_payment_id']);

            return $this->existingPaymentResponse($canonical, $claim['outcome']);
        }

        try {
            $payment = TechnicalServiceMountPayment::query()->findOrFail((int) $payment->getKey());
            $this->messagingSettings->assertProviderHttpOutsideTransaction();
            $this->providerForName($provider)->createPayment($payment->refresh());
            $payment = $this->assertProviderCreateAttached(
                $payment->refresh(),
                $provider,
                $providerMode,
                $businessIdentityBefore,
            );
            $payment = $this->completeCanonicalPaymentCreate(
                $payment,
                $claim['idempotency_hash'],
                $claim['business_identity_hash'],
                $provider,
            );

            return $this->existingPaymentResponse($payment, self::CREATE_OUTCOME_NEW_PENDING);
        } catch (Throwable $exception) {
            $this->recordProviderCreateFailure($payment, $exception);
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
            $matchingCandidates = $candidates
                ->filter(fn (mixed $candidate): bool => $candidate instanceof TechnicalServiceMountPayment
                    && $this->sameCanonicalBusinessEffect($candidate, $identity['identity_hash'], $amountMinor, $currency, $provider))
                ->values();

            $paidCandidate = $matchingCandidates
                ->filter(fn (TechnicalServiceMountPayment $candidate): bool => $candidate->status === TechnicalServiceMountPayment::STATUS_PAID)
                ->last();
            if ($paidCandidate instanceof TechnicalServiceMountPayment) {
                $this->markCanonicalDuplicate($locked, $paidCandidate, $idempotencyHash, $identity['identity_hash'], $provider);

                return [
                    'duplicate_payment_id' => (int) $paidCandidate->getKey(),
                    'outcome' => self::CREATE_OUTCOME_ALREADY_PAID,
                    'idempotency_hash' => $idempotencyHash,
                    'business_identity_hash' => $identity['identity_hash'],
                ];
            }

            foreach ($matchingCandidates->where('status', TechnicalServiceMountPayment::STATUS_PENDING) as $candidate) {
                $outcome = $this->canonicalCandidateOutcome($candidate, $idempotencyHash);
                $this->markCanonicalDuplicate($locked, $candidate, $idempotencyHash, $identity['identity_hash'], $provider);

                return [
                    'duplicate_payment_id' => (int) $candidate->getKey(),
                    'outcome' => $outcome,
                    'idempotency_hash' => $idempotencyHash,
                    'business_identity_hash' => $identity['identity_hash'],
                ];
            }

            $terminalCandidates = $matchingCandidates
                ->filter(fn (TechnicalServiceMountPayment $candidate): bool => in_array($candidate->status, [
                    TechnicalServiceMountPayment::STATUS_FAILED,
                    TechnicalServiceMountPayment::STATUS_CANCELLED,
                    TechnicalServiceMountPayment::STATUS_EXPIRED,
                ], true))
                ->values();
            if ($terminalCandidates->isNotEmpty()) {
                $terminalCandidate = $terminalCandidates->last();
                $operationsReviewRequired = $terminalCandidates->contains(
                    fn (TechnicalServiceMountPayment $candidate): bool => (bool) data_get(
                        $candidate->raw_payload,
                        'payment_create_outcome.operations_review_required',
                        false,
                    ),
                );
                $retryAuthority = $this->terminalRetryAuthority($locked);
                if (! $terminalCandidate instanceof TechnicalServiceMountPayment
                    || $operationsReviewRequired
                    || $retryAuthority === null) {
                    $terminalCandidate ??= $terminalCandidates->first();
                    if (! $terminalCandidate instanceof TechnicalServiceMountPayment) {
                        throw new ConflictHttpException('TERMINAL_PAYMENT_NOT_REUSABLE: Terminal ödeme authority çözülemedi.');
                    }
                    $this->markCanonicalDuplicate($locked, $terminalCandidate, $idempotencyHash, $identity['identity_hash'], $provider);

                    return [
                        'duplicate_payment_id' => (int) $terminalCandidate->getKey(),
                        'outcome' => self::CREATE_OUTCOME_TERMINAL_NOT_REUSABLE,
                        'idempotency_hash' => $idempotencyHash,
                        'business_identity_hash' => $identity['identity_hash'],
                    ];
                }

                $payload = is_array($locked->raw_payload) ? $locked->raw_payload : [];
                $payload['canonical_payment_terminal_retry'] = [
                    ...$retryAuthority,
                    'status' => 'consumed',
                    'terminal_payment_ids' => $terminalCandidates
                        ->map(fn (TechnicalServiceMountPayment $candidate): int => (int) $candidate->getKey())
                        ->all(),
                    'consumed_at' => now()->toIso8601String(),
                ];
                $locked->forceFill(['raw_payload' => $payload])->save();
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

    /** @return array{schema_version:int,source:string,reason:string,requested_by_user_id:int,requested_at:string}|null */
    private function terminalRetryAuthority(TechnicalServiceMountPayment $payment): ?array
    {
        if ((bool) data_get($payment->raw_payload, 'payment_create_outcome.operations_review_required', false)) {
            return null;
        }

        $retry = data_get($payment->raw_payload, 'canonical_payment_terminal_retry');
        if (! is_array($retry)
            || (int) ($retry['schema_version'] ?? 0) !== 1
            || (string) ($retry['source'] ?? '') !== 'ops_explicit_terminal_retry'
            || ! is_numeric($retry['requested_by_user_id'] ?? null)
            || (int) $retry['requested_by_user_id'] < 1
            || trim((string) ($retry['requested_at'] ?? '')) === '') {
            return null;
        }

        $reason = trim((string) ($retry['reason'] ?? ''));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            return null;
        }

        return [
            'schema_version' => 1,
            'source' => 'ops_explicit_terminal_retry',
            'reason' => $reason,
            'requested_by_user_id' => (int) $retry['requested_by_user_id'],
            'requested_at' => (string) $retry['requested_at'],
        ];
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
        $providerCreateState = (string) data_get($payment->raw_payload, 'payment_create_outcome.state', '');
        if ($providerCreateState !== '' && $providerCreateState !== 'provider_success_attached') {
            return false;
        }
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

    /**
     * Resolve the immutable create profile once before identity, claims, or provider I/O.
     *
     * @return array{provider_family:string,provider_mode:string,provider_transport:string,provider_environment:string,provider_identity:string,profile_fingerprint:string}
     */
    public function resolvedCreateProfile(): array
    {
        $provider = $this->canonicalProviderKey($this->providerName());
        $providerMode = $this->providerModeForFamily($provider);
        if ($provider === 'iyzico' && $providerMode === 'live' && app()->environment('local', 'testing')) {
            throw new ConflictHttpException('PAYMENT_PROVIDER_LIVE_FORBIDDEN: Local/UAT ortamında live ödeme sağlayıcısı kullanılamaz.');
        }
        $providerTransport = $provider === 'fake'
            ? 'fake_local'
            : $this->transportResolver->activeTransport();
        $providerIdentity = $this->messagingSettings->canonicalScopedLocalUatProviderIdentity(
            $provider,
            $providerMode,
        );
        $profile = [
            'provider_family' => $provider,
            'provider_mode' => $providerMode,
            'provider_transport' => $providerTransport,
            'provider_environment' => $this->environment(),
            'provider_identity' => $providerIdentity,
        ];

        return [
            ...$profile,
            'profile_fingerprint' => hash('sha256', json_encode(
                $profile,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{provider_family:string,provider_mode:string,provider_transport:string,provider_environment:string,provider_identity:string,profile_fingerprint:string}
     */
    private function validatedCreateProfile(array $profile): array
    {
        $provider = $this->canonicalProviderKey((string) ($profile['provider_family'] ?? ''));
        $providerMode = strtolower(trim((string) ($profile['provider_mode'] ?? '')));
        $providerTransport = strtolower(trim((string) ($profile['provider_transport'] ?? '')));
        $providerEnvironment = strtolower(trim((string) ($profile['provider_environment'] ?? '')));
        $providerIdentity = strtolower(trim((string) ($profile['provider_identity'] ?? '')));
        $profileFingerprint = strtolower(trim((string) ($profile['profile_fingerprint'] ?? '')));

        if (! in_array($providerMode, ['local', 'sandbox', 'live'], true)
            || $providerTransport === ''
            || $providerEnvironment === ''
            || $providerIdentity === ''
            || ! preg_match('/^[a-f0-9]{64}$/', $profileFingerprint)) {
            throw new ConflictHttpException('PAYMENT_PROVIDER_PROFILE_INVALID: Provider create profili eksik veya geçersiz.');
        }
        if ($provider === 'fake' && $providerMode !== 'local') {
            throw new ConflictHttpException('PAYMENT_PROVIDER_PROFILE_INVALID: Fake provider yalnız local modda çalışabilir.');
        }
        if ($provider === 'iyzico' && ! in_array($providerMode, ['sandbox', 'live'], true)) {
            throw new ConflictHttpException('PAYMENT_PROVIDER_PROFILE_INVALID: Iyzico provider modu canonical değil.');
        }
        if ($provider === 'iyzico' && $providerMode === 'live' && app()->environment('local', 'testing')) {
            throw new ConflictHttpException('PAYMENT_PROVIDER_LIVE_FORBIDDEN: Local/UAT ortamında live ödeme sağlayıcısı kullanılamaz.');
        }

        $expectedIdentity = $this->messagingSettings->canonicalScopedLocalUatProviderIdentity(
            $provider,
            $providerMode,
        );
        $fingerprintPayload = [
            'provider_family' => $provider,
            'provider_mode' => $providerMode,
            'provider_transport' => $providerTransport,
            'provider_environment' => $providerEnvironment,
            'provider_identity' => $expectedIdentity,
        ];
        $expectedFingerprint = hash('sha256', json_encode(
            $fingerprintPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        if (! hash_equals($expectedIdentity, $providerIdentity)
            || ! hash_equals($expectedFingerprint, $profileFingerprint)) {
            throw new ConflictHttpException('PAYMENT_PROVIDER_PROFILE_DRIFT: Provider create profili çözüm sonrasında değişti.');
        }

        return [
            ...$fingerprintPayload,
            'profile_fingerprint' => $expectedFingerprint,
        ];
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

    /** @param array<string, mixed>|null $resolvedProfile */
    private function stampProviderDecision(
        TechnicalServiceMountPayment $payment,
        string $provider,
        ?string $providerMode = null,
        ?array $resolvedProfile = null,
    ): void {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $provider = $this->canonicalProviderKey($provider);
        $profile = $resolvedProfile === null
            ? null
            : $this->validatedCreateProfile($resolvedProfile);
        $providerMode = $profile['provider_mode'] ?? $providerMode ?? $this->providerModeForFamily($provider);
        $transport = $profile['provider_transport'] ?? ($provider === 'fake'
            ? 'fake_local'
            : $this->transportResolver->activeTransport());
        $environment = $profile['provider_environment'] ?? $this->environment();
        $providerIdentity = $profile['provider_identity']
            ?? $this->messagingSettings->canonicalScopedLocalUatProviderIdentity($provider, $providerMode);

        if ($profile !== null && ($profile['provider_family'] !== $provider || $profile['provider_mode'] !== $providerMode)) {
            throw new ConflictHttpException('PAYMENT_PROVIDER_PROFILE_DRIFT: Provider create profili payment kararıyla eşleşmiyor.');
        }

        $payload['provider_decision'] = [
            'provider' => $provider,
            'provider_mode' => $providerMode,
            'provider_transport' => $transport,
            'environment' => $environment,
            'provider_identity' => $providerIdentity,
            'profile_fingerprint' => $profile['profile_fingerprint'] ?? null,
            'real_provider_enabled' => $this->modeResolver->realProviderEnabled(),
            'decided_at' => now()->toIso8601String(),
        ];
        $payload['provider_mode'] = $payload['provider_decision']['provider_mode'];
        $payload['provider_transport'] = $transport;
        $payload['provider_environment'] = $environment;
        if ($profile !== null) {
            $payload['provider_profile_fingerprint'] = $profile['profile_fingerprint'];
        }

        $payment->forceFill([
            'provider' => $provider,
            'raw_payload' => $payload,
        ])->save();
    }

    private function assertProviderCreateAttached(
        TechnicalServiceMountPayment $payment,
        string $provider,
        string $providerMode,
        string $businessIdentityBefore,
    ): TechnicalServiceMountPayment {
        $fresh = $payment->refresh();
        $businessIdentityAfter = $this->messagingSettings->canonicalPaymentBusinessIdentity($fresh)['identity_hash'];
        if (! hash_equals($businessIdentityBefore, $businessIdentityAfter)) {
            throw new ConflictHttpException('PAYMENT_BUSINESS_IDENTITY_DRIFT: Provider sonucu canonical ödeme kimliğini değiştirdi.');
        }

        $paymentUrl = trim((string) $fresh->payment_url);
        try {
            $trustedUrl = $provider === 'iyzico'
                ? PartnerPortalPublicUrl::trustedPaymentProviderUrl($paymentUrl)
                : PartnerPortalPublicUrl::rebaseLegacyUrl($paymentUrl);
        } catch (InvalidArgumentException $exception) {
            throw new ConflictHttpException('PAYMENT_PROVIDER_URL_INVALID: Sağlayıcı güvenli bir ödeme bağlantısı döndürmedi.', $exception);
        }
        if ($trustedUrl === null || trim((string) $fresh->provider_reference) === '') {
            throw new ConflictHttpException('PAYMENT_PROVIDER_URL_INVALID: Sağlayıcı ödeme bağlantısı veya referansı eksik.');
        }
        $host = strtolower((string) parse_url($trustedUrl, PHP_URL_HOST));
        if ($provider === 'iyzico' && $providerMode === 'sandbox' && $host !== 'sandbox.iyzi.link') {
            throw new ConflictHttpException('PAYMENT_PROVIDER_URL_INVALID: Iyzico Sandbox bağlantı hostu canonical değil.');
        }
        if ($provider === 'iyzico' && $providerMode === 'live' && app()->environment('local', 'testing')) {
            throw new ConflictHttpException('PAYMENT_PROVIDER_LIVE_FORBIDDEN: Local/UAT ortamında live ödeme bağlantısı kullanılamaz.');
        }

        return $this->markProviderCreateOutcome(
            $fresh,
            'provider_success_attached',
            false,
            false,
            null,
            $businessIdentityBefore,
            $businessIdentityAfter,
        );
    }

    private function recordProviderCreateFailure(
        TechnicalServiceMountPayment $payment,
        Throwable $exception,
    ): void {
        $fresh = $payment->fresh();
        if (! $fresh instanceof TechnicalServiceMountPayment) {
            return;
        }

        $message = $exception->getMessage();
        $providerReturnedIdentity = trim((string) $fresh->provider_reference) !== ''
            || trim((string) $fresh->payment_url) !== '';
        $urlInvalid = str_starts_with($message, 'PAYMENT_PROVIDER_URL_INVALID:');
        $ambiguous = $providerReturnedIdentity || $this->exceptionChainContains($exception, ConnectionException::class);
        $state = $urlInvalid
            ? 'provider_success_url_invalid'
            : ($ambiguous ? 'provider_effect_ambiguous' : 'provider_rejected');
        $reviewRequired = $urlInvalid || $ambiguous;

        $this->markProviderCreateOutcome(
            $fresh,
            $state,
            ! $reviewRequired,
            $reviewRequired,
            $reviewRequired
                ? 'Sağlayıcı etkisi kesinleştirilemedi; yeni dış işlem operasyon kontrolü olmadan başlatılamaz.'
                : 'Iyzico Sandbox ödeme bağlantısı hazırlanamadı.',
        );
    }

    private function markProviderCreateOutcome(
        TechnicalServiceMountPayment $payment,
        string $state,
        bool $retryAllowed,
        bool $operationsReviewRequired,
        ?string $message,
        ?string $businessIdentityBefore = null,
        ?string $businessIdentityAfter = null,
    ): TechnicalServiceMountPayment {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['payment_create_outcome'] = array_filter([
            'schema_version' => 1,
            'state' => $state,
            'retry_allowed' => $retryAllowed,
            'operations_review_required' => $operationsReviewRequired,
            'message' => $message,
            'business_identity_before' => $businessIdentityBefore,
            'business_identity_after' => $businessIdentityAfter,
            'recorded_at' => now()->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null);
        $updates = ['raw_payload' => $payload];
        if ($state !== 'provider_success_attached'
            && $payment->status === TechnicalServiceMountPayment::STATUS_PENDING) {
            $updates['status'] = TechnicalServiceMountPayment::STATUS_FAILED;
        }
        $payment->forceFill($updates)->save();

        return $payment->fresh();
    }

    private function exceptionChainContains(Throwable $exception, string $class): bool
    {
        $current = $exception;
        do {
            if ($current instanceof $class) {
                return true;
            }
            $current = $current->getPrevious();
        } while ($current instanceof Throwable);

        return false;
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

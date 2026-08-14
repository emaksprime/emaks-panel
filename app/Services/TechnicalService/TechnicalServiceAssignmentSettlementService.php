<?php

namespace App\Services\TechnicalService;

use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceEarningPayment;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TechnicalServiceAssignmentSettlementService
{
    public const DECISION_PAY_TECHNICIAN = 'pay_technician';

    public const DECISION_RETAIN_COMPANY = 'retain_company';

    public const SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT = 'company_payment';

    public const EARNING_PAYMENT_SOURCE_COMPANY = 'company';

    public const EARNING_PAYMENT_SOURCE_CUSTOMER_DIRECT = 'customer_direct';

    private const ALLOCATION_TABLE = 'technical_service_payment_settlement_allocations';

    private const ALLOCATION_STATUS_ACTIVE = 'active';

    private const ALLOCATION_STATUS_REVERSED = 'reversed';

    private const ALLOCATION_STATUS_REVERSAL = 'reversal';

    private const PURPOSE_EXTRA_SERVICE = 'service_payment';

    private const PURPOSE_ROUTE_FEE = 'route_fee';

    private const PURPOSE_PART_CHARGE = 'part_charge';

    private const PAYER_STATE_COMPANY_PAYS_TECHNICIAN = 'company_collected_company_pays_technician';

    private ?bool $allocationSchemaAvailableCache = null;

    public function __construct(
        private readonly TechnicalServiceSettlementCalculator $calculator,
        private readonly TechnicalServicePaymentOwnershipService $paymentOwnership,
    ) {}

    /**
     * @param  array<string, mixed>|null  $ownership
     * @return array<string, mixed>
     */
    public function assignmentPaymentModel(TechnicalServiceRequest $request, ?array $ownership = null): array
    {
        $ownership ??= $this->paymentOwnership->summary($request);
        $saleMountStatus = trim((string) $request->sale_mount_status);
        $mountIncluded = in_array($saleMountStatus, [
            TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
        ], true);
        $mountIncludedSource = $mountIncluded ? 'request.sale_mount_status' : null;

        if (! $mountIncluded && in_array($saleMountStatus, [
            '',
            TechnicalServiceMountSession::SALE_UNKNOWN,
            TechnicalServiceMountSession::SALE_CHECK_FAILED,
        ], true)) {
            $resolverMountStatus = trim((string) Arr::get(
                is_array($request->qr_context_payload) ? $request->qr_context_payload : [],
                'resolver_payload.mikro_decision.montaj_durumu',
                '',
            ));
            $mountIncluded = in_array($resolverMountStatus, ['Montaj Dahil', 'Montaj Sonradan Dahil'], true);
            $mountIncludedSource = $mountIncluded ? 'qr_context_payload.resolver_payload.mikro_decision.montaj_durumu' : null;
        }

        $companyCollectedAmount = $this->money($ownership['company_collected_amount'] ?? 0);
        $settlement = $request->relationLoaded('settlement')
            ? $request->settlement
            : $request->settlement()->first();
        $settlementMetadata = is_array($settlement?->metadata) ? $settlement->metadata : [];
        $persistedPaymentSource = (string) ($settlementMetadata['earning_payment_source'] ?? '');
        $companyPaysTechnician = $mountIncluded
            || $companyCollectedAmount > 0
            || $persistedPaymentSource === self::EARNING_PAYMENT_SOURCE_COMPANY;
        $earningPaymentSource = $companyPaysTechnician
            ? self::EARNING_PAYMENT_SOURCE_COMPANY
            : self::EARNING_PAYMENT_SOURCE_CUSTOMER_DIRECT;

        return [
            'mount_included' => $mountIncluded,
            'mount_included_source' => $mountIncludedSource,
            'customer_direct_payment_locked' => $companyPaysTechnician,
            'customer_direct_payment_amount' => $companyPaysTechnician ? 0.0 : null,
            'customer_direct_payment_amount_label' => $companyPaysTechnician ? '0,00 TL' : null,
            'earning_payment_source' => $earningPaymentSource,
            'technician_payment_source_key' => $companyPaysTechnician ? 'emaks_prime' : 'customer',
            'technician_payment_source_label' => $companyPaysTechnician ? 'EMAKS Prime' : 'Müşteri',
        ];
    }

    public function persistForAssignment(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        TechnicalServiceAssignmentOffer $offer,
        ?TechnicalServiceRouteQuote $routeQuote,
        float $laborAmount,
        float $routeFeeAmount,
        ?float $customerDirectAmount,
        ?Authenticatable $user = null,
        ?string $earningPaymentSource = null,
    ): TechnicalServiceSettlement {
        $this->assertCompanyPaymentAssignmentIsStable($request, $technician, $offer, $routeFeeAmount);

        $ownership = $this->paymentOwnership->summary($request);
        $paymentModel = $this->assignmentPaymentModel($request, $ownership);
        $customerCollectionAmount = $this->money($ownership['company_collected_amount'] ?? 0);
        $mountPaymentCollected = $customerCollectionAmount > 0;
        $technicianEarningTotal = $this->money($laborAmount + $routeFeeAmount);
        $directAmount = $customerDirectAmount;
        $earningPaymentSource = in_array($earningPaymentSource, [
            self::EARNING_PAYMENT_SOURCE_COMPANY,
            self::EARNING_PAYMENT_SOURCE_CUSTOMER_DIRECT,
        ], true)
            ? $earningPaymentSource
            : (string) $paymentModel['earning_payment_source'];

        if ($paymentModel['customer_direct_payment_locked']
            && $earningPaymentSource !== self::EARNING_PAYMENT_SOURCE_COMPANY
        ) {
            throw ValidationException::withMessages([
                'earning_payment_source' => 'Bu işte usta hakedişi EMAKS Prime tarafından ödenmelidir.',
            ]);
        }

        if ($earningPaymentSource === self::EARNING_PAYMENT_SOURCE_COMPANY) {
            if (($directAmount ?? 0.0) > 0) {
                throw ValidationException::withMessages([
                    'assignment_offer.customer_direct_to_technician_amount' => $paymentModel['mount_included']
                        ? 'Montaj dahil işte müşterinin ustaya doğrudan ödeme tutarı 0 olmalıdır.'
                        : 'Müşteriden montaj ödemesi alındığı için ustaya doğrudan ödeme tutarı 0 olmalıdır.',
                ]);
            }

            $directAmount = 0.0;
        } elseif ($directAmount === null) {
            $directAmount = $technicianEarningTotal;
        }

        if ($earningPaymentSource === self::EARNING_PAYMENT_SOURCE_CUSTOMER_DIRECT
            && $technicianEarningTotal > 0
            && ($directAmount ?? 0.0) <= 0
        ) {
            throw ValidationException::withMessages([
                'assignment_offer.customer_direct_to_technician_amount' => 'Müşterinin ustaya doğrudan ödeyeceği tutar 0 TL üzerinde olmalıdır.',
            ]);
        }

        try {
            $calculation = $this->calculator->calculate(
                $technicianEarningTotal,
                $directAmount,
                $mountPaymentCollected ? $customerCollectionAmount : 0,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'assignment_offer.customer_direct_to_technician_amount' => $exception->getMessage(),
            ]);
        }
        $canonicalPayerState = $earningPaymentSource === self::EARNING_PAYMENT_SOURCE_COMPANY
            ? self::PAYER_STATE_COMPANY_PAYS_TECHNICIAN
            : ($mountPaymentCollected
            ? ($ownership['payer_state_key'] ?? TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_EXTERNAL)
            : ($calculation['customer_direct_to_technician_amount'] > 0
                ? TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN
                : TechnicalServicePaymentOwnershipService::STATE_NO_PAYMENT_REQUIRED));

        $settlement = TechnicalServiceSettlement::query()
            ->firstOrNew(['technical_service_request_id' => $request->id]);

        if (! $settlement->exists) {
            $settlement->created_by = $user?->getAuthIdentifier();
        }

        $partnerId = $this->partnerIdForTechnician($technician);
        $overpayRequiresReview = (bool) $calculation['overpay_requires_review'];
        $companyPaymentAmount = $settlement->exists
            ? $this->activeCompanyPaymentLineTotal((int) $settlement->id)
            : 0;
        $companyPayableAmount = $this->money($calculation['company_payable_amount'] + $companyPaymentAmount);
        $companyPaidAmount = $settlement->exists
            ? $this->effectiveCompanyPaidTotal((int) $settlement->id)
            : 0;
        $companyRemainingAmount = max($this->money($companyPayableAmount - $companyPaidAmount), 0);

        $settlement->fill([
            'root_request_id' => $request->parent_request_id ?: $request->id,
            'request_code' => $request->service_code,
            'root_mrn' => $request->root_mrn ?: $request->mrn,
            'technical_service_technician_id' => $technician->id,
            'b2b_partner_id' => $partnerId,
            'technical_service_assignment_offer_id' => $offer->id,
            'currency' => strtoupper(substr((string) ($offer->currency ?: 'TRY'), 0, 8)) ?: 'TRY',
            'labor_earning_amount' => $this->money($laborAmount),
            'route_earning_amount' => $this->money($routeFeeAmount),
            'technician_earning_total' => $this->money($technicianEarningTotal + $companyPaymentAmount),
            'customer_collection_amount' => $calculation['customer_collection_amount'],
            'customer_direct_to_technician_amount' => $calculation['customer_direct_to_technician_amount'],
            'customer_direct_assumed_paid_amount' => 0,
            'company_payable_amount' => $companyPayableAmount,
            'company_paid_amount' => $companyPaidAmount,
            'company_remaining_amount' => $companyRemainingAmount,
            'overpay_warning_amount' => $calculation['overpay_warning_amount'],
            'status' => $this->settlementStatus(
                $settlement,
                $companyPayableAmount,
                $companyPaidAmount,
                $overpayRequiresReview,
            ),
            'settlement_source' => 'assignment_popup',
            'overpay_requires_review' => $overpayRequiresReview,
            'review_reason' => $overpayRequiresReview
                ? 'Müşteriye bildirilen tutar usta hakedişinden yüksek.'
                : null,
            'updated_by' => $user?->getAuthIdentifier(),
            'metadata' => array_merge(is_array($settlement->metadata) ? $settlement->metadata : [], [
                'source' => 'assignment_popup',
                'mount_payment_collected' => $mountPaymentCollected,
                'mount_included' => $paymentModel['mount_included'],
                'mount_included_source' => $paymentModel['mount_included_source'],
                'earning_payment_source' => $earningPaymentSource,
                'payer_state_key' => $canonicalPayerState,
                'company_collected_source' => $ownership['company_collected_source'] ?? null,
                'route_quote_id' => $routeQuote?->id,
                'assignment_offer_id' => $offer->id,
                'base_company_payable_amount' => $this->money($calculation['company_payable_amount']),
                'company_payment_amount' => $companyPaymentAmount,
            ]),
        ]);

        $settlement->save();

        return $settlement->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function canonicalEarningSnapshot(
        TechnicalServiceAssignmentOffer $offer,
        ?TechnicalServiceSettlement $settlement = null,
    ): array {
        $laborAmount = $this->money($offer->labor_amount);
        $routeFeeAmount = $this->money($offer->route_fee_amount);
        $baseTotalAmount = $this->money($laborAmount + $routeFeeAmount);
        if (abs(((float) $offer->total_amount) - $baseTotalAmount) > 0.01) {
            throw ValidationException::withMessages([
                'assignment_offer' => 'Canonical hakediş toplamı işçilik ve yol toplamıyla eşleşmiyor.',
            ]);
        }

        $companyPayments = $this->companyPaymentBreakdownForOffer($offer);
        $companyPaymentAmount = $this->money($companyPayments->sum('amount'));
        $totalAmount = $this->money($baseTotalAmount + $companyPaymentAmount);
        if (! $settlement instanceof TechnicalServiceSettlement
            || (int) $settlement->technical_service_assignment_offer_id !== (int) $offer->id
            || (int) $settlement->technical_service_technician_id !== (int) $offer->technical_service_technician_id
        ) {
            $settlement = TechnicalServiceSettlement::query()
                ->where('technical_service_request_id', $offer->technical_service_request_id)
                ->where('technical_service_assignment_offer_id', $offer->id)
                ->where('technical_service_technician_id', $offer->technical_service_technician_id)
                ->first();
        }

        $technicianPaidAmount = $this->money(
            (float) ($settlement?->company_paid_amount ?? 0)
            + (float) ($settlement?->customer_direct_assumed_paid_amount ?? 0),
        );
        $technicianRemainingAmount = max($this->money($totalAmount - $technicianPaidAmount), 0);
        $customerCollectionAmount = $this->money($settlement?->customer_collection_amount);
        $settlementMetadata = is_array($settlement?->metadata) ? $settlement->metadata : [];
        $basePayerState = trim((string) ($settlementMetadata['payer_state_key'] ?? ''));
        $companyFunded = $companyPaymentAmount > 0
            || str_starts_with($basePayerState, 'company_collected');
        $customerPaysTechnician = $basePayerState === TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN;
        $payerState = $companyFunded
            ? self::PAYER_STATE_COMPANY_PAYS_TECHNICIAN
            : ($basePayerState !== '' ? $basePayerState : TechnicalServicePaymentOwnershipService::STATE_NO_PAYMENT_REQUIRED);
        $paymentModelLabel = $companyFunded
            ? 'Şirket ödemesi'
            : ($customerPaysTechnician ? 'Müşteri ödemesi' : 'Ödeme modeli belirlenmedi');
        $paymentSourceLabel = $companyFunded
            ? 'EMAKS Prime'
            : ($customerPaysTechnician ? 'Müşteri' : 'Belirlenmedi');
        $paymentStatusKey = $technicianRemainingAmount <= 0.0 && $totalAmount > 0.0 ? 'paid' : 'payable';
        $paymentStatusLabel = $paymentStatusKey === 'paid' ? 'Ödendi' : 'Ödenecek';
        $customerCollectionSourceLabel = $companyFunded
            ? 'EMAKS Prime tarafından alındı'
            : ($customerPaysTechnician ? 'Ustaya doğrudan ödenecek' : null);
        $operationNote = trim((string) ($offer->note ?? ''));
        $latestCompanyPaymentAt = $companyPayments->pluck('updated_at')->filter()->sort()->last();
        $persistedAt = $latestCompanyPaymentAt ?: $offer->updated_at?->toISOString();
        $revisionPayload = [
            'schema_version' => 3,
            'assignment_id' => (int) $offer->id,
            'technician_id' => (int) $offer->technical_service_technician_id,
            'labor_amount' => number_format($laborAmount, 2, '.', ''),
            'route_fee_amount' => number_format($routeFeeAmount, 2, '.', ''),
            'total_amount' => number_format($totalAmount, 2, '.', ''),
            'technician_paid_amount' => number_format($technicianPaidAmount, 2, '.', ''),
            'technician_remaining_amount' => number_format($technicianRemainingAmount, 2, '.', ''),
            'customer_collection_amount' => number_format($customerCollectionAmount, 2, '.', ''),
            'payer_state' => $payerState,
            'technician_payment_model_label' => $paymentModelLabel,
            'technician_payment_source_label' => $paymentSourceLabel,
            'technician_payment_status_key' => $paymentStatusKey,
            'technician_payment_status_label' => $paymentStatusLabel,
            'currency' => (string) ($offer->currency ?: 'TRY'),
            'operation_note' => $operationNote,
            'persisted_at' => $persistedAt,
        ];

        if ($companyPayments->isNotEmpty()) {
            $revisionPayload['base_total_amount'] = number_format($baseTotalAmount, 2, '.', '');
            $revisionPayload['company_payment_amount'] = number_format($companyPaymentAmount, 2, '.', '');
            $revisionPayload['company_payment_lines'] = $companyPayments
                ->map(fn (array $line): array => [
                    'line_id' => $line['line_id'],
                    'payment_id' => $line['payment_id'],
                    'purpose' => $line['purpose'],
                    'purpose_label' => $line['purpose_label'],
                    'source' => $line['source'],
                    'status' => $line['status'],
                    'amount' => number_format((float) $line['amount'], 2, '.', ''),
                    'revision' => $line['revision'],
                ])
                ->values()
                ->all();
        }

        $revision = hash('sha256', json_encode($revisionPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            ...$revisionPayload,
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'base_total_amount' => $baseTotalAmount,
            'company_payment_amount' => $companyPaymentAmount,
            'company_payment_breakdown' => $companyPayments->values()->all(),
            'total_amount' => $totalAmount,
            'technician_paid_amount' => $technicianPaidAmount,
            'technician_remaining_amount' => $technicianRemainingAmount,
            'customer_collection_amount' => $customerCollectionAmount,
            'payer_state' => $payerState,
            'payer_state_key' => $payerState,
            'technician_payment_model_label' => $paymentModelLabel,
            'technician_payment_source_label' => $paymentSourceLabel,
            'technician_payment_status_key' => $paymentStatusKey,
            'technician_payment_status_label' => $paymentStatusLabel,
            'customer_collection_source_label' => $customerCollectionSourceLabel,
            'operation_note' => $operationNote !== '' ? $operationNote : null,
            'revision' => $revision,
            'snapshot_hash' => $revision,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function companyPaymentDecisionPayload(TechnicalServiceRequest $request, bool $lock = false): array
    {
        if (! $this->allocationSchemaAvailable()) {
            return $this->emptyDecisionPayload();
        }

        $settlementQuery = TechnicalServiceSettlement::query()
            ->where('technical_service_request_id', $request->id);
        if ($lock) {
            $settlementQuery->lockForUpdate();
        }

        $settlement = $settlementQuery->first();
        $context = $this->allocationContext($request, $settlement, $lock);
        $payments = $this->eligiblePaymentRows($request, $lock);
        $allocationQuery = DB::table(self::ALLOCATION_TABLE)
            ->whereIn('technical_service_mount_payment_id', $payments->pluck('id')->all())
            ->orderBy('id');
        if ($lock) {
            $allocationQuery->lockForUpdate();
        }

        $allocations = $allocationQuery->get()
            ->groupBy(fn (object $row): int => (int) $row->technical_service_mount_payment_id);
        $routeRemaining = $settlement instanceof TechnicalServiceSettlement
            ? $this->minorUnits($settlement->route_earning_amount)
            : 0;
        $routeEarningAmount = $routeRemaining;
        $routeCollectionAmount = 0;
        $routeCoveredAmount = 0;
        $routePaymentRows = collect();
        $items = collect();
        $decisions = collect();

        foreach ($payments as $payment) {
            $purpose = $this->paymentPurpose($payment);
            $sourceAmount = $this->minorUnits($payment->amount);
            $covered = 0;
            if ($purpose === self::PURPOSE_ROUTE_FEE) {
                $covered = min($sourceAmount, $routeRemaining);
                $routeRemaining = max($routeRemaining - $covered, 0);
            }

            $paymentAllocations = collect($allocations->get((int) $payment->id, collect()));
            $activeAllocation = $paymentAllocations
                ->first(fn (object $row): bool => (string) $row->status === self::ALLOCATION_STATUS_ACTIVE);
            $allocated = $activeAllocation ? $this->minorUnits($activeAllocation->eligible_amount) : 0;
            $eligible = max($sourceAmount - $covered - $allocated, 0);

            if ($purpose === self::PURPOSE_ROUTE_FEE) {
                $routeCollectionAmount += $sourceAmount;
                $routeCoveredAmount += $covered;
                $routePaymentRows->push([
                    'payment_id' => (int) $payment->id,
                    'paid_amount' => $this->fromMinorUnits($sourceAmount),
                    'paid_amount_label' => $this->moneyLabel($sourceAmount),
                    'covered_amount' => $this->fromMinorUnits($covered),
                    'covered_amount_label' => $this->moneyLabel($covered),
                    'previously_allocated_amount' => $this->fromMinorUnits($allocated),
                    'previously_allocated_amount_label' => $this->moneyLabel($allocated),
                    'residual_allocatable_amount' => $this->fromMinorUnits($eligible),
                    'residual_allocatable_amount_label' => $this->moneyLabel($eligible),
                ]);
            }

            if ($activeAllocation) {
                $decisions->push($this->allocationDecisionPayload($activeAllocation));
            }

            if ($eligible <= 0 || $activeAllocation) {
                continue;
            }

            $contextReady = (bool) ($context['ready'] ?? false);
            $items->push([
                'payment_id' => (int) $payment->id,
                'payment_purpose' => $purpose,
                'payment_purpose_label' => $this->paymentPurposeLabel($purpose),
                'provider' => (string) $payment->provider,
                'provider_label' => $this->paymentProviderLabel($payment),
                'paid_at' => ($payment->provider_paid_at ?? $payment->paid_at)?->toISOString(),
                'source_paid_amount' => $this->fromMinorUnits($sourceAmount),
                'source_paid_amount_label' => $this->moneyLabel($sourceAmount),
                'covered_amount' => $this->fromMinorUnits($covered),
                'covered_amount_label' => $this->moneyLabel($covered),
                'previously_allocated_amount' => $this->fromMinorUnits($allocated),
                'previously_allocated_amount_label' => $this->moneyLabel($allocated),
                'eligible_amount' => $this->fromMinorUnits($eligible),
                'eligible_amount_label' => $this->moneyLabel($eligible),
                'currency' => strtoupper((string) $payment->currency),
                'request_id' => (int) $request->id,
                'root_request_id' => (int) ($request->parent_request_id ?: $request->id),
                'current_srv_id' => $request->parent_request_id !== null || filled($request->service_code)
                    ? (int) $request->id
                    : null,
                'mrn_or_srv' => $request->service_code ?: ($request->root_mrn ?: $request->mrn),
                'assignment_id' => $context['assignment_id'] ?? null,
                'technician_id' => $context['technician_id'] ?? null,
                'technician_name' => $context['technician_name'] ?? null,
                'can_pay_technician' => $contextReady,
                'disabled_reason' => $contextReady
                    ? null
                    : 'Bu tahsilatın bağlı olduğu servis ve usta belirlenemedi.',
                'decision' => null,
            ]);
        }

        $earningRevision = null;
        if (($context['offer'] ?? null) instanceof TechnicalServiceAssignmentOffer) {
            $earningRevision = $this->canonicalEarningSnapshot($context['offer'])['revision'];
        }

        $contextReady = (bool) ($context['ready'] ?? false);
        $pendingItems = $contextReady ? $items : collect();
        $routeResidualAmount = (int) $routePaymentRows->sum(
            fn (array $item): int => $this->minorUnits($item['residual_allocatable_amount'] ?? 0),
        );

        return [
            'schema_version' => 1,
            'eligible_items' => $items->values()->all(),
            'decisions' => $decisions->values()->all(),
            'eligible_count' => $items->count(),
            'pending_decision_count' => $pendingItems->count(),
            'pending_decision_amount' => $this->fromMinorUnits((int) $pendingItems->sum(
                fn (array $item): int => $this->minorUnits($item['eligible_amount'] ?? 0),
            )),
            'pending_decision_amount_label' => $this->moneyLabel((int) $pendingItems->sum(
                fn (array $item): int => $this->minorUnits($item['eligible_amount'] ?? 0),
            )),
            'all_decisions_required' => $contextReady && $items->isNotEmpty(),
            'context_ready' => $contextReady,
            'context_state' => $context['state'] ?? ($contextReady ? 'ready' : 'invalid'),
            'context_blocker' => $context['blocker'] ?? null,
            'earning_revision' => $earningRevision,
            'component_matching' => [
                'route' => [
                    'earning_amount' => $this->fromMinorUnits($routeEarningAmount),
                    'earning_amount_label' => $this->moneyLabel($routeEarningAmount),
                    'collection_amount' => $this->fromMinorUnits($routeCollectionAmount),
                    'collection_amount_label' => $this->moneyLabel($routeCollectionAmount),
                    'covered_amount' => $this->fromMinorUnits($routeCoveredAmount),
                    'covered_amount_label' => $this->moneyLabel($routeCoveredAmount),
                    'residual_allocatable_amount' => $this->fromMinorUnits($routeResidualAmount),
                    'residual_allocatable_amount_label' => $this->moneyLabel($routeResidualAmount),
                    'company_top_up_amount' => $this->fromMinorUnits($routeRemaining),
                    'company_top_up_amount_label' => $this->moneyLabel($routeRemaining),
                    'payments' => $routePaymentRows->values()->all(),
                ],
            ],
            'visit_count_used' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $submittedDecisions
     * @return array<string, mixed>
     */
    public function applyCompanyPaymentDecisions(
        TechnicalServiceRequest $request,
        array $submittedDecisions,
        ?Authenticatable $actor,
    ): array {
        if ($actor === null || $actor->getAuthIdentifier() === null) {
            throw ValidationException::withMessages([
                'company_payment_decisions' => 'Bu tahsilat kararını vermek için yetkili kullanıcı gerekir.',
            ]);
        }

        return DB::transaction(function () use ($request, $submittedDecisions, $actor): array {
            $lockedRequest = TechnicalServiceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $settlement = TechnicalServiceSettlement::query()
                ->where('technical_service_request_id', $lockedRequest->id)
                ->lockForUpdate()
                ->first();
            $context = $this->allocationContext($lockedRequest, $settlement, true);
            if (! ($context['ready'] ?? false)) {
                throw ValidationException::withMessages([
                    'company_payment_decisions' => 'Bu tahsilatın bağlı olduğu servis ve usta belirlenemedi.',
                ]);
            }

            $decisionPayload = $this->companyPaymentDecisionPayload($lockedRequest, true);
            $eligibleByPayment = collect($decisionPayload['eligible_items'])
                ->keyBy(fn (array $item): int => (int) $item['payment_id']);
            $activeByPayment = collect($decisionPayload['decisions'])
                ->keyBy(fn (array $item): int => (int) $item['payment_id']);
            $submitted = collect($submittedDecisions)
                ->map(function (array $decision): array {
                    $paymentId = is_numeric($decision['payment_id'] ?? null) ? (int) $decision['payment_id'] : 0;
                    $value = trim((string) ($decision['decision'] ?? ''));

                    if ($paymentId < 1 || ! in_array($value, [self::DECISION_PAY_TECHNICIAN, self::DECISION_RETAIN_COMPANY], true)) {
                        throw ValidationException::withMessages([
                            'company_payment_decisions' => 'Her uygun tahsilat için geçerli Evet/Hayır kararı verilmelidir.',
                        ]);
                    }

                    return [
                        'payment_id' => $paymentId,
                        'decision' => $value,
                        'note' => trim((string) ($decision['note'] ?? '')) ?: null,
                        'expected_earning_revision' => trim((string) ($decision['expected_earning_revision'] ?? '')),
                    ];
                });

            if ($submitted->pluck('payment_id')->unique()->count() !== $submitted->count()) {
                throw ValidationException::withMessages([
                    'company_payment_decisions' => 'Aynı tahsilat için birden fazla karar gönderilemez.',
                ]);
            }

            $missing = $eligibleByPayment->keys()->diff($submitted->pluck('payment_id'));
            $knownPaymentIds = $eligibleByPayment->keys()->merge($activeByPayment->keys())->unique();
            $unexpected = $submitted->pluck('payment_id')->diff($knownPaymentIds);
            if ($missing->isNotEmpty() || $unexpected->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'company_payment_decisions' => 'Tüm güncel uygun tahsilatlar için bağımsız karar verilmelidir.',
                ]);
            }

            $expectedRevision = (string) ($decisionPayload['earning_revision'] ?? '');
            $newDecisions = $submitted->filter(
                fn (array $decision): bool => $eligibleByPayment->has($decision['payment_id']),
            );
            if ($newDecisions->contains(fn (array $decision): bool => $decision['expected_earning_revision'] === ''
                || ! hash_equals($expectedRevision, $decision['expected_earning_revision']))) {
                throw ValidationException::withMessages([
                    'company_payment_decisions' => 'Hakediş bilgisi değişti. Güncel kaydı yenileyip tekrar deneyin.',
                ]);
            }

            $paymentIds = $submitted->pluck('payment_id')->all();
            $lockedPayments = TechnicalServiceMountPayment::query()
                ->whereIn('id', $paymentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $created = [];

            foreach ($submitted as $decision) {
                $item = $eligibleByPayment->get($decision['payment_id']);
                $payment = $lockedPayments->get($decision['payment_id']);
                $activeDecision = $activeByPayment->get($decision['payment_id']);
                if (! $payment instanceof TechnicalServiceMountPayment) {
                    throw ValidationException::withMessages([
                        'company_payment_decisions' => 'Tahsilat kararı sırasında payment authority değişti.',
                    ]);
                }

                $existing = DB::table(self::ALLOCATION_TABLE)
                    ->where('technical_service_mount_payment_id', $payment->id)
                    ->where('status', self::ALLOCATION_STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ((int) $existing->technical_service_request_id === (int) $lockedRequest->id
                        && (string) $existing->decision === $decision['decision']) {
                        continue;
                    }

                    throw ValidationException::withMessages([
                        'company_payment_decisions' => 'Bu tahsilat için daha önce karar verilmiş. Değişiklik correction/reversal akışıyla yapılmalıdır.',
                    ]);
                }

                if (is_array($activeDecision)) {
                    throw ValidationException::withMessages([
                        'company_payment_decisions' => 'Tahsilat kararının canonical kaydı değişti. Güncel kaydı yenileyin.',
                    ]);
                }

                if (! is_array($item)
                    || ! $this->isEligibleCanonicalPayment($payment, $lockedRequest)
                    || $this->paymentPurpose($payment) !== (string) $item['payment_purpose']
                    || $this->minorUnits($payment->amount) !== $this->minorUnits($item['source_paid_amount'])) {
                    throw ValidationException::withMessages([
                        'company_payment_decisions' => 'Tahsilat kararı sırasında payment authority değişti.',
                    ]);
                }

                $eligibleMinor = $this->minorUnits($item['eligible_amount']);
                if ($eligibleMinor <= 0) {
                    throw ValidationException::withMessages([
                        'company_payment_decisions' => 'Tahsilatın ustaya aktarılabilir bakiyesi kalmadı.',
                    ]);
                }

                $settlementLine = null;
                if ($decision['decision'] === self::DECISION_PAY_TECHNICIAN) {
                    $settlementLine = new TechnicalServiceEarningPayment;
                    $settlementLine->forceFill([
                        'technical_service_settlement_id' => $settlement->id,
                        'technical_service_request_id' => $lockedRequest->id,
                        'technical_service_assignment_offer_id' => $context['assignment_id'],
                        'technical_service_technician_id' => $context['technician_id'],
                        'b2b_partner_id' => $settlement->b2b_partner_id,
                        'currency' => $payment->currency ?: 'TRY',
                        'payment_type' => self::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT,
                        'amount' => $this->fromMinorUnits($eligibleMinor),
                        'status' => TechnicalServiceEarningPayment::STATUS_PENDING,
                        'paid_at' => null,
                        'paid_by' => null,
                        'paid_by_name' => null,
                        'reason' => $decision['note'],
                        'reference' => 'CUSTOMER-PAYMENT-'.$payment->id,
                        'metadata' => [
                            'source' => 'paid_customer_charge_allocation',
                            'payment_id' => (int) $payment->id,
                            'payment_purpose' => $item['payment_purpose'],
                            'payment_purpose_label' => $item['payment_purpose_label'],
                            'allocation_source' => $item['payment_purpose'] === self::PURPOSE_ROUTE_FEE
                                ? 'route_excess'
                                : 'extra_service',
                            'status' => 'payable',
                        ],
                    ])->save();
                }

                $idempotencyKey = hash('sha256', implode('|', [
                    'technical_service_company_payment_allocation_v1',
                    $payment->id,
                    $context['assignment_id'],
                    $context['technician_id'],
                    $decision['decision'],
                    $eligibleMinor,
                ]));
                $allocationId = DB::table(self::ALLOCATION_TABLE)->insertGetId([
                    'technical_service_mount_payment_id' => $payment->id,
                    'technical_service_settlement_id' => $settlement->id,
                    'technical_service_request_id' => $lockedRequest->id,
                    'root_request_id' => $lockedRequest->parent_request_id ?: $lockedRequest->id,
                    'current_srv_id' => $lockedRequest->parent_request_id !== null || filled($lockedRequest->service_code)
                        ? $lockedRequest->id
                        : null,
                    'technical_service_assignment_offer_id' => $context['assignment_id'],
                    'technical_service_technician_id' => $context['technician_id'],
                    'payment_purpose' => $item['payment_purpose'],
                    'currency' => $payment->currency ?: 'TRY',
                    'source_paid_amount' => $this->fromMinorUnits($this->minorUnits($item['source_paid_amount'])),
                    'covered_amount' => $this->fromMinorUnits($this->minorUnits($item['covered_amount'])),
                    'eligible_amount' => $this->fromMinorUnits($eligibleMinor),
                    'decision' => $decision['decision'],
                    'decision_note' => $decision['note'],
                    'decided_by' => $actor->getAuthIdentifier(),
                    'decided_by_name' => $this->actorName($actor),
                    'decided_at' => now(),
                    'settlement_line_id' => $settlementLine?->id,
                    'reversal_of_id' => null,
                    'status' => self::ALLOCATION_STATUS_ACTIVE,
                    'idempotency_key' => $idempotencyKey,
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($settlementLine instanceof TechnicalServiceEarningPayment) {
                    $metadata = is_array($settlementLine->metadata) ? $settlementLine->metadata : [];
                    $metadata['allocation_id'] = $allocationId;
                    $settlementLine->forceFill(['metadata' => $metadata])->save();
                }

                $lockedRequest->events()->create([
                    'event_type' => 'company_payment_allocation_decided',
                    'title' => $decision['decision'] === self::DECISION_PAY_TECHNICIAN
                        ? 'Müşteri tahsilatı usta hakedişine eklendi'
                        : 'Müşteri tahsilatı şirkette bırakıldı',
                    'note' => $decision['note'],
                    'from_status' => $lockedRequest->workflow_status,
                    'to_status' => $lockedRequest->workflow_status,
                    'author_user_id' => $actor->getAuthIdentifier(),
                    'metadata' => [
                        'allocation_id' => $allocationId,
                        'payment_id' => (int) $payment->id,
                        'payment_purpose' => $item['payment_purpose'],
                        'old' => null,
                        'new' => $decision['decision'],
                        'decision' => $decision['decision'],
                        'eligible_amount' => $this->fromMinorUnits($eligibleMinor),
                        'assignment_id' => $context['assignment_id'],
                        'technician_id' => $context['technician_id'],
                        'correlation_id' => $idempotencyKey,
                        'actor_user_id' => $actor->getAuthIdentifier(),
                        'actor_role' => trim((string) data_get($actor, 'role_code')) ?: 'authenticated_user',
                        'source' => 'technical_service_admin',
                        'occurred_at_istanbul' => now('Europe/Istanbul')->toIso8601String(),
                        'request_id' => $lockedRequest->id,
                        'mrn' => $lockedRequest->mrn,
                        'srv' => $lockedRequest->service_code,
                    ],
                ]);
                $created[] = $allocationId;
            }

            $this->refreshSettlementForRequest($lockedRequest);

            return [
                'status' => $created === [] ? 'duplicate_noop' : 'decided',
                'created_allocation_ids' => $created,
                'decision_payload' => $this->companyPaymentDecisionPayload($lockedRequest),
            ];
        });
    }

    public function applyPreparedPartSupplierAllocation(
        TechnicalServiceRequest $request,
        TechnicalServiceMountPayment $payment,
        Authenticatable $actor,
    ): array {
        if (! $this->allocationSchemaAvailable()) {
            throw ValidationException::withMessages([
                'order_context' => 'Usta parça hakedişi için canonical allocation tablosu hazır değil.',
            ]);
        }

        return DB::transaction(function () use ($request, $payment, $actor): array {
            $lockedRequest = TechnicalServiceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $lockedPayment = TechnicalServiceMountPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $payload = is_array($lockedPayment->raw_payload) ? $lockedPayment->raw_payload : [];
            $orderContext = is_array($payload['order_context'] ?? null) ? $payload['order_context'] : [];
            if ($lockedPayment->status !== TechnicalServiceMountPayment::STATUS_PAID
                || $this->paymentPurpose($lockedPayment) !== self::PURPOSE_PART_CHARGE
                || ($orderContext['part_supplier'] ?? null) !== 'technician'
                || ($orderContext['collection_allocation'] ?? null) !== self::DECISION_PAY_TECHNICIAN
                || (int) ($orderContext['request_id'] ?? 0) !== (int) $lockedRequest->id) {
                throw ValidationException::withMessages([
                    'order_context' => 'Usta parça tahsilatı canonical sipariş hazırlığıyla eşleşmiyor.',
                ]);
            }

            $existing = DB::table(self::ALLOCATION_TABLE)
                ->where('technical_service_mount_payment_id', $lockedPayment->id)
                ->where('status', self::ALLOCATION_STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ((int) $existing->technical_service_request_id === (int) $lockedRequest->id
                    && (string) $existing->decision === self::DECISION_PAY_TECHNICIAN
                    && (string) $existing->payment_purpose === self::PURPOSE_PART_CHARGE) {
                    return ['status' => 'duplicate_noop', 'allocation_id' => (int) $existing->id];
                }

                throw ValidationException::withMessages([
                    'order_context' => 'Bu parça tahsilatı için farklı bir canonical allocation zaten var.',
                ]);
            }

            $settlement = TechnicalServiceSettlement::query()
                ->where('technical_service_request_id', $lockedRequest->id)
                ->lockForUpdate()
                ->first();
            $context = $this->allocationContext($lockedRequest, $settlement, true);
            if (! $settlement instanceof TechnicalServiceSettlement || ! ($context['ready'] ?? false)) {
                throw ValidationException::withMessages([
                    'order_context' => 'Ustanın sağladığı parça tahsilatı için aktif atama ve hakediş kaydı gereklidir.',
                ]);
            }

            $eligibleMinor = $this->minorUnits($lockedPayment->amount);
            if ($eligibleMinor <= 0 || strtoupper((string) $lockedPayment->currency) !== 'TRY') {
                throw ValidationException::withMessages([
                    'order_context' => 'Usta parça tahsilat tutarı canonical TRY tutarı olmalıdır.',
                ]);
            }

            $line = new TechnicalServiceEarningPayment;
            $line->forceFill([
                'technical_service_settlement_id' => $settlement->id,
                'technical_service_request_id' => $lockedRequest->id,
                'technical_service_assignment_offer_id' => $context['assignment_id'],
                'technical_service_technician_id' => $context['technician_id'],
                'b2b_partner_id' => $settlement->b2b_partner_id,
                'currency' => $lockedPayment->currency ?: 'TRY',
                'payment_type' => self::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT,
                'amount' => $this->fromMinorUnits($eligibleMinor),
                'status' => TechnicalServiceEarningPayment::STATUS_PENDING,
                'paid_at' => null,
                'paid_by' => null,
                'paid_by_name' => null,
                'reason' => 'Ustanın sağladığı parça tahsilatı',
                'reference' => 'CUSTOMER-PAYMENT-'.$lockedPayment->id,
                'metadata' => [
                    'source' => 'paid_technician_supplied_part_allocation',
                    'payment_id' => (int) $lockedPayment->id,
                    'payment_purpose' => self::PURPOSE_PART_CHARGE,
                    'payment_purpose_label' => 'Ustanın sağladığı parça',
                    'allocation_source' => 'technician_supplied_part',
                    'order_context_id' => $orderContext['id'] ?? null,
                    'context_hash' => $orderContext['context_hash'] ?? null,
                    'status' => 'payable',
                ],
            ])->save();

            $idempotencyKey = hash('sha256', implode('|', [
                'technical-service-technician-part-allocation-v1',
                $lockedPayment->id,
                $orderContext['context_hash'] ?? '',
                $context['assignment_id'],
                $context['technician_id'],
                $eligibleMinor,
            ]));
            $allocationId = DB::table(self::ALLOCATION_TABLE)->insertGetId([
                'technical_service_mount_payment_id' => $lockedPayment->id,
                'technical_service_settlement_id' => $settlement->id,
                'technical_service_request_id' => $lockedRequest->id,
                'root_request_id' => $lockedRequest->parent_request_id ?: $lockedRequest->id,
                'current_srv_id' => $lockedRequest->parent_request_id !== null || filled($lockedRequest->service_code)
                    ? $lockedRequest->id
                    : null,
                'technical_service_assignment_offer_id' => $context['assignment_id'],
                'technical_service_technician_id' => $context['technician_id'],
                'payment_purpose' => self::PURPOSE_PART_CHARGE,
                'currency' => $lockedPayment->currency ?: 'TRY',
                'source_paid_amount' => $this->fromMinorUnits($eligibleMinor),
                'covered_amount' => 0,
                'eligible_amount' => $this->fromMinorUnits($eligibleMinor),
                'decision' => self::DECISION_PAY_TECHNICIAN,
                'decision_note' => 'Parçayı aktif usta sağladı.',
                'decided_by' => $actor->getAuthIdentifier(),
                'decided_by_name' => $this->actorName($actor),
                'decided_at' => now(),
                'settlement_line_id' => $line->id,
                'reversal_of_id' => null,
                'status' => self::ALLOCATION_STATUS_ACTIVE,
                'idempotency_key' => $idempotencyKey,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $lineMetadata = is_array($line->metadata) ? $line->metadata : [];
            $lineMetadata['allocation_id'] = $allocationId;
            $line->forceFill(['metadata' => $lineMetadata])->save();

            $lockedRequest->events()->create([
                'event_type' => 'company_payment_allocation_decided',
                'title' => 'Ustanın sağladığı parça tahsilatı hakedişe eklendi',
                'note' => 'Parça ödemesi mevcut aktif ustaya bir kez bağlandı; Mikro siparişi ve sevkiyat gerekmiyor.',
                'from_status' => $lockedRequest->workflow_status,
                'to_status' => $lockedRequest->workflow_status,
                'author_user_id' => $actor->getAuthIdentifier(),
                'metadata' => [
                    'allocation_id' => $allocationId,
                    'payment_id' => (int) $lockedPayment->id,
                    'payment_purpose' => self::PURPOSE_PART_CHARGE,
                    'decision' => self::DECISION_PAY_TECHNICIAN,
                    'eligible_amount' => $this->fromMinorUnits($eligibleMinor),
                    'assignment_id' => $context['assignment_id'],
                    'technician_id' => $context['technician_id'],
                    'order_context_id' => $orderContext['id'] ?? null,
                    'context_hash' => $orderContext['context_hash'] ?? null,
                    'correlation_id' => $idempotencyKey,
                    'source' => 'payment_order_context',
                ],
            ]);
            $this->refreshSettlementForRequest($lockedRequest);

            return ['status' => 'decided', 'allocation_id' => $allocationId];
        });
    }

    public function refreshSettlementForRequest(TechnicalServiceRequest $request): ?TechnicalServiceSettlement
    {
        $settlement = TechnicalServiceSettlement::query()
            ->where('technical_service_request_id', $request->id)
            ->lockForUpdate()
            ->first();
        if (! $settlement instanceof TechnicalServiceSettlement) {
            return null;
        }

        $baseTotal = $this->money($settlement->labor_earning_amount + $settlement->route_earning_amount);
        $companyPaymentAmount = $this->activeCompanyPaymentLineTotal((int) $settlement->id);
        $metadata = is_array($settlement->metadata) ? $settlement->metadata : [];
        $previousCompanyPaymentAmount = $this->money($metadata['company_payment_amount'] ?? 0);
        $basePayable = array_key_exists('base_company_payable_amount', $metadata)
            ? $this->money($metadata['base_company_payable_amount'])
            : max($this->money($settlement->company_payable_amount) - $previousCompanyPaymentAmount, 0);
        $companyPayable = $this->money($basePayable + $companyPaymentAmount);
        $companyPaid = $this->effectiveCompanyPaidTotal((int) $settlement->id);
        $remaining = max($this->money($companyPayable - $companyPaid), 0);
        $metadata['base_company_payable_amount'] = $basePayable;
        $metadata['company_payment_amount'] = $companyPaymentAmount;
        $metadata['company_payment_refreshed_at'] = now()->toISOString();

        $settlement->forceFill([
            'technician_earning_total' => $this->money($baseTotal + $companyPaymentAmount),
            'company_payable_amount' => $companyPayable,
            'company_paid_amount' => $companyPaid,
            'company_remaining_amount' => $remaining,
            'status' => $this->settlementStatus(
                $settlement,
                $companyPayable,
                $companyPaid,
                (bool) $settlement->overpay_requires_review,
            ),
            'paid_at' => $companyPayable > 0 && $remaining <= 0
                ? ($this->latestAppliedPayoutAt((int) $settlement->id) ?? $settlement->paid_at)
                : null,
            'metadata' => $metadata,
        ])->save();

        return $settlement->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function reverseCompanyPaymentAllocation(
        TechnicalServiceMountPayment $payment,
        ?Authenticatable $actor = null,
        ?string $note = null,
    ): array {
        return DB::transaction(function () use ($payment, $actor, $note): array {
            $lockedPayment = TechnicalServiceMountPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $allocation = DB::table(self::ALLOCATION_TABLE)
                ->where('technical_service_mount_payment_id', $lockedPayment->id)
                ->where('status', self::ALLOCATION_STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            if (! $allocation) {
                return ['status' => 'not_allocated', 'adjustment_id' => null];
            }

            $settlement = TechnicalServiceSettlement::query()->whereKey($allocation->technical_service_settlement_id)->lockForUpdate()->firstOrFail();
            $line = is_numeric($allocation->settlement_line_id)
                ? TechnicalServiceEarningPayment::query()->whereKey((int) $allocation->settlement_line_id)->lockForUpdate()->first()
                : null;
            $linkedPaidMinor = $line instanceof TechnicalServiceEarningPayment
                ? $this->minorUnits(TechnicalServiceEarningPayment::query()
                    ->where('source_company_payment_line_id', $line->id)
                    ->where('payment_type', TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT)
                    ->where('status', TechnicalServiceEarningPayment::STATUS_APPLIED)
                    ->sum('amount'))
                : 0;
            $adjustment = null;

            if ($line instanceof TechnicalServiceEarningPayment) {
                $line->forceFill([
                    'status' => TechnicalServiceEarningPayment::STATUS_VOID,
                    'metadata' => array_merge(is_array($line->metadata) ? $line->metadata : [], [
                        'reversed_at' => now()->toISOString(),
                        'reversed_by' => $actor?->getAuthIdentifier(),
                        'reversal_note' => $note,
                    ]),
                ])->save();
            }

            if ($linkedPaidMinor > 0 && $line instanceof TechnicalServiceEarningPayment) {
                $adjustment = new TechnicalServiceEarningPayment;
                $adjustment->forceFill([
                    'technical_service_settlement_id' => $settlement->id,
                    'technical_service_request_id' => $settlement->technical_service_request_id,
                    'technical_service_assignment_offer_id' => $allocation->technical_service_assignment_offer_id,
                    'technical_service_technician_id' => $allocation->technical_service_technician_id,
                    'b2b_partner_id' => $settlement->b2b_partner_id,
                    'currency' => $allocation->currency,
                    'payment_type' => TechnicalServiceEarningPayment::TYPE_ADJUSTMENT,
                    'source_company_payment_line_id' => $line->id,
                    'amount' => $this->fromMinorUnits(-$linkedPaidMinor),
                    'status' => TechnicalServiceEarningPayment::STATUS_APPLIED,
                    'paid_at' => now(),
                    'paid_by' => $actor?->getAuthIdentifier(),
                    'paid_by_name' => $this->actorName($actor),
                    'reason' => $note ?: 'Müşteri tahsilatı iade/ters kayıt nedeniyle mahsup edildi.',
                    'reference' => 'CUSTOMER-PAYMENT-REVERSAL-'.$lockedPayment->id,
                    'metadata' => [
                        'source' => 'company_payment_refund_after_payout',
                        'payment_id' => (int) $lockedPayment->id,
                        'allocation_id' => (int) $allocation->id,
                        'finance_review_required' => true,
                    ],
                ])->save();
            }

            DB::table(self::ALLOCATION_TABLE)->where('id', $allocation->id)->update([
                'status' => self::ALLOCATION_STATUS_REVERSED,
                'revision' => (int) $allocation->revision + 1,
                'updated_at' => now(),
            ]);
            DB::table(self::ALLOCATION_TABLE)->insert([
                'technical_service_mount_payment_id' => $allocation->technical_service_mount_payment_id,
                'technical_service_settlement_id' => $allocation->technical_service_settlement_id,
                'technical_service_request_id' => $allocation->technical_service_request_id,
                'root_request_id' => $allocation->root_request_id,
                'current_srv_id' => $allocation->current_srv_id,
                'technical_service_assignment_offer_id' => $allocation->technical_service_assignment_offer_id,
                'technical_service_technician_id' => $allocation->technical_service_technician_id,
                'payment_purpose' => $allocation->payment_purpose,
                'currency' => $allocation->currency,
                'source_paid_amount' => -$this->money($allocation->source_paid_amount),
                'covered_amount' => -$this->money($allocation->covered_amount),
                'eligible_amount' => -$this->money($allocation->eligible_amount),
                'decision' => $allocation->decision,
                'decision_note' => $note,
                'decided_by' => $actor?->getAuthIdentifier(),
                'decided_by_name' => $this->actorName($actor),
                'decided_at' => now(),
                'settlement_line_id' => $adjustment?->id,
                'reversal_of_id' => $allocation->id,
                'status' => self::ALLOCATION_STATUS_REVERSAL,
                'idempotency_key' => hash('sha256', 'technical_service_company_payment_reversal_v1|'.$allocation->id),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $request = TechnicalServiceRequest::query()->findOrFail($allocation->technical_service_request_id);
            $request->events()->create([
                'event_type' => 'company_payment_allocation_reversed',
                'title' => $linkedPaidMinor > 0
                    ? 'Ödenmiş şirket ödemesi için mahsup oluşturuldu'
                    : 'Ödenmemiş şirket ödemesi iptal edildi',
                'note' => $note,
                'from_status' => $request->workflow_status,
                'to_status' => $request->workflow_status,
                'author_user_id' => $actor?->getAuthIdentifier(),
                'metadata' => [
                    'payment_id' => (int) $lockedPayment->id,
                    'allocation_id' => (int) $allocation->id,
                    'settlement_line_id' => $line?->id,
                    'adjustment_id' => $adjustment?->id,
                    'finance_review_required' => $linkedPaidMinor > 0,
                    'actor_user_id' => $actor?->getAuthIdentifier(),
                    'actor_role' => trim((string) data_get($actor, 'role_code')) ?: ($actor === null ? 'system_worker' : 'authenticated_user'),
                    'source' => 'technical_service_admin',
                    'occurred_at_istanbul' => now('Europe/Istanbul')->toIso8601String(),
                    'request_id' => $request->id,
                    'mrn' => $request->mrn,
                    'srv' => $request->service_code,
                ],
            ]);
            $this->refreshSettlementForRequest($request);

            return [
                'status' => $linkedPaidMinor > 0 ? 'negative_adjustment_created' : 'payable_reversed',
                'adjustment_id' => $adjustment?->id,
            ];
        });
    }

    private function assertCompanyPaymentAssignmentIsStable(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        TechnicalServiceAssignmentOffer $offer,
        float $routeFeeAmount,
    ): void {
        $existingSettlement = TechnicalServiceSettlement::query()
            ->where('technical_service_request_id', $request->id)
            ->lockForUpdate()
            ->first();
        if ($existingSettlement instanceof TechnicalServiceSettlement
            && is_numeric($existingSettlement->technical_service_technician_id)
            && (int) $existingSettlement->technical_service_technician_id !== (int) $technician->id) {
            $hasProtectedPayout = $this->minorUnits($existingSettlement->company_paid_amount) > 0
                || $existingSettlement->earningPayments()
                    ->whereIn('status', [
                        TechnicalServiceEarningPayment::STATUS_PENDING,
                        TechnicalServiceEarningPayment::STATUS_APPLIED,
                    ])
                    ->exists()
                || in_array($existingSettlement->status, [
                    TechnicalServiceSettlement::STATUS_FINALIZED,
                    TechnicalServiceSettlement::STATUS_SENT,
                    TechnicalServiceSettlement::STATUS_PARTIAL_PAID,
                    TechnicalServiceSettlement::STATUS_PAID,
                ], true)
                || $existingSettlement->finalized_at !== null
                || $existingSettlement->completed_at !== null
                || $existingSettlement->paid_at !== null;

            if ($hasProtectedPayout) {
                throw ValidationException::withMessages([
                    'technical_service_technician_id' => 'Önceki ustaya ait kesinleşmiş veya ödenmiş hakediş sessizce yeni ustaya aktarılamaz. Önce explicit correction/reversal uygulanmalıdır.',
                ]);
            }
        }

        if (! $this->allocationSchemaAvailable()) {
            return;
        }

        $active = DB::table(self::ALLOCATION_TABLE)
            ->where('technical_service_request_id', $request->id)
            ->where('decision', self::DECISION_PAY_TECHNICIAN)
            ->where('status', self::ALLOCATION_STATUS_ACTIVE)
            ->where(function ($query) use ($technician, $offer): void {
                $query->where('technical_service_technician_id', '!=', $technician->id)
                    ->orWhere('technical_service_assignment_offer_id', '!=', $offer->id);
            })
            ->exists();

        if ($active) {
            throw ValidationException::withMessages([
                'assignment_offer' => 'Mevcut şirket ödemesi satırı eski usta ve atamaya bağlıdır. Önce explicit correction/reversal uygulanmalıdır.',
            ]);
        }

        $activeRouteDecision = DB::table(self::ALLOCATION_TABLE)
            ->where('technical_service_request_id', $request->id)
            ->where('payment_purpose', self::PURPOSE_ROUTE_FEE)
            ->where('status', self::ALLOCATION_STATUS_ACTIVE)
            ->exists();
        $settlement = $activeRouteDecision
            ? TechnicalServiceSettlement::query()->where('technical_service_request_id', $request->id)->first()
            : null;
        if ($settlement instanceof TechnicalServiceSettlement
            && $this->minorUnits($settlement->route_earning_amount) !== $this->minorUnits($routeFeeAmount)) {
            throw ValidationException::withMessages([
                'route_fee_amount' => 'Yol tahsilatı dağıtım kararı varken yol hakedişi değiştirilemez. Önce explicit correction/reversal uygulanmalıdır.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function allocationContext(
        TechnicalServiceRequest $request,
        ?TechnicalServiceSettlement $settlement,
        bool $lock = false,
    ): array {
        if (! $settlement instanceof TechnicalServiceSettlement
            || ! is_numeric($settlement->technical_service_assignment_offer_id)
            || ! is_numeric($settlement->technical_service_technician_id)) {
            return [
                'ready' => false,
                'state' => 'awaiting_assignment',
                'blocker' => 'Atama tamamlandıktan sonra tahsilat dağılımı hesaplanacaktır.',
            ];
        }

        $offerQuery = TechnicalServiceAssignmentOffer::query()
            ->whereKey((int) $settlement->technical_service_assignment_offer_id);
        if ($lock) {
            $offerQuery->lockForUpdate();
        }
        $offer = $offerQuery->first();
        if (! $offer instanceof TechnicalServiceAssignmentOffer
            || (int) $offer->technical_service_request_id !== (int) $request->id
            || (int) $offer->technical_service_technician_id !== (int) $settlement->technical_service_technician_id
            || ($request->technical_service_technician_id !== null
                && (int) $request->technical_service_technician_id !== (int) $settlement->technical_service_technician_id)) {
            return ['ready' => false, 'state' => 'invalid', 'blocker' => 'Bu tahsilatın bağlı olduğu servis ve usta belirlenemedi.'];
        }

        $technician = TechnicalServiceTechnician::query()->find($settlement->technical_service_technician_id);
        if (! $technician instanceof TechnicalServiceTechnician) {
            return ['ready' => false, 'state' => 'invalid', 'blocker' => 'Bu tahsilatın bağlı olduğu servis ve usta belirlenemedi.'];
        }

        return [
            'ready' => true,
            'state' => 'ready',
            'assignment_id' => (int) $offer->id,
            'technician_id' => (int) $technician->id,
            'technician_name' => TechnicalServiceUiLabelService::displayName($technician->name),
            'offer' => $offer,
            'blocker' => null,
        ];
    }

    /**
     * @return Collection<int, TechnicalServiceMountPayment>
     */
    private function eligiblePaymentRows(TechnicalServiceRequest $request, bool $lock = false): Collection
    {
        $query = TechnicalServiceMountPayment::query()
            ->where('technical_service_request_id', $request->id)
            ->orderByRaw('COALESCE(provider_paid_at, paid_at, created_at) asc')
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()
            ->filter(fn (TechnicalServiceMountPayment $payment): bool => $this->isEligibleCanonicalPayment($payment, $request))
            ->values();
    }

    private function isEligibleCanonicalPayment(
        TechnicalServiceMountPayment $payment,
        TechnicalServiceRequest $request,
    ): bool {
        if ((int) ($payment->technical_service_request_id ?? 0) !== (int) $request->id
            || $payment->status !== TechnicalServiceMountPayment::STATUS_PAID
            || $this->minorUnits($payment->amount) <= 0
            || strtoupper((string) $payment->currency) !== 'TRY') {
            return false;
        }

        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $purpose = $this->paymentPurpose($payment);
        $orderContext = is_array($payload['order_context'] ?? null) ? $payload['order_context'] : [];
        $technicianPartPayment = $purpose === self::PURPOSE_PART_CHARGE
            && ($orderContext['part_supplier'] ?? null) === 'technician'
            && ($orderContext['collection_allocation'] ?? null) === self::DECISION_PAY_TECHNICIAN;
        if ((! in_array($purpose, [self::PURPOSE_EXTRA_SERVICE, self::PURPOSE_ROUTE_FEE], true) && ! $technicianPartPayment)
            || (is_numeric($payload['part_request_id'] ?? null) && ! $technicianPartPayment)
            || is_array($payload['canonical_payment_duplicate'] ?? null)
            || (bool) ($payload['metadata_only'] ?? false)
            || str_contains(strtolower((string) ($payload['source'] ?? '')), 'metadata')
            || in_array(strtolower((string) ($payload['payer_state_key'] ?? '')), ['customer_pays_technician'], true)) {
            return false;
        }

        $refundStates = collect([
            Arr::get($payload, 'refund_status'),
            Arr::get($payload, 'refundStatus'),
            Arr::get($payload, 'provider_reconciliation.refund_status'),
            Arr::get($payload, 'provider_reconciliation.provider_response_redacted.payments.0.refundStatus'),
            Arr::get($payload, 'callback_payload.provider_reconciliation.provider_response_redacted.payments.0.refundStatus'),
        ])->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => strtoupper(trim((string) $value)));

        return ! $refundStates->contains(fn (string $state): bool => ! in_array($state, [
            'NOT_REFUNDED',
            'NONE',
            'NO',
            'FALSE',
            '0',
        ], true));
    }

    private function paymentPurpose(TechnicalServiceMountPayment $payment): string
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        return strtolower(trim((string) ($payload['purpose'] ?? $payload['charge_type'] ?? '')));
    }

    private function paymentPurposeLabel(string $purpose): string
    {
        return match ($purpose) {
            self::PURPOSE_EXTRA_SERVICE => 'Ek servis',
            self::PURPOSE_ROUTE_FEE => 'Yol ücreti',
            self::PURPOSE_PART_CHARGE => 'Ustanın sağladığı parça',
            default => 'Belirsiz tahsilat',
        };
    }

    private function paymentProviderLabel(TechnicalServiceMountPayment $payment): string
    {
        $provider = strtolower(trim((string) $payment->provider));

        return in_array($provider, ['manual', 'external', 'cash', 'bank_transfer', 'eft', 'havale'], true)
            ? 'Harici / manuel tahsilat'
            : strtoupper($provider ?: 'provider');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function companyPaymentBreakdownForOffer(TechnicalServiceAssignmentOffer $offer): Collection
    {
        if (! $this->allocationSchemaAvailable()) {
            return collect();
        }

        return TechnicalServiceEarningPayment::query()
            ->where('technical_service_assignment_offer_id', $offer->id)
            ->where('technical_service_technician_id', $offer->technical_service_technician_id)
            ->where('payment_type', self::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT)
            ->whereIn('status', [TechnicalServiceEarningPayment::STATUS_PENDING, TechnicalServiceEarningPayment::STATUS_APPLIED])
            ->orderBy('id')
            ->get()
            ->map(function (TechnicalServiceEarningPayment $line): array {
                $metadata = is_array($line->metadata) ? $line->metadata : [];
                $purpose = (string) ($metadata['payment_purpose'] ?? '');

                return [
                    'line_id' => (int) $line->id,
                    'payment_id' => is_numeric($metadata['payment_id'] ?? null) ? (int) $metadata['payment_id'] : null,
                    'purpose' => $purpose,
                    'purpose_label' => $metadata['payment_purpose_label'] ?? $this->paymentPurposeLabel($purpose),
                    'source' => $metadata['allocation_source'] ?? null,
                    'amount' => $this->money($line->amount),
                    'amount_label' => $this->moneyLabel($this->minorUnits($line->amount)),
                    'status' => $line->status === TechnicalServiceEarningPayment::STATUS_APPLIED ? 'paid' : 'payable',
                    'revision' => hash('sha256', implode('|', [
                        $line->id,
                        $line->status,
                        number_format((float) $line->amount, 2, '.', ''),
                        $line->updated_at?->toISOString(),
                    ])),
                    'updated_at' => $line->updated_at?->toISOString(),
                ];
            })
            ->values();
    }

    /** @return array<string, mixed> */
    private function allocationDecisionPayload(object $allocation): array
    {
        return [
            'allocation_id' => (int) $allocation->id,
            'payment_id' => (int) $allocation->technical_service_mount_payment_id,
            'payment_purpose' => (string) $allocation->payment_purpose,
            'payment_purpose_label' => $this->paymentPurposeLabel((string) $allocation->payment_purpose),
            'decision' => (string) $allocation->decision,
            'decision_label' => (string) $allocation->decision === self::DECISION_PAY_TECHNICIAN
                ? 'Evet, şirket ödemesi olarak ustaya eklendi'
                : 'Hayır, şirkette bırakıldı',
            'eligible_amount' => $this->money($allocation->eligible_amount),
            'eligible_amount_label' => $this->moneyLabel($this->minorUnits($allocation->eligible_amount)),
            'decided_by' => $allocation->decided_by,
            'decided_by_name' => $allocation->decided_by_name,
            'decided_at' => optional($allocation->decided_at ? CarbonImmutable::parse($allocation->decided_at) : null)?->toISOString(),
            'settlement_line_id' => $allocation->settlement_line_id,
            'status' => $allocation->status,
        ];
    }

    private function activeCompanyPaymentLineTotal(int $settlementId): float
    {
        if (! $this->allocationSchemaAvailable()) {
            return 0;
        }

        return $this->money(TechnicalServiceEarningPayment::query()
            ->where('technical_service_settlement_id', $settlementId)
            ->where('payment_type', self::SETTLEMENT_LINE_TYPE_COMPANY_PAYMENT)
            ->whereIn('status', [TechnicalServiceEarningPayment::STATUS_PENDING, TechnicalServiceEarningPayment::STATUS_APPLIED])
            ->sum('amount'));
    }

    private function effectiveCompanyPaidTotal(int $settlementId): float
    {
        $payments = TechnicalServiceEarningPayment::query()
            ->where('technical_service_settlement_id', $settlementId)
            ->where('status', TechnicalServiceEarningPayment::STATUS_APPLIED)
            ->whereIn('payment_type', [
                TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT,
                TechnicalServiceEarningPayment::TYPE_ADJUSTMENT,
                TechnicalServiceEarningPayment::TYPE_REVERSAL,
            ])
            ->sum('amount');

        return max($this->money($payments), 0);
    }

    private function latestAppliedPayoutAt(int $settlementId): mixed
    {
        return TechnicalServiceEarningPayment::query()
            ->where('technical_service_settlement_id', $settlementId)
            ->where('payment_type', TechnicalServiceEarningPayment::TYPE_COMPANY_PAYOUT)
            ->where('status', TechnicalServiceEarningPayment::STATUS_APPLIED)
            ->max('paid_at');
    }

    private function settlementStatus(
        TechnicalServiceSettlement $settlement,
        float $companyPayable,
        float $companyPaid,
        bool $requiresReview,
    ): string {
        if ($settlement->status === TechnicalServiceSettlement::STATUS_EXCLUDED) {
            return TechnicalServiceSettlement::STATUS_EXCLUDED;
        }
        if ($requiresReview) {
            return TechnicalServiceSettlement::STATUS_ADMIN_REVIEW;
        }
        if ($companyPayable > 0 && $companyPaid >= $companyPayable) {
            return TechnicalServiceSettlement::STATUS_PAID;
        }
        if ($companyPaid > 0) {
            return TechnicalServiceSettlement::STATUS_PARTIAL_PAID;
        }
        if (in_array($settlement->status, [TechnicalServiceSettlement::STATUS_FINALIZED, TechnicalServiceSettlement::STATUS_SENT], true)) {
            return $settlement->status;
        }

        return TechnicalServiceSettlement::STATUS_CALCULATED;
    }

    private function allocationSchemaAvailable(): bool
    {
        return $this->allocationSchemaAvailableCache ??= Schema::hasTable(self::ALLOCATION_TABLE)
            && Schema::hasColumn('technical_service_earning_payments', 'technical_service_assignment_offer_id')
            && Schema::hasColumn('technical_service_earning_payments', 'source_company_payment_line_id');
    }

    /** @return array<string, mixed> */
    private function emptyDecisionPayload(): array
    {
        return [
            'schema_version' => 1,
            'eligible_items' => [],
            'decisions' => [],
            'eligible_count' => 0,
            'pending_decision_count' => 0,
            'pending_decision_amount' => 0.0,
            'pending_decision_amount_label' => '0,00 TL',
            'all_decisions_required' => false,
            'context_ready' => false,
            'context_state' => 'invalid',
            'context_blocker' => null,
            'earning_revision' => null,
            'component_matching' => [
                'route' => [
                    'earning_amount' => 0.0,
                    'earning_amount_label' => '0,00 TL',
                    'collection_amount' => 0.0,
                    'collection_amount_label' => '0,00 TL',
                    'covered_amount' => 0.0,
                    'covered_amount_label' => '0,00 TL',
                    'residual_allocatable_amount' => 0.0,
                    'residual_allocatable_amount_label' => '0,00 TL',
                    'company_top_up_amount' => 0.0,
                    'company_top_up_amount_label' => '0,00 TL',
                    'payments' => [],
                ],
            ],
            'visit_count_used' => false,
        ];
    }

    private function partnerIdForTechnician(TechnicalServiceTechnician $technician): ?int
    {
        $partnerId = B2BPartnerTechnician::query()
            ->where('technical_service_technician_id', $technician->id)
            ->where('active', true)
            ->whereIn('relationship_type', ['owner', 'field_technician'])
            ->value('partner_id');

        return $partnerId !== null ? (int) $partnerId : null;
    }

    private function actorName(?Authenticatable $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        return trim((string) ($actor->full_name ?? $actor->name ?? $actor->getAuthIdentifier())) ?: null;
    }

    private function minorUnits(mixed $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }

    private function fromMinorUnits(int $value): float
    {
        return round($value / 100, 2);
    }

    private function money(mixed $value): float
    {
        return $this->fromMinorUnits($this->minorUnits($value));
    }

    private function moneyLabel(int $minorUnits): string
    {
        return number_format($this->fromMinorUnits($minorUnits), 2, ',', '.').' TL';
    }
}

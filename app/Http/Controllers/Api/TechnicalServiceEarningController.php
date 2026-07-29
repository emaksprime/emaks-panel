<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TechnicalServiceEarning;
use App\Services\TechnicalService\TechnicalServiceEarningPaymentService;
use App\Services\TechnicalService\TechnicalServiceEarningService;
use App\Services\TechnicalService\TechnicalServiceSettlementReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TechnicalServiceEarningController extends Controller
{
    public function __construct(
        private readonly TechnicalServiceEarningService $earnings,
        private readonly TechnicalServiceEarningPaymentService $earningPayments,
        private readonly TechnicalServiceSettlementReviewService $settlementReviews,
    ) {
    }

    public function calculate(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $period = $this->earnings->calculatePeriod((int) $payload['year'], (int) $payload['month']);

        return response()->json([
            'period' => $period,
            'message' => 'Hakediş dönemi hesaplandı.',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'technician_id' => ['nullable', 'integer', 'exists:technical_service_technicians,id'],
            'status' => ['nullable', 'string', Rule::in(TechnicalServiceEarningService::EARNING_STATUS_FILTERS)],
        ]);

        return response()->json($this->earnings->listPeriodEarnings(
            (int) $payload['year'],
            (int) $payload['month'],
            $payload,
        ));
    }

    public function show(TechnicalServiceEarning $earning): JsonResponse
    {
        return response()->json([
            'earning' => $this->earnings->getEarningDetail($earning->id),
        ]);
    }

    public function update(Request $request, TechnicalServiceEarning $earning): JsonResponse
    {
        $payload = $request->validate([
            'status' => ['required', 'string', Rule::in(TechnicalServiceEarningService::EARNING_STATUSES)],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'dispute_note' => ['nullable', 'string', 'max:5000'],
        ]);

        return response()->json([
            'earning' => $this->earnings->updateEarningStatus(
                $earning->id,
                $payload['status'],
                $payload['internal_note'] ?? null,
                $payload['dispute_note'] ?? null,
            ),
        ]);
    }

    public function markPaid(Request $request, TechnicalServiceEarning $earning): JsonResponse
    {
        $payload = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:160'],
        ]);

        $result = $this->earningPayments->recordCompanyPayoutForEarning(
            $earning->id,
            (float) $payload['amount'],
            $payload['reason'] ?? null,
            $payload['reference'] ?? null,
            $request->user(),
        );

        return response()->json([
            'earning' => $result['earning'],
            'payments' => $result['payments'],
            'summary' => $result['summary'],
        ]);
    }

    public function review(Request $request, TechnicalServiceEarning $earning): JsonResponse
    {
        $payload = $request->validate([
            'settlement_id' => ['required', 'integer', 'exists:technical_service_settlements,id'],
            'decision' => ['required', 'string', Rule::in([
                TechnicalServiceSettlementReviewService::DECISION_APPROVE_DIFFERENCE,
                TechnicalServiceSettlementReviewService::DECISION_CORRECT_DIRECT_AMOUNT,
                TechnicalServiceSettlementReviewService::DECISION_EXCLUDE,
            ])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'customer_direct_to_technician_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $settlement = $this->settlementReviews->resolveForEarning(
            $earning,
            (int) $payload['settlement_id'],
            (string) $payload['decision'],
            $payload,
            $request->user(),
        );

        return response()->json([
            'settlement' => $settlement,
            'earning' => $this->earnings->getEarningDetail($earning->id),
        ]);
    }

    public function whatsappText(TechnicalServiceEarning $earning): JsonResponse
    {
        return response()->json([
            'text' => $this->earnings->buildWhatsappText($earning->id),
        ]);
    }
}

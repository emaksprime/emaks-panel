<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Services\TechnicalService\TechnicalServicePartRequestService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TechnicalServicePartRequestController extends Controller
{
    public function __construct(
        private readonly TechnicalServicePartRequestService $partRequests,
        private readonly TechnicalServiceWorkflowService $workflow,
    ) {}

    public function transition(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePartRequest $partRequest,
    ): JsonResponse {
        $this->assertBelongsToRequest($technicalServiceRequest, $partRequest);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                TechnicalServicePartRequest::STATUS_APPROVED,
                TechnicalServicePartRequest::STATUS_REJECTED,
                TechnicalServicePartRequest::STATUS_ORDERED,
                TechnicalServicePartRequest::STATUS_SENT,
                TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED,
                TechnicalServicePartRequest::STATUS_CLOSED,
            ])],
            'note' => ['nullable', 'string', 'max:2000'],
            'partner_message' => ['nullable', 'string', 'max:2000'],
            'charge_decision' => ['nullable', 'string', Rule::in(['free', 'chargeable'])],
            'service_amount' => ['nullable', 'numeric', 'min:0'],
            'part_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_message' => ['nullable', 'string', 'max:4000'],
            'shipment_provider' => ['nullable', 'string', 'max:255'],
            'tracking_no' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $this->partRequests->transition($partRequest, $validated['status'], $request->user(), $validated);
        $partRequestPayload = $this->partRequests->serialize($updated);
        $requestPayload = $this->workflow->serialize($technicalServiceRequest->refresh(), true);

        return response()->json([
            'ok' => true,
            'status' => $updated->status,
            'part_request' => $partRequestPayload,
            'customer_charge' => $partRequestPayload['customer_charge'] ?? null,
            'payment_summary' => $requestPayload['sale_and_payment']['payment_summary'] ?? null,
            'request' => $requestPayload,
        ]);
    }

    public function createServiceVisit(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePartRequest $partRequest,
    ): JsonResponse {
        $this->assertBelongsToRequest($technicalServiceRequest, $partRequest);
        $validated = $request->validate([
            'reason' => ['nullable', 'string', Rule::in(['spare_part', 'revisit', 'other'])],
        ]);

        $child = $this->partRequests->createServiceVisit($partRequest, $request->user(), $validated['reason'] ?? 'spare_part');

        return response()->json([
            'status' => 'created',
            'child_request' => $this->workflow->serialize($child, true),
            'request' => $this->workflow->serialize($technicalServiceRequest->refresh(), true),
        ], 201);
    }

    private function assertBelongsToRequest(TechnicalServiceRequest $request, TechnicalServicePartRequest $partRequest): void
    {
        abort_unless((int) $partRequest->technical_service_request_id === (int) $request->id, 404);
    }
}

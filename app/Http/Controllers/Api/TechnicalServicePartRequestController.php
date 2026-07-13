<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
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
        private readonly TechnicalServiceWorkflowMessageDispatchService $workflowMessages,
    ) {}

    public function store(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $validated = $request->validate([
            'part_name' => ['required', 'string', 'max:255'],
            'part_code' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'charge_decision' => ['required', 'string', Rule::in(['free', 'chargeable'])],
            'service_amount' => ['nullable', 'numeric', 'min:0'],
            'part_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'partner_message' => ['nullable', 'string', 'max:2000'],
            'customer_message' => ['nullable', 'string', 'max:4000'],
        ]);

        $partRequest = $this->partRequests->createFromOperations($technicalServiceRequest, $request->user(), $validated);
        $updated = $this->partRequests->transition($partRequest, TechnicalServicePartRequest::STATUS_APPROVED, $request->user(), [
            ...$validated,
            'partner_message' => $validated['partner_message']
                ?? ($validated['charge_decision'] === 'free' ? 'Parça ücretsiz / garanti kapsamında karşılanacak.' : null),
        ]);

        $partRequestPayload = $this->partRequests->serialize($updated);
        $requestPayload = $this->workflow->serialize($technicalServiceRequest->refresh(), true);
        $messageDispatches = [
            'part_request_ops' => $this->queuePartRequestOps($technicalServiceRequest->refresh(), $updated, $request, 'ops_part_request_created'),
        ];

        return response()->json([
            'ok' => true,
            'status' => $updated->status,
            'part_request' => $partRequestPayload,
            'customer_charge' => $partRequestPayload['customer_charge'] ?? null,
            'payment_summary' => $requestPayload['sale_and_payment']['payment_summary'] ?? null,
            'message_dispatches' => $messageDispatches,
            'request' => $requestPayload,
        ], 201);
    }

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
        $messageDispatches = [];

        return response()->json([
            'ok' => true,
            'status' => $updated->status,
            'part_request' => $partRequestPayload,
            'customer_charge' => $partRequestPayload['customer_charge'] ?? null,
            'payment_summary' => $requestPayload['sale_and_payment']['payment_summary'] ?? null,
            'message_dispatches' => $messageDispatches,
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

    /**
     * @return array<string, mixed>
     */
    private function queuePartRequestOps(
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePartRequest $partRequest,
        Request $request,
        string $triggeredBy,
    ): array {
        return $this->workflowMessages->queueWorkflowDispatches(
            $technicalServiceRequest,
            'part_request_ops',
            'ops',
            [
                'actor_name' => $request->user()?->name ?? 'OPS kullanıcı',
                'part_name' => $partRequest->part_name,
                'part_code' => $partRequest->part_code,
                'part_quantity' => (string) ($partRequest->quantity ?? 1),
                'part_reason' => $partRequest->reason ?: $partRequest->technician_note ?: 'Parça talebi',
                'next_action_text' => $partRequest->statusLabel(),
            ],
            $request->user(),
            null,
            [
                'triggered_by' => $triggeredBy,
                'event_version' => 'part-request:'.$partRequest->id.':'.($partRequest->updated_at?->timestamp ?? 'missing'),
                'metadata' => [
                    'part_request_id' => $partRequest->id,
                    'workflow_event' => 'part_request',
                ],
            ],
        );
    }
}

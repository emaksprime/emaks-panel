<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TechnicalServiceAdminOverride;
use App\Models\TechnicalServiceRequest;
use App\Services\TechnicalService\TechnicalServiceAdminOverrideService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TechnicalServiceAdminOverrideController extends Controller
{
    public function __construct(
        private readonly TechnicalServiceAdminOverrideService $overrides,
        private readonly TechnicalServiceWorkflowService $workflow,
    ) {}

    public function index(TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'overrides' => $this->overrides->serializeForRequest($technicalServiceRequest),
            'summary' => $this->overrides->summaryForRequest($technicalServiceRequest),
            'field_correction_policy' => $this->overrides->correctionPolicyPayload(),
        ]);
    }

    public function store(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $data = $request->validate([
            'field_key' => ['required', 'string', 'max:96'],
            'new_value' => ['present'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'mode' => ['nullable', 'string', 'in:apply,request'],
        ]);

        $override = $this->overrides->submit(
            $technicalServiceRequest,
            $data,
            $request->user(),
            false,
        );

        $technicalServiceRequest->refresh();

        return response()->json([
            'status' => $override->status,
            'override' => $this->overrides->serialize($override),
            'request' => $this->workflow->serialize($technicalServiceRequest, true),
        ]);
    }

    public function approve(Request $request, TechnicalServiceRequest $technicalServiceRequest, TechnicalServiceAdminOverride $override): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $override = $this->overrides->approve($technicalServiceRequest, $override, $request->user(), $data['note'] ?? null);
        $technicalServiceRequest->refresh();

        return response()->json([
            'status' => 'applied',
            'override' => $this->overrides->serialize($override),
            'request' => $this->workflow->serialize($technicalServiceRequest, true),
        ]);
    }

    public function reject(Request $request, TechnicalServiceRequest $technicalServiceRequest, TechnicalServiceAdminOverride $override): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $override = $this->overrides->reject($technicalServiceRequest, $override, $request->user(), $data['note']);
        $technicalServiceRequest->refresh();

        return response()->json([
            'status' => 'rejected',
            'override' => $this->overrides->serialize($override),
            'request' => $this->workflow->serialize($technicalServiceRequest, true),
        ]);
    }

    public function recompute(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $override = $this->overrides->logRecompute($technicalServiceRequest, $request->user(), $data['reason']);
        $technicalServiceRequest->refresh();

        return response()->json([
            'status' => 'applied',
            'override' => $this->overrides->serialize($override),
            'request' => $this->workflow->serialize($technicalServiceRequest, true),
        ]);
    }
}

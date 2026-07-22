<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TechnicalServiceMessageDispatch;
use App\Services\Messaging\TechnicalServiceMessageDispatchLogService;
use App\Services\Messaging\TechnicalServiceMessageDispatchQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TechnicalServiceMessageDispatchController extends Controller
{
    public function index(Request $request, TechnicalServiceMessageDispatchLogService $logs): JsonResponse
    {
        return response()->json([
            'message_dispatch_queue' => $logs->payload($request),
        ]);
    }

    public function show(
        TechnicalServiceMessageDispatch $dispatch,
        TechnicalServiceMessageDispatchLogService $logs,
        Request $request,
    ): JsonResponse {
        return response()->json([
            'dispatch' => $logs->detail($dispatch, $request->user()),
        ]);
    }

    public function cancel(
        TechnicalServiceMessageDispatch $dispatch,
        TechnicalServiceMessageDispatchQueue $queue,
        Request $request,
    ): JsonResponse {
        return response()->json([
            'dispatch' => $queue->cancelQueued($dispatch, $request->user())->fresh(),
            'message' => 'Queued mesaj iptal edildi.',
        ]);
    }

    public function retry(
        TechnicalServiceMessageDispatch $dispatch,
        TechnicalServiceMessageDispatchQueue $queue,
        Request $request,
    ): JsonResponse {
        return response()->json([
            'dispatch' => $queue->retryFailed($dispatch, $request->user())->fresh(),
            'message' => 'Failed mesaj retry kuyruğuna alındı.',
        ]);
    }

    public function forceResend(
        TechnicalServiceMessageDispatch $dispatch,
        TechnicalServiceMessageDispatchQueue $queue,
        Request $request,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $copy = $queue->enqueue([
            'event' => $dispatch->event,
            'request_id' => $dispatch->request_id ?? $dispatch->technical_service_request_id,
            'related_type' => $dispatch->related_type,
            'related_id' => $dispatch->related_id,
            'root_mrn' => $dispatch->root_mrn,
            'mrn' => $dispatch->mrn,
            'srv' => $dispatch->srv,
            'message_type' => $dispatch->message_type,
            'channel' => $dispatch->channel,
            'provider_key' => $dispatch->provider_key,
            'recipient_role' => $dispatch->recipient_role,
            'target_phone' => null,
            'template_key' => $dispatch->template_key,
            'template_version' => $dispatch->template_version,
            'payload' => $dispatch->request_payload ?? [],
            'channel_policy' => $dispatch->channel_policy,
            'parent_dispatch_id' => $dispatch->id,
            'force_resend' => true,
            'force_resend_reason' => $data['reason'],
            'force_resend_nonce' => now()->format('YmdHisv'),
            'metadata' => [
                ...((array) $dispatch->metadata),
                'force_resend_from_dispatch_id' => $dispatch->id,
            ],
        ], $request->user());

        return response()->json([
            'dispatch' => $copy,
            'message' => 'Force resend yeni parent-child dispatch olarak kuyruğa alındı.',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExternalEffects\ExternalExecutionControlPlaneService;
use App\Services\PanelAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExternalExecutionControlPlaneController extends Controller
{
    public function show(
        Request $request,
        ExternalExecutionControlPlaneService $controlPlane,
        PanelAccessService $access,
    ): JsonResponse {
        $user = $request->user();
        $payload = $controlPlane->payload();
        $payload['can_transition'] = $user !== null && $access->userCanAccess($user, 'technical_service_admin');

        return response()->json(['execution_control' => $payload]);
    }

    public function update(Request $request, ExternalExecutionControlPlaneService $controlPlane): JsonResponse
    {
        $this->assertPayloadKeys($request);
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in([
                ExternalExecutionControlPlaneService::MODE_LOCAL,
                ExternalExecutionControlPlaneService::MODE_LIVE,
            ])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirmation' => ['nullable', 'string', 'max:40'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);
        $actor = $request->user();
        if ($actor === null) {
            abort(403);
        }

        $correlationId = trim((string) $request->header('X-Request-ID'));
        if (preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $correlationId) !== 1) {
            $correlationId = (string) Str::uuid();
        }

        return response()->json([
            'execution_control' => $controlPlane->transition(
                (string) $data['mode'],
                (string) $data['reason'],
                $actor,
                (int) $data['expected_revision'],
                isset($data['confirmation']) ? (string) $data['confirmation'] : null,
                $correlationId,
            ),
            'message' => $data['mode'] === ExternalExecutionControlPlaneService::MODE_LIVE
                ? 'Sistem çalışma modu bütün REQUIRED capability kontrolleriyle Canlı olarak güncellendi.'
                : 'Sistem çalışma modu Lokal olarak donduruldu; dış etki kapıları kapalı.',
        ]);
    }

    private function assertPayloadKeys(Request $request): void
    {
        $allowed = ['mode', 'reason', 'confirmation', 'expected_revision'];
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages([
                'mode' => 'Çalışma modu payload yalnız mode, reason, confirmation ve expected_revision alanlarını kabul eder.',
            ]);
        }
    }
}

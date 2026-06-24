<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TechnicalService\QrPublicFlowSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TechnicalServiceQrFlowSettingsController extends Controller
{
    public function show(QrPublicFlowSettingsService $settings): JsonResponse
    {
        return response()->json([
            'settings' => $settings->payload(),
        ]);
    }

    public function update(Request $request, QrPublicFlowSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'pre_form_payment_for_mount_excluded_enabled' => ['required', 'boolean'],
        ]);

        return response()->json([
            'settings' => $settings->updatePreFormPaymentEnabled(
                (bool) $data['pre_form_payment_for_mount_excluded_enabled'],
            ),
        ]);
    }
}

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
            'pre_form_payment_for_mount_excluded_enabled' => ['sometimes', 'required', 'boolean'],
            'ops_detail_visibility' => ['sometimes', 'array'],
            'ops_detail_visibility.show_mount_excluded_approval_block' => ['sometimes', 'required', 'boolean'],
            'ops_detail_visibility.show_payment_mount_control_block' => ['sometimes', 'required', 'boolean'],
            'ops_detail_visibility.show_address_control_block' => ['sometimes', 'required', 'boolean'],
        ]);

        $updates = [];

        if (array_key_exists('pre_form_payment_for_mount_excluded_enabled', $data)) {
            $updates[QrPublicFlowSettingsService::PRE_FORM_PAYMENT_KEY] = (bool) $data['pre_form_payment_for_mount_excluded_enabled'];
        }

        $opsDetailVisibility = $data['ops_detail_visibility'] ?? [];
        if (array_key_exists('show_mount_excluded_approval_block', $opsDetailVisibility)) {
            $updates[QrPublicFlowSettingsService::OPS_SHOW_MOUNT_EXCLUDED_APPROVAL_BLOCK_KEY] = (bool) $opsDetailVisibility['show_mount_excluded_approval_block'];
        }
        if (array_key_exists('show_payment_mount_control_block', $opsDetailVisibility)) {
            $updates[QrPublicFlowSettingsService::OPS_SHOW_PAYMENT_MOUNT_CONTROL_BLOCK_KEY] = (bool) $opsDetailVisibility['show_payment_mount_control_block'];
        }
        if (array_key_exists('show_address_control_block', $opsDetailVisibility)) {
            $updates[QrPublicFlowSettingsService::OPS_SHOW_ADDRESS_CONTROL_BLOCK_KEY] = (bool) $opsDetailVisibility['show_address_control_block'];
        }

        return response()->json([
            'settings' => $settings->update($updates),
        ]);
    }
}

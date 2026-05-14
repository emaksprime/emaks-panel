<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TechnicalServiceQrLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TechnicalServiceQrLinkController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:255'],
            'product_name' => ['required', 'string', 'max:255'],
            'product_model' => ['nullable', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'link_type' => [
                'required',
                'string',
                Rule::in([
                    TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
                    TechnicalServiceQrLink::TYPE_SOLD_PRODUCT,
                    TechnicalServiceQrLink::TYPE_MANUAL_TEST,
                ]),
            ],
        ]);

        $token = Str::random(64);
        $path = '/mount-request/'.$token;
        $link = TechnicalServiceQrLink::query()->create([
            'token_hash' => TechnicalServiceQrLink::hashToken($token),
            'serial_number' => trim($data['serial_number']),
            'product_name' => trim($data['product_name']),
            'product_model' => $this->nullableText($data['product_model'] ?? null),
            'brand' => trim($data['brand']),
            'link_type' => $data['link_type'],
            'status' => TechnicalServiceQrLink::STATUS_ACTIVE,
        ]);

        return response()->json([
            'link' => [
                'id' => $link->id,
                'serial_number' => $link->serial_number,
                'product_name' => $link->product_name,
                'product_model' => $link->product_model,
                'brand' => $link->brand,
                'link_type' => $link->link_type,
                'status' => $link->status,
            ],
            'token' => $token,
            'path' => $path,
            'public_url' => url($path),
        ], 201);
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

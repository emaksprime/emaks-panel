<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TechnicalServiceQrLink;
use App\Services\TechnicalService\SerialProductContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TechnicalServiceQrLinkController extends Controller
{
    public function serialContext(Request $request, SerialProductContextResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:255'],
        ]);

        $context = $resolver->resolve($data['serial_number']);

        if (! $this->nullableText($context['product_name'] ?? null)) {
            throw ValidationException::withMessages([
                'serial_number' => 'Seri bağlamı çözülemedi. Ürün bilgisi alınamadı.',
            ]);
        }

        return response()->json([
            'context' => $this->contextPayload($context),
        ]);
    }

    public function store(Request $request, SerialProductContextResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:255'],
        ]);
        $context = $resolver->resolve($data['serial_number']);
        $productName = $this->nullableText($context['product_name'] ?? null);

        if ($productName === null) {
            throw ValidationException::withMessages([
                'serial_number' => 'Seri bağlamı çözülemedi. Ürün bilgisi alınamadı.',
            ]);
        }

        $token = Str::random(64);
        $path = '/mount-request/'.$token;
        $link = TechnicalServiceQrLink::query()->create([
            'token_hash' => TechnicalServiceQrLink::hashToken($token),
            'serial_number' => trim($context['serial_number']),
            'product_name' => $productName,
            'product_model' => $this->nullableText($context['product_model'] ?? null),
            'brand' => $this->nullableText($context['brand'] ?? null),
            'link_type' => $context['suggested_link_type'] ?? TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
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
            'context' => $this->contextPayload($context),
            'token' => $token,
            'path' => $path,
            'public_url' => url($path),
        ], 201);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function contextPayload(array $context): array
    {
        return [
            'serial_number' => $context['serial_number'],
            'product_name' => $context['product_name'],
            'product_model' => $context['product_model'],
            'brand' => $context['brand'],
            'activation_code' => $context['activation_code'],
            'sale_mount_status' => $context['sale_mount_status'],
            'suggested_link_type' => $context['suggested_link_type'] ?? TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
        ];
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

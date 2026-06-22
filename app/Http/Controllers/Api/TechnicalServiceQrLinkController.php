<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TechnicalServiceQrLink;
use App\Services\TechnicalService\SerialProductContextResolver;
use App\Support\PartnerPortalPublicUrl;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TechnicalServiceQrLinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_model' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = TechnicalServiceQrLink::query()
            ->withCount('sessions')
            ->latest('id');

        $search = $this->nullableText($filters['search'] ?? null);

        if ($search !== null) {
            $like = '%'.$search.'%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('serial_number', 'like', $like)
                    ->orWhere('product_name', 'like', $like)
                    ->orWhere('product_model', 'like', $like)
                    ->orWhere('brand', 'like', $like);
            });
        }

        $status = $this->nullableText($filters['status'] ?? null);

        if ($status !== null) {
            $query->where('status', $status);
        }

        foreach (['product_name', 'product_model', 'brand'] as $field) {
            $value = $this->nullableText($filters[$field] ?? null);

            if ($value !== null) {
                $query->where($field, 'like', '%'.$value.'%');
            }
        }

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? $filters['limit'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $links = $paginator->getCollection()
            ->map(fn (TechnicalServiceQrLink $link): array => $this->linkPayload($link))
            ->values();

        return response()->json([
            'data' => $links,
            'links' => $links,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function serialContext(Request $request, SerialProductContextResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:255'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_model' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
        ]);

        $serialNumber = $this->normalizeSerial($data['serial_number']);

        if ($serialNumber === null) {
            throw ValidationException::withMessages([
                'serial_number' => 'Seri no zorunludur.',
            ]);
        }

        return response()->json([
            'context' => $this->contextPayload($this->resolveContext($serialNumber, $data, $resolver, true, false)),
        ]);
    }

    public function store(Request $request, SerialProductContextResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:255'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_model' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
        ]);

        [$link, $context, $created] = $this->createOrReuseLink($data, $resolver, $request);

        return response()->json([
            'link' => $this->linkPayload($link),
            'context' => $this->contextPayload($context),
            'token' => $link->publicToken(),
            'path' => $link->publicPath(),
            'public_url' => $this->publicUrl($link),
            'created' => $created,
            'duplicate' => ! $created,
            'warning' => $context['warning'] ?? null,
        ], $created ? 201 : 200);
    }

    public function bulk(Request $request, SerialProductContextResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'csv_text' => ['nullable', 'string', 'max:5000000'],
            'file' => ['nullable', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $csvText = $this->nullableText($data['csv_text'] ?? null);

        if ($request->hasFile('file')) {
            $csvText = file_get_contents($request->file('file')->getRealPath()) ?: $csvText;
        }

        if ($csvText === null) {
            throw ValidationException::withMessages([
                'csv_text' => 'CSV metni veya CSV dosyası zorunludur.',
            ]);
        }

        $resultPreview = [];
        $errorPreview = [];
        $summary = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'manual_fallback' => 0,
        ];
        $previewLimit = 20;

        foreach ($this->parseCsvRows($csvText) as $index => $row) {
            $summary['total']++;

            try {
                [$link, $context, $created] = $this->createOrReuseLink($row, $resolver, $request, allowResolverFallback: false);
                $result = [
                    'row' => $index + 2,
                    'status' => $created ? 'created' : 'skipped_duplicate',
                    'message' => $context['warning'] ?? ($created ? 'QR oluşturuldu.' : 'Aynı seri için aktif QR zaten var.'),
                    'link' => $this->linkPayload($link),
                    'context' => $this->contextPayload($context),
                ];
                $summary[$created ? 'created' : 'skipped']++;

                if (in_array($context['resolution_status'] ?? null, ['manual_fallback', 'partial_with_manual'], true)) {
                    $summary['manual_fallback']++;
                }
            } catch (ValidationException $exception) {
                $result = [
                    'row' => $index + 2,
                    'status' => 'failed',
                    'message' => Arr::first(Arr::flatten($exception->errors())) ?? 'Satır işlenemedi.',
                    'serial_number' => $this->nullableText($row['serial_number'] ?? null),
                ];
                $summary['failed']++;
            } catch (\Throwable $exception) {
                $result = [
                    'row' => $index + 2,
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                    'serial_number' => $this->nullableText($row['serial_number'] ?? null),
                ];
                $summary['failed']++;
            }

            if (count($resultPreview) < $previewLimit) {
                $resultPreview[] = $result;
            }

            if (($result['status'] ?? null) === 'failed' && count($errorPreview) < $previewLimit) {
                $errorPreview[] = $result;
            }
        }

        return response()->json([
            'summary' => $summary,
            'results' => $resultPreview,
            'errors' => $errorPreview,
            'meta' => [
                'result_limit' => $previewLimit,
                'results_truncated' => $summary['total'] > $previewLimit,
                'errors_truncated' => $summary['failed'] > $previewLimit,
            ],
        ]);
    }

    public function markPrinted(TechnicalServiceQrLink $link): JsonResponse
    {
        $link->forceFill(['printed_at' => now()])->save();

        return response()->json([
            'link' => $this->linkPayload($link->fresh()),
        ]);
    }

    public function svg(TechnicalServiceQrLink $link): Response
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(360, 2),
            new SvgImageBackEnd(),
        ));

        return response($writer->writeString($this->publicUrl($link)), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{TechnicalServiceQrLink,array<string,mixed>,bool}
     */
    private function createOrReuseLink(
        array $data,
        SerialProductContextResolver $resolver,
        Request $request,
        bool $allowResolverFallback = true,
    ): array {
        $serialNumber = $this->normalizeSerial($data['serial_number'] ?? $data['seri_no'] ?? null);

        if ($serialNumber === null) {
            throw ValidationException::withMessages([
                'serial_number' => 'Seri no zorunludur.',
            ]);
        }

        $existing = TechnicalServiceQrLink::query()
            ->where('status', TechnicalServiceQrLink::STATUS_ACTIVE)
            ->get()
            ->first(fn (TechnicalServiceQrLink $link): bool => $this->normalizeSerial($link->serial_number) === $serialNumber);

        if ($existing instanceof TechnicalServiceQrLink) {
            return [$existing, $this->contextFromLink($existing), false];
        }

        $context = $this->resolveContext($serialNumber, $data, $resolver, $allowResolverFallback);
        $productName = $this->nullableText($context['product_name'] ?? null);

        if ($productName === null) {
            throw ValidationException::withMessages([
                'serial_number' => 'Seri Mikro’da çözülemedi. QR oluşturmak için ürün adı girin.',
            ]);
        }

        $token = Str::random(64);
        $link = TechnicalServiceQrLink::query()->create([
            'token_hash' => TechnicalServiceQrLink::hashToken($token),
            'public_token' => $token,
            'serial_number' => $serialNumber,
            'product_name' => $productName,
            'product_model' => $this->nullableText($context['product_model'] ?? null),
            'brand' => $this->nullableText($context['brand'] ?? null),
            'link_type' => $context['suggested_link_type'] ?? TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'status' => TechnicalServiceQrLink::STATUS_ACTIVE,
            'created_by' => $request->user()?->id,
            'scan_count' => 0,
            'metadata' => [
                'serial_context' => $this->contextPayload($context),
                'source' => 'technical_service_qr_products',
            ],
        ]);

        return [$link, $context, true];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function contextPayload(array $context): array
    {
        return [
            'serial_number' => $context['serial_number'] ?? null,
            'product_name' => $context['product_name'] ?? null,
            'product_model' => $context['product_model'] ?? null,
            'brand' => $context['brand'] ?? null,
            'activation_code' => $context['activation_code'] ?? null,
            'sale_mount_status' => $context['sale_mount_status'] ?? 'unknown',
            'suggested_link_type' => $context['suggested_link_type'] ?? TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'current_serial_state' => $context['current_serial_state'] ?? 'unknown',
            'has_current_sale' => $context['has_current_sale'] ?? false,
            'latest_event_type' => $context['latest_event_type'] ?? null,
            'latest_valid_sale_exists' => $context['latest_valid_sale_exists'] ?? false,
            'stock_code' => $context['stock_code'] ?? null,
            'resolution_status' => $context['resolution_status'] ?? 'unknown',
            'resolution_source' => $context['resolution_source'] ?? null,
            'warning' => $context['warning'] ?? null,
            'requires_manual_product' => $context['requires_manual_product'] ?? false,
            'ops_review_required' => $context['ops_review_required'] ?? false,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveContext(
        string $serialNumber,
        array $data,
        SerialProductContextResolver $resolver,
        bool $allowResolverFallback,
        bool $enforceProductName = true,
    ): array {
        $manualProductName = $this->nullableText($data['product_name'] ?? null);
        $manualProductModel = $this->nullableText($data['product_model'] ?? $data['model'] ?? null);
        $manualBrand = $this->nullableText($data['brand'] ?? null);
        $context = [];
        $resolverFailed = false;

        if ($allowResolverFallback || $manualProductName === null) {
            try {
                $context = $resolver->resolve($serialNumber);
            } catch (\Throwable) {
                $context = [];
                $resolverFailed = true;
            }
        }

        $resolverProductName = $this->nullableText($context['product_name'] ?? null);
        $productName = $resolverProductName ?? $manualProductName;
        $productModel = $this->nullableText($context['product_model'] ?? null) ?? $manualProductModel;
        $brand = $this->nullableText($context['brand'] ?? null) ?? $manualBrand;
        $hasResolverEvidence = $context !== [] && ! $resolverFailed;
        $resolutionStatus = 'resolved';
        $resolutionSource = 'mikro_resolver';
        $warning = null;
        $requiresManualProduct = false;
        $opsReviewRequired = false;

        if ($resolverProductName === null && $manualProductName !== null) {
            $resolutionStatus = $hasResolverEvidence ? 'partial_with_manual' : 'manual_fallback';
            $resolutionSource = $hasResolverEvidence ? 'partial_mikro_manual' : ($allowResolverFallback ? 'manual_fallback' : 'manual_csv');
            $warning = $hasResolverEvidence
                ? 'Mikro seri kaydı kısmi geldi; manuel ürün bilgisiyle QR oluşturuldu.'
                : 'Mikro seri kaydı bulunamadı; manuel ürün bilgisiyle QR oluşturuldu.';
            $opsReviewRequired = true;
        } elseif ($resolverProductName === null) {
            $resolutionStatus = 'requires_manual_product';
            $resolutionSource = $resolverFailed ? 'mikro_resolver_failed' : ($hasResolverEvidence ? 'partial_mikro' : 'mikro_not_found');
            $warning = 'Seri Mikro’da çözülemedi. QR oluşturmak için ürün adı girin.';
            $requiresManualProduct = true;
        }

        if ($enforceProductName && $productName === null) {
            throw ValidationException::withMessages([
                'serial_number' => 'Seri Mikro’da çözülemedi. QR oluşturmak için ürün adı girin.',
            ]);
        }

        return [
            ...$context,
            'serial_number' => $serialNumber,
            'product_name' => $productName,
            'product_model' => $productModel,
            'brand' => $brand,
            'activation_code' => $context['activation_code'] ?? null,
            'sale_mount_status' => $context['sale_mount_status'] ?? 'unknown',
            'suggested_link_type' => $context['suggested_link_type'] ?? TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'current_serial_state' => $context['current_serial_state'] ?? 'unknown',
            'has_current_sale' => $context['has_current_sale'] ?? false,
            'latest_event_type' => $context['latest_event_type'] ?? null,
            'latest_valid_sale_exists' => $context['latest_valid_sale_exists'] ?? false,
            'stock_code' => $context['stock_code'] ?? null,
            'resolution_status' => $resolutionStatus,
            'resolution_source' => $resolutionSource,
            'warning' => $warning,
            'requires_manual_product' => $requiresManualProduct,
            'ops_review_required' => $opsReviewRequired,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFromLink(TechnicalServiceQrLink $link): array
    {
        $metadata = is_array($link->metadata) ? $link->metadata : [];
        $storedContext = is_array($metadata['serial_context'] ?? null) ? $metadata['serial_context'] : [];

        return [
            ...$storedContext,
            'serial_number' => $link->serial_number,
            'product_name' => $link->product_name,
            'product_model' => $link->product_model,
            'brand' => $link->brand,
            'suggested_link_type' => $link->link_type,
            'sale_mount_status' => $storedContext['sale_mount_status'] ?? 'unknown',
            'resolution_status' => $storedContext['resolution_status'] ?? 'resolved',
            'resolution_source' => $storedContext['resolution_source'] ?? null,
            'warning' => $storedContext['warning'] ?? null,
            'requires_manual_product' => $storedContext['requires_manual_product'] ?? false,
            'ops_review_required' => $storedContext['ops_review_required'] ?? false,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCsvRows(string $csvText): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csvText)) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));

        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'csv_text' => 'CSV başlık ve en az bir satır içermelidir.',
            ]);
        }

        $headers = array_map(
            fn (string $header): string => $this->normalizeHeader($header),
            str_getcsv(array_shift($lines) ?: ''),
        );

        $rows = [];

        foreach ($lines as $line) {
            $values = str_getcsv($line);
            $row = [];

            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = $values[$index] ?? null;
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $header = trim(mb_strtolower($header, 'UTF-8'));

        return match ($header) {
            'seri_no', 'seri no', 'serial', 'serial_no', 'serial_number' => 'serial_number',
            'ürün', 'urun', 'urun_adi', 'ürün adı', 'product', 'product_name' => 'product_name',
            'model', 'product_model' => 'product_model',
            'marka', 'brand' => 'brand',
            default => $header,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function linkPayload(TechnicalServiceQrLink $link): array
    {
        $metadata = is_array($link->metadata) ? $link->metadata : [];

        return [
            'id' => $link->id,
            'serial_number' => $link->serial_number,
            'product_name' => $link->product_name,
            'product_model' => $link->product_model,
            'brand' => $link->brand,
            'link_type' => $link->link_type,
            'link_type_label' => $this->linkTypeLabel($link->link_type),
            'status' => $link->status,
            'status_label' => $this->statusLabel($link->status),
            'token' => $link->publicToken(),
            'path' => $link->publicPath(),
            'public_url' => $this->publicUrl($link),
            'qr_svg_url' => route('api.technical-service.qr-products.svg', ['link' => $link], false),
            'printed_at' => $link->printed_at?->toISOString(),
            'last_scanned_at' => $link->last_scanned_at?->toISOString(),
            'scan_count' => (int) $link->scan_count,
            'sessions_count' => (int) ($link->sessions_count ?? $link->sessions()->count()),
            'created_at' => $link->created_at?->toISOString(),
            'serial_context' => is_array($metadata['serial_context'] ?? null) ? $metadata['serial_context'] : null,
        ];
    }

    private function publicUrl(TechnicalServiceQrLink $link): string
    {
        return PartnerPortalPublicUrl::url($link->publicPath());
    }

    private function normalizeSerial(mixed $value): ?string
    {
        $value = $this->nullableText($value);

        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\s\p{Z}\x{200B}\x{200C}\x{200D}\x{FEFF}]+/u', '', $value) ?? $value;

        return mb_strtoupper($value, 'UTF-8');
    }

    private function linkTypeLabel(?string $value): string
    {
        return match ($value) {
            TechnicalServiceQrLink::TYPE_SOLD_PRODUCT => 'Satılmış ürün',
            TechnicalServiceQrLink::TYPE_MANUAL_TEST => 'Test linki',
            default => 'Ön baskı / ürün QR',
        };
    }

    private function statusLabel(?string $value): string
    {
        return match ($value) {
            TechnicalServiceQrLink::STATUS_REVOKED => 'İptal edildi',
            TechnicalServiceQrLink::STATUS_EXPIRED => 'Süresi doldu',
            default => 'Aktif',
        };
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

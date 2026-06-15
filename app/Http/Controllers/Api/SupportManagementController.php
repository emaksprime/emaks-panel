<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportActivationCode;
use App\Services\SupportManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportManagementController extends Controller
{
    public function __construct(
        private readonly SupportManagementService $supportManagement,
    ) {}

    public function activationCodes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50])],
        ]);

        return response()->json(
            $this->supportManagement->activationCodeList(
                $data['search'] ?? null,
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? 25),
            ),
        );
    }

    public function storeActivationCode(Request $request): JsonResponse
    {
        $data = $this->validateActivationCode($request);

        return response()->json(
            $this->supportManagement->saveActivationCode($data, (int) $request->user()->id),
        );
    }

    public function updateActivationCode(Request $request, SupportActivationCode $supportActivationCode): JsonResponse
    {
        $data = $this->validateActivationCode($request);

        return response()->json(
            $this->supportManagement->saveActivationCode($data, (int) $request->user()->id, $supportActivationCode),
        );
    }

    public function previewImport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'file', 'mimes:csv,txt,xlsx', 'max:5120'],
            'paste_text' => ['nullable', 'string', 'max:2000000'],
            'source' => ['nullable', 'string', Rule::in(['csv', 'xlsx', 'paste'])],
        ]);

        $file = $request->file('file');
        $contents = null;
        $filename = null;
        $source = $data['source'] ?? ($file ? pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) : 'paste');

        if ($file) {
            $path = $file->getRealPath();
            $contents = $path ? file_get_contents($path) : false;
            $filename = $file->getClientOriginalName();
        } elseif (array_key_exists('paste_text', $data)) {
            $contents = (string) $data['paste_text'];
        }

        if (! is_string($contents) || trim($contents) === '') {
            return response()->json([
                'message' => 'CSV/XLSX dosyası veya yapıştırılmış veri zorunlu.',
            ], 422);
        }

        return response()->json(
            $this->supportManagement->previewActivationImport($contents, $source, $filename),
        );
    }

    public function commitImport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*' => ['array'],
            'source' => ['nullable', 'string', Rule::in(['csv', 'xlsx', 'paste'])],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(
            $this->supportManagement->commitActivationImport(
                $data['rows'],
                (int) $request->user()->id,
                $data['source'] ?? 'csv',
                $data['filename'] ?? null,
            ),
        );
    }

    public function guides(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(
            $this->supportManagement->guideProductList($data['search'] ?? null),
        );
    }

    public function storeGuide(Request $request): JsonResponse
    {
        $data = $this->validateGuideProduct($request);

        return response()->json(
            $this->supportManagement->saveGuideProduct($data, (int) $request->user()->id),
        );
    }

    public function updateGuide(Request $request, string $guide): JsonResponse
    {
        $data = $this->validateGuideProduct($request);

        return response()->json(
            $this->supportManagement->saveGuideProduct($data, (int) $request->user()->id, $guide),
        );
    }

    public function duplicateGuide(Request $request, string $guide): JsonResponse
    {
        return response()->json(
            $this->supportManagement->duplicateGuideProduct($guide, (int) $request->user()->id),
        );
    }

    public function storeGuideStep(Request $request, string $guide): JsonResponse
    {
        $data = $this->validateGuideStep($request);

        return response()->json(
            $this->supportManagement->saveGuideStep($guide, $data, (int) $request->user()->id),
        );
    }

    public function updateGuideStep(
        Request $request,
        string $guide,
        string $step,
    ): JsonResponse {
        $data = $this->validateGuideStep($request);

        return response()->json(
            $this->supportManagement->saveGuideStep(
                $guide,
                $data,
                (int) $request->user()->id,
                $step,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateActivationCode(Request $request): array
    {
        return $request->validate([
            'stock_code' => ['nullable', 'string', 'max:255'],
            'stock_name' => ['nullable', 'string', 'max:1000'],
            'serial_number' => ['required', 'string', 'max:255'],
            'serial_number_clean' => ['nullable', 'string', 'max:255'],
            'activation_code' => ['nullable', 'string', 'max:255'],
            'search_code' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGuideProduct(Request $request): array
    {
        return $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'search_keywords' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGuideStep(Request $request): array
    {
        return $request->validate([
            'section_type' => ['required', 'string', Rule::in(['pairing', 'fingerprint', 'pin', 'card', 'remote', 'reset', 'other'])],
            'custom_title' => ['nullable', 'required_if:section_type,other', 'string', 'max:255'],
            'entry_method' => ['nullable', 'string', 'max:255'],
            'entry_format' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:20000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
    }
}

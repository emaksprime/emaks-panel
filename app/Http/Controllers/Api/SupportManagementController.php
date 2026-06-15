<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportActivationCode;
use App\Models\SupportGuideEntry;
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
        ]);

        return response()->json(
            $this->supportManagement->activationCodeList($data['search'] ?? null),
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
            'file' => ['nullable', 'file', 'mimes:csv,txt', 'max:2048'],
            'paste_text' => ['nullable', 'string', 'max:2000000'],
            'source' => ['nullable', 'string', Rule::in(['csv', 'paste'])],
        ]);

        $file = $request->file('file');
        $contents = null;
        $filename = null;
        $source = $data['source'] ?? ($file ? 'csv' : 'paste');

        if ($file) {
            $path = $file->getRealPath();
            $contents = $path ? file_get_contents($path) : false;
            $filename = $file->getClientOriginalName();
        } elseif (array_key_exists('paste_text', $data)) {
            $contents = (string) $data['paste_text'];
        }

        if (! is_string($contents) || trim($contents) === '') {
            return response()->json([
                'message' => 'CSV dosyası veya yapıştırılmış veri zorunlu.',
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
            'source' => ['nullable', 'string', Rule::in(['csv', 'paste'])],
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
            $this->supportManagement->guideList($data['search'] ?? null),
        );
    }

    public function storeGuide(Request $request): JsonResponse
    {
        $data = $this->validateGuide($request);

        return response()->json(
            $this->supportManagement->saveGuideEntry($data, (int) $request->user()->id),
        );
    }

    public function updateGuide(Request $request, SupportGuideEntry $supportGuideEntry): JsonResponse
    {
        $data = $this->validateGuide($request);

        return response()->json(
            $this->supportManagement->saveGuideEntry($data, (int) $request->user()->id, $supportGuideEntry),
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
    private function validateGuide(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'stok_kodu' => ['nullable', 'string', 'max:255'],
            'product_keyword' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', 'string', 'max:255'],
            'guide_content' => ['required', 'string', 'max:20000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
    }
}

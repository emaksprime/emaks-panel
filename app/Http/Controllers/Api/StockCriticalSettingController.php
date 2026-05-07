<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PanelAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockCriticalSettingController extends Controller
{
    public function index(Request $request, PanelAccessService $access): JsonResponse
    {
        abort_unless(
            ($access->userCanAccess($request->user(), 'stock') || $access->userCanAccess($request->user(), 'stock_critical'))
                && $access->stockScopeFor($request->user()) !== null,
            403,
            'Yetki bulunmamaktadır.',
        );

        return response()->json([
            'rows' => DB::table('stock_critical_settings')
                ->where('active', true)
                ->orderBy('stock_code')
                ->get()
                ->map(fn (object $row): array => $this->serialize($row))
                ->values(),
            'can_manage' => $this->canManage($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->canManage($request), 403, 'Yetki bulunmamaktadır.');

        $validated = $request->validate([
            'stock_code' => ['required', 'string', 'max:255'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'threshold_quantity' => ['required', 'numeric', 'gt:0'],
            'active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $now = now();
        $stockCode = trim($validated['stock_code']);
        $existing = DB::table('stock_critical_settings')->where('stock_code', $stockCode)->first();

        $values = [
            'product_name' => $validated['product_name'] ?? null,
            'category' => $validated['category'] ?? null,
            'threshold_quantity' => $validated['threshold_quantity'],
            'active' => (bool) ($validated['active'] ?? true),
            'note' => $validated['note'] ?? null,
            'updated_by_user_id' => $request->user()?->id,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('stock_critical_settings')->where('stock_code', $stockCode)->update($values);
        } else {
            DB::table('stock_critical_settings')->insert([
                'stock_code' => $stockCode,
                ...$values,
                'created_by_user_id' => $request->user()?->id,
                'created_at' => $now,
            ]);
        }

        $row = DB::table('stock_critical_settings')->where('stock_code', $stockCode)->first();

        return response()->json([
            'row' => $row ? $this->serialize($row) : null,
        ]);
    }

    public function destroy(Request $request, string $stockCode): JsonResponse
    {
        abort_unless($this->canManage($request), 403, 'Yetki bulunmamaktadır.');

        DB::table('stock_critical_settings')
            ->where('stock_code', $stockCode)
            ->update([
                'active' => false,
                'updated_by_user_id' => $request->user()?->id,
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    private function canManage(Request $request): bool
    {
        return (bool) $request->user()?->role?->is_super_admin;
    }

    private function serialize(object $row): array
    {
        return [
            'stock_code' => (string) $row->stock_code,
            'product_name' => $row->product_name,
            'category' => $row->category,
            'threshold_quantity' => (float) $row->threshold_quantity,
            'active' => (bool) $row->active,
            'note' => $row->note,
        ];
    }
}

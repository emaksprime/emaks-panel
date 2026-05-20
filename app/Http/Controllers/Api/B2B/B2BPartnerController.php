<?php

namespace App\Http\Controllers\Api\B2B;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartner;
use App\Services\B2B\B2BPartnerAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class B2BPartnerController extends Controller
{
    public function __construct(
        private readonly B2BPartnerAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'partner_type' => ['nullable', 'string', Rule::in([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH])],
            'active' => ['nullable', 'boolean'],
            'city' => ['nullable', 'string', 'max:128'],
            'search' => ['nullable', 'string', 'max:255'],
            'mikro_cari_kodu' => ['nullable', 'string', 'max:128'],
        ]);

        $user = $request->user();
        abort_unless($user, 403);

        $query = $this->access->visiblePartnerQuery($user, $filters['partner_type'] ?? null);
        $this->applyFilters($query, $filters);

        return response()->json([
            'items' => $query
                ->orderByDesc('active')
                ->orderBy('partner_type')
                ->orderBy('display_name')
                ->get()
                ->map(fn (B2BPartner $partner): array => $this->partnerPayload($partner))
                ->values(),
        ]);
    }

    public function show(Request $request, B2BPartner $partner): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canViewPartner($user, $partner), 403);

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        if (array_key_exists('active', $filters)) {
            $query->where('active', $filters['active']);
        }

        if (! empty($filters['city'])) {
            $query->where('city', $likeOperator, $filters['city']);
        }

        if (! empty($filters['mikro_cari_kodu'])) {
            $query->where('mikro_cari_kodu', $likeOperator, '%'.$filters['mikro_cari_kodu'].'%');
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $query) use ($likeOperator, $search): void {
                $query->where('partner_code', $likeOperator, '%'.$search.'%')
                    ->orWhere('display_name', $likeOperator, '%'.$search.'%')
                    ->orWhere('mikro_cari_kodu', $likeOperator, '%'.$search.'%')
                    ->orWhere('mikro_cari_unvan', $likeOperator, '%'.$search.'%')
                    ->orWhere('city', $likeOperator, '%'.$search.'%')
                    ->orWhere('district', $likeOperator, '%'.$search.'%');
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerPayload(B2BPartner $partner): array
    {
        return [
            'id' => $partner->id,
            'partner_type' => $partner->partner_type,
            'partner_code' => $partner->partner_code,
            'display_name' => $partner->display_name,
            'mikro_cari_kodu' => $partner->mikro_cari_kodu,
            'mikro_cari_unvan' => $partner->mikro_cari_unvan,
            'city' => $partner->city,
            'district' => $partner->district,
            'active' => (bool) $partner->active,
            'technical_service_technician_id' => $partner->technical_service_technician_id,
            'linked_technician_name' => $partner->technician?->name,
        ];
    }
}

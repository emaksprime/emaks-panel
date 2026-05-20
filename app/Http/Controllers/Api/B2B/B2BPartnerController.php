<?php

namespace App\Http\Controllers\Api\B2B;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\TechnicalServiceTechnician;
use App\Services\B2B\B2BPartnerAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPartnerData($request);
        $user = $request->user();
        abort_unless($user && $this->access->canCreatePartner($user, $data['partner_type']), 403);

        $payload = $this->partnerWritePayload($data);

        $partner = DB::transaction(function () use ($payload, $request, $user): B2BPartner {
            $partner = B2BPartner::query()->create($payload);

            $this->writeAuditLog(
                $partner,
                $request,
                'b2b.partner.created',
                null,
                $this->auditPayload($partner),
                $user->id,
            );

            return $partner;
        });

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
        ], 201);
    }

    public function update(Request $request, B2BPartner $partner): JsonResponse
    {
        $data = $this->validatedPartnerData($request, $partner);
        $user = $request->user();
        abort_unless($user && $this->access->canUpdatePartner($user, $partner), 403);

        $partner = DB::transaction(function () use ($partner, $data, $request, $user): B2BPartner {
            $oldValues = $this->auditPayload($partner);
            $partner->fill($this->partnerWritePayload($data));
            $partner->save();
            $partner->refresh();

            $this->writeAuditLog(
                $partner,
                $request,
                'b2b.partner.updated',
                $oldValues,
                $this->auditPayload($partner),
                $user->id,
            );

            return $partner;
        });

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
        ]);
    }

    public function updateActive(Request $request, B2BPartner $partner): JsonResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
        ]);
        $user = $request->user();
        abort_unless($user && $this->access->canUpdatePartner($user, $partner), 403);

        $partner = DB::transaction(function () use ($partner, $data, $request, $user): B2BPartner {
            $oldValues = $this->auditPayload($partner);
            $partner->forceFill(['active' => (bool) $data['active']])->save();
            $partner->refresh();

            $this->writeAuditLog(
                $partner,
                $request,
                'b2b.partner.active_changed',
                $oldValues,
                $this->auditPayload($partner),
                $user->id,
            );

            return $partner;
        });

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
        ]);
    }

    public function locksmithTechnicians(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
        ]);

        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query = TechnicalServiceTechnician::query()
            ->where('active', true)
            ->where('technician_type', 'locksmith');

        if (! empty($filters['city'])) {
            $query->where('city', $likeOperator, $filters['city']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $query) use ($likeOperator, $search): void {
                $query->where('name', $likeOperator, '%'.$search.'%')
                    ->orWhere('phone', $likeOperator, '%'.$search.'%')
                    ->orWhere('mikro_cari_kodu', $likeOperator, '%'.$search.'%')
                    ->orWhere('city', $likeOperator, '%'.$search.'%');
            });
        }

        return response()->json([
            'items' => $query
                ->orderBy('name')
                ->limit(50)
                ->get()
                ->map(fn (TechnicalServiceTechnician $technician): array => [
                    'id' => $technician->id,
                    'name' => $technician->name,
                    'phone' => $technician->phone,
                    'city' => $technician->city,
                    'district' => $technician->district,
                    'mikro_cari_kodu' => $technician->mikro_cari_kodu,
                    'mikro_cari_adi' => $technician->mikro_cari_adi,
                    'source_key' => 'technical_service_technician:'.$technician->id,
                ])
                ->values(),
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

    private function validatedPartnerData(Request $request, ?B2BPartner $partner = null): array
    {
        $data = $request->validate([
            'partner_type' => ['required', 'string', Rule::in([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH])],
            'partner_code' => [
                'required',
                'string',
                'max:128',
                Rule::unique((new B2BPartner)->getTable(), 'partner_code')->ignore($partner?->id),
            ],
            'display_name' => ['required', 'string', 'max:255'],
            'mikro_cari_kodu' => ['nullable', 'string', 'max:128'],
            'mikro_cari_unvan' => ['nullable', 'string', 'max:255'],
            'cari_grup_kodu' => ['nullable', 'string', 'max:128'],
            'responsibility_code' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'district' => ['nullable', 'string', 'max:128'],
            'active' => ['sometimes', 'boolean'],
            'technical_service_technician_id' => [
                'nullable',
                'integer',
                Rule::exists((new TechnicalServiceTechnician)->getTable(), 'id'),
            ],
        ]);

        if ($data['partner_type'] === B2BPartner::TYPE_DEALER && ! empty($data['technical_service_technician_id'])) {
            throw ValidationException::withMessages([
                'technical_service_technician_id' => 'Bayi partner için teknik servis ustası bağlantısı kullanılamaz.',
            ]);
        }

        if (
            $data['partner_type'] === B2BPartner::TYPE_LOCKSMITH
            && ! empty($data['technical_service_technician_id'])
            && ! TechnicalServiceTechnician::query()
                ->whereKey($data['technical_service_technician_id'])
                ->where('technician_type', 'locksmith')
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'technical_service_technician_id' => 'Seçilen teknik servis kaydı çilingir tipinde olmalıdır.',
            ]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function partnerWritePayload(array $data): array
    {
        return [
            'partner_type' => $data['partner_type'],
            'partner_code' => $this->nullableString($data['partner_code']),
            'display_name' => $this->nullableString($data['display_name']),
            'mikro_cari_kodu' => $this->nullableString($data['mikro_cari_kodu'] ?? null),
            'mikro_cari_unvan' => $this->nullableString($data['mikro_cari_unvan'] ?? null),
            'cari_grup_kodu' => $this->nullableString($data['cari_grup_kodu'] ?? null),
            'responsibility_code' => $this->nullableString($data['responsibility_code'] ?? null),
            'phone' => $this->nullableString($data['phone'] ?? null),
            'email' => $this->nullableString($data['email'] ?? null),
            'city' => $this->nullableString($data['city'] ?? null),
            'district' => $this->nullableString($data['district'] ?? null),
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'technical_service_technician_id' => $data['partner_type'] === B2BPartner::TYPE_LOCKSMITH
                ? ($data['technical_service_technician_id'] ?? null)
                : null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(B2BPartner $partner): array
    {
        return $partner->only([
            'partner_type',
            'partner_code',
            'display_name',
            'mikro_cari_kodu',
            'mikro_cari_unvan',
            'cari_grup_kodu',
            'responsibility_code',
            'phone',
            'email',
            'city',
            'district',
            'active',
            'technical_service_technician_id',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function writeAuditLog(
        B2BPartner $partner,
        Request $request,
        string $action,
        ?array $oldValues,
        array $newValues,
        int $userId,
    ): void {
        B2BPartnerAuditLog::query()->create([
            'partner_id' => $partner->id,
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => B2BPartner::class,
            'subject_id' => $partner->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
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
            'cari_grup_kodu' => $partner->cari_grup_kodu,
            'responsibility_code' => $partner->responsibility_code,
            'phone' => $partner->phone,
            'email' => $partner->email,
            'city' => $partner->city,
            'district' => $partner->district,
            'active' => (bool) $partner->active,
            'technical_service_technician_id' => $partner->technical_service_technician_id,
            'linked_technician_name' => $partner->technician?->name,
            'linked_technician_phone' => $partner->technician?->phone,
        ];
    }
}

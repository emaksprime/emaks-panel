<?php

namespace App\Http\Controllers\Api\B2B;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\DataSource;
use App\Models\TechnicalServiceTechnician;
use App\Services\B2B\B2BCariControlService;
use App\Services\B2B\B2BPartnerAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class B2BPartnerController extends Controller
{
    public function __construct(
        private readonly B2BPartnerAccessService $access,
        private readonly B2BCariControlService $cariControlService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'partner_type' => ['nullable', 'string', Rule::in(B2BPartner::SUPPORTED_CAPABILITIES)],
            'capability' => ['nullable', 'string', Rule::in(B2BPartner::SUPPORTED_CAPABILITIES)],
            'active' => ['nullable', 'boolean'],
            'city' => ['nullable', 'string', 'max:128'],
            'search' => ['nullable', 'string', 'max:255'],
            'mikro_cari_kodu' => ['nullable', 'string', 'max:128'],
        ]);

        $user = $request->user();
        abort_unless($user, 403);

        $query = $this->access->visiblePartnerQuery($user, $filters['capability'] ?? $filters['partner_type'] ?? null);
        $this->applyFilters($query, $filters);

        return response()->json([
            'items' => $query
                ->orderByDesc('active')
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
            'partner' => $this->partnerPayload($partner->loadMissing(['technician', 'capabilities'])),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPartnerData($request);
        $user = $request->user();
        abort_unless($user && $this->access->canManageCapabilities($user, $data['capabilities']), 403);

        $writePayload = $this->partnerWritePayload($data);
        $capabilities = $writePayload['capabilities'];
        unset($writePayload['capabilities']);

        $partner = DB::transaction(function () use ($writePayload, $capabilities, $request, $user): B2BPartner {
            $partner = B2BPartner::query()->create($writePayload);
            $this->syncCapabilities($partner, $capabilities, $request, $user->id, []);

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
            'partner' => $this->partnerPayload($partner->loadMissing(['technician', 'capabilities'])),
        ], 201);
    }

    public function update(Request $request, B2BPartner $partner): JsonResponse
    {
        $data = $this->validatedPartnerData($request, $partner);
        $user = $request->user();
        abort_unless($user && $this->access->canManagePartner($user, $partner) && $this->access->canManageCapabilities($user, $data['capabilities']), 403);

        $partner = DB::transaction(function () use ($partner, $data, $request, $user): B2BPartner {
            $oldValues = $this->auditPayload($partner);
            $oldCapabilities = $partner->capabilityCodes();
            $writePayload = $this->partnerWritePayload($data);
            $capabilities = $writePayload['capabilities'];
            unset($writePayload['capabilities']);
            $partner->fill($writePayload);
            $partner->save();
            $this->syncCapabilities($partner, $capabilities, $request, $user->id, $oldCapabilities);
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
            'partner' => $this->partnerPayload($partner->loadMissing(['technician', 'capabilities'])),
        ]);
    }

    public function updateActive(Request $request, B2BPartner $partner): JsonResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
        ]);
        $user = $request->user();
        abort_unless($user && $this->access->canUpdatePartner($user, $partner), 403);

        if ((bool) $data['active'] && $partner->technical_service_technician_id && $this->activeTechnicianLinked($partner->technical_service_technician_id, $partner)) {
            throw ValidationException::withMessages([
                'technical_service_technician_id' => 'Bu teknik servis ustası zaten aktif bir B2B partner kaydına bağlı.',
            ]);
        }

        if ((bool) $data['active'] && $this->activeMikroCariLinked($partner->mikro_cari_kodu, $partner, true)) {
            throw ValidationException::withMessages([
                'mikro_cari_kodu' => 'Bu Mikro cari zaten aktif bir B2B partner kaydına bağlı.',
            ]);
        }

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
            'partner' => $this->partnerPayload($partner->loadMissing(['technician', 'capabilities'])),
        ]);
    }

    public function updateCapabilities(Request $request, B2BPartner $partner): JsonResponse
    {
        $data = $request->validate([
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', 'string', Rule::in(B2BPartner::SUPPORTED_CAPABILITIES)],
        ]);
        $capabilities = $this->normalizeCapabilities($data);
        $user = $request->user();
        abort_unless($user && $this->access->canManagePartner($user, $partner) && $this->access->canManageCapabilities($user, $capabilities), 403);

        $partner = DB::transaction(function () use ($partner, $capabilities, $request, $user): B2BPartner {
            $oldValues = $this->auditPayload($partner);
            $oldCapabilities = $partner->capabilityCodes();
            $partner->forceFill([
                'partner_type' => $this->primaryPartnerType($capabilities),
                'technical_service_technician_id' => in_array(B2BPartner::TYPE_LOCKSMITH, $capabilities, true)
                    ? $partner->technical_service_technician_id
                    : null,
            ])->save();
            $this->syncCapabilities($partner, $capabilities, $request, $user->id, $oldCapabilities);
            $partner->refresh();

            $this->writeAuditLog(
                $partner,
                $request,
                'b2b.partner.capabilities_updated',
                $oldValues,
                $this->auditPayload($partner),
                $user->id,
            );

            return $partner;
        });

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing(['technician', 'capabilities'])),
        ]);
    }

    public function cariControl(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user
            && (
                $this->access->canManageCapabilities($user, [B2BPartner::TYPE_DEALER])
                || $this->access->canManageCapabilities($user, [B2BPartner::TYPE_LOCKSMITH])
                || $this->access->canManageCapabilities($user, [B2BPartner::TYPE_MANUFACTURER])
                || $this->access->canManageCapabilities($user, [B2BPartner::TYPE_SELLER])
            ),
            403,
        );

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'capability' => ['nullable', 'string', Rule::in(B2BPartner::SUPPORTED_CAPABILITIES)],
            'city' => ['nullable', 'string', 'max:128'],
            'include_review_required' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:250'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($this->cariControlService->candidateResponse($filters));

        $search = trim((string) $request->query('search', ''));
        $existingSources = $this->existingCariSources();

        return response()->json([
            'status' => 'query_contract_required',
            'message' => 'Mikro cari adayları için SELECT-only sorgu sözleşmesi hazır. Mevcut cari kaynakları n8n/data_source zincirine bağlı; onaylı aday verisi gelmeden otomatik partner açılmaz.',
            'search' => $search,
            'items' => [],
            'existing_sources' => $existingSources,
            'query_contract' => [
                'document_path' => 'docs/b2b-mikro-cari-control-query-contract.md',
                'mode' => 'select_only_discovery',
                'discovery_queries' => $this->cariDiscoveryQueries(),
                'candidate_schema' => [
                    'mikro_cari_kodu',
                    'display_name',
                    'mikro_cari_unvan',
                    'cari_grup_kodu',
                    'responsibility_code',
                    'phone',
                    'email',
                    'city',
                    'district',
                    'suggested_capabilities',
                    'status',
                    'status_label',
                ],
            ],
            'actions_enabled' => false,
        ]);
    }

    public function importCariControl(Request $request): JsonResponse
    {
        $request->merge([
            'action' => 'import',
            'candidates' => $request->input('candidates', $request->input('items', [])),
        ]);

        return $this->applyCariControl($request);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.mikro_cari_kodu' => ['required', 'string', 'max:128'],
            'items.*.display_name' => ['nullable', 'string', 'max:255'],
            'items.*.mikro_cari_unvan' => ['nullable', 'string', 'max:255'],
            'items.*.cari_grup_kodu' => ['nullable', 'string', 'max:128'],
            'items.*.responsibility_code' => ['nullable', 'string', 'max:128'],
            'items.*.phone' => ['nullable', 'string', 'max:64'],
            'items.*.email' => ['nullable', 'email', 'max:255'],
            'items.*.city' => ['nullable', 'string', 'max:128'],
            'items.*.district' => ['nullable', 'string', 'max:128'],
            'items.*.capabilities' => ['required', 'array', 'min:1'],
            'items.*.capabilities.*' => ['required', 'string', Rule::in([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH])],
        ]);
        $user = $request->user();
        abort_unless($user, 403);

        $results = DB::transaction(function () use ($data, $request, $user): array {
            return collect($data['items'])
                ->map(function (array $item) use ($request, $user): array {
                    $capabilities = $this->normalizeCapabilities($item);
                    abort_unless($this->access->canManageCapabilities($user, $capabilities), 403);

                    $mikroCode = $this->nullableString($item['mikro_cari_kodu']);
                    $partner = B2BPartner::query()
                        ->where('mikro_cari_kodu', $mikroCode)
                        ->first();

                    if ($partner) {
                        $oldCapabilities = $partner->capabilityCodes();
                        $mergedCapabilities = collect($oldCapabilities)->merge($capabilities)->unique()->values()->all();
                        $oldValues = $this->auditPayload($partner);
                        $partner->fill([
                            'partner_type' => $this->primaryPartnerType($mergedCapabilities),
                            'display_name' => $this->nullableString($item['display_name'] ?? null) ?? $partner->display_name,
                            'mikro_cari_unvan' => $this->nullableString($item['mikro_cari_unvan'] ?? null) ?? $partner->mikro_cari_unvan,
                            'cari_grup_kodu' => $this->nullableString($item['cari_grup_kodu'] ?? null) ?? $partner->cari_grup_kodu,
                            'responsibility_code' => $this->nullableString($item['responsibility_code'] ?? null) ?? $partner->responsibility_code,
                            'phone' => $this->nullableString($item['phone'] ?? null) ?? $partner->phone,
                            'email' => $this->nullableString($item['email'] ?? null) ?? $partner->email,
                            'city' => $this->nullableString($item['city'] ?? null) ?? $partner->city,
                            'district' => $this->nullableString($item['district'] ?? null) ?? $partner->district,
                        ])->save();
                        $this->syncCapabilities($partner, $mergedCapabilities, $request, $user->id, $oldCapabilities);
                        $this->writeAuditLog($partner, $request, 'b2b.partner.updated_from_cari', $oldValues, $this->auditPayload($partner), $user->id);

                        return ['partner_id' => $partner->id, 'status' => 'updated'];
                    }

                    $partner = B2BPartner::query()->create([
                        'partner_type' => $this->primaryPartnerType($capabilities),
                        'partner_code' => 'B2B-'.$mikroCode,
                        'display_name' => $this->nullableString($item['display_name'] ?? null) ?? $this->nullableString($item['mikro_cari_unvan'] ?? null) ?? $mikroCode,
                        'mikro_cari_kodu' => $mikroCode,
                        'mikro_cari_unvan' => $this->nullableString($item['mikro_cari_unvan'] ?? null),
                        'cari_grup_kodu' => $this->nullableString($item['cari_grup_kodu'] ?? null),
                        'responsibility_code' => $this->nullableString($item['responsibility_code'] ?? null),
                        'phone' => $this->nullableString($item['phone'] ?? null),
                        'email' => $this->nullableString($item['email'] ?? null),
                        'city' => $this->nullableString($item['city'] ?? null),
                        'district' => $this->nullableString($item['district'] ?? null),
                        'active' => true,
                    ]);
                    $this->syncCapabilities($partner, $capabilities, $request, $user->id, []);
                    $this->writeAuditLog($partner, $request, 'b2b.partner.imported_from_cari', null, $this->auditPayload($partner), $user->id);

                    return ['partner_id' => $partner->id, 'status' => 'created'];
                })
                ->values()
                ->all();
        });

        return response()->json(['items' => $results]);
    }

    public function applyCariControl(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['create_partner', 'update_partner', 'add_capability', 'mark_review', 'import'])],
            'candidates' => ['required', 'array', 'min:1'],
            'candidates.*' => ['required', 'array'],
            'selected_capabilities' => ['nullable', 'array'],
            'selected_capabilities.*' => ['required_with:selected_capabilities', 'string', Rule::in(B2BPartner::SUPPORTED_CAPABILITIES)],
            'existing_partner_id' => ['nullable', 'integer', Rule::exists((new B2BPartner)->getTable(), 'id')],
        ]);
        $user = $request->user();
        abort_unless($user, 403);

        $results = DB::transaction(function () use ($data, $request, $user): array {
            return collect($data['candidates'])
                ->map(function (array $item) use ($data, $request, $user): array {
                    $candidate = $this->cariControlService->normalizeCandidateInput($item);
                    $capabilities = $this->candidateSelectedCapabilities($item, $data);
                    abort_unless($this->access->canManageCapabilities($user, $capabilities), 403);

                    return $this->applyCariCandidate($data['action'], $candidate, $capabilities, $request, $user->id, $data['existing_partner_id'] ?? null);
                })
                ->values()
                ->all();
        });

        return response()->json(['items' => $results]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    private function applyCariCandidate(string $action, array $candidate, array $capabilities, Request $request, int $userId, mixed $explicitPartnerId): array
    {
        $mikroCode = $this->nullableString($candidate['mikro_cari_kodu'] ?? null);

        if ($mikroCode === null) {
            throw ValidationException::withMessages([
                'candidates' => 'Cari adayında mikro_cari_kodu zorunludur.',
            ]);
        }

        $partner = $this->candidatePartner($candidate, $explicitPartnerId);

        if ($action === 'create_partner') {
            $activePartner = B2BPartner::query()
                ->where('active', true)
                ->where('mikro_cari_kodu', $mikroCode)
                ->first();

            if ($activePartner) {
                throw new HttpResponseException(response()->json([
                    'status' => 'duplicate_mikro_cari',
                    'message' => 'Bu Mikro cari zaten aktif bir B2B partner kaydına bağlı. Yeni kayıt açmak yerine mevcut partnere rol ekleyin.',
                    'existing_partner_id' => $activePartner->id,
                ], 409));
            }

            return $this->createPartnerFromCari($candidate, $capabilities, $request, $userId);
        }

        if ($action === 'import' && ! $partner) {
            return $this->createPartnerFromCari($candidate, $capabilities, $request, $userId);
        }

        if (! $partner) {
            throw ValidationException::withMessages([
                'existing_partner_id' => 'Bu işlem için mevcut partner seçilmelidir.',
            ]);
        }

        if ($action === 'mark_review') {
            $oldValues = $this->auditPayload($partner);
            $metadata = is_array($partner->metadata) ? $partner->metadata : [];
            $metadata['cari_control_review'] = [
                'marked_at' => now()->toIso8601String(),
                'candidate' => $candidate,
            ];
            $partner->forceFill(['metadata' => $metadata])->save();
            $partner->refresh();
            $this->writeAuditLog($partner, $request, 'b2b.partner.cari_review_marked', $oldValues, $this->auditPayload($partner), $userId);

            return ['partner_id' => $partner->id, 'status' => 'review_marked'];
        }

        if ($action === 'add_capability') {
            $oldCapabilities = $partner->capabilityCodes();
            $mergedCapabilities = collect($oldCapabilities)->merge($capabilities)->unique()->values()->all();
            $oldValues = $this->auditPayload($partner);
            $partner->forceFill(['partner_type' => $this->primaryPartnerType($mergedCapabilities)])->save();
            $this->syncCapabilities($partner, $mergedCapabilities, $request, $userId, $oldCapabilities);
            $partner->refresh();
            $this->writeAuditLog($partner, $request, 'b2b.partner.capability_added', $oldValues, $this->auditPayload($partner), $userId);

            return ['partner_id' => $partner->id, 'status' => 'capability_added'];
        }

        $oldValues = $this->auditPayload($partner);
        $partner->fill($this->snapshotPayload($candidate, $partner));

        if ($action === 'import') {
            $oldCapabilities = $partner->capabilityCodes();
            $mergedCapabilities = collect($oldCapabilities)->merge($capabilities)->unique()->values()->all();
            $partner->partner_type = $this->primaryPartnerType($mergedCapabilities);
            $this->syncCapabilities($partner, $mergedCapabilities, $request, $userId, $oldCapabilities);
        }

        $partner->save();
        $partner->refresh();
        $this->writeAuditLog($partner, $request, 'b2b.partner.updated_from_cari', $oldValues, $this->auditPayload($partner), $userId);

        return ['partner_id' => $partner->id, 'status' => 'updated'];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    private function createPartnerFromCari(array $candidate, array $capabilities, Request $request, int $userId): array
    {
        $mikroCode = $this->nullableString($candidate['mikro_cari_kodu'] ?? null);
        $snapshotPayload = $this->snapshotPayload($candidate);
        $snapshotPayload['display_name'] = $snapshotPayload['display_name'] ?? $mikroCode;
        $partner = B2BPartner::query()->create([
            'partner_type' => $this->primaryPartnerType($capabilities),
            'partner_code' => $this->uniquePartnerCode($mikroCode),
            ...$snapshotPayload,
            'mikro_cari_kodu' => $mikroCode,
            'active' => true,
            'metadata' => [
                'cari_control' => [
                    'source' => $candidate['source_used'] ?? 'gateway_candidate',
                    'status' => $candidate['status'] ?? null,
                    'confidence' => $candidate['confidence'] ?? null,
                ],
            ],
        ]);
        $this->syncCapabilities($partner, $capabilities, $request, $userId, []);
        $this->writeAuditLog($partner, $request, 'b2b.partner.imported_from_cari', null, $this->auditPayload($partner), $userId);

        return ['partner_id' => $partner->id, 'status' => 'created'];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function snapshotPayload(array $candidate, ?B2BPartner $partner = null): array
    {
        return [
            'display_name' => $this->nullableString($candidate['display_name'] ?? null)
                ?? $this->nullableString($candidate['mikro_cari_unvan'] ?? null)
                ?? $partner?->display_name,
            'mikro_cari_unvan' => $this->nullableString($candidate['mikro_cari_unvan'] ?? null) ?? $partner?->mikro_cari_unvan,
            'cari_grup_kodu' => $this->nullableString($candidate['cari_grup_kodu'] ?? null) ?? $partner?->cari_grup_kodu,
            'responsibility_code' => $this->nullableString($candidate['responsibility_code'] ?? null) ?? $partner?->responsibility_code,
            'phone' => $this->nullableString($candidate['phone'] ?? null) ?? $partner?->phone,
            'email' => $this->nullableString($candidate['email'] ?? null) ?? $partner?->email,
            'city' => $this->nullableString($candidate['city'] ?? null) ?? $partner?->city,
            'district' => $this->nullableString($candidate['district'] ?? null) ?? $partner?->district,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidatePartner(array $candidate, mixed $explicitPartnerId): ?B2BPartner
    {
        if ($explicitPartnerId) {
            return B2BPartner::query()->find($explicitPartnerId);
        }

        if (! empty($candidate['existing_partner_id'])) {
            return B2BPartner::query()->find($candidate['existing_partner_id']);
        }

        return B2BPartner::query()
            ->where('mikro_cari_kodu', $this->nullableString($candidate['mikro_cari_kodu'] ?? null))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function candidateSelectedCapabilities(array $item, array $data): array
    {
        $capabilities = $this->cariControlService->normalizeCapabilities($item['selected_capabilities'] ?? []);

        if ($capabilities === []) {
            $capabilities = $this->cariControlService->normalizeCapabilities($data['selected_capabilities'] ?? []);
        }

        if ($capabilities === []) {
            $capabilities = $this->cariControlService->normalizeCapabilities($item['capabilities'] ?? $item['suggested_capabilities'] ?? []);
        }

        if ($capabilities === []) {
            throw ValidationException::withMessages([
                'selected_capabilities' => 'En az bir partner rolü seçilmelidir.',
            ]);
        }

        return $capabilities;
    }

    private function uniquePartnerCode(?string $mikroCode): string
    {
        $base = Str::limit('B2B-'.preg_replace('/[^A-Z0-9]+/', '-', Str::upper((string) $mikroCode)), 120, '');
        $code = $base;
        $counter = 1;

        while (B2BPartner::query()->where('partner_code', $code)->exists()) {
            $code = Str::limit($base.'-'.$counter, 128, '');
            $counter++;
        }

        return $code;
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
                    ->orWhere('cari_code', $likeOperator, '%'.$search.'%')
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
                    'mikro_cari_kodu' => $technician->mikro_cari_kodu ?? $technician->cari_code,
                    'mikro_cari_adi' => $technician->mikro_cari_adi ?? $technician->cari_title,
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

    /**
     * @return array<string, mixed>
     */
    private function validatedPartnerData(Request $request, ?B2BPartner $partner = null): array
    {
        $data = $request->validate([
            'partner_type' => ['nullable', 'string', Rule::in(B2BPartner::SUPPORTED_CAPABILITIES)],
            'capabilities' => ['nullable', 'array', 'min:1'],
            'capabilities.*' => ['required_with:capabilities', 'string', Rule::in(B2BPartner::SUPPORTED_CAPABILITIES)],
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
        $data['capabilities'] = $this->normalizeCapabilities($data);
        $data['partner_type'] = $data['partner_type'] ?? $this->primaryPartnerType($data['capabilities']);

        if (! in_array(B2BPartner::TYPE_LOCKSMITH, $data['capabilities'], true) && ! empty($data['technical_service_technician_id'])) {
            throw ValidationException::withMessages([
                'technical_service_technician_id' => 'Teknik servis ustası bağlantısı için çilingir / servis kanalı seçilmelidir.',
            ]);
        }

        if (
            in_array(B2BPartner::TYPE_LOCKSMITH, $data['capabilities'], true)
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

        if (! empty($data['technical_service_technician_id']) && $this->activeTechnicianLinked($data['technical_service_technician_id'], $partner)) {
            throw ValidationException::withMessages([
                'technical_service_technician_id' => 'Bu teknik servis ustası zaten aktif bir B2B partner kaydına bağlı.',
            ]);
        }

        if ($this->activeMikroCariLinked($data['mikro_cari_kodu'] ?? null, $partner, array_key_exists('active', $data) ? (bool) $data['active'] : true)) {
            throw ValidationException::withMessages([
                'mikro_cari_kodu' => 'Bu Mikro cari zaten partner olarak kayıtlı. Yeni kayıt oluşturmak yerine mevcut partner üzerinde rol ekleyin.',
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
            'partner_type' => $this->primaryPartnerType($data['capabilities']),
            'capabilities' => $data['capabilities'],
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
            'technical_service_technician_id' => in_array(B2BPartner::TYPE_LOCKSMITH, $data['capabilities'], true)
                ? ($data['technical_service_technician_id'] ?? null)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function normalizeCapabilities(array $data): array
    {
        $capabilities = collect($data['capabilities'] ?? [])
            ->filter(fn (mixed $capability): bool => in_array($capability, B2BPartner::SUPPORTED_CAPABILITIES, true))
            ->values();

        if ($capabilities->isEmpty() && ! empty($data['partner_type'])) {
            $capabilities->push($data['partner_type']);
        }

        $capabilities = $capabilities->unique()->values()->all();

        if (count($capabilities) === 0) {
            throw ValidationException::withMessages([
                'capabilities' => 'En az bir partner rolü seçilmelidir.',
            ]);
        }

        return $capabilities;
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function primaryPartnerType(array $capabilities): string
    {
        foreach ([B2BPartner::TYPE_DEALER, B2BPartner::TYPE_LOCKSMITH, B2BPartner::TYPE_MANUFACTURER, B2BPartner::TYPE_SELLER] as $type) {
            if (in_array($type, $capabilities, true)) {
                return $type;
            }
        }

        return B2BPartner::TYPE_DEALER;
    }

    /**
     * @param  array<int, string>  $capabilities
     * @param  array<int, string>  $oldCapabilities
     */
    private function syncCapabilities(B2BPartner $partner, array $capabilities, Request $request, int $userId, array $oldCapabilities): void
    {
        foreach ($capabilities as $capability) {
            B2BPartnerCapability::query()->updateOrCreate(
                [
                    'partner_id' => $partner->id,
                    'capability' => $capability,
                ],
                ['active' => true],
            );

            if (! in_array($capability, $oldCapabilities, true)) {
                $this->writeAuditLog(
                    $partner,
                    $request,
                    'b2b.partner.capability_added',
                    ['capabilities' => $oldCapabilities],
                    ['capability' => $capability, 'capabilities' => $capabilities],
                    $userId,
                );
            }
        }

        B2BPartnerCapability::query()
            ->where('partner_id', $partner->id)
            ->whereNotIn('capability', $capabilities)
            ->update(['active' => false]);
    }

    private function activeTechnicianLinked(int|string $technicianId, ?B2BPartner $currentPartner = null): bool
    {
        return B2BPartner::query()
            ->where('active', true)
            ->where('technical_service_technician_id', $technicianId)
            ->when($currentPartner, fn (Builder $query): Builder => $query->whereKeyNot($currentPartner->id))
            ->whereHas('activeCapabilities', function (Builder $query): void {
                $query->where('capability', B2BPartner::TYPE_LOCKSMITH);
            })
            ->exists();
    }

    private function activeMikroCariLinked(mixed $mikroCariKodu, ?B2BPartner $currentPartner, bool $willBeActive): bool
    {
        $mikroCariKodu = $this->nullableString($mikroCariKodu);

        if (! $willBeActive || $mikroCariKodu === null) {
            return false;
        }

        return B2BPartner::query()
            ->where('active', true)
            ->where('mikro_cari_kodu', $mikroCariKodu)
            ->when($currentPartner, fn (Builder $query): Builder => $query->whereKeyNot($currentPartner->id))
            ->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function existingCariSources(): array
    {
        return DB::table((new DataSource)->getTable())
            ->whereIn('code', [
                'cari_bilgi_dashboard',
                'cari_list',
                'customers_list',
                'customer_detail',
                'sales_customer_search',
                'proforma_customer_search',
            ])
            ->orderBy('code')
            ->get(['code', 'name', 'db_type', 'active'])
            ->map(fn (object $source): array => [
                'code' => (string) $source->code,
                'name' => (string) $source->name,
                'db_type' => (string) $source->db_type,
                'active' => (bool) $source->active,
                'usable_for_b2b_cari_control' => false,
                'reason' => 'Mevcut kaynak n8n/data_source zincirine bağlı; B2B cari kontrol için ayrı SELECT-only sözleşme onayı gerekir.',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function cariDiscoveryQueries(): array
    {
        return [
            [
                'key' => 'find_cari_tables',
                'title' => 'Cari benzeri tabloları bul',
                'sql' => "SELECT TABLE_SCHEMA, TABLE_NAME\nFROM INFORMATION_SCHEMA.TABLES\nWHERE TABLE_NAME LIKE '%CARI%'\nORDER BY TABLE_SCHEMA, TABLE_NAME;",
            ],
            [
                'key' => 'find_cari_columns',
                'title' => 'Cari kolonlarını bul',
                'sql' => "SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE\nFROM INFORMATION_SCHEMA.COLUMNS\nWHERE TABLE_NAME LIKE '%CARI%'\nORDER BY TABLE_SCHEMA, TABLE_NAME, ORDINAL_POSITION;",
            ],
            [
                'key' => 'sample_cari_hesaplar',
                'title' => 'CARI_HESAPLAR örnek satır',
                'sql' => "SELECT TOP 20\n    cari_kod,\n    cari_unvan1,\n    cari_unvan2,\n    cari_grup_kodu,\n    cari_temsilci_kodu,\n    cari_srm_merkezi,\n    cari_CepTel,\n    cari_EMail,\n    cari_il,\n    cari_ilce\nFROM CARI_HESAPLAR\nORDER BY cari_kod;",
            ],
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
        return [
            ...$partner->only([
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
            ]),
            'capabilities' => $partner->capabilityCodes(),
        ];
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
        $partner->loadMissing(['technician', 'capabilities']);

        return [
            'id' => $partner->id,
            'partner_type' => $partner->partner_type,
            'capabilities' => $partner->capabilityCodes(),
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
            'linked_technician_city' => $partner->technician?->city,
            'linked_technician_mikro_cari_kodu' => $partner->technician?->mikro_cari_kodu ?? $partner->technician?->cari_code,
            'users_count' => B2BPartnerUserProfile::query()->where('partner_id', $partner->id)->count(),
            'active_users_count' => B2BPartnerUserProfile::query()->where('partner_id', $partner->id)->where('active', true)->count(),
            'mikro_snapshot' => [
                'mikro_cari_kodu' => $partner->mikro_cari_kodu,
                'mikro_cari_unvan' => $partner->mikro_cari_unvan,
                'cari_grup_kodu' => $partner->cari_grup_kodu,
                'responsibility_code' => $partner->responsibility_code,
            ],
        ];
    }
}

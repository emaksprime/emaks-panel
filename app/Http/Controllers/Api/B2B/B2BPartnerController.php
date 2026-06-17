<?php

namespace App\Http\Controllers\Api\B2B;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\DataSource;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BCariControlService;
use App\Services\B2B\B2BPartnerAccessService;
use App\Services\B2B\B2BPartnerAdminProvisioningService;
use App\Services\TechnicalService\TechnicalServiceGeocodingService;
use App\Services\TechnicalService\TechnicianGeocodingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class B2BPartnerController extends Controller
{
    private const CARI_CONTROL_DRY_RUN_LIMIT = 250;
    private const CARI_CONTROL_APPLY_LIMIT = 50;

    public function __construct(
        private readonly B2BPartnerAccessService $access,
        private readonly B2BCariControlService $cariControlService,
        private readonly B2BPartnerAdminProvisioningService $adminProvisioning,
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

        $partner = DB::transaction(function () use ($writePayload, $capabilities, $data, $request, $user): B2BPartner {
            $partner = B2BPartner::query()->create($writePayload);
            $this->syncCapabilities($partner, $capabilities, $request, $user->id, []);
            $this->applyPartnerFormMetadata($partner, $request->all());

            if (! empty($data['technical_service_technician_id'])) {
                $technician = TechnicalServiceTechnician::query()->findOrFail($data['technical_service_technician_id']);
                $freshPartner = $partner->fresh('capabilities');
                $this->upsertPartnerTechnicianLink($freshPartner, $technician, $request, $user->id, $this->defaultTechnicianRelationshipType($freshPartner), true, 'manual', 'partner_form');
            }

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
            $this->applyPartnerFormMetadata($partner, $data);

            if (! empty($data['technical_service_technician_id'])) {
                $technician = TechnicalServiceTechnician::query()->findOrFail($data['technical_service_technician_id']);
                $freshPartner = $partner->fresh('capabilities');
                $this->upsertPartnerTechnicianLink($freshPartner, $technician, $request, $user->id, $this->defaultTechnicianRelationshipType($freshPartner), true, 'manual', 'partner_form');
            }

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

    public function geocodePartner(Request $request, B2BPartner $partner): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canManagePartner($user, $partner), 403);

        $data = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'override_existing_coordinates' => ['sometimes', 'boolean'],
        ]);

        $candidate = $this->partnerAsCariCandidate($partner);
        $plan = $this->candidatePartnerGeocodePlan($candidate, ['geocode_mode' => 'auto']);

        if ((bool) ($data['dry_run'] ?? false)) {
            return response()->json([
                'ok' => true,
                'dry_run' => true,
                'writes_performed' => false,
                'partner_geocode_plan' => $plan,
                'partner' => $this->partnerPayload($partner->fresh()->loadMissing(['technician', 'capabilities'])),
            ]);
        }

        $oldValues = $this->auditPayload($partner);
        $result = $this->applyPartnerGeocode($partner, $candidate, [
            'geocode_mode' => 'auto',
            'override_existing_coordinates' => (bool) ($data['override_existing_coordinates'] ?? false),
        ]);
        $partner = $partner->fresh()->loadMissing(['technician', 'capabilities']);

        $this->writeAuditLog(
            $partner,
            $request,
            'b2b.partner.geocode_updated',
            $oldValues,
            $this->auditPayload($partner),
            $user->id,
        );

        return response()->json([
            'ok' => true,
            'dry_run' => false,
            'writes_performed' => true,
            'partner_geocode' => $result,
            'partner' => $this->partnerPayload($partner),
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

    public function partnerTechnicians(Request $request, B2BPartner $partner): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canViewPartner($user, $partner), 403);

        return response()->json([
            'items' => $this->partnerTechnicianItems($partner),
            'partner' => $this->partnerPayload($partner->fresh()->loadMissing(['technician', 'capabilities'])),
        ]);
    }

    public function storePartnerTechnician(Request $request, B2BPartner $partner): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canUpdatePartner($user, $partner), 403);
        $data = $this->validatedPartnerTechnicianData($request);
        $technician = TechnicalServiceTechnician::query()->findOrFail($data['technical_service_technician_id']);
        $relationshipType = $data['relationship_type'] ?? $this->defaultTechnicianRelationshipType($partner);

        if ($this->activeTechnicianLinkForPartner($partner, $technician->id)) {
            throw ValidationException::withMessages([
                'technical_service_technician_id' => 'Bu teknik servis ustası bu partnere zaten bağlı.',
            ]);
        }

        $link = DB::transaction(function () use ($partner, $technician, $data, $relationshipType, $request, $user): B2BPartnerTechnician {
            $oldPrimary = $partner->primaryTechnicianLink()->first()?->technical_service_technician_id;
            $link = $this->upsertPartnerTechnicianLink(
                $partner,
                $technician,
                $request,
                $user->id,
                $relationshipType,
                (bool) ($data['is_primary'] ?? false),
                'manual',
                'manual_link',
            );
            $partner->refresh();
            $this->writeAuditLog($partner, $request, 'b2b.partner.technician_linked', null, [
                ...$this->partnerTechnicianAuditPayload($link),
                'partner_id' => $partner->id,
                'technician_id' => $technician->id,
                'old_primary' => $oldPrimary,
                'new_primary' => $partner->technical_service_technician_id,
                'source' => 'manual',
                'match_reason' => 'manual_link',
                'linked_by' => $user->id,
            ], $user->id);

            return $link;
        });

        return response()->json([
            'link' => $this->partnerTechnicianPayload($link->loadMissing('technician')),
            'items' => $this->partnerTechnicianItems($partner->fresh()),
            'partner' => $this->partnerPayload($partner->fresh()->loadMissing(['technician', 'capabilities'])),
        ], 201);
    }

    public function updatePartnerTechnician(Request $request, B2BPartner $partner, B2BPartnerTechnician $link): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canUpdatePartner($user, $partner), 403);
        abort_unless((int) $link->partner_id === (int) $partner->id, 404);
        $data = $request->validate([
            'relationship_type' => ['nullable', 'string', Rule::in(['owner', 'field_technician', 'contracted_technician', 'branch_technician', 'contact'])],
            'is_primary' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($partner, $link, $data, $request, $user): void {
            $oldValues = $this->partnerTechnicianAuditPayload($link);
            $oldPrimary = $partner->primaryTechnicianLink()->first()?->technical_service_technician_id;
            $relationshipChanged = array_key_exists('relationship_type', $data) && $data['relationship_type'] !== $link->relationship_type;

            if (($data['active'] ?? $link->active) === true) {
                $link->loadMissing('technician');
                if ($link->technician) {
                    $this->ensurePartnerCanLinkTechnician($partner, $link->technician);
                }
            }

            if (($data['is_primary'] ?? false) === true) {
                $this->clearOtherPrimaryTechnicians($partner, $link->id);
                $data['active'] = true;
            }

            $link->fill([
                'relationship_type' => $data['relationship_type'] ?? $link->relationship_type,
                'is_primary' => array_key_exists('is_primary', $data) ? (bool) $data['is_primary'] : $link->is_primary,
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : $link->active,
            ]);
            $link->save();
            $this->ensurePartnerHasPrimaryTechnician($partner);
            $partner->refresh();
            $link->refresh();

            $action = ((bool) ($data['is_primary'] ?? false)) === true
                ? 'b2b.partner.technician_primary_changed'
                : ($relationshipChanged ? 'b2b.partner.technician_relationship_changed' : 'b2b.partner.technician_linked');
            $this->writeAuditLog($partner, $request, $action, $oldValues, [
                ...$this->partnerTechnicianAuditPayload($link),
                'old_primary' => $oldPrimary,
                'new_primary' => $partner->technical_service_technician_id,
                'linked_by' => $user->id,
            ], $user->id);
        });

        return response()->json([
            'link' => $this->partnerTechnicianPayload($link->fresh()->loadMissing('technician')),
            'items' => $this->partnerTechnicianItems($partner->fresh()),
            'partner' => $this->partnerPayload($partner->fresh()->loadMissing(['technician', 'capabilities'])),
        ]);
    }

    public function markPartnerTechnicianReviewed(Request $request, B2BPartner $partner, B2BPartnerTechnician $link): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canUpdatePartner($user, $partner), 403);
        abort_unless((int) $link->partner_id === (int) $partner->id, 404);

        $link->loadMissing('technician');
        $technician = $link->technician;

        if (! $technician
            || $this->nullableString($technician->phone) === null
            || $this->nullableString($link->service_city ?? $technician->city) === null
            || ($this->nullableString($link->service_region_note ?? $technician->address ?? $technician->cari_address) === null)
            || ! $this->technicianHasCoordinates($technician)) {
            throw ValidationException::withMessages([
                'mark_reviewed' => 'Kontrol kapatılamaz: telefon/adres/koordinat eksik.',
            ]);
        }

        $oldValues = $this->partnerTechnicianAuditPayload($link);
        $link->forceFill([
            'needs_review' => false,
            'review_reason' => null,
            'review_reasons' => [],
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
        ])->save();
        $this->writeAuditLog($partner, $request, 'b2b.partner.technician_reviewed', $oldValues, $this->partnerTechnicianAuditPayload($link->fresh()), $user->id);

        return response()->json([
            'link' => $this->partnerTechnicianPayload($link->fresh()->loadMissing('technician')),
            'items' => $this->partnerTechnicianItems($partner->fresh()),
            'partner' => $this->partnerPayload($partner->fresh()->loadMissing(['technician', 'capabilities'])),
        ]);
    }

    public function destroyPartnerTechnician(Request $request, B2BPartner $partner, B2BPartnerTechnician $link): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canUpdatePartner($user, $partner), 403);
        abort_unless((int) $link->partner_id === (int) $partner->id, 404);

        DB::transaction(function () use ($partner, $link, $request, $user): void {
            $oldValues = $this->partnerTechnicianAuditPayload($link);
            $oldPrimary = $partner->primaryTechnicianLink()->first()?->technical_service_technician_id;
            $link->forceFill([
                'active' => false,
                'is_primary' => false,
            ])->save();
            $this->ensurePartnerHasPrimaryTechnician($partner);
            $partner->refresh();
            $this->writeAuditLog($partner, $request, 'b2b.partner.technician_unlinked', $oldValues, [
                ...$this->partnerTechnicianAuditPayload($link->fresh()),
                'old_primary' => $oldPrimary,
                'new_primary' => $partner->technical_service_technician_id,
                'linked_by' => $user->id,
            ], $user->id);
        });

        return response()->json([
            'items' => $this->partnerTechnicianItems($partner->fresh()),
            'partner' => $this->partnerPayload($partner->fresh()->loadMissing(['technician', 'capabilities'])),
        ]);
    }

    public function provisionAdminUser(Request $request, B2BPartner $partner): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canSearchPanelUsers($user), 403);

        $data = $request->validate([
            'force' => ['nullable', 'boolean'],
            'show_default_password' => ['nullable', 'boolean'],
        ]);

        $result = $this->adminProvisioning->provisionForPartner($partner, [
            'force' => (bool) ($data['force'] ?? false),
            'show_default_password' => (bool) ($data['show_default_password'] ?? true),
            'actor' => $user,
            'request' => $request,
        ]);

        return response()->json([
            ...$result,
            'partner' => $this->partnerPayload($partner->fresh()),
        ], ($result['created'] ?? false) ? 201 : 200);
    }

    public function bulkProvisionAdminUsers(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canSearchPanelUsers($user), 403);

        $data = $request->validate([
            'partner_ids' => ['nullable', 'array'],
            'partner_ids.*' => ['integer', Rule::exists((new B2BPartner)->getTable(), 'id')],
            'only_without_users' => ['nullable', 'boolean'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in(B2BPartner::SUPPORTED_CAPABILITIES)],
            'active_only' => ['nullable', 'boolean'],
            'show_default_password' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->adminProvisioning->provisionForAllActivePartners([
            'partner_ids' => $data['partner_ids'] ?? [],
            'only_without_users' => (bool) ($data['only_without_users'] ?? true),
            'capabilities' => $data['capabilities'] ?? [],
            'active_only' => (bool) ($data['active_only'] ?? true),
            'show_default_password' => (bool) ($data['show_default_password'] ?? true),
            'actor' => $user,
            'request' => $request,
        ]));
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
            'status' => ['nullable', 'string', Rule::in(['new', 'existing', 'changed', 'review_required', 'candidate'])],
            'city' => ['nullable', 'string', 'max:128'],
            'include_review_required' => ['nullable', 'boolean'],
            'refresh' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'page' => ['nullable', 'integer', 'min:1'],
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
            'dry_run' => ['nullable', 'boolean'],
            'sync_technician' => ['nullable', 'boolean'],
            'geocode_mode' => ['nullable', 'string', Rule::in(['none', 'dry_run', 'auto'])],
            'override_existing_coordinates' => ['nullable', 'boolean'],
            'update_existing' => ['nullable', 'boolean'],
            'mark_reviewed' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        abort_unless($user, 403);
        $candidateCount = count($data['candidates']);
        $isDryRun = (bool) ($data['dry_run'] ?? false);

        if ($isDryRun && $candidateCount > self::CARI_CONTROL_DRY_RUN_LIMIT) {
            throw ValidationException::withMessages([
                'candidates' => 'Tek seferde en fazla 250 aday için dry-run yapılabilir. Filtreyi daraltın veya parça parça ilerleyin.',
            ]);
        }

        if (! $isDryRun && $candidateCount > self::CARI_CONTROL_APPLY_LIMIT) {
            throw ValidationException::withMessages([
                'candidates' => 'Tek seferde en fazla 50 aday işlenebilir. Filtreyi daraltın veya parça parça ilerleyin.',
            ]);
        }

        $options = [
            'dry_run' => $isDryRun,
            'sync_technician' => (bool) ($data['sync_technician'] ?? false),
            'geocode_mode' => $data['geocode_mode'] ?? 'none',
            'override_existing_coordinates' => (bool) ($data['override_existing_coordinates'] ?? false),
            'update_existing' => (bool) ($data['update_existing'] ?? true),
            'mark_reviewed' => (bool) ($data['mark_reviewed'] ?? false),
        ];

        $work = function () use ($data, $request, $user, $options): array {
            return collect($data['candidates'])
                ->map(function (array $item) use ($data, $request, $user, $options): array {
                    $candidate = $this->cariControlService->enrichCandidateForApply($item);
                    $capabilities = $this->candidateSelectedCapabilities($item, $data);
                    abort_unless($this->access->canManageCapabilities($user, $capabilities), 403);

                    return $this->applyCariCandidate($data['action'], $candidate, $capabilities, $request, $user->id, $data['existing_partner_id'] ?? null, $options);
                })
                ->values()
                ->all();
        };

        $results = $options['dry_run'] ? $work() : DB::transaction($work);

        return response()->json([
            'ok' => true,
            'dry_run' => $options['dry_run'],
            'writes_performed' => ! $options['dry_run'],
            'geocode_mode' => $options['geocode_mode'],
            'summary' => $this->cariApplySummary($results),
            'candidates' => $options['dry_run'] ? $this->cariDryRunCandidates($results) : [],
            'items' => $results,
        ]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    private function applyCariCandidate(string $action, array $candidate, array $capabilities, Request $request, int $userId, mixed $explicitPartnerId, array $options = []): array
    {
        $mikroCode = $this->nullableString($candidate['mikro_cari_kodu'] ?? null);

        if ($mikroCode === null) {
            throw ValidationException::withMessages([
                'candidates' => 'Cari adayında mikro_cari_kodu zorunludur.',
            ]);
        }

        $partner = $this->candidatePartner($candidate, $explicitPartnerId);
        $technicianMatch = $this->candidateTechnicianSyncMatch($candidate, $partner);
        $technician = $technicianMatch['technician'];

        if ((bool) ($options['dry_run'] ?? false)) {
            return $this->cariApplyPlan($action, $candidate, $capabilities, $partner, $technician, $options, $technicianMatch);
        }

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

            $result = $this->createPartnerFromCari($candidate, $capabilities, $request, $userId);

            return $this->withCariLocksmithSyncResult($result, $candidate, $capabilities, $request, $userId, $options);
        }

        if ($action === 'import' && ! $partner) {
            $result = $this->createPartnerFromCari($candidate, $capabilities, $request, $userId);

            return $this->withCariLocksmithSyncResult($result, $candidate, $capabilities, $request, $userId, $options);
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
            $roleChanges = $this->cariRoleChanges($partner, $capabilities);
            $mergedCapabilities = collect($oldCapabilities)->merge($capabilities)->unique()->values()->all();
            $oldValues = $this->auditPayload($partner);
            $partner->forceFill(['partner_type' => $this->primaryPartnerType($mergedCapabilities)])->save();
            $this->syncCapabilities($partner, $mergedCapabilities, $request, $userId, $oldCapabilities);
            $partner->refresh();
            $this->cariControlService->markSnapshotLinkedToPartner($partner);
            $this->writeAuditLog($partner, $request, 'b2b.partner.capability_added', $oldValues, $this->auditPayload($partner), $userId);
            $defaultUser = $this->ensureDefaultDealerUser($partner, $mergedCapabilities, $candidate, $request, $userId);

            $result = array_filter([
                'partner_id' => $partner->id,
                'status' => 'capability_added',
                'role_changes' => $roleChanges,
                'default_user' => $defaultUser,
            ], fn (mixed $value): bool => $value !== null);

            return $this->withCariLocksmithSyncResult($result, $candidate, $mergedCapabilities, $request, $userId, $options);
        }

        $oldValues = $this->auditPayload($partner);
        $partner->fill($this->snapshotPayload($candidate, $partner));
        $partner->metadata = $this->mergeCariCandidateMetadata($partner, $candidate);

        if ($action === 'import') {
            $oldCapabilities = $partner->capabilityCodes();
            $roleChanges = $this->cariRoleChanges($partner, $capabilities);
            $mergedCapabilities = collect($oldCapabilities)->merge($capabilities)->unique()->values()->all();
            $partner->partner_type = $this->primaryPartnerType($mergedCapabilities);
            $this->syncCapabilities($partner, $mergedCapabilities, $request, $userId, $oldCapabilities);
        }

        $partner->save();
        $partner->refresh();
        $this->cariControlService->markSnapshotLinkedToPartner($partner);
        $this->writeAuditLog($partner, $request, 'b2b.partner.updated_from_cari', $oldValues, $this->auditPayload($partner), $userId);
        $defaultUser = $action === 'import'
            ? $this->ensureDefaultDealerUser($partner, $partner->capabilityCodes(), $candidate, $request, $userId)
            : null;

        $result = array_filter([
            'partner_id' => $partner->id,
            'status' => 'updated',
            'role_changes' => $roleChanges ?? [],
            'default_user' => $defaultUser,
        ], fn (mixed $value): bool => $value !== null);

        return $this->withCariLocksmithSyncResult($result, $candidate, $action === 'import' ? $partner->capabilityCodes() : $capabilities, $request, $userId, $options);
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
        $metadata = $this->cariCandidateMetadata($candidate);
        $partner = B2BPartner::query()->create([
            'partner_type' => $this->primaryPartnerType($capabilities),
            'partner_code' => $this->uniquePartnerCode($mikroCode),
            ...$snapshotPayload,
            'mikro_cari_kodu' => $mikroCode,
            'active' => true,
            'metadata' => $metadata,
        ]);
        $this->syncCapabilities($partner, $capabilities, $request, $userId, []);
        $this->cariControlService->markSnapshotLinkedToPartner($partner);
        $this->writeAuditLog($partner, $request, 'b2b.partner.imported_from_cari', null, $this->auditPayload($partner), $userId);
        $defaultUser = $this->ensureDefaultDealerUser($partner, $capabilities, $candidate, $request, $userId);

        return array_filter([
            'partner_id' => $partner->id,
            'status' => 'created',
            'default_user' => $defaultUser,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $baseResult
     * @param  array<string, mixed>  $candidate
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withCariLocksmithSyncResult(array $baseResult, array $candidate, array $capabilities, Request $request, int $userId, array $options): array
    {
        $partner = B2BPartner::query()->find($baseResult['partner_id'] ?? null);

        if (! $partner) {
            return [
                ...$baseResult,
                'partner_geocode' => [
                    'status' => 'partner_missing',
                ],
                'technician_sync' => [
                    'status' => 'partner_missing',
                ],
            ];
        }

        $partnerGeocode = $this->applyPartnerGeocode($partner, $candidate, $options);
        $baseResult = [
            ...$baseResult,
            'partner_geocode' => $partnerGeocode,
        ];

        $isLocksmith = in_array(B2BPartner::TYPE_LOCKSMITH, $capabilities, true);
        $syncTechnician = (bool) ($options['sync_technician'] ?? false);

        if (! $isLocksmith) {
            return [
                ...$baseResult,
                'technician_sync' => [
                    'status' => 'not_applicable',
                ],
                'technician_geocode' => [
                    'status' => 'not_applicable',
                    'message' => 'Çilingir/teknisyen seçilmedi.',
                ],
            ];
        }

        if (! $syncTechnician) {
            return [
                ...$baseResult,
                'technician_sync' => [
                    'status' => 'not_requested',
                    'message' => 'Teknisyen oluştur/eşleştir seçilmedi.',
                ],
                'technician_geocode' => [
                    'status' => 'not_applicable',
                    'reason' => 'Teknisyen oluştur/eşleştir seçilmedi',
                    'message' => 'Teknisyen oluştur/eşleştir seçilmedi.',
                ],
            ];
        }

        $technicianSync = $this->syncCariLocksmithTechnician($partner, $candidate, $request, $userId, $options, $partnerGeocode);

        return [
            ...$baseResult,
            'technician_sync' => $technicianSync,
            'technician_geocode' => $technicianSync['geocode'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function cariApplyPlan(string $action, array $candidate, array $capabilities, ?B2BPartner $partner, ?TechnicalServiceTechnician $technician, array $options, array $technicianMatch = []): array
    {
        $isLocksmith = in_array(B2BPartner::TYPE_LOCKSMITH, $capabilities, true);
        $syncTechnician = (bool) ($options['sync_technician'] ?? false);
        $linkExists = $isLocksmith && $partner && $technician
            ? B2BPartnerTechnician::query()
                ->where('partner_id', $partner->id)
                ->where('technical_service_technician_id', $technician->id)
                ->where('active', true)
                ->exists()
            : false;

        $partnerGeocodePlan = $this->candidatePartnerGeocodePlan($candidate, $options);
        $technicianGeocodePlan = $this->candidateTechnicianGeocodePlan($candidate, $technician, $options, $isLocksmith);
        $technicianAction = 'not_applicable';
        $linkAction = 'not_applicable';

        if ($isLocksmith && ! $syncTechnician) {
            $technicianAction = 'not_requested';
            $linkAction = 'not_requested';
        } elseif ($isLocksmith) {
            $technicianAction = $technician ? 'update_or_use_existing_technician' : 'create_technician';
            $linkAction = $linkExists ? 'no_link_change' : 'ensure_partner_technician_link';
        }

        return [
            'status' => 'dry_run',
            'action' => $action,
            'cari_code' => $this->nullableString($candidate['mikro_cari_kodu'] ?? null),
            'roles' => [
                B2BPartner::TYPE_DEALER => in_array(B2BPartner::TYPE_DEALER, $capabilities, true),
                B2BPartner::TYPE_LOCKSMITH => $isLocksmith,
                B2BPartner::TYPE_MANUFACTURER => in_array(B2BPartner::TYPE_MANUFACTURER, $capabilities, true),
                B2BPartner::TYPE_SELLER => in_array(B2BPartner::TYPE_SELLER, $capabilities, true),
            ],
            'address' => $this->candidateAddressValue($candidate),
            'address_source' => $this->nullableString($candidate['address_source'] ?? null),
            'city' => $this->candidateCityValue($candidate),
            'district' => $this->candidateDistrictValue($candidate),
            'plus_code' => $this->candidatePlusCodeValue($candidate),
            'partner_action' => $partner ? ($action === 'add_capability' ? 'add_capability' : 'update_partner') : 'create_partner',
            'role_changes' => $this->cariRoleChanges($partner, $capabilities),
            'partner_id' => $partner?->id,
            'technician_action' => $technicianAction,
            'technician_id' => $technician?->id,
            'ignored_technician_id' => $technicianMatch['ignored_technician']?->id ?? null,
            'ignored_technician_reason' => $technicianMatch['ignore_reason'] ?? null,
            'link_action' => $linkAction,
            'partner_geocode_plan' => $partnerGeocodePlan,
            'technician_geocode_plan' => $technicianGeocodePlan,
            'review_warnings' => $isLocksmith && $syncTechnician ? $this->reviewReasonsForCandidate($candidate, $technician) : [],
        ];
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<int, string>
     */
    private function cariRoleChanges(?B2BPartner $partner, array $capabilities): array
    {
        if (! $partner) {
            return [];
        }

        $currentCapabilities = $partner->capabilityCodes();

        return collect($capabilities)
            ->diff($currentCapabilities)
            ->map(fn (string $capability): string => $capability.'_added')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, int>
     */
    private function cariApplySummary(array $results): array
    {
        $summary = [
            'selected_count' => count($results),
            'partner_create' => 0,
            'partner_update' => 0,
            'partner_skip' => 0,
            'technician_create' => 0,
            'technician_update' => 0,
            'technician_skip' => 0,
            'link_create' => 0,
            'link_update' => 0,
            'link_skip' => 0,
            'partner_geocode_ready' => 0,
            'partner_geocode_warning' => 0,
            'partner_geocode_skipped' => 0,
            'technician_geocode_ready' => 0,
            'technician_geocode_warning' => 0,
            'technician_geocode_not_applicable' => 0,
            'technician_geocode_skipped' => 0,
            'geocode_ready' => 0,
            'geocode_warning' => 0,
            'geocode_not_applicable' => 0,
            'geocode_skipped' => 0,
            'warning_count' => 0,
            'error_count' => 0,
        ];

        foreach ($results as $result) {
            $partnerAction = (string) ($result['partner_action'] ?? '');
            $technicianAction = (string) ($result['technician_action'] ?? '');
            $linkAction = (string) ($result['link_action'] ?? '');
            $partnerGeocodeStatus = (string) data_get($result, 'partner_geocode_plan.status', '');
            $technicianGeocodeStatus = (string) data_get($result, 'technician_geocode_plan.status', '');

            if (str_contains($partnerAction, 'create')) {
                $summary['partner_create']++;
            } elseif (str_contains($partnerAction, 'update') || str_contains($partnerAction, 'add_capability')) {
                $summary['partner_update']++;
            } else {
                $summary['partner_skip']++;
            }

            if (str_contains($technicianAction, 'create')) {
                $summary['technician_create']++;
            } elseif (str_contains($technicianAction, 'update') || str_contains($technicianAction, 'existing') || str_contains($technicianAction, 'match')) {
                $summary['technician_update']++;
            } else {
                $summary['technician_skip']++;
            }

            if (str_contains($linkAction, 'ensure')) {
                $summary['link_create']++;
            } elseif (str_contains($linkAction, 'update')) {
                $summary['link_update']++;
            } else {
                $summary['link_skip']++;
            }

            match ($partnerGeocodeStatus) {
                'ready' => $summary['partner_geocode_ready']++,
                'warning' => $summary['partner_geocode_warning']++,
                'skipped', 'skipped_existing_coordinates' => $summary['partner_geocode_skipped']++,
                default => null,
            };

            match ($technicianGeocodeStatus) {
                'ready' => $summary['technician_geocode_ready']++,
                'warning' => $summary['technician_geocode_warning']++,
                'not_applicable' => $summary['technician_geocode_not_applicable']++,
                'skipped', 'skipped_existing_coordinates' => $summary['technician_geocode_skipped']++,
                default => null,
            };

            $summary['geocode_ready'] = $summary['partner_geocode_ready'] + $summary['technician_geocode_ready'];
            $summary['geocode_warning'] = $summary['partner_geocode_warning'] + $summary['technician_geocode_warning'];
            $summary['geocode_not_applicable'] = $summary['technician_geocode_not_applicable'];
            $summary['geocode_skipped'] = $summary['partner_geocode_skipped'] + $summary['technician_geocode_skipped'];

            $summary['warning_count'] += count((array) ($result['review_warnings'] ?? []));
            if (($result['status'] ?? null) === 'error') {
                $summary['error_count']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function cariDryRunCandidates(array $results): array
    {
        return collect($results)
            ->map(fn (array $result): array => [
                'cari_code' => $result['cari_code'] ?? null,
                'roles' => $result['roles'] ?? [],
                'address' => $result['address'] ?? null,
                'address_source' => $result['address_source'] ?? null,
                'city' => $result['city'] ?? null,
                'district' => $result['district'] ?? null,
                'plus_code' => $result['plus_code'] ?? null,
                'partner_action' => $result['partner_action'] ?? null,
                'role_changes' => $result['role_changes'] ?? [],
                'technician_action' => $result['technician_action'] ?? null,
                'link_action' => $result['link_action'] ?? null,
                'partner_geocode_plan' => $result['partner_geocode_plan'] ?? null,
                'technician_geocode_plan' => $result['technician_geocode_plan'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function syncCariLocksmithTechnician(B2BPartner $partner, array $candidate, Request $request, int $userId, array $options, ?array $reusableGeocode = null): array
    {
        $technicianMatch = $this->candidateTechnicianSyncMatch($candidate, $partner);
        $technician = $technicianMatch['technician'];
        $matchedExistingPartnerLink = ($technicianMatch['match_type'] ?? null) === 'existing_partner_link';
        $ignoredTechnician = $technicianMatch['ignored_technician'] ?? null;
        $ignoredTechnicianReason = $technicianMatch['ignore_reason'] ?? null;

        $created = false;
        $updated = false;

        if ($matchedExistingPartnerLink && $technician) {
            $link = $this->activeTechnicianLinkForPartner($partner, $technician->id);

            if (! $link) {
                $link = $this->upsertPartnerTechnicianLink(
                    $partner->fresh('capabilities'),
                    $technician,
                    $request,
                    $userId,
                    'field_technician',
                    false,
                    'cari_control',
                    'cari_control_existing_partner_technician',
                    [
                        'service_city' => $technician->city,
                        'service_district' => $technician->district,
                        'service_region_note' => $technician->address,
                    ],
                );
            }

            $geocode = [
                'status' => 'skipped_existing_partner_link',
                'message' => 'Mevcut bağlı teknisyen korundu.',
            ];

            $this->writeAuditLog($partner->fresh(), $request, 'b2b.partner.cari_locksmith_synced', null, [
                'partner_id' => $partner->id,
                'technician_id' => $technician->id,
                'link_id' => $link->id,
                'created_technician' => false,
                'updated_technician' => false,
                'matched_existing_partner_link' => true,
                'ignored_technician_id' => $ignoredTechnician?->id,
                'ignored_technician_reason' => $ignoredTechnicianReason,
                'geocode' => $geocode,
                'review_reasons' => $this->reviewReasonsForTechnician($technician),
            ], $userId);

            return [
                'status' => 'technician_matched_existing_link',
                'technician_id' => $technician->id,
                'link_id' => $link->id,
                'partner_id' => $partner->id,
                'created' => false,
                'updated' => false,
                'geocode' => $geocode,
                'needs_review' => (bool) $technician->needs_review,
                'review_reasons' => $this->reviewReasonsForTechnician($technician),
            ];
        }

        $existingHadCoordinates = $technician ? $this->technicianHasCoordinates($technician) : false;
        $payload = $this->technicianPayloadFromCariCandidate($candidate, $technician);

        if ($technician) {
            if ((bool) ($options['update_existing'] ?? true)) {
                $technician->fill($payload);
                $updated = $technician->isDirty();
                $technician->save();
            }
        } else {
            $technician = TechnicalServiceTechnician::query()->create($payload);
            $created = true;
        }

        $geocode = $this->applyCandidateGeocode($technician, (string) ($options['geocode_mode'] ?? 'none'), (bool) ($options['override_existing_coordinates'] ?? false), $existingHadCoordinates, $reusableGeocode);
        $reviewReasons = $this->reviewReasonsForTechnician($technician->fresh(), $geocode);
        $reviewCleared = false;

        $reviewPayload = [
            'needs_review' => $reviewReasons !== [],
            'review_status' => $reviewReasons === [] ? 'ready' : 'review_required',
            'review_reason' => $reviewReasons === [] ? null : implode(' ', $reviewReasons),
            'review_reasons' => $reviewReasons,
        ];

        if ((bool) ($options['mark_reviewed'] ?? false) && $reviewReasons === []) {
            $reviewPayload['needs_review'] = false;
            $reviewPayload['review_status'] = 'reviewed';
            $reviewPayload['reviewed_at'] = now();
            $reviewPayload['reviewed_by'] = $userId;
            $reviewCleared = true;
        }

        $technician->forceFill($reviewPayload)->save();
        $technician->refresh();

        $link = $this->upsertPartnerTechnicianLink(
            $partner->fresh('capabilities'),
            $technician,
            $request,
            $userId,
            'field_technician',
            false,
            'cari_control',
            $created ? 'cari_control_created_technician' : 'cari_control_matched_technician',
            [
                'service_city' => $this->nullableString($candidate['city'] ?? null) ?? $technician->city,
                'service_district' => $this->nullableString($candidate['district'] ?? null) ?? $technician->district,
                'service_region_note' => $this->nullableString($candidate['address'] ?? null),
                'needs_review' => $reviewReasons !== [],
                'review_reason' => $reviewReasons === [] ? null : implode(' ', $reviewReasons),
                'review_reasons' => $reviewReasons,
                'reviewed_at' => $reviewCleared ? now() : null,
                'reviewed_by' => $reviewCleared ? $userId : null,
                'candidate' => $candidate,
            ],
        );

        $this->writeAuditLog($partner->fresh(), $request, 'b2b.partner.cari_locksmith_synced', null, [
            'partner_id' => $partner->id,
            'technician_id' => $technician->id,
            'link_id' => $link->id,
            'created_technician' => $created,
            'updated_technician' => $updated,
            'ignored_technician_id' => $ignoredTechnician?->id,
            'ignored_technician_reason' => $ignoredTechnicianReason,
            'geocode' => collect($geocode)->except(['payload'])->all(),
            'review_reasons' => $reviewReasons,
        ], $userId);

        return [
            'status' => $created ? 'technician_created' : ($updated ? 'technician_updated' : 'technician_matched'),
            'technician_id' => $technician->id,
            'link_id' => $link->id,
            'partner_id' => $partner->id,
            'created' => $created,
            'updated' => $updated,
            'ignored_technician_id' => $ignoredTechnician?->id,
            'ignored_technician_reason' => $ignoredTechnicianReason,
            'geocode' => $geocode,
            'needs_review' => (bool) $technician->needs_review,
            'review_reasons' => $reviewReasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{technician: TechnicalServiceTechnician|null, match_type: string|null, ignored_technician: TechnicalServiceTechnician|null, ignore_reason: string|null}
     */
    private function candidateTechnicianSyncMatch(array $candidate, ?B2BPartner $partner): array
    {
        $technician = $this->candidateTechnician($candidate);

        if ($technician) {
            if ($this->technicianIdentityDiffersFromCandidate($technician, $candidate)) {
                return [
                    'technician' => null,
                    'match_type' => null,
                    'ignored_technician' => $technician,
                    'ignore_reason' => 'different_person_same_cari_or_phone',
                ];
            }

            return [
                'technician' => $technician,
                'match_type' => 'cari_or_phone',
                'ignored_technician' => null,
                'ignore_reason' => null,
            ];
        }

        if ($partner) {
            $linkedTechnician = $this->candidatePartnerLinkedTechnician($partner);

            if ($linkedTechnician) {
                if ($this->technicianIdentityDiffersFromCandidate($linkedTechnician, $candidate)) {
                    return [
                        'technician' => null,
                        'match_type' => null,
                        'ignored_technician' => $linkedTechnician,
                        'ignore_reason' => 'different_linked_person',
                    ];
                }

                return [
                    'technician' => $linkedTechnician,
                    'match_type' => 'existing_partner_link',
                    'ignored_technician' => null,
                    'ignore_reason' => null,
                ];
            }
        }

        return [
            'technician' => null,
            'match_type' => null,
            'ignored_technician' => null,
            'ignore_reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateTechnician(array $candidate): ?TechnicalServiceTechnician
    {
        $code = $this->nullableString($candidate['mikro_cari_kodu'] ?? null);

        if ($code !== null) {
            $technician = TechnicalServiceTechnician::query()
                ->where(function (Builder $query) use ($code): void {
                    $query->where('mikro_cari_kodu', $code)
                        ->orWhere('cari_code', $code);
                })
                ->first();

            if ($technician) {
                return $technician;
            }
        }

        $phone = $this->normalizedPhone($candidate['phone'] ?? null);

        if ($phone === '') {
            return null;
        }

        $suffix = substr($phone, -10) ?: $phone;

        return TechnicalServiceTechnician::query()
            ->where(function (Builder $query) use ($suffix): void {
                $query->where('phone', 'like', '%'.$suffix.'%')
                    ->orWhere('phone_e164', 'like', '%'.$suffix.'%')
                    ->orWhere('phone_display', 'like', '%'.$suffix.'%');
            })
            ->get()
            ->first(fn (TechnicalServiceTechnician $technician): bool => in_array($phone, [
                $this->normalizedPhone($technician->phone),
                $this->normalizedPhone($technician->phone_e164),
                $this->normalizedPhone($technician->phone_display),
            ], true));
    }

    private function candidatePartnerLinkedTechnician(B2BPartner $partner): ?TechnicalServiceTechnician
    {
        $link = $partner->activePartnerTechnicians()
            ->with('technician')
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->orderBy('id')
            ->first();

        return $link?->technician;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function technicianIdentityDiffersFromCandidate(TechnicalServiceTechnician $technician, array $candidate): bool
    {
        $candidateName = $this->candidateTechnicianIdentityName($candidate);
        $technicianName = $this->technicianIdentityName($technician);

        return $candidateName !== null
            && $technicianName !== null
            && $this->normalizedText($candidateName) !== $this->normalizedText($technicianName);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateTechnicianIdentityName(array $candidate): ?string
    {
        return $this->nullableString($candidate['contact_or_service_name'] ?? null)
            ?? $this->nullableString($candidate['display_name'] ?? null)
            ?? $this->nullableString($candidate['mikro_cari_unvan'] ?? null);
    }

    private function technicianIdentityName(TechnicalServiceTechnician $technician): ?string
    {
        return $this->nullableString($technician->name)
            ?? $this->nullableString(trim(implode(' ', array_filter([$technician->first_name, $technician->last_name]))))
            ?? $this->nullableString($technician->display_name);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function technicianPayloadFromCariCandidate(array $candidate, ?TechnicalServiceTechnician $technician = null): array
    {
        $displayName = $this->nullableString($candidate['display_name'] ?? null)
            ?? $this->nullableString($candidate['mikro_cari_unvan'] ?? null)
            ?? $this->nullableString($candidate['mikro_cari_kodu'] ?? null)
            ?? 'Çilingir';
        $phone = $this->nullableString($candidate['phone'] ?? null);
        $city = $this->nullableString($candidate['city'] ?? null);
        $district = $this->nullableString($candidate['district'] ?? null);
        $address = $this->nullableString($candidate['address'] ?? null);
        $code = $this->nullableString($candidate['mikro_cari_kodu'] ?? null);
        $title = $this->nullableString($candidate['mikro_cari_unvan'] ?? null) ?? $displayName;
        $sourceKey = $code !== null
            ? 'cari_control:'.$code
            : ('cari_control:'.($phone ?? Str::uuid()->toString()));

        return [
            'name' => $technician?->name ?: $displayName,
            'first_name' => $technician?->first_name ?: $displayName,
            'last_name' => $technician?->last_name,
            'display_name' => $displayName,
            'technician_type' => 'locksmith',
            'phone' => $phone ?? $technician?->phone,
            'phone_e164' => $phone ?? $technician?->phone_e164,
            'phone_display' => $phone ?? $technician?->phone_display,
            'city' => $city ?? $technician?->city,
            'district' => $district ?? $technician?->district,
            'address' => $address ?? $technician?->address,
            'default_start_address' => $address ?? $technician?->default_start_address,
            'mikro_cari_kodu' => $code ?? $technician?->mikro_cari_kodu,
            'mikro_cari_adi' => $title ?? $technician?->mikro_cari_adi,
            'cari_code' => $code ?? $technician?->cari_code,
            'cari_title' => $title ?? $technician?->cari_title,
            'cari_address' => $address ?? $technician?->cari_address,
            'cari_city_district_country' => implode(' / ', array_values(array_filter([$district, $city, 'Türkiye']))) ?: $technician?->cari_city_district_country,
            'active' => true,
            'import_status' => 'Cari Control',
            'import_source' => 'b2b_cari_control',
            'imported_at' => $technician?->imported_at ?? now(),
            'source_key' => $technician?->source_key ?? $sourceKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function candidatePartnerGeocodePlan(array $candidate, array $options): array
    {
        return $this->candidateLocationGeocodePlan(
            candidate: $candidate,
            options: $options,
            applicable: true,
            notApplicableReason: null,
            notApplicableMessage: null,
            existingCoordinates: false,
        );
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function candidateTechnicianGeocodePlan(array $candidate, ?TechnicalServiceTechnician $technician, array $options, bool $isLocksmith): array
    {
        return $this->candidateLocationGeocodePlan(
            candidate: $candidate,
            options: $options,
            applicable: $isLocksmith && (bool) ($options['sync_technician'] ?? false),
            notApplicableReason: 'Teknisyen oluşmayacağı için geocode uygulanmaz',
            notApplicableMessage: 'Teknisyen oluşmayacağı için geocode uygulanmaz.',
            existingCoordinates: $technician && $this->technicianHasCoordinates($technician) && ! (bool) ($options['override_existing_coordinates'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function candidateLocationGeocodePlan(array $candidate, array $options, bool $applicable, ?string $notApplicableReason, ?string $notApplicableMessage, bool $existingCoordinates): array
    {
        $mode = (string) ($options['geocode_mode'] ?? 'none');

        if ($mode === 'none') {
            return [
                'mode' => $mode,
                'status' => 'skipped',
                'reason' => 'Geocode modu kapalı',
                'message' => 'Geocode yapılmayacak.',
                'query' => null,
                'will_call_provider_on_apply' => false,
            ];
        }

        if (! $applicable) {
            return [
                'mode' => $mode,
                'status' => 'not_applicable',
                'reason' => $notApplicableReason,
                'message' => $notApplicableMessage,
                'query' => null,
                'will_call_provider_on_apply' => false,
            ];
        }

        if ($existingCoordinates) {
            return [
                'mode' => $mode,
                'status' => 'skipped',
                'source' => 'existing_coordinates',
                'reason' => 'Mevcut koordinat korunacak',
                'message' => 'Mevcut koordinat korunacak.',
                'query' => null,
                'will_call_provider_on_apply' => false,
            ];
        }

        $plusCode = $this->candidatePlusCodeValue($candidate);
        $address = $this->candidateAddressValue($candidate);
        $city = $this->candidateCityValue($candidate);
        $district = $this->candidateDistrictValue($candidate);

        if ($plusCode !== null) {
            return [
                'mode' => $mode,
                'status' => 'ready',
                'source' => 'plus_code',
                'reason' => 'Plus Code ile koordinat çözülebilir',
                'message' => 'Plus Code ile koordinat çözülebilir.',
                'query' => $plusCode,
                'will_call_provider_on_apply' => $mode === 'auto' || $mode === 'dry_run',
            ];
        }

        if ($address !== null) {
            return [
                'mode' => $mode,
                'status' => 'ready',
                'source' => $this->nullableString($candidate['address_source'] ?? null) ?? 'mikro_address',
                'reason' => 'Adres ile koordinat çözülebilir',
                'message' => 'Adres ile koordinat çözülebilir.',
                'query' => implode(', ', array_values(array_filter([$address, $district, $city, 'Türkiye']))),
                'will_call_provider_on_apply' => $mode === 'auto' || $mode === 'dry_run',
            ];
        }

        if ($city !== null || $district !== null) {
            return [
                'mode' => $mode,
                'status' => 'warning',
                'source' => 'city_only',
                'reason' => 'Adres yetersiz; rota için gerçek koordinat yok',
                'message' => 'Adres yetersiz; rota için gerçek koordinat yok.',
                'query' => implode(', ', array_values(array_filter([$district, $city, 'Türkiye']))),
                'will_call_provider_on_apply' => false,
            ];
        }

        return [
            'mode' => $mode,
            'status' => 'warning',
            'source' => 'missing_location',
            'reason' => 'Adres/konum eksik',
            'message' => 'Adres/konum eksik.',
            'query' => null,
            'will_call_provider_on_apply' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidatePlusCodeValue(array $candidate): ?string
    {
        return $this->candidateStringValue($candidate, ['plus_code', 'google_plus_code', 'location_code']);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateAddressValue(array $candidate): ?string
    {
        return $this->candidateStringValue($candidate, ['full_address', 'formatted_address', 'address', 'address_line', 'google_formatted_address', 'default_start_address']);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateCityValue(array $candidate): ?string
    {
        return $this->candidateStringValue($candidate, ['city', 'province', 'il']);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateDistrictValue(array $candidate): ?string
    {
        return $this->candidateStringValue($candidate, ['district', 'ilce']);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, string>  $keys
     */
    private function candidateStringValue(array $candidate, array $keys): ?string
    {
        $rawSource = is_array($candidate['raw_source'] ?? null) ? $candidate['raw_source'] : [];

        foreach ($keys as $key) {
            $value = $this->nullableString($candidate[$key] ?? null) ?? $this->nullableString($rawSource[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function applyCandidateGeocode(TechnicalServiceTechnician $technician, string $mode, bool $overrideExisting, bool $existingHadCoordinates, ?array $reusableGeocode = null): array
    {
        if ($mode !== 'auto') {
            $technician->forceFill([
                'geocode_status' => $mode === 'dry_run' ? 'dry_run' : 'skipped',
                'geocode_source' => null,
                'geocode_confidence' => null,
            ])->save();

            return ['status' => $mode === 'dry_run' ? 'dry_run' : 'skipped', 'message' => 'Geocode uygulanmadı.'];
        }

        if ($existingHadCoordinates && ! $overrideExisting) {
            $technician->forceFill([
                'geocode_status' => 'skipped_existing_coordinates',
                'geocode_source' => $technician->location_source,
            ])->save();

            return ['status' => 'skipped_existing_coordinates', 'message' => 'Mevcut koordinat korundu.'];
        }

        $result = $this->usableReusableGeocode($reusableGeocode)
            ? $reusableGeocode
            : app(TechnicianGeocodingService::class)->geocode($technician);
        $payload = $this->geocodePersistencePayload($result);

        if (($result['ok'] ?? false) === true) {
            $technician->forceFill([
                'latitude' => $result['latitude'],
                'longitude' => $result['longitude'],
                'start_latitude' => $result['latitude'],
                'start_longitude' => $result['longitude'],
                'google_formatted_address' => $this->nullableString($result['formatted_address'] ?? null) ?? $technician->google_formatted_address,
                'location_source' => $result['provider'] ?? 'google_geocode',
                'route_note' => $this->geocodeNoteFromResult($result),
                ...$payload,
            ])->save();
        } else {
            $technician->forceFill([
                'needs_review' => true,
                'route_note' => (string) ($result['error_message'] ?? 'Geocoding başarısız.'),
                ...$payload,
            ])->save();
        }

        return collect($result)
            ->except(['geocode_payload'])
            ->merge(['status' => $payload['geocode_status']])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function applyPartnerGeocode(B2BPartner $partner, array $candidate, array $options): array
    {
        $mode = (string) ($options['geocode_mode'] ?? 'none');
        $overrideExisting = (bool) ($options['override_existing_coordinates'] ?? false);

        if ($mode !== 'auto') {
            return ['status' => $mode === 'dry_run' ? 'dry_run' : 'skipped', 'message' => 'Partner geocode uygulanmadı.'];
        }

        if (! $overrideExisting && $this->partnerHasCoordinates($partner)) {
            return [
                'ok' => true,
                'status' => 'skipped_existing_coordinates',
                'message' => 'Mevcut partner koordinatı korundu.',
                'latitude' => $partner->latitude,
                'longitude' => $partner->longitude,
                'formatted_address' => $partner->google_formatted_address,
                'needs_review' => (bool) $partner->needs_review,
                'review_reason' => $partner->review_reason,
            ];
        }

        $plan = $this->candidatePartnerGeocodePlan($candidate, ['geocode_mode' => 'auto']);

        if (($plan['status'] ?? null) !== 'ready') {
            $result = [
                'ok' => false,
                'status' => 'review_required',
                'quality' => 'failed',
                'query' => $plan['query'] ?? null,
                'source_type' => $plan['source'] ?? null,
                'needs_review' => true,
                'review_reason' => $plan['reason'] ?? 'Partner adresi geocode için yetersiz.',
                'latitude' => null,
                'longitude' => null,
                'formatted_address' => null,
                'error_message' => $plan['message'] ?? null,
            ];

            $this->persistPartnerGeocode($partner, $result);

            return $result;
        }

        $result = app(TechnicalServiceGeocodingService::class)->geocodeText(
            $plan['query'] ?? null,
            $plan['source'] ?? 'partner_address',
            [
                'city' => $this->candidateCityValue($candidate),
                'district' => $this->candidateDistrictValue($candidate),
            ],
        );

        $this->persistPartnerGeocode($partner, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persistPartnerGeocode(B2BPartner $partner, array $result): void
    {
        $metadata = is_array($partner->metadata) ? $partner->metadata : [];
        $payload = $this->geocodePersistencePayload($result);
        $ok = (bool) ($result['ok'] ?? false);
        $needsReview = (bool) ($result['needs_review'] ?? ! $ok);
        $reviewReason = $this->nullableString($result['review_reason'] ?? $result['error_message'] ?? null);
        $formattedAddress = $this->nullableString($result['formatted_address'] ?? null);
        $metadata['geocode'] = [
            'status' => $payload['geocode_status'],
            'source' => $payload['geocode_source'],
            'confidence' => $payload['geocode_confidence'],
            'geocoded_at' => optional($payload['geocoded_at'])->toIso8601String(),
            'latitude' => $result['latitude'] ?? null,
            'longitude' => $result['longitude'] ?? null,
            'formatted_address' => $formattedAddress,
            'needs_review' => $needsReview,
            'review_reason' => $reviewReason,
            'payload' => $payload['geocode_payload'],
        ];

        $writePayload = [
            'metadata' => $metadata,
            'geocode_status' => $payload['geocode_status'],
            'geocode_source' => $payload['geocode_source'],
            'geocode_confidence' => $payload['geocode_confidence'],
            'geocoded_at' => $payload['geocoded_at'],
            'geocode_payload' => $payload['geocode_payload'],
            'needs_review' => $needsReview,
            'review_reason' => $reviewReason,
            'review_reasons' => $reviewReason !== null ? [$reviewReason] : [],
        ];

        if ($ok && $this->nullableString($result['latitude'] ?? null) !== null && $this->nullableString($result['longitude'] ?? null) !== null) {
            $writePayload['latitude'] = $result['latitude'];
            $writePayload['longitude'] = $result['longitude'];
        }

        if ($formattedAddress !== null) {
            $writePayload['google_formatted_address'] = $formattedAddress;
        }

        if ($payload['geocode_source'] !== null) {
            $writePayload['location_source'] = $payload['geocode_source'];
        }

        $partner->forceFill($writePayload)->save();
        $partner->refresh();
    }

    private function partnerHasCoordinates(B2BPartner $partner): bool
    {
        return app(TechnicalServiceGeocodingService::class)->validCoordinatePair($partner->latitude, $partner->longitude) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerAsCariCandidate(B2BPartner $partner): array
    {
        return [
            'mikro_cari_kodu' => $partner->mikro_cari_kodu ?? $partner->partner_code,
            'display_name' => $partner->display_name,
            'mikro_cari_unvan' => $partner->mikro_cari_unvan,
            'phone' => $partner->phone,
            'email' => $partner->email,
            'city' => $partner->city,
            'district' => $partner->district,
            'address' => $partner->address,
            'google_plus_code' => $partner->google_plus_code,
            'plus_code' => $partner->google_plus_code,
            'tax_no' => $partner->tax_number,
            'tax_number' => $partner->tax_number,
            'tax_office' => $partner->tax_office,
            'selected_capabilities' => $partner->capabilityCodes(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $geocode
     */
    private function usableReusableGeocode(?array $geocode): bool
    {
        return is_array($geocode)
            && array_key_exists('ok', $geocode)
            && array_key_exists('query', $geocode);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function geocodePersistencePayload(array $result): array
    {
        $ok = (bool) ($result['ok'] ?? false);
        $source = $this->nullableString($result['source_type'] ?? null) ?? $this->nullableString($result['provider'] ?? null);

        return [
            'geocode_status' => $ok ? ((bool) ($result['needs_review'] ?? false) ? 'review_required' : 'ok') : ((string) ($result['status'] ?? 'failed')),
            'geocode_source' => $source,
            'geocode_confidence' => $this->geocodeConfidence($result),
            'geocoded_at' => now(),
            'geocode_payload' => collect($result)
                ->only(['ok', 'status', 'provider', 'query', 'source_type', 'quality', 'needs_review', 'review_reason', 'location_type', 'latitude', 'longitude', 'formatted_address', 'error_message'])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function geocodeConfidence(array $result): int
    {
        if (! (bool) ($result['ok'] ?? false)) {
            return 0;
        }

        return match ((string) ($result['quality'] ?? '')) {
            'exact_plus_code' => 95,
            'formatted_address' => 88,
            'address_fallback' => (bool) ($result['needs_review'] ?? false) ? 60 : 78,
            default => (bool) ($result['needs_review'] ?? false) ? 50 : 70,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function geocodeNoteFromResult(array $result): string
    {
        $source = trim((string) ($result['source_type'] ?? 'unknown'));
        $formatted = trim((string) ($result['formatted_address'] ?? ''));
        $note = "Geocoded from {$source}";

        if ($formatted !== '') {
            $note .= "; formatted: {$formatted}";
        }

        if ((bool) ($result['needs_review'] ?? false) && $this->nullableString($result['review_reason'] ?? null) !== null) {
            $note .= '; '.$result['review_reason'];
        }

        return $note.'; at '.now()->toDateTimeString();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<int, string>
     */
    private function reviewReasonsForCandidate(array $candidate, ?TechnicalServiceTechnician $technician = null): array
    {
        $phone = $this->nullableString($candidate['phone'] ?? null) ?? $technician?->phone;
        $address = $this->nullableString($candidate['address'] ?? null) ?? $technician?->address ?? $technician?->cari_address;
        $city = $this->nullableString($candidate['city'] ?? null) ?? $technician?->city;
        $hasCoordinates = $technician ? $this->technicianHasCoordinates($technician) : false;
        $reasons = [];

        if ($phone === null) {
            $reasons[] = 'Telefon eksik.';
        }

        if ($address === null || $city === null) {
            $reasons[] = 'Adres/şehir eksik.';
        }

        if (! $hasCoordinates) {
            $reasons[] = 'Koordinat eksik.';
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $geocode
     * @return array<int, string>
     */
    private function reviewReasonsForTechnician(TechnicalServiceTechnician $technician, array $geocode = []): array
    {
        $reasons = [];

        if ($this->nullableString($technician->phone) === null && $this->nullableString($technician->phone_e164) === null) {
            $reasons[] = 'Telefon eksik.';
        }

        if (($this->nullableString($technician->address) === null
            && $this->nullableString($technician->cari_address) === null
            && $this->nullableString($technician->google_formatted_address) === null
            && $this->nullableString($technician->default_start_address) === null)
            || $this->nullableString($technician->city) === null) {
            $reasons[] = 'Adres/şehir eksik.';
        }

        if (! $this->technicianHasCoordinates($technician)) {
            $reasons[] = 'Koordinat eksik.';
        }

        if ((bool) ($geocode['needs_review'] ?? false) && $this->nullableString($geocode['review_reason'] ?? $geocode['error_message'] ?? null) !== null) {
            $reasons[] = (string) ($geocode['review_reason'] ?? $geocode['error_message']);
        }

        return array_values(array_unique($reasons));
    }

    private function technicianHasCoordinates(TechnicalServiceTechnician $technician): bool
    {
        return app(TechnicianGeocodingService::class)->hasValidCoordinates($technician);
    }

    private function normalizedPhone(mixed $phone): string
    {
        return preg_replace('/\D+/', '', (string) ($phone ?? '')) ?? '';
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function snapshotPayload(array $candidate, ?B2BPartner $partner = null): array
    {
        $taxNumber = $this->candidateTaxNumber($candidate);
        $taxOffice = $this->candidateTaxOffice($candidate);

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
            'address' => $this->nullableString($candidate['address'] ?? null) ?? $partner?->address,
            'tax_number' => $partner?->tax_number ?? $taxNumber,
            'tax_office' => $partner?->tax_office ?? $taxOffice,
            'tax_identity_type' => $partner?->tax_identity_type ?? $this->taxIdentityType($taxNumber),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateTaxNumber(array $candidate): ?string
    {
        foreach ([
            'tax_number',
            'tax_no',
            'vergi_no',
            'vkn',
            'tckn',
            'cari_vdaire_no',
            'cari_VergiKimlikNo',
        ] as $key) {
            $value = $this->nullableString($candidate[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateTaxOffice(array $candidate): ?string
    {
        foreach ([
            'tax_office',
            'vergi_dairesi',
            'cari_vdaire_adi',
        ] as $key) {
            $value = $this->nullableString($candidate[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function taxIdentityType(?string $taxNumber): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $taxNumber);

        return match (strlen($digits ?? '')) {
            10 => 'vkn',
            11 => 'tckn',
            0 => null,
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function cariCandidateMetadata(array $candidate): array
    {
        $children = $this->childCariAccountsMetadata($candidate);

        return array_filter([
            'cari_control' => [
                'source' => $candidate['source_used'] ?? 'gateway_candidate',
                'detail_source' => $candidate['detail_source_used'] ?? null,
                'status' => $candidate['status'] ?? null,
                'confidence' => $candidate['confidence'] ?? null,
            ],
            'address' => $this->nullableString($candidate['address'] ?? null),
            'tax_no' => $this->candidateTaxNumber($candidate),
            'tax_office' => $this->candidateTaxOffice($candidate),
            'raw_source_summary' => $this->rawSourceSummary($candidate),
            'source_field_missing' => $candidate['source_field_missing'] ?? null,
            'child_cari_accounts' => $children,
            'invoice_profile' => $this->invoiceProfileMetadata($candidate),
            'shipping_profile' => $this->shippingProfileMetadata($candidate, $children),
            'invoice_usage_note' => $children === [] ? null : 'Konsinye/teshir/proje siparislerinde fatura cari kodu ilgili alt cari hesabindan secilecektir. Bu fazda siparis/fatura logic degistirilmedi.',
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function rawSourceSummary(array $candidate): array
    {
        return [
            'mikro_cari_kodu' => $this->nullableString($candidate['mikro_cari_kodu'] ?? null),
            'source_used' => $candidate['source_used'] ?? null,
            'detail_source_used' => $candidate['detail_source_used'] ?? null,
            'phone_present' => $this->nullableString($candidate['phone'] ?? null) !== null,
            'email_present' => $this->nullableString($candidate['email'] ?? null) !== null,
            'city_present' => $this->nullableString($candidate['city'] ?? null) !== null,
            'district_present' => $this->nullableString($candidate['district'] ?? null) !== null,
            'address_present' => $this->nullableString($candidate['address'] ?? null) !== null,
            'tax_no_present' => $this->candidateTaxNumber($candidate) !== null,
            'tax_office_present' => $this->candidateTaxOffice($candidate) !== null,
            'source_field_missing' => $candidate['source_field_missing'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function invoiceProfileMetadata(array $candidate): array
    {
        return array_filter([
            'cari_kodu' => $this->nullableString($candidate['mikro_cari_kodu'] ?? null),
            'cari_unvan' => $this->nullableString($candidate['mikro_cari_unvan'] ?? null)
                ?? $this->nullableString($candidate['display_name'] ?? null),
            'tax_no' => $this->candidateTaxNumber($candidate),
            'tax_office' => $this->candidateTaxOffice($candidate),
            'invoice_address' => $this->nullableString($candidate['address'] ?? null),
            'city' => $this->nullableString($candidate['city'] ?? null),
            'district' => $this->nullableString($candidate['district'] ?? null),
            'email' => $this->nullableString($candidate['email'] ?? null),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function shippingProfileMetadata(array $candidate, array $children): array
    {
        $childMap = collect($children)
            ->mapWithKeys(fn (array $child): array => [
                (string) ($child['usage_type'] ?? '') => $this->nullableString($child['mikro_cari_kodu'] ?? null),
            ]);

        return array_filter([
            'shipping_name' => $this->nullableString($candidate['display_name'] ?? null)
                ?? $this->nullableString($candidate['mikro_cari_unvan'] ?? null),
            'phone' => $this->nullableString($candidate['phone'] ?? null),
            'address' => $this->nullableString($candidate['address'] ?? null),
            'city' => $this->nullableString($candidate['city'] ?? null),
            'district' => $this->nullableString($candidate['district'] ?? null),
            'consignment_cari_kodu' => $childMap->get('consignment'),
            'showroom_cari_kodu' => $childMap->get('showroom'),
            'project_cari_kodu' => $childMap->get('project'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<int, array<string, mixed>>
     */
    private function childCariAccountsMetadata(array $candidate): array
    {
        return collect($candidate['child_cari_accounts'] ?? [])
            ->filter(fn (mixed $child): bool => is_array($child))
            ->map(fn (array $child): array => [
                'mikro_cari_kodu' => $this->nullableString($child['mikro_cari_kodu'] ?? null),
                'mikro_cari_unvan' => $this->nullableString($child['mikro_cari_unvan'] ?? null),
                'display_name' => $this->nullableString($child['display_name'] ?? null),
                'usage_type' => $this->nullableString($child['usage_type'] ?? null),
                'cari_usage_type' => $this->nullableString($child['cari_usage_type'] ?? null),
                'invoice_usage_note' => $this->nullableString($child['invoice_usage_note'] ?? null),
                'status' => $this->nullableString($child['status'] ?? null),
                'status_label' => $this->nullableString($child['status_label'] ?? null),
            ])
            ->filter(fn (array $child): bool => $child['mikro_cari_kodu'] !== null)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function mergeCariCandidateMetadata(B2BPartner $partner, array $candidate): array
    {
        $metadata = is_array($partner->metadata) ? $partner->metadata : [];
        $candidateMetadata = $this->cariCandidateMetadata($candidate);

        foreach ($candidateMetadata as $key => $value) {
            $metadata[$key] = $value;
        }

        return $metadata;
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

    /**
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function ensureDefaultDealerUser(B2BPartner $partner, array $capabilities, array $candidate, Request $request, int $actorId): ?array
    {
        if (! in_array(B2BPartner::TYPE_DEALER, $capabilities, true)) {
            return null;
        }

        $existingProfile = B2BPartnerUserProfile::query()
            ->where('partner_id', $partner->id)
            ->whereHas('user', fn (Builder $query): Builder => $query->where('role_code', 'b2b_dealer'))
            ->first();

        if ($existingProfile) {
            return [
                'user_id' => $existingProfile->user_id,
                'username' => $existingProfile->user?->username,
                'status' => 'already_linked',
            ];
        }

        $username = $this->uniqueDealerUsername($partner->display_name ?? $candidate['display_name'] ?? $candidate['mikro_cari_unvan'] ?? null, $partner->mikro_cari_kodu);
        $user = User::query()->create([
            'username' => $username,
            'full_name' => Str::limit((string) ($partner->display_name ?? $candidate['display_name'] ?? $candidate['mikro_cari_unvan'] ?? $username), 120, ''),
            'password_hash' => Hash::make('12345678'),
            'role_code' => 'b2b_dealer',
            'aktif' => true,
            'force_password_change' => true,
        ]);

        B2BPartnerUserProfile::query()->updateOrCreate(
            [
                'partner_id' => $partner->id,
                'user_id' => $user->id,
            ],
            [
                'title' => 'Bayi kullanicisi',
                'phone' => $partner->phone,
                'active' => true,
                'metadata' => [
                    'default_dealer_user' => true,
                    'source' => 'b2b_cari_control',
                ],
            ],
        );

        foreach ($this->defaultDealerAccessRows() as $scope => $attributes) {
            B2BPartnerUserAccess::query()->updateOrCreate(
                [
                    'partner_id' => $partner->id,
                    'user_id' => $user->id,
                    'access_scope' => $scope,
                ],
                [
                    ...$attributes,
                    'created_by' => $actorId,
                ],
            );
        }

        $this->writeAuditLog($partner, $request, 'b2b.partner.default_user_created', null, [
            'user_id' => $user->id,
            'username' => $user->username,
            'role_code' => $user->role_code,
            'default_password_assigned' => true,
        ], $actorId);
        $this->writePartnerUserAuditLog($partner, $request, 'b2b.partner_user.assigned', $user, null, [
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'scopes' => $this->defaultDealerAccessRows(),
        ], $actorId);

        return [
            'user_id' => $user->id,
            'username' => $user->username,
            'role_code' => $user->role_code,
            'default_password' => '12345678',
            'status' => 'created',
        ];
    }

    private function uniqueDealerUsername(?string $displayName, ?string $mikroCode): string
    {
        $name = strtolower(Str::ascii((string) ($displayName ?: 'bayi')));
        $namePrefix = substr(preg_replace('/[^a-z0-9]/', '', $name) ?: 'bayi', 0, 5);
        $digits = preg_replace('/\D/', '', (string) $mikroCode) ?: '000';
        $base = $namePrefix.substr($digits, 0, 3);
        $username = $base;
        $counter = 2;

        while (User::query()->where('username', $username)->exists()) {
            $username = $base.$counter;
            $counter++;
        }

        return $username;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function defaultDealerAccessRows(): array
    {
        return [
            'view' => [
                'can_view' => true,
                'can_create' => false,
                'can_update' => false,
                'can_approve' => false,
            ],
            'orders' => [
                'can_view' => true,
                'can_create' => true,
                'can_update' => false,
                'can_approve' => false,
            ],
            'stock' => [
                'can_view' => true,
                'can_create' => false,
                'can_update' => false,
                'can_approve' => false,
            ],
        ];
    }

    public function locksmithTechnicians(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'mikro_cari_kodu' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:64'],
            'active' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'partner_id' => ['nullable', 'integer', Rule::exists((new B2BPartner)->getTable(), 'id')],
        ]);

        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $limit = min((int) ($filters['limit'] ?? 50), 100);
        $active = array_key_exists('active', $filters) ? (bool) $filters['active'] : true;
        $query = TechnicalServiceTechnician::query()
            ->where('active', $active);

        if (! empty($filters['city'])) {
            $query->where('city', $likeOperator, $filters['city']);
        }

        if (! empty($filters['mikro_cari_kodu'])) {
            $mikroCariKodu = $filters['mikro_cari_kodu'];
            $query->where(function (Builder $query) use ($likeOperator, $mikroCariKodu): void {
                $query->where('mikro_cari_kodu', $likeOperator, '%'.$mikroCariKodu.'%')
                    ->orWhere('cari_code', $likeOperator, '%'.$mikroCariKodu.'%');
            });
        }

        if (! empty($filters['phone'])) {
            $phone = $filters['phone'];
            $normalizedPhone = preg_replace('/\D+/', '', $phone) ?? '';
            $query->where(function (Builder $query) use ($likeOperator, $phone, $normalizedPhone): void {
                $query->where('phone', $likeOperator, '%'.$phone.'%')
                    ->orWhere('phone_display', $likeOperator, '%'.$phone.'%')
                    ->orWhere('phone_e164', $likeOperator, '%'.$phone.'%');

                if ($normalizedPhone !== '') {
                    $query->orWhere('phone', $likeOperator, '%'.$normalizedPhone.'%')
                        ->orWhere('phone_display', $likeOperator, '%'.$normalizedPhone.'%')
                        ->orWhere('phone_e164', $likeOperator, '%'.$normalizedPhone.'%');
                }
            });
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $normalizedSearch = preg_replace('/\D+/', '', $search) ?? '';
            $query->where(function (Builder $query) use ($likeOperator, $search, $normalizedSearch): void {
                $query->where('name', $likeOperator, '%'.$search.'%')
                    ->orWhere('phone', $likeOperator, '%'.$search.'%')
                    ->orWhere('mikro_cari_kodu', $likeOperator, '%'.$search.'%')
                    ->orWhere('cari_code', $likeOperator, '%'.$search.'%')
                    ->orWhere('mikro_cari_adi', $likeOperator, '%'.$search.'%')
                    ->orWhere('cari_title', $likeOperator, '%'.$search.'%')
                    ->orWhere('city', $likeOperator, '%'.$search.'%')
                    ->orWhere('district', $likeOperator, '%'.$search.'%')
                    ->orWhere('address', $likeOperator, '%'.$search.'%')
                    ->orWhere('cari_address', $likeOperator, '%'.$search.'%')
                    ->orWhere('source_key', $likeOperator, '%'.$search.'%');

                if ($normalizedSearch !== '') {
                    $query->orWhere('phone', $likeOperator, '%'.$normalizedSearch.'%')
                        ->orWhere('phone_display', $likeOperator, '%'.$normalizedSearch.'%')
                        ->orWhere('phone_e164', $likeOperator, '%'.$normalizedSearch.'%');
                }
            });
        }

        return response()->json([
            'items' => $query
                ->with(['b2bPartnerLinks.partner'])
                ->orderByDesc('active')
                ->orderBy('city')
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(fn (TechnicalServiceTechnician $technician): array => $this->locksmithTechnicianPayload($technician, $filters))
                ->values(),
        ]);
    }

    public function syncLocksmithTechnicians(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->access->canManageCapabilities($user, [B2BPartner::TYPE_LOCKSMITH]), 403);

        $summary = DB::transaction(function () use ($request, $user): array {
            $result = [
                'created' => 0,
                'updated' => 0,
                'capability_added' => 0,
                'skipped' => 0,
                'created_partners' => 0,
                'updated_partners' => 0,
                'linked_technicians' => 0,
                'already_linked' => 0,
                'review_required' => 0,
                'skipped_errors' => 0,
                'items' => [],
            ];

            TechnicalServiceTechnician::query()
                ->where('active', true)
                ->where(function (Builder $query): void {
                    $query->whereIn('technician_type', ['locksmith', 'technician'])
                        ->orWhere(function (Builder $query): void {
                            $query->where(function (Builder $query): void {
                                $query->whereNull('technician_type')
                                    ->orWhereNotIn('technician_type', ['locksmith', 'technician']);
                            })
                                ->where(function (Builder $query): void {
                                    $query->whereNotNull('mikro_cari_kodu')
                                        ->orWhereNotNull('cari_code')
                                        ->orWhereNotNull('phone')
                                        ->orWhereNotNull('city')
                                        ->orWhereNotNull('source_key');
                                });
                        });
                })
                ->orderBy('id')
                ->get()
                ->each(function (TechnicalServiceTechnician $technician) use (&$result, $request, $user): void {
                    $partner = $this->partnerForTechnicianSync($technician);
                    $reviewRequired = ! in_array($technician->technician_type, ['locksmith', 'technician'], true);

                    if ($partner) {
                        $oldValues = $this->auditPayload($partner);
                        $oldCapabilities = $partner->capabilityCodes();
                        $capabilities = collect($oldCapabilities)->merge([B2BPartner::TYPE_LOCKSMITH])->unique()->values()->all();
                        $partner->fill($this->technicianSnapshotPayload($technician, $partner));
                        $partner->metadata = $this->mergeTechnicianMetadata($partner, $technician);
                        $partner->partner_type = $this->primaryPartnerType($capabilities);
                        $partner->save();
                        $this->syncCapabilities($partner, $capabilities, $request, $user->id, $oldCapabilities);
                        $alreadyLinked = $this->activeTechnicianLinkForPartner($partner, $technician->id) !== null;
                        $link = $this->upsertPartnerTechnicianLink(
                            $partner->fresh('capabilities'),
                            $technician,
                            $request,
                            $user->id,
                            'field_technician',
                            (int) $partner->technical_service_technician_id === (int) $technician->id,
                            'sync',
                            $reviewRequired ? 'legacy_type_review' : 'mikro_cari_match',
                        );
                        $partner->refresh();
                        $this->writeAuditLog($partner, $request, 'b2b.partner.updated_from_technician', $oldValues, $this->auditPayload($partner), $user->id);
                        $this->writeAuditLog($partner, $request, 'b2b.partner.locksmith_synced', $oldValues, $this->auditPayload($partner), $user->id);
                        $this->writeAuditLog($partner, $request, 'b2b.partner.technician_sync_added', null, [
                            'partner_id' => $partner->id,
                            'technician_id' => $technician->id,
                            'link_id' => $link->id,
                            'source' => 'sync',
                            'match_reason' => 'mikro_cari_match',
                            'linked_by' => $user->id,
                        ], $user->id);

                        $result['updated']++;
                        $result['updated_partners']++;
                        if ($alreadyLinked) {
                            $result['already_linked']++;
                        } else {
                            $result['linked_technicians']++;
                        }
                        if ($reviewRequired) {
                            $result['review_required']++;
                        }
                        if (! in_array(B2BPartner::TYPE_LOCKSMITH, $oldCapabilities, true)) {
                            $result['capability_added']++;
                        }
                        $result['items'][] = [
                            'technician_id' => $technician->id,
                            'partner_id' => $partner->id,
                            'status' => 'updated',
                        ];

                        return;
                    }

                    $partner = B2BPartner::query()->create([
                        'partner_type' => B2BPartner::TYPE_LOCKSMITH,
                        'partner_code' => $this->uniqueLocksmithPartnerCode($technician),
                        ...$this->technicianSnapshotPayload($technician),
                        'active' => true,
                        'metadata' => $this->technicianMetadata($technician),
                    ]);
                    $this->syncCapabilities($partner, [B2BPartner::TYPE_LOCKSMITH], $request, $user->id, []);
                    $link = $this->upsertPartnerTechnicianLink($partner->fresh('capabilities'), $technician, $request, $user->id, 'field_technician', true, 'sync', 'new_locksmith_partner');
                    $this->writeAuditLog($partner, $request, 'b2b.partner.locksmith_synced', null, $this->auditPayload($partner), $user->id);
                    $this->writeAuditLog($partner, $request, 'b2b.partner.technician_sync_added', null, [
                        'partner_id' => $partner->id,
                        'technician_id' => $technician->id,
                        'link_id' => $link->id,
                        'source' => 'sync',
                        'match_reason' => 'new_locksmith_partner',
                        'linked_by' => $user->id,
                    ], $user->id);

                    $result['created']++;
                    $result['created_partners']++;
                    $result['linked_technicians']++;
                    if ($reviewRequired) {
                        $result['review_required']++;
                    }
                    $result['items'][] = [
                        'technician_id' => $technician->id,
                        'partner_id' => $partner->id,
                        'status' => 'created',
                    ];
                });

            return $result;
        });

        return response()->json($summary);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function partnerTechnicianItems(B2BPartner $partner): array
    {
        return $partner->partnerTechnicians()
            ->with('technician')
            ->orderByDesc('is_primary')
            ->orderByDesc('active')
            ->orderBy('id')
            ->get()
            ->map(fn (B2BPartnerTechnician $link): array => $this->partnerTechnicianPayload($link))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerTechnicianPayload(B2BPartnerTechnician $link): array
    {
        $technician = $link->technician;

        return [
            'id' => $link->id,
            'partner_id' => $link->partner_id,
            'technical_service_technician_id' => $link->technical_service_technician_id,
            'relationship_type' => $link->relationship_type,
            'is_primary' => (bool) $link->is_primary,
            'active' => (bool) $link->active,
            'source' => $link->source,
            'match_reason' => $link->match_reason,
            'service_city' => $link->service_city,
            'service_district' => $link->service_district,
            'service_region_note' => $link->service_region_note,
            'priority' => $link->priority,
            'needs_review' => (bool) $link->needs_review,
            'review_reason' => $link->review_reason,
            'review_reasons' => $link->review_reasons ?? [],
            'reviewed_at' => $link->reviewed_at?->toIso8601String(),
            'reviewed_by' => $link->reviewed_by,
            'metadata' => $link->metadata ?? [],
            'technician' => $technician ? [
                'id' => $technician->id,
                'name' => $technician->name,
                'display_name' => $technician->display_name ?? $technician->name,
                'phone' => $technician->phone,
                'city' => $technician->city,
                'district' => $technician->district,
                'address' => $technician->address ?? $technician->cari_address,
                'latitude' => $technician->latitude,
                'longitude' => $technician->longitude,
                'start_latitude' => $technician->start_latitude,
                'start_longitude' => $technician->start_longitude,
                'mikro_cari_kodu' => $technician->mikro_cari_kodu ?? $technician->cari_code,
                'mikro_cari_adi' => $technician->mikro_cari_adi ?? $technician->cari_title,
                'technician_type' => $technician->technician_type,
                'needs_review' => (bool) $technician->needs_review,
                'review_reason' => $technician->review_reason,
                'review_reasons' => $technician->review_reasons ?? [],
                'geocode_status' => $technician->geocode_status,
                'location_source' => $technician->location_source,
                'active' => (bool) $technician->active,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPartnerTechnicianData(Request $request): array
    {
        return $request->validate([
            'technical_service_technician_id' => [
                'required',
                'integer',
                Rule::exists((new TechnicalServiceTechnician)->getTable(), 'id'),
            ],
            'relationship_type' => ['nullable', 'string', Rule::in(['owner', 'field_technician', 'contracted_technician', 'branch_technician', 'contact'])],
            'is_primary' => ['nullable', 'boolean'],
        ]);
    }

    private function defaultTechnicianRelationshipType(B2BPartner $partner): string
    {
        return $partner->hasCapability(B2BPartner::TYPE_LOCKSMITH)
            ? 'field_technician'
            : 'contracted_technician';
    }

    private function upsertPartnerTechnicianLink(
        B2BPartner $partner,
        TechnicalServiceTechnician $technician,
        Request $request,
        int $userId,
        string $relationshipType = 'field_technician',
        bool $isPrimary = false,
        ?string $source = null,
        ?string $matchReason = null,
        array $context = [],
    ): B2BPartnerTechnician {
        $this->ensurePartnerCanLinkTechnician($partner, $technician);
        $hasActiveLinks = $partner->activePartnerTechnicians()->exists();
        $isPrimary = $isPrimary || ! $hasActiveLinks;

        if ($isPrimary) {
            $this->clearOtherPrimaryTechnicians($partner);
        }

        $link = B2BPartnerTechnician::query()->updateOrCreate(
            [
                'partner_id' => $partner->id,
                'technical_service_technician_id' => $technician->id,
            ],
            [
                'relationship_type' => $relationshipType,
                'is_primary' => $isPrimary,
                'active' => true,
                'source' => $source,
                'match_reason' => $matchReason,
                'service_city' => $this->nullableString($context['service_city'] ?? null) ?? $technician->city,
                'service_district' => $this->nullableString($context['service_district'] ?? null) ?? $technician->district,
                'service_region_note' => $this->nullableString($context['service_region_note'] ?? null),
                'priority' => (int) ($context['priority'] ?? 1),
                'needs_review' => (bool) ($context['needs_review'] ?? false),
                'review_reason' => $this->nullableString($context['review_reason'] ?? null),
                'review_reasons' => is_array($context['review_reasons'] ?? null) ? $context['review_reasons'] : [],
                'reviewed_at' => $context['reviewed_at'] ?? null,
                'reviewed_by' => $context['reviewed_by'] ?? null,
                'metadata' => [
                    'technician_snapshot' => $this->technicianMetadata($technician)['technician_snapshot'] ?? [],
                    'candidate' => $context['candidate'] ?? null,
                    'linked_at' => now()->toIso8601String(),
                ],
                'created_by' => $userId,
            ],
        );

        $this->ensurePartnerHasPrimaryTechnician($partner);
        $partner->metadata = $this->mergeTechnicianMetadata($partner, $technician);
        $partner->save();

        return $link->fresh(['technician']);
    }

    private function ensurePartnerCanLinkTechnician(B2BPartner $partner, TechnicalServiceTechnician $technician): void
    {
        if (! $technician->active) {
            throw ValidationException::withMessages([
                'technical_service_technician_id' => 'Seçilen teknik servis ustası aktif olmalıdır.',
            ]);
        }
    }

    private function activeTechnicianLinkForPartner(B2BPartner $partner, int|string $technicianId): ?B2BPartnerTechnician
    {
        return B2BPartnerTechnician::query()
            ->where('partner_id', $partner->id)
            ->where('technical_service_technician_id', $technicianId)
            ->where('active', true)
            ->first();
    }

    private function clearOtherPrimaryTechnicians(B2BPartner $partner, ?int $exceptLinkId = null): void
    {
        B2BPartnerTechnician::query()
            ->where('partner_id', $partner->id)
            ->where('is_primary', true)
            ->when($exceptLinkId, fn (Builder $query): Builder => $query->whereKeyNot($exceptLinkId))
            ->update(['is_primary' => false]);
    }

    private function ensurePartnerHasPrimaryTechnician(B2BPartner $partner): void
    {
        $primary = $partner->primaryTechnicianLink()->first();

        if (! $primary) {
            $primary = $partner->activePartnerTechnicians()
                ->orderBy('id')
                ->first();

            if ($primary) {
                $primary->forceFill(['is_primary' => true])->save();
            }
        }

        $partner->forceFill([
            'technical_service_technician_id' => $primary?->technical_service_technician_id,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerTechnicianAuditPayload(B2BPartnerTechnician $link): array
    {
        return [
            'partner_id' => $link->partner_id,
            'technician_id' => $link->technical_service_technician_id,
            'relationship_type' => $link->relationship_type,
            'is_primary' => (bool) $link->is_primary,
            'active' => (bool) $link->active,
            'source' => $link->source,
            'match_reason' => $link->match_reason,
            'service_city' => $link->service_city,
            'service_district' => $link->service_district,
            'service_region_note' => $link->service_region_note,
            'priority' => $link->priority,
            'needs_review' => (bool) $link->needs_review,
            'review_reason' => $link->review_reason,
            'review_reasons' => $link->review_reasons ?? [],
            'reviewed_at' => $link->reviewed_at?->toIso8601String(),
            'reviewed_by' => $link->reviewed_by,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function locksmithTechnicianPayload(TechnicalServiceTechnician $technician, array $filters): array
    {
        $cariCode = $technician->mikro_cari_kodu ?? $technician->cari_code;
        $cariTitle = $technician->mikro_cari_adi ?? $technician->cari_title;
        $currentPartnerId = $filters['partner_id'] ?? null;
        $activeLinks = $technician->relationLoaded('b2bPartnerLinks')
            ? $technician->b2bPartnerLinks
                ->filter(fn (B2BPartnerTechnician $link): bool => (bool) $link->active && (bool) $link->partner?->active)
                ->values()
            : B2BPartnerTechnician::query()
                ->with('partner')
                ->where('technical_service_technician_id', $technician->id)
                ->where('active', true)
                ->whereHas('partner', fn (Builder $query): Builder => $query->where('active', true))
                ->get();
        $linkedToCurrentPartner = $currentPartnerId !== null && $activeLinks->contains(fn (B2BPartnerTechnician $link): bool => (int) $link->partner_id === (int) $currentPartnerId);
        $linkedPartnerIds = $activeLinks->pluck('partner_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $linkedPartnerNames = $activeLinks
            ->map(fn (B2BPartnerTechnician $link): ?string => $link->partner?->display_name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'id' => $technician->id,
            'name' => $technician->name,
            'display_name' => $technician->name,
            'phone' => $technician->phone,
            'city' => $technician->city,
            'district' => $technician->district,
            'address' => $technician->address ?? $technician->cari_address,
            'mikro_cari_kodu' => $cariCode,
            'mikro_cari_adi' => $cariTitle,
            'cari_code' => $technician->cari_code,
            'cari_title' => $technician->cari_title,
            'technician_type' => $technician->technician_type,
            'active' => (bool) $technician->active,
            'needs_review' => (bool) $technician->needs_review,
            'review_reason' => $technician->review_reason,
            'review_reasons' => $technician->review_reasons ?? [],
            'geocode_status' => $technician->geocode_status,
            'location_source' => $technician->location_source,
            'source_key' => 'technical_service_technician:'.$technician->id,
            'match_reason' => $this->technicianMatchReason($technician, $filters),
            'requires_type_review' => $technician->technician_type === null,
            'linked_partner_id' => $linkedPartnerIds[0] ?? null,
            'linked_partner_name' => $linkedPartnerNames[0] ?? null,
            'linked_partner_ids' => $linkedPartnerIds,
            'linked_partner_names' => $linkedPartnerNames,
            'linked_to_current_partner' => $linkedToCurrentPartner,
            'can_link' => ! $linkedToCurrentPartner,
            'cannot_link_reason' => $linkedToCurrentPartner
                ? 'already_linked_to_this_partner'
                : null,
        ];
    }

    private function partnerForTechnicianSync(TechnicalServiceTechnician $technician): ?B2BPartner
    {
        $partner = B2BPartner::query()
            ->where('active', true)
            ->where('technical_service_technician_id', $technician->id)
            ->first();

        if ($partner) {
            return $partner;
        }

        $codes = array_values(array_filter([$technician->mikro_cari_kodu, $technician->cari_code]));

        if ($codes === []) {
            return null;
        }

        return B2BPartner::query()
            ->where('active', true)
            ->whereIn('mikro_cari_kodu', $codes)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function technicianSnapshotPayload(TechnicalServiceTechnician $technician, ?B2BPartner $partner = null): array
    {
        return [
            'display_name' => $this->nullableString($technician->display_name)
                ?? $this->nullableString($technician->name)
                ?? $partner?->display_name,
            'mikro_cari_kodu' => $this->nullableString($technician->mikro_cari_kodu)
                ?? $this->nullableString($technician->cari_code)
                ?? $partner?->mikro_cari_kodu,
            'mikro_cari_unvan' => $this->nullableString($technician->mikro_cari_adi)
                ?? $this->nullableString($technician->cari_title)
                ?? $partner?->mikro_cari_unvan,
            'phone' => $this->nullableString($technician->phone) ?? $partner?->phone,
            'city' => $this->nullableString($technician->city) ?? $partner?->city,
            'district' => $this->nullableString($technician->district) ?? $partner?->district,
            'address' => $this->nullableString($technician->address) ?? $this->nullableString($technician->cari_address) ?? $partner?->address,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technicianMetadata(TechnicalServiceTechnician $technician): array
    {
        return [
            'technician_sync' => [
                'technical_service_technician_id' => $technician->id,
                'source_key' => $technician->source_key,
                'synced_at' => now()->toIso8601String(),
            ],
            'address' => $this->nullableString($technician->address) ?? $this->nullableString($technician->cari_address),
            'technician_snapshot' => [
                'name' => $technician->name,
                'phone' => $technician->phone,
                'city' => $technician->city,
                'district' => $technician->district,
                'address' => $technician->address ?? $technician->cari_address,
                'mikro_cari_kodu' => $technician->mikro_cari_kodu ?? $technician->cari_code,
                'mikro_cari_unvan' => $technician->mikro_cari_adi ?? $technician->cari_title,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyPartnerFormMetadata(B2BPartner $partner, array $data): void
    {
        $metadata = is_array($partner->metadata) ? $partner->metadata : [];

        if (! empty($data['technical_service_technician_id'])) {
            $technician = TechnicalServiceTechnician::query()->find($data['technical_service_technician_id']);

            if ($technician) {
                $metadata = [
                    ...$metadata,
                    ...$this->technicianMetadata($technician),
                ];
            }
        }

        $address = $this->nullableString($data['address'] ?? null);

        if ($address !== null) {
            $metadata['address'] = $address;
        }

        if ($metadata !== (is_array($partner->metadata) ? $partner->metadata : [])) {
            $partner->forceFill(['metadata' => $metadata])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeTechnicianMetadata(B2BPartner $partner, TechnicalServiceTechnician $technician): array
    {
        return [
            ...(is_array($partner->metadata) ? $partner->metadata : []),
            ...$this->technicianMetadata($technician),
        ];
    }

    private function uniqueLocksmithPartnerCode(TechnicalServiceTechnician $technician): string
    {
        $base = Str::limit('B2B-LG-'.$technician->id, 120, '');
        $code = $base;
        $counter = 1;

        while (B2BPartner::query()->where('partner_code', $code)->exists()) {
            $code = Str::limit($base.'-'.$counter, 128, '');
            $counter++;
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function technicianMatchReason(TechnicalServiceTechnician $technician, array $filters): string
    {
        $mikroCariKodu = $this->nullableString($filters['mikro_cari_kodu'] ?? null);
        $phone = $this->nullableString($filters['phone'] ?? null);
        $city = $this->nullableString($filters['city'] ?? null);
        $search = $this->nullableString($filters['search'] ?? null);

        if ($mikroCariKodu && in_array($mikroCariKodu, array_filter([$technician->mikro_cari_kodu, $technician->cari_code]), true)) {
            return 'cari_match';
        }

        if ($search && in_array($search, array_filter([$technician->mikro_cari_kodu, $technician->cari_code]), true)) {
            return 'cari_match';
        }

        if ($city && strcasecmp($city, (string) $technician->city) === 0) {
            return 'city_match';
        }

        if ($phone && str_contains((string) $technician->phone, $phone)) {
            return 'phone_match';
        }

        if ($technician->technician_type === null) {
            return 'legacy_type_review';
        }

        return $search ? 'search_match' : 'active_technician';
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
            'address' => ['nullable', 'string', 'max:1024'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'tax_no' => ['nullable', 'string', 'max:64'],
            'tax_office' => ['nullable', 'string', 'max:255'],
            'tax_identity_type' => ['nullable', 'string', Rule::in(['vkn', 'tckn', 'unknown'])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'google_formatted_address' => ['nullable', 'string', 'max:2000'],
            'google_plus_code' => ['nullable', 'string', 'max:255'],
            'location_source' => ['nullable', 'string', 'max:64'],
            'geocode_status' => ['nullable', 'string', 'max:64'],
            'needs_review' => ['sometimes', 'boolean'],
            'review_reason' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
            'technical_service_technician_id' => [
                'nullable',
                'integer',
                Rule::exists((new TechnicalServiceTechnician)->getTable(), 'id'),
            ],
        ]);
        if (array_key_exists('tax_number', $data) || array_key_exists('tax_no', $data)) {
            $data['tax_number'] = $this->nullableString($data['tax_number'] ?? null) ?? $this->nullableString($data['tax_no'] ?? null);
            $data['tax_identity_type'] = $data['tax_identity_type'] ?? $this->taxIdentityType($data['tax_number'] ?? null);
        }
        $data['capabilities'] = $this->normalizeCapabilities($data);
        $data['partner_type'] = $data['partner_type'] ?? $this->primaryPartnerType($data['capabilities']);
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
        $payload = [
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
            'address' => $this->nullableString($data['address'] ?? null),
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
        ];

        foreach ([
            'tax_number',
            'tax_office',
            'tax_identity_type',
            'google_formatted_address',
            'google_plus_code',
            'location_source',
            'geocode_status',
            'review_reason',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $this->nullableString($data[$field] ?? null);
            }
        }

        foreach (['latitude', 'longitude'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field] !== null && $data[$field] !== '' ? $data[$field] : null;
            }
        }

        if (array_key_exists('needs_review', $data)) {
            $payload['needs_review'] = (bool) $data['needs_review'];
        }

        if (array_key_exists('review_reason', $data)) {
            $reviewReason = $this->nullableString($data['review_reason'] ?? null);
            $payload['review_reasons'] = $reviewReason !== null ? [$reviewReason] : [];
        }

        return $payload;
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

    private function normalizedText(mixed $value): string
    {
        return trim(strtoupper(Str::ascii((string) ($value ?? ''))));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(B2BPartner $partner): array
    {
        $partner->loadMissing('activePartnerTechnicians');

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
                'address',
                'tax_number',
                'tax_office',
                'tax_identity_type',
                'latitude',
                'longitude',
                'google_formatted_address',
                'google_plus_code',
                'location_source',
                'geocode_status',
                'geocode_source',
                'geocode_confidence',
                'geocoded_at',
                'needs_review',
                'review_reason',
                'review_reasons',
                'active',
                'technical_service_technician_id',
            ]),
            'capabilities' => $partner->capabilityCodes(),
            'linked_technician_ids' => $partner->activePartnerTechnicians
                ->pluck('technical_service_technician_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all(),
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
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function writePartnerUserAuditLog(
        B2BPartner $partner,
        Request $request,
        string $action,
        User $subjectUser,
        ?array $oldValues,
        array $newValues,
        int $actorId,
    ): void {
        B2BPartnerAuditLog::query()->create([
            'partner_id' => $partner->id,
            'user_id' => $actorId,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $subjectUser->id,
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
        $partner->loadMissing(['technician', 'capabilities', 'primaryTechnicianLink.technician', 'activePartnerTechnicians.technician']);
        $primaryTechnician = $partner->primaryTechnicianLink?->technician ?? $partner->technician;
        $linkedTechnicians = $partner->activePartnerTechnicians
            ->sortByDesc('is_primary')
            ->map(fn (B2BPartnerTechnician $link): array => $this->partnerTechnicianPayload($link))
            ->values()
            ->all();

        $metadata = is_array($partner->metadata) ? $partner->metadata : [];
        $taxNumber = $partner->tax_number ?? ($metadata['tax_no'] ?? data_get($metadata, 'invoice_profile.tax_no'));
        $taxOffice = $partner->tax_office ?? ($metadata['tax_office'] ?? data_get($metadata, 'invoice_profile.tax_office'));

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
            'technical_service_technician_id' => $primaryTechnician?->id,
            'primary_technician_id' => $primaryTechnician?->id,
            'linked_technicians' => $linkedTechnicians,
            'linked_technician_name' => $primaryTechnician?->name,
            'linked_technician_phone' => $primaryTechnician?->phone,
            'linked_technician_city' => $primaryTechnician?->city,
            'linked_technician_mikro_cari_kodu' => $primaryTechnician?->mikro_cari_kodu ?? $primaryTechnician?->cari_code,
            'address' => $partner->address ?? ($metadata['address'] ?? null),
            'tax_number' => $taxNumber,
            'tax_no' => $taxNumber,
            'tax_office' => $taxOffice,
            'tax_identity_type' => $partner->tax_identity_type ?? $this->taxIdentityType($this->nullableString($taxNumber)),
            'latitude' => $partner->latitude,
            'longitude' => $partner->longitude,
            'google_formatted_address' => $partner->google_formatted_address,
            'google_plus_code' => $partner->google_plus_code,
            'location_source' => $partner->location_source,
            'geocode_status' => $partner->geocode_status,
            'geocode_source' => $partner->geocode_source,
            'geocode_confidence' => $partner->geocode_confidence,
            'geocoded_at' => optional($partner->geocoded_at)->toIso8601String(),
            'needs_review' => (bool) $partner->needs_review,
            'review_reason' => $partner->review_reason,
            'review_reasons' => $partner->review_reasons ?? [],
            'source_field_missing' => $metadata['source_field_missing'] ?? [],
            'child_cari_accounts' => $metadata['child_cari_accounts'] ?? [],
            'invoice_profile' => $metadata['invoice_profile'] ?? [],
            'shipping_profile' => $metadata['shipping_profile'] ?? [],
            'invoice_usage_note' => $metadata['invoice_usage_note'] ?? null,
            'users_count' => B2BPartnerUserProfile::query()->where('partner_id', $partner->id)->count(),
            'active_users_count' => B2BPartnerUserProfile::query()->where('partner_id', $partner->id)->where('active', true)->count(),
            'portal_admin_users' => $this->adminProvisioning->portalAdminSummaries($partner),
            'has_portal_admin' => $this->adminProvisioning->activePortalAdminProfile($partner) !== null,
            'mikro_snapshot' => [
                'mikro_cari_kodu' => $partner->mikro_cari_kodu,
                'mikro_cari_unvan' => $partner->mikro_cari_unvan,
                'cari_grup_kodu' => $partner->cari_grup_kodu,
                'responsibility_code' => $partner->responsibility_code,
            ],
        ];
    }
}

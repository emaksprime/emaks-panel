<?php

namespace App\Http\Controllers\Api\B2B;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartner;
use App\Models\User;
use App\Services\B2B\B2BPartnerAccessService;
use App\Services\B2B\B2BPartnerUserMembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class B2BPartnerUserController extends Controller
{
    public function __construct(
        private readonly B2BPartnerAccessService $access,
        private readonly B2BPartnerUserMembershipService $memberships,
    ) {}

    public function index(Request $request, B2BPartner $partner): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->canViewPartner($actor, $partner), 403);

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
            'items' => $this->memberships->partnerUsersPayload($partner),
            'can_manage' => $this->access->canManagePartnerUsers($actor, $partner),
        ]);
    }

    public function store(Request $request, B2BPartner $partner): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->canManagePartnerUsers($actor, $partner), 403);

        $data = $this->memberships->validatePayload($request, $partner, true);
        $targetUser = User::query()->findOrFail($data['user_id']);
        $this->memberships->assign($partner, $targetUser, $data, $request, $actor);

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
            'items' => $this->memberships->partnerUsersPayload($partner),
        ], 201);
    }

    public function update(Request $request, B2BPartner $partner, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->canManagePartnerUsers($actor, $partner), 403);

        $data = $this->memberships->validatePayload($request, $partner, false);
        $this->memberships->update($partner, $user, $data, $request, $actor);

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
            'items' => $this->memberships->partnerUsersPayload($partner),
        ]);
    }

    public function destroy(Request $request, B2BPartner $partner, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->canManagePartnerUsers($actor, $partner), 403);

        $this->memberships->deactivate($partner, $user, $request, $actor);

        return response()->json([
            'partner' => $this->partnerPayload($partner->loadMissing('technician')),
            'items' => $this->memberships->partnerUsersPayload($partner),
        ]);
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && $this->access->canSearchPanelUsers($actor), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'role_code' => ['nullable', 'string', 'max:128'],
        ]);
        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query = User::query()->with('role');

        if (array_key_exists('active', $filters)) {
            $query->where('aktif', $filters['active']);
        }

        if (! empty($filters['role_code'])) {
            $query->where('role_code', $filters['role_code']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $query) use ($likeOperator, $search): void {
                $query->where('full_name', $likeOperator, '%'.$search.'%')
                    ->orWhere('username', $likeOperator, '%'.$search.'%')
                    ->orWhere('role_code', $likeOperator, '%'.$search.'%');
            });
        }

        return response()->json([
            'items' => $query
                ->orderByDesc('aktif')
                ->orderBy('full_name')
                ->limit(50)
                ->get()
                ->map(fn (User $user): array => $this->userPayload($user))
                ->values(),
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
            'capabilities' => $partner->capabilityCodes(),
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

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role_code' => $user->role_code,
            'active' => (bool) $user->aktif,
        ];
    }
}

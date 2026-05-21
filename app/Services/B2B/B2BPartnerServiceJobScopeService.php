<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class B2BPartnerServiceJobScopeService
{
    public function __construct(
        private readonly B2BPartnerAccessService $partnerAccess,
    ) {}

    /**
     * @return array<int, int>
     */
    public function activeTechnicianIds(B2BPartner $partner): array
    {
        if (! $partner->hasCapability(B2BPartner::TYPE_LOCKSMITH)) {
            return [];
        }

        return $partner->activePartnerTechnicians()
            ->whereIn('relationship_type', ['owner', 'field_technician'])
            ->pluck('technical_service_technician_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, B2BPartner>
     */
    public function visibleLocksmithPartnersForPortal(User $user): Collection
    {
        $query = $this->partnerAccess
            ->visiblePartnerQuery($user, B2BPartner::TYPE_LOCKSMITH)
            ->where('active', true)
            ->with(['capabilities', 'activePartnerTechnicians.technician']);

        $user->loadMissing('role');
        if (! (bool) $user->role?->is_super_admin) {
            $query->whereHas('profiles', fn (Builder $query): Builder => $query
                ->where('user_id', $user->id)
                ->where('active', true));
        }

        return $query->get();
    }

    /**
     * @return array<int, int>
     */
    public function getVisibleTechnicianIdsForPartnerPortal(User $user): array
    {
        return $this->visibleLocksmithPartnersForPortal($user)
            ->flatMap(fn (B2BPartner $partner): array => $this->activeTechnicianIds($partner))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function queryVisibleServiceJobs(User $user): Builder
    {
        return TechnicalServiceRequest::query()
            ->whereIn('technical_service_technician_id', $this->getVisibleTechnicianIdsForPartnerPortal($user));
    }

    /**
     * @throws AuthorizationException
     */
    public function assertCanViewServiceJob(User $user, TechnicalServiceRequest $request): B2BPartner
    {
        $technicianId = (int) ($request->technical_service_technician_id ?? 0);
        if ($technicianId <= 0) {
            throw new AuthorizationException('Bu iş için görünür usta bağlantısı yok.');
        }

        $partner = $this->visibleLocksmithPartnersForPortal($user)
            ->first(fn (B2BPartner $partner): bool => in_array($technicianId, $this->activeTechnicianIds($partner), true));

        if (! $partner instanceof B2BPartner) {
            throw new AuthorizationException('Bu işe erişim yetkiniz yok.');
        }

        return $partner;
    }

    public function serviceJobsQuery(B2BPartner $partner): Builder
    {
        return TechnicalServiceRequest::query()
            ->whereIn('technical_service_technician_id', $this->activeTechnicianIds($partner));
    }
}

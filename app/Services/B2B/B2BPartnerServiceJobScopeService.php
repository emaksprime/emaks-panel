<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class B2BPartnerServiceJobScopeService
{
    private const CANCELLED_STATUSES = ['İptal', 'Iptal', 'Ä°ptal'];


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

    /**
     * @throws AuthorizationException
     */
    public function selectedLocksmithPartnerForPortal(User $user, ?int $requestedPartnerId = null): B2BPartner
    {
        $partners = $this->visibleLocksmithPartnersForPortal($user);

        if ($partners->isEmpty()) {
            throw new AuthorizationException('Bu kullanici icin gorunur cilingir partneri yok.');
        }

        if ($requestedPartnerId !== null && $requestedPartnerId > 0) {
            $partner = $partners->first(fn (B2BPartner $partner): bool => (int) $partner->id === $requestedPartnerId);

            if (! $partner instanceof B2BPartner) {
                throw new AuthorizationException('Bu partner icin portal erisim yetkiniz yok.');
            }

            return $partner;
        }

        return $partners->first();
    }

    /**
     * @throws AuthorizationException
     */
    public function queryVisibleServiceJobs(User $user, ?int $requestedPartnerId = null): Builder
    {
        return $this->serviceJobsQuery(
            $this->selectedLocksmithPartnerForPortal($user, $requestedPartnerId),
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function assertCanViewServiceJob(User $user, TechnicalServiceRequest $request): B2BPartner
    {
        if ($this->isCancelled($request)) {
            throw new AuthorizationException('Bu iş aktif partner işlerinde görünmez.');
        }

        if ($this->hasNonCancelledChildServiceVisit($request)) {
            throw new AuthorizationException('Bu ana talebin SRV kaydi var; partner islerinde SRV karti gorunur.');
        }

        if ($this->hasActiveRejectedAction($request)) {
            throw new AuthorizationException('Bu iş usta reddi sonrası operasyon incelemesinde.');
        }

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
            ->whereIn('technical_service_technician_id', $this->activeTechnicianIds($partner))
            ->whereNull('cancelled_at')
            ->whereDoesntHave('childRequests', fn (Builder $query): Builder => $this->nonCancelledChildServiceVisitQuery($query))
            ->whereNotIn('status', ['İptal', 'Iptal', 'Ä°ptal'])
            ->whereNotIn('workflow_status', ['İptal', 'Iptal', 'Ä°ptal'])
            ->whereDoesntHave('partnerJobActions', fn (Builder $query): Builder => $query
                ->where('action', TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED)
                ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW));
    }

    private function isCancelled(TechnicalServiceRequest $request): bool
    {
        return $request->cancelled_at !== null
            || in_array($request->status, ['İptal', 'Iptal', 'Ä°ptal'], true)
            || in_array($request->workflow_status, ['İptal', 'Iptal', 'Ä°ptal'], true);
    }

    private function hasNonCancelledChildServiceVisit(TechnicalServiceRequest $request): bool
    {
        if ($request->parent_request_id !== null) {
            return false;
        }

        return $request->childRequests()
            ->where(fn (Builder $query): Builder => $this->nonCancelledChildServiceVisitQuery($query))
            ->exists();
    }

    private function nonCancelledChildServiceVisitQuery(Builder $query): Builder
    {
        return $query
            ->whereNull('cancelled_at')
            ->whereNotIn('status', self::CANCELLED_STATUSES)
            ->whereNotIn('workflow_status', self::CANCELLED_STATUSES);
    }

    private function hasActiveRejectedAction(TechnicalServiceRequest $request): bool
    {
        return $request->partnerJobActions()
            ->where('action', TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED)
            ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->exists();
    }
}

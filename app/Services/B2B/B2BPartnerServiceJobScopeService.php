<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\TechnicalServiceAssignmentArchive;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use App\Support\PartnerPortalPublicUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class B2BPartnerServiceJobScopeService
{
    private const CANCELLED_STATUSES = ['İptal', 'Iptal', 'Ä°ptal'];

    /** @var array<int, Collection<int, B2BPartnerTechnician>> */
    private array $technicianLinks = [];

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
            ->whereHas('technician', fn (Builder $query): Builder => $query->where('active', true))
            ->pluck('technical_service_technician_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, B2BPartnerTechnician>
     */
    public function activeAssignmentLinksForTechnician(int $technicianId): Collection
    {
        if ($technicianId <= 0) {
            return collect();
        }

        if (array_key_exists($technicianId, $this->technicianLinks)) {
            return $this->technicianLinks[$technicianId];
        }

        return $this->technicianLinks[$technicianId] = B2BPartnerTechnician::query()
            ->active()
            ->where('technical_service_technician_id', $technicianId)
            ->whereIn('relationship_type', ['owner', 'field_technician'])
            ->whereHas('technician', fn (Builder $query): Builder => $query->where('active', true))
            ->whereHas('partner', fn (Builder $query): Builder => $query->where('active', true))
            ->with(['partner.capabilities', 'technician'])
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->filter(fn (B2BPartnerTechnician $link): bool => $link->partner?->hasCapability(B2BPartner::TYPE_LOCKSMITH) === true)
            ->values();
    }

    /**
     * @throws ValidationException
     */
    public function resolveAssignmentPartnerLink(int $technicianId, ?int $preferredPartnerId = null): B2BPartnerTechnician
    {
        $links = $this->activeAssignmentLinksForTechnician($technicianId);

        if ($preferredPartnerId !== null && $preferredPartnerId > 0) {
            $selected = $links->first(
                fn (B2BPartnerTechnician $link): bool => (int) $link->partner_id === $preferredPartnerId,
            );

            if (! $selected instanceof B2BPartnerTechnician) {
                throw ValidationException::withMessages([
                    'b2b_partner_id' => 'Seçilen partner, bu ustanın aktif iş kartı kapsamına bağlı değil.',
                ]);
            }

            return $selected;
        }

        if ($links->count() !== 1) {
            throw ValidationException::withMessages([
                'b2b_partner_id' => $links->isEmpty()
                    ? 'Seçilen ustanın aktif çilingir partner bağlantısı bulunamadı.'
                    : 'Ustanın birden fazla aktif partner bağlantısı var. İş kartı kapsamını açıkça seçin.',
            ]);
        }

        return $links->firstOrFail();
    }

    public function activeAssignmentLink(TechnicalServiceRequest $request): ?B2BPartnerTechnician
    {
        $technicianId = (int) ($request->technical_service_technician_id ?? 0);
        if ($technicianId <= 0) {
            return null;
        }

        $offer = $request->relationLoaded('latestAssignmentOffer')
            ? $request->latestAssignmentOffer
            : TechnicalServiceAssignmentOffer::query()
                ->where('technical_service_request_id', $request->id)
                ->where('technical_service_technician_id', $technicianId)
                ->whereIn('status', [
                    TechnicalServiceAssignmentOffer::STATUS_SENT,
                    TechnicalServiceAssignmentOffer::STATUS_ACCEPTED,
                    TechnicalServiceAssignmentOffer::STATUS_REVISED,
                ])
                ->latest('id')
                ->first();
        $metadata = $offer instanceof TechnicalServiceAssignmentOffer && is_array($offer->metadata)
            ? $offer->metadata
            : [];
        $boundPartnerId = data_get($metadata, 'assignment_partner_id');
        $boundLinkId = data_get($metadata, 'assignment_partner_technician_link_id');
        $links = $this->activeAssignmentLinksForTechnician($technicianId);

        if (! is_numeric($boundPartnerId) && ! is_numeric($boundLinkId) && $links->count() === 1) {
            return $links->first();
        }

        if (! is_numeric($boundPartnerId) && ! is_numeric($boundLinkId)) {
            $archive = TechnicalServiceAssignmentArchive::query()
                ->where('technical_service_request_id', $request->id)
                ->where('new_technician_id', $technicianId)
                ->whereNotNull('new_partner_id')
                ->latest('id')
                ->first();
            $boundPartnerId = $archive?->new_partner_id;
        }

        if (is_numeric($boundPartnerId) || is_numeric($boundLinkId)) {
            return $links->first(function (B2BPartnerTechnician $link) use ($boundPartnerId, $boundLinkId): bool {
                if (is_numeric($boundLinkId) && (int) $link->id !== (int) $boundLinkId) {
                    return false;
                }

                return ! is_numeric($boundPartnerId) || (int) $link->partner_id === (int) $boundPartnerId;
            });
        }

        return $links->count() === 1 ? $links->first() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function technicianJobCardContext(
        TechnicalServiceRequest $request,
        ?B2BPartnerTechnician $assignmentLink = null,
    ): array {
        $technicianId = (int) ($request->technical_service_technician_id ?? 0);
        if ($technicianId <= 0) {
            return $this->blockedJobCardContext($request, 'active_assignment_missing', 'Aktif usta ataması bulunamadı.');
        }

        $link = $assignmentLink ?? $this->activeAssignmentLink($request);
        if ($link instanceof B2BPartnerTechnician
            && ((int) $link->technical_service_technician_id !== $technicianId || ! $this->isActiveLocksmithLink($link))
        ) {
            $link = null;
        }
        if (! $link instanceof B2BPartnerTechnician) {
            $linkCount = $this->activeAssignmentLinksForTechnician($technicianId)->count();

            return $this->blockedJobCardContext(
                $request,
                $linkCount > 1 ? 'active_assignment_partner_ambiguous' : 'active_assignment_partner_missing',
                $linkCount > 1
                    ? 'Aktif atamanın partner kapsamı belirsiz. Usta iş kartı bağlantısı üretilemez.'
                    : 'Aktif atamaya bağlı çilingir partneri bulunamadı.',
            );
        }

        $canonicalQuery = http_build_query([
            'partner_id' => (int) $link->partner_id,
            'job_id' => (int) $request->id,
        ]);
        $opsSupportQuery = http_build_query([
            'partner_id' => (int) $link->partner_id,
            'technician_id' => $technicianId,
            'job_id' => (int) $request->id,
        ]);
        $canonicalPath = '/partner/service-jobs?'.$canonicalQuery;
        $publicOriginProfile = PartnerPortalPublicUrl::profile();
        $publicUrlReady = (bool) ($publicOriginProfile['ready'] ?? false);
        $publicUrlBlockerCode = $publicUrlReady
            ? null
            : (is_string($publicOriginProfile['blocker_code'] ?? null)
                ? $publicOriginProfile['blocker_code']
                : 'PUBLIC_ORIGIN_MISSING_OR_INVALID');
        $publicUrlBlockerMessage = $publicUrlReady
            ? null
            : (is_string($publicOriginProfile['blocker_message'] ?? null)
                ? $publicOriginProfile['blocker_message']
                : 'Seçili environment profile için geçerli public origin tanımlı değil.');

        return [
            'ready' => $publicUrlReady,
            'blocker_code' => $publicUrlBlockerCode,
            'blocker_message' => $publicUrlBlockerMessage,
            'partner_id' => (int) $link->partner_id,
            'technician_id' => $technicianId,
            'partner_technician_link_id' => (int) $link->id,
            'canonical_path' => $canonicalPath,
            'short_path' => '/pj/'.(int) $request->id,
            'canonical_url' => $publicUrlReady ? PartnerPortalPublicUrl::url($canonicalPath) : null,
            'ops_support_url' => '/technical-service/ops-support/service-jobs?'.$opsSupportQuery,
            'preview_url' => '/panel/b2b/partners/'.(int) $link->partner_id.'/portal-preview?'.http_build_query([
                'view' => 'service-jobs',
                'job_id' => (int) $request->id,
            ]),
        ];
    }

    public function canonicalTechnicianJobCardUrl(TechnicalServiceRequest $request): ?string
    {
        $context = $this->technicianJobCardContext($request);

        return ($context['ready'] ?? false) === true
            ? (string) $context['canonical_url']
            : null;
    }

    public function portalTechnicianId(User $user, B2BPartner $partner): ?int
    {
        try {
            return $this->resolveAuthenticatedTechnicianOrFail($user, $partner);
        } catch (AuthorizationException) {
            return null;
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function resolveAuthenticatedTechnicianOrFail(User $user, B2BPartner $partner): int
    {
        if (! (bool) $user->aktif) {
            throw new AuthorizationException('Portal kullanıcısı aktif değil.');
        }

        $profiles = B2BPartnerUserProfile::query()
            ->where('user_id', $user->id)
            ->where('partner_id', $partner->id)
            ->where('active', true)
            ->get();
        if ($profiles->count() !== 1) {
            throw new AuthorizationException('Portal kullanıcısının tekil aktif partner profili bulunamadı.');
        }

        $profileTechnicianId = data_get($profiles->first()?->metadata, 'technical_service_technician_id');
        if (! is_numeric($profileTechnicianId) || (int) $profileTechnicianId <= 0) {
            throw new AuthorizationException('Portal kullanıcısının açık usta eşlemesi bulunamadı.');
        }

        $technicianId = (int) $profileTechnicianId;
        $link = $this->activeAssignmentLinksForTechnician($technicianId)
            ->first(fn (B2BPartnerTechnician $candidate): bool => (int) $candidate->partner_id === (int) $partner->id);
        if (! $link instanceof B2BPartnerTechnician || ! $this->isActiveLocksmithLink($link)) {
            throw new AuthorizationException('Portal kullanıcısının usta eşlemesi bu partner için aktif değil.');
        }

        return $technicianId;
    }

    public function requestBelongsToPartner(TechnicalServiceRequest $request, B2BPartner $partner): bool
    {
        $link = $this->activeAssignmentLink($request);

        return $link instanceof B2BPartnerTechnician
            && (int) $link->partner_id === (int) $partner->id;
    }

    /**
     * @return Collection<int, TechnicalServiceRequest>
     */
    public function filterForAssignmentPartner(Collection $requests, B2BPartner $partner): Collection
    {
        return $requests
            ->filter(fn (TechnicalServiceRequest $request): bool => $this->requestBelongsToPartner($request, $partner))
            ->values();
    }

    /**
     * @throws AuthorizationException
     */
    public function assertCanReceivePart(
        User $user,
        TechnicalServiceRequest $request,
        TechnicalServicePartRequest $partRequest,
        ?int $requestedPartnerId = null,
        ?int $requestedTechnicianId = null,
    ): B2BPartner {
        if ((int) $partRequest->technical_service_request_id !== (int) $request->id) {
            throw new AuthorizationException('Parça kaydı istenen iş kapsamına ait değil.');
        }

        $expectedRootId = (int) ($request->parent_request_id ?: $request->id);
        if ((int) $partRequest->root_request_id !== $expectedRootId) {
            throw new AuthorizationException('Parça kaydının ana talep kapsamı eşleşmiyor.');
        }

        $parentPartner = $this->assertAssignedServiceJobScope(
            $user,
            $request,
            $requestedPartnerId,
            $requestedTechnicianId,
        );

        if (! is_numeric($partRequest->service_visit_request_id)) {
            return $parentPartner;
        }

        $serviceVisit = TechnicalServiceRequest::query()
            ->whereKey((int) $partRequest->service_visit_request_id)
            ->where('parent_request_id', $request->id)
            ->where('source_part_request_id', $partRequest->id)
            ->first();
        if (! $serviceVisit instanceof TechnicalServiceRequest
            || trim((string) $serviceVisit->root_mrn) !== trim((string) ($request->root_mrn ?: $request->mrn))
        ) {
            throw new AuthorizationException('Parça kaydının SRV ilişkisi doğrulanamadı.');
        }

        $requestLink = $this->activeAssignmentLink($request);
        $serviceVisitLink = $this->activeAssignmentLink($serviceVisit);
        if (! $requestLink instanceof B2BPartnerTechnician
            || ! $serviceVisitLink instanceof B2BPartnerTechnician
            || (int) $requestLink->partner_id !== (int) $serviceVisitLink->partner_id
            || (int) $requestLink->technical_service_technician_id !== (int) $serviceVisitLink->technical_service_technician_id
        ) {
            throw new AuthorizationException('Parça ve SRV aktif atama kapsamları eşleşmiyor.');
        }

        $partner = $this->assertAssignedServiceJobScope(
            $user,
            $serviceVisit,
            $requestedPartnerId,
            $requestedTechnicianId,
        );
        if ((int) $partner->id !== (int) $requestLink->partner_id) {
            throw new AuthorizationException('Parça kaydının partner kapsamı eşleşmiyor.');
        }
        if ((int) $partner->id !== (int) $parentPartner->id) {
            throw new AuthorizationException('Parça ve SRV yetkili partner kapsamları eşleşmiyor.');
        }

        return $partner;
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function opsSupportTechnicianOptions(): array
    {
        return B2BPartnerTechnician::query()
            ->active()
            ->whereIn('relationship_type', ['owner', 'field_technician'])
            ->whereHas('partner', fn (Builder $query): Builder => $query->where('active', true))
            ->with(['partner.capabilities', 'technician'])
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->filter(fn (B2BPartnerTechnician $link): bool => $link->partner?->hasCapability(B2BPartner::TYPE_LOCKSMITH) === true
                && $link->technician !== null)
            ->map(fn (B2BPartnerTechnician $link): array => [
                'partner_id' => (int) $link->partner_id,
                'technician_id' => (int) $link->technical_service_technician_id,
                'partner_technician_link_id' => (int) $link->id,
                'partner_name' => (string) $link->partner?->display_name,
                'technician_name' => (string) $link->technician?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{link:B2BPartnerTechnician,partner:B2BPartner,technician_id:int}
     *
     * @throws AuthorizationException
     */
    public function assertOpsSupportSelection(
        ?int $partnerId,
        ?int $technicianId,
        ?TechnicalServiceRequest $request = null,
    ): array {
        if ($request instanceof TechnicalServiceRequest) {
            $link = $this->activeAssignmentLink($request);
            if (! $link instanceof B2BPartnerTechnician) {
                throw new AuthorizationException('Bu iş için doğrulanmış aktif usta/partner ataması yok.');
            }

            if (($partnerId !== null && $partnerId > 0 && (int) $link->partner_id !== $partnerId)
                || ($technicianId !== null && $technicianId > 0 && (int) $link->technical_service_technician_id !== $technicianId)
            ) {
                throw new AuthorizationException('OPS destek kapsamı aktif atamayla eşleşmiyor.');
            }
        } else {
            if (($partnerId ?? 0) <= 0 || ($technicianId ?? 0) <= 0) {
                throw new AuthorizationException('OPS destek modu için partner ve usta seçimi zorunludur.');
            }

            $link = $this->activeAssignmentLinksForTechnician((int) $technicianId)
                ->first(fn (B2BPartnerTechnician $candidate): bool => (int) $candidate->partner_id === (int) $partnerId);
            if (! $link instanceof B2BPartnerTechnician) {
                throw new AuthorizationException('Seçilen usta ve partner arasında aktif iş kartı kapsamı yok.');
            }
        }

        $partner = $link->partner;
        if (! $partner instanceof B2BPartner) {
            throw new AuthorizationException('Seçilen partner bulunamadı.');
        }

        return [
            'link' => $link,
            'partner' => $partner,
            'technician_id' => (int) $link->technical_service_technician_id,
        ];
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
    public function assertCanViewServiceJob(
        User $user,
        TechnicalServiceRequest $request,
        ?int $requestedPartnerId = null,
        ?int $requestedTechnicianId = null,
    ): B2BPartner {
        $isCancelledOrReview = $this->isCancelled($request) || $this->isCancellationReview($request);
        if (! $isCancelledOrReview && $this->hasNonCancelledChildServiceVisit($request) && ! $this->isCompletedHistoryJob($request)) {
            throw new AuthorizationException('Bu ana talebin SRV kaydi var; partner islerinde SRV karti gorunur.');
        }

        if (! $isCancelledOrReview && $this->hasActiveRejectedAction($request)) {
            throw new AuthorizationException('Bu iş usta reddi sonrası operasyon incelemesinde.');
        }

        return $this->assertAssignedServiceJobScope(
            $user,
            $request,
            $requestedPartnerId,
            $requestedTechnicianId,
        );
    }

    /**
     * Authorize the exact active assignment without applying portal card visibility rules.
     *
     * @throws AuthorizationException
     */
    public function assertAssignedServiceJobScope(
        User $user,
        TechnicalServiceRequest $request,
        ?int $requestedPartnerId = null,
        ?int $requestedTechnicianId = null,
    ): B2BPartner {
        $link = $this->activeAssignmentLink($request);
        if (! $link instanceof B2BPartnerTechnician) {
            throw new AuthorizationException('Bu iş için doğrulanmış aktif usta/partner bağlantısı yok.');
        }

        if (($requestedPartnerId !== null && $requestedPartnerId > 0 && (int) $link->partner_id !== $requestedPartnerId)
            || ($requestedTechnicianId !== null && $requestedTechnicianId > 0 && (int) $link->technical_service_technician_id !== $requestedTechnicianId)
        ) {
            throw new AuthorizationException('URL kapsamı aktif usta atamasıyla eşleşmiyor.');
        }

        $partner = $this->visibleLocksmithPartnersForPortal($user)
            ->first(fn (B2BPartner $candidate): bool => (int) $candidate->id === (int) $link->partner_id);

        if (! $partner instanceof B2BPartner) {
            throw new AuthorizationException('Bu işe erişim yetkiniz yok.');
        }

        $portalTechnicianId = $this->resolveAuthenticatedTechnicianOrFail($user, $partner);
        if ($portalTechnicianId !== (int) $link->technical_service_technician_id) {
            throw new AuthorizationException('Bu iş başka bir ustanın aktif kapsamındadır.');
        }

        return $partner;
    }

    public function serviceJobsQuery(B2BPartner $partner): Builder
    {
        return TechnicalServiceRequest::query()
            ->with('latestAssignmentOffer')
            ->whereIn('technical_service_technician_id', $this->activeTechnicianIds($partner))
            ->whereNull('cancelled_at')
            ->whereDoesntHave('childRequests', fn (Builder $query): Builder => $this->nonCancelledChildServiceVisitQuery($query))
            ->whereNotIn('status', ['İptal', 'Iptal', 'Ä°ptal'])
            ->whereNotIn('workflow_status', ['İptal', 'Iptal', 'Ä°ptal'])
            ->where(fn (Builder $query): Builder => $this->notCancellationReviewQuery($query))
            ->whereDoesntHave('partnerJobActions', fn (Builder $query): Builder => $query
                ->where('action', TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED)
                ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW));
    }

    public function serviceJobsQueryForTechnician(B2BPartner $partner, int $technicianId): Builder
    {
        $this->assertPartnerTechnicianLink($partner, $technicianId);

        return $this->serviceJobsQuery($partner)
            ->where('technical_service_technician_id', $technicianId);
    }

    public function completedHistoryJobsQuery(B2BPartner $partner): Builder
    {
        return TechnicalServiceRequest::query()
            ->with('latestAssignmentOffer')
            ->whereIn('technical_service_technician_id', $this->activeTechnicianIds($partner))
            ->whereNull('cancelled_at')
            ->where(fn (Builder $query): Builder => $this->completedHistoryQuery($query))
            ->whereNotIn('status', ['İptal', 'Iptal', 'Ä°ptal'])
            ->whereNotIn('workflow_status', ['İptal', 'Iptal', 'Ä°ptal'])
            ->where(fn (Builder $query): Builder => $this->notCancellationReviewQuery($query))
            ->whereDoesntHave('partnerJobActions', fn (Builder $query): Builder => $query
                ->where('action', TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED)
                ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW));
    }

    public function completedHistoryJobsQueryForTechnician(B2BPartner $partner, int $technicianId): Builder
    {
        $this->assertPartnerTechnicianLink($partner, $technicianId);

        return $this->completedHistoryJobsQuery($partner)
            ->where('technical_service_technician_id', $technicianId);
    }

    public function shouldHideActiveParentWithChild(TechnicalServiceRequest $request): bool
    {
        return $this->hasNonCancelledChildServiceVisit($request)
            && ! $this->isCompletedHistoryJob($request);
    }

    public function isCompletedHistoryJob(TechnicalServiceRequest $request): bool
    {
        return $request->completed_at !== null
            || $request->installation_completed_at !== null;
    }

    private function isCancelled(TechnicalServiceRequest $request): bool
    {
        return $request->cancelled_at !== null
            || in_array($request->status, ['İptal', 'Iptal', 'Ä°ptal'], true)
            || in_array($request->workflow_status, ['İptal', 'Iptal', 'Ä°ptal'], true);
    }

    private function isCancellationReview(TechnicalServiceRequest $request): bool
    {
        if ($this->isCancelled($request)) {
            return false;
        }

        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $review = $operationControl[TechnicalServiceWorkflowService::CANCELLATION_REVIEW_KEY] ?? $operationControl['cancellation_review'] ?? null;
        $reviewStatus = is_array($review) ? (string) ($review['status'] ?? '') : '';

        return in_array($reviewStatus, ['pending', 'review'], true)
            || (string) $request->pending_reason === TechnicalServiceWorkflowService::CANCELLATION_REVIEW_PENDING_REASON;
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

    private function completedHistoryQuery(Builder $query): Builder
    {
        return $query
            ->whereNotNull('completed_at')
            ->orWhereNotNull('installation_completed_at');
    }

    private function notCancellationReviewQuery(Builder $query): Builder
    {
        return $query
            ->whereNull('pending_reason')
            ->orWhere('pending_reason', '!=', TechnicalServiceWorkflowService::CANCELLATION_REVIEW_PENDING_REASON);
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

    private function assertPartnerTechnicianLink(B2BPartner $partner, int $technicianId): void
    {
        $linked = $this->activeAssignmentLinksForTechnician($technicianId)
            ->contains(fn (B2BPartnerTechnician $link): bool => (int) $link->partner_id === (int) $partner->id);

        if (! $linked) {
            throw new AuthorizationException('Seçilen usta bu partnerin aktif iş kartı kapsamında değil.');
        }
    }

    private function isActiveLocksmithLink(B2BPartnerTechnician $link): bool
    {
        $link->loadMissing(['partner.capabilities', 'technician']);

        return (bool) $link->active
            && in_array($link->relationship_type, ['owner', 'field_technician'], true)
            && $link->technician?->active === true
            && $link->technician?->deleted_at === null
            && $link->partner?->active === true
            && $link->partner?->hasCapability(B2BPartner::TYPE_LOCKSMITH) === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function blockedJobCardContext(TechnicalServiceRequest $request, string $code, string $message): array
    {
        return [
            'ready' => false,
            'blocker_code' => $code,
            'blocker_message' => $message,
            'partner_id' => null,
            'technician_id' => $request->technical_service_technician_id !== null
                ? (int) $request->technical_service_technician_id
                : null,
            'partner_technician_link_id' => null,
            'canonical_path' => null,
            'short_path' => null,
            'canonical_url' => null,
            'ops_support_url' => null,
            'preview_url' => null,
        ];
    }
}

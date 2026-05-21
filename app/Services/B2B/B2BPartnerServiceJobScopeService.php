<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\TechnicalServiceRequest;
use Illuminate\Database\Eloquent\Builder;

class B2BPartnerServiceJobScopeService
{
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

    public function serviceJobsQuery(B2BPartner $partner): Builder
    {
        return TechnicalServiceRequest::query()
            ->whereIn('technical_service_technician_id', $this->activeTechnicianIds($partner));
    }
}

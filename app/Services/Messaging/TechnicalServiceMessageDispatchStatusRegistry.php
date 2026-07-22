<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;

class TechnicalServiceMessageDispatchStatusRegistry
{
    /**
     * @return array<int, string>
     */
    public function statuses(): array
    {
        return [
            TechnicalServiceMessageDispatch::STATUS_SUPPRESSED,
            TechnicalServiceMessageDispatch::STATUS_QUEUED,
            TechnicalServiceMessageDispatch::STATUS_SENDING,
            TechnicalServiceMessageDispatch::STATUS_SENT,
            TechnicalServiceMessageDispatch::STATUS_FAILED,
            TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED,
            TechnicalServiceMessageDispatch::STATUS_CANCELLED,
            TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
            TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED,
            TechnicalServiceMessageDispatch::STATUS_COOLDOWN_BLOCKED,
            TechnicalServiceMessageDispatch::STATUS_BLOCKED,
            TechnicalServiceMessageDispatch::STATUS_TEST_SENT,
            TechnicalServiceMessageDispatch::STATUS_TEST_FAILED,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function successStatuses(): array
    {
        return TechnicalServiceMessageDispatch::SUCCESS_STATUSES;
    }

    /**
     * @return array<int, string>
     */
    public function duplicateBlockingStatuses(): array
    {
        return TechnicalServiceMessageDispatch::DUPLICATE_BLOCKING_STATUSES;
    }
}

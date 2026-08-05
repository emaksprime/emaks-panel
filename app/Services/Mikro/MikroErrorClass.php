<?php

namespace App\Services\Mikro;

final class MikroErrorClass
{
    public const NONE = 'NONE';

    public const FEATURE_DISABLED = 'FEATURE_DISABLED';

    public const CONTRACT_UNVERIFIED = 'CONTRACT_UNVERIFIED';

    public const BODY_CONTRACT_UNVERIFIED = 'BODY_CONTRACT_UNVERIFIED';

    public const MISSING_CREDENTIALS = 'MISSING_CREDENTIALS';

    public const NON_LOCAL_EXECUTION = 'NON_LOCAL_EXECUTION';

    public const OPERATION_BLOCKED = 'OPERATION_BLOCKED';

    public const WRITE_OPERATION_FORBIDDEN = 'WRITE_OPERATION_FORBIDDEN';

    public const REQUEST_BUDGET_EXCEEDED = 'REQUEST_BUDGET_EXCEEDED';

    public const HTTP_CONNECTION = 'HTTP_CONNECTION';

    public const HTTP_CLIENT = 'HTTP_CLIENT';

    public const HTTP_SERVER = 'HTTP_SERVER';

    public const PROVIDER_REJECTED = 'PROVIDER_REJECTED';

    public const INVALID_RESPONSE = 'INVALID_RESPONSE';

    public const BUSINESS_WRITE_DETECTED = 'BUSINESS_WRITE_DETECTED';

    public const UNKNOWN = 'UNKNOWN';

    private function __construct() {}
}

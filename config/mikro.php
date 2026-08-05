<?php

return [
    'real_canary_enabled' => env('MIKRO_REAL_CANARY_ENABLED', false),
    'real_health_probe_enabled' => env('MIKRO_REAL_HEALTH_PROBE_ENABLED', false),
    'allowed_canary_environments' => ['local', 'testing'],
    'health' => [
        'method' => 'GET',
        'endpoint' => '/Api/APIMethods/HealthCheck',
        'contract_status' => 'BODY_CONTRACT_UNVERIFIED',
        'execution_state' => 'HEALTH_CONTRACT_READY_NOT_EXECUTED',
    ],
    'stock_list' => [
        'method' => 'POST',
        'endpoint' => '/Api/APIMethods/StokListesiV2',
        'contract_status' => 'BLOCKED_PENDING_CANONICAL_CONTRACT',
        'maximum_canary_rows' => 5,
    ],
];

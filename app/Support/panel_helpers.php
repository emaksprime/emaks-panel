<?php

use App\Services\PanelAccessService;

if (! function_exists('user_can_access')) {
    function user_can_access(int $userId, string $resourceCode): bool
    {
        return app(PanelAccessService::class)->userCanAccess($userId, $resourceCode);
    }
}

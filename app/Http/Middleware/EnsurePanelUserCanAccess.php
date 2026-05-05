<?php

namespace App\Http\Middleware;

use App\Services\PanelAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePanelUserCanAccess
{
    public function __construct(
        private readonly PanelAccessService $access,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$resourceCodes): Response
    {
        $canAccess = collect($resourceCodes)
            ->filter()
            ->contains(fn (string $resourceCode) => $this->access->userCanAccess($request->user(), $resourceCode));

        abort_unless($canAccess, 403);

        return $next($request);
    }
}

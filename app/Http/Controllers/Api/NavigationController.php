<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PanelNavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavigationController extends Controller
{
    public function __invoke(Request $request, PanelNavigationService $navigation): JsonResponse
    {
        return response()->json(
            $navigation->sharedForUser($request->user(), '/'.ltrim($request->path(), '/')),
        );
    }
}

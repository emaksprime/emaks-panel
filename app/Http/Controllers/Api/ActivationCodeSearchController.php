<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivationCodeSearchRequest;
use App\Services\ActivationCodeSearchService;
use Illuminate\Http\JsonResponse;

class ActivationCodeSearchController extends Controller
{
    public function __invoke(
        ActivationCodeSearchRequest $request,
        ActivationCodeSearchService $searchService,
    ): JsonResponse {
        return response()->json(
            $searchService->search((string) $request->validated('query')),
        );
    }
}

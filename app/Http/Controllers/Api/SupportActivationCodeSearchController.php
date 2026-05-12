<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportActivationCodeSearchRequest;
use App\Services\SupportActivationCodeService;
use Illuminate\Http\JsonResponse;

class SupportActivationCodeSearchController extends Controller
{
    public function __invoke(
        SupportActivationCodeSearchRequest $request,
        SupportActivationCodeService $service,
    ): JsonResponse {
        return response()->json(
            $service->search((string) $request->validated('query')),
        );
    }
}

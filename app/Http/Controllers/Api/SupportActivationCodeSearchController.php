<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportActivationCodeSearchRequest;
use App\Services\SupportActivationCodeService;
use App\Services\SupportGuideService;
use Illuminate\Http\JsonResponse;

class SupportActivationCodeSearchController extends Controller
{
    public function __invoke(
        SupportActivationCodeSearchRequest $request,
        SupportActivationCodeService $service,
        SupportGuideService $guides,
    ): JsonResponse {
        $result = $service->search((string) $request->validated('query'));
        $result['items'] = collect($result['items'])
            ->map(function (array $item) use ($guides): array {
                $item['matching_guide'] = $guides->matchingGuideForActivation($item);

                return $item;
            })
            ->all();

        return response()->json($result);
    }
}

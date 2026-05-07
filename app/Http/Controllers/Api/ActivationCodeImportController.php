<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportActivationCodeCsvRequest;
use App\Services\ActivationCodeImportService;
use Illuminate\Http\JsonResponse;

class ActivationCodeImportController extends Controller
{
    public function __invoke(
        ImportActivationCodeCsvRequest $request,
        ActivationCodeImportService $importService,
    ): JsonResponse {
        $result = $importService->import($request->file('file'));

        $status = count($result['errors']) > 0 && ($result['created_count'] ?? 0) === 0 && ($result['updated_count'] ?? 0) === 0
            ? 422
            : 200;

        return response()->json($result, $status);
    }
}

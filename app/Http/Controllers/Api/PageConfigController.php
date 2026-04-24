<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\SalesMainPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageConfigController extends Controller
{
    public function __invoke(Request $request, string $code, SalesMainPageService $salesMain): JsonResponse
    {
        if (in_array($code, ['sales-main', 'sales_main'], true)) {
            return response()->json($salesMain->config($request->user()));
        }

        $page = Page::query()
            ->with(['pageConfig.dataSource'])
            ->where('code', str_replace('-', '_', $code))
            ->firstOrFail();

        return response()->json([
            'page' => [
                'title' => $page->name,
                'description' => $page->description,
                'routePath' => $page->route,
                'component' => $page->component,
            ],
            'layout' => $page->pageConfig?->layout_json ?? [],
            'filters' => $page->pageConfig?->filters_json ?? [],
            'dataSource' => [
                'slug' => $page->pageConfig?->dataSource?->code,
                'status' => $page->pageConfig?->dataSource?->active ? 'active' : 'inactive',
            ],
        ]);
    }
}

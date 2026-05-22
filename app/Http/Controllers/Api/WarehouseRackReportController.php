<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WarehouseRackReportRequest;
use App\Http\Requests\WarehouseSerialHistoryRequest;
use App\Services\WarehouseRackReportService;
use App\Services\WarehouseSerialHistoryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseRackReportController extends Controller
{
    public function __construct(
        private readonly WarehouseRackReportService $reports,
        private readonly WarehouseSerialHistoryService $serialHistory,
    ) {
    }

    public function index(WarehouseRackReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->report($request->validated()));
    }

    public function export(WarehouseRackReportRequest $request): JsonResponse|StreamedResponse
    {
        $rows = $this->reports->exportRows($request->validated());

        if ($rows->count() > WarehouseRackReportService::EXPORT_LIMIT) {
            return response()->json([
                'message' => 'Rapor satır sayısı çok yüksek. Lütfen filtre kullanın.',
            ], 422);
        }

        $filename = 'raf-raporu-'.now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            echo "\xEF\xBB\xBF";

            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fputcsv($output, [
                'Depo',
                'Raf',
                'Tip',
                'Kategori Adı',
                'Stok Kodu',
                'Stok Adı',
                'Seri No',
                'Miktar',
                'Durum',
                'Son İşlem No',
                'Son Raf Hareketi',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($output, $this->reports->csvRow($row), ';');
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function serialHistory(WarehouseSerialHistoryRequest $request): JsonResponse
    {
        return response()->json($this->serialHistory->history($request->validated('serial_no')));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTechnicalServiceRequest;
use App\Http\Requests\StoreTechnicalServiceRequest;
use App\Http\Requests\UpdateTechnicalServiceRequest;
use App\Http\Requests\UpdateTechnicalServiceRequestStatus;
use App\Models\TechnicalServiceRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TechnicalServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:64'],
            'service_type' => ['nullable', 'string', 'max:128'],
            'priority' => ['nullable', 'string', 'max:64'],
            'risk_level' => ['nullable', 'string', 'max:64'],
            'technician_name' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = TechnicalServiceRequest::query();

        if (! empty($filters['search'])) {
            $query->where(function ($query) use ($filters) {
                $query->where('mrn', 'ilike', "%{$filters['search']}%")
                    ->orWhere('customer_name', 'ilike', "%{$filters['search']}%")
                    ->orWhere('product_name', 'ilike', "%{$filters['search']}%")
                    ->orWhere('serial_number', 'ilike', "%{$filters['search']}%")
                    ->orWhere('technician_name', 'ilike', "%{$filters['search']}%");
            });
        }

        foreach (['status', 'service_type', 'priority', 'risk_level', 'technician_name'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $limit = $filters['limit'] ?? 25;

        $paginator = $query
            ->orderByDesc('scheduled_at')
            ->orderByDesc('created_at')
            ->paginate($limit);

        return response()->json([
            'items' => $paginator->items(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        return response()->json([
            'request' => $technicalServiceRequest->load('events'),
        ]);
    }

    public function store(StoreTechnicalServiceRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();

        $payload['status'] = $payload['status'] ?? 'Yeni';
        $payload['priority'] = $payload['priority'] ?? 'Orta';
        $payload['risk_level'] = $payload['risk_level'] ?? 'Orta';
        $payload['created_by_user_id'] = $user?->id;
        $payload['updated_by_user_id'] = $user?->id;

        $requestModel = DB::transaction(function () use ($payload, $user) {
            $payload['mrn'] = $this->generateMrn();

            $requestModel = TechnicalServiceRequest::create($payload);

            $requestModel->events()->create([
                'event_type' => 'created',
                'title' => 'Talep oluşturuldu',
                'note' => 'Teknik servis talebi oluşturuldu.',
                'from_status' => null,
                'to_status' => $requestModel->status,
                'author_user_id' => $user?->id,
                'metadata' => [
                    'source_channel' => $payload['source_channel'] ?? null,
                ],
            ]);

            return $requestModel;
        });

        return response()->json(['request' => $requestModel->load('events')], 201);
    }

    public function update(UpdateTechnicalServiceRequest $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        $payload['updated_by_user_id'] = $request->user()?->id;

        $technicalServiceRequest->update($payload);

        return response()->json(['request' => $technicalServiceRequest]);
    }

    public function updateStatus(UpdateTechnicalServiceRequestStatus $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        $previousStatus = $technicalServiceRequest->status;
        $technicalServiceRequest->status = $payload['status'];
        $technicalServiceRequest->updated_by_user_id = $request->user()?->id;

        if ($payload['status'] === 'Tamamlandı') {
            $technicalServiceRequest->completed_at = now();
        }

        if ($payload['status'] === 'İptal') {
            $technicalServiceRequest->cancelled_at = now();
        }

        $technicalServiceRequest->save();

        $technicalServiceRequest->events()->create([
            'event_type' => 'status_change',
            'title' => 'Durum değişti',
            'note' => $payload['note'] ?? null,
            'from_status' => $previousStatus,
            'to_status' => $payload['status'],
            'author_user_id' => $request->user()?->id,
            'metadata' => [],
        ]);

        return response()->json(['request' => $technicalServiceRequest]);
    }

    public function assign(AssignTechnicalServiceRequest $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        $technicalServiceRequest->technician_name = $payload['technician_name'];
        $technicalServiceRequest->updated_by_user_id = $request->user()?->id;
        $technicalServiceRequest->save();

        $technicalServiceRequest->events()->create([
            'event_type' => 'assignment',
            'title' => 'Teknisyen atandı',
            'note' => $payload['note'] ?? null,
            'from_status' => null,
            'to_status' => null,
            'author_user_id' => $request->user()?->id,
            'metadata' => ['technician_name' => $payload['technician_name']],
        ]);

        return response()->json(['request' => $technicalServiceRequest]);
    }

    public function summary(): JsonResponse
    {
        $total = TechnicalServiceRequest::count();

        $statusCounts = TechnicalServiceRequest::query()
            ->select('status')
            ->selectRaw('count(*) as total')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status => $row->total]);

        $priorityCounts = TechnicalServiceRequest::query()
            ->select('priority')
            ->selectRaw('count(*) as total')
            ->groupBy('priority')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->priority => $row->total]);

        $riskCounts = TechnicalServiceRequest::query()
            ->select('risk_level')
            ->selectRaw('count(*) as total')
            ->groupBy('risk_level')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->risk_level => $row->total]);

        return response()->json([
            'total_requests' => $total,
            'ongoing_requests' => TechnicalServiceRequest::whereNotIn('status', ['Tamamlandı', 'İptal'])->count(),
            'status_counts' => $statusCounts,
            'priority_counts' => $priorityCounts,
            'risk_level_counts' => $riskCounts,
            'scheduled_today' => TechnicalServiceRequest::whereDate('scheduled_at', today())->count(),
        ]);
    }

    private function generateMrn(): string
    {
        $today = now()->format('Ymd');
        $last = TechnicalServiceRequest::query()
            ->where('mrn', 'like', "MRN-{$today}-%")
            ->orderByDesc('id')
            ->value('mrn');

        $sequence = 1;

        if ($last !== null && preg_match('/-(\d{4})$/', $last, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return sprintf('MRN-%s-%04d', $today, $sequence);
    }
}

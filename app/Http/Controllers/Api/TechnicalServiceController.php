<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTechnicalServiceRequest;
use App\Http\Requests\StoreTechnicalServiceRequest;
use App\Http\Requests\UpdateTechnicalServiceRequest;
use App\Http\Requests\UpdateTechnicalServiceRequestStatus;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
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
            'request' => $technicalServiceRequest->load([
                'events' => function ($query) {
                    $query->orderBy('created_at');
                },
            ]),
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

        $scheduleNote = $payload['schedule_note'] ?? null;
        unset($payload['schedule_note']);

        $travelSummary = null;
        if (array_key_exists('travel_round_trip_km', $payload) && $payload['travel_round_trip_km'] !== null) {
            $travelSummary = $this->calculateTravelCosts((float) $payload['travel_round_trip_km']);
            $payload = array_merge($payload, $travelSummary);
        }

        $previousStatus = $technicalServiceRequest->status;
        $isScheduling = array_key_exists('scheduled_at', $payload) && ! empty($payload['scheduled_at']);

        if ($isScheduling && empty($payload['status'])) {
            $payload['status'] = 'Randevulu';
        }

        $technicalServiceRequest->update($payload);

        if ($isScheduling) {
            $technicalServiceRequest->events()->create([
                'event_type' => 'scheduled',
                'title' => 'Randevu planlandı',
                'note' => $scheduleNote,
                'from_status' => $previousStatus,
                'to_status' => $technicalServiceRequest->status,
                'author_user_id' => $request->user()?->id,
                'metadata' => $travelSummary ? ['travel' => $travelSummary] : [],
            ]);
        }

        return response()->json(['request' => $technicalServiceRequest]);
    }

    public function updateStatus(UpdateTechnicalServiceRequestStatus $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        $previousStatus = $technicalServiceRequest->status;
        $technicalServiceRequest->status = $payload['status'];
        $technicalServiceRequest->updated_by_user_id = $request->user()?->id;

        if ($payload['status'] === 'Yeni') {
            $technicalServiceRequest->completed_at = null;
            $technicalServiceRequest->cancelled_at = null;
        } elseif ($payload['status'] === 'Tamamlandı') {
            $technicalServiceRequest->completed_at = now();
            $technicalServiceRequest->cancelled_at = null;
            $technicalServiceRequest->resolution_notes = $payload['resolution_notes'] ?? $payload['note'] ?? null;
        } elseif ($payload['status'] === 'İptal') {
            $technicalServiceRequest->completed_at = null;
            $technicalServiceRequest->cancelled_at = now();
        }

        $technicalServiceRequest->save();

        $eventTitle = 'Durum değişti';
        if ($payload['status'] === 'Yeni' && in_array($previousStatus, ['Tamamlandı', 'İptal'], true)) {
            $eventTitle = 'Talep yeniden açıldı';
        }

        $technicalServiceRequest->events()->create([
            'event_type' => 'status_change',
            'title' => $eventTitle,
            'note' => $payload['note'] ?? ($payload['resolution_notes'] ?? null),
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
        $technician = isset($payload['technical_service_technician_id'])
            ? TechnicalServiceTechnician::query()->find($payload['technical_service_technician_id'])
            : null;
        $technicianName = $technician?->name ?? $payload['technician_name'];

        $technicalServiceRequest->technical_service_technician_id = $technician?->id;
        $technicalServiceRequest->technician_name = $technicianName;
        $travelSummary = $this->calculateTravelCosts((float) $payload['travel_round_trip_km']);
        $technicalServiceRequest->fill($travelSummary);
        $technicalServiceRequest->updated_by_user_id = $request->user()?->id;
        $technicalServiceRequest->save();

        $technicalServiceRequest->events()->create([
            'event_type' => 'assignment',
            'title' => 'Teknisyen atandı',
            'note' => $payload['note'] ?? null,
            'from_status' => null,
            'to_status' => null,
            'author_user_id' => $request->user()?->id,
            'metadata' => [
                'technical_service_technician_id' => $technician?->id,
                'technician_name' => $technicianName,
                'travel' => $travelSummary,
            ],
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

    /**
     * @return array<string, mixed>
     */
    private function calculateTravelCosts(float $roundTripKm): array
    {
        $roundTripKm = round(max($roundTripKm, 0), 2);
        $billableKm = round(max($roundTripKm - 30, 0), 2);
        $travelFee = round($billableKm * 10, 2);

        return [
            'travel_round_trip_km' => $roundTripKm,
            'travel_billable_km' => $billableKm,
            'travel_fee_amount' => $travelFee,
            'travel_calculation_source' => 'manual',
            'travel_calculated_at' => now(),
        ];
    }
}

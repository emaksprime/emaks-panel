<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningsPeriod;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TechnicalServiceEarningService
{
    public const PERIOD_STATUSES = ['draft', 'reviewing', 'approved', 'paid', 'locked'];
    public const EARNING_STATUSES = ['Kontrol Bekliyor', 'Ödenecek', 'Ödendi', 'İtirazlı'];

    public function calculatePeriod(int $year, int $month): TechnicalServiceEarningsPeriod
    {
        $this->validatePeriod($year, $month);

        return DB::transaction(function () use ($year, $month): TechnicalServiceEarningsPeriod {
            $period = TechnicalServiceEarningsPeriod::query()->firstOrCreate(
                ['year' => $year, 'month' => $month],
                ['status' => 'draft'],
            );

            if (in_array($period->status, ['approved', 'paid', 'locked'], true)) {
                throw ValidationException::withMessages([
                    'period' => 'Onaylı, ödenmiş veya kilitli hakediş dönemi yeniden hesaplanamaz.',
                ]);
            }

            if ($period->earnings()->where('status', 'Ödendi')->exists()) {
                throw ValidationException::withMessages([
                    'period' => 'Ödenmiş hakediş içeren dönem yeniden hesaplanamaz.',
                ]);
            }

            $period->earnings()->delete();

            $start = CarbonImmutable::create($year, $month, 1)->startOfMonth();
            $end = $start->endOfMonth();
            $requests = $this->completedRequestsForPeriod($start, $end);

            $requests
                ->groupBy(fn (TechnicalServiceRequest $request) => (string) ($request->technical_service_technician_id ?? 'unassigned'))
                ->each(function (Collection $group) use ($period): void {
                    $first = $group->first();

                    if (! $first instanceof TechnicalServiceRequest) {
                        return;
                    }

                    $earning = TechnicalServiceEarning::query()->create([
                        'period_id' => $period->id,
                        'technical_service_technician_id' => $first->technical_service_technician_id,
                        'technician_name_snapshot' => $first->technician_name ?: 'Atanmadı',
                        'city_snapshot' => $first->technicianRecord?->city ?? $first->customer_city,
                        'status' => 'Kontrol Bekliyor',
                    ]);

                    foreach ($group as $request) {
                        $amounts = $this->earningAmountsForRequest($request);
                        $laborAmount = $amounts['labor_amount'];
                        $travelFee = $amounts['travel_fee_amount'];
                        $note = $laborAmount <= 0 ? 'usta hizmet bedeli boş' : null;

                        $earning->items()->create([
                            'technical_service_request_id' => $request->id,
                            'mrn' => $request->mrn,
                            'job_date' => $this->jobDate($request),
                            'customer_city' => $request->customer_city,
                            'customer_district' => $request->customer_district,
                            'service_type' => $this->displayServiceType($request),
                            'product_name' => $request->product_name,
                            'serial_number' => $request->serial_number,
                            'labor_amount' => $laborAmount,
                            'travel_round_trip_km' => $this->money($request->travel_round_trip_km),
                            'travel_billable_km' => $this->money($request->travel_billable_km),
                            'travel_fee_amount' => $travelFee,
                            'line_total' => $laborAmount + $travelFee,
                            'note' => $note,
                        ]);
                    }

                    $this->refreshEarningTotals($earning);
                });

            $period->forceFill([
                'calculated_at' => now(),
                'status' => $period->status === 'reviewing' ? 'reviewing' : 'draft',
            ])->save();

            return $period->fresh(['earnings.items']);
        });
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listPeriodEarnings(int $year, int $month, array $filters = []): array
    {
        $this->validatePeriod($year, $month);
        $period = TechnicalServiceEarningsPeriod::query()
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if (! $period) {
            return [
                'period' => null,
                'items' => [],
                'summary' => $this->emptySummary(),
            ];
        }

        $query = TechnicalServiceEarning::query()
            ->where('period_id', $period->id)
            ->withCount('items')
            ->orderBy('technician_name_snapshot');

        if (! empty($filters['technician_id'])) {
            $query->where('technical_service_technician_id', $filters['technician_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $items = $query->get();

        return [
            'period' => $period,
            'items' => $items,
            'summary' => $this->summary($items),
        ];
    }

    public function getEarningDetail(int $id): TechnicalServiceEarning
    {
        return TechnicalServiceEarning::query()
            ->with(['period', 'items' => fn ($query) => $query->orderBy('job_date')->orderBy('mrn')])
            ->findOrFail($id);
    }

    public function updateEarningStatus(int $id, string $status, ?string $internalNote = null, ?string $disputeNote = null): TechnicalServiceEarning
    {
        if (! in_array($status, self::EARNING_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Geçersiz hakediş durumu.']);
        }

        $earning = $this->getEarningDetail($id);
        $payload = [
            'status' => $status,
            'internal_note' => $internalNote,
            'dispute_note' => $status === 'İtirazlı' ? $disputeNote : $earning->dispute_note,
        ];

        if ($status === 'Ödendi' && $earning->paid_at === null) {
            $payload['paid_at'] = now();
        }

        $earning->update($payload);
        $this->syncPeriodPaidStatus($earning->period_id);

        return $this->getEarningDetail($id);
    }

    public function markPaid(int $id): TechnicalServiceEarning
    {
        $earning = $this->getEarningDetail($id);
        $earning->update([
            'status' => 'Ödendi',
            'paid_at' => now(),
        ]);
        $this->syncPeriodPaidStatus($earning->period_id);

        return $this->getEarningDetail($id);
    }

    public function buildWhatsappText(int $id): string
    {
        $earning = $this->getEarningDetail($id);
        $monthName = CarbonImmutable::create((int) $earning->period->year, (int) $earning->period->month, 1)
            ->locale('tr')
            ->translatedFormat('F');
        $money = fn ($value): string => number_format((float) $value, 2, ',', '.');

        $lines = [
            "Merhaba {$earning->technician_name_snapshot},",
            '',
            "{$monthName} {$earning->period->year} hakediş özetiniz aşağıdaki gibidir:",
            '',
            "Toplam iş: {$earning->job_count}",
            "Montaj: {$earning->installation_count}",
            "Servis/Arıza: {$earning->service_count}",
            '',
            'Hizmet bedeli: '.$money($earning->labor_total).' TL',
            'Yol ücreti: '.$money($earning->travel_fee_total).' TL',
            'Toplam hakediş: '.$money($earning->grand_total).' TL',
            '',
            'İş detayları:',
        ];

        foreach ($earning->items as $index => $item) {
            $lines[] = sprintf(
                '%d. %s | %s | %s/%s | %s',
                $index + 1,
                $item->mrn,
                $item->job_date?->format('d.m.Y') ?? '-',
                $item->customer_city ?: '-',
                $item->customer_district ?: '-',
                $item->service_type ?: '-',
            );
            $lines[] = '   '.($item->product_name ?: '-');
            $lines[] = '   Seri No: '.($item->serial_number ?: '-');
            $lines[] = '   Hizmet: '.$money($item->labor_amount).' TL | Yol: '.$money($item->travel_fee_amount).' TL | Toplam: '.$money($item->line_total).' TL';
        }

        $lines[] = '';
        $lines[] = "Durum: {$earning->status}";
        $lines[] = 'Kontrolünüzü rica ederiz.';

        return implode("\n", $lines);
    }

    private function validatePeriod(int $year, int $month): void
    {
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw ValidationException::withMessages(['period' => 'Geçersiz ay/yıl bilgisi.']);
        }
    }

    private function completedRequestsForPeriod(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return TechnicalServiceRequest::query()
            ->with(['technicianRecord', 'latestAssignmentOffer'])
            ->whereNull('cancelled_at')
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('installation_completed_at', [$start, $end])
                    ->orWhere(function ($query) use ($start, $end) {
                        $query->whereNull('installation_completed_at')
                            ->whereBetween('completed_at', [$start, $end]);
                    });
            })
            ->orderBy('technical_service_technician_id')
            ->orderBy('installation_completed_at')
            ->orderBy('completed_at')
            ->get()
            ->filter(fn (TechnicalServiceRequest $request): bool => $this->isCompletedRequest($request)
                && ! $this->isCancelledRequest($request))
            ->values();
    }

    /**
     * @return array{labor_amount:float,travel_fee_amount:float}
     */
    private function earningAmountsForRequest(TechnicalServiceRequest $request): array
    {
        $offer = $request->latestAssignmentOffer;

        if ($offer instanceof TechnicalServiceAssignmentOffer) {
            return [
                'labor_amount' => $this->money($offer->labor_amount),
                'travel_fee_amount' => $this->money($offer->route_fee_amount),
            ];
        }

        return [
            'labor_amount' => $this->money($request->technician_payment_amount),
            'travel_fee_amount' => $this->money($request->travel_fee_amount),
        ];
    }

    private function displayServiceType(TechnicalServiceRequest $request): ?string
    {
        if ($request->parent_request_id !== null || $request->service_code !== null) {
            return 'Servis';
        }

        return $request->service_type;
    }

    private function isCompletedRequest(TechnicalServiceRequest $request): bool
    {
        return $this->statusIncludes($request->status, 'tamamland')
            || $this->statusIncludes($request->workflow_status, 'tamamland');
    }

    private function isCancelledRequest(TechnicalServiceRequest $request): bool
    {
        return $request->cancelled_at !== null
            || $this->statusIncludes($request->status, 'ptal')
            || $this->statusIncludes($request->workflow_status, 'ptal');
    }

    private function statusIncludes(?string $value, string $needle): bool
    {
        return str_contains(mb_strtolower((string) $value), $needle);
    }

    private function jobDate(TechnicalServiceRequest $request): CarbonImmutable
    {
        return CarbonImmutable::parse($request->installation_completed_at ?? $request->completed_at);
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function refreshEarningTotals(TechnicalServiceEarning $earning): void
    {
        $items = $earning->items()->get();

        $earning->update([
            'job_count' => $items->count(),
            'installation_count' => $items->filter(fn ($item) => $item->service_type === 'Montaj')->count(),
            'service_count' => $items->filter(fn ($item) => $item->service_type !== 'Montaj')->count(),
            'labor_total' => $items->sum('labor_amount'),
            'travel_fee_total' => $items->sum('travel_fee_amount'),
            'travel_round_trip_km_total' => $items->sum('travel_round_trip_km'),
            'travel_billable_km_total' => $items->sum('travel_billable_km'),
            'grand_total' => $items->sum('line_total'),
        ]);
    }

    private function syncPeriodPaidStatus(int $periodId): void
    {
        $period = TechnicalServiceEarningsPeriod::query()->find($periodId);

        if (! $period || in_array($period->status, ['paid', 'locked'], true)) {
            return;
        }

        $total = $period->earnings()->count();

        if ($total > 0 && $period->earnings()->where('status', '!=', 'Ödendi')->doesntExist()) {
            $period->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }
    }

    private function emptySummary(): array
    {
        return [
            'technician_count' => 0,
            'job_count' => 0,
            'labor_total' => 0,
            'travel_fee_total' => 0,
            'grand_total' => 0,
            'payable_count' => 0,
            'paid_count' => 0,
            'disputed_count' => 0,
        ];
    }

    private function summary(Collection $items): array
    {
        return [
            'technician_count' => $items->count(),
            'job_count' => $items->sum('job_count'),
            'labor_total' => round((float) $items->sum('labor_total'), 2),
            'travel_fee_total' => round((float) $items->sum('travel_fee_total'), 2),
            'grand_total' => round((float) $items->sum('grand_total'), 2),
            'payable_count' => $items->where('status', 'Ödenecek')->count(),
            'paid_count' => $items->where('status', 'Ödendi')->count(),
            'disputed_count' => $items->where('status', 'İtirazlı')->count(),
        ];
    }
}

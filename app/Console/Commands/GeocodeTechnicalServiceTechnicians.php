<?php

namespace App\Console\Commands;

use App\Models\TechnicalServiceTechnician;
use App\Services\TechnicalService\TechnicianGeocodingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class GeocodeTechnicalServiceTechnicians extends Command
{
    protected $signature = 'technical-service:geocode-technicians
        {--dry-run : Call Google and report results without writing to the database}
        {--limit=50 : Maximum number of technicians to process}
        {--id=* : Only process one or more technician ids}
        {--only-missing : Keep the default missing-coordinate filter}
        {--force : Include records that already have valid coordinates and allow update mode}
        {--sleep-ms=150 : Delay between Google requests}
        {--city= : Restrict by city}
        {--needs-review : Only process records marked needs_review}';

    protected $description = 'Geocode technical service technician addresses or Plus Codes into real coordinates.';

    public function handle(TechnicianGeocodingService $geocodingService): int
    {
        if ($geocodingService->apiKey() === null) {
            $this->error('Google geocoding key tanımlı değil.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max((int) $this->option('limit'), 1);
        $sleepMs = max((int) $this->option('sleep-ms'), 0);
        $query = TechnicalServiceTechnician::query()
            ->orderBy('city')
            ->orderBy('name')
            ->limit($limit);

        $ids = collect($this->option('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        if ($ids->isNotEmpty()) {
            $query->whereIn('id', $ids->all());
        }

        if (! (bool) $this->option('force')) {
            $query->where(function (Builder $query): void {
                $query->whereNull('latitude')
                    ->orWhereNull('longitude')
                    ->orWhereNull('start_latitude')
                    ->orWhereNull('start_longitude');
            });
        }

        if (filled($this->option('city'))) {
            $query->where('city', (string) $this->option('city'));
        }

        if ((bool) $this->option('needs-review')) {
            $query->where('needs_review', true);
        }

        $summary = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($query->get() as $technician) {
            $summary['processed']++;
            $result = $geocodingService->geocode($technician);

            if (! ($result['ok'] ?? false)) {
                $status = (string) ($result['status'] ?? 'failed');

                if ($status === 'skipped') {
                    $summary['skipped']++;
                } else {
                    $summary['failed']++;
                }

                $this->warn(sprintf(
                    '#%s %s: %s',
                    $technician->id,
                    $technician->name,
                    $result['error_message'] ?? 'Geocoding başarısız.'
                ));

                if (! $dryRun) {
                    $technician->forceFill([
                        'needs_review' => true,
                        'route_note' => (string) ($result['error_message'] ?? 'Geocoding başarısız.'),
                    ])->save();
                }

                $this->sleep($sleepMs);

                continue;
            }

            $this->line(sprintf(
                '#%s %s: %s -> %s,%s (%s)',
                $technician->id,
                $technician->name,
                $result['source_type'],
                $result['latitude'],
                $result['longitude'],
                $dryRun ? 'dry-run' : 'update'
            ));

            if (! $dryRun) {
                $technician->forceFill([
                    'latitude' => $result['latitude'],
                    'longitude' => $result['longitude'],
                    'start_latitude' => $result['latitude'],
                    'start_longitude' => $result['longitude'],
                    'location_source' => $result['provider'] ?? 'google_geocode',
                    'route_note' => $this->routeNote($result),
                    'needs_review' => (bool) ($result['needs_review'] ?? false),
                ])->save();
                $summary['updated']++;
            }

            $this->sleep($sleepMs);
        }

        $this->info($dryRun ? 'Geocode dry-run completed.' : 'Geocode update completed.');
        $this->line('processed: '.$summary['processed']);
        $this->line('updated: '.$summary['updated']);
        $this->line('skipped: '.$summary['skipped']);
        $this->line('failed: '.$summary['failed']);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function routeNote(array $result): string
    {
        $formatted = trim((string) ($result['formatted_address'] ?? ''));
        $source = trim((string) ($result['source_type'] ?? 'unknown'));
        $summary = "Geocoded from {$source}";

        if ($formatted !== '') {
            $summary .= "; formatted: {$formatted}";
        }

        $reviewReason = trim((string) ($result['review_reason'] ?? ''));
        if ((bool) ($result['needs_review'] ?? false) && $reviewReason !== '') {
            $summary .= "; {$reviewReason}";
        }

        $locationType = trim((string) ($result['location_type'] ?? ''));
        if ($locationType !== '') {
            $summary .= "; location_type: {$locationType}";
        }

        return $summary.'; at '.now()->toDateTimeString();
    }

    private function sleep(int $sleepMs): void
    {
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }
}

<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BCariSnapshot;
use App\Models\B2B\B2BCariSnapshotRun;
use App\Models\B2B\B2BPartner;
use App\Models\DataSource;
use App\Models\TechnicalServiceTechnician;
use App\Services\N8nPanelDataGateway;
use Illuminate\Support\Str;
use RuntimeException;

class B2BCariControlService
{
    private const SOURCE_CODES = [
        'b2b_mikro_cari_candidates',
        'customers_list',
        'proforma_customer_search',
        'cari_list',
        'cari_bilgi_dashboard',
        'customer_detail',
        'sales_customer_search',
    ];

    private const DETAIL_SOURCE_CODES = [
        'customer_detail',
        'cari_bilgi_dashboard',
    ];

    private const ONLINE_RETAIL_SIGNALS = [
        'ONLINE',
        'ONLINE PERAKENDE',
        'WEB PERAKENDE',
        'E-TICARET',
        'ETICARET',
        'ECOMMERCE',
        'PAZARYERI',
        'PAZAR YERI',
        'MARKETPLACE',
        'INTERNET',
    ];

    private const CHILD_CARI_USAGE_SUFFIXES = [
        'KONSINYE' => 'Konsinye cari',
        'TESHIR' => 'Teşhir cari',
        'PROJE' => 'Proje cari',
    ];

    public function __construct(
        private readonly N8nPanelDataGateway $gateway,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function candidateResponse(array $filters): array
    {
        $inventory = $this->sourceInventory();
        $source = $this->candidateSource();
        $refresh = (bool) ($filters['refresh'] ?? false);
        $snapshot = $this->snapshotResponse($filters, $inventory);

        if (! $refresh && $snapshot['snapshot_total'] > 0 && ($snapshot['candidates'] !== [] || $this->nullableString($filters['search'] ?? null) === '' || ! $source)) {
            return $snapshot;
        }

        if (! $source) {
            return $snapshot['snapshot_total'] > 0
                ? $snapshot
                : $this->errorResponse($inventory, 'Cari adaylari icin uygun aktif SELECT-only data source bulunamadi.');
        }

        $run = B2BCariSnapshotRun::query()->create([
            'source_code' => $source->code,
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'filters' => collect($filters)->except(['password', 'token', 'secret'])->all(),
            ],
        ]);

        try {
            $result = $this->gateway->run($source->code, $this->gatewayFilters($filters), $source);
        } catch (RuntimeException $exception) {
            $this->finishSnapshotRun($run, 'failed', [
                'error_message' => $exception->getMessage(),
            ]);

            return $snapshot['snapshot_total'] > 0
                ? [
                    ...$snapshot,
                    'message' => 'Gateway hatasi nedeniyle son PostgreSQL cari snapshot adaylari gosteriliyor: '.$exception->getMessage(),
                    'gateway_error' => $exception->getMessage(),
                ]
                : $this->errorResponse($inventory, 'Cari adaylari gateway uzerinden alinamadi: '.$exception->getMessage(), $source->code);
        }

        $normalizationFilters = [
            ...$filters,
            'include_review_required' => true,
            'offset' => 0,
            'limit' => max((int) ($filters['limit'] ?? 100), 250),
        ];
        $normalized = $this->normalizeRows($result['rows'], $normalizationFilters, $source->code);
        $snapshotCounts = $this->persistSnapshots($normalized['candidates'], $source->code);
        $this->finishSnapshotRun($run, 'success', [
            'total_received' => count($result['rows']),
            'total_normalized' => count($normalized['candidates']),
            'excluded_online_retail_count' => $normalized['excluded_online_retail_count'],
            'new_count' => $snapshotCounts['new_count'],
            'changed_count' => $snapshotCounts['changed_count'],
            'matched_count' => $snapshotCounts['matched_count'],
            'metadata' => [
                'gateway_meta' => $result['meta'],
                'filters' => collect($filters)->except(['password', 'token', 'secret'])->all(),
            ],
        ]);

        $snapshot = $this->snapshotResponse($filters, $inventory, $source->code, $result['meta'], $run->id);

        return [
            ...$snapshot,
            'message' => 'Cari adaylari gateway uzerinden alindi ve PostgreSQL snapshot guncellendi. Otomatik partner acilmaz; secili adaylar islenir.',
            'source_used' => $source->code,
            'gateway_meta' => $result['meta'],
            'snapshot_run_id' => $run->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function normalizeCandidateInput(array $candidate): array
    {
        $normalized = $this->normalizeRow($candidate, 'manual_apply');

        $normalized = $normalized ?? [
            'mikro_cari_kodu' => trim((string) ($candidate['mikro_cari_kodu'] ?? '')),
            'display_name' => $this->nullableString($candidate['display_name'] ?? null),
            'mikro_cari_unvan' => $this->nullableString($candidate['mikro_cari_unvan'] ?? null),
            'cari_grup_kodu' => $this->nullableString($candidate['cari_grup_kodu'] ?? null),
            'responsibility_code' => $this->nullableString($candidate['responsibility_code'] ?? null),
            'temsilci_kodu' => $this->nullableString($candidate['temsilci_kodu'] ?? null),
            'srm_merkezi' => $this->nullableString($candidate['srm_merkezi'] ?? null),
            'phone' => $this->nullableString($candidate['phone'] ?? null),
            'email' => $this->nullableString($candidate['email'] ?? null),
            'city' => $this->nullableString($candidate['city'] ?? null),
            'district' => $this->nullableString($candidate['district'] ?? null),
            'address' => $this->nullableString($candidate['address'] ?? null),
            'tax_no' => $this->nullableString($candidate['tax_no'] ?? null),
            'tax_office' => $this->nullableString($candidate['tax_office'] ?? null),
            'suggested_capabilities' => $this->normalizeCapabilities($candidate['suggested_capabilities'] ?? $candidate['capabilities'] ?? []),
            'status' => $this->nullableString($candidate['status'] ?? null) ?? 'review_required',
            'raw_source' => $candidate,
        ];

        $normalized['child_cari_accounts'] = $this->normalizeChildCariAccountsInput($candidate['child_cari_accounts'] ?? []);
        $normalized['review_required'] = (bool) ($candidate['review_required'] ?? (($normalized['status'] ?? null) === 'review_required'));

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function enrichCandidateForApply(array $candidate): array
    {
        $normalized = $this->normalizeCandidateInput($candidate);

        if ($this->missingCandidateFields($normalized) !== []) {
            $detail = $this->detailCandidate($normalized);

            if ($detail !== null) {
                $normalized = $this->mergeCandidateDetail($normalized, $detail);
            }
        }

        return $this->withSourceFieldMissingMeta($normalized);
    }

    /**
     * @param  mixed  $capabilities
     * @return array<int, string>
     */
    public function normalizeCapabilities(mixed $capabilities): array
    {
        if (! is_array($capabilities)) {
            $capabilities = [];
        }

        return collect($capabilities)
            ->map(fn (mixed $capability): string => trim((string) $capability))
            ->filter(fn (string $capability): bool => in_array($capability, B2BPartner::SUPPORTED_CAPABILITIES, true))
            ->unique()
            ->values()
            ->all();
    }

    public function markSnapshotLinkedToPartner(B2BPartner $partner): void
    {
        $code = $this->nullableString($partner->mikro_cari_kodu);

        if ($code === null) {
            return;
        }

        B2BCariSnapshot::query()
            ->where('base_mikro_cari_kodu', $code)
            ->update([
                'existing_partner_id' => $partner->id,
                'candidate_status' => 'matched',
                'last_seen_at' => now(),
            ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sourceInventory(): array
    {
        return DataSource::query()
            ->whereIn('code', self::SOURCE_CODES)
            ->orderByRaw($this->sourceOrderSql())
            ->get(['code', 'name', 'db_type', $this->readStatementColumn(), 'active'])
            ->map(fn (DataSource $source): array => [
                'code' => $source->code,
                'name' => $source->name,
                'db_type' => $source->db_type,
                'active' => (bool) $source->active,
                'usable_for_b2b_cari_control' => $this->isRunnableSource($source),
                'reason' => $this->isRunnableSource($source)
                    ? 'B2B cari adaylari icin SELECT-only gateway kaynagi olarak kullanilabilir.'
                    : 'Kaynak aktif degil veya calistirilabilir onayli okuma ifadesi yok.',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function discoveryQueries(): array
    {
        return [
            [
                'key' => 'find_cari_tables',
                'title' => 'Cari benzeri tablolari bul',
                'sql' => "SELECT TABLE_SCHEMA, TABLE_NAME\nFROM INFORMATION_SCHEMA.TABLES\nWHERE TABLE_NAME LIKE '%CARI%'\nORDER BY TABLE_SCHEMA, TABLE_NAME;",
            ],
            [
                'key' => 'find_cari_columns',
                'title' => 'Cari kolonlarini bul',
                'sql' => "SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE\nFROM INFORMATION_SCHEMA.COLUMNS\nWHERE TABLE_NAME LIKE '%CARI%'\nORDER BY TABLE_SCHEMA, TABLE_NAME, ORDINAL_POSITION;",
            ],
            [
                'key' => 'sample_cari_hesaplar',
                'title' => 'CARI_HESAPLAR ornek satir',
                'sql' => "SELECT TOP 50\n    cari_kod,\n    cari_unvan1,\n    cari_unvan2,\n    cari_grup_kodu,\n    cari_temsilci_kodu,\n    cari_srm_merkezi,\n    cari_CepTel,\n    cari_EMail,\n    cari_il,\n    cari_ilce\nFROM CARI_HESAPLAR\nWHERE cari_kod IS NOT NULL\nORDER BY cari_kod;",
            ],
        ];
    }

    private function candidateSource(): ?DataSource
    {
        return DataSource::query()
            ->whereIn('code', self::SOURCE_CODES)
            ->get()
            ->sortBy(function (DataSource $source): int {
                $index = array_search($source->code, self::SOURCE_CODES, true);

                return $index === false ? 999 : $index;
            })
            ->first(fn (DataSource $source): bool => $this->isRunnableSource($source));
    }

    private function isRunnableSource(DataSource $source): bool
    {
        return (bool) $source->active
            && preg_match('/\b(SELECT|WITH|EXEC)\b/i', (string) $source->getAttribute($this->readStatementColumn())) === 1;
    }

    private function readStatementColumn(): string
    {
        return implode('_', ['query', 'template']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function gatewayFilters(array $filters): array
    {
        $search = $this->nullableString($filters['search'] ?? null);

        return [
            'search' => $search,
            'customer_filter' => $search,
            'cari_filter' => $search,
            'scope_key' => 'all',
            'customer_scope_key' => 'bayi_proje',
            'limit' => (int) ($filters['limit'] ?? 100),
            'page' => 1,
            'bypass_cache' => true,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return array{candidates: array<int, array<string, mixed>>, excluded_online_retail_count: int}
     */
    private function normalizeRows(array $rows, array $filters, string $sourceCode): array
    {
        $existingPartners = $this->existingPartnerMap();
        $techniciansByCari = $this->technicianCariMap();
        $excludedOnlineRetailCount = 0;
        $includeReviewRequired = (bool) ($filters['include_review_required'] ?? false);
        $requestedCapability = $this->nullableString($filters['capability'] ?? null);
        $requestedStatus = $this->nullableString($filters['status'] ?? null);
        $cityFilter = $this->normalizedText($filters['city'] ?? null);
        $search = $this->normalizedText($filters['search'] ?? null);
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $limit = min(250, max(1, (int) ($filters['limit'] ?? 100)));

        $normalizedRows = [];

        foreach ($rows as $row) {
            $candidate = $this->normalizeRow($row, $sourceCode, $existingPartners, $techniciansByCari);

            if (! $candidate) {
                continue;
            }

            if (($candidate['status'] ?? null) === 'excluded_online_retail') {
                $excludedOnlineRetailCount++;

                continue;
            }

            $normalizedRows[] = $candidate;
        }

        $candidates = collect($this->groupChildCariAccounts($normalizedRows, $existingPartners))
            ->filter(function (array $candidate) use ($includeReviewRequired): bool {
                return $includeReviewRequired || ($candidate['status'] ?? null) !== 'review_required';
            })
            ->filter(function (array $candidate) use ($requestedCapability): bool {
                if ($requestedCapability === null) {
                    return true;
                }

                return in_array($requestedCapability, $candidate['suggested_capabilities'] ?? [], true);
            })
            ->filter(fn (array $candidate): bool => $this->matchesStatusFilter($candidate, $requestedStatus))
            ->filter(function (array $candidate) use ($cityFilter): bool {
                if ($cityFilter === '') {
                    return true;
                }

                return str_contains($this->normalizedText($candidate['city'] ?? null), $cityFilter);
            })
            ->map(fn (array $candidate): array => $this->annotateSearchMatch($candidate, $search))
            ->filter(fn (array $candidate): bool => $search === '' || ($candidate['search_match'] ?? null) !== null)
            ->slice($offset, $limit)
            ->values()
            ->all();

        $candidates = $this->enrichListCandidates($candidates);

        return [
            'candidates' => $candidates,
            'excluded_online_retail_count' => $excludedOnlineRetailCount,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function enrichListCandidates(array $candidates): array
    {
        if ($this->detailSources()->isEmpty()) {
            return $candidates;
        }

        $enrichedCount = 0;

        return collect($candidates)
            ->map(function (array $candidate) use (&$enrichedCount): array {
                if ($this->missingCandidateFields($candidate) !== [] && $enrichedCount < 25) {
                    $enrichedCount++;
                    $detail = $this->detailCandidate($candidate);

                    if ($detail !== null) {
                        $candidate = $this->mergeCandidateDetail($candidate, $detail);
                    }
                }

                return $this->withSourceFieldMissingMeta($candidate);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, array<string, mixed>>  $inventory
     * @param  array<string, mixed>  $gatewayMeta
     * @return array<string, mixed>
     */
    private function snapshotResponse(array $filters, array $inventory, ?string $sourceCode = null, array $gatewayMeta = [], ?int $runId = null): array
    {
        $requestedCapability = $this->nullableString($filters['capability'] ?? null);
        $requestedStatus = $this->nullableString($filters['status'] ?? null);
        $cityFilter = $this->normalizedText($filters['city'] ?? null);
        $search = $this->normalizedText($filters['search'] ?? null);
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $limit = min(250, max(1, (int) ($filters['limit'] ?? 100)));
        $existingPartners = $this->existingPartnerMap();

        $rows = B2BCariSnapshot::query()
            ->where('candidate_status', '!=', 'excluded')
            ->orderBy('base_mikro_cari_kodu')
            ->limit(1000)
            ->get()
            ->map(fn (B2BCariSnapshot $snapshot): array => $this->candidateFromSnapshot($snapshot, $existingPartners))
            ->unique('mikro_cari_kodu')
            ->values();

        $snapshotTotal = $rows->count();
        $filtered = $rows
            ->filter(function (array $candidate) use ($requestedStatus): bool {
                if ($requestedStatus === null) {
                    return ($candidate['existing_partner_id'] ?? null) === null;
                }

                return true;
            })
            ->filter(function (array $candidate) use ($requestedCapability): bool {
                if ($requestedCapability === null) {
                    return true;
                }

                return in_array($requestedCapability, $candidate['suggested_capabilities'] ?? [], true);
            })
            ->filter(fn (array $candidate): bool => $this->matchesStatusFilter($candidate, $requestedStatus))
            ->filter(function (array $candidate) use ($cityFilter): bool {
                if ($cityFilter === '') {
                    return true;
                }

                return str_contains($this->normalizedText($candidate['city'] ?? null), $cityFilter);
            })
            ->map(fn (array $candidate): array => $this->annotateSearchMatch($candidate, $search))
            ->filter(fn (array $candidate): bool => $search === '' || ($candidate['search_match'] ?? null) !== null)
            ->slice($offset, $limit)
            ->values()
            ->all();

        $latestRun = B2BCariSnapshotRun::query()->latest('id')->first();

        return [
            'status' => 'ok',
            'message' => $snapshotTotal > 0
                ? 'Cari adaylari PostgreSQL snapshot uzerinden gosteriliyor. Otomatik partner acilmaz; secili adaylar islenir.'
                : 'Cari snapshot bos. Gateway uzerinden SELECT-only cari adaylari cekilecek.',
            'candidates' => $filtered,
            'items' => $filtered,
            'excluded_online_retail_count' => (int) ($latestRun?->excluded_online_retail_count ?? 0),
            'source_used' => $sourceCode ?? $latestRun?->source_code ?? 'snapshot',
            'source_inventory' => $inventory,
            'existing_sources' => $inventory,
            'gateway_meta' => $gatewayMeta,
            'snapshot_run_id' => $runId ?? $latestRun?->id,
            'snapshot_total' => $snapshotTotal,
            'snapshot_counts' => [
                'new' => $rows->where('status', 'new')->count(),
                'matched' => $rows->where('status', 'matched')->count(),
                'changed' => $rows->where('status', 'changed')->count(),
                'review_required' => $rows->where('status', 'review_required')->count(),
            ],
            'actions_enabled' => true,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{new_count: int, changed_count: int, matched_count: int}
     */
    private function persistSnapshots(array $candidates, string $sourceCode): array
    {
        $existingPartners = $this->existingPartnerMap();
        $counts = [
            'new_count' => 0,
            'changed_count' => 0,
            'matched_count' => 0,
        ];

        foreach ($candidates as $candidate) {
            $code = $this->nullableString($candidate['mikro_cari_kodu'] ?? null);

            if ($code === null) {
                continue;
            }

            $existingPartner = $existingPartners[$this->normalizedText($code)] ?? null;
            $differenceSummary = $existingPartner ? $this->differenceSummary($existingPartner, $candidate) : [];
            $status = $this->snapshotStatus($candidate, $existingPartner, $differenceSummary);
            $counts[$status.'_count'] = ($counts[$status.'_count'] ?? 0) + 1;
            $candidate['existing_partner_id'] = $existingPartner?->id;
            $candidate['difference_summary'] = $differenceSummary;
            $candidate['status'] = $status;
            $candidate['status_label'] = $this->snapshotStatusLabel($status);
            $candidate['review_required'] = $status === 'review_required';
            $children = $this->normalizeChildCariAccountsInput($candidate['child_cari_accounts'] ?? []);
            $candidate['child_cari_accounts'] = $children;
            $invoiceProfile = $this->invoiceProfileForSnapshot($candidate, $children);
            $shippingProfile = $this->shippingProfileForSnapshot($candidate, $children);
            $payload = $this->snapshotRawPayload($candidate);

            B2BCariSnapshot::query()->updateOrCreate(
                [
                    'source_code' => $sourceCode,
                    'base_mikro_cari_kodu' => $code,
                ],
                [
                    'mikro_cari_kodu' => $code,
                    'mikro_cari_unvan' => $this->nullableString($candidate['mikro_cari_unvan'] ?? null)
                        ?? $this->nullableString($candidate['display_name'] ?? null),
                    'normalized_unvan' => $this->normalizedText($candidate['mikro_cari_unvan'] ?? $candidate['display_name'] ?? null),
                    'cari_grup_kodu' => $this->nullableString($candidate['cari_grup_kodu'] ?? null),
                    'responsibility_code' => $this->nullableString($candidate['responsibility_code'] ?? null),
                    'temsilci_kodu' => $this->nullableString($candidate['temsilci_kodu'] ?? null),
                    'phone' => $this->nullableString($candidate['phone'] ?? null),
                    'email' => $this->nullableString($candidate['email'] ?? null),
                    'city' => $this->nullableString($candidate['city'] ?? null),
                    'district' => $this->nullableString($candidate['district'] ?? null),
                    'address' => $this->nullableString($candidate['address'] ?? null),
                    'tax_no' => $this->nullableString($candidate['tax_no'] ?? null),
                    'tax_office' => $this->nullableString($candidate['tax_office'] ?? null),
                    'suggested_capabilities' => $this->normalizeCapabilities($candidate['suggested_capabilities'] ?? []),
                    'child_cari_accounts' => $children,
                    'invoice_profile' => $invoiceProfile,
                    'shipping_profile' => $shippingProfile,
                    'raw_payload' => $payload,
                    'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
                    'existing_partner_id' => $existingPartner?->id,
                    'candidate_status' => $status,
                    'review_reason' => $status === 'review_required' ? $this->nullableString($candidate['review_reason'] ?? null) : null,
                    'last_seen_at' => now(),
                ],
            );
        }

        return [
            'new_count' => $counts['new_count'] ?? 0,
            'changed_count' => $counts['changed_count'] ?? 0,
            'matched_count' => $counts['matched_count'] ?? 0,
        ];
    }

    /**
     * @param  array<string, B2BPartner>  $existingPartners
     * @return array<string, mixed>
     */
    private function candidateFromSnapshot(B2BCariSnapshot $snapshot, array $existingPartners): array
    {
        $candidate = [
            'mikro_cari_kodu' => $snapshot->base_mikro_cari_kodu,
            'mikro_cari_unvan' => $snapshot->mikro_cari_unvan,
            'display_name' => $snapshot->mikro_cari_unvan ?: $snapshot->base_mikro_cari_kodu,
            'cari_grup_kodu' => $snapshot->cari_grup_kodu,
            'responsibility_code' => $snapshot->responsibility_code,
            'temsilci_kodu' => $snapshot->temsilci_kodu,
            'srm_merkezi' => $snapshot->responsibility_code,
            'phone' => $snapshot->phone,
            'email' => $snapshot->email,
            'city' => $snapshot->city,
            'district' => $snapshot->district,
            'address' => $snapshot->address,
            'tax_no' => $snapshot->tax_no,
            'tax_office' => $snapshot->tax_office,
            'suggested_capabilities' => $snapshot->suggested_capabilities ?? [],
            'capabilities' => $snapshot->suggested_capabilities ?? [],
            'confidence' => data_get($snapshot->raw_payload, 'confidence', 0.82),
            'child_cari_accounts' => $snapshot->child_cari_accounts ?? [],
            'invoice_profile' => $snapshot->invoice_profile ?? [],
            'shipping_profile' => $snapshot->shipping_profile ?? [],
            'source_used' => $snapshot->source_code,
            'source_field_missing' => data_get($snapshot->raw_payload, 'source_field_missing', []),
            'raw_source' => $snapshot->raw_payload,
        ];
        $existingPartner = $existingPartners[$this->normalizedText($snapshot->base_mikro_cari_kodu)] ?? null;
        $differenceSummary = $existingPartner ? $this->differenceSummary($existingPartner, $candidate) : [];
        $status = $this->snapshotStatus($candidate + ['status' => $snapshot->candidate_status], $existingPartner, $differenceSummary);
        $candidate['existing_partner_id'] = $existingPartner?->id ?? $snapshot->existing_partner_id;
        $candidate['difference_summary'] = $differenceSummary;
        $candidate['status'] = $status;
        $candidate['status_label'] = $this->snapshotStatusLabel($status);
        $candidate['review_required'] = $status === 'review_required';
        $candidate['matched_child_cari_codes'] = [];
        $candidate['search_match'] = null;

        return $this->withSourceFieldMissingMeta($candidate);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, string>  $differenceSummary
     */
    private function snapshotStatus(array $candidate, ?B2BPartner $existingPartner, array $differenceSummary): string
    {
        if (($candidate['status'] ?? null) === 'review_required' || ($candidate['candidate_status'] ?? null) === 'review_required') {
            return 'review_required';
        }

        if ($existingPartner) {
            return $differenceSummary === [] ? 'matched' : 'changed';
        }

        return 'new';
    }

    private function snapshotStatusLabel(string $status): string
    {
        return match ($status) {
            'new' => 'Yeni',
            'matched' => 'Mevcut',
            'changed' => 'Guncellenecek',
            'review_required' => 'Kontrol gerekli',
            default => 'Aday',
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function invoiceProfileForSnapshot(array $candidate, array $children): array
    {
        return array_filter([
            'cari_kodu' => $this->nullableString($candidate['mikro_cari_kodu'] ?? null),
            'cari_unvan' => $this->nullableString($candidate['mikro_cari_unvan'] ?? null)
                ?? $this->nullableString($candidate['display_name'] ?? null),
            'tax_no' => $this->nullableString($candidate['tax_no'] ?? null),
            'tax_office' => $this->nullableString($candidate['tax_office'] ?? null),
            'invoice_address' => $this->nullableString($candidate['address'] ?? null),
            'city' => $this->nullableString($candidate['city'] ?? null),
            'district' => $this->nullableString($candidate['district'] ?? null),
            'email' => $this->nullableString($candidate['email'] ?? null),
            'child_account_mapping' => $this->childAccountMapping($children),
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function shippingProfileForSnapshot(array $candidate, array $children): array
    {
        return array_filter([
            'shipping_name' => $this->nullableString($candidate['display_name'] ?? null)
                ?? $this->nullableString($candidate['mikro_cari_unvan'] ?? null),
            'phone' => $this->nullableString($candidate['phone'] ?? null),
            'address' => $this->nullableString($candidate['address'] ?? null),
            'city' => $this->nullableString($candidate['city'] ?? null),
            'district' => $this->nullableString($candidate['district'] ?? null),
            'child_account_mapping' => $this->childAccountMapping($children),
            'consignment_cari_kodu' => data_get($this->childAccountMapping($children), 'consignment'),
            'showroom_cari_kodu' => data_get($this->childAccountMapping($children), 'showroom'),
            'project_cari_kodu' => data_get($this->childAccountMapping($children), 'project'),
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, string>
     */
    private function childAccountMapping(array $children): array
    {
        return collect($children)
            ->filter(fn (array $child): bool => $this->nullableString($child['usage_type'] ?? null) !== null && $this->nullableString($child['mikro_cari_kodu'] ?? null) !== null)
            ->mapWithKeys(fn (array $child): array => [
                (string) $child['usage_type'] => (string) $child['mikro_cari_kodu'],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function snapshotRawPayload(array $candidate): array
    {
        return collect($candidate)
            ->except(['raw_source'])
            ->put('raw_source', $candidate['raw_source'] ?? null)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finishSnapshotRun(B2BCariSnapshotRun $run, string $status, array $attributes = []): void
    {
        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            ...$attributes,
        ])->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventory
     * @return array<string, mixed>
     */
    private function errorResponse(array $inventory, string $message, ?string $sourceCode = null): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'candidates' => [],
            'items' => [],
            'excluded_online_retail_count' => 0,
            'source_used' => $sourceCode,
            'source_inventory' => $inventory,
            'existing_sources' => $inventory,
            'snapshot_total' => B2BCariSnapshot::query()->count(),
            'actions_enabled' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<string, B2BPartner>  $existingPartners
     * @return array<int, array<string, mixed>>
     */
    private function groupChildCariAccounts(array $candidates, array $existingPartners): array
    {
        $grouped = [];

        foreach ($candidates as $candidate) {
            $childInfo = $this->childCariInfo((string) ($candidate['mikro_cari_kodu'] ?? ''));

            if ($childInfo) {
                $groupKey = $this->normalizedText($childInfo['parent_code']);

                if (! isset($grouped[$groupKey])) {
                    $grouped[$groupKey] = $this->parentCandidateFromChild($candidate, $childInfo['parent_code'], $existingPartners);
                }

                $grouped[$groupKey] = $this->mergeCandidateMetadata($grouped[$groupKey], $candidate);
                $grouped[$groupKey]['child_cari_accounts'][] = [
                    'mikro_cari_kodu' => $candidate['mikro_cari_kodu'],
                    'mikro_cari_unvan' => $candidate['mikro_cari_unvan'] ?? $candidate['display_name'] ?? null,
                    'display_name' => $candidate['display_name'] ?? $candidate['mikro_cari_unvan'] ?? null,
                    'usage_type' => $childInfo['usage_type'],
                    'cari_usage_type' => $childInfo['display_label'],
                    'invoice_usage_note' => $childInfo['invoice_usage_note'],
                    'status' => $candidate['status'] ?? null,
                    'status_label' => $candidate['status_label'] ?? null,
                ];

                continue;
            }

            $groupKey = $this->normalizedText($candidate['mikro_cari_kodu'] ?? null);
            $candidate['child_cari_accounts'] = $candidate['child_cari_accounts'] ?? [];

            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = $candidate;

                continue;
            }

            $children = $grouped[$groupKey]['child_cari_accounts'] ?? [];
            $grouped[$groupKey] = $this->mergeCandidateMetadata($candidate, $grouped[$groupKey]);
            $grouped[$groupKey]['child_cari_accounts'] = $children;
        }

        return collect($grouped)
            ->map(function (array $candidate): array {
                $candidate['child_cari_accounts'] = collect($candidate['child_cari_accounts'] ?? [])
                    ->unique('mikro_cari_kodu')
                    ->values()
                    ->all();

                return $candidate;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, B2BPartner>  $existingPartners
     * @return array<string, mixed>
     */
    private function parentCandidateFromChild(array $candidate, string $parentCode, array $existingPartners): array
    {
        $parent = $candidate;
        $parent['mikro_cari_kodu'] = $parentCode;
        $parent['child_cari_accounts'] = [];
        $parent['review_required'] = true;
        $parent['status'] = 'review_required';
        $parent['status_label'] = 'Kontrol gerekli';
        $parent['synthetic_parent'] = true;

        $existingPartner = $existingPartners[$this->normalizedText($parentCode)] ?? null;

        if ($existingPartner) {
            $parent['existing_partner_id'] = $existingPartner->id;
            $parent['difference_summary'] = $this->differenceSummary($existingPartner, [
                'mikro_cari_unvan' => $parent['mikro_cari_unvan'] ?? null,
                'cari_grup_kodu' => $parent['cari_grup_kodu'] ?? null,
                'responsibility_code' => $parent['responsibility_code'] ?? null,
                'phone' => $parent['phone'] ?? null,
                'email' => $parent['email'] ?? null,
                'city' => $parent['city'] ?? null,
                'district' => $parent['district'] ?? null,
            ]);
        }

        return $parent;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeCandidateMetadata(array $base, array $incoming): array
    {
        $capabilities = array_values(array_unique([
            ...($base['suggested_capabilities'] ?? []),
            ...($incoming['suggested_capabilities'] ?? []),
        ]));

        if ($capabilities !== []) {
            $base['suggested_capabilities'] = $capabilities;
            $base['capabilities'] = $capabilities;
            $base['confidence'] = max((float) ($base['confidence'] ?? 0), (float) ($incoming['confidence'] ?? 0));
        }

        if (($base['synthetic_parent'] ?? false) !== true && ($base['status'] ?? null) === 'review_required' && ($incoming['status'] ?? null) === 'candidate') {
            $base['status'] = 'candidate';
            $base['status_label'] = 'Aday';
        }

        if (($base['existing_partner_id'] ?? null) === null && ($incoming['existing_partner_id'] ?? null) !== null) {
            $base['existing_partner_id'] = $incoming['existing_partner_id'];
        }

        $base['difference_summary'] = array_values(array_unique([
            ...($base['difference_summary'] ?? []),
            ...($incoming['difference_summary'] ?? []),
        ]));

        return $base;
    }

    /**
     * @return array{parent_code: string, usage_type: string, display_label: string, invoice_usage_note: string}|null
     */
    private function childCariInfo(string $code): ?array
    {
        $parts = explode('.', trim($code));

        if (count($parts) < 2) {
            return null;
        }

        $lastPart = array_pop($parts);
        $normalizedSuffix = $this->normalizedText($lastPart);
        $usage = $this->childCariUsage($normalizedSuffix);

        if ($usage === null) {
            return null;
        }

        return [
            'parent_code' => implode('.', $parts),
            'usage_type' => $usage['usage_type'],
            'display_label' => $usage['display_label'],
            'invoice_usage_note' => $usage['invoice_usage_note'],
        ];
    }

    /**
     * @return array{usage_type: string, display_label: string, invoice_usage_note: string}|null
     */
    private function childCariUsage(string $normalizedSuffix): ?array
    {
        return match ($normalizedSuffix) {
            'KONSINYE' => [
                'usage_type' => 'consignment',
                'display_label' => 'Konsinye cari',
                'invoice_usage_note' => 'Konsinye siparisi/faturasi icin bu alt cari hesabi kullanilacak.',
            ],
            'TESHIR' => [
                'usage_type' => 'showroom',
                'display_label' => 'Teshir cari',
                'invoice_usage_note' => 'Teshir siparisi/faturasi icin bu alt cari hesabi kullanilacak.',
            ],
            'PROJE' => [
                'usage_type' => 'project',
                'display_label' => 'Proje cari',
                'invoice_usage_note' => 'Proje siparisi/faturasi icin bu alt cari hesabi kullanilacak.',
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function matchesStatusFilter(array $candidate, ?string $statusFilter): bool
    {
        if ($statusFilter === null) {
            return true;
        }

        $status = $this->normalizedText($statusFilter);

        return match ($status) {
            'NEW', 'YENI' => ($candidate['existing_partner_id'] ?? null) === null,
            'EXISTING', 'MEVCUT' => ($candidate['existing_partner_id'] ?? null) !== null && count($candidate['difference_summary'] ?? []) === 0,
            'CHANGED', 'UPDATE', 'GUNCELLENECEK' => ($candidate['existing_partner_id'] ?? null) !== null && count($candidate['difference_summary'] ?? []) > 0,
            'REVIEW_REQUIRED', 'KONTROL_GEREKLI' => ($candidate['status'] ?? null) === 'review_required',
            default => $this->normalizedText($candidate['status'] ?? null) === $status,
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function annotateSearchMatch(array $candidate, string $search): array
    {
        $candidate['matched_child_cari_codes'] = [];
        $candidate['search_match'] = null;

        if ($search === '') {
            return $candidate;
        }

        $parentFields = [
            $candidate['mikro_cari_kodu'] ?? null,
            $candidate['mikro_cari_unvan'] ?? null,
            $candidate['display_name'] ?? null,
            $candidate['cari_grup_kodu'] ?? null,
            $candidate['responsibility_code'] ?? null,
            $candidate['temsilci_kodu'] ?? null,
            $candidate['phone'] ?? null,
            $candidate['email'] ?? null,
            $candidate['city'] ?? null,
            $candidate['district'] ?? null,
            $candidate['address'] ?? null,
            $candidate['tax_no'] ?? null,
            $candidate['tax_office'] ?? null,
        ];

        if (str_contains($this->normalizedText(implode(' ', array_filter($parentFields))), $search)) {
            $candidate['search_match'] = 'parent';
        }

        $matchedChildren = [];

        foreach ($candidate['child_cari_accounts'] ?? [] as $child) {
            $childText = $this->normalizedText(implode(' ', array_filter([
                $child['mikro_cari_kodu'] ?? null,
                $child['mikro_cari_unvan'] ?? null,
                $child['display_name'] ?? null,
                $child['usage_type'] ?? null,
                $child['cari_usage_type'] ?? null,
            ])));

            if (str_contains($childText, $search)) {
                $matchedChildren[] = (string) ($child['mikro_cari_kodu'] ?? '');
            }
        }

        $candidate['matched_child_cari_codes'] = array_values(array_filter($matchedChildren));

        if (($candidate['search_match'] ?? null) === null && $candidate['matched_child_cari_codes'] !== []) {
            $candidate['search_match'] = 'child';
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, B2BPartner>  $existingPartners
     * @param  array<string, TechnicalServiceTechnician>  $techniciansByCari
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row, string $sourceCode, array $existingPartners = [], array $techniciansByCari = []): ?array
    {
        $code = $this->value($row, [
            'mikro_cari_kodu',
            'cari_kodu',
            'cari_kod',
            'Cari Kodu',
            'Satış Cari Kodu',
            'musteri_kodu',
            'Musteri Kodu',
            'CariKodu',
        ]);

        if ($code === null) {
            return null;
        }

        $title1 = $this->value($row, [
            'display_name',
            'mikro_cari_unvan',
            'cari_unvan',
            'cari_unvani',
            'cari_unvan1',
            'Cari Ünvanı',
            'Cari Adı',
            'firma_unvani',
            'musteri_adi',
            'FirmaUnvani',
        ]);
        $title2 = $this->value($row, ['cari_unvan2', 'firma_unvani_2', 'FirmaUnvani2']);
        $title = trim(implode(' ', array_filter([$title1, $title2])));
        $groupCode = $this->value($row, ['cari_grup_kodu', 'grup_kodu', 'Cari Grup Kodu']);
        $groupName = $this->value($row, ['grup', 'Grup', 'cari_grup_adi', 'cari_grup']);
        $responsibility = $this->value($row, ['responsibility_code', 'sorumluluk_kodu', 'srm', 'srm_merkezi', 'Sorumluluk Kodu']);
        $rep = $this->value($row, ['temsilci_kodu', 'temsilci', 'sales_rep', 'TemsilciKodu', 'TemsilciAdi']);
        $phone = $this->value($row, ['cari_CepTel', 'cari_cep_tel', 'cari_tel', 'cari_tel_no', 'cari_telefon', 'cep_tel', 'telefon', 'phone', 'gsm', 'cep', 'CepTel']);
        $email = $this->value($row, ['cari_EMail', 'cari_email', 'email', 'e_mail', 'mail', 'Cari Email']);
        $city = $this->value($row, ['cari_il', 'il', 'city', 'sehir', 'şehir']);
        $district = $this->value($row, ['cari_ilce', 'ilce', 'ilçe', 'district']);
        $address = $this->value($row, ['cari_adres', 'cari_adres1', 'cari_adres2', 'adres', 'address']);
        $taxNo = $this->value($row, ['tax_no', 'vergi_no', 'vkn', 'tckn', 'cari_vdaire_no', 'cari_VergiKimlikNo', 'cari_VergiNo', 'vergi_kimlik_no', 'tc_kimlik_no']);
        $taxOffice = $this->value($row, ['tax_office', 'vergi_dairesi', 'cari_vdaire_adi', 'cari_VergiDairesi', 'vergi_daire']);
        $normalizedCode = $this->normalizedText($code);
        $existingPartner = $existingPartners[$normalizedCode] ?? null;
        $technician = $techniciansByCari[$normalizedCode] ?? null;
        $onlineSignal = $this->onlineRetailSignal($row, [$title, $groupCode, $groupName, $responsibility, $rep]);

        $suggestedCapabilities = $this->suggestedCapabilities([
            'code' => $code,
            'title' => $title,
            'group_code' => $groupCode,
            'group_name' => $groupName,
            'responsibility' => $responsibility,
            'rep' => $rep,
        ], $technician !== null);

        $status = 'candidate';
        $statusLabel = 'Aday';
        $confidence = count($suggestedCapabilities) > 0 ? 0.82 : 0.45;

        if ($onlineSignal === 'exclude') {
            $status = 'excluded_online_retail';
            $statusLabel = 'Online perakende hariç';
        } elseif ($onlineSignal === 'review' || count($suggestedCapabilities) === 0) {
            $status = 'review_required';
            $statusLabel = 'Kontrol gerekli';
        }

        return [
            'mikro_cari_kodu' => $code,
            'mikro_cari_unvan' => $title !== '' ? $title : null,
            'display_name' => $title !== '' ? $title : $code,
            'cari_grup_kodu' => $groupCode ?? $groupName,
            'responsibility_code' => $responsibility,
            'temsilci_kodu' => $rep,
            'srm_merkezi' => $responsibility,
            'phone' => $phone,
            'email' => $email,
            'city' => $city,
            'district' => $district,
            'address' => $address,
            'tax_no' => $taxNo,
            'tax_office' => $taxOffice,
            'raw_source' => $row,
            'suggested_capabilities' => $suggestedCapabilities,
            'capabilities' => $suggestedCapabilities,
            'confidence' => $confidence,
            'status' => $status,
            'status_label' => $statusLabel,
            'review_required' => $status === 'review_required',
            'existing_partner_id' => $existingPartner?->id,
            'difference_summary' => $existingPartner ? $this->differenceSummary($existingPartner, [
                'mikro_cari_unvan' => $title,
                'cari_grup_kodu' => $groupCode ?? $groupName,
                'responsibility_code' => $responsibility,
                'phone' => $phone,
                'email' => $email,
                'city' => $city,
                'district' => $district,
            ]) : [],
            'source_used' => $sourceCode,
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<int, string>
     */
    private function suggestedCapabilities(array $signals, bool $hasTechnicianMatch): array
    {
        $code = $this->normalizedText($signals['code'] ?? null);
        $text = $this->normalizedText(implode(' ', array_filter($signals)));
        $capabilities = [];

        if (
            $hasTechnicianMatch
            || str_contains($code, '320.CLG')
            || str_contains($code, 'CLG')
            || str_contains($code, 'CILINGIR')
            || str_contains($text, 'CILINGIR')
        ) {
            $capabilities[] = B2BPartner::TYPE_LOCKSMITH;
        }

        if (str_contains($text, 'BAYI')) {
            $capabilities[] = B2BPartner::TYPE_DEALER;
        }

        if (
            str_contains($text, 'URETICI')
            || str_contains($text, 'MANUFACTURER')
            || str_contains($text, 'FABRIKA')
        ) {
            $capabilities[] = B2BPartner::TYPE_MANUFACTURER;
        }

        if (
            str_contains($text, 'SATICI')
            || str_contains($text, 'SATIS')
            || str_contains($text, 'SALES')
        ) {
            $capabilities[] = B2BPartner::TYPE_SELLER;
        }

        return array_values(array_unique($capabilities));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string|null>  $fields
     */
    private function onlineRetailSignal(array $row, array $fields): ?string
    {
        $text = $this->normalizedText(implode(' ', array_filter([
            ...$fields,
            ...array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $row),
        ])));

        foreach (self::ONLINE_RETAIL_SIGNALS as $signal) {
            if (str_contains($text, $this->normalizedText($signal))) {
                return 'exclude';
            }
        }

        if (str_contains($text, 'WEB') || str_contains($text, 'PAZAR')) {
            return 'review';
        }

        return null;
    }

    /**
     * @return array<string, B2BPartner>
     */
    private function existingPartnerMap(): array
    {
        return B2BPartner::query()
            ->whereNotNull('mikro_cari_kodu')
            ->get()
            ->mapWithKeys(fn (B2BPartner $partner): array => [$this->normalizedText($partner->mikro_cari_kodu) => $partner])
            ->all();
    }

    /**
     * @return array<string, TechnicalServiceTechnician>
     */
    private function technicianCariMap(): array
    {
        return TechnicalServiceTechnician::query()
            ->where('active', true)
            ->get(['id', 'mikro_cari_kodu', 'cari_code'])
            ->flatMap(function (TechnicalServiceTechnician $technician): array {
                $codes = array_filter([$technician->mikro_cari_kodu, $technician->cari_code]);

                return collect($codes)
                    ->mapWithKeys(fn (string $code): array => [$this->normalizedText($code) => $technician])
                    ->all();
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    private function differenceSummary(B2BPartner $partner, array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => $this->nullableString($value) !== null)
            ->filter(function (mixed $value, string $field) use ($partner): bool {
                return $this->nullableString($partner->{$field} ?? null) !== $this->nullableString($value);
            })
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $aliases
     */
    private function value(array $row, array $aliases): ?string
    {
        $indexed = collect($row)
            ->mapWithKeys(fn (mixed $value, string|int $key): array => [$this->normalizedKey((string) $key) => $value])
            ->all();

        foreach ($aliases as $alias) {
            $key = $this->normalizedKey($alias);

            if (array_key_exists($key, $indexed)) {
                return $this->nullableString($indexed[$key]);
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function normalizedKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($this->ascii($key))) ?? '';
    }

    private function normalizedText(mixed $value): string
    {
        return trim(strtoupper($this->ascii((string) ($value ?? ''))));
    }

    private function ascii(string $value): string
    {
        return Str::ascii($value);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function detailCandidate(array $candidate): ?array
    {
        $code = $this->nullableString($candidate['mikro_cari_kodu'] ?? null);

        if ($code === null) {
            return null;
        }

        foreach ($this->detailSources() as $source) {
            try {
                $result = $this->gateway->run($source->code, $this->detailGatewayFilters($code), $source);
            } catch (RuntimeException) {
                continue;
            }

            $rows = collect($result['rows'])
                ->map(fn (array $row): ?array => $this->normalizeRow($row, $source->code))
                ->filter()
                ->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $normalizedCode = $this->normalizedText($code);
            $match = $rows->first(
                fn (array $row): bool => $this->normalizedText($row['mikro_cari_kodu'] ?? null) === $normalizedCode
            ) ?? $rows->first();

            if (is_array($match)) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, DataSource>
     */
    private function detailSources()
    {
        return DataSource::query()
            ->whereIn('code', self::DETAIL_SOURCE_CODES)
            ->get()
            ->sortBy(function (DataSource $source): int {
                $index = array_search($source->code, self::DETAIL_SOURCE_CODES, true);

                return $index === false ? 999 : $index;
            })
            ->filter(fn (DataSource $source): bool => $this->isRunnableSource($source))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function detailGatewayFilters(string $code): array
    {
        return [
            'search' => $code,
            'customer_filter' => $code,
            'cari_filter' => $code,
            'scope_key' => 'all',
            'customer_scope_key' => 'bayi_proje',
            'limit' => 10,
            'page' => 1,
            'bypass_cache' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<int, string>
     */
    private function missingCandidateFields(array $candidate): array
    {
        return collect(['phone', 'email', 'city', 'district', 'address', 'tax_no', 'tax_office'])
            ->filter(fn (string $field): bool => $this->nullableString($candidate[$field] ?? null) === null)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function withSourceFieldMissingMeta(array $candidate): array
    {
        $candidate['source_field_missing'] = $this->missingCandidateFields($candidate);

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function mergeCandidateDetail(array $candidate, array $detail): array
    {
        foreach ([
            'display_name',
            'mikro_cari_unvan',
            'cari_grup_kodu',
            'responsibility_code',
            'temsilci_kodu',
            'srm_merkezi',
            'phone',
            'email',
            'city',
            'district',
            'address',
            'tax_no',
            'tax_office',
        ] as $field) {
            if ($this->nullableString($candidate[$field] ?? null) === null && $this->nullableString($detail[$field] ?? null) !== null) {
                $candidate[$field] = $detail[$field];
            }
        }

        $candidate['detail_source_used'] = $detail['source_used'] ?? null;
        $candidate['raw_source_summary'] = [
            'list_source' => $candidate['source_used'] ?? null,
            'detail_source' => $detail['source_used'] ?? null,
            'detail_fields_applied' => array_values(array_diff(['phone', 'email', 'city', 'district', 'address'], $this->missingCandidateFields($candidate))),
        ];

        return $candidate;
    }

    /**
     * @param  mixed  $children
     * @return array<int, array<string, mixed>>
     */
    private function normalizeChildCariAccountsInput(mixed $children): array
    {
        if (! is_array($children)) {
            return [];
        }

        return collect($children)
            ->filter(fn (mixed $child): bool => is_array($child))
            ->map(function (array $child): array {
                $code = $this->nullableString($child['mikro_cari_kodu'] ?? null);
                $childInfo = $code ? $this->childCariInfo($code) : null;
                $usageType = $this->nullableString($child['usage_type'] ?? null) ?? $childInfo['usage_type'] ?? null;
                $displayLabel = $this->nullableString($child['cari_usage_type'] ?? null) ?? $childInfo['display_label'] ?? 'Alt cari';

                return [
                    'mikro_cari_kodu' => $code,
                    'mikro_cari_unvan' => $this->nullableString($child['mikro_cari_unvan'] ?? null),
                    'display_name' => $this->nullableString($child['display_name'] ?? null),
                    'usage_type' => $usageType,
                    'cari_usage_type' => $displayLabel,
                    'invoice_usage_note' => $this->nullableString($child['invoice_usage_note'] ?? null) ?? $childInfo['invoice_usage_note'] ?? null,
                    'status' => $this->nullableString($child['status'] ?? null),
                    'status_label' => $this->nullableString($child['status_label'] ?? null),
                ];
            })
            ->filter(fn (array $child): bool => $child['mikro_cari_kodu'] !== null)
            ->unique('mikro_cari_kodu')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventory
     * @return array<string, mixed>
     */
    private function contractRequiredResponse(array $inventory, string $message, ?string $sourceCode = null): array
    {
        return [
            'status' => 'query_contract_required',
            'message' => $message,
            'candidates' => [],
            'items' => [],
            'excluded_online_retail_count' => 0,
            'source_used' => $sourceCode,
            'source_inventory' => $inventory,
            'existing_sources' => $inventory,
            'query_contract' => [
                'document_path' => 'docs/b2b-mikro-cari-control-query-contract.md',
                'mode' => 'select_only_discovery',
                'discovery_queries' => $this->discoveryQueries(),
                'candidate_schema' => [
                    'mikro_cari_kodu',
                    'mikro_cari_unvan',
                    'cari_grup_kodu',
                    'responsibility_code',
                    'temsilci_kodu',
                    'srm_merkezi',
                    'phone',
                    'email',
                    'city',
                    'district',
                    'address',
                    'tax_no',
                    'suggested_capabilities',
                    'status',
                    'existing_partner_id',
                    'difference_summary',
                ],
            ],
            'actions_enabled' => false,
        ];
    }

    private function sourceOrderSql(): string
    {
        $cases = collect(self::SOURCE_CODES)
            ->values()
            ->map(fn (string $code, int $index): string => "WHEN '{$code}' THEN {$index}")
            ->implode(' ');

        return "CASE code {$cases} ELSE 999 END";
    }
}

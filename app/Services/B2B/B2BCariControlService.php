<?php

namespace App\Services\B2B;

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

        if (! $source) {
            return $this->contractRequiredResponse($inventory, 'Cari adaylari icin uygun aktif SELECT-only data source bulunamadi.');
        }

        try {
            $result = $this->gateway->run($source->code, $this->gatewayFilters($filters), $source);
        } catch (RuntimeException $exception) {
            return $this->contractRequiredResponse(
                $inventory,
                'Cari adaylari gateway uzerinden alinamadi: '.$exception->getMessage(),
                $source->code,
            );
        }

        $normalized = $this->normalizeRows($result['rows'], $filters, $source->code);

        return [
            'status' => 'ok',
            'message' => 'Cari adaylari gateway uzerinden alindi. Otomatik partner acilmaz; secili adaylar islenir.',
            'candidates' => $normalized['candidates'],
            'items' => $normalized['candidates'],
            'excluded_online_retail_count' => $normalized['excluded_online_retail_count'],
            'source_used' => $source->code,
            'source_inventory' => $inventory,
            'existing_sources' => $inventory,
            'gateway_meta' => $result['meta'],
            'actions_enabled' => true,
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sourceInventory(): array
    {
        return DataSource::query()
            ->whereIn('code', self::SOURCE_CODES)
            ->orderByRaw($this->sourceOrderSql())
            ->get(['code', 'name', 'db_type', 'query_template', 'active'])
            ->map(fn (DataSource $source): array => [
                'code' => $source->code,
                'name' => $source->name,
                'db_type' => $source->db_type,
                'active' => (bool) $source->active,
                'usable_for_b2b_cari_control' => $this->isRunnableSource($source),
                'reason' => $this->isRunnableSource($source)
                    ? 'B2B cari adaylari icin SELECT-only gateway kaynagi olarak kullanilabilir.'
                    : 'Kaynak aktif degil veya calistirilabilir SELECT/WITH/EXEC query template yok.',
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
            && preg_match('/\b(SELECT|WITH|EXEC)\b/i', (string) $source->query_template) === 1;
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

        return [
            'candidates' => $candidates,
            'excluded_online_retail_count' => $excludedOnlineRetailCount,
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
            $candidate['tax_no'] ?? null,
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
        $taxNo = $this->value($row, ['tax_no', 'vergi_no', 'vkn', 'tckn']);
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
        return collect(['phone', 'email', 'city', 'district', 'address'])
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

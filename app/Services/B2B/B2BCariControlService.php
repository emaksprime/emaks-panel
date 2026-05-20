<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\DataSource;
use App\Models\TechnicalServiceTechnician;
use App\Services\N8nPanelDataGateway;
use Illuminate\Support\Collection;
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

        return $normalized ?? [
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
        $cityFilter = $this->normalizedText($filters['city'] ?? null);
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $limit = min(250, max(1, (int) ($filters['limit'] ?? 100)));

        $candidates = collect($rows)
            ->map(fn (array $row): ?array => $this->normalizeRow($row, $sourceCode, $existingPartners, $techniciansByCari))
            ->filter(function (?array $candidate) use (&$excludedOnlineRetailCount): bool {
                if (! $candidate) {
                    return false;
                }

                if (($candidate['status'] ?? null) === 'excluded_online_retail') {
                    $excludedOnlineRetailCount++;

                    return false;
                }

                return true;
            })
            ->filter(function (array $candidate) use ($includeReviewRequired): bool {
                return $includeReviewRequired || ($candidate['status'] ?? null) !== 'review_required';
            })
            ->filter(function (array $candidate) use ($requestedCapability): bool {
                if ($requestedCapability === null) {
                    return true;
                }

                return in_array($requestedCapability, $candidate['suggested_capabilities'] ?? [], true);
            })
            ->filter(function (array $candidate) use ($cityFilter): bool {
                if ($cityFilter === '') {
                    return true;
                }

                return str_contains($this->normalizedText($candidate['city'] ?? null), $cityFilter);
            })
            ->slice($offset, $limit)
            ->values()
            ->all();

        return [
            'candidates' => $candidates,
            'excluded_online_retail_count' => $excludedOnlineRetailCount,
        ];
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
        $phone = $this->value($row, ['phone', 'cep_tel', 'telefon', 'gsm', 'cari_CepTel', 'cari_CepTel', 'CepTel']);
        $email = $this->value($row, ['email', 'cari_EMail', 'Cari Email', 'e_mail']);
        $city = $this->value($row, ['city', 'il', 'cari_il']);
        $district = $this->value($row, ['district', 'ilce', 'cari_ilce']);
        $address = $this->value($row, ['address', 'adres', 'cari_adres']);
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
        return strtr($value, [
            'İ' => 'I',
            'I' => 'I',
            'ı' => 'I',
            'Ş' => 'S',
            'ş' => 's',
            'Ğ' => 'G',
            'ğ' => 'g',
            'Ü' => 'U',
            'ü' => 'u',
            'Ö' => 'O',
            'ö' => 'o',
            'Ç' => 'C',
            'ç' => 'c',
        ]);
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

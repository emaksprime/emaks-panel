<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroFixedQueryCatalog;
use App\Services\Mikro\MikroParityContract;
use App\Services\Mikro\MikroParitySource;
use DomainException;
use PHPUnit\Framework\TestCase;

class MikroParityContractTest extends TestCase
{
    private MikroFixedQueryCatalog $queries;

    private MikroParityContract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queries = new MikroFixedQueryCatalog;
        $this->contract = new MikroParityContract($this->queries);
    }

    public function test_parity_sources_are_dedicated_typed_and_do_not_modify_dashboard_sources(): void
    {
        $runtimeQueries = array_slice($this->queries->queryIds(), 0, 21);

        foreach (MikroParitySource::cases() as $source) {
            $definition = $this->contract->source($source);
            $this->assertTrue(str_starts_with($source->value, 'parity_'));
            $this->assertTrue(str_starts_with($source->queryId(), 'parity.'));
            $this->assertNotContains($source->queryId(), $runtimeQueries);
            $this->assertSame(2, $definition['schema_version']);
            $this->assertNotEmpty($definition['response_schema']);
            $this->assertSame(['mikro' => 'Europe/Istanbul', 'n8n' => 'Europe/Istanbul'], $definition['source_timezones']);
        }
    }

    public function test_parity_query_templates_are_server_owned_and_read_only(): void
    {
        foreach (MikroParitySource::cases() as $source) {
            $definition = $this->contract->source($source);
            $sql = $definition['query_template'];

            $this->assertMatchesRegularExpression('/^(SELECT|WITH)\b/i', ltrim($sql));
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|EXEC(?:UTE)?)\b/i', $sql);
            $this->assertSame(hash('sha256', $sql), $definition['query_template_sha256']);
        }
    }

    public function test_parity_allowed_params_are_explicit_and_warehouse_scope_is_one_or_five(): void
    {
        $parameters = ['item_code' => 'STOK-001', 'warehouse_code' => 5, 'as_of_date' => '2026-07-31'];
        $this->assertSame($parameters, $this->contract->validatedParameters(MikroParitySource::STOCK_DETAIL, $parameters));

        foreach ([0, 2, 999] as $warehouse) {
            try {
                $this->contract->validatedParameters(MikroParitySource::STOCK_DETAIL, [
                    'item_code' => 'STOK-001',
                    'warehouse_code' => $warehouse,
                    'as_of_date' => '2026-07-31',
                ]);
                $this->fail('Non-pilot warehouse must fail before network.');
            } catch (DomainException $exception) {
                $this->assertSame('MIKRO_QUERY_PARAMETER_INVALID', $exception->getMessage());
            }
        }
    }

    public function test_contract_versions_and_fingerprint_are_deterministic(): void
    {
        $first = $this->contract->fingerprint();
        $second = (new MikroParityContract(new MikroFixedQueryCatalog))->fingerprint();

        $this->assertSame($first, $second);
        $this->assertSame('mikro-shadow-parity-normalization.v2', MikroParityContract::NORMALIZATION_VERSION);
        $this->assertSame('mikro-shadow-parity-operations.v2', MikroParityContract::OPERATION_CONTRACT_VERSION);
        $this->assertSame('mikro-shadow-parity-samples.v2', MikroParityContract::SAMPLE_POLICY_VERSION);
    }

    public function test_changing_versions_schema_or_normalizer_changes_contract_fingerprint(): void
    {
        $original = $this->contract->contract();
        foreach ([
            ['normalization_version', 'changed'],
            ['operation_contract_version', 'changed'],
            ['sample_policy_version', 'changed'],
        ] as [$field, $value]) {
            $changed = $original;
            $changed[$field] = $value;
            $this->assertNotSame($this->contract->fingerprint(), hash('sha256', $this->contract->canonicalJson($changed)));
        }

        $schemaChanged = $original;
        $schemaChanged['operations']['customer.lookup']['response_schema'][0]['nullable'] = true;
        $normalizerChanged = $original;
        $normalizerChanged['normalizer_rules']['timestamp']['precision'] = 'milliseconds';
        $this->assertNotSame($this->contract->fingerprint(), hash('sha256', $this->contract->canonicalJson($schemaChanged)));
        $this->assertNotSame($this->contract->fingerprint(), hash('sha256', $this->contract->canonicalJson($normalizerChanged)));
    }

    public function test_explicit_schema_declares_type_and_nullability_for_every_critical_field(): void
    {
        foreach ($this->contract->operationKeys() as $operationKey) {
            $operation = $this->contract->operation($operationKey);
            $schema = collect($operation['response_schema'])->keyBy('path');
            foreach ($operation['promotion_critical_fields'] as $critical) {
                $this->assertTrue($schema->has($critical), $operationKey.':'.$critical);
                $field = $schema->get($critical);
                foreach (['type', 'nullable', 'critical', 'identity_role', 'normalizer', 'source_timezone', 'decimal_scale', 'null_empty_equivalence', 'availability'] as $key) {
                    $this->assertArrayHasKey($key, $field, $operationKey.':'.$critical.':'.$key);
                }
                $this->assertTrue($field['critical']);
            }
        }

        $lines = collect($this->contract->operation('order.detail')['response_schema'])->firstWhere('path', 'lines');
        $this->assertIsArray($lines['children']);
        $this->assertContains('line_key', array_column($lines['children'], 'path'));
        $this->assertContains('unit_code', array_column($lines['children'], 'path'));
    }

    public function test_missing_critical_field_is_not_defaulted_and_wrong_type_is_contract_error(): void
    {
        $missing = $this->contract->normalizeN8n(MikroParitySource::CUSTOMER_DETAIL, [[
            'record_id' => '123e4567-e89b-42d3-a456-426614174000',
            'customer_code' => '120.01',
        ]]);
        $wrongType = $this->customerRow();
        $wrongType['title_1'] = ['not', 'text'];
        $wrong = $this->contract->normalizeN8n(MikroParitySource::CUSTOMER_DETAIL, [$wrongType]);

        $this->assertSame('CONTRACT_ERROR', $missing['status']);
        $this->assertContains('title_1', $missing['missing_source_fields']);
        $this->assertArrayNotHasKey('currency_code', $missing['envelope']);
        $this->assertSame('CONTRACT_ERROR', $wrong['status']);
        $this->assertContains('title_1:TYPE_STRING', $wrong['missing_source_fields']);
    }

    public function test_nullable_false_rejects_null_and_unknown_raw_fields_do_not_escape(): void
    {
        $null = $this->customerRow();
        $null['record_id'] = null;
        $nullResult = $this->contract->normalizeN8n(MikroParitySource::CUSTOMER_DETAIL, [$null]);
        $valid = $this->customerRow();
        $valid['raw_provider_secret_field'] = 'must-not-escape';
        $validResult = $this->contract->normalizeN8n(MikroParitySource::CUSTOMER_DETAIL, [$valid]);

        $this->assertSame('CONTRACT_ERROR', $nullResult['status']);
        $this->assertContains('record_id:NULL_NOT_ALLOWED', $nullResult['missing_source_fields']);
        $this->assertArrayNotHasKey('raw_provider_secret_field', $validResult['envelope']);
    }

    public function test_iso_and_mssql_same_instant_still_normalize_equal(): void
    {
        $this->assertSame(
            $this->contract->canonicalTimestamp('2026-07-31T10:15:30Z', 'UTC'),
            $this->contract->canonicalTimestamp('2026-07-31 13:15:30', 'Europe/Istanbul'),
        );
    }

    public function test_date_and_timestamp_are_not_conflated(): void
    {
        $this->assertSame('2026-07-31', $this->contract->canonicalDate('2026-07-31 13:15:30', 'Europe/Istanbul'));
        $this->assertSame('2026-07-31T10:15:30Z', $this->contract->canonicalTimestamp('2026-07-31 13:15:30', 'Europe/Istanbul'));
    }

    public function test_naive_timestamp_still_requires_source_timezone(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SOURCE_TIMEZONE_UNAVAILABLE');
        $this->contract->canonicalTimestamp('2026-07-31 13:15:30', null);
    }

    public function test_invalid_and_dst_ambiguous_timestamps_are_contract_errors(): void
    {
        foreach ([
            ['not-a-timestamp', 'Europe/Istanbul', 'MIKRO_PARITY_TIMESTAMP_INVALID'],
            ['2026-03-29 02:30:00', 'Europe/Berlin', 'MIKRO_PARITY_TIMESTAMP_INVALID'],
            ['2026-10-25 02:30:00', 'Europe/Berlin', 'MIKRO_PARITY_TIMESTAMP_AMBIGUOUS'],
        ] as [$value, $timezone, $error]) {
            try {
                $this->contract->canonicalTimestamp($value, $timezone);
                $this->fail($value.' must fail.');
            } catch (DomainException $exception) {
                $this->assertSame($error, $exception->getMessage());
            }
        }
    }

    public function test_timestamp_normalization_is_timezone_safe_and_idempotent(): void
    {
        $canonical = $this->contract->canonicalTimestamp('2026-01-15 12:00:00', 'Europe/Berlin');
        $this->assertSame('2026-01-15T11:00:00Z', $canonical);
        $this->assertSame($canonical, $this->contract->canonicalTimestamp($canonical, 'Europe/Istanbul'));
    }

    public function test_non_leap_year_february_29_is_contract_error(): void
    {
        $this->assertDateContractError('2026-02-29');
        $this->assertTimestampContractError('2026-02-29T10:15:30Z');
    }

    public function test_valid_leap_year_february_29_is_accepted(): void
    {
        $this->assertSame('2024-02-29', $this->contract->canonicalDate('2024-02-29', 'UTC'));
        $this->assertSame(
            '2024-02-29T10:15:30Z',
            $this->contract->canonicalTimestamp('2024-02-29T10:15:30Z', 'UTC'),
        );
    }

    public function test_april_31_is_contract_error(): void
    {
        $this->assertDateContractError('2026-04-31');
        $this->assertTimestampContractError('2026-04-31T10:15:30+03:00');
    }

    public function test_zero_and_thirteenth_month_are_contract_errors(): void
    {
        $this->assertDateContractError('2026-00-10');
        $this->assertDateContractError('2026-13-10');
    }

    public function test_explicit_offset_hour_24_is_contract_error(): void
    {
        $this->assertTimestampContractError('2026-07-31T10:15:30+24:00');
    }

    public function test_explicit_offset_above_iso_limit_is_contract_error(): void
    {
        $this->assertTimestampContractError('2026-07-31T10:15:30-15:00');
    }

    public function test_offset_hour_14_requires_zero_minutes(): void
    {
        $this->assertTimestampContractError('2026-07-31T10:15:30+14:01');
        $this->assertSame(
            '2026-07-30T20:15:30Z',
            $this->contract->canonicalTimestamp('2026-07-31T10:15:30+14:00', 'UTC'),
        );
    }

    public function test_timestamp_hour_24_is_contract_error(): void
    {
        $this->assertTimestampContractError('2026-07-31T24:15:30Z');
    }

    public function test_timestamp_minute_60_is_contract_error(): void
    {
        $this->assertTimestampContractError('2026-07-31T10:60:30Z');
    }

    public function test_timestamp_second_60_is_contract_error(): void
    {
        $this->assertTimestampContractError('2026-07-31T10:15:60Z');
    }

    public function test_parser_warnings_are_contract_error(): void
    {
        $this->assertDateContractError('2026-02-29');
        $this->assertTimestampContractError('2026-04-31 10:15:30');
    }

    public function test_valid_z_timestamp_round_trips_exactly(): void
    {
        $value = '2026-07-31T10:15:30Z';

        $this->assertSame($value, $this->contract->canonicalTimestamp($value, 'Europe/Istanbul'));
    }

    public function test_valid_positive_offset_normalizes_to_utc(): void
    {
        $this->assertSame(
            '2026-07-31T07:15:30Z',
            $this->contract->canonicalTimestamp('2026-07-31T10:15:30+03:00', 'UTC'),
        );
    }

    public function test_valid_negative_offset_normalizes_to_utc(): void
    {
        $this->assertSame(
            '2026-07-31T15:15:30Z',
            $this->contract->canonicalTimestamp('2026-07-31T10:15:30-05:00', 'UTC'),
        );
    }

    public function test_rfc3339_negative_zero_offset_matches_z_and_positive_zero_without_changing_the_contract(): void
    {
        $canonical = $this->contract->canonicalTimestamp('2026-07-31T10:15:30Z', 'UTC');
        $contract = $this->contract->contract();

        $this->assertSame($canonical, $this->contract->canonicalTimestamp('2026-07-31T10:15:30+00:00', 'UTC'));
        $this->assertSame($canonical, $this->contract->canonicalTimestamp('2026-07-31T10:15:30-00:00', 'UTC'));
        $this->assertSame('2026-07-31T07:15:30Z', $this->contract->canonicalTimestamp('2026-07-31T10:15:30+03:00', 'UTC'));
        $this->assertSame('2026-07-31T13:15:30Z', $this->contract->canonicalTimestamp('2026-07-31T10:15:30-03:00', 'UTC'));
        $this->assertSame('mikro-shadow-parity-normalization.v2', $contract['normalization_version']);
        $this->assertSame('mikro-shadow-parity-operations.v2', $contract['operation_contract_version']);
        $this->assertSame('mikro-shadow-parity-samples.v2', $contract['sample_policy_version']);
        $this->assertSame('1a16f2f0371ee6e150702405a1bc35533624dbf77fef56b8de87cc08cd59dfdb', $this->contract->fingerprint());
    }

    public function test_existing_fractional_second_and_space_separator_contract_is_preserved(): void
    {
        $this->assertSame(
            '2026-07-31T07:15:30Z',
            $this->contract->canonicalTimestamp('2026-07-31 10:15:30.123456+03:00', 'UTC'),
        );
        $this->assertSame(
            '2026-07-31T10:15:30Z',
            $this->contract->canonicalTimestamp('2026-07-31 10:15:30.1', 'UTC'),
        );
    }

    public function test_timestamp_normalization_remains_idempotent(): void
    {
        $canonical = $this->contract->canonicalTimestamp('2026-07-31T10:15:30+03:00', 'UTC');

        $this->assertSame($canonical, $this->contract->canonicalTimestamp($canonical, 'Europe/Istanbul'));
    }

    public function test_invalid_value_is_never_silently_rolled_forward(): void
    {
        foreach ([
            '2026-02-29T10:15:30Z',
            '2026-04-31T10:15:30+03:00',
            '2026-07-31T10:15:30+24:00',
        ] as $value) {
            $this->assertTimestampContractError($value);
        }
    }

    public function test_stock_on_hand_is_not_mislabelled_as_available_and_operation_blockers_remain(): void
    {
        $source = $this->contract->source(MikroParitySource::STOCK_DETAIL);
        $operation = $this->contract->operation('stock.availability');

        $this->assertStringContainsString('AS on_hand_quantity', $source['query_template']);
        $this->assertStringNotContainsString('AS available_quantity', $source['query_template']);
        $this->assertSame(['item_code', 'warehouse_code'], $operation['identity_key']);
        $this->assertSame(['item_code', 'warehouse_code', 'as_of_date'], $operation['sample_identity_contract']);
        $this->assertArrayHasKey('reserved_quantity', $operation['unavailable_fields']);
        $this->assertArrayHasKey('available_quantity', $operation['unavailable_fields']);
        $this->assertArrayHasKey('on_hand_quantity', $operation['semantic_blockers']);
    }

    public function test_discovery_emits_strata_without_defaulting_unavailable_business_fields(): void
    {
        $customer = $this->contract->normalizeN8n(MikroParitySource::CUSTOMER_DISCOVERY, [$this->customerRow()]);
        $stock = $this->contract->normalizeN8n(MikroParitySource::STOCK_DISCOVERY, [$this->stockRow()]);

        $this->assertSame(['active'], $customer['envelope']['samples'][0]['strata']);
        $this->assertSame(['currency' => '0'], $customer['envelope']['samples'][0]['strata_dimensions']);
        $this->assertContains('in_stock', $stock['envelope']['samples'][0]['strata']);
        $this->assertContains('serial_tracked', $stock['envelope']['samples'][0]['strata']);
        $this->assertArrayNotHasKey('reserved_quantity', $stock['envelope']['samples'][0]['lookup']);
    }

    public function test_one_blocked_operation_does_not_hide_other_operation_readiness(): void
    {
        foreach ($this->contract->operationKeys() as $operationKey) {
            $readiness = $this->contract->operationReadiness($operationKey);
            $this->assertSame('TYPED_SCHEMA_READY', $readiness['source_contract']);
            $this->assertTrue($readiness['schema_compiled']);
            $this->assertSame('CONTRACT_FIELD_UNAVAILABLE', $readiness['parity_readiness']);
        }
    }

    public function test_contract_contains_no_secret_or_raw_pii_field(): void
    {
        $json = strtolower($this->contract->canonicalJson($this->contract->contract()));

        foreach (['api_key', 'password', 'authorization', 'tax_number', 'phone_number', 'street_address'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    private function assertDateContractError(string $value): void
    {
        try {
            $this->contract->canonicalDate($value, 'Europe/Istanbul');
            $this->fail($value.' must be rejected as an invalid date.');
        } catch (DomainException $exception) {
            $this->assertSame('MIKRO_PARITY_DATE_INVALID', $exception->getMessage());
        }
    }

    private function assertTimestampContractError(string $value): void
    {
        try {
            $this->contract->canonicalTimestamp($value, 'Europe/Istanbul');
            $this->fail($value.' must be rejected as an invalid timestamp.');
        } catch (DomainException $exception) {
            $this->assertSame('MIKRO_PARITY_TIMESTAMP_INVALID', $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function customerRow(): array
    {
        return [
            'record_id' => '123e4567-e89b-42d3-a456-426614174000',
            'customer_code' => 'C001',
            'title_1' => 'Customer',
            'title_2' => null,
            'customer_group_code' => null,
            'active_abandon_code' => 0,
            'company_open_closed_flag' => 0,
            'locked_flag' => 0,
            'currency_index' => 0,
            'source_updated_at' => '2026-07-31 13:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function stockRow(): array
    {
        return [
            'record_id' => '123e4567-e89b-42d3-a456-426614174000',
            'item_code' => 'STOK-001',
            'warehouse_code' => 5,
            'unit_name' => 'ADET',
            'on_hand_quantity' => '4.000000',
            'serial_tracking_code' => 3,
            'item_active_flag' => 1,
            'source_updated_at' => '2026-07-31 13:00:00',
        ];
    }
}

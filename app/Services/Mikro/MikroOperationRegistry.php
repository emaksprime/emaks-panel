<?php

namespace App\Services\Mikro;

use DomainException;

class MikroOperationRegistry
{
    public const BLOCKED_UNKNOWN = 'MIKRO_OPERATION_UNKNOWN';

    public const BLOCKED_DENIED = 'MIKRO_OPERATION_DENIED';

    /**
     * @var array<string, array<string, mixed>>
     */
    private const READ_OPERATIONS = [
        'health.check' => [
            'operation_key' => 'health.check',
            'endpoint' => '/Api/APIMethods/HealthCheck',
            'method' => 'GET',
            'api_version' => 'V17',
            'mode' => 'READ',
            'enabled' => true,
            'required_context' => [],
            'request_type' => 'MikroHealthCheckRequest',
            'response_type' => 'MikroHealthCheckResult',
            'timeout' => 15,
            'retry_policy' => 'none',
            'source_mode' => 'mikro',
            'n8n_fallback' => false,
            'parity_fields' => ['status', 'success', 'duration_ms'],
        ],
        'customer.list' => [
            'operation_key' => 'customer.list',
            'endpoint' => '/Api/APIMethods/CariListesiV3',
            'method' => 'POST',
            'api_version' => 'V17',
            'mode' => 'READ',
            'enabled' => true,
            'required_context' => ['api_key', 'working_year', 'firm_code', 'user_code', 'password'],
            'request_type' => 'MikroCustomerListQuery',
            'response_type' => 'MikroCustomerListResult',
            'timeout' => 15,
            'retry_policy' => 'none',
            'source_mode' => 'shadow_compare',
            'n8n_fallback' => true,
            'parity_fields' => ['customer_code', 'title', 'tax_number'],
        ],
        'stock.list' => [
            'operation_key' => 'stock.list',
            'endpoint' => '/Api/APIMethods/StokListesiV2',
            'method' => 'POST',
            'api_version' => 'V17',
            'mode' => 'READ',
            'enabled' => true,
            'required_context' => ['api_key', 'working_year', 'firm_code', 'user_code', 'password'],
            'request_type' => 'MikroStockListQuery',
            'response_type' => 'MikroStockListResult',
            'timeout' => 15,
            'retry_policy' => 'none',
            'source_mode' => 'shadow_compare',
            'n8n_fallback' => true,
            'parity_fields' => ['stock_code', 'name', 'availability'],
        ],
    ];

    /**
     * @var array<int, string>
     */
    private const DENIED_OPERATIONS = [
        'generic.call',
        'sql.read',
        'sql.write',
        'record.save',
        'record.update',
        'record.delete',
        'stock.save',
        'stock.movement.create',
        'order.create',
        'order.update',
        'order.delete',
        'invoice.create',
        'dispatch.create',
        'return.create',
        'exchange.create',
    ];

    /**
     * @return array<string, mixed>
     */
    public function read(string $operationKey): array
    {
        if (in_array($operationKey, self::DENIED_OPERATIONS, true)) {
            throw new DomainException(self::BLOCKED_DENIED);
        }

        $operation = self::READ_OPERATIONS[$operationKey] ?? null;

        if (! is_array($operation) || ! ($operation['enabled'] ?? false) || ($operation['mode'] ?? null) !== 'READ') {
            throw new DomainException(self::BLOCKED_UNKNOWN);
        }

        return $operation;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enabledReads(): array
    {
        return array_values(self::READ_OPERATIONS);
    }

    /**
     * @return array<int, string>
     */
    public function deniedOperations(): array
    {
        return self::DENIED_OPERATIONS;
    }

    /**
     * @return array{status:string,read_count:int,write_count:int,enabled_keys:array<int,string>}
     */
    public function summary(): array
    {
        return [
            'status' => 'active',
            'read_count' => count(self::READ_OPERATIONS),
            'write_count' => 0,
            'enabled_keys' => array_keys(self::READ_OPERATIONS),
        ];
    }

    public function baseUrlBlocker(?string $baseUrl): ?string
    {
        $baseUrl = trim((string) $baseUrl);

        if ($baseUrl === '') {
            return null;
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return 'MIKRO_BASE_URL_SCHEME_INVALID';
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'MIKRO_BASE_URL_AUTHORITY_INVALID';
        }

        $path = (string) ($parts['path'] ?? '');
        if (! in_array($path, ['', '/'], true)) {
            return 'MIKRO_BASE_URL_MUST_BE_ORIGIN';
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '') {
            return 'MIKRO_BASE_URL_HOST_MISSING';
        }

        $allowedHosts = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) config('services.mikro_api.allowed_hosts', []),
        );

        if (in_array($host, array_filter($allowedHosts), true)) {
            return null;
        }

        $isLocalRuntime = app()->environment(['local', 'testing']);
        if ($isLocalRuntime && ($this->isPrivateIp($host)
            || $this->isLoopback($host)
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.example.test'))) {
            return null;
        }

        return 'MIKRO_BASE_URL_PUBLIC_HOST_DENIED';
    }

    private function isLoopback(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_starts_with($host, '127.');
    }

    private function isPrivateIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $value = ip2long($host);

            return $value !== false && (
                ($value >= ip2long('10.0.0.0') && $value <= ip2long('10.255.255.255'))
                || ($value >= ip2long('172.16.0.0') && $value <= ip2long('172.31.255.255'))
                || ($value >= ip2long('192.168.0.0') && $value <= ip2long('192.168.255.255'))
            );
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = strtolower($host);

            return str_starts_with($normalized, 'fc') || str_starts_with($normalized, 'fd');
        }

        return false;
    }
}

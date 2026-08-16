<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

final class IsolatedPostgreSqlEnvironment
{
    public const CANONICAL_PORT = 15433;

    public const CANONICAL_DATABASE = 'emaks92_eecfec2da752';

    public const QUARANTINE_DATABASE_PREFIX = 'emaks92_eecfec2da752_wiped_';

    public const DATABASE_PREFIX = 'emaks_pr92_rel4g_test_';

    public const CONTAINER_PREFIX = 'emaks-pr92-rel4g-wp0a-db-';

    public const NETWORK_PREFIX = 'emaks-pr92-rel4g-wp0a-net-';

    public const SCOPE_LABEL = 'wp0a';

    private const DATABASE_ALIAS_VARIABLES = [
        'DB_URL',
        'DATABASE_URL',
        'PGSERVICE',
        'PGSERVICEFILE',
    ];

    private const REQUIRED_ENVIRONMENT_VARIABLES = [
        'APP_ENV',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'PGHOST',
        'PGPORT',
        'PGDATABASE',
        'PGUSER',
        'PGPASSWORD',
        'REL4G_CONTAINER_ID',
        'REL4G_CONTAINER_NAME',
        'REL4G_NETWORK_ID',
        'REL4G_NETWORK_NAME',
        'REL4G_NONCE',
        'REL4G_SCOPE',
        'REL4G_DOCKER_BINARY',
        'REL4G_WORKER_PID_REGISTRY',
    ];

    /**
     * @param  array<string, string|null>|null  $environment
     * @return array{host: string, port: int, database: string, nonce: string, container_id: string, container_name: string, network_id: string, network_name: string}
     */
    public static function assertProcessEnvironment(?array $environment = null): array
    {
        $environment ??= self::currentEnvironment();

        $database = $environment['DB_DATABASE'] ?? null;

        if (! is_string($database) || $database === '') {
            throw new RuntimeException('TEST_DATABASE_GUARD_BLOCKED_DATABASE_NAME_EMPTY');
        }

        if (hash_equals(self::CANONICAL_DATABASE, $database)) {
            throw new RuntimeException('TEST_DATABASE_GUARD_BLOCKED_CANONICAL_UAT');
        }

        if (str_starts_with($database, self::QUARANTINE_DATABASE_PREFIX)) {
            throw new RuntimeException('TEST_DATABASE_GUARD_BLOCKED_QUARANTINE_DATABASE');
        }

        self::requireSame('testing', $environment['APP_ENV'] ?? null, 'app_environment');
        self::requireSame('pgsql', $environment['DB_CONNECTION'] ?? null, 'database_driver');
        self::requireSame('127.0.0.1', $environment['DB_HOST'] ?? null, 'database_host');

        $portValue = $environment['DB_PORT'] ?? null;

        if (! is_string($portValue) || ! ctype_digit($portValue)) {
            throw new RuntimeException('rel4g_guard:database_port_invalid');
        }

        $port = (int) $portValue;

        if ($port < 1024 || $port > 65535 || $port === self::CANONICAL_PORT) {
            throw new RuntimeException('rel4g_guard:canonical_or_invalid_port');
        }

        $nonce = $environment['REL4G_NONCE'] ?? null;

        if (! is_string($nonce) || preg_match('/^[a-f0-9]{12}$/D', $nonce) !== 1) {
            throw new RuntimeException('rel4g_guard:nonce_invalid');
        }

        $expectedDatabase = self::DATABASE_PREFIX.$nonce;

        self::requireSame($expectedDatabase, $database, 'database_name');

        $username = $environment['DB_USERNAME'] ?? null;
        $password = $environment['DB_PASSWORD'] ?? null;

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('rel4g_guard:generated_credentials_missing');
        }

        self::requireSame('127.0.0.1', $environment['PGHOST'] ?? null, 'postgres_host_alias');
        self::requireSame($portValue, $environment['PGPORT'] ?? null, 'postgres_port_alias');
        self::requireSame($database, $environment['PGDATABASE'] ?? null, 'postgres_database_alias');
        self::requireSame($username, $environment['PGUSER'] ?? null, 'postgres_user_alias');
        self::requireSame($password, $environment['PGPASSWORD'] ?? null, 'postgres_password_alias');

        foreach (self::DATABASE_ALIAS_VARIABLES as $variable) {
            $value = $environment[$variable] ?? null;

            if ($value !== null && $value !== '') {
                throw new RuntimeException('rel4g_guard:database_url_or_service_alias_present');
            }
        }

        $containerId = $environment['REL4G_CONTAINER_ID'] ?? null;
        $networkId = $environment['REL4G_NETWORK_ID'] ?? null;

        if (! is_string($containerId) || preg_match('/^[a-f0-9]{64}$/D', $containerId) !== 1) {
            throw new RuntimeException('rel4g_guard:container_id_invalid');
        }

        if (! is_string($networkId) || preg_match('/^[a-f0-9]{64}$/D', $networkId) !== 1) {
            throw new RuntimeException('rel4g_guard:network_id_invalid');
        }

        $containerName = $environment['REL4G_CONTAINER_NAME'] ?? null;
        $networkName = $environment['REL4G_NETWORK_NAME'] ?? null;

        self::requireSame(self::CONTAINER_PREFIX.$nonce, $containerName, 'container_name');
        self::requireSame(self::NETWORK_PREFIX.$nonce, $networkName, 'network_name');
        self::requireSame(self::SCOPE_LABEL, $environment['REL4G_SCOPE'] ?? null, 'scope_label');

        $dockerBinary = $environment['REL4G_DOCKER_BINARY'] ?? null;

        if (! is_string($dockerBinary) || $dockerBinary === '' || ! is_file($dockerBinary)) {
            throw new RuntimeException('rel4g_guard:docker_binary_invalid');
        }

        $registry = $environment['REL4G_WORKER_PID_REGISTRY'] ?? null;

        if (! is_string($registry) || $registry === '' || ! is_file($registry)) {
            throw new RuntimeException('rel4g_guard:worker_registry_invalid');
        }

        $registryDirectory = basename(dirname($registry));

        if (! str_starts_with($registryDirectory, 'emaks-pr92-rel4g-wp0a-')) {
            throw new RuntimeException('rel4g_guard:worker_registry_scope_invalid');
        }

        return [
            'host' => '127.0.0.1',
            'port' => $port,
            'database' => $expectedDatabase,
            'nonce' => $nonce,
            'container_id' => $containerId,
            'container_name' => $containerName,
            'network_id' => $networkId,
            'network_name' => $networkName,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public static function currentEnvironment(): array
    {
        $environment = [];

        foreach (array_merge(self::REQUIRED_ENVIRONMENT_VARIABLES, self::DATABASE_ALIAS_VARIABLES) as $name) {
            $value = getenv($name);
            $environment[$name] = $value === false ? null : $value;
        }

        return $environment;
    }

    public static function assertConfigurationCacheAbsent(string $projectRoot): void
    {
        if (is_file($projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php')) {
            throw new RuntimeException('rel4g_guard:configuration_cache_present');
        }
    }

    public static function assertLaravelConfiguration(ConfigRepository $config): void
    {
        $facts = self::assertProcessEnvironment();
        $connection = $config->get('database.connections.pgsql');

        if (! is_array($connection)) {
            throw new RuntimeException('rel4g_guard:pgsql_configuration_missing');
        }

        self::requireSame('testing', $config->get('app.env'), 'resolved_app_environment');
        self::requireSame('pgsql', $config->get('database.default'), 'resolved_database_driver');
        self::requireSame('pgsql', $connection['driver'] ?? null, 'resolved_pgsql_driver');

        $url = $connection['url'] ?? null;

        if ($url !== null && $url !== '') {
            throw new RuntimeException('rel4g_guard:resolved_database_url_present');
        }

        self::requireSame($facts['host'], $connection['host'] ?? null, 'resolved_database_host');
        self::requireSame((string) $facts['port'], (string) ($connection['port'] ?? ''), 'resolved_database_port');
        self::requireSame($facts['database'], $connection['database'] ?? null, 'resolved_database_name');
        self::requireSame(getenv('DB_USERNAME') ?: null, $connection['username'] ?? null, 'resolved_database_username');
        self::requireSame(getenv('DB_PASSWORD') ?: null, $connection['password'] ?? null, 'resolved_database_password');
    }

    /**
     * @return array{major: int, database: string, driver: string}
     */
    public static function assertConnectedDatabase(ConnectionInterface $connection): array
    {
        $facts = self::assertProcessEnvironment();
        $driver = (string) $connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        self::requireSame('pgsql', $driver, 'connected_database_driver');

        $row = $connection->selectOne(<<<'SQL'
            select
                current_setting('server_version_num')::integer as version_number,
                current_database() as database_name
            SQL);

        if (! is_object($row)) {
            throw new RuntimeException('rel4g_guard:database_identity_unavailable');
        }

        $versionNumber = (int) ($row->version_number ?? 0);
        $major = intdiv($versionNumber, 10000);
        $database = (string) ($row->database_name ?? '');

        if ($major !== 16) {
            throw new RuntimeException('rel4g_guard:postgres_major_mismatch');
        }

        self::requireSame($facts['database'], $database, 'connected_database_name');

        return [
            'major' => $major,
            'database' => $database,
            'driver' => $driver,
        ];
    }

    /**
     * @return array{mount_type: string, mount_count: int, network_container_count: int, host_ip: string, host_port: int}
     */
    public static function assertDockerIsolation(): array
    {
        $facts = self::assertProcessEnvironment();
        $containerId = self::docker(['inspect', '--format', '{{.Id}}', $facts['container_id']]);
        $containerName = ltrim(self::docker(['inspect', '--format', '{{.Name}}', $facts['container_id']]), '/');
        $scope = self::docker(['inspect', '--format', '{{index .Config.Labels "emaks.rel4g.scope"}}', $facts['container_id']]);
        $nonce = self::docker(['inspect', '--format', '{{index .Config.Labels "emaks.rel4g.nonce"}}', $facts['container_id']]);
        $networkMode = self::docker(['inspect', '--format', '{{.HostConfig.NetworkMode}}', $facts['container_id']]);

        self::requireSame($facts['container_id'], $containerId, 'inspected_container_id');
        self::requireSame($facts['container_name'], $containerName, 'inspected_container_name');
        self::requireSame(self::SCOPE_LABEL, $scope, 'inspected_container_scope');
        self::requireSame($facts['nonce'], $nonce, 'inspected_container_nonce');
        self::requireSame($facts['network_name'], $networkMode, 'container_network_mode');

        $mounts = self::decodeJson(self::docker(['inspect', '--format', '{{json .Mounts}}', $facts['container_id']]), 'container_mounts');

        if (! is_array($mounts) || count($mounts) !== 1) {
            throw new RuntimeException('rel4g_guard:persistent_or_unexpected_mount');
        }

        $mount = $mounts[0] ?? null;

        if (! is_array($mount)
            || ($mount['Type'] ?? null) !== 'tmpfs'
            || ($mount['Destination'] ?? null) !== '/var/lib/postgresql/data') {
            throw new RuntimeException('rel4g_guard:postgres_data_not_tmpfs');
        }

        $bindings = preg_split('/\R/', self::docker(['port', $facts['container_id'], '5432/tcp']), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($bindings) || count($bindings) !== 1) {
            throw new RuntimeException('rel4g_guard:postgres_port_binding_invalid');
        }

        if (preg_match('/^(127\.0\.0\.1):(\d+)$/D', $bindings[0], $matches) !== 1) {
            throw new RuntimeException('rel4g_guard:postgres_bind_address_mismatch');
        }

        $hostIp = $matches[1];
        $hostPort = (int) $matches[2];

        self::requireSame('127.0.0.1', $hostIp, 'postgres_bind_address');

        if ($hostPort !== $facts['port'] || $hostPort === self::CANONICAL_PORT) {
            throw new RuntimeException('rel4g_guard:postgres_dynamic_port_mismatch');
        }

        $networkId = self::docker(['network', 'inspect', '--format', '{{.Id}}', $facts['network_id']]);
        $networkName = self::docker(['network', 'inspect', '--format', '{{.Name}}', $facts['network_id']]);
        $networkScope = self::docker(['network', 'inspect', '--format', '{{index .Labels "emaks.rel4g.scope"}}', $facts['network_id']]);
        $networkNonce = self::docker(['network', 'inspect', '--format', '{{index .Labels "emaks.rel4g.nonce"}}', $facts['network_id']]);
        $containers = self::decodeJson(self::docker(['network', 'inspect', '--format', '{{json .Containers}}', $facts['network_id']]), 'network_containers');

        self::requireSame($facts['network_id'], $networkId, 'inspected_network_id');
        self::requireSame($facts['network_name'], $networkName, 'inspected_network_name');
        self::requireSame(self::SCOPE_LABEL, $networkScope, 'inspected_network_scope');
        self::requireSame($facts['nonce'], $networkNonce, 'inspected_network_nonce');

        if (! is_array($containers) || count($containers) !== 1 || ! array_key_exists($facts['container_id'], $containers)) {
            throw new RuntimeException('rel4g_guard:network_membership_invalid');
        }

        return [
            'mount_type' => 'tmpfs',
            'mount_count' => 1,
            'network_container_count' => 1,
            'host_ip' => $hostIp,
            'host_port' => $hostPort,
        ];
    }

    /**
     * @param  array{id: string, name: string, scope: string, nonce: string}  $expected
     * @param  array{id: string, name: string, scope: string, nonce: string}  $observed
     */
    public static function assertCleanupIdentity(array $expected, array $observed): void
    {
        foreach (['id', 'name', 'scope', 'nonce'] as $key) {
            if (! hash_equals($expected[$key], $observed[$key])) {
                throw new RuntimeException('rel4g_cleanup_guard:'.$key.'_mismatch');
            }
        }
    }

    /**
     * @return array{id: string, name: string, scope: string, nonce: string}
     */
    public static function cleanupIdentityFromEnvironment(): array
    {
        $facts = self::assertProcessEnvironment();

        return [
            'id' => $facts['container_id'],
            'name' => $facts['container_name'],
            'scope' => self::SCOPE_LABEL,
            'nonce' => $facts['nonce'],
        ];
    }

    private static function requireSame(mixed $expected, mixed $actual, string $code): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('rel4g_guard:'.$code.'_mismatch');
        }
    }

    private static function docker(array $arguments): string
    {
        $binary = getenv('REL4G_DOCKER_BINARY');

        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('rel4g_guard:docker_binary_missing');
        }

        $process = new Process(array_merge([$binary], $arguments), dirname(__DIR__, 2));
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('rel4g_guard:bounded_docker_inspect_failed');
        }

        return trim($process->getOutput());
    }

    private static function decodeJson(string $json, string $code): mixed
    {
        try {
            return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('rel4g_guard:'.$code.'_invalid');
        }
    }
}

<?php

namespace App\Services\Mikro;

use Illuminate\Support\Facades\Cache;

class MikroRuntimeState
{
    public const FAILURE_THRESHOLD = 3;

    public const OPEN_SECONDS = 30;

    private const SNAPSHOT_TTL_SECONDS = 86400;

    /**
     * @return array{allowed:bool,circuit_state:string}
     */
    public function beforeRequest(string $origin, string $operationKey): array
    {
        $state = $this->circuit($origin, $operationKey);

        if (($state['circuit_state'] ?? 'CLOSED') !== 'OPEN') {
            return ['allowed' => true, 'circuit_state' => 'CLOSED'];
        }

        $openUntil = (int) ($state['open_until'] ?? 0);
        if ($openUntil > now()->getTimestamp()) {
            return ['allowed' => false, 'circuit_state' => 'OPEN'];
        }

        $probeKey = $this->circuitKey($origin, $operationKey).':half-open-probe';
        if (! Cache::add($probeKey, true, self::OPEN_SECONDS)) {
            return ['allowed' => false, 'circuit_state' => 'HALF_OPEN'];
        }

        return ['allowed' => true, 'circuit_state' => 'HALF_OPEN'];
    }

    public function recordSuccess(string $origin, string $operationKey): void
    {
        Cache::forget($this->circuitKey($origin, $operationKey));
        Cache::forget($this->circuitKey($origin, $operationKey).':half-open-probe');
    }

    public function recordTransientFailure(string $origin, string $operationKey): void
    {
        $key = $this->circuitKey($origin, $operationKey);
        $state = $this->circuit($origin, $operationKey);
        $failures = (int) ($state['failure_count'] ?? 0) + 1;
        $wasHalfOpen = (int) ($state['open_until'] ?? 0) <= now()->getTimestamp()
            && ($state['circuit_state'] ?? 'CLOSED') === 'OPEN';

        $next = [
            'failure_count' => $failures,
            'circuit_state' => 'CLOSED',
            'open_until' => null,
        ];

        if ($wasHalfOpen || $failures >= self::FAILURE_THRESHOLD) {
            $next['circuit_state'] = 'OPEN';
            $next['open_until'] = now()->addSeconds(self::OPEN_SECONDS)->getTimestamp();
        }

        Cache::put($key, $next, now()->addDay());
        Cache::forget($key.':half-open-probe');
    }

    public function resetCircuit(string $origin, string $operationKey): void
    {
        $this->recordSuccess($origin, $operationKey);
    }

    /**
     * @return array{failure_count:int,circuit_state:string,open_until:?int}
     */
    public function circuit(string $origin, string $operationKey): array
    {
        $state = Cache::get($this->circuitKey($origin, $operationKey));

        return is_array($state) ? [
            'failure_count' => (int) ($state['failure_count'] ?? 0),
            'circuit_state' => (string) ($state['circuit_state'] ?? 'CLOSED'),
            'open_until' => isset($state['open_until']) ? (int) $state['open_until'] : null,
        ] : [
            'failure_count' => 0,
            'circuit_state' => 'CLOSED',
            'open_until' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     */
    public function storeLastGood(
        string $operationKey,
        array $filters,
        array $data,
        string $source,
        string $freshnessAt,
    ): void {
        Cache::put($this->snapshotKey($operationKey, $filters), [
            'operation_key' => $operationKey,
            'filter_fingerprint' => $this->filterFingerprint($filters),
            'data' => $data,
            'source' => $source,
            'freshness_at' => $freshnessAt,
        ], self::SNAPSHOT_TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastGood(string $operationKey, array $filters): ?array
    {
        $snapshot = Cache::get($this->snapshotKey($operationKey, $filters));

        return is_array($snapshot) ? $snapshot : null;
    }

    public function filterFingerprint(array $filters): string
    {
        $this->sortRecursive($filters);

        return hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
    }

    private function circuitKey(string $origin, string $operationKey): string
    {
        return 'mikro:circuit:'.hash('sha256', strtolower(trim($origin))).':'.hash('sha256', $operationKey);
    }

    private function snapshotKey(string $operationKey, array $filters): string
    {
        return 'mikro:last-good:'.hash('sha256', $operationKey.':'.$this->filterFingerprint($filters));
    }

    private function sortRecursive(array &$values): void
    {
        foreach ($values as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }

        if (! array_is_list($values)) {
            ksort($values);
        }
    }
}

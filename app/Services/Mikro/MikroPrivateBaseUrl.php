<?php

namespace App\Services\Mikro;

use InvalidArgumentException;
use JsonSerializable;
use ValueError;

final class MikroPrivateBaseUrl implements JsonSerializable
{
    private readonly string $value;

    public function __construct(string $value)
    {
        try {
            $parts = parse_url(trim($value));
        } catch (ValueError) {
            $parts = false;
        }

        if (! is_array($parts)) {
            throw new InvalidArgumentException('Provider origin is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $numericPort = isset($parts['port']) ? (int) $parts['port'] : null;
        if (! in_array($scheme, ['http', 'https'], true)
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! $this->isPrivateOrLoopback($host)
            || ($numericPort !== null && ($numericPort < 1 || $numericPort > 65535))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($path, ['', '/'], true)) {
            throw new InvalidArgumentException('Provider origin must be an HTTP(S) private literal IPv4 origin.');
        }

        $port = $numericPort !== null ? ':'.$numericPort : '';
        $this->value = $scheme.'://'.$host.$port;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function endpoint(string $path): string
    {
        if (! str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new InvalidArgumentException('Provider endpoint path is invalid.');
        }

        return $this->value.$path;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private function isPrivateOrLoopback(string $host): bool
    {
        $long = ip2long($host);
        if ($long === false) {
            return false;
        }

        $unsigned = (int) sprintf('%u', $long);

        return $this->inRange($unsigned, '10.0.0.0', 8)
            || $this->inRange($unsigned, '172.16.0.0', 12)
            || $this->inRange($unsigned, '192.168.0.0', 16)
            || $this->inRange($unsigned, '127.0.0.0', 8);
    }

    private function inRange(int $address, string $network, int $prefix): bool
    {
        $networkLong = ip2long($network);
        if ($networkLong === false) {
            return false;
        }

        $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;

        return ($address & $mask) === (((int) sprintf('%u', $networkLong)) & $mask);
    }
}

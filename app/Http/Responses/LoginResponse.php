<?php

namespace App\Http\Responses;

use App\Services\PanelNavigationService;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function __construct(
        private readonly PanelNavigationService $navigation,
    ) {}

    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->noContent();
        }

        $fallback = $this->navigation->homePathFor($request->user());

        return redirect()->to($this->safeIntendedPath($request) ?? $fallback);
    }

    private function safeIntendedPath(Request $request): ?string
    {
        $intended = $request->session()->pull('url.intended');
        if (! is_string($intended)) {
            return null;
        }

        $intended = trim($intended);
        $decodedIntended = rawurldecode($intended);
        if ($intended === ''
            || str_contains($intended, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $intended) === 1
            || str_contains($decodedIntended, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $decodedIntended) === 1
        ) {
            return null;
        }

        $parts = parse_url($intended);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $decodedPath = rawurldecode($path);
        if (! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_starts_with($decodedPath, '//')
            || str_contains($decodedPath, '\\')
        ) {
            return null;
        }

        if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['port'])) {
            $requestOrigin = parse_url($request->getSchemeAndHttpHost());
            if (! is_array($requestOrigin)
                || ! $this->sameOrigin($parts, $requestOrigin)
            ) {
                return null;
            }
        }

        return $path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $origin
     */
    private function sameOrigin(array $candidate, array $origin): bool
    {
        $candidateScheme = strtolower((string) ($candidate['scheme'] ?? ''));
        $originScheme = strtolower((string) ($origin['scheme'] ?? ''));
        if (! in_array($candidateScheme, ['http', 'https'], true)
            || $candidateScheme !== $originScheme
            || strtolower((string) ($candidate['host'] ?? '')) !== strtolower((string) ($origin['host'] ?? ''))
        ) {
            return false;
        }

        return $this->originPort($candidate, $candidateScheme) === $this->originPort($origin, $originScheme);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function originPort(array $parts, string $scheme): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return $scheme === 'https' ? 443 : 80;
    }
}

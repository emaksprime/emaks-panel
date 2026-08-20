<?php

namespace App\Services\TechnicalService;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class TechnicalServicePrivateDatasetPathPolicy
{
    public const RELATIVE_ROOT = 'storage/app/private/technical-service';

    public function root(): string
    {
        return storage_path('app/private/technical-service');
    }

    public function source(string $path): string
    {
        $candidate = $this->absolutePath($path);
        $root = $this->canonicalRoot(false);
        $real = realpath($candidate);

        if ($real === false || ! is_file($real)) {
            throw new RuntimeException('Private veri kaynagi bulunamadi.');
        }

        $real = $this->normalize($real);
        $this->assertInsideRoot($real, $root);

        if (basename($real) === '.gitignore') {
            throw new RuntimeException('Tracked private-root dosyasi veri kaynagi olamaz.');
        }

        if (! $this->samePath($real, $this->normalize($candidate))) {
            throw new RuntimeException('Symlink veya junction veri kaynagi reddedildi.');
        }

        return $real;
    }

    public function output(string $path): string
    {
        $root = $this->canonicalRoot(true);
        $candidate = $this->normalize($this->absolutePath($path));

        $this->assertInsideRoot($candidate, $root);

        if (! $this->samePath($this->normalize(dirname($candidate)), $root)) {
            throw new RuntimeException('Private veri ciktisi dogrudan teknik servis private root altinda olmalidir.');
        }

        if (basename($candidate) === '.gitignore') {
            throw new RuntimeException('Tracked private-root dosyasi veri ciktisi olamaz.');
        }

        if (file_exists($candidate) || is_link($candidate)) {
            throw new RuntimeException('Private veri ciktisi zaten mevcut; uzerine yazma reddedildi.');
        }

        return $candidate;
    }

    public function assertDifferent(string $source, string $output): void
    {
        if ($this->samePath($this->normalize($source), $this->normalize($output))) {
            throw new RuntimeException('Private veri kaynagi ve cikti ayni dosya olamaz.');
        }
    }

    public function writeAtomically(string $outputPath, string $contents): void
    {
        $output = $this->output($outputPath);
        $directory = dirname($output);
        $temporary = tempnam($directory, '.technical-service-export-');

        if ($temporary === false) {
            throw new RuntimeException('Private veri gecici dosyasi olusturulamadi.');
        }

        try {
            $written = file_put_contents($temporary, $contents, LOCK_EX);
            if ($written === false || $written !== strlen($contents)) {
                throw new RuntimeException('Private veri ciktisi tam yazilamadi.');
            }

            if (file_exists($output) || ! rename($temporary, $output)) {
                throw new RuntimeException('Private veri ciktisi atomik olarak yayinlanamadi.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function canonicalRoot(bool $create): string
    {
        $lexical = $this->normalize($this->root());

        if ($create) {
            File::ensureDirectoryExists($lexical);
        }

        $real = realpath($lexical);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Technical Service private veri root hazir degil.');
        }

        $real = $this->normalize($real);
        if (! $this->samePath($real, $lexical)) {
            throw new RuntimeException('Symlink veya junction private veri root reddedildi.');
        }

        return $real;
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Private veri path zorunludur.');
        }

        $absolute = preg_match('/^[A-Za-z]:[\\\\\/]|^\//', $path) === 1
            ? $path
            : base_path($path);

        return $this->normalize($absolute);
    }

    private function assertInsideRoot(string $path, string $root): void
    {
        $prefix = rtrim($root, '/').'/';
        if (! str_starts_with(
            PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path,
            PHP_OS_FAMILY === 'Windows' ? strtolower($prefix) : $prefix,
        )) {
            throw new RuntimeException('Tracked veya private root disindaki veri path reddedildi.');
        }
    }

    private function samePath(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}

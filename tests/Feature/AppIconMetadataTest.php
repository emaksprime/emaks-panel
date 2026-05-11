<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppIconMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_blade_contains_emaks_prime_icons_manifest_and_social_preview_meta(): void
    {
        $blade = file_get_contents(resource_path('views/app.blade.php')) ?: '';

        $this->assertStringContainsString('/favicon.ico?v={{ $assetVersion }}', $blade);
        $this->assertStringContainsString('/favicon.svg?v={{ $assetVersion }}', $blade);
        $this->assertStringContainsString('/apple-touch-icon.png?v={{ $assetVersion }}', $blade);
        $this->assertStringContainsString('/android-chrome-192x192.png?v={{ $assetVersion }}', $blade);
        $this->assertStringContainsString('/site.webmanifest?v={{ $assetVersion }}', $blade);
        $this->assertStringContainsString('property="og:image"', $blade);
        $this->assertStringContainsString('name="twitter:image"', $blade);
        $this->assertStringContainsString('og-emaks-prime.png?v={$assetVersion}', $blade);
        $this->assertStringContainsString('Emaks Prime Operasyon Paneli', $blade);
        $this->assertStringContainsString('Emaks Prime operasyon ve yönetim paneli', $blade);
    }

    public function test_public_emaks_prime_icon_files_exist_with_expected_sizes(): void
    {
        foreach ([
            public_path('favicon.ico'),
            public_path('favicon.svg'),
            public_path('apple-touch-icon.png'),
            public_path('android-chrome-192x192.png'),
            public_path('android-chrome-512x512.png'),
            public_path('og-emaks-prime.png'),
            public_path('site.webmanifest'),
        ] as $path) {
            $this->assertFileExists($path);
            $this->assertGreaterThan(100, filesize($path));
        }

        $this->assertSame([180, 180], $this->imageSize(public_path('apple-touch-icon.png')));
        $this->assertSame([192, 192], $this->imageSize(public_path('android-chrome-192x192.png')));
        $this->assertSame([512, 512], $this->imageSize(public_path('android-chrome-512x512.png')));
        $this->assertSame([1200, 630], $this->imageSize(public_path('og-emaks-prime.png')));
    }

    public function test_favicon_svg_no_longer_contains_laravel_default_mark(): void
    {
        $svg = file_get_contents(public_path('favicon.svg')) ?: '';

        $this->assertStringContainsString('data:image/png;base64,', $svg);
        $this->assertStringNotContainsString('#FF2D20', $svg);
        $this->assertStringNotContainsString('fill="#FF2D20"', $svg);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function imageSize(string $path): array
    {
        $size = getimagesize($path);

        $this->assertIsArray($size);

        return [(int) $size[0], (int) $size[1]];
    }
}

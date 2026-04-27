<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePublicUrls();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Coolify/Traefik terminates TLS before the Laravel container.
     * Generate every asset, redirect, and route URL from the public HTTPS URL.
     */
    protected function configurePublicUrls(): void
    {
        $publicUrl = rtrim((string) config('app.url'), '/');

        if ($publicUrl !== '') {
            URL::forceRootUrl($publicUrl);
        }

        if ((bool) env('APP_FORCE_HTTPS', app()->isProduction()) || str_starts_with($publicUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }
}

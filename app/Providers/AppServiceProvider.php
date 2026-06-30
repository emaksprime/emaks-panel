<?php

namespace App\Providers;

use App\Services\Payments\DirectIyzicoLinkProviderClient;
use App\Services\Payments\PaymentProviderGatewayClient;
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
        $this->app->bind(PaymentProviderGatewayClient::class, DirectIyzicoLinkProviderClient::class);
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

        Password::defaults(function (): Password {
            $rule = Password::min(8);

            return app()->isProduction()
                ? $rule
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : $rule;
        });
    }

    /**
     * Coolify/Traefik terminates TLS before the Laravel container.
     * Force a public root only where the runtime is expected to have one.
     */
    protected function configurePublicUrls(): void
    {
        $publicUrl = rtrim((string) config('app.url'), '/');
        $forceRootUrl = filter_var(env('APP_FORCE_ROOT_URL', app()->isProduction()), FILTER_VALIDATE_BOOL);

        if ($forceRootUrl && $publicUrl !== '') {
            URL::forceRootUrl($publicUrl);
        }

        if ((bool) env('APP_FORCE_HTTPS', app()->isProduction()) || ($forceRootUrl && str_starts_with($publicUrl, 'https://'))) {
            URL::forceScheme('https');
        }
    }
}

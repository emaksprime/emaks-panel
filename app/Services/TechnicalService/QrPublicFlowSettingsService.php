<?php

namespace App\Services\TechnicalService;

use App\Models\PageConfig;
use Illuminate\Support\Arr;

class QrPublicFlowSettingsService
{
    public const PAGE_CODE = 'technical_service_admin';
    public const PRE_FORM_PAYMENT_KEY = 'technical_service.qr.pre_form_payment_for_mount_excluded_enabled';

    public function preFormPaymentEnabled(): bool
    {
        $layout = $this->layout();

        return filter_var(
            Arr::get($layout, self::PRE_FORM_PAYMENT_KEY, false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * @return array{pre_form_payment_for_mount_excluded_enabled:bool,key:string,label:string}
     */
    public function payload(): array
    {
        return [
            'pre_form_payment_for_mount_excluded_enabled' => $this->preFormPaymentEnabled(),
            'key' => self::PRE_FORM_PAYMENT_KEY,
            'label' => 'Montaj durumu kontrolü ödeme sayfasına yönlendirsin',
        ];
    }

    public function updatePreFormPaymentEnabled(bool $enabled): array
    {
        $config = PageConfig::query()->firstOrCreate(
            ['page_code' => self::PAGE_CODE],
            ['layout_json' => []],
        );
        $layout = is_array($config->layout_json) ? $config->layout_json : [];

        Arr::set($layout, self::PRE_FORM_PAYMENT_KEY, $enabled);

        $config->forceFill(['layout_json' => $layout])->save();

        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    private function layout(): array
    {
        $layout = PageConfig::query()
            ->where('page_code', self::PAGE_CODE)
            ->value('layout_json');

        return is_array($layout) ? $layout : [];
    }
}

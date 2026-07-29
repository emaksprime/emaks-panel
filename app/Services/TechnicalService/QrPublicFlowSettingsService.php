<?php

namespace App\Services\TechnicalService;

use App\Models\PageConfig;
use Illuminate\Support\Arr;

class QrPublicFlowSettingsService
{
    public const PAGE_CODE = 'technical_service_admin';
    public const PRE_FORM_PAYMENT_KEY = 'technical_service.qr.pre_form_payment_for_mount_excluded_enabled';
    public const OPS_SHOW_MOUNT_EXCLUDED_APPROVAL_BLOCK_KEY = 'technical_service.ops_detail.show_mount_excluded_approval_block';
    public const OPS_SHOW_PAYMENT_MOUNT_CONTROL_BLOCK_KEY = 'technical_service.ops_detail.show_payment_mount_control_block';
    public const OPS_SHOW_ADDRESS_CONTROL_BLOCK_KEY = 'technical_service.ops_detail.show_address_control_block';

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
            'ops_detail_visibility' => $this->opsDetailVisibility(),
        ];
    }

    public function updatePreFormPaymentEnabled(bool $enabled): array
    {
        return $this->update([
            self::PRE_FORM_PAYMENT_KEY => $enabled,
        ]);
    }

    /**
     * @param array<string, bool> $values
     * @return array<string, mixed>
     */
    public function update(array $values): array
    {
        $config = PageConfig::query()->firstOrCreate(
            ['page_code' => self::PAGE_CODE],
            ['layout_json' => []],
        );
        $layout = is_array($config->layout_json) ? $config->layout_json : [];

        foreach ($values as $key => $enabled) {
            Arr::set($layout, $key, $enabled);
        }

        $config->forceFill(['layout_json' => $layout])->save();

        return $this->payload();
    }

    /**
     * @return array{show_mount_excluded_approval_block:bool,show_payment_mount_control_block:bool,show_address_control_block:bool,keys:array<string, string>}
     */
    public function opsDetailVisibility(): array
    {
        $layout = $this->layout();

        return [
            'show_mount_excluded_approval_block' => filter_var(
                Arr::get($layout, self::OPS_SHOW_MOUNT_EXCLUDED_APPROVAL_BLOCK_KEY, false),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'show_payment_mount_control_block' => filter_var(
                Arr::get($layout, self::OPS_SHOW_PAYMENT_MOUNT_CONTROL_BLOCK_KEY, false),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'show_address_control_block' => filter_var(
                Arr::get($layout, self::OPS_SHOW_ADDRESS_CONTROL_BLOCK_KEY, false),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'keys' => [
                'show_mount_excluded_approval_block' => self::OPS_SHOW_MOUNT_EXCLUDED_APPROVAL_BLOCK_KEY,
                'show_payment_mount_control_block' => self::OPS_SHOW_PAYMENT_MOUNT_CONTROL_BLOCK_KEY,
                'show_address_control_block' => self::OPS_SHOW_ADDRESS_CONTROL_BLOCK_KEY,
            ],
        ];
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

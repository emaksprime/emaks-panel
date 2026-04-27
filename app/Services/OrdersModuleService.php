<?php

namespace App\Services;

class OrdersModuleService
{
    /**
     * @return array<string, string>
     */
    public function alinan(): array
    {
        return $this->placeholder();
    }

    /**
     * @return array<string, string>
     */
    public function verilen(): array
    {
        return $this->placeholder();
    }

    /**
     * @return array<string, string>
     */
    private function placeholder(): array
    {
        return [
            'mode' => 'placeholder',
            'message' => 'Canlı veri kaynağı henüz bağlanmadı.',
        ];
    }
}

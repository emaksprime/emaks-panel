<?php

namespace App\Services;

class StockModuleService
{
    /**
     * @return array<string, string>
     */
    public function list(): array
    {
        return $this->placeholder();
    }

    /**
     * @return array<string, string>
     */
    public function critical(): array
    {
        return $this->placeholder();
    }

    /**
     * @return array<string, string>
     */
    public function warehouse(): array
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

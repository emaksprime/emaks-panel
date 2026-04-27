<?php

namespace App\Services;

class SalesModuleService
{
    /**
     * @return array<string, string>
     */
    public function mainSummary(): array
    {
        return $this->placeholder();
    }

    /**
     * @return array<string, string>
     */
    public function onlineDetail(): array
    {
        return $this->placeholder();
    }

    /**
     * @return array<string, string>
     */
    public function bayiDetail(): array
    {
        return $this->placeholder();
    }

    /**
     * @return array<string, string>
     */
    public function representativeSummary(): array
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

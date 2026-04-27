<?php

namespace App\Services;

class CariModuleService
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
    public function balance(): array
    {
        return $this->placeholder();
    }

    /**
     * @return array<string, string>
     */
    public function detail(): array
    {
        return $this->placeholder();
    }

    /**
     * @return array<string, string>
     */
    public function statement(): array
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

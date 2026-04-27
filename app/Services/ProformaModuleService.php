<?php

namespace App\Services;

class ProformaModuleService
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
    public function createDraft(): array
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
    private function placeholder(): array
    {
        return [
            'mode' => 'placeholder',
            'message' => 'Canlı veri kaynağı henüz bağlanmadı.',
        ];
    }
}

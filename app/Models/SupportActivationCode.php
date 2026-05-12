<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportActivationCode extends Model
{
    protected $table = 'panel.support_activation_codes';

    protected $fillable = [
        'code',
        'stock_code',
        'stock_name',
        'serial_number',
        'serial_number_clean',
        'search_code',
        'activation_code',
        'activation_link',
        'metadata',
        'search_text',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }
}

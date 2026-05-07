<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivationCodeRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_code',
        'stock_name',
        'serial_no',
        'serial_prefix',
        'serial_prefix_clean',
        'serial_prefix_tail_6',
        'serial_prefix_tail_10',
        'activation_code',
        'serial_no_clean',
        'serial_tail_6',
        'serial_tail_10',
        'search_code',
        'source_file_name',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }
}

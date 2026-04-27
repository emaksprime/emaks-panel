<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSourceCache extends Model
{
    protected $table = 'panel.data_source_cache';

    protected $fillable = [
        'cache_key',
        'source_code',
        'request_payload',
        'response_payload',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}

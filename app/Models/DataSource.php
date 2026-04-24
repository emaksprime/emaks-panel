<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    use HasFactory;

    protected $table = 'panel.data_sources';

    protected $fillable = [
        'code',
        'name',
        'db_type',
        'query_template',
        'allowed_params',
        'connection_meta',
        'preview_payload',
        'active',
        'sort_order',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'allowed_params' => 'array',
            'connection_meta' => 'array',
            'preview_payload' => 'array',
            'active' => 'boolean',
        ];
    }
}

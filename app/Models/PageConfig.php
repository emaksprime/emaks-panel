<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageConfig extends Model
{
    use HasFactory;

    protected $table = 'panel.page_configs';

    protected $fillable = [
        'page_code',
        'layout_json',
        'filters_json',
        'datasource_id',
    ];

    protected function casts(): array
    {
        return [
            'layout_json' => 'array',
            'filters_json' => 'array',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'datasource_id');
    }
}

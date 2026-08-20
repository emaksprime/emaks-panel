<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalServiceAssignmentOffer extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVISED = 'revised';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'technical_service_assignment_offers';

    protected $fillable = [
        'technical_service_request_id',
        'technical_service_technician_id',
        'route_quote_id',
        'labor_amount',
        'route_fee_amount',
        'total_amount',
        'currency',
        'status',
        'note',
        'sent_by',
        'sent_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'labor_amount' => 'decimal:2',
            'route_fee_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRequest::class, 'technical_service_request_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceTechnician::class, 'technical_service_technician_id');
    }

    public function routeQuote(): BelongsTo
    {
        return $this->belongsTo(TechnicalServiceRouteQuote::class, 'route_quote_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}

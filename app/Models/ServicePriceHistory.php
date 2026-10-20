<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable price-change record: every edit of Service.current_price appends
 * one row (never an update of an existing row).
 */
class ServicePriceHistory extends Model
{
    use HasUlids;

    public const CREATED_AT = 'changed_at';

    public const UPDATED_AT = null;

    protected $table = 'service_price_history';

    protected $fillable = [
        'service_id',
        'old_price',
        'new_price',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'old_price' => 'decimal:2',
            'new_price' => 'decimal:2',
            'changed_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}

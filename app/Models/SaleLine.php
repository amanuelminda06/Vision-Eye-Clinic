<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sale with a full snapshot of the service name and price at
 * sale time — later renames/price changes never touch history.
 */
class SaleLine extends Model
{
    use HasUlids;

    protected $table = 'sale_lines';

    /** The Prisma model has no createdAt/updatedAt on sale lines. */
    public $timestamps = false;

    protected $fillable = [
        'sale_id',
        'service_id',
        'service_name_snapshot',
        'qty',
        'unit_price',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}

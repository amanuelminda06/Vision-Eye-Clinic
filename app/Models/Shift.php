<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A concrete worked shift stored as UTC instants. May cross midnight
 * (endsAt on the next day). source: TEMPLATE | MANUAL.
 */
class Shift extends Model
{
    use HasUlids;

    protected $table = 'shifts';

    protected $fillable = [
        'staff_id',
        'starts_at',
        'ends_at',
        'source',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Weekly recurring shift pattern in clinic-local wall-clock time.
 * endTime <= startTime means the shift crosses midnight into the next day.
 * weekday: 0 = Sunday … 6 = Saturday (mirrors JS Date.getUTCDay()).
 */
class ShiftTemplate extends Model
{
    use HasUlids;

    protected $table = 'shift_templates';

    protected $fillable = [
        'staff_id',
        'weekday',
        'start_time',
        'end_time',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Non-clinical recall/review for a patient. due_date is a clinic-local
 * calendar day (no time component). Statuses: OPEN, DONE, CANCELLED.
 */
class FollowUp extends Model
{
    use HasUlids;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_DONE = 'DONE';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'follow_ups';

    protected $fillable = [
        'patient_id',
        'due_date',
        'reason',
        'status',
        'created_from_appointment_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}

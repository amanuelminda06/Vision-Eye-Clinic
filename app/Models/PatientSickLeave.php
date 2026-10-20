<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sick leave recorded FOR a patient (issued by the clinic) — a patient record,
 * not a schedule block, and distinct from staff leave_requests.
 * Reason/note are NON-CLINICAL free text only.
 */
class PatientSickLeave extends Model
{
    use HasUlids;

    protected $table = 'patient_sick_leaves';

    protected $fillable = [
        'patient_id',
        'start_date',
        'end_date',
        'reason',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

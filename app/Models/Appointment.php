<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booked patient appointment with a doctor (UTC instants; day buckets use
 * the clinic timezone). Statuses: SCHEDULED, CONFIRMED, COMPLETED, NO_SHOW,
 * CANCELLED — transitions follow a fixed graph (App\Support\AppointmentStatus
 * holds the rules). `notes` is FREE TEXT ONLY — never clinical detail.
 */
class Appointment extends Model
{
    use HasUlids;

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_NO_SHOW = 'NO_SHOW';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const SOURCE_STAFF = 'STAFF';

    public const SOURCE_WEBSITE = 'WEBSITE_REQUEST';

    protected $table = 'appointments';

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'starts_at',
        'ends_at',
        'service_id',
        'status',
        'cancel_reason',
        'notes',
        'created_from',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'doctor_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}

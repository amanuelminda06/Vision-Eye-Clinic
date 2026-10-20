<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A website appointment request (the "book an appointment" form).
 *
 * A public booking creates ONE row here — never an appointment and never a
 * patient. The front desk converts it into a real appointment, contacts the
 * visitor, declines it or marks it as spam.
 *
 * Only the sha-256 hash of the submitter's IP is stored — never the raw IP.
 */
class AppointmentRequest extends Model
{
    use HasUlids;

    public const STATUS_NEW = 'NEW';

    public const STATUS_CONTACTED = 'CONTACTED';

    public const STATUS_CONVERTED = 'CONVERTED';

    public const STATUS_DECLINED = 'DECLINED';

    public const STATUS_SPAM = 'SPAM';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_CONVERTED,
        self::STATUS_DECLINED,
        self::STATUS_SPAM,
    ];

    protected $table = 'appointment_requests';

    protected $fillable = [
        'name',
        'phone_e164',
        'preferred_date',
        'preferred_time_window',
        'service_interest',
        'message',
        'language',
        'status',
        'handled_by',
        'handled_at',
        'patient_id',
        'appointment_id',
        'ip_hash',
    ];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'handled_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }
}

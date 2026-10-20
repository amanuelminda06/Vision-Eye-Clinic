<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff leave request + decision. Only the OWNER decides, and never their own
 * request (a peer decides). Dates are clinic-local calendar days; half_day
 * means 0.5 days. Approved leave suppresses the website "on duty" status.
 */
class LeaveRequest extends Model
{
    use HasUlids;

    public const STATUS_REQUESTED = 'REQUESTED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'leave_requests';

    protected $fillable = [
        'staff_id',
        'type',
        'start_date',
        'end_date',
        'half_day',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'half_day' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}

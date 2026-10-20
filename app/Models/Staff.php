<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A clinic staff member (doctors included). Deactivated, never deleted.
 */
class Staff extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'staff';

    protected $fillable = [
        'full_name',
        'job_title',
        'is_doctor',
        'specialty',
        'phone',
        'photo_url',
        'show_on_website',
        'active',
        'annual_leave_days',
    ];

    protected function casts(): array
    {
        return [
            'is_doctor' => 'boolean',
            'show_on_website' => 'boolean',
            'active' => 'boolean',
            'annual_leave_days' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'staff_id');
    }

    public function salesAsDoctor(): HasMany
    {
        return $this->hasMany(Sale::class, 'doctor_id');
    }

    public function shiftTemplates(): HasMany
    {
        return $this->hasMany(ShiftTemplate::class, 'staff_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'staff_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'staff_id');
    }

    public function appointmentsAsDoctor(): HasMany
    {
        return $this->hasMany(Appointment::class, 'doctor_id');
    }

    /** Published website doctors: active + shown on the website. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('active', true)->where('show_on_website', true);
    }
}

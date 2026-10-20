<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Staff login account. Roles: OWNER | RECEPTION | STAFF (server-side enforced).
 *
 * Passwords are argon2id hashes. Accounts are never hard-deleted — `active`
 * plus the OWNER's deactivate/reset actions are the only mutations.
 */
class User extends Authenticatable
{
    use HasFactory, HasUlids;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'staff_id',
        'active',
        'must_change_password',
        'failed_login_count',
        'locked_until',
        'last_login_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'failed_login_count' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function sickLeavesRecorded(): HasMany
    {
        return $this->hasMany(PatientSickLeave::class, 'recorded_by');
    }

    /** Scope: only active accounts (used by the login gate). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}

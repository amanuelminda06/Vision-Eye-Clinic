<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sale (POS receipt). CONFIRMED sales are immutable — the only allowed
 * mutation is a void (with a reason + audit). `total` is always recomputed
 * server-side from DB prices; `receipt_number` is gap-free per clinic-local
 * year (atomic receipt_counters claim).
 */
class Sale extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const STATUS_VOIDED = 'VOIDED';

    protected $table = 'sales';

    protected $fillable = [
        'receipt_number',
        'patient_id',
        'doctor_id',
        'sale_date',
        'status',
        'payment_method',
        'total',
        'void_requested_by',
        'void_request_reason',
        'voided_by',
        'void_reason',
        'voided_at',
        'created_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'datetime',
            'total' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'voided_at' => 'datetime',
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

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class, 'sale_id');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopeVoided(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VOIDED);
    }
}

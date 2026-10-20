<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A patient record. Contains NO clinical data — notes_non_clinical is free
 * text only. phone_e164 is always E.164. Records are deactivated, never
 * deleted. do_not_message + do_not_message_reason implement the patient's
 * "stop messages" request (audited, OWNER-only to re-enable).
 */
class Patient extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'patients';

    protected $fillable = [
        'full_name',
        'phone_e164',
        'sex',
        'birth_date',
        'preferred_language',
        'notes_non_clinical',
        'do_not_message',
        'do_not_message_reason',
        'id_photo_url',
        'id_no',
        'active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'do_not_message' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'patient_tag', 'patient_id', 'tag_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'patient_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'patient_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'patient_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MessageTask::class, 'patient_id');
    }

    public function sickLeaves(): HasMany
    {
        return $this->hasMany(PatientSickLeave::class, 'patient_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}

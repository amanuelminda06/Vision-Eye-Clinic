<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A patient record. Contains NO clinical data — notes_non_clinical is free
 * text only. phone_e164 is always E.164 and may be NULL for a patient who has
 * no number of their own. Records are deactivated, never deleted.
 * do_not_message + do_not_message_reason implement the patient's "stop
 * messages" request (audited, OWNER-only to re-enable).
 *
 * A patient may be linked to a guardian (`guardian_id` + `relationship`): a
 * child keeps their own name/age/sex, stores no phone, and messages are
 * addressed to the guardian's number (see effectivePhoneE164()).
 */
class Patient extends Model
{
    use HasFactory, HasUlids;

    /** The only relationship value this build writes on a guardian link. */
    public const RELATIONSHIP_GUARDIAN = 'guardian';

    /** How far effectivePhoneE164() walks a guardian chain before giving up. */
    private const MAX_GUARDIAN_HOPS = 8;

    protected $table = 'patients';

    protected $fillable = [
        'full_name',
        'phone_e164',
        'sex',
        'age',
        'preferred_language',
        'notes_non_clinical',
        'do_not_message',
        'do_not_message_reason',
        'id_photo_url',
        'system_id',
        'id_no',
        'guardian_id',
        'relationship',
        'active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'age' => 'integer',
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

    /** The guardian this patient is linked to (their parent / guardian). */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'guardian_id');
    }

    /** The family members (children) who are linked to this patient. */
    public function children(): HasMany
    {
        return $this->hasMany(Patient::class, 'guardian_id');
    }

    /**
     * The number a message must reach: this patient's own phone when they
     * have one, otherwise the guardian's number (a child without a phone
     * borrows it — nothing is copied onto the child's row, so a guardian
     * changing their number updates every linked family member at once).
     *
     * The chain is walked a few hops with a visited-set so a bad link can
     * never spin. `guardian_id` null (the common case) short-circuits, so a
     * patient without a guardian never triggers a query.
     */
    public function effectivePhoneE164(): ?string
    {
        $own = $this->phone_e164;
        if ($own !== null && $own !== '') {
            return $own;
        }

        $seen = [];
        $current = $this;
        $hops = 0;

        while ($current !== null && $hops < self::MAX_GUARDIAN_HOPS) {
            $seen[$current->id] = true;

            $guardianId = $current->guardian_id;
            if ($guardianId === null || $guardianId === '') {
                return null;
            }

            $guardian = $current->guardian;
            if ($guardian === null || isset($seen[$guardian->id])) {
                return null;
            }

            $phone = $guardian->phone_e164;
            if ($phone !== null && $phone !== '') {
                return $phone;
            }

            $current = $guardian;
            $hops++;
        }

        return null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}

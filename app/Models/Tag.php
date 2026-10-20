<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Lowercase display tag with a deterministic colour (#3B82F6). */
class Tag extends Model
{
    use HasFactory, HasUlids;

    public $timestamps = false;

    protected $table = 'tags';

    protected $fillable = ['name', 'color'];

    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'patient_tag', 'tag_id', 'patient_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved, named recipient filter ("Audience"). The member list is never
 * frozen — the filter re-evaluates at use time. OWNER-managed.
 */
class Audience extends Model
{
    use HasUlids;

    protected $table = 'audiences';

    protected $fillable = ['name', 'filter_json', 'created_by'];

    protected function casts(): array
    {
        return [
            'filter_json' => 'array',
        ];
    }
}

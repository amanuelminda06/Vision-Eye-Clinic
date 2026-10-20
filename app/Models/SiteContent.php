<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bilingual public-website copy (CMS lite). One row per content key.
 * Amharic values are drafts that need a native-speaker review.
 */
class SiteContent extends Model
{
    public const CREATED_AT = null;

    protected $table = 'site_content';

    protected $fillable = ['key', 'value_en', 'value_am', 'updated_by'];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }
}

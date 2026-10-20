<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reusable message archive. Duplicates are prevented per language by
 * normalized_hash; edited/hidden items are DEACTIVATED, never hard-deleted.
 */
class MessageLibraryItem extends Model
{
    use HasUlids;

    protected $table = 'message_library_items';

    protected $fillable = [
        'title',
        'body',
        'language',
        'category',
        'normalized_hash',
        'is_favorite',
        'use_count',
        'last_used_at',
        'created_by',
        'active',
        'pair_id',
    ];

    protected function casts(): array
    {
        return [
            'is_favorite' => 'boolean',
            'use_count' => 'integer',
            'last_used_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'library_item_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A one-off batch of messages to a filtered group of patients. Each recipient
 * gets their OWN MessageTask — never one shared group message — unless the
 * campaign uses GROUP delivery, where tasks are organised into re-planable
 * CampaignBatch chunks for the phone's SMS app.
 */
class Campaign extends Model
{
    use HasUlids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'campaigns';

    protected $fillable = [
        'title',
        'template_key',
        'custom_body',
        'language_mode',
        'fixed_language',
        'filter_json',
        'status',
        'delivery_mode',
        'library_item_id',
        'expires_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'filter_json' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(MessageTask::class, 'campaign_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(CampaignBatch::class, 'campaign_id');
    }

    public function libraryItem(): BelongsTo
    {
        return $this->belongsTo(MessageLibraryItem::class, 'library_item_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One group-chat batch of a GROUP campaign ("Group 1 of N"). Size is
 * re-planable; a batch never hard-binds recipients before sending.
 */
class CampaignBatch extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_SENT = 'SENT';

    public const STATUS_SKIPPED = 'SKIPPED';

    protected $table = 'campaign_batches';

    protected $fillable = [
        'campaign_id',
        'batch_number',
        'size',
        'status',
        'handled_by',
        'handled_at',
        'skip_reason',
    ];

    protected function casts(): array
    {
        return [
            'batch_number' => 'integer',
            'size' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(MessageTask::class, 'campaign_batch_id');
    }
}

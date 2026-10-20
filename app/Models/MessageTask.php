<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One prepared message for one patient, generated idempotently: the unique
 * tuple (source_type, source_id, template_key, patient_id) prevents duplicate
 * tasks from repeated job runs. `rendered_message` holds the final text with
 * variables substituted (a one-off edit may change it, tracked by edited_by).
 *
 * Statuses: PENDING, OPENED, SENT, SKIPPED, EXPIRED, CANCELLED, BLOCKED.
 */
class MessageTask extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_OPENED = 'OPENED';

    public const STATUS_SENT = 'SENT';

    public const STATUS_SKIPPED = 'SKIPPED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_BLOCKED = 'BLOCKED';

    public const UPDATED_AT = null;

    protected $table = 'message_tasks';

    protected $fillable = [
        'patient_id',
        'template_key',
        'source_type',
        'source_id',
        'campaign_id',
        'language',
        'rendered_message',
        'due_at',
        'send_by',
        'expires_at',
        'status',
        'blocked_reason',
        'skip_reason',
        'handled_by',
        'handled_at',
        'edited_by',
        'edited_at',
        'campaign_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'send_by' => 'datetime',
            'expires_at' => 'datetime',
            'handled_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function campaignBatch(): BelongsTo
    {
        return $this->belongsTo(CampaignBatch::class, 'campaign_batch_id');
    }
}

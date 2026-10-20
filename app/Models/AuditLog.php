<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit trail. Every mutation writes one row with a field-level
 * diff of the changed fields only; secrets are stored as [REDACTED].
 * Rows are never updated or deleted.
 */
class AuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'entity',
        'entity_id',
        'action',
        'old_json',
        'new_json',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'old_json' => 'array',
            'new_json' => 'array',
        ];
    }
}

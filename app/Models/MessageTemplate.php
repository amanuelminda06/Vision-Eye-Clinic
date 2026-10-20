<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Editable SMS wording, one row per (key, language). Amharic rows are drafts
 * that need a native-speaker review (surfaced in the editor).
 */
class MessageTemplate extends Model
{
    use HasUlids;

    protected $table = 'message_templates';

    protected $fillable = ['key', 'language', 'body', 'active', 'updated_by'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}

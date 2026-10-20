<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An in-progress composer message, autosaved so the user can leave and
 * resume. Drafts belong to their creator and are never shared.
 */
class MessageDraft extends Model
{
    use HasUlids;

    protected $table = 'message_drafts';

    protected $fillable = ['created_by', 'title', 'body', 'language'];
}

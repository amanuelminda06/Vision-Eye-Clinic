<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Blob storage for uploaded images (staff photos, patient ID photos) so
 * uploads survive disk wipes. Keys are path-traversal-safe and the bytes are
 * only ever served to signed-in users through /uploads/{key}.
 */
class StoredFile extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'stored_files';

    protected $fillable = ['key', 'mime_type', 'data'];

    protected $hidden = ['data'];

    protected function casts(): array
    {
        return [
            'data' => 'binary',
        ];
    }
}

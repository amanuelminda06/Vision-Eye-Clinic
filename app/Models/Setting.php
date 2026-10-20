<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value clinic settings (17 keys, see App\Support\Settings).
 * The value column holds JSON (strings, numbers, booleans, objects).
 */
class Setting extends Model
{
    public const CREATED_AT = null;

    protected $table = 'settings';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'updated_at' => 'datetime',
        ];
    }
}

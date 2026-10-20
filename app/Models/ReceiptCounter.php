<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gap-free receipt numbering: one row per clinic-local calendar year, claimed
 * with a row lock inside the sale transaction (SELECT … FOR UPDATE on MySQL;
 * the SQLite grammar drops the lock for tests, where writes serialize anyway).
 */
class ReceiptCounter extends Model
{
    public $timestamps = false;

    protected $table = 'receipt_counters';

    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['year', 'last_number'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}

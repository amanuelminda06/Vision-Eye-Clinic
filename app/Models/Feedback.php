<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Feedback left through the public website's contact form. New rows are never
 * public: they appear in the testimonials section only after the OWNER ticks
 * "show on website". Only a sha-256 IP hash is stored — never a raw IP.
 */
class Feedback extends Model
{
    use HasUlids;

    protected $table = 'feedback';

    protected $fillable = [
        'name',
        'email',
        'rating',
        'text',
        'language',
        'show_on_website',
        'ip_hash',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'show_on_website' => 'boolean',
        ];
    }
}

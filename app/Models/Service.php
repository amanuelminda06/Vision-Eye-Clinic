<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A billable service. `current_price` is the price used for NEW sales only —
 * existing sale lines keep their own snapshot. `is_public` controls website
 * visibility; the price is NEVER exposed publicly.
 */
class Service extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'services';

    protected $fillable = [
        'name',
        'name_en',
        'name_am',
        'category',
        'current_price',
        'active',
        'is_public',
        'public_description_en',
        'public_description_am',
        'receipt_label_en',
        'receipt_label_am',
        'display_order',
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'current_price' => 'decimal:2',
            'active' => 'boolean',
            'is_public' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ServicePriceHistory::class, 'service_id');
    }

    public function saleLines(): HasMany
    {
        return $this->hasMany(SaleLine::class, 'service_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'service_id');
    }
}

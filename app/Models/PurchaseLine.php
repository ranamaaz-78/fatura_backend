<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product of a purchase: how many came in and what each cost. */
class PurchaseLine extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'article',
        'quantity',
        'unit_cost',
        'total_cents',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

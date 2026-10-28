<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One delivery from a supplier: a batch of products bought on one date. */
class Purchase extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'supplier_id',
        'supplier_name',
        'number',
        'purchased_on',
        'supplier_invoice_no',
        'notes',
        'total_cents',
        'voided_at',
        'void_reason',
        'voided_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purchased_on' => 'date',
            'total_cents' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}

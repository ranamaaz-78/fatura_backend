<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'iva_mode',
        'iva_percent',
        'base_cents',
        'iva_cents',
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
            'base_cents' => 'integer',
            'iva_cents' => 'integer',
            'iva_percent' => 'float',
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

    /** The entry this purchase wrote in Supplier Payments. */
    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(SupplierBill::class, 'purchase_id');
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

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry of the supplier ledger: something bought from a supplier, in words, for an amount, and what has been
 * paid against it. No products and no stock: it is only a record book.
 */
class SupplierBill extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'supplier_id',
        'purchase_id',
        'supplier_name',
        'number',
        'bill_date',
        'description',
        'reference',
        'notes',
        'amount_cents',
        'paid_cents',
        'voided_at',
        'void_reason',
        'voided_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'amount_cents' => 'integer',
            'paid_cents' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The purchase this entry was written from, when it came from one. */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierBillPayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** What is still to be paid to the supplier. */
    public function owedCents(): int
    {
        return max(0, (int) $this->amount_cents - (int) $this->paid_cents);
    }

    /** unpaid, partial or paid: it flips to paid by itself when nothing is owed any more. */
    public function paymentStatus(): string
    {
        if ($this->owedCents() === 0) {
            return 'paid';
        }

        return (int) $this->paid_cents > 0 ? 'partial' : 'unpaid';
    }
}

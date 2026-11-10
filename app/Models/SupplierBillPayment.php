<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One payment to the supplier against a ledger entry. An entry can have as many as it takes to settle it. */
class SupplierBillPayment extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'supplier_bill_id',
        'payment_method_id',
        'paid_on',
        'amount_cents',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount_cents' => 'integer',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(CompanyPaymentMethod::class, 'payment_method_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

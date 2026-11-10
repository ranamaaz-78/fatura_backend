<?php

namespace App\Services;

use App\Models\CompanyPaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The supplier ledger: entries (what was bought from a supplier, for how much) and the payments made against them.
 * It is a record book only. It never touches products or stock, and Purchases never touch it.
 */
class SupplierLedger
{
    public function __construct(private readonly DocumentNumber $numbers) {}

    /**
     * @param  array{supplier_id: int, bill_date: string, description: string, amount_cents: int, reference?: ?string, notes?: ?string, paid_cents?: ?int, payment_method_id?: ?int}  $data
     */
    public function record(User $user, array $data): SupplierBill
    {
        return DB::transaction(function () use ($user, $data) {
            $supplier = Supplier::query()->findOrFail($data['supplier_id']);
            $companyId = (int) $user->company_id;
            $date = Carbon::parse($data['bill_date']);

            $bill = SupplierBill::create([
                'company_id' => $companyId,
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'number' => $this->numbers->take($companyId, 'supplier_bill', (int) $date->format('Ym')),
                'bill_date' => $date->toDateString(),
                'description' => trim($data['description']),
                'reference' => $this->clean($data['reference'] ?? null),
                'notes' => $this->clean($data['notes'] ?? null),
                'amount_cents' => (int) $data['amount_cents'],
                'paid_cents' => 0,
                'created_by' => $user->id,
            ]);

            // Paid when it is written down, in full or in part: that is the first payment. The rest comes later.
            $paidNow = (int) ($data['paid_cents'] ?? 0);
            if ($paidNow > 0) {
                $this->pay($user, $bill, [
                    'amount_cents' => $paidNow,
                    'paid_on' => $date->toDateString(),
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'note' => null,
                ], 'paid_cents');
            }

            return $bill->fresh(['payments.paymentMethod', 'creator:id,name']);
        });
    }

    /**
     * Adds a payment to the supplier against this entry. It cannot be more than is still owed; when nothing is owed
     * any more the entry counts as paid.
     *
     * @param  array{amount_cents: int, paid_on: string, payment_method_id?: ?int, note?: ?string}  $data
     */
    public function addPayment(User $user, SupplierBill $bill, array $data): SupplierBill
    {
        return DB::transaction(function () use ($user, $bill, $data) {
            $current = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($current->isVoided()) {
                throw ValidationException::withMessages(['amount_cents' => __('This entry is cancelled, so it cannot be paid.')]);
            }

            $this->pay($user, $current, $data, 'amount_cents');

            return $current->fresh(['payments.paymentMethod', 'creator:id,name']);
        });
    }

    /** Takes a payment typed by mistake back out: what is owed goes up again. The record of it stays. */
    public function removePayment(SupplierBill $bill, SupplierBillPayment $payment): SupplierBill
    {
        return DB::transaction(function () use ($bill, $payment) {
            $current = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $row = SupplierBillPayment::query()->whereKey($payment->id)->where('supplier_bill_id', $current->id)->lockForUpdate()->first();

            if ($row === null) {
                throw ValidationException::withMessages(['payment' => __('That payment was already removed.')]);
            }

            $row->delete();
            $current->update(['paid_cents' => max(0, (int) $current->paid_cents - (int) $row->amount_cents)]);

            return $current->fresh(['payments.paymentMethod', 'creator:id,name']);
        });
    }

    /** Cancels a written-down entry by mistake. Refused while it has payments: they are taken out first. */
    public function void(User $user, SupplierBill $bill, string $reason): SupplierBill
    {
        return DB::transaction(function () use ($user, $bill, $reason) {
            $current = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($current->isVoided()) {
                throw ValidationException::withMessages(['reason' => __('This entry is already cancelled.')]);
            }

            if ($current->payments()->exists()) {
                throw ValidationException::withMessages(['reason' => __('This entry has payments. Remove them first, then cancel it.')]);
            }

            $current->update(['voided_at' => now(), 'void_reason' => $reason, 'voided_by' => $user->id]);

            return $current->fresh(['payments.paymentMethod', 'creator:id,name']);
        });
    }

    /**
     * Writes one payment and raises the entry's paid total. The caller holds the entry locked.
     *
     * @param  array{amount_cents: int, paid_on: string, payment_method_id?: ?int, note?: ?string}  $data
     * @param  string  $field  the form field an error is shown on
     */
    private function pay(User $user, SupplierBill $bill, array $data, string $field): void
    {
        $amount = (int) $data['amount_cents'];
        $paidOn = Carbon::parse($data['paid_on']);

        if ($amount < 1) {
            throw ValidationException::withMessages([$field => __('The amount must be more than zero.')]);
        }

        if ($amount > $bill->owedCents()) {
            throw ValidationException::withMessages([$field => __('That is more than what is still owed on this entry.')]);
        }

        if ($paidOn->copy()->startOfDay()->lt($bill->bill_date->copy()->startOfDay())) {
            throw ValidationException::withMessages(['paid_on' => __('A payment cannot be dated before the entry.')]);
        }

        $methodId = CompanyPaymentMethod::requireActive((int) $bill->company_id, $data['payment_method_id'] ?? null);

        SupplierBillPayment::create([
            'company_id' => $bill->company_id,
            'supplier_bill_id' => $bill->id,
            'payment_method_id' => $methodId,
            'paid_on' => $paidOn->toDateString(),
            'amount_cents' => $amount,
            'note' => $this->clean($data['note'] ?? null),
            'created_by' => $user->id,
        ]);

        $bill->update(['paid_cents' => (int) $bill->paid_cents + $amount]);
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}

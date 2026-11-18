<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Support\Pricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records what was bought from a supplier and keeps the stock in step: saving a purchase adds its pieces to the
 * products and makes the rate the products' buying price; cancelling it takes the pieces back out.
 */
class PurchaseRecorder
{
    public function __construct(private readonly DocumentNumber $numbers, private readonly SupplierLedger $ledger) {}

    /**
     * @param  array{supplier_id: int, purchased_on: string, supplier_invoice_no?: ?string, notes?: ?string, iva_mode?: ?string, iva_percent?: float|int|string|null, lines: list<array{product_id: int, quantity: int, unit_cost: int, selling_price?: ?int}>}  $data
     */
    public function record(User $user, array $data): Purchase
    {
        return DB::transaction(function () use ($user, $data) {
            $date = Carbon::parse($data['purchased_on']);
            $companyId = (int) $user->company_id;

            $purchase = new Purchase([
                'company_id' => $companyId,
                'number' => $this->numbers->take($companyId, 'purchase', (int) $date->format('Ym')),
                'created_by' => $user->id,
            ]);

            $this->apply($purchase, $data, []);

            // The purchase writes itself down in Supplier Payments: what is owed to the supplier starts here.
            $this->ledger->writeDownPurchase($purchase);

            return $purchase->load(['lines', 'creator:id,name']);
        });
    }

    /**
     * Changes a purchase while nothing was paid against it yet: its supplier, date, lines and IVA. The stock moves by the
     * difference (pieces taken off a line leave the stock again, refused if they were already sold), and its entry in
     * Supplier Payments follows the new total.
     *
     * @param  array{supplier_id: int, purchased_on: string, supplier_invoice_no?: ?string, notes?: ?string, iva_mode?: ?string, iva_percent?: float|int|string|null, lines: list<array{product_id: int, quantity: int, unit_cost: int, selling_price?: ?int}>}  $data
     */
    public function update(User $user, Purchase $purchase, array $data): Purchase
    {
        return DB::transaction(function () use ($purchase, $data) {
            $current = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($current->isVoided()) {
                throw ValidationException::withMessages(['purchase' => __('A cancelled purchase cannot be changed.')]);
            }

            $entry = SupplierBill::query()->where('purchase_id', $current->id)->whereNull('voided_at')->lockForUpdate()->first();
            if ($entry !== null && ((int) $entry->paid_cents > 0 || $entry->payments()->exists())) {
                throw ValidationException::withMessages(['purchase' => __('Something was already paid for this purchase in Supplier Payments, so it can no longer be changed.')]);
            }

            // What this purchase put in the stock before, per product: the change is measured against it.
            $before = [];
            foreach ($current->lines()->get() as $line) {
                if ($line->product_id !== null) {
                    $before[(int) $line->product_id] = ($before[(int) $line->product_id] ?? 0) + (int) $line->quantity;
                }
            }

            $this->apply($current, $data, $before);

            // Its entry in Supplier Payments follows: the same supplier, date, words and the new total.
            if ($entry !== null) {
                $current->load('lines');
                if ((int) $current->total_cents < 1) {
                    $entry->update(['voided_at' => now(), 'void_reason' => __('The purchase :number now costs nothing.', ['number' => $current->number])]);
                } else {
                    $this->ledger->rewritePurchaseEntry($entry, $current);
                }
            } else {
                $this->ledger->writeDownPurchase($current);
            }

            return $current->fresh(['lines.product:id,barcode', 'creator:id,name', 'ledgerEntry']);
        });
    }

    /**
     * Writes the purchase (new or changed) and moves the stock. `$before` is what the purchase had put in the stock until
     * now per product (empty for a new one): only the difference moves.
     *
     * @param  array<int, int>  $before
     */
    private function apply(Purchase $purchase, array $data, array $before): void
    {
        $supplier = Supplier::query()->findOrFail($data['supplier_id']);
        $date = Carbon::parse($data['purchased_on']);

        // Several lines may be for the same product; the stock moves once per product.
        $pieces = [];
        $rate = [];
        $sell = [];
        foreach ($data['lines'] as $line) {
            $id = (int) $line['product_id'];
            $pieces[$id] = ($pieces[$id] ?? 0) + (int) $line['quantity'];
            // The last rate typed for a product in this delivery is the one it is now bought at.
            $rate[$id] = (int) $line['unit_cost'];
            // The selling price typed with it (when the shop changes it at the same time).
            if (isset($line['selling_price'])) {
                $sell[$id] = (int) $line['selling_price'];
            }
        }

        $ids = array_values(array_unique([...array_keys($pieces), ...array_keys($before)]));
        $products = Product::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

        foreach (array_keys($pieces) as $id) {
            if (! $products->has($id)) {
                throw ValidationException::withMessages(['lines' => __('One of the products is not in your catalog.')]);
            }
        }

        // Pieces that leave the stock again (a line lowered or taken off) must still be there.
        foreach ($ids as $id) {
            $change = ($pieces[$id] ?? 0) - ($before[$id] ?? 0);
            $product = $products[$id] ?? null;
            if ($change < 0 && $product !== null && (int) $product->quantity < -$change) {
                throw ValidationException::withMessages([
                    'lines' => __(':article has only :left in stock, so :count pieces cannot be taken off this purchase.', [
                        'article' => $product->article,
                        'left' => (int) $product->quantity,
                        'count' => -$change,
                    ]),
                ]);
            }
        }

        $mode = $data['iva_mode'] ?? 'none';
        $percent = $mode === 'none' ? 0.0 : round((float) ($data['iva_percent'] ?? 0), 2);
        if ($percent <= 0) {
            $mode = 'none';
            $percent = 0.0;
        }

        $total = 0;
        $rows = [];
        foreach ($data['lines'] as $line) {
            $lineTotal = (int) $line['quantity'] * (int) $line['unit_cost'];
            $total += $lineTotal;
            $rows[] = [
                'product_id' => (int) $line['product_id'],
                'article' => $products[(int) $line['product_id']]->article,
                'quantity' => (int) $line['quantity'],
                'unit_cost' => (int) $line['unit_cost'],
                'total_cents' => $lineTotal,
            ];
        }

        // The typed prices are before IVA (excluded: it is added on top) or hold it (included: it is taken out of the total).
        if ($mode === 'excluded') {
            $base = $total;
            $iva = (int) round($base * $percent / 100);
            $total = $base + $iva;
        } elseif ($mode === 'included') {
            $base = (int) round($total / (1 + $percent / 100));
            $iva = $total - $base;
        } else {
            $base = $total;
            $iva = 0;
        }

        $purchase->fill([
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'purchased_on' => $date->toDateString(),
            'supplier_invoice_no' => $this->clean($data['supplier_invoice_no'] ?? null),
            'notes' => $this->clean($data['notes'] ?? null),
            'iva_mode' => $mode,
            'iva_percent' => $percent,
            'base_cents' => $base,
            'iva_cents' => $iva,
            'total_cents' => $total,
        ])->save();

        $purchase->lines()->delete();
        $purchase->lines()->createMany($rows);

        foreach ($ids as $id) {
            $product = $products[$id] ?? null;
            if ($product === null) {
                continue;
            }

            $product->quantity = (int) $product->quantity + ($pieces[$id] ?? 0) - ($before[$id] ?? 0);

            if (isset($rate[$id])) {
                // The old buying price moves to last_buying_price by itself (see Product::booted()).
                $cost = $mode === 'included' ? (int) round($rate[$id] * 100 / (100 + $percent)) : $rate[$id];
                $product->buying_price = $cost;
                if (isset($sell[$id])) {
                    $product->selling_price = $sell[$id];
                }
                $product->margin_percent = Pricing::marginFromPrices($cost, (int) $product->selling_price);
            }

            $product->save();
        }
    }

    /** Takes the pieces back out of the stock. Refused when some of them were already sold. */
    public function void(User $user, Purchase $purchase, string $reason): Purchase
    {
        return DB::transaction(function () use ($user, $purchase, $reason) {
            $current = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($current->isVoided()) {
                throw ValidationException::withMessages(['reason' => __('This purchase is already cancelled.')]);
            }

            // Its entry in Supplier Payments goes with it, unless money was already paid against it there.
            $entry = SupplierBill::query()->where('purchase_id', $current->id)->whereNull('voided_at')->lockForUpdate()->first();
            if ($entry !== null && $entry->payments()->exists()) {
                throw ValidationException::withMessages(['reason' => __('Its entry in Supplier Payments has payments. Remove them there first, then cancel this purchase.')]);
            }

            $current->load('lines');

            $pieces = [];
            foreach ($current->lines as $line) {
                if ($line->product_id !== null) {
                    $pieces[(int) $line->product_id] = ($pieces[(int) $line->product_id] ?? 0) + (int) $line->quantity;
                }
            }

            $products = Product::query()->whereIn('id', array_keys($pieces))->lockForUpdate()->get()->keyBy('id');

            foreach ($pieces as $id => $count) {
                $product = $products[$id] ?? null;

                if ($product !== null && (int) $product->quantity < $count) {
                    throw ValidationException::withMessages([
                        'reason' => __(':article has only :left in stock, so these :count pieces cannot be taken back.', [
                            'article' => $product->article,
                            'left' => (int) $product->quantity,
                            'count' => $count,
                        ]),
                    ]);
                }
            }

            foreach ($pieces as $id => $count) {
                $product = $products[$id] ?? null;

                if ($product !== null) {
                    $product->quantity = (int) $product->quantity - $count;
                    $product->save();
                }
            }

            $current->update([
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $user->id,
            ]);

            $entry?->update(['voided_at' => now(), 'void_reason' => __('The purchase :number was cancelled.', ['number' => $current->number]), 'voided_by' => $user->id]);

            return $current->fresh(['lines', 'creator:id,name', 'ledgerEntry']);
        });
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}

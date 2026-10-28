<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
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
    public function __construct(private readonly DocumentNumber $numbers) {}

    /**
     * @param  array{supplier_id: int, purchased_on: string, supplier_invoice_no?: ?string, notes?: ?string, lines: list<array{product_id: int, quantity: int, unit_cost: int}>}  $data
     */
    public function record(User $user, array $data): Purchase
    {
        return DB::transaction(function () use ($user, $data) {
            $supplier = Supplier::query()->findOrFail($data['supplier_id']);
            $companyId = (int) $user->company_id;
            $date = Carbon::parse($data['purchased_on']);

            // Several lines may be for the same product; the stock moves once per product.
            $pieces = [];
            $rate = [];
            foreach ($data['lines'] as $line) {
                $id = (int) $line['product_id'];
                $pieces[$id] = ($pieces[$id] ?? 0) + (int) $line['quantity'];
                // The last rate typed for a product in this delivery is the one it is now bought at.
                $rate[$id] = (int) $line['unit_cost'];
            }

            $products = Product::query()
                ->whereIn('id', array_keys($pieces))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== count($pieces)) {
                throw ValidationException::withMessages(['lines' => __('One of the products is not in your catalog.')]);
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

            $purchase = Purchase::create([
                'company_id' => $companyId,
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'number' => $this->numbers->take($companyId, 'purchase', (int) $date->format('Ym')),
                'purchased_on' => $date->toDateString(),
                'supplier_invoice_no' => $this->clean($data['supplier_invoice_no'] ?? null),
                'notes' => $this->clean($data['notes'] ?? null),
                'total_cents' => $total,
                'created_by' => $user->id,
            ]);

            $purchase->lines()->createMany($rows);

            foreach ($products as $id => $product) {
                $product->quantity = (int) $product->quantity + $pieces[$id];
                // The old buying price moves to last_buying_price by itself (see Product::booted()).
                $product->buying_price = $rate[$id];
                $product->margin_percent = Pricing::marginFromPrices($rate[$id], (int) $product->selling_price);
                $product->save();
            }

            return $purchase->load(['lines', 'creator:id,name']);
        });
    }

    /** Takes the pieces back out of the stock. Refused when some of them were already sold. */
    public function void(User $user, Purchase $purchase, string $reason): Purchase
    {
        return DB::transaction(function () use ($user, $purchase, $reason) {
            $current = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($current->isVoided()) {
                throw ValidationException::withMessages(['reason' => __('This purchase is already cancelled.')]);
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

            return $current->fresh(['lines', 'creator:id,name']);
        });
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}

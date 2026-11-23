<?php

namespace App\Services;

use App\Models\CompanyPaymentMethod;
use App\Models\Product;
use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a draft invoice: it keeps the number and date it was drafted with, and only now takes its goods out of stock.
 * Every line is checked against what is on the shelf today; if any product falls short, nothing is issued and each
 * shortage is named.
 */
class DraftIssuer
{
    /**
     * @param  array{payment_status: string, payment_method_id?: int|null}  $input
     */
    public function issue(SalesDocument $draft, array $input): SalesDocument
    {
        return DB::transaction(function () use ($draft, $input) {
            /** @var SalesDocument $document */
            $document = SalesDocument::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if (! $document->canIssueDraft()) {
                throw ValidationException::withMessages([
                    'document' => $document->isVoided()
                        ? __('This draft was deleted and can no longer be issued.')
                        : __('Only a draft invoice can be issued this way.'),
                ]);
            }

            $document->load('lines');
            $companyId = (int) $document->company_id;

            // What the draft needs of each product, summed over its lines.
            $needed = [];
            foreach ($document->lines as $line) {
                if ($line->product_id !== null) {
                    $needed[$line->product_id] = ($needed[$line->product_id] ?? 0) + (int) $line->quantity;
                }
            }

            $products = $needed === []
                ? collect()
                : Product::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->whereIn('id', array_keys($needed))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

            $short = [];
            foreach ($needed as $productId => $quantity) {
                $product = $products->get($productId);

                if ($product === null) {
                    $short[] = __('A product on this draft is no longer in the catalog. Remove that line and try again.');
                } elseif ($product->quantity < $quantity) {
                    $short[] = __(':article: :needed needed, only :stock available now.', [
                        'article' => $product->article,
                        'needed' => $quantity,
                        'stock' => $product->quantity,
                    ]);
                }
            }

            if ($short !== []) {
                throw ValidationException::withMessages(['lines' => $short]);
            }

            foreach ($needed as $productId => $quantity) {
                $product = $products[$productId];
                $product->quantity -= $quantity;
                $product->save();
            }

            $paid = ($input['payment_status'] ?? 'pending') === 'paid';

            $document->forceFill([
                // The number and the date stay as drafted; only now is it a sale.
                'draft_at' => null,
                'payment_status' => $paid ? 'paid' : 'pending',
                'payment_method_id' => $paid ? CompanyPaymentMethod::requireActive($companyId, $input['payment_method_id'] ?? null) : null,
            ])->save();

            return $document->fresh(['lines', 'paymentMethod']);
        });
    }

    /**
     * What each line of a live draft would lack if it were issued now: [{position, product_id, article, needed, available}].
     * Shown when the draft is opened again, so the shortage is seen before trying to issue it.
     *
     * @return list<array{position: int, product_id: int, article: string, needed: int, available: int}>
     */
    public static function shortages(SalesDocument $document): array
    {
        if (! $document->canIssueDraft()) {
            return [];
        }

        $lines = $document->relationLoaded('lines') ? $document->lines : $document->lines()->get();
        $ids = $lines->pluck('product_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $stock = Product::withoutGlobalScopes()
            ->where('company_id', $document->company_id)
            ->whereIn('id', $ids)
            ->pluck('quantity', 'id');

        // Several lines of the same product share its stock: each is checked against what the earlier ones leave.
        $left = $stock->all();
        $out = [];
        foreach ($lines->sortBy('position') as $line) {
            if ($line->product_id === null) {
                continue;
            }

            $available = (int) ($left[$line->product_id] ?? 0);
            if ((int) $line->quantity > $available) {
                $out[] = [
                    'position' => (int) $line->position,
                    'product_id' => (int) $line->product_id,
                    'article' => (string) $line->article,
                    'needed' => (int) $line->quantity,
                    'available' => max(0, $available),
                ];
            }
            $left[$line->product_id] = $available - (int) $line->quantity;
        }

        return $out;
    }
}

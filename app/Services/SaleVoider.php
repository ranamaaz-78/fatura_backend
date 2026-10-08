<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleVoider
{
    public function void(User $user, SalesDocument $sale, string $reason): SalesDocument
    {
        return DB::transaction(function () use ($user, $sale, $reason) {
            /** @var SalesDocument $document */
            $document = SalesDocument::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->isVoided()) {
                throw ValidationException::withMessages([
                    'reason' => __('This document is already voided.'),
                ]);
            }

            if (! $document->canVoid()) {
                throw ValidationException::withMessages([
                    'reason' => __('Only a paid invoice or delivery note can be voided.'),
                ]);
            }

            $document->load('lines');
            $this->restock($document);

            $document->update([
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $user->id,
            ]);

            return $document->fresh(['lines', 'paymentMethod']);
        });
    }

    private function restock(SalesDocument $document): void
    {
        $direction = $document->stockDirection();
        // An invoice made from a proforma payment never took stock out.
        if ($direction === 0 || $document->isDerived()) {
            return;
        }

        $needed = [];
        foreach ($document->lines as $line) {
            if ($line->product_id === null) {
                continue;
            }

            $needed[(int) $line->product_id] = ($needed[(int) $line->product_id] ?? 0) + (int) $line->quantity;
        }

        if ($needed === []) {
            return;
        }

        $products = Product::withoutGlobalScopes()
            ->where('company_id', $document->company_id)
            ->whereIn('id', array_keys($needed))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($needed as $productId => $quantity) {
            $product = $products[$productId] ?? null;
            if ($product === null) {
                continue;
            }

            $product->quantity += (-1 * $direction) * $quantity;
            $product->save();
        }
    }
}

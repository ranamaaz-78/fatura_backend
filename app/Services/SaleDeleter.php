<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a document that was never paid, and brings it back. The row stays (so its number is never used again);
 * it just drops out of the lists, the reports and the dashboard. See SalesDocument::deleteBlockedReason() for which
 * documents may be deleted.
 */
class SaleDeleter
{
    public function __construct(private readonly SaleVoider $voider) {}

    public function delete(User $user, SalesDocument $sale): SalesDocument
    {
        return DB::transaction(function () use ($user, $sale) {
            /** @var SalesDocument $document */
            $document = SalesDocument::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if (($reason = $document->deleteBlockedReason()) !== null) {
                throw ValidationException::withMessages(['document' => $reason]);
            }

            // The stock it took out goes back on the shelf (a quotation or an invoice made from another document took none).
            $document->load('lines');
            $this->voider->restock($document);

            // What it was made from (a quotation, a delivery note) is free to be used again.
            $source = SalesDocument::query()->where('converted_to_id', $document->id)->lockForUpdate()->first();
            $source?->forceFill(['converted_to_id' => null, 'converted_at' => null])->save();

            $document->forceFill(['deleted_by' => $user->id, 'deleted_source_id' => $source?->id])->save();
            $document->delete();

            return SalesDocument::withTrashed()->with(['lines', 'paymentMethod', 'deleter'])->findOrFail($document->id);
        });
    }

    public function restore(User $user, SalesDocument $sale): SalesDocument
    {
        return DB::transaction(function () use ($sale) {
            /** @var SalesDocument|null $document */
            $document = SalesDocument::onlyTrashed()->whereKey($sale->id)->lockForUpdate()->first();

            if ($document === null) {
                throw ValidationException::withMessages(['document' => __('This document is not deleted.')]);
            }

            // What it was made from must still be free: it cannot be made into two documents at once.
            $source = null;
            if ($document->deleted_source_id !== null) {
                $source = SalesDocument::query()->whereKey($document->deleted_source_id)->lockForUpdate()->first();

                $free = $source !== null && ($source->type === 'albaran' ? $source->canInvoice() && ! $source->isConverted() : ! $source->isConverted());
                if (! $free) {
                    throw ValidationException::withMessages([
                        'document' => __('It cannot be restored: the :type it was made from was already used again.', [
                            'type' => mb_strtolower(SalesDocument::typeLabel($source?->type ?? 'quotation')),
                        ]),
                    ]);
                }
            }

            $document->load('lines');
            $this->takeStock($document);

            $source?->forceFill(['converted_to_id' => $document->id, 'converted_at' => now()])->save();

            $document->forceFill(['deleted_by' => null, 'deleted_source_id' => null])->save();
            $document->restore();

            return $document->fresh(['lines', 'paymentMethod', 'deleter']);
        });
    }

    /** Takes the pieces of a restored document out of the stock again, the way issuing it did. Refused when they are not there. */
    private function takeStock(SalesDocument $document): void
    {
        $direction = $document->stockDirection();
        if ($direction === 0 || $document->isDerived()) {
            return;
        }

        $needed = [];
        foreach ($document->lines as $line) {
            if ($line->product_id !== null) {
                $needed[(int) $line->product_id] = ($needed[(int) $line->product_id] ?? 0) + (int) $line->quantity;
            }
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

        // Enough of everything first, so nothing is taken when something is short.
        foreach ($needed as $productId => $quantity) {
            $product = $products[$productId] ?? null;
            if ($product !== null && (int) $product->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'document' => __('Not enough stock for :article. Only :stock left.', ['article' => $product->article, 'stock' => (int) $product->quantity]),
                ]);
            }
        }

        foreach ($needed as $productId => $quantity) {
            $product = $products[$productId] ?? null;
            if ($product !== null) {
                $product->quantity -= $quantity;
                $product->save();
            }
        }
    }
}

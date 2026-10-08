<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentReturn;
use App\Models\SalesDocumentReturnLine;
use App\Models\SalesDocumentSettlementLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pieces of a proforma that came back. They return to stock and stop counting as owed; no money moves.
 * The proforma is finished once every piece is either paid for or returned.
 */
class SaleReturner
{
    public function record(User $user, SalesDocument $sale, array $input): SalesDocument
    {
        return DB::transaction(function () use ($user, $sale, $input) {
            $document = $this->lock($sale);

            if (! $document->canReturn()) {
                throw ValidationException::withMessages([
                    'type' => $document->type !== 'proforma'
                        ? __('Only a proforma can have pieces returned.')
                        : __('This proforma is already settled.'),
                ]);
            }

            $lines = SalesDocumentLine::query()
                ->where('sales_document_id', $document->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            [$settled, $returned] = $this->quantities($lines->keys()->all());

            $seen = [];
            $rows = [];
            $totalCents = 0;

            foreach ($input['lines'] ?? [] as $index => $row) {
                $lineId = (int) ($row['line_id'] ?? 0);
                $quantity = (int) ($row['quantity'] ?? 0);

                if (isset($seen[$lineId])) {
                    throw ValidationException::withMessages(["lines.{$index}.line_id" => __('Return each line once.')]);
                }
                $seen[$lineId] = true;

                $line = $lines->get($lineId);
                if ($line === null) {
                    throw ValidationException::withMessages(["lines.{$index}.line_id" => __('That line is not on this proforma.')]);
                }

                $remaining = (int) $line->quantity - (int) ($settled[$lineId] ?? 0) - (int) ($returned[$lineId] ?? 0);
                if ($quantity < 1 || $quantity > $remaining) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => __('Pick between 1 and :remaining pieces.', ['remaining' => max(0, $remaining)]),
                    ]);
                }

                $value = $quantity * (int) $line->unit_price;
                $totalCents += $value;
                $rows[] = [
                    'sales_document_line_id' => $lineId,
                    'quantity' => $quantity,
                    'unit_price' => (int) $line->unit_price,
                    'total_cents' => $value,
                ];
            }

            if ($rows === []) {
                throw ValidationException::withMessages(['lines' => __('Pick at least one line to return.')]);
            }

            $return = SalesDocumentReturn::create([
                'company_id' => $document->company_id,
                'sales_document_id' => $document->id,
                'created_by' => $user->id,
                'note' => filled($input['note'] ?? null) ? trim((string) $input['note']) : null,
                'total_cents' => $totalCents,
            ]);
            $return->lines()->createMany($rows);

            $this->moveStock($lines, $rows, 1);
            $this->refresh($document);

            return $document->fresh(SaleSettler::relations());
        });
    }

    /** Undo a return that was recorded by mistake: the pieces leave stock again and count as owed. */
    public function cancel(SalesDocument $sale, SalesDocumentReturn $return): SalesDocument
    {
        return DB::transaction(function () use ($sale, $return) {
            $document = $this->lock($sale);

            $locked = SalesDocumentReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->sales_document_id !== (int) $document->id) {
                throw ValidationException::withMessages(['return' => __('That return is not on this proforma.')]);
            }

            $lines = SalesDocumentLine::query()->where('sales_document_id', $document->id)->lockForUpdate()->get()->keyBy('id');
            $rows = $locked->lines()->get()->map(fn ($line) => [
                'sales_document_line_id' => $line->sales_document_line_id,
                'quantity' => $line->quantity,
            ])->all();

            $this->moveStock($lines, $rows, -1);
            $locked->delete();
            $this->refresh($document);

            return $document->fresh(SaleSettler::relations());
        });
    }

    /** Recount what is paid and returned, and set the document's status and returned total. */
    public function refresh(SalesDocument $document): void
    {
        $lines = SalesDocumentLine::query()->where('sales_document_id', $document->id)->get();
        [$settled, $returned] = $this->quantities($lines->pluck('id')->all());

        $resolved = $lines->isNotEmpty() && $lines->every(
            fn (SalesDocumentLine $line) => (int) ($settled[$line->id] ?? 0) + (int) ($returned[$line->id] ?? 0) >= (int) $line->quantity,
        );

        $document->update([
            'returned_cents' => (int) SalesDocumentReturn::query()->where('sales_document_id', $document->id)->sum('total_cents'),
            'payment_status' => $resolved ? 'paid' : (array_sum($settled) > 0 ? 'partial' : 'pending'),
        ]);
    }

    /**
     * @param  list<int>  $lineIds
     * @return array{0: array<int, int>, 1: array<int, int>} paid and returned pieces per line
     */
    public function quantities(array $lineIds): array
    {
        $settled = SalesDocumentSettlementLine::query()
            ->whereIn('sales_document_line_id', $lineIds)
            ->selectRaw('sales_document_line_id, SUM(quantity) as qty')
            ->groupBy('sales_document_line_id')
            ->pluck('qty', 'sales_document_line_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();

        $returned = SalesDocumentReturnLine::query()
            ->whereIn('sales_document_line_id', $lineIds)
            ->selectRaw('sales_document_line_id, SUM(quantity) as qty')
            ->groupBy('sales_document_line_id')
            ->pluck('qty', 'sales_document_line_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();

        return [$settled, $returned];
    }

    private function lock(SalesDocument $sale): SalesDocument
    {
        return SalesDocument::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SalesDocumentLine>  $lines
     * @param  list<array{sales_document_line_id: int, quantity: int}>  $rows
     */
    private function moveStock($lines, array $rows, int $direction): void
    {
        foreach ($rows as $row) {
            $productId = $lines->get($row['sales_document_line_id'])?->product_id;
            if ($productId === null) {
                continue;
            }

            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            if ($product === null) {
                continue;
            }

            $next = $product->quantity + ($direction * (int) $row['quantity']);
            if ($next < 0) {
                throw ValidationException::withMessages([
                    'lines' => __('Not enough stock for :article. Only :stock left.', [
                        'article' => $product->article,
                        'stock' => $product->quantity,
                    ]),
                ]);
            }

            $product->update(['quantity' => $next]);
        }
    }
}

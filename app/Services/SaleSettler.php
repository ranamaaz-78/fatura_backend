<?php

namespace App\Services;

use App\Models\CompanyPaymentMethod;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentSettlement;
use App\Models\SalesDocumentSettlementLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleSettler
{
    public function settle(User $user, SalesDocument $sale, array $input): SalesDocument
    {
        return DB::transaction(function () use ($user, $sale, $input) {
            /** @var SalesDocument $document */
            $document = SalesDocument::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->type !== 'proforma') {
                throw ValidationException::withMessages([
                    'type' => __('Only a proforma can be settled this way.'),
                ]);
            }

            if ($document->payment_status === 'paid') {
                throw ValidationException::withMessages([
                    'lines' => __('This proforma is already settled.'),
                ]);
            }

            $companyId = (int) $user->company_id;
            $methodId = CompanyPaymentMethod::requireActive($companyId, $input['payment_method_id'] ?? null);

            $lines = SalesDocumentLine::query()
                ->where('sales_document_id', $document->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $settledQty = SalesDocumentSettlementLine::query()
                ->whereIn('sales_document_line_id', $lines->keys())
                ->selectRaw('sales_document_line_id, SUM(quantity) as qty')
                ->groupBy('sales_document_line_id')
                ->pluck('qty', 'sales_document_line_id');

            $rows = $input['lines'] ?? [];
            $seen = [];
            $settlementLines = [];
            $addedQty = [];
            $totalCents = 0;

            foreach ($rows as $index => $row) {
                $lineId = (int) ($row['line_id'] ?? 0);
                $quantity = (int) ($row['quantity'] ?? 0);
                $unitPrice = (int) ($row['unit_price'] ?? 0);

                if (isset($seen[$lineId])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.line_id" => __('Settle each line once in this payment.'),
                    ]);
                }
                $seen[$lineId] = true;

                $line = $lines->get($lineId);
                if ($line === null) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.line_id" => __('That line is not on this proforma.'),
                    ]);
                }

                $remaining = (int) $line->quantity - (int) ($settledQty[$lineId] ?? 0);
                if ($quantity < 1 || $quantity > $remaining) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => __('Pick between 1 and :remaining pieces.', [
                            'remaining' => max(0, $remaining),
                        ]),
                    ]);
                }

                if ($unitPrice < 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.unit_price" => __('Price cannot be negative.'),
                    ]);
                }

                $lineTotal = $quantity * $unitPrice;
                $totalCents += $lineTotal;
                $addedQty[$lineId] = $quantity;
                $settlementLines[] = [
                    'sales_document_line_id' => $lineId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_cents' => $lineTotal,
                ];
            }

            if ($settlementLines === []) {
                throw ValidationException::withMessages([
                    'lines' => __('Pick at least one line to settle.'),
                ]);
            }

            $settlement = SalesDocumentSettlement::create([
                'company_id' => $document->company_id,
                'sales_document_id' => $document->id,
                'payment_method_id' => $methodId,
                'created_by' => $user->id,
                'total_cents' => $totalCents,
            ]);
            $settlement->lines()->createMany($settlementLines);

            $fullySettled = $lines->every(function (SalesDocumentLine $line) use ($settledQty, $addedQty) {
                $done = (int) ($settledQty[$line->id] ?? 0) + (int) ($addedQty[$line->id] ?? 0);

                return $done >= (int) $line->quantity;
            });

            $document->update([
                'payment_status' => $fullySettled ? 'paid' : 'partial',
            ]);

            return $document->fresh(self::relations());
        });
    }

    public function updateQuantities(SalesDocument $sale, SalesDocumentSettlement $settlement, array $input): SalesDocument
    {
        return DB::transaction(function () use ($sale, $settlement, $input) {
            /** @var SalesDocument $document */
            $document = SalesDocument::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->type !== 'proforma') {
                throw ValidationException::withMessages([
                    'type' => __('Only a proforma payment can be edited.'),
                ]);
            }

            /** @var SalesDocumentSettlement $locked */
            $locked = SalesDocumentSettlement::query()
                ->whereKey($settlement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->sales_document_id !== (int) $document->id) {
                throw ValidationException::withMessages([
                    'settlement' => __('That payment is not on this proforma.'),
                ]);
            }

            $lines = SalesDocumentLine::query()
                ->where('sales_document_id', $document->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $currentLines = $locked->lines()->lockForUpdate()->get()->keyBy('sales_document_line_id');

            $otherQty = SalesDocumentSettlementLine::query()
                ->whereIn('sales_document_line_id', $lines->keys())
                ->where('sales_document_settlement_id', '!=', $locked->id)
                ->selectRaw('sales_document_line_id, SUM(quantity) as qty')
                ->groupBy('sales_document_line_id')
                ->pluck('qty', 'sales_document_line_id');

            $rows = $input['lines'] ?? [];
            $seen = [];
            $nextQty = [];
            $totalCents = 0;

            foreach ($rows as $index => $row) {
                $lineId = (int) ($row['line_id'] ?? 0);
                $quantity = (int) ($row['quantity'] ?? 0);

                if (isset($seen[$lineId])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.line_id" => __('Edit each line once.'),
                    ]);
                }
                $seen[$lineId] = true;

                $current = $currentLines->get($lineId);
                if ($current === null) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.line_id" => __('That line is not on this payment.'),
                    ]);
                }

                $documentLine = $lines->get($lineId);
                if ($documentLine === null) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.line_id" => __('That line is not on this proforma.'),
                    ]);
                }

                $max = (int) $documentLine->quantity - (int) ($otherQty[$lineId] ?? 0);
                if ($quantity < 1 || $quantity > $max) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => __('Pick between 1 and :remaining pieces.', [
                            'remaining' => max(0, $max),
                        ]),
                    ]);
                }

                $unitPrice = (int) $current->unit_price;
                $lineTotal = $quantity * $unitPrice;
                $totalCents += $lineTotal;
                $nextQty[$lineId] = $quantity;

                $current->update([
                    'quantity' => $quantity,
                    'total_cents' => $lineTotal,
                ]);
            }

            if ($nextQty === [] || count($nextQty) !== $currentLines->count()) {
                throw ValidationException::withMessages([
                    'lines' => __('Set a quantity for every line on this payment.'),
                ]);
            }

            $locked->update(['total_cents' => $totalCents]);

            $fullySettled = $lines->every(function (SalesDocumentLine $line) use ($otherQty, $nextQty) {
                $done = (int) ($otherQty[$line->id] ?? 0) + (int) ($nextQty[$line->id] ?? 0);

                return $done >= (int) $line->quantity;
            });

            $document->update([
                'payment_status' => $fullySettled ? 'paid' : 'partial',
            ]);

            return $document->fresh(self::relations());
        });
    }

    /**
     * @return list<string>
     */
    public static function relations(): array
    {
        return [
            'lines.settlementLines',
            'paymentMethod',
            'convertedTo',
            'settlements.paymentMethod',
            'settlements.lines',
        ];
    }
}

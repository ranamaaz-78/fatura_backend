<?php

namespace App\Services;

use App\Models\CompanyPaymentMethod;
use App\Models\Customer;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\RecargoRate;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\Fmt;
use App\Support\SaleMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleIssuer
{
    public function __construct(private readonly DocumentNumber $numbers) {}

    public function issue(User $user, array $input): SalesDocument
    {
        return DB::transaction(function () use ($user, $input) {
            $companyId = (int) $user->company_id;
            $lines = $this->pricedLines($companyId, $input['lines'], $input['type'], moveStock: empty($input['from_settlement_id']));
            [$lines, $discount] = $this->applyBillDiscount($lines, $input, $input['type']);
            $customer = $this->resolveCustomer($companyId, $input);

            $baseCents = (int) array_sum(array_column($lines, 'base_cents'));
            $taxCents = (int) array_sum(array_column($lines, 'tax_cents'));
            [$recargoPercent, $recargoCents] = $this->resolveRecargo($companyId, $input, $baseCents, $input['type']);

            $issuedAt = $input['issued_at'];
            $document = SalesDocument::create([
                'company_id' => $companyId,
                'customer_id' => $customer?->id,
                'created_by' => $user->id,
                'type' => $input['type'],
                'number' => $this->numbers->take($companyId, $input['type'], (int) $issuedAt->year),
                'issued_at' => $issuedAt,
                'from_settlement_id' => $input['from_settlement_id'] ?? null,
                'expires_at' => $input['type'] === 'quotation' ? SalesDocument::expiryFor($issuedAt) : null,
                'payment_status' => $input['payment_status'],
                'payment_method_id' => $this->resolvePaymentMethod($companyId, $input),
                'client_code' => $customer?->code ?? (($input['client_code'] ?? '') !== '' ? $input['client_code'] : null),
                'client_name' => $input['client_name'],
                'client_company' => ($input['client_company'] ?? '') !== '' ? $input['client_company'] : null,
                'client_phone' => ($input['client_phone'] ?? '') !== '' ? $input['client_phone'] : null,
                'client_nif' => ($input['client_nif'] ?? '') !== '' ? $input['client_nif'] : null,
                'client_nie' => ($input['client_nie'] ?? '') !== '' ? $input['client_nie'] : null,
                'client_address' => ($input['client_address'] ?? '') !== '' ? $input['client_address'] : null,
                // Stamped from Printables, never typed on the document. It keeps the note it was issued with.
                'notes' => PrintTemplate::notesFor($companyId, $input['type']),
                'base_cents' => $baseCents,
                'tax_cents' => $taxCents,
                'discount_type' => $discount['type'],
                'discount_value' => $discount['value'],
                'discount_cents' => $discount['cents'],
                'recargo_percent' => $recargoPercent,
                'recargo_cents' => $recargoCents,
                'total_cents' => $baseCents + $taxCents + $recargoCents,
            ]);

            $document->lines()->createMany($lines);

            return $document->load(['lines', 'paymentMethod']);
        });
    }

    public function updateQuote(User $user, SalesDocument $quote, array $input): SalesDocument
    {
        return DB::transaction(function () use ($user, $quote, $input) {
            /** @var SalesDocument $document */
            $document = SalesDocument::query()
                ->whereKey($quote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $document->canEdit()) {
                throw ValidationException::withMessages([
                    'type' => $document->isConverted()
                        ? __('This quotation has already been converted.')
                        : ($document->isExpired()
                            ? __('This quotation expired on :date and can no longer be edited.', ['date' => Fmt::date($document->expires_at)])
                            : __('Only a quotation can be edited.')),
                ]);
            }

            $companyId = (int) $user->company_id;
            $lines = $this->pricedLines($companyId, $input['lines'], 'quotation');
            [$lines, $discount] = $this->applyBillDiscount($lines, $input, 'quotation');
            $customer = $this->resolveCustomer($companyId, $input);

            $baseCents = (int) array_sum(array_column($lines, 'base_cents'));
            $taxCents = (int) array_sum(array_column($lines, 'tax_cents'));
            [$recargoPercent, $recargoCents] = $this->resolveRecargo($companyId, $input, $baseCents, 'quotation');

            $document->lines()->delete();
            $document->update([
                'customer_id' => $customer?->id,
                'issued_at' => $input['issued_at'],
                // The week runs from the date written on the quotation, so moving that date moves the end too.
                'expires_at' => SalesDocument::expiryFor($input['issued_at']),
                'client_code' => $customer?->code ?? (($input['client_code'] ?? '') !== '' ? $input['client_code'] : $document->client_code),
                'client_name' => $input['client_name'],
                'client_company' => ($input['client_company'] ?? '') !== '' ? $input['client_company'] : null,
                'client_phone' => ($input['client_phone'] ?? '') !== '' ? $input['client_phone'] : null,
                'client_nif' => ($input['client_nif'] ?? '') !== '' ? $input['client_nif'] : null,
                'client_nie' => ($input['client_nie'] ?? '') !== '' ? $input['client_nie'] : $document->client_nie,
                'client_address' => ($input['client_address'] ?? '') !== '' ? $input['client_address'] : null,
                // The note the quotation was issued with stays as it is; only Printables sets notes.
                'base_cents' => $baseCents,
                'tax_cents' => $taxCents,
                'discount_type' => $discount['type'],
                'discount_value' => $discount['value'],
                'discount_cents' => $discount['cents'],
                'recargo_percent' => $recargoPercent,
                'recargo_cents' => $recargoCents,
                'total_cents' => $baseCents + $taxCents + $recargoCents,
            ]);
            $document->lines()->createMany($lines);

            return $document->fresh(['lines', 'paymentMethod', 'convertedTo']);
        });
    }

    public function convert(User $user, SalesDocument $quote, array $input): SalesDocument
    {
        return DB::transaction(function () use ($user, $quote, $input) {
            /** @var SalesDocument $document */
            $document = SalesDocument::query()
                ->whereKey($quote->id)
                ->lockForUpdate()
                ->firstOrFail();

            $document->load('lines');

            if (! $document->canConvert()) {
                throw ValidationException::withMessages([
                    'type' => $document->isConverted()
                        ? __('This quotation has already been converted.')
                        : ($document->isExpired()
                            ? __('This quotation expired on :date and can no longer be converted.', ['date' => Fmt::date($document->expires_at)])
                            : __('Only an open quotation can be converted.')),
                ]);
            }

            $issued = $this->issue($user, [
                'type' => $input['type'],
                'issued_at' => $input['issued_at'],
                'payment_status' => $input['payment_status'],
                'payment_method_id' => $input['payment_method_id'] ?? null,
                'customer_id' => $document->customer_id,
                'save_customer' => false,
                'client_name' => $document->client_name,
                'client_company' => $document->client_company,
                'client_phone' => $document->client_phone,
                'client_nif' => $document->client_nif,
                'client_nie' => $document->client_nie,
                'client_address' => $document->client_address,
                // The new document takes the note of its own type from Printables.
                'notes' => null,
                // Carried as the rate the quote had, even if it has since left Settings.
                'recargo_percent' => SalesDocument::carriesRecargo($input['type']) ? $document->recargo_percent : null,
                'discount_type' => $document->discount_type,
                'discount_value' => $document->discount_type === 'amount' ? (int) round($document->discount_value) : $document->discount_value,
                'lines' => $document->lines->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'sr_number' => $line->sr_number,
                    'article' => $line->article,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'iva_percent' => $line->iva_percent,
                ])->all(),
            ]);

            $document->update([
                'converted_to_id' => $issued->id,
                'converted_at' => now(),
            ]);

            return $issued;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function pricedLines(int $companyId, array $rows, string $type, bool $moveStock = true): array
    {
        $needed = [];
        foreach ($rows as $row) {
            if (! empty($row['product_id'])) {
                $needed[(int) $row['product_id']] = ($needed[(int) $row['product_id']] ?? 0) + (int) $row['quantity'];
            }
        }

        // An invoice made from a proforma payment moves no stock: the proforma took it out already.
        $direction = $moveStock ? SalesDocument::stockDirectionFor($type) : 0;

        $products = $needed === []
            ? collect()
            : Product::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('id', array_keys($needed))
                ->when($direction !== 0, fn ($query) => $query->lockForUpdate())
                ->get()
                ->keyBy('id');

        if ($products->count() !== count($needed)) {
            throw ValidationException::withMessages([
                'lines' => __('One of the products is no longer in this catalog.'),
            ]);
        }

        if ($direction !== 0) {
            foreach ($needed as $productId => $quantity) {
                $product = $products[$productId];
                $next = $product->quantity + ($direction * $quantity);

                if ($next < 0) {
                    throw ValidationException::withMessages([
                        'lines' => __('Not enough stock for :article. Only :stock left.', [
                            'article' => $product->article,
                            'stock' => $product->quantity,
                        ]),
                    ]);
                }
            }
        }

        // The form already hides these, but the rule belongs here so a crafted
        // request cannot tax an albarán or knock money off a proforma.
        $taxable = SalesDocument::carriesTax($type);
        $discountable = SalesDocument::carriesDiscount($type);

        $priced = [];
        foreach ($rows as $index => $row) {
            $discount = $discountable ? (float) $row['discount_percent'] : 0.0;
            $iva = $taxable ? (float) $row['iva_percent'] : 0.0;

            $math = SaleMath::line(
                (int) $row['quantity'],
                (int) $row['unit_price'],
                $discount,
                $iva,
            );

            $priced[] = [
                'product_id' => $row['product_id'] ?? null,
                'position' => $index + 1,
                'sr_number' => ($row['sr_number'] ?? '') !== '' ? $row['sr_number'] : null,
                'article' => $row['article'],
                'description' => ($row['description'] ?? '') !== '' ? $row['description'] : null,
                'quantity' => (int) $row['quantity'],
                'unit_price' => (int) $row['unit_price'],
                'discount_percent' => $discount,
                'iva_percent' => $iva,
                'base_cents' => $math['base'],
                'tax_cents' => $math['tax'],
                'total_cents' => $math['total'],
            ];
        }

        if ($direction !== 0) {
            foreach ($needed as $productId => $quantity) {
                $product = $products[$productId];
                $product->quantity += $direction * $quantity;
                $product->save();
            }
        }

        return $priced;
    }

    private function resolveCustomer(int $companyId, array $input): ?Customer
    {
        if (! empty($input['customer_id'])) {
            $customer = Customer::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey($input['customer_id'])
                ->first();

            if ($customer === null) {
                throw ValidationException::withMessages([
                    'customer_id' => __('That client is not in this folder.'),
                ]);
            }

            if (! $customer->is_active) {
                throw ValidationException::withMessages([
                    'customer_id' => __('That client is inactive.'),
                ]);
            }

            $customer->update([
                'name' => $input['client_name'],
                'company_name' => ($input['client_company'] ?? '') !== '' ? $input['client_company'] : null,
                'phone' => ($input['client_phone'] ?? '') !== '' ? $input['client_phone'] : null,
                'nif' => ($input['client_nif'] ?? '') !== '' ? $input['client_nif'] : null,
                'nie' => ($input['client_nie'] ?? '') !== '' ? $input['client_nie'] : $customer->nie,
                'address' => ($input['client_address'] ?? '') !== '' ? $input['client_address'] : null,
            ]);

            return $customer;
        }

        if (empty($input['save_customer'])) {
            return null;
        }

        return Customer::create([
            'company_id' => $companyId,
            'code' => $this->numbers->take($companyId, 'client'),
            'name' => $input['client_name'],
            'company_name' => ($input['client_company'] ?? '') !== '' ? $input['client_company'] : null,
            'phone' => ($input['client_phone'] ?? '') !== '' ? $input['client_phone'] : null,
            'nif' => ($input['client_nif'] ?? '') !== '' ? $input['client_nif'] : null,
            'nie' => ($input['client_nie'] ?? '') !== '' ? $input['client_nie'] : null,
            'address' => ($input['client_address'] ?? '') !== '' ? $input['client_address'] : null,
        ]);
    }

    /**
     * A discount on the whole bill comes off the taxable base, so IVA is then worked out on what
     * is left. It is split over the lines in proportion to their bases, which keeps every line,
     * the per-rate tax report and the document total in step, cent for cent.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{0: array<int, array<string, mixed>>, 1: array{type: string|null, value: float|int|null, cents: int}}
     */
    private function applyBillDiscount(array $lines, array $input, string $type): array
    {
        $none = ['type' => null, 'value' => null, 'cents' => 0];
        $kind = $input['discount_type'] ?? null;

        if ($kind === null || $kind === '') {
            return [array_map(fn ($line) => $line + ['bill_discount_cents' => 0], $lines), $none];
        }

        if (! SalesDocument::carriesDiscount($type)) {
            throw ValidationException::withMessages([
                'discount_type' => __('This kind of document does not take a discount.'),
            ]);
        }

        $value = (float) ($input['discount_value'] ?? 0);
        $gross = (int) array_sum(array_column($lines, 'base_cents'));

        if ($kind === 'percent') {
            if ($value > 100) {
                throw ValidationException::withMessages([
                    'discount_value' => __('A discount cannot be more than 100%.'),
                ]);
            }

            $cents = SaleMath::percentOf($gross, $value);
            $stored = round($value, 2);
        } else {
            $cents = (int) round($value);

            if ($cents > $gross) {
                throw ValidationException::withMessages([
                    'discount_value' => __('The discount cannot be more than the bill.'),
                ]);
            }

            $stored = $cents;
        }

        if ($cents <= 0) {
            return [array_map(fn ($line) => $line + ['bill_discount_cents' => 0], $lines), $none];
        }

        $shares = SaleMath::allocate(array_column($lines, 'base_cents'), $cents);

        foreach ($lines as $index => $line) {
            $base = (int) $line['base_cents'] - $shares[$index];
            $tax = SaleMath::taxOn($base, (float) $line['iva_percent']);

            $lines[$index]['base_cents'] = $base;
            $lines[$index]['tax_cents'] = $tax;
            $lines[$index]['total_cents'] = $base + $tax;
            $lines[$index]['bill_discount_cents'] = $shares[$index];
        }

        return [$lines, ['type' => $kind, 'value' => $stored, 'cents' => $cents]];
    }

    /**
     * The rate comes from the company's own recargo list and the amount is worked out
     * here, so a crafted request cannot invent a percentage or an amount.
     *
     * @return array{0: float|null, 1: int}
     */
    private function resolveRecargo(int $companyId, array $input, int $baseCents, string $type): array
    {
        // Only convert() sets this: a quotation's own rate travelling to the invoice made from it.
        if (($input['recargo_percent'] ?? null) !== null && (float) $input['recargo_percent'] > 0) {
            $percent = (float) $input['recargo_percent'];

            return [$percent, SaleMath::recargo($baseCents, $percent)];
        }

        if (empty($input['recargo_rate_id'])) {
            return [null, 0];
        }

        if (! SalesDocument::carriesRecargo($type)) {
            throw ValidationException::withMessages([
                'recargo_rate_id' => __('Recargo de equivalencia can only be added to an invoice or a quotation.'),
            ]);
        }

        $rate = RecargoRate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereKey($input['recargo_rate_id'])
            ->first();

        if ($rate === null) {
            throw ValidationException::withMessages([
                'recargo_rate_id' => __('That recargo rate is not in your settings.'),
            ]);
        }

        return [(float) $rate->rate, SaleMath::recargo($baseCents, (float) $rate->rate)];
    }

    private function resolvePaymentMethod(int $companyId, array $input): ?int
    {
        if (($input['payment_status'] ?? '') !== 'paid' || ! SalesDocument::settlesPayment($input['type'])) {
            return null;
        }

        // Paid at the time of the proforma payment: keep that method even if it has since been switched off.
        if (! empty($input['from_settlement_id'])) {
            return (int) $input['payment_method_id'];
        }

        return CompanyPaymentMethod::requireActive($companyId, $input['payment_method_id'] ?? null);
    }
}

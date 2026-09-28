<?php

namespace App\Services;

use App\Models\CompanyPaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\User;
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
            $lines = $this->pricedLines($companyId, $input['lines'], $input['type']);
            $customer = $this->resolveCustomer($companyId, $input);

            $issuedAt = $input['issued_at'];
            $document = SalesDocument::create([
                'company_id' => $companyId,
                'customer_id' => $customer?->id,
                'created_by' => $user->id,
                'type' => $input['type'],
                'number' => $this->numbers->take($companyId, $input['type'], (int) $issuedAt->year),
                'issued_at' => $issuedAt,
                'payment_status' => $input['payment_status'],
                'payment_method_id' => $this->resolvePaymentMethod($companyId, $input),
                'client_code' => $customer?->code ?? (($input['client_code'] ?? '') !== '' ? $input['client_code'] : null),
                'client_name' => $input['client_name'],
                'client_company' => ($input['client_company'] ?? '') !== '' ? $input['client_company'] : null,
                'client_phone' => ($input['client_phone'] ?? '') !== '' ? $input['client_phone'] : null,
                'client_nif' => ($input['client_nif'] ?? '') !== '' ? $input['client_nif'] : null,
                'client_nie' => ($input['client_nie'] ?? '') !== '' ? $input['client_nie'] : null,
                'notes' => ($input['notes'] ?? '') !== '' ? $input['notes'] : null,
                'base_cents' => array_sum(array_column($lines, 'base_cents')),
                'tax_cents' => array_sum(array_column($lines, 'tax_cents')),
                'total_cents' => array_sum(array_column($lines, 'total_cents')),
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
                        : __('Only a quotation can be edited.'),
                ]);
            }

            $companyId = (int) $user->company_id;
            $lines = $this->pricedLines($companyId, $input['lines'], 'quotation');
            $customer = $this->resolveCustomer($companyId, $input);

            $document->lines()->delete();
            $document->update([
                'customer_id' => $customer?->id,
                'issued_at' => $input['issued_at'],
                'client_code' => $customer?->code ?? (($input['client_code'] ?? '') !== '' ? $input['client_code'] : $document->client_code),
                'client_name' => $input['client_name'],
                'client_company' => ($input['client_company'] ?? '') !== '' ? $input['client_company'] : null,
                'client_phone' => ($input['client_phone'] ?? '') !== '' ? $input['client_phone'] : null,
                'client_nif' => ($input['client_nif'] ?? '') !== '' ? $input['client_nif'] : null,
                'client_nie' => ($input['client_nie'] ?? '') !== '' ? $input['client_nie'] : $document->client_nie,
                'notes' => ($input['notes'] ?? '') !== '' ? $input['notes'] : null,
                'base_cents' => array_sum(array_column($lines, 'base_cents')),
                'tax_cents' => array_sum(array_column($lines, 'tax_cents')),
                'total_cents' => array_sum(array_column($lines, 'total_cents')),
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
                        : __('Only an open quotation can be converted.'),
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
                'notes' => $document->notes,
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
    private function pricedLines(int $companyId, array $rows, string $type): array
    {
        $needed = [];
        foreach ($rows as $row) {
            if (! empty($row['product_id'])) {
                $needed[(int) $row['product_id']] = ($needed[(int) $row['product_id']] ?? 0) + (int) $row['quantity'];
            }
        }

        $direction = SalesDocument::stockDirectionFor($type);

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
        ]);
    }

    private function resolvePaymentMethod(int $companyId, array $input): ?int
    {
        if (($input['payment_status'] ?? '') !== 'paid' || ! SalesDocument::settlesPayment($input['type'])) {
            return null;
        }

        return CompanyPaymentMethod::requireActive($companyId, $input['payment_method_id'] ?? null);
    }
}

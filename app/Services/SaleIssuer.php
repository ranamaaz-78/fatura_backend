<?php

namespace App\Services;

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

            return $document->load('lines');
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

        $products = $needed === []
            ? collect()
            : Product::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('id', array_keys($needed))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        if ($products->count() !== count($needed)) {
            throw ValidationException::withMessages([
                'lines' => __('One of the products is no longer in this catalog.'),
            ]);
        }

        $direction = $type === 'abono' ? 1 : -1;

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

        $priced = [];
        foreach ($rows as $index => $row) {
            $math = SaleMath::line(
                (int) $row['quantity'],
                (int) $row['unit_price'],
                (float) $row['discount_percent'],
                (float) $row['iva_percent'],
            );

            $priced[] = [
                'product_id' => $row['product_id'] ?? null,
                'position' => $index + 1,
                'sr_number' => ($row['sr_number'] ?? '') !== '' ? $row['sr_number'] : null,
                'article' => $row['article'],
                'description' => ($row['description'] ?? '') !== '' ? $row['description'] : null,
                'quantity' => (int) $row['quantity'],
                'unit_price' => (int) $row['unit_price'],
                'discount_percent' => $row['discount_percent'],
                'iva_percent' => $row['iva_percent'],
                'base_cents' => $math['base'],
                'tax_cents' => $math['tax'],
                'total_cents' => $math['total'],
            ];
        }

        foreach ($needed as $productId => $quantity) {
            $product = $products[$productId];
            $product->quantity += $direction * $quantity;
            $product->save();
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
}

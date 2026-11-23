<?php

namespace Tests\Feature\Concerns;

use App\Models\Category;
use App\Models\CompanyPaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\RecargoRate;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\User;

/** Builds one real record of every kind a company's URLs take, through the API where the app makes them that way. */
trait MakesCompanyRecords
{
    /** @return array<string, int|string> One real record of $owner's company for every kind of ID the app's URLs take. */
    protected function records(User $owner): array
    {
        $companyId = $owner->company_id;
        $as = fn () => $this->actingAs($owner, 'sanctum');

        $supplier = Supplier::factory()->create(['company_id' => $companyId]);
        $product = Product::factory()->create(['company_id' => $companyId, 'quantity' => 50]);
        $cash = (int) CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $companyId)->where('name', 'Cash')->value('id');

        $line = fn (int $quantity) => [[
            'product_id' => $product->id,
            'article' => $product->article,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'discount_percent' => 0,
            'iva_percent' => 21,
        ]];
        $document = fn (string $type, int $quantity) => $as()->postJson('/api/app/sales', [
            'type' => $type,
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => 'Counter sale',
            'lines' => $line($quantity),
        ])->assertCreated()->json('data');

        $proforma = $document('proforma', 4);
        $as()->postJson("/api/app/sales/{$proforma['id']}/settle", [
            'payment_method_id' => $cash,
            'lines' => [['line_id' => $proforma['lines'][0]['id'], 'quantity' => 2, 'unit_price' => 1000]],
        ])->assertOk();
        $proforma = $as()->postJson("/api/app/sales/{$proforma['id']}/returns", [
            'lines' => [['line_id' => $proforma['lines'][0]['id'], 'quantity' => 1]],
        ])->assertCreated()->json('data');

        $purchase = $as()->postJson('/api/app/purchases', [
            'supplier_id' => $supplier->id,
            'purchased_on' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 400]],
        ])->assertCreated()->json('data.id');

        $bill = $as()->postJson('/api/app/ledger', [
            'supplier_id' => $supplier->id,
            'bill_date' => now()->toDateString(),
            'description' => 'Lamps',
            'amount_cents' => 100000,
            'paid_cents' => 40000,
            'payment_method_id' => $cash,
        ])->assertCreated()->json('data');

        $this->app['auth']->forgetGuards();

        return [
            'sale' => $document('factura', 1)['id'],
            'proforma' => $proforma['id'],
            'settlement' => $proforma['settlements'][0]['id'],
            'return' => $proforma['returns'][0]['id'],
            'purchase' => $purchase,
            'bill' => $bill['id'],
            'payment' => $bill['payments'][0]['id'],
            'supplier' => $supplier->id,
            'product' => $product->id,
            'customer' => Customer::factory()->create(['company_id' => $companyId])->id,
            'category' => Category::factory()->create(['company_id' => $companyId])->id,
            'companyPaymentMethod' => $cash,
            'productImage' => ProductImage::factory()->create(['company_id' => $companyId])->uuid,
            'taxRate' => (int) TaxRate::withoutGlobalScopes()->where('company_id', $companyId)->value('id'),
            'recargoRate' => (int) RecargoRate::withoutGlobalScopes()->where('company_id', $companyId)->value('id'),
            'user' => User::factory()->create(['role' => 'staff', 'company_id' => $companyId])->id,
        ];
    }

}

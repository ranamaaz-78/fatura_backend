<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Input that is the wrong type, too long or too large is refused with a 422 and a message, never saved as a
 * wrong value and never a 500. (The tests run on SQLite, which does not enforce column lengths, so the lengths
 * here are what keeps MySQL from failing in production.)
 */
class InputValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $company->id]);
        $this->product = Product::factory()->create(['company_id' => $company->id, 'quantity' => 50]);
    }

    private function sale(array $line, array $extra = [])
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', array_merge([
            'type' => 'factura',
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => 'Counter sale',
            'lines' => [array_merge([
                'product_id' => $this->product->id,
                'article' => 'Lamp',
                'quantity' => 1,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ], $line)],
        ], $extra));
    }

    private function import(array $row)
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/products/import', ['rows' => [array_merge([
            'article' => 'Lamp',
            'buying_price' => 100,
            'selling_price' => 200,
            'iva_percent' => 21,
            'quantity' => 1,
            'minimum_stock' => 0,
        ], $row)]]);
    }

    public function test_a_rate_that_is_not_a_number_is_refused_not_saved_as_0_or_1(): void
    {
        foreach (['/api/app/tax-rates', '/api/app/recargo-rates'] as $url) {
            foreach (['abc', ['x'], ''] as $bad) {
                $this->actingAs($this->owner, 'sanctum')->postJson($url, ['name' => 'Bad', 'rate' => $bad])
                    ->assertStatus(422)->assertJsonValidationErrors('rate');
            }

            // A real number is still rounded to two decimals.
            $this->actingAs($this->owner, 'sanctum')->postJson($url, ['name' => 'Odd', 'rate' => '7.456'])
                ->assertCreated()->assertJsonPath('data.rate', fn ($rate) => (float) $rate === 7.46);
        }
    }

    public function test_a_price_too_large_to_add_up_is_refused_instead_of_overflowing(): void
    {
        $this->sale(['unit_price' => PHP_INT_MAX, 'quantity' => 2])->assertStatus(422)->assertJsonValidationErrors('lines.0.unit_price');
        $this->sale(['unit_price' => Pricing::MAX_UNIT_CENTS + 1])->assertStatus(422);
        $this->sale([], ['discount_type' => 'amount', 'discount_value' => 1e30])->assertStatus(422)->assertJsonValidationErrors('discount_value');

        // The largest allowed price on the largest allowed quantity still adds up correctly.
        $this->product->update(['quantity' => 200000]);
        $total = $this->sale(['unit_price' => Pricing::MAX_UNIT_CENTS, 'quantity' => 100000, 'iva_percent' => 0])
            ->assertCreated()->json('data.total_cents');
        $this->assertSame(Pricing::MAX_UNIT_CENTS * 100000, $total);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/products', [
            'article' => 'Big', 'buying_price' => 100, 'selling_price' => Pricing::MAX_UNIT_CENTS + 1, 'iva_percent' => 21,
        ])->assertStatus(422)->assertJsonValidationErrors('selling_price');
    }

    public function test_an_import_row_of_the_wrong_shape_is_reported_not_a_crash(): void
    {
        $this->import(['article' => ['x']])->assertStatus(422)->assertJsonPath('errors.rows.0.messages.0', 'Article is required.');
        $this->import(['iva_percent' => ['x']])->assertStatus(422);
        $this->import(['buying_price' => ['x']])->assertStatus(422);
        $this->assertSame(1, Product::query()->count());
    }

    public function test_an_import_row_too_long_or_too_large_is_reported_not_saved(): void
    {
        $cases = [
            ['article' => str_repeat('a', 256)],
            ['description' => str_repeat('d', 2001)],
            ['brand' => str_repeat('b', 121)],
            ['short_code' => str_repeat('s', 121)],
            ['barcode' => str_repeat('1', 65)],
            ['category' => str_repeat('c', 121)],
            ['selling_price' => '1e30'],
            ['buying_price' => 1, 'selling_price' => Pricing::MAX_UNIT_CENTS + 1],
            ['quantity' => '1e12'],
            ['minimum_stock' => 10_000_000],
        ];

        foreach ($cases as $row) {
            $this->assertSame(422, $this->import($row)->status(), 'Accepted: '.json_encode(array_map(fn ($v) => is_string($v) ? strlen($v).' chars' : $v, $row)));
        }

        $this->assertSame(1, Product::query()->count(), 'Nothing from a refused import was saved.');

        // At the limits, it goes in.
        $this->import([
            'article' => str_repeat('a', 255),
            'brand' => str_repeat('b', 120),
            'barcode' => '12345678',
            'buying_price' => 1,
            'selling_price' => Pricing::MAX_UNIT_CENTS,
            'quantity' => 9_999_999,
        ])->assertCreated();
    }

    public function test_the_admin_whatsapp_message_is_validated(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $application = \App\Models\Application::factory()->create(['whatsapp' => '+34600111222']);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/whatsapp-link", ['message' => str_repeat('m', 2001)])
            ->assertStatus(422)->assertJsonValidationErrors('message');
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/whatsapp-link", ['message' => ['x']])
            ->assertStatus(422);
    }
}

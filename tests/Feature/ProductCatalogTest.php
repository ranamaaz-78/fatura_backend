<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    public function test_a_company_only_sees_its_own_products(): void
    {
        Product::factory()->create(['company_id' => $this->company->id, 'article' => 'Mine']);
        Product::factory()->create(['company_id' => Company::factory()->create()->id, 'article' => 'Theirs']);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/products')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.article', 'Mine')
            ->assertJsonPath('data.counts.all', 1);
    }

    public function test_creating_a_product_generates_a_barcode_and_computes_the_selling_price(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products', [
                'article' => 'Cable USB-C 2m',
                'buying_price' => 1000,
                'margin_percent' => 30,
                'iva_percent' => 21,
                'quantity' => 12,
                'minimum_stock' => 4,
            ])
            ->assertCreated();

        // 1000 x 1.30 x 1.21 = 1573 cents.
        $response->assertJsonPath('data.selling_price', 1573)
            ->assertJsonPath('data.barcode_generated', true);

        $barcode = $response->json('data.barcode');
        $this->assertSame(13, strlen($barcode));
        $this->assertStringStartsWith('200', $barcode);
    }

    public function test_a_supplied_barcode_is_kept_and_must_be_unique_per_company(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products', [
                'article' => 'Keyboard',
                'barcode' => '8412345678905',
                'buying_price' => 2000,
                'margin_percent' => 25,
                'iva_percent' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('data.barcode', '8412345678905')
            ->assertJsonPath('data.barcode_generated', false);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products', [
                'article' => 'Keyboard copy',
                'barcode' => '8412345678905',
                'buying_price' => 2000,
                'margin_percent' => 25,
                'iva_percent' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('barcode');
    }

    public function test_updating_prices_recomputes_the_selling_price(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'buying_price' => 1000,
            'margin_percent' => 0,
            'iva_percent' => 0,
            'selling_price' => 1000,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/products/{$product->id}", ['margin_percent' => 50, 'iva_percent' => 4])
            ->assertOk()
            ->assertJsonPath('data.selling_price', 1560);
    }

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $category = Category::factory()->create(['company_id' => $this->company->id]);
        Product::factory()->create(['company_id' => $this->company->id, 'category_id' => $category->id]);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/categories/{$category->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_import_creates_missing_categories_and_attaches_them(): void
    {
        Category::factory()->create(['company_id' => $this->company->id, 'name' => 'Cables']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products/import', [
                'rows' => [
                    [
                        'article' => 'Cable HDMI',
                        'category' => 'cables',
                        'buying_price' => 1000,
                        'margin_percent' => 30,
                        'iva_percent' => 21,
                        'selling_price' => 1573,
                    ],
                    [
                        'article' => 'Wireless mouse',
                        'category' => 'Peripherals',
                        'buying_price' => 2000,
                        'margin_percent' => 25,
                        'iva_percent' => 10,
                        'selling_price' => 2750,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.created', 2);

        $this->assertSame(2, Category::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        $hdmi = Product::withoutGlobalScopes()->where('article', 'Cable HDMI')->firstOrFail();
        $this->assertSame('Cables', $hdmi->category->name);
        $this->assertTrue($hdmi->barcode_generated);

        $mouse = Product::withoutGlobalScopes()->where('article', 'Wireless mouse')->firstOrFail();
        $this->assertSame('Peripherals', $mouse->category->name);
    }

    public function test_import_saves_nothing_when_a_row_has_a_wrong_selling_price(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products/import', [
                'rows' => [
                    [
                        'article' => 'Good row',
                        'buying_price' => 1000,
                        'margin_percent' => 30,
                        'iva_percent' => 21,
                        'selling_price' => 1573,
                    ],
                    [
                        'article' => 'Bad row',
                        'buying_price' => 1000,
                        'margin_percent' => 30,
                        'iva_percent' => 21,
                        'selling_price' => 9999,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.rows.0.row', 1);

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_import_fills_a_blank_selling_price_from_the_formula(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products/import', [
                'rows' => [
                    [
                        'article' => 'No price given',
                        'buying_price' => 1000,
                        'margin_percent' => 30,
                        'iva_percent' => 21,
                        'selling_price' => null,
                    ],
                ],
            ])
            ->assertCreated();

        $this->assertSame(1573, Product::withoutGlobalScopes()->firstOrFail()->selling_price);
    }

    public function test_stock_filters_and_counts(): void
    {
        Product::factory()->lowStock()->create(['company_id' => $this->company->id]);
        Product::factory()->outOfStock()->create(['company_id' => $this->company->id]);
        Product::factory()->generatedBarcode()->create(['company_id' => $this->company->id, 'quantity' => 50, 'minimum_stock' => 1]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/products?stock=low')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.counts.low', 1)
            ->assertJsonPath('data.counts.out', 1)
            ->assertJsonPath('data.counts.no_barcode', 1);
    }
}

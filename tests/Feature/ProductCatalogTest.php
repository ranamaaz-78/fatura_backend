<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Supplier;
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

    public function test_creating_a_product_keeps_the_typed_selling_price(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products', [
                'article' => 'Cable USB-C 2m',
                'buying_price' => 1000,
                'selling_price' => 1500,
                'iva_percent' => 21,
                'quantity' => 12,
                'minimum_stock' => 4,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.selling_price', 1500)
            ->assertJsonPath('data.margin_percent', 50)
            ->assertJsonPath('data.iva_percent', 21)
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
                'selling_price' => 2500,
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
                'selling_price' => 2500,
                'iva_percent' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('barcode');
    }

    public function test_selling_price_must_be_higher_than_the_buying_price(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products', [
                'article' => 'Cable',
                'buying_price' => 1000,
                'selling_price' => 1000,
                'iva_percent' => 21,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('selling_price');
    }

    public function test_an_iva_rate_outside_settings_is_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products', [
                'article' => 'Cable',
                'buying_price' => 1000,
                'selling_price' => 1500,
                'iva_percent' => 15,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('iva_percent');
    }

    public function test_updating_the_selling_price_stores_it_and_the_margin(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'buying_price' => 1000,
            'selling_price' => 1200,
            'iva_percent' => 21,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/products/{$product->id}", ['selling_price' => 1800])
            ->assertOk()
            ->assertJsonPath('data.selling_price', 1800)
            ->assertJsonPath('data.margin_percent', 80);
    }

    public function test_a_new_product_has_no_last_buying_price(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products', [
                'article' => 'Cable',
                'buying_price' => 1000,
                'selling_price' => 1500,
                'iva_percent' => 21,
                'quantity' => 1,
                'minimum_stock' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.buying_price', 1000)
            ->assertJsonPath('data.last_buying_price', null);
    }

    public function test_changing_the_buying_price_keeps_the_previous_one_as_the_last_buying_price(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'buying_price' => 1000,
            'selling_price' => 2000,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/products/{$product->id}", ['buying_price' => 1200])
            ->assertOk()
            ->assertJsonPath('data.buying_price', 1200)
            ->assertJsonPath('data.last_buying_price', 1000);

        // Another change moves the price it replaces, not the very first one.
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/products/{$product->id}", ['buying_price' => 1350])
            ->assertOk()
            ->assertJsonPath('data.buying_price', 1350)
            ->assertJsonPath('data.last_buying_price', 1200);
    }

    public function test_saving_without_changing_the_buying_price_leaves_the_last_one_alone(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'buying_price' => 1000,
            'selling_price' => 2000,
        ]);
        $product->update(['buying_price' => 1100]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/products/{$product->id}", ['selling_price' => 2500, 'buying_price' => 1100])
            ->assertOk()
            ->assertJsonPath('data.buying_price', 1100)
            ->assertJsonPath('data.last_buying_price', 1000);
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
                        'iva_percent' => 21,
                        'selling_price' => 1300,
                    ],
                    [
                        'article' => 'Wireless mouse',
                        'category' => 'Peripherals',
                        'buying_price' => 2000,
                        'iva_percent' => 10,
                        'selling_price' => 2500,
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

    public function test_import_saves_nothing_when_a_selling_price_is_not_above_cost(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products/import', [
                'rows' => [
                    [
                        'article' => 'Good row',
                        'buying_price' => 1000,
                        'iva_percent' => 21,
                        'selling_price' => 1300,
                    ],
                    [
                        'article' => 'Bad row',
                        'buying_price' => 1000,
                        'iva_percent' => 21,
                        'selling_price' => 1000,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.rows.0.row', 1);

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_import_rejects_a_blank_selling_price(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products/import', [
                'rows' => [
                    [
                        'article' => 'No price given',
                        'buying_price' => 1000,
                        'iva_percent' => 21,
                        'selling_price' => null,
                    ],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
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

    public function test_creating_a_product_can_attach_a_supplier_from_the_same_company(): void
    {
        $supplier = Supplier::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Acme Parts',
        ]);
        $foreign = Supplier::factory()->create(['name' => 'Other Co']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products', [
                'article' => 'Cable USB-C 2m',
                'buying_price' => 1000,
                'selling_price' => 1500,
                'iva_percent' => 21,
                'supplier_id' => $supplier->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.supplier_id', $supplier->id)
            ->assertJsonPath('data.supplier', 'Acme Parts');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products', [
                'article' => 'Stolen attach',
                'buying_price' => 1000,
                'selling_price' => 1500,
                'iva_percent' => 21,
                'supplier_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');
    }

    public function test_import_attaches_an_existing_supplier_and_rejects_unknown_names(): void
    {
        $supplier = Supplier::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Acme Parts',
            'company_name' => 'Acme SL',
            'code' => 'S-0001',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products/import', [
                'rows' => [
                    [
                        'article' => 'Cable HDMI',
                        'supplier' => 'acme parts',
                        'buying_price' => 1000,
                        'iva_percent' => 21,
                        'selling_price' => 1300,
                    ],
                    [
                        'article' => 'HDMI adapter',
                        'supplier' => 'S-0001',
                        'buying_price' => 800,
                        'iva_percent' => 21,
                        'selling_price' => 1100,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.created', 2);

        $this->assertSame(1, Supplier::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(
            $supplier->id,
            Product::withoutGlobalScopes()->where('article', 'Cable HDMI')->value('supplier_id'),
        );
        $this->assertSame(
            $supplier->id,
            Product::withoutGlobalScopes()->where('article', 'HDMI adapter')->value('supplier_id'),
        );

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/products/import', [
                'rows' => [
                    [
                        'article' => 'Unknown vendor cable',
                        'supplier' => 'Ghost Ltd',
                        'buying_price' => 1000,
                        'iva_percent' => 21,
                        'selling_price' => 1300,
                    ],
                ],
            ])
            ->assertStatus(422);

        $this->assertNull(
            Product::withoutGlobalScopes()->where('article', 'Unknown vendor cable')->first(),
        );
        $this->assertSame(1, Supplier::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCompanyProductImportTest extends TestCase
{
    use RefreshDatabase;

    private function rows(): array
    {
        return [
            ['article' => 'Cable HDMI', 'category' => 'Cables', 'buying_price' => 1000, 'iva_percent' => 21, 'selling_price' => 1300],
            ['article' => 'Wireless mouse', 'buying_price' => 2000, 'iva_percent' => 21, 'selling_price' => 2500],
        ];
    }

    public function test_the_super_admin_imports_products_into_a_company(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $other = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/companies/{$company->id}/products/import", ['rows' => $this->rows()])
            ->assertCreated()
            ->assertJsonPath('data.created', 2);

        $this->assertSame(2, Product::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(0, Product::withoutGlobalScopes()->where('company_id', $other->id)->count());
        $this->assertTrue(Product::withoutGlobalScopes()->where('article', 'Cable HDMI')->firstOrFail()->barcode_generated);
    }

    public function test_a_bad_row_stops_the_whole_import(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $rows = $this->rows();
        $rows[1]['selling_price'] = 1500; // below the buying price

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/companies/{$company->id}/products/import", ['rows' => $rows])
            ->assertStatus(422)
            ->assertJsonPath('errors.rows.0.row', 1);

        $this->assertSame(0, Product::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_a_company_owner_cannot_use_the_admin_import(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $owner = User::factory()->businessAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/admin/companies/{$company->id}/products/import", ['rows' => $this->rows()])
            ->assertForbidden();

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }
}

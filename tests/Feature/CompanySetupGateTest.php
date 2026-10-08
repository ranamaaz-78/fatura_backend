<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySetupGateTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Company $company, string $role = 'businessAdmin'): User
    {
        Subscription::factory()->create(['company_id' => $company->id]);

        $factory = User::factory()->businessAdmin();

        return $role === 'staff'
            ? $factory->create(['company_id' => $company->id, 'role' => 'staff'])
            : $factory->create(['company_id' => $company->id]);
    }

    public function test_a_company_still_in_setup_cannot_open_the_workspace(): void
    {
        $owner = $this->owner(Company::factory()->incomplete()->create());

        foreach (['/api/app/dashboard', '/api/app/products', '/api/app/sales', '/api/app/customers'] as $path) {
            $this->actingAs($owner, 'sanctum')
                ->getJson($path)
                ->assertStatus(403)
                ->assertJsonPath('code', 'COMPANY_SETUP_REQUIRED')
                ->assertJsonPath('data.missing_fields', ['tax_id', 'address', 'postal_code']);
        }
    }

    public function test_the_setup_screens_stay_open_and_who_you_are_still_loads(): void
    {
        $owner = $this->owner(Company::factory()->incomplete()->create());

        $this->actingAs($owner, 'sanctum')->getJson('/api/app/company')->assertOk()
            ->assertJsonPath('data.profile_complete', false);
        $this->actingAs($owner, 'sanctum')->getJson('/api/app/me')->assertOk()
            ->assertJsonPath('data.company.profile_complete', false);
    }

    public function test_finishing_setup_opens_the_workspace(): void
    {
        $company = Company::factory()->incomplete()->create();
        $owner = $this->owner($company);

        $this->actingAs($owner, 'sanctum')->getJson('/api/app/dashboard')->assertStatus(403);

        // No logo is uploaded: it is optional.
        $owner->refresh();

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/app/company', [
                'name' => 'northwind trading',
                'email' => 'hello@northwind.test',
                'tax_id' => 'B87654321',
                'phone' => '+34911222333',
                'whatsapp' => '+34600111222',
                'address' => 'calle mayor 5',
                'city' => 'madrid',
                'postal_code' => '28013',
                'country' => 'spain',
                'currency' => 'EUR',
            ])
            ->assertOk()
            ->assertJsonPath('data.profile_complete', true);

        $this->actingAs($owner, 'sanctum')->getJson('/api/app/dashboard')->assertOk();
    }

    public function test_staff_are_held_back_too_until_the_owner_has_finished(): void
    {
        $staff = $this->owner(Company::factory()->incomplete()->create(), 'staff');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_SETUP_REQUIRED');
    }

    public function test_a_complete_company_is_not_held_back(): void
    {
        $owner = $this->owner(Company::factory()->create());

        $this->actingAs($owner, 'sanctum')->getJson('/api/app/dashboard')->assertOk();
    }
}

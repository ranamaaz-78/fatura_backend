<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create([
            'name' => 'Old Co',
            'email' => 'old@example.com',
            'currency' => 'USD',
        ]);
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    public function test_the_owner_can_read_and_update_company_details(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/company')
            ->assertOk()
            ->assertJsonPath('data.name', 'Old Co')
            ->assertJsonPath('data.currency', 'USD');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', [
                'name' => 'techo sphere',
                'email' => 'hello@techo.test',
                'phone' => '+923001112233',
                'whatsapp' => '+923001112233',
                'address' => 'Shop 4, Market Road',
                'city' => 'Gujranwala',
                'country' => 'Pakistan',
                'currency' => 'pkr',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'techo sphere')
            ->assertJsonPath('data.email', 'hello@techo.test')
            ->assertJsonPath('data.city', 'Gujranwala')
            ->assertJsonPath('data.currency', 'PKR');

        $this->assertSame('techo sphere', $this->company->fresh()->name);
        $this->assertSame('PKR', $this->company->fresh()->currency);
    }

    public function test_an_unknown_currency_is_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', [
                'name' => 'Old Co',
                'email' => 'old@example.com',
                'currency' => 'XXX',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');
    }

    public function test_another_company_cannot_change_these_details(): void
    {
        $other = Company::factory()->create(['name' => 'Other']);
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson('/api/app/company', [
                'name' => 'Hijacked',
                'email' => 'x@other.test',
                'currency' => 'EUR',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Hijacked');

        $this->assertSame('Old Co', $this->company->fresh()->name);
        $this->assertSame('Hijacked', $other->fresh()->name);
    }

    public function test_slug_and_status_cannot_be_changed_from_settings(): void
    {
        $slug = $this->company->slug;

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', [
                'name' => 'Still us',
                'email' => 'old@example.com',
                'currency' => 'USD',
                'slug' => 'stolen',
                'status' => 'suspended',
            ])
            ->assertOk();

        $fresh = $this->company->fresh();
        $this->assertSame($slug, $fresh->slug);
        $this->assertSame('active', $fresh->status->value);
        $this->assertSame('Still us', $fresh->name);
    }
}

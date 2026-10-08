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
            'logo_path' => 'company-logos/1/logo.png',
        ]);
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Techo Sphere',
            'email' => 'hello@techo.test',
            'tax_id' => 'B12345678',
            'phone' => '+923001112233',
            'whatsapp' => '+923001112233',
            'address' => 'Shop 4, Market Road',
            'city' => 'Gujranwala',
            'postal_code' => '52250',
            'country' => 'Pakistan',
            'currency' => 'PKR',
        ], $overrides);
    }

    public function test_the_owner_can_read_and_update_company_details(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/company')
            ->assertOk()
            ->assertJsonPath('data.name', 'Old Co')
            ->assertJsonPath('data.currency', 'USD');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', $this->details(['currency' => 'pkr']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Techo Sphere')
            ->assertJsonPath('data.email', 'hello@techo.test')
            ->assertJsonPath('data.tax_id', 'B12345678')
            ->assertJsonPath('data.postal_code', '52250')
            ->assertJsonPath('data.city', 'Gujranwala')
            ->assertJsonPath('data.currency', 'PKR')
            ->assertJsonPath('data.profile_complete', true)
            ->assertJsonPath('data.missing_fields', []);

        $this->assertSame('PKR', $this->company->fresh()->currency);
    }

    public function test_every_detail_is_compulsory(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', [
                'name' => 'Old Co',
                'email' => 'old@example.com',
                'currency' => 'USD',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tax_id', 'phone', 'whatsapp', 'address', 'city', 'postal_code', 'country'])
            ->assertJsonMissingValidationErrors(['name', 'email', 'currency']);
    }

    public function test_the_details_can_be_saved_without_a_logo(): void
    {
        $this->company->forceFill(['logo_path' => null])->save();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', $this->details())
            ->assertOk()
            ->assertJsonPath('data.profile_complete', true);

        $this->assertNotSame('Old Co', $this->company->fresh()->name);
    }

    public function test_names_and_places_are_saved_in_proper_case(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', $this->details([
                'name' => '  TALLER de MARTA   s.l. ',
                'address' => 'CALLE mayor 5b',
                'city' => 'ALCOBENDAS',
                'country' => 'spain',
                'email' => 'Hello@Techo.TEST',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Taller de Marta S.L.')
            ->assertJsonPath('data.address', 'Calle Mayor 5B')
            ->assertJsonPath('data.city', 'Alcobendas')
            ->assertJsonPath('data.country', 'Spain')
            ->assertJsonPath('data.email', 'hello@techo.test');
    }

    public function test_one_tax_id_field_takes_a_nif_nie_or_cif_and_stores_it_tidy(): void
    {
        foreach (['b-1234 5678' => 'B12345678', 'x1234567l' => 'X1234567L', '12345678z' => '12345678Z'] as $typed => $stored) {
            $this->actingAs($this->owner, 'sanctum')
                ->patchJson('/api/app/company', $this->details(['tax_id' => $typed]))
                ->assertOk()
                ->assertJsonPath('data.tax_id', $stored);
        }

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', $this->details(['tax_id' => '!!']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax_id');
    }

    public function test_the_postal_code_is_checked_and_tidied(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', $this->details(['postal_code' => ' sw1a  1aa ']))
            ->assertOk()
            ->assertJsonPath('data.postal_code', 'SW1A 1AA');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', $this->details(['postal_code' => '###']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('postal_code');
    }

    public function test_a_company_that_is_missing_details_says_which(): void
    {
        $this->company->forceFill(['tax_id' => null, 'address' => null, 'postal_code' => null, 'logo_path' => null])->save();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/company')
            ->assertOk()
            ->assertJsonPath('data.profile_complete', false)
            ->assertJsonPath('data.missing_fields', ['tax_id', 'address', 'postal_code']);
    }

    public function test_an_unknown_currency_is_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', $this->details(['currency' => 'XXX']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');
    }

    public function test_another_company_cannot_change_these_details(): void
    {
        $other = Company::factory()->create(['name' => 'Other', 'logo_path' => 'company-logos/2/logo.png']);
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson('/api/app/company', $this->details(['name' => 'Hijacked']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Hijacked');

        $this->assertSame('Old Co', $this->company->fresh()->name);
        $this->assertSame('Hijacked', $other->fresh()->name);
    }

    public function test_slug_and_status_cannot_be_changed_from_settings(): void
    {
        $slug = $this->company->slug;

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company', $this->details(['name' => 'Still Us', 'slug' => 'stolen', 'status' => 'suspended']))
            ->assertOk();

        $fresh = $this->company->fresh();
        $this->assertSame($slug, $fresh->slug);
        $this->assertSame('active', $fresh->status->value);
        $this->assertSame('Still Us', $fresh->name);
    }
}

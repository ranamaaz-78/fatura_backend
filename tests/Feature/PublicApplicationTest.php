<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicApplicationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Northwind Trading',
            'contact_name' => 'Grace Hopper',
            'email' => 'grace@northwind.test',
            'phone' => '+923001234567',
            'city' => 'Lahore',
            'country' => 'Pakistan',
            'team_size' => '2-5',
        ], $overrides);
    }

    public function test_public_plans_only_returns_active_plans(): void
    {
        Plan::factory()->create(['name' => 'Starter', 'slug' => 'starter']);
        Plan::factory()->inactive()->create(['name' => 'Retired', 'slug' => 'retired']);

        $response = $this->getJson('/api/public/plans');

        $response->assertOk();
        $this->assertSame(['Starter'], array_column($response->json('data'), 'name'));
    }

    public function test_it_stores_an_application(): void
    {
        $plan = Plan::factory()->create(['slug' => 'starter']);

        $response = $this->postJson('/api/public/applications', $this->payload(['plan_slug' => 'starter']));

        $response->assertCreated()->assertJsonPath('success', true);

        $application = Application::sole();
        $this->assertSame('grace@northwind.test', $application->email);
        $this->assertSame(ApplicationStatus::New, $application->status);
        $this->assertSame($plan->id, $application->plan_id);
        $this->assertSame('website', $application->source);
        $this->assertDatabaseCount('application_activities', 1);
    }

    public function test_it_validates_required_fields(): void
    {
        $this->postJson('/api/public/applications', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name', 'contact_name', 'email', 'phone']);
    }

    public function test_a_filled_honeypot_looks_successful_but_stores_nothing(): void
    {
        $this->postJson('/api/public/applications', $this->payload(['website' => 'http://spam.test']))
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_a_duplicate_email_within_24_hours_is_swallowed(): void
    {
        Application::factory()->pending()->create([
            'email' => 'grace@northwind.test',
            'created_at' => now()->subHour(),
        ]);

        $this->postJson('/api/public/applications', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('applications', 1);
    }

    public function test_the_same_email_is_accepted_again_after_24_hours(): void
    {
        Application::factory()->pending()->create([
            'email' => 'grace@northwind.test',
            'created_at' => now()->subDays(2),
        ]);

        $this->postJson('/api/public/applications', $this->payload())->assertCreated();

        $this->assertDatabaseCount('applications', 2);
    }

    public function test_it_throttles_after_five_submissions_a_minute(): void
    {
        RateLimiter::clear('');

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/public/applications', $this->payload([
                'email' => "lead{$i}@northwind.test",
            ]))->assertCreated();
        }

        $this->postJson('/api/public/applications', $this->payload(['email' => 'lead6@northwind.test']))
            ->assertStatus(429);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCompanyListTest extends TestCase
{
    use RefreshDatabase;

    private function seedCompanies(): array
    {
        $healthy = Company::factory()->create(['name' => 'Healthy']);
        Subscription::factory()->create(['company_id' => $healthy->id, 'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(25)]);

        $soon = Company::factory()->create(['name' => 'Soon']);
        Subscription::factory()->create(['company_id' => $soon->id, 'starts_at' => now()->subDays(26), 'ends_at' => now()->addDays(4)]);

        $lapsed = Company::factory()->create(['name' => 'Lapsed']);
        Subscription::factory()->expired()->create(['company_id' => $lapsed->id]);

        $bare = Company::factory()->create(['name' => 'Bare']);

        $paused = Company::factory()->create(['name' => 'Paused', 'status' => CompanyStatus::Suspended]);
        Subscription::factory()->create(['company_id' => $paused->id, 'ends_at' => now()->addDays(20)]);

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        return compact('healthy', 'soon', 'lapsed', 'bare', 'paused');
    }

    private function names(string $query): array
    {
        return collect($this->getJson('/api/admin/companies?'.$query)->assertOk()->json('data.items'))
            ->pluck('name')->sort()->values()->all();
    }

    public function test_counts_cover_every_subscription_state(): void
    {
        $this->seedCompanies();

        $this->getJson('/api/admin/companies')
            ->assertOk()
            ->assertJsonPath('data.counts', ['all' => 5, 'active' => 3, 'expiring' => 1, 'expired' => 1, 'none' => 1, 'suspended' => 1]);
    }

    public function test_filters_by_subscription_state(): void
    {
        $this->seedCompanies();

        $this->assertSame(['Healthy', 'Paused', 'Soon'], $this->names('subscription=active'));
        $this->assertSame(['Soon'], $this->names('subscription=expiring'));
        $this->assertSame(['Lapsed'], $this->names('subscription=expired'));
        $this->assertSame(['Bare'], $this->names('subscription=none'));
        $this->assertSame(['Paused'], $this->names('status=suspended'));
    }

    public function test_each_company_reports_its_subscription_state(): void
    {
        $this->seedCompanies();

        $states = collect($this->getJson('/api/admin/companies')->json('data.items'))->pluck('subscription_state', 'name')->all();

        ksort($states);

        $this->assertSame(
            ['Bare' => 'none', 'Healthy' => 'active', 'Lapsed' => 'expired', 'Paused' => 'active', 'Soon' => 'expiring'],
            $states,
        );
    }

    public function test_rejects_an_unknown_subscription_filter(): void
    {
        $this->seedCompanies();

        $this->getJson('/api/admin/companies?subscription=weird')->assertStatus(422);
    }
}

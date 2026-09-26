<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Company $company): User
    {
        return User::factory()->businessAdmin()->create(['company_id' => $company->id]);
    }

    public function test_guests_cannot_reach_admin_or_app_endpoints(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        $this->getJson('/api/app/dashboard')->assertUnauthorized();
    }

    public function test_a_business_admin_cannot_reach_the_admin_area(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);

        $this->actingAs($this->owner($company), 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_a_super_admin_cannot_reach_the_tenant_area(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_an_active_subscription_opens_the_tenant_dashboard(): void
    {
        $company = Company::factory()->create(['name' => 'Northwind Trading']);
        Subscription::factory()->create(['company_id' => $company->id]);

        $this->actingAs($this->owner($company), 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertOk()
            ->assertJsonPath('data.company.name', 'Northwind Trading')
            ->assertJsonPath('data.kpis.outstanding', 0);
    }

    public function test_an_expired_subscription_returns_402_with_support_contacts(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->expired()->create(['company_id' => $company->id]);

        $this->actingAs($this->owner($company), 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertStatus(402)
            ->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED')
            ->assertJsonPath('support.email', config('fatura.support.email'))
            ->assertJsonPath('support.whatsapp', config('fatura.support.whatsapp'));
    }

    public function test_grace_days_keep_a_just_lapsed_subscription_usable(): void
    {
        config(['fatura.subscriptions.grace_days' => 3]);

        $company = Company::factory()->create();
        Subscription::factory()->create([
            'company_id' => $company->id,
            'ends_at' => now()->subDay(),
        ]);

        $this->actingAs($this->owner($company), 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertOk();
    }

    public function test_a_cancelled_subscription_blocks_the_dashboard(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->cancelled()->create(['company_id' => $company->id]);

        $this->actingAs($this->owner($company), 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertStatus(402)
            ->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');
    }

    public function test_me_and_subscription_stay_reachable_while_expired(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->expired()->create(['company_id' => $company->id]);
        $owner = $this->owner($company);

        $this->actingAs($owner, 'sanctum')->getJson('/api/app/me')->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/app/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription.days_left', 0);
    }

    public function test_a_tenant_only_sees_its_own_subscriptions(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $mine->id]);
        Subscription::factory()->create(['company_id' => $theirs->id]);

        $this->actingAs($this->owner($mine), 'sanctum');

        $this->assertSame(1, Subscription::count());
        $this->assertSame(2, Subscription::withoutGlobalScopes()->count());
    }
}

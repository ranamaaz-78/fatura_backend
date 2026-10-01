<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_trends_attention_list_and_recent_payments(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $soon = Company::factory()->create(['name' => 'Soon Traders']);
        Subscription::factory()->create(['company_id' => $soon->id, 'plan_name' => 'Starter', 'ends_at' => now()->addDays(3)]);

        $lapsed = Company::factory()->create(['name' => 'Lapsed Co']);
        Subscription::factory()->expired()->create(['company_id' => $lapsed->id, 'plan_name' => 'Starter']);

        $healthy = Company::factory()->create(['name' => 'Healthy']);
        Subscription::factory()->create(['company_id' => $healthy->id, 'plan_name' => 'Pro', 'ends_at' => now()->addDays(40)]);

        Payment::create(['company_id' => $healthy->id, 'amount' => 100, 'currency' => 'USD', 'status' => PaymentStatus::Paid, 'paid_at' => now()]);
        Payment::create(['company_id' => $healthy->id, 'amount' => 50, 'currency' => 'USD', 'status' => PaymentStatus::Paid, 'paid_at' => now()->subMonthNoOverflow()->startOfMonth()->addDay()]);

        Application::factory()->count(2)->create(['created_at' => now()->subDay()]);

        $data = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');

        $this->assertCount(6, $data['revenue']['trend']);
        $this->assertEquals(100, $data['revenue']['trend'][5]['amount']);
        $this->assertEquals(50, $data['revenue']['trend'][4]['amount']);
        $this->assertEquals(50, $data['revenue']['last_month']);

        $this->assertCount(14, $data['application_trend']);
        $this->assertSame(2, collect($data['application_trend'])->sum('count'));
        $this->assertSame(['total' => 2, 'converted' => 0], $data['conversion']);

        $this->assertSame(['Soon Traders'], collect($data['needs_attention']['expiring'])->pluck('company_name')->all());
        $this->assertSame(['Lapsed Co'], collect($data['needs_attention']['expired'])->pluck('company_name')->all());

        $this->assertSame(['Pro', 'Starter'], collect($data['plan_mix'])->pluck('name')->sort()->values()->all());
        $this->assertCount(2, $data['latest_payments']);
        $this->assertSame('Healthy', $data['latest_payments'][0]['company_name']);
    }
}

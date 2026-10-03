<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CompanyStatus;
use App\Enums\NotificationChannel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Mail\AccountReadyMail;
use App\Models\Application;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConvertApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Plan $plan, array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Northwind Trading',
            'company_email' => 'billing@northwind.test',
            'company_phone' => '+923001234567',
            'city' => 'Lahore',
            'country' => 'Pakistan',
            'owner_name' => 'Grace Hopper',
            'owner_email' => 'grace@northwind.test',
            'owner_whatsapp' => '+923001234567',
            'plan_id' => $plan->id,
            'periods' => 1,
        ], $overrides);
    }

    public function test_it_creates_a_company_owner_subscription_and_payment(): void
    {
        Mail::fake();

        $plan = Plan::factory()->create(['name' => 'Starter', 'price' => 20, 'currency' => 'USD']);
        $method = PaymentMethod::factory()->create(['name' => 'Bank transfer']);
        $application = Application::factory()->pending()->create();
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/convert",
            $this->payload($plan, [
                'payment_method_id' => $method->id,
                'payment_reference' => 'TRX-001',
            ]),
        );

        $response->assertCreated()->assertJsonPath('success', true);

        $company = Company::sole();
        $this->assertSame('Northwind Trading', $company->name);
        $this->assertSame('northwind-trading', $company->slug);
        $this->assertSame(CompanyStatus::Active, $company->status);

        $owner = User::where('email', 'grace@northwind.test')->sole();
        $this->assertNull($owner->password, 'The owner must set their own password from the invite link.');
        $this->assertSame(UserRole::BusinessAdmin, $owner->role);
        $this->assertSame($company->id, $owner->company_id);

        $this->assertDatabaseHas('payments', [
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'TRX-001',
            'amount' => '20.00',
            'recorded_by' => $admin->id,
        ]);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Approved, $application->status);
        $this->assertSame($company->id, $application->converted_company_id);
        $this->assertNotNull($application->converted_at);
    }

    public function test_the_subscription_snapshots_the_plan_at_conversion_time(): void
    {
        Mail::fake();

        $plan = Plan::factory()->create([
            'name' => 'Starter',
            'price' => 20,
            'currency' => 'USD',
            'features' => ['Unlimited invoices'],
        ]);
        $application = Application::factory()->pending()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/applications/{$application->id}/convert", $this->payload($plan))
            ->assertCreated();

        $plan->update(['name' => 'Starter v2', 'price' => 99, 'features' => ['Everything']]);

        $subscription = Subscription::withoutGlobalScopes()->sole();
        $this->assertSame('Starter', $subscription->plan_name);
        $this->assertSame('20.00', $subscription->plan_price);
        $this->assertSame('USD', $subscription->plan_currency);
        $this->assertSame(['Unlimited invoices'], $subscription->plan_features);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    public function test_month_end_start_dates_do_not_roll_into_the_next_month(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-01-31 09:00:00');

        $plan = Plan::factory()->create(['interval' => PlanInterval::Month]);
        $application = Application::factory()->pending()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/applications/{$application->id}/convert", $this->payload($plan, [
                'starts_at' => '2026-01-31',
            ]))
            ->assertCreated();

        $subscription = Subscription::withoutGlobalScopes()->sole();
        $this->assertSame('2026-01-31', $subscription->starts_at->toDateString());
        $this->assertSame('2026-02-28', $subscription->ends_at->toDateString());

        Carbon::setTestNow();
    }

    public function test_multiple_yearly_periods_extend_the_end_date(): void
    {
        Mail::fake();

        $plan = Plan::factory()->yearly()->create(['price' => 200]);
        $application = Application::factory()->pending()->create();
        $method = PaymentMethod::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/applications/{$application->id}/convert", $this->payload($plan, [
                'starts_at' => '2026-03-01',
                'periods' => 2,
                'payment_method_id' => $method->id,
            ]))
            ->assertCreated();

        $subscription = Subscription::withoutGlobalScopes()->sole();
        $this->assertSame('2028-03-01', $subscription->ends_at->toDateString());
        $this->assertDatabaseHas('payments', ['amount' => '400.00']);
    }

    public function test_it_sends_the_account_ready_mail_at_once_and_logs_both_channels(): void
    {
        Mail::fake();

        $plan = Plan::factory()->create();
        $application = Application::factory()->pending()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/convert",
            $this->payload($plan),
        );

        $response->assertCreated();

        Mail::assertSent(AccountReadyMail::class, fn (AccountReadyMail $mail) => $mail->hasTo('grace@northwind.test'));
        Mail::assertNothingQueued();
        $response->assertJsonPath('data.email_sent', true);

        $this->assertSame(
            'http://localhost:5173',
            config('fatura.frontend_url'),
            'The invite link is built from the configured frontend URL.',
        );

        $whatsappUrl = $response->json('data.whatsapp_url');
        $this->assertStringStartsWith('https://wa.me/', $whatsappUrl);
        $this->assertStringContainsString('set-password', urldecode($whatsappUrl));

        $this->assertSame(2, NotificationLog::count());
        $this->assertTrue(NotificationLog::where('channel', NotificationChannel::Email)->where('status', 'sent')->exists());
        $this->assertTrue(NotificationLog::where('channel', NotificationChannel::WhatsApp)->exists());
        $this->assertDatabaseHas('invite_tokens', ['email' => 'grace@northwind.test']);
    }

    public function test_a_mail_failure_still_creates_the_account_and_tells_the_admin(): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('locale')->andReturnSelf();
        Mail::shouldReceive('sendNow')->andThrow(new \RuntimeException('smtp down'));

        $plan = Plan::factory()->create();
        $application = Application::factory()->pending()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/convert",
            $this->payload($plan),
        );

        $response->assertCreated()
            ->assertJsonPath('data.email_sent', false)
            ->assertJsonPath('data.email_error', 'smtp down');

        $this->assertDatabaseHas('companies', ['email' => 'billing@northwind.test']);
        $this->assertTrue(NotificationLog::where('channel', NotificationChannel::Email)->where('status', 'failed')->exists());
        $this->assertStringStartsWith('https://wa.me/', $response->json('data.whatsapp_url'));
    }

    public function test_it_rejects_an_owner_email_that_already_exists(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'grace@northwind.test']);
        $plan = Plan::factory()->create();
        $application = Application::factory()->pending()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/admin/applications/{$application->id}/convert", $this->payload($plan))
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_email');

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_an_application_cannot_be_converted_twice(): void
    {
        Mail::fake();

        $plan = Plan::factory()->create();
        $application = Application::factory()->pending()->create();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/applications/{$application->id}/convert", $this->payload($plan))
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/applications/{$application->id}/convert", $this->payload($plan, [
                'owner_email' => 'second@northwind.test',
            ]))
            ->assertStatus(422);

        $this->assertDatabaseCount('companies', 1);
    }
}

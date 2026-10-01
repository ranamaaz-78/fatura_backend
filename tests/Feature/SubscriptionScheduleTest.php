<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\SubscriptionStatus;
use App\Mail\SubscriptionExpiringMail;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_expire_marks_lapsed_subscriptions_only(): void
    {
        $lapsed = Subscription::factory()->expired()->create(['company_id' => Company::factory()]);
        $live = Subscription::factory()->create(['company_id' => Company::factory()]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Expired, $lapsed->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $live->fresh()->status);
    }

    public function test_expire_respects_the_grace_period(): void
    {
        config(['fatura.subscriptions.grace_days' => 5]);

        $subscription = Subscription::factory()->create([
            'company_id' => Company::factory(),
            'ends_at' => now()->subDays(2),
        ]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
    }

    public function test_remind_notifies_owners_of_subscriptions_about_to_lapse(): void
    {
        Mail::fake();

        $company = Company::factory()->create();
        User::factory()->businessAdmin()->create([
            'company_id' => $company->id,
            'email' => 'grace@northwind.test',
        ]);
        Subscription::factory()->create([
            'company_id' => $company->id,
            'ends_at' => now()->addDays(3)->endOfDay(),
        ]);

        $quiet = Company::factory()->create();
        User::factory()->businessAdmin()->create(['company_id' => $quiet->id]);
        Subscription::factory()->create([
            'company_id' => $quiet->id,
            'ends_at' => now()->addDays(20),
        ]);

        $this->artisan('subscriptions:remind')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(
            SubscriptionExpiringMail::class,
            fn (SubscriptionExpiringMail $mail) => $mail->hasTo('grace@northwind.test'),
        );

        $this->assertTrue(
            NotificationLog::where('channel', NotificationChannel::Email)
                ->where('type', 'subscription_expiring_3d')
                ->exists(),
        );
    }
}

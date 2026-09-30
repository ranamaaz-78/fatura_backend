<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Mail\ApplicationOtpMail;
use App\Models\Application;
use App\Models\Plan;
use App\Models\User;
use App\Services\ApplicationOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'phone' => '+34 612345678',
            'city' => 'Madrid',
            'country' => 'Spain',
        ], $overrides);
    }

    /** Asks for a code the way the form does, and returns the one that was emailed. */
    private function requestCode(array $payload): string
    {
        Mail::fake();

        $this->postJson('/api/public/applications/otp', $payload)->assertOk();

        $code = null;
        Mail::assertSent(ApplicationOtpMail::class, function (ApplicationOtpMail $mail) use (&$code, $payload) {
            $code = $mail->code;

            return $mail->hasTo(strtolower($payload['email']));
        });

        return (string) $code;
    }

    public function test_public_plans_only_returns_active_plans(): void
    {
        Plan::factory()->create(['name' => 'Starter', 'slug' => 'starter']);
        Plan::factory()->inactive()->create(['name' => 'Retired', 'slug' => 'retired']);

        $response = $this->getJson('/api/public/plans');

        $response->assertOk();
        $this->assertSame(['Starter'], array_column($response->json('data'), 'name'));
    }

    public function test_asking_for_a_code_emails_it_and_saves_nothing(): void
    {
        Mail::fake();

        $this->postJson('/api/public/applications/otp', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        Mail::assertSent(ApplicationOtpMail::class, fn (ApplicationOtpMail $mail) => $mail->hasTo('grace@northwind.test')
            && preg_match('/^\d{6}$/', $mail->code) === 1);
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_the_code_email_has_a_clear_subject_and_shows_the_code(): void
    {
        $mail = new ApplicationOtpMail('Grace Hopper', '048213');

        $this->assertStringContainsString('048213', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('048213', $html);
        $this->assertStringContainsString('Hi Grace Hopper', $html);
        $this->assertStringContainsString('10 minutes', $html);
    }

    public function test_it_stores_an_application_once_the_code_is_confirmed(): void
    {
        $plan = Plan::factory()->create(['slug' => 'starter']);
        $payload = $this->payload(['plan_slug' => 'starter']);
        $code = $this->requestCode($payload);

        $this->postJson('/api/public/applications', $payload + ['otp' => $code])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $application = Application::sole();
        $this->assertSame('grace@northwind.test', $application->email);
        $this->assertSame(ApplicationStatus::New, $application->status);
        $this->assertSame($plan->id, $application->plan_id);
        $this->assertSame('website', $application->source);
        $this->assertDatabaseCount('application_activities', 1);
    }

    public function test_an_application_cannot_be_sent_without_a_code(): void
    {
        $this->postJson('/api/public/applications', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_a_code_that_was_never_requested_is_refused(): void
    {
        $this->postJson('/api/public/applications', $this->payload() + ['otp' => '123456'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_a_wrong_code_is_refused_and_nothing_is_saved(): void
    {
        $payload = $this->payload();
        $code = $this->requestCode($payload);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/public/applications', $payload + ['otp' => $wrong])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_a_code_only_works_for_the_email_it_was_sent_to(): void
    {
        $code = $this->requestCode($this->payload());

        $this->postJson('/api/public/applications', $this->payload(['email' => 'someone@else.test']) + ['otp' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_a_code_can_only_be_used_once(): void
    {
        $payload = $this->payload();
        $code = $this->requestCode($payload);

        $this->postJson('/api/public/applications', $payload + ['otp' => $code])->assertCreated();

        // The same address now has a live application, and the code is gone either way.
        $this->postJson('/api/public/applications', $payload + ['otp' => $code])->assertStatus(422);

        $this->assertDatabaseCount('applications', 1);
    }

    public function test_five_wrong_guesses_lock_the_code_even_for_the_right_one(): void
    {
        $payload = $this->payload();
        $code = $this->requestCode($payload);
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, ApplicationOtp::MAX_ATTEMPTS) as $attempt) {
            $this->postJson('/api/public/applications', $payload + ['otp' => $wrong])->assertStatus(422);
        }

        $this->postJson('/api/public/applications', $payload + ['otp' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $payload = $this->payload();
        $code = $this->requestCode($payload);

        $this->travel(ApplicationOtp::TTL_SECONDS + 5)->seconds();

        $this->postJson('/api/public/applications', $payload + ['otp' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_asking_again_right_away_does_not_send_a_second_email(): void
    {
        Mail::fake();

        $this->postJson('/api/public/applications/otp', $this->payload())->assertOk();
        $this->postJson('/api/public/applications/otp', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        Mail::assertSent(ApplicationOtpMail::class, 1);
    }

    public function test_a_new_code_can_be_asked_for_after_the_wait_and_replaces_the_old_one(): void
    {
        $payload = $this->payload();
        $first = $this->requestCode($payload);

        $this->travel(ApplicationOtp::RESEND_SECONDS + 1)->seconds();

        $second = $this->requestCode($payload);

        if ($first !== $second) {
            $this->postJson('/api/public/applications', $payload + ['otp' => $first])->assertStatus(422);
        }
        $this->postJson('/api/public/applications', $payload + ['otp' => $second])->assertCreated();
    }

    public function test_a_failed_email_says_so_and_lets_them_retry_at_once(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/public/applications/otp', $this->payload())->assertStatus(503);

        $this->assertSame(0, app(ApplicationOtp::class)->waitSeconds('grace@northwind.test'));
    }

    public function test_asking_for_a_code_validates_the_details_first(): void
    {
        Mail::fake();

        $this->postJson('/api/public/applications/otp', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name', 'contact_name', 'email', 'phone'])
            ->assertJsonMissingValidationErrors(['otp']);

        Mail::assertNothingSent();
    }

    public function test_a_filled_honeypot_looks_successful_but_sends_and_stores_nothing(): void
    {
        Mail::fake();

        $this->postJson('/api/public/applications/otp', $this->payload(['website' => 'http://spam.test']))
            ->assertOk();
        $this->postJson('/api/public/applications', $this->payload(['website' => 'http://spam.test', 'otp' => '123456']))
            ->assertCreated()
            ->assertJsonPath('success', true);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * @return array<string, array{0: ApplicationStatus}>
     */
    public static function liveStatuses(): array
    {
        return [
            'new' => [ApplicationStatus::New],
            'contacted' => [ApplicationStatus::Contacted],
            'approved' => [ApplicationStatus::Approved],
        ];
    }

    #[DataProvider('liveStatuses')]
    public function test_an_email_with_a_live_application_gets_no_code_and_cannot_apply(ApplicationStatus $status): void
    {
        Mail::fake();
        Application::factory()->create([
            'email' => 'grace@northwind.test',
            'status' => $status,
            'created_at' => now()->subDays(30),
        ]);

        $this->postJson('/api/public/applications/otp', $this->payload(['email' => 'Grace@Northwind.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_an_email_that_already_has_an_account_gets_no_code_and_cannot_apply(): void
    {
        Mail::fake();
        User::factory()->businessAdmin()->create(['email' => 'grace@northwind.test']);

        $this->postJson('/api/public/applications/otp', $this->payload(['email' => 'Grace@Northwind.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_a_rejected_email_can_apply_again_with_a_code(): void
    {
        Application::factory()->create([
            'email' => 'grace@northwind.test',
            'status' => ApplicationStatus::Rejected,
        ]);

        $payload = $this->payload();
        $code = $this->requestCode($payload);

        $this->postJson('/api/public/applications', $payload + ['otp' => $code])->assertCreated();

        $this->assertDatabaseCount('applications', 2);
    }

    public function test_asking_for_codes_is_throttled(): void
    {
        RateLimiter::clear('');
        Mail::fake();

        foreach (range(1, 6) as $i) {
            $this->postJson('/api/public/applications/otp', $this->payload([
                'email' => "lead{$i}@northwind.test",
            ]))->assertOk();
        }

        $this->postJson('/api/public/applications/otp', $this->payload(['email' => 'lead7@northwind.test']))
            ->assertStatus(429);
    }
}

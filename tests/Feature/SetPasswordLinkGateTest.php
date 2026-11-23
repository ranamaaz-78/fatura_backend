<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\TeamMemberMail;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * A set-password link is a way in, so it obeys the same rules as the sign-in form: it never re-opens a disabled
 * account, never hands a token to a suspended company or to a team whose subscription ended, and a link sent before
 * a member was disabled or removed stops working. (Audit finding H1.)
 */
class SetPasswordLinkGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->company = Company::factory()->create();
        $this->subscription = Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    private function useLink(string $email, string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/set-password', [
            'token' => $token,
            'email' => $email,
            'password' => 'my-own-pass-1',
            'password_confirmation' => 'my-own-pass-1',
        ]);
    }

    /** A member made through the team screen, and the token from the link they were emailed. */
    private function invitedMember(): array
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [
            'name' => 'Marta Rivas',
            'email' => 'marta@shop.test',
            'permissions' => ['invoices.view'],
        ])->assertCreated();

        $mail = Mail::sent(TeamMemberMail::class)->last();
        parse_str((string) parse_url($mail->setPasswordUrl, PHP_URL_QUERY), $query);

        return [User::where('email', 'marta@shop.test')->firstOrFail(), $query['token']];
    }

    public function test_a_member_disabled_after_the_link_was_sent_cannot_use_it_to_come_back(): void
    {
        [$member, $token] = $this->invitedMember();

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/team/{$member->id}/status", ['status' => 'disabled'])->assertOk();

        $response = $this->useLink($member->email, $token);

        $this->assertNotSame(200, $response->status());
        $this->assertNull($response->json('data.token'));
        $this->assertSame('disabled', $member->fresh()->status->value);
        $this->assertNull($member->fresh()->password);
        $this->assertSame(0, $member->fresh()->tokens()->count());
    }

    public function test_a_disabled_account_is_refused_even_with_a_valid_link(): void
    {
        // However the account came to be disabled, a link that is still valid does not open it.
        $member = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id, 'password' => null, 'status' => 'disabled']);
        $token = app(InviteService::class)->createToken($member);

        $this->useLink($member->email, $token)->assertForbidden()->assertJsonPath('code', 'USER_DISABLED')->assertJsonMissingPath('data.token');
        $this->assertSame('disabled', $member->fresh()->status->value);
    }

    public function test_a_suspended_company_gets_no_token_and_the_link_waits_for_it_to_reopen(): void
    {
        $this->owner->forceFill(['password' => null])->save();
        $token = app(InviteService::class)->createToken($this->owner);
        $this->company->update(['status' => CompanyStatus::Suspended]);

        $this->useLink($this->owner->email, $token)->assertForbidden()->assertJsonPath('code', 'COMPANY_SUSPENDED')->assertJsonMissingPath('data.token');
        $this->assertNull($this->owner->fresh()->password);
        $this->assertSame(0, $this->owner->tokens()->count());

        // Reopened: the same link now works.
        $this->company->update(['status' => CompanyStatus::Active]);
        $this->useLink($this->owner->email, $token)->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_a_team_member_gets_no_token_once_the_subscription_has_ended(): void
    {
        [$member, $token] = $this->invitedMember();
        $this->subscription->update(['status' => SubscriptionStatus::Cancelled, 'ends_at' => now()->subMonth()]);

        $this->useLink($member->email, $token)->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');
    }

    public function test_without_a_real_link_the_answer_never_tells_whether_an_account_is_disabled_or_suspended(): void
    {
        $member = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id, 'status' => 'disabled']);

        $this->useLink($member->email, 'not-a-real-token')->assertStatus(422)->assertJsonPath('code', 'INVITE_INVALID');
        $this->useLink('nobody@shop.test', 'not-a-real-token')->assertStatus(422)->assertJsonPath('code', 'INVITE_INVALID');
    }

    public function test_a_removed_members_old_link_does_not_open_a_new_account_on_the_same_email(): void
    {
        [$member, $token] = $this->invitedMember();
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/app/team/{$member->id}")->assertOk();

        // The address is given to someone new.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [
            'name' => 'Someone Else', 'email' => 'marta@shop.test', 'permissions' => ['invoices.view'],
        ])->assertCreated();

        $this->useLink('marta@shop.test', $token)->assertStatus(422)->assertJsonPath('code', 'INVITE_INVALID');
    }

    public function test_disabling_a_member_also_kills_a_forgot_password_link(): void
    {
        [$member] = $this->invitedMember();
        $member->forceFill(['password' => 'old-pass-123'])->save();
        $resetToken = Password::broker()->createToken($member);

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/team/{$member->id}/status", ['status' => 'disabled'])->assertOk();

        $this->assertFalse(Password::broker()->tokenExists($member->fresh(), $resetToken));
    }

    public function test_an_active_member_still_sets_a_password_and_signs_in(): void
    {
        [$member, $token] = $this->invitedMember();

        $this->useLink($member->email, $token)->assertOk()->assertJsonStructure(['data' => ['token', 'user', 'role']]);
        $this->assertSame('active', $member->fresh()->status->value);
        $this->postJson('/api/auth/login', ['email' => $member->email, 'password' => 'my-own-pass-1'])->assertOk();
    }
}

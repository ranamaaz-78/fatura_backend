<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class IdleSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // The rule under test is the standard 30 minutes, whatever the machine's .env says.
        config(['fatura.session.idle_minutes' => 30]);

        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $company->id, 'password' => 'secret-pass-1']);
    }

    private function signIn(): string
    {
        return (string) $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => 'secret-pass-1'])->assertOk()->json('data.token');
    }

    private function me(string $token)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/auth/me');
    }

    private function idleFor(string $token, int $minutes): void
    {
        $row = PersonalAccessToken::findToken($token);
        $row->forceFill(['last_used_at' => now()->subMinutes($minutes)])->save();
    }

    public function test_someone_who_keeps_working_stays_signed_in(): void
    {
        $token = $this->signIn();

        // 29 minutes of quiet is fine, and the next request starts the count again.
        $this->idleFor($token, 29);
        $this->me($token)->assertOk();
        $this->me($token)->assertOk();
    }

    public function test_thirty_one_minutes_without_a_request_signs_the_person_out_for_good(): void
    {
        $token = $this->signIn();
        $this->idleFor($token, 31);

        $this->me($token)->assertStatus(401)->assertJsonPath('code', 'SESSION_IDLE');

        // The token is gone: it cannot be used again, even straight away.
        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->me($token)->assertStatus(401);
    }

    public function test_a_token_that_was_never_used_counts_from_the_day_it_was_made(): void
    {
        $token = $this->signIn();
        PersonalAccessToken::findToken($token)->forceFill(['last_used_at' => null, 'created_at' => now()->subDays(2)])->save();

        $this->me($token)->assertStatus(401)->assertJsonPath('code', 'SESSION_IDLE');
    }

    public function test_the_idle_time_can_be_changed(): void
    {
        config(['fatura.session.idle_minutes' => 120]);
        $token = $this->signIn();
        $this->idleFor($token, 100);
        $this->me($token)->assertOk();

        // Zero switches the rule off.
        config(['fatura.session.idle_minutes' => 0]);
        $this->idleFor($token, 5000);
        $this->me($token)->assertOk();
    }

    public function test_the_login_form_and_public_pages_are_not_affected(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')->getJson('/api/public/plans')->assertOk();
        $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => 'secret-pass-1'])->assertOk();
    }

    public function test_the_answer_speaks_the_language_of_the_request(): void
    {
        $token = $this->signIn();
        $this->idleFor($token, 45);

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept-Language' => 'es'])->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_IDLE')
            ->assertJsonPath('message', 'Se cerró tu sesión tras 30 minutos sin actividad. Vuelve a iniciar sesión.');
    }

    public function test_the_session_tells_the_screen_how_long_the_idle_time_is(): void
    {
        $token = $this->signIn();
        $this->me($token)->assertOk()->assertJsonPath('data.idle_minutes', 30);

        config(['fatura.session.idle_minutes' => 45]);
        $this->me($token)->assertJsonPath('data.idle_minutes', 45);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * In production (APP_DEBUG off) an error answer says only what went wrong in plain words: never a stack trace,
 * SQL, a file path, a class name, a route or the mail server's own message. The full details go to the server log.
 */
class ErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    /** Things that must never reach the person on the other end. */
    private const LEAKS = ['SQLSTATE', 'select *', 'table_that_does_not_exist', 'hunter2', 'App\\Models', 'App\Models', '.php', '"trace"', '"file"', '"line"', '"exception"', 'api/app/nope', 'Supported methods', 'smtp.internal.example', 'secret-login@'];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        Route::middleware('api')->prefix('api')->group(function () {
            Route::get('/zz-test/crash', fn () => throw new \RuntimeException('internal detail in /var/www/html/app/Secret.php'));
            Route::get('/zz-test/sql', fn () => DB::select('SELECT * FROM table_that_does_not_exist WHERE password = ?', ['hunter2']));
            Route::get('/zz-test/type', fn () => strlen([]));
        });

        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $company->id]);
    }

    private function assertNothingLeaks(TestResponse $response, string $label): void
    {
        foreach (self::LEAKS as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent(), "{$label} leaked \"{$leak}\".");
        }
    }

    public function test_a_crash_answers_server_error_and_is_logged_in_full(): void
    {
        Log::spy();

        foreach (['/api/zz-test/crash', '/api/zz-test/sql', '/api/zz-test/type'] as $url) {
            $response = $this->actingAs($this->owner, 'sanctum')->getJson($url)->assertStatus(500);

            $this->assertSame(['message' => 'Server Error'], $response->json());
            $this->assertNothingLeaks($response, $url);
        }

        // The full exception (message, file, trace) was handed to the log, server side only.
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => str_contains($message, 'internal detail') && isset($context['exception']))->once();
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'table_that_does_not_exist'))->once();
    }

    public function test_not_found_and_wrong_method_name_no_classes_routes_or_methods(): void
    {
        foreach ([
            ['GET', '/api/app/products/999999', 404, 'NOT_FOUND'],
            ['GET', '/api/app/sales/999999', 404, 'NOT_FOUND'],
            ['GET', '/api/app/nope', 404, 'NOT_FOUND'],
            ['DELETE', '/api/app/dashboard', 405, 'METHOD_NOT_ALLOWED'],
        ] as [$method, $url, $status, $code]) {
            $response = $this->actingAs($this->owner, 'sanctum')->json($method, $url)->assertStatus($status)->assertJsonPath('code', $code);
            $this->assertNothingLeaks($response, "{$method} {$url}");
        }

        // Spanish users get it in Spanish.
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/products/999999?lang=es')
            ->assertJsonPath('message', 'No encontrado.');
    }

    public function test_with_debug_on_developers_still_see_the_details(): void
    {
        config(['app.debug' => true]);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/products/999999')
            ->assertNotFound()->assertJsonPath('message', fn ($message) => str_contains($message, 'App\Models\Product'));
    }

    public function test_a_mail_failure_tells_the_owner_it_failed_but_not_the_mail_servers_words(): void
    {
        $smtp = 'Failed to authenticate on SMTP server smtp.internal.example with username "secret-login@shop.test"';
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('locale')->andReturnSelf();
        Mail::shouldReceive('sendNow')->andThrow(new \RuntimeException($smtp));

        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [
            'name' => 'Marta Rivas',
            'email' => 'marta@shop.test',
            'permissions' => ['invoices.view'],
        ])->assertCreated()
            ->assertJsonPath('data.email_sent', false)
            ->assertJsonPath('data.email_error', 'The email could not be sent. Please try again in a few minutes.');

        $this->assertNothingLeaks($response, 'team email failure');
        // Kept for whoever runs the server.
        $this->assertDatabaseHas('notification_logs', ['recipient' => 'marta@shop.test', 'status' => 'failed']);
    }

    public function test_debug_left_on_in_production_is_switched_off(): void
    {
        Log::spy();
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse(config('app.debug'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'APP_DEBUG'))->once();
    }
}

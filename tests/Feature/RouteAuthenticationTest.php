<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every endpoint either needs a signed-in user or is on the short, deliberate list of public ones below. Adding a
 * public endpoint means adding it here on purpose; forgetting the auth middleware on a new one fails this test.
 */
class RouteAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** Open to anyone, on purpose. Each one is either public information or protected by a secret in the request. */
    private const PUBLIC = [
        'GET /',                                              // Laravel welcome page
        'GET up',                                             // health check
        'GET api/health',                                     // health check: app name and time only
        'GET sanctum/csrf-cookie',                            // Sanctum's CSRF cookie
        'GET api/public/plans',                               // the price list
        'GET api/public/site-content',                        // FAQs and legal pages
        'GET api/public/desktop-app',                         // the Windows app's version info
        'GET api/public/desktop-app/download',                // the Windows installer
        'GET api/public/desktop-app/updates/latest.yml',      // the auto-updater's feed
        'GET api/public/desktop-app/updates/{file}',          // the auto-updater's download
        'GET api/public/invoices/verify/{code}',              // QR on an invoice: a 24-character secret code
        'GET api/public/invoices/verify/{code}/document',     // same code, the invoice as printed
        'POST api/public/applications/otp',                   // sign-up form: email code
        'POST api/public/applications',                       // sign-up form
        'POST api/auth/login',
        'POST api/auth/forgot-password',
        'POST api/auth/reset-password',                       // needs the emailed reset token
        'POST api/auth/set-password',                         // needs the emailed invite token
    ];

    /** @return list<array{string, Route}> */
    private function routes(): array
    {
        $routes = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method.' '.$route->uri(), $route];
            }
        }

        return $routes;
    }

    private function requiresLogin(Route $route): bool
    {
        return collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth:sanctum'));
    }

    public function test_every_endpoint_needs_a_login_unless_it_is_deliberately_public(): void
    {
        $unexpected = [];
        $stale = self::PUBLIC;

        foreach ($this->routes() as [$key, $route]) {
            if ($this->requiresLogin($route)) {
                $this->assertNotContains($key, self::PUBLIC, "{$key} is listed as public but requires a login.");

                continue;
            }

            $stale = array_diff($stale, [$key]);

            if (! in_array($key, self::PUBLIC, true)) {
                $unexpected[] = $key;
            }
        }

        $this->assertSame([], $unexpected, 'These endpoints have no login check. Add auth:sanctum, or list them in PUBLIC on purpose.');
        $this->assertSame([], array_values($stale), 'These PUBLIC entries no longer exist; remove them from the list.');
    }

    public function test_a_guest_gets_401_from_every_private_endpoint(): void
    {
        $checked = 0;

        foreach ($this->routes() as [$key, $route]) {
            if (! $this->requiresLogin($route)) {
                continue;
            }

            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn (array $m) => match ($m[1]) {
                'upload' => (string) Str::uuid(),
                'type' => 'factura',
                'role' => 'cashier',
                'slug' => 'terms',
                'kind' => 'sales',
                default => '1',
            }, $route->uri());

            [$method] = explode(' ', $key);
            $response = $this->json($method, '/'.$uri);

            $this->assertSame(401, $response->status(), "{$key} answered {$response->status()} to a guest.");
            $checked++;
        }

        $this->assertGreaterThan(150, $checked);
    }

    public function test_the_token_endpoints_are_rate_limited(): void
    {
        $body = ['token' => 'nope', 'email' => 'x@shop.test', 'password' => 'whatever-1', 'password_confirmation' => 'whatever-1'];

        foreach (['/api/auth/set-password', '/api/auth/reset-password'] as $url) {
            for ($i = 0; $i < 10; $i++) {
                $this->assertNotSame(429, $this->postJson($url, $body)->status(), "{$url} was limited too early.");
            }

            $this->postJson($url, $body)->assertStatus(429);
        }
    }

    public function test_failed_logins_do_not_use_up_the_forgot_password_limit(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/login', ['email' => "nobody{$i}@shop.test", 'password' => 'wrong-pass-1']);
        }

        $this->assertNotSame(429, $this->postJson('/api/auth/forgot-password', ['email' => 'someone@shop.test'])->status());
        $this->assertNotSame(429, $this->postJson('/api/public/applications/otp', ['email' => 'someone@shop.test'])->status());
    }

    /**
     * Without its own name, every "throttle:N,M" on the same IP shares one counter, so one form uses up another's
     * limit. Each limit therefore carries a name (the third parameter).
     */
    public function test_every_rate_limit_has_its_own_counter(): void
    {
        $names = [];

        foreach ($this->routes() as [$key, $route]) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }

                $parts = explode(',', substr($middleware, strlen('throttle:')));
                // A named limiter (throttle:api) keeps its own key already.
                if (count($parts) === 1 && ! is_numeric($parts[0])) {
                    continue;
                }

                $this->assertCount(3, $parts, "{$key} uses {$middleware}: add a name, e.g. {$middleware},my-form");
                $names[$parts[2]][] = $key;
            }
        }

        foreach ($names as $name => $keys) {
            $this->assertCount(1, $keys, "Rate limit name '{$name}' is shared by: ".implode(', ', $keys));
        }
    }

    public function test_private_files_are_not_served_from_a_public_storage_url(): void
    {
        $this->assertFalse(Router::has('storage.local'));
        $this->get('/storage/company-logos/1/logo.png')->assertNotFound();
    }

    public function test_a_company_cannot_read_or_change_another_companys_records(): void
    {
        $theirs = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $theirs->id]);
        $theirOwner = User::factory()->businessAdmin()->create(['company_id' => $theirs->id]);

        $mine = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $mine->id]);
        $me = User::factory()->businessAdmin()->create(['company_id' => $mine->id]);

        $customer = Customer::factory()->create(['company_id' => $theirs->id]);
        $supplier = Supplier::factory()->create(['company_id' => $theirs->id]);
        $product = Product::factory()->create(['company_id' => $theirs->id, 'quantity' => 5]);
        $image = ProductImage::factory()->create(['company_id' => $theirs->id]);
        $category = Category::factory()->create(['company_id' => $theirs->id]);
        $method = CompanyPaymentMethod::factory()->create(['company_id' => $theirs->id]);
        $member = User::factory()->create(['role' => 'staff', 'company_id' => $theirs->id]);

        $sale = $this->actingAs($theirOwner, 'sanctum')->postJson('/api/app/sales', [
            'type' => 'factura',
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => 'Counter sale',
            'lines' => [[
                'product_id' => $product->id,
                'article' => $product->article,
                'quantity' => 1,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();

        $attempts = [
            ['PATCH', "/api/app/customers/{$customer->id}", ['name' => 'Hacked']],
            ['PATCH', "/api/app/customers/{$customer->id}/active", ['is_active' => false]],
            ['DELETE', "/api/app/customers/{$customer->id}", []],
            ['PATCH', "/api/app/suppliers/{$supplier->id}", ['name' => 'Hacked']],
            ['DELETE', "/api/app/suppliers/{$supplier->id}", []],
            ['GET', "/api/app/products/{$product->id}", []],
            ['PATCH', "/api/app/products/{$product->id}", ['article' => 'Hacked']],
            ['DELETE', "/api/app/products/{$product->id}", []],
            ['GET', "/api/app/product-images/{$image->id}/file", []],
            ['PATCH', "/api/app/product-images/{$image->id}", ['name' => 'Hacked']],
            ['DELETE', "/api/app/product-images/{$image->id}", []],
            ['PATCH', "/api/app/categories/{$category->id}", ['name' => 'Hacked']],
            ['DELETE', "/api/app/categories/{$category->id}", []],
            ['PATCH', "/api/app/payment-methods/{$method->id}", ['name' => 'Hacked']],
            ['DELETE', "/api/app/payment-methods/{$method->id}", []],
            ['GET', "/api/app/sales/{$sale}", []],
            ['PATCH', "/api/app/sales/{$sale}/payment", ['payment_status' => 'paid']],
            ['POST', "/api/app/sales/{$sale}/void", ['reason' => 'Hacked']],
            ['DELETE', "/api/app/sales/{$sale}", []],
            ['PATCH', "/api/app/team/{$member->id}", ['name' => 'Hacked']],
            ['POST', "/api/app/team/{$member->id}/password", []],
            ['DELETE', "/api/app/team/{$member->id}", []],
        ];

        foreach ($attempts as [$verb, $url, $body]) {
            $status = $this->actingAs($me, 'sanctum')->json($verb, $url, $body)->status();
            $this->assertContains($status, [403, 404], "{$verb} {$url} answered {$status} to another company.");
        }

        // Bulk delete answers 200 with a per-item report: their invoice must come back as not found, untouched.
        $this->actingAs($me, 'sanctum')->postJson('/api/app/sales/bulk-delete', ['ids' => [$sale]])
            ->assertOk()->assertJsonPath('data.failed.0.id', $sale);
        $this->assertNull(\App\Models\SalesDocument::withoutGlobalScopes()->withTrashed()->find($sale)->deleted_at);

        // Nothing of theirs was touched.
        $this->assertNotSame('Hacked', $customer->fresh()->name);
        $this->assertNotSame('Hacked', $supplier->fresh()->name);
        $this->assertNotSame('Hacked', $product->fresh()->article);
        $this->assertNotNull($image->fresh());
        $this->assertNotNull($category->fresh());
        $this->assertNotNull($member->fresh());
        $this->assertNotNull($member->fresh()->password);

        // And their lists do not show up in mine.
        $this->assertNotContains($customer->id, collect($this->actingAs($me, 'sanctum')->getJson('/api/app/customers')->json('data.items') ?? $this->actingAs($me, 'sanctum')->getJson('/api/app/customers')->json('data'))->pluck('id')->all());
        $this->assertNotContains($sale, collect($this->actingAs($me, 'sanctum')->getJson('/api/app/sales')->json('data.items'))->pluck('id')->all());
    }
}

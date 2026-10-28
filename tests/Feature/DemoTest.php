<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DemoSession;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Demo\DemoCompany;
use App\Services\Demo\DemoResetter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // A real customer, with a real company of their own.
        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    private function demo(): Company
    {
        return app(DemoCompany::class)->find() ?? app(DemoResetter::class)->reset();
    }

    /** Goes into the demo as $user and returns the demo token. */
    private function enter(?User $user = null): string
    {
        return $this->actingAs($user ?? $this->owner, 'sanctum')->postJson('/api/app/demo/enter')->assertOk()->json('data.token');
    }

    /** A request made with the demo token itself, the way the app does after switching. */
    private function asDemo(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_the_demo_is_built_with_sample_data_that_adds_up(): void
    {
        $company = $this->demo();

        $this->assertTrue($company->is_demo);
        $this->assertTrue($company->isProfileComplete());
        $count = fn (string $model) => $model::withoutGlobalScopes()->where('company_id', $company->id)->count();

        $this->assertSame(6, \App\Models\Category::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(5, $count(Supplier::class));
        $this->assertSame(10, $count(Customer::class));
        $this->assertSame(34, $count(Product::class));
        $this->assertSame(2, $count(Purchase::class));
        $this->assertSame(13, $count(SalesDocument::class), '12 documents and a proforma');

        // The sample has something to show on every screen: paid and pending invoices, a part-paid proforma, low stock.
        $documents = SalesDocument::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $this->assertTrue($documents->where('type', 'factura')->where('payment_status', 'paid')->isNotEmpty());
        $this->assertTrue($documents->where('type', 'factura')->where('payment_status', 'pending')->isNotEmpty());
        $this->assertSame(1, $documents->where('type', 'proforma')->count());
        $this->assertSame(2, $documents->where('type', 'quotation')->count());
        $this->assertSame(2, $documents->where('type', 'albaran')->count());
        $this->assertGreaterThanOrEqual(3, Product::withoutGlobalScopes()->where('company_id', $company->id)->whereColumn('quantity', '<=', 'minimum_stock')->count());
        $this->assertSame(0, Product::withoutGlobalScopes()->where('company_id', $company->id)->where('quantity', '<', 0)->count(), 'stock never goes negative');

        // Every barcode is a real EAN-13.
        foreach (Product::withoutGlobalScopes()->where('company_id', $company->id)->pluck('barcode') as $barcode) {
            $this->assertSame(13, strlen($barcode));
            $this->assertSame(\App\Support\Barcode::checkDigit(substr($barcode, 0, 12)), substr($barcode, 12));
        }

        // The owner has no password: nobody can sign in to it from the login form.
        $owner = app(DemoCompany::class)->owner($company);
        $this->assertNull($owner->password);
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'anything'])->assertStatus(422);
    }

    public function test_a_customer_goes_in_with_their_own_login_and_sees_only_the_demo(): void
    {
        $mine = Product::factory()->create(['company_id' => $this->company->id, 'article' => 'My own secret product']);
        $token = $this->enter();

        $session = $this->asDemo($token)->getJson('/api/auth/me')->assertOk();
        $session->assertJsonPath('data.company.is_demo', true)->assertJsonPath('data.company.name', 'Demo Shop');

        $products = $this->getJson('/api/app/products?per_page=100')->assertOk();
        $this->assertNotContains('My own secret product', collect($products->json('data.items'))->pluck('article')->all());
        $this->assertSame(34, $products->json('data.meta.total'));

        $this->assertDatabaseHas('demo_sessions', ['user_id' => $this->owner->id, 'exited_at' => null]);
        $this->assertSame($mine->id, Product::withoutGlobalScopes()->where('article', 'My own secret product')->value('id'), 'their own data is untouched');
    }

    public function test_the_demo_token_is_short_lived_and_one_per_customer(): void
    {
        $first = $this->enter();
        $this->enter();

        $tokens = PersonalAccessToken::query()->where('name', 'demo:'.$this->owner->id)->get();
        $this->assertCount(1, $tokens, 'a new visit replaces the old one');
        $this->assertTrue($tokens->first()->expires_at->between(now()->addHours(5), now()->addHours(7)));

        // The first token is gone with the replaced visit.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$first)->getJson('/api/auth/me')->assertUnauthorized();

        // Once it has expired it stops working.
        $current = $this->enter();
        PersonalAccessToken::query()->where('name', 'demo:'.$this->owner->id)->update(['expires_at' => now()->subMinute()]);
        $this->asDemo($current)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_leaving_ends_the_visit(): void
    {
        $token = $this->enter();

        $this->asDemo($token)->postJson('/api/app/demo/exit')->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'demo:'.$this->owner->id]);
        $this->assertNotNull(DemoSession::query()->first()->exited_at);
    }

    public function test_only_members_of_a_company_may_go_in_and_not_twice(): void
    {
        $this->postJson('/api/app/demo/enter')->assertUnauthorized();

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin, 'sanctum')->postJson('/api/app/demo/enter')->assertForbidden();

        $token = $this->enter();
        $this->asDemo($token)->postJson('/api/app/demo/enter')->assertStatus(422);
    }

    public function test_what_would_change_the_shared_account_for_everybody_is_closed(): void
    {
        $token = $this->enter();
        $this->asDemo($token);

        foreach ([
            ['patch', '/api/app/company', ['name' => 'Hijacked']],
            ['patch', '/api/app/company/locale', ['locale' => 'es']],
            ['patch', '/api/app/password', ['current_password' => 'x', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1']],
            ['get', '/api/app/team', []],
            ['post', '/api/app/team', ['name' => 'X', 'email' => 'x@y.test', 'password' => 'secret-pass-1', 'permissions' => ['invoices.view']]],
            ['patch', '/api/app/whatsapp/settings', ['template' => 'hi']],
            ['get', '/api/app/subscription', []],
            ['post', '/api/app/company/logo', []],
            ['post', '/api/app/product-images', []],
            ['post', '/api/app/products/import', ['rows' => []]],
        ] as [$method, $url, $body]) {
            $this->{$method.'Json'}($url, $body)->assertForbidden()->assertJsonPath('code', 'DEMO_RESTRICTED');
        }

        $this->assertSame('Demo Shop', Company::where('is_demo', true)->value('name'));
    }

    public function test_everything_else_stays_open_for_practice(): void
    {
        $token = $this->enter();
        $this->asDemo($token);

        $product = Product::withoutGlobalScopes()->where('company_id', $this->demo()->id)->first();
        $supplier = Supplier::withoutGlobalScopes()->where('company_id', $this->demo()->id)->first();

        $this->postJson('/api/app/customers', ['name' => 'Practice Client'])->assertCreated();
        $this->postJson('/api/app/purchases', [
            'supplier_id' => $supplier->id,
            'purchased_on' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 100]],
        ])->assertCreated();
        $this->getJson('/api/app/dashboard')->assertOk()->assertJsonPath('data.company.name', 'Demo Shop');
        $this->putJson('/api/app/document-series', [
            'series' => [
                'factura' => ['prefix' => 'TRY', 'first' => 1, 'start' => 1],
                'albaran' => ['prefix' => 'AL', 'first' => 1, 'start' => 1],
                'quotation' => ['prefix' => 'Q', 'first' => 1, 'start' => 1],
                'proforma' => ['prefix' => 'PF', 'first' => 1, 'start' => 1],
            ],
        ])->assertOk();
    }

    public function test_the_demo_cannot_be_filled_up_by_one_visitor(): void
    {
        $company = $this->demo();
        // Fill the demo up to the cap (150 products) the way a visitor with a script would.
        $token = $this->enter();
        $this->asDemo($token);
        $room = 150 - Product::withoutGlobalScopes()->where('company_id', $company->id)->count();

        for ($i = 0; $i < $room; $i++) {
            Product::create([
                'company_id' => $company->id, 'article' => "Filler {$i}", 'barcode' => '2000000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT).'0',
                'quantity' => 1, 'minimum_stock' => 0, 'buying_price' => 100, 'selling_price' => 200, 'margin_percent' => 100, 'iva_percent' => 21,
            ]);
        }

        $this->postJson('/api/app/products', [
            'article' => 'One too many', 'quantity' => 1, 'minimum_stock' => 0, 'buying_price' => 100, 'selling_price' => 200, 'iva_percent' => 21,
        ])->assertStatus(422)->assertJsonPath('code', 'DEMO_FULL');
    }

    public function test_a_real_company_is_never_limited_or_restricted(): void
    {
        // The same routes, signed in as a real owner, behave normally.
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/team')->assertOk();
        $this->getJson('/api/app/subscription')->assertOk();
        $this->postJson('/api/app/customers', ['name' => 'A real client'])->assertCreated();
    }

    public function test_the_reset_puts_everything_back_and_keeps_the_people_signed_in(): void
    {
        $company = $this->demo();
        $token = $this->enter();
        $this->asDemo($token);

        // A visitor trashes the demo: a product deleted, a client added, the numbering changed.
        $victim = Product::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $this->postJson('/api/app/customers', ['name' => 'Practice Client'])->assertCreated();
        $company->forceFill(['document_series' => ['factura' => ['prefix' => 'TRY', 'start' => 50]]])->save();
        $victim->delete();

        app(DemoResetter::class)->reset();

        $count = fn (string $model) => $model::withoutGlobalScopes()->where('company_id', $company->id)->count();
        $this->assertSame(34, $count(Product::class));
        $this->assertSame(10, $count(Customer::class));
        $this->assertNull($company->fresh()->document_series);

        // Numbers start over, so the sample is the same every night.
        $numbers = SalesDocument::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'factura')->orderBy('id')->pluck('number');
        $this->assertStringEndsWith('/00001', $numbers->first());

        // The visitor's token still works.
        $this->asDemo($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.company.is_demo', true);
        $this->assertSame(1, User::where('company_id', $company->id)->count());
    }

    public function test_every_table_with_a_company_is_either_reset_or_deliberately_kept(): void
    {
        $known = [...DemoResetter::TABLES, ...DemoResetter::CASCADED, ...DemoResetter::KEEP];
        $missing = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (Schema::hasColumn($table, 'company_id') && ! in_array($table, $known, true)) {
                $missing[] = $table;
            }
        }

        $this->assertSame([], $missing, 'A table with a company_id was added: say in DemoResetter whether the demo reset empties it or keeps it.');
    }

    public function test_the_demo_stays_out_of_the_admin_numbers_and_the_reminders(): void
    {
        $this->demo();
        $admin = User::factory()->superAdmin()->create();

        // Only the real company counts.
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.companies.total', 1);
        $list = $this->getJson('/api/admin/companies')->assertOk();
        $this->assertSame([$this->company->id], collect($list->json('data.items') ?? $list->json('data.companies') ?? $list->json('data.data') ?? [])->pluck('id')->all());

        // The demo's subscription is never touched by the daily expiry, even if it were old.
        Subscription::withoutGlobalScopes()->where('company_id', $this->demo()->id)->update(['ends_at' => now()->subDays(400)]);
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame('active', Subscription::withoutGlobalScopes()->where('company_id', $this->demo()->id)->first()->status->value);
    }

    public function test_the_admin_sees_how_the_demo_is_used_and_can_reset_it(): void
    {
        $this->demo();
        $this->enter();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/demo')
            ->assertOk()
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.entries_total', 1)
            ->assertJsonPath('data.people_total', 1)
            ->assertJsonPath('data.data.products', 34);

        $this->postJson('/api/admin/demo/reset')->assertOk()->assertJsonPath('data.data.products', 34);

        // A company owner cannot.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/admin/demo/reset')->assertForbidden();
    }

    public function test_the_demo_is_built_on_the_first_visit_and_the_nightly_reset_is_scheduled(): void
    {
        $this->assertNull(app(DemoCompany::class)->find());

        $this->enter();

        $this->assertNotNull(app(DemoCompany::class)->find());

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($event) => $event->command);
        $this->assertTrue($events->contains(fn ($command) => str_contains((string) $command, 'demo:reset')));
    }

    public function test_the_admin_can_turn_the_demo_off_and_on(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $token = $this->enter();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/auth/me')->assertJsonPath('data.demo_enabled', true);

        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/demo', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::query()->where('name', 'like', 'demo:%')->count(), 'visitors are sent out');

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/auth/me')->assertJsonPath('data.demo_enabled', false);
        $this->postJson('/api/app/demo/enter')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/demo', ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/app/demo/enter')->assertOk();
        $this->assertNotEmpty($token);
    }
}

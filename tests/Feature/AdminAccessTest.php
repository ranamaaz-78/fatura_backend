<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\SiteFaq;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\MakesCompanyRecords;
use Tests\TestCase;

/**
 * Who may do what is decided on the server, not by what the screens hide. Every endpoint is called straight
 * through the API by someone who should not use it, with real records in the URL, and must refuse and change nothing.
 */
class AdminAccessTest extends TestCase
{
    use MakesCompanyRecords;
    use RefreshDatabase;

    /**
     * /api/app endpoints with no permission and no owner-only role on the route, each on purpose. A new endpoint
     * that is neither guarded nor listed here fails the test.
     */
    private const OPEN_TO_EVERY_MEMBER = [
        'GET api/app/me',                          // who am I
        'POST api/app/demo/enter',                 // the shared practice account
        'POST api/app/demo/exit',
        'GET api/app/tax-rates',                   // lookups the sale screen needs
        'GET api/app/recargo-rates',
        'GET api/app/categories',
        'GET api/app/payment-methods',
        'GET api/app/company',                     // the header printed on every document
        'GET api/app/company/logo',
        'GET api/app/whatsapp/settings',           // the message wording used when sending a document
        'PATCH api/app/password',                  // one's own password
    ];

    /** Checked inside the controller instead: per document type for sales, owner-only for the team. */
    private const CHECKED_IN_CONTROLLER = ['api/app/sales', 'api/app/team'];

    /** Only the company owner, whatever a team member was ticked. */
    private const OWNER_ONLY = [
        'GET api/app/subscription',
        'PATCH api/app/company/locale',
        'PATCH api/app/company/document-locale',
        'GET api/app/team',
        'POST api/app/team',
        'PUT api/app/team/roles/{role}',
        'DELETE api/app/team/roles/{role}',
        'PATCH api/app/team/{user}',
        'PATCH api/app/team/{user}/status',
        'POST api/app/team/{user}/password',
        'DELETE api/app/team/{user}',
    ];

    private const VOLATILE = ['personal_access_tokens', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'notification_logs', 'migrations'];

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    /** @return list<array{string, Route}> */
    private function routes(string $prefix): array
    {
        $routes = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $prefix)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method, $route];
            }
        }

        return $routes;
    }

    private function staff(array $permissions): User
    {
        return User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id, 'permissions' => $permissions]);
    }

    private function snapshot(): array
    {
        $tables = array_diff(array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables()), self::VOLATILE);

        return collect($tables)->mapWithKeys(fn ($t) => [$t => md5(DB::table($t)->get()->toJson())])->all();
    }

    /** Real records for every URL part of the admin and app areas, so the refusal comes from the role check, not a 404. */
    private function fill(string $uri, array $ids): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($ids, $uri) {
            $this->assertArrayHasKey($m[1], $ids, "No test value for {{$m[1]}} in {$uri}: add one.");

            return (string) $ids[$m[1]];
        }, $uri);
    }

    /**
     * The caller's own company and subscription: records they can see, so a refusal can only come from the role
     * check. (Another company's subscription would already answer 404, which hides whether the role is checked.)
     */
    private function adminIds(): array
    {
        return [
            'application' => Application::factory()->create()->id,
            'plan' => Plan::factory()->create()->id,
            'paymentMethod' => PaymentMethod::factory()->create()->id,
            'faq' => SiteFaq::create(['question_en' => 'Q', 'answer_en' => 'A', 'sort_order' => 1, 'is_published' => true])->id,
            'slug' => 'terms',
            'upload' => (string) Str::uuid(),
            'company' => $this->company->id,
            'subscription' => (int) Subscription::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->value('id'),
        ];
    }

    /** A company URL filled with that company's real records. */
    private function appUrl(string $uri, array $records): string
    {
        return '/'.preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($records, $uri) {
            if ($m[1] === 'sale' && preg_match('/settlements|returns/', $uri)) {
                return (string) $records['proforma'];
            }

            return (string) match ($m[1]) {
                'type' => 'factura',
                'kind' => 'sales',
                'role' => 'cashier',
                default => $records[$m[1]] ?? $this->fail("No test record for {{$m[1]}} in {$uri}: add one to records()."),
            };
        }, $uri);
    }

    public function test_only_the_platform_admin_reaches_the_admin_area(): void
    {
        $ids = $this->adminIds();
        $everything = $this->staff(Permissions::all());
        $before = $this->snapshot();
        $checked = 0;

        foreach ([$this->owner, $everything] as $user) {
            foreach ($this->routes('api/admin/') as [$method, $route]) {
                $url = '/'.$this->fill($route->uri(), $ids);
                $response = $this->actingAs($user, 'sanctum')->json($method, $url, []);

                $this->assertSame(403, $response->status(), "{$method} {$url} answered {$response->status()} to a {$user->role->value}.");
                $this->assertSame('FORBIDDEN_ROLE', $response->json('code'));
                $checked++;
            }
        }

        $this->assertGreaterThan(80, $checked);
        $this->assertSame($before, $this->snapshot(), 'A non-admin changed something through the admin area.');
    }

    public function test_a_refusal_does_not_reveal_whether_a_record_exists(): void
    {
        $real = Application::factory()->create()->id;
        $nobody = $this->staff([]);
        $customer = $this->records($this->owner)['customer'];

        // The role is checked before the record is looked up: the same 403 for a real ID and a made-up one.
        foreach ([$real, 999999] as $id) {
            $this->actingAs($this->owner, 'sanctum')->getJson("/api/admin/applications/{$id}")
                ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ROLE');
        }

        // Likewise for a permission.
        foreach ([$customer, 999999] as $id) {
            $this->actingAs($nobody, 'sanctum')->deleteJson("/api/app/customers/{$id}")
                ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_PERMISSION');
        }
    }

    public function test_the_platform_admin_does_not_act_inside_a_company(): void
    {
        $records = $this->records($this->owner);
        $admin = User::factory()->superAdmin()->create();
        $before = $this->snapshot();

        foreach ($this->routes('api/app/') as [$method, $route]) {
            $url = $this->appUrl($route->uri(), $records);
            $response = $this->actingAs($admin, 'sanctum')->json($method, $url, []);

            $this->assertSame(403, $response->status(), "{$method} {$url} answered {$response->status()} to the platform admin.");
            $this->assertSame('FORBIDDEN_ROLE', $response->json('code'));
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_every_member_endpoint_is_guarded_or_deliberately_open(): void
    {
        $unguarded = [];

        foreach ($this->routes('api/app/') as [$method, $route]) {
            $key = "{$method} {$route->uri()}";
            $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));

            $guarded = $middleware->contains(fn ($m) => str_starts_with($m, 'permission:') || $m === 'role:business_admin');
            $inController = collect(self::CHECKED_IN_CONTROLLER)->contains(fn ($p) => $route->uri() === $p || str_starts_with($route->uri(), $p.'/'));

            if (! $guarded && ! $inController && ! in_array($key, self::OPEN_TO_EVERY_MEMBER, true)) {
                $unguarded[] = $key;
            }
        }

        $this->assertSame([], $unguarded, 'No permission or owner check on these. Guard them, or list them in OPEN_TO_EVERY_MEMBER on purpose.');
    }

    public function test_owner_only_actions_refuse_a_member_who_has_every_permission(): void
    {
        $everything = $this->staff(Permissions::all());
        $colleague = $this->staff(['invoices.view']);
        $ids = ['role' => 'cashier', 'user' => $colleague->id];
        $bodies = [
            'POST api/app/team' => ['name' => 'X', 'email' => 'x@shop.test', 'permissions' => ['invoices.view']],
            'PUT api/app/team/roles/{role}' => ['permissions' => Permissions::all()],
            'PATCH api/app/team/{user}' => ['permissions' => Permissions::all()],
            'PATCH api/app/team/{user}/status' => ['status' => 'disabled'],
            'PATCH api/app/company/locale' => ['locale' => 'es'],
            'PATCH api/app/company/document-locale' => ['document_locale' => 'es'],
        ];

        $before = $this->snapshot();

        foreach (self::OWNER_ONLY as $key) {
            [$method, $uri] = explode(' ', $key);
            $url = '/'.$this->fill($uri, $ids);
            $status = $this->actingAs($everything, 'sanctum')->json($method, $url, $bodies[$key] ?? [])->status();

            $this->assertSame(403, $status, "{$key} answered {$status} to a member with every permission.");
        }

        $this->assertSame($before, $this->snapshot());

        // A member cannot give themselves more either: the only way to change permissions is the owner's team screen.
        $this->actingAs($everything, 'sanctum')->patchJson("/api/app/team/{$everything->id}", ['permissions' => Permissions::all()])->assertForbidden();
        $this->assertSame($everything->permissions, $everything->fresh()->permissions);
    }

    public function test_a_member_without_a_permission_is_refused_by_the_server(): void
    {
        $nobody = $this->staff([]);
        $this->assertSame([], $nobody->permissionList());

        // Real records of the company, so each refusal comes from the permission check.
        $records = $this->records($this->owner);

        $before = $this->snapshot();
        $checked = 0;

        foreach ($this->routes('api/app/') as [$method, $route]) {
            $permission = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
            if ($permission === null) {
                continue;
            }

            $url = $this->appUrl($route->uri(), $records);

            $response = $this->actingAs($nobody, 'sanctum')->json($method, $url, []);
            $this->assertSame(403, $response->status(), "{$method} {$url} answered {$response->status()} without {$permission}.");
            $this->assertSame('FORBIDDEN_PERMISSION', $response->json('code'));
            $checked++;
        }

        $this->assertGreaterThan(60, $checked);

        // Sales check the document type in the controller: nothing to see, nothing done.
        $sale = $records['sale'];
        foreach ([
            ['GET', "/api/app/sales/{$sale}"],
            ['PATCH', "/api/app/sales/{$sale}"],
            ['POST', "/api/app/sales/{$sale}/void"],
            ['PATCH', "/api/app/sales/{$sale}/payment"],
            ['POST', "/api/app/sales/{$sale}/convert"],
            ['DELETE', "/api/app/sales/{$sale}"],
            ['POST', '/api/app/sales'],
            ['GET', '/api/app/sales?type=factura'],
        ] as [$method, $url]) {
            $status = $this->actingAs($nobody, 'sanctum')->json($method, $url, ['type' => 'factura', 'reason' => 'X', 'payment_status' => 'paid'])->status();
            $this->assertContains($status, [403, 422], "{$method} {$url} answered {$status} without any permission.");
        }
        $this->assertSame([], $this->actingAs($nobody, 'sanctum')->getJson('/api/app/sales')->json('data.items') ?? []);

        $this->assertSame($before, $this->snapshot(), 'A member without permission changed something.');
    }
}

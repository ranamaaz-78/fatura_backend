<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\MakesCompanyRecords;
use Tests\TestCase;

/**
 * IDOR guard: every /api/app endpoint that takes a record in its URL is called by another company's owner with this
 * company's record. Each must answer 403/404 and leave the database exactly as it was. The list of endpoints is read
 * from the router, so a new endpoint with an ID is checked automatically, and one with an unknown kind of ID fails
 * until it is added to the map below.
 */
class TenantIsolationTest extends TestCase
{
    use MakesCompanyRecords;
    use RefreshDatabase;

    /** URL parts that are not records (a fixed list of words), so there is nothing to own. */
    private const NOT_RECORDS = ['type', 'role', 'kind'];

    /** Tables that change on every request by design (sessions, tokens, logs), so they are left out of the snapshot. */
    private const VOLATILE = ['personal_access_tokens', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'notification_logs', 'migrations'];

    private User $theirOwner;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $theirs = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $theirs->id]);
        $this->theirOwner = User::factory()->businessAdmin()->create(['company_id' => $theirs->id]);

        $mine = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $mine->id]);
        $this->me = User::factory()->businessAdmin()->create(['company_id' => $mine->id]);
    }

    /** Every row of every table, as one string per table, to prove nothing changed. */
    private function snapshot(): array
    {
        $tables = array_diff(array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables()), self::VOLATILE);
        $state = [];

        foreach ($tables as $table) {
            $state[$table] = md5(DB::table($table)->get()->toJson());
        }

        return $state;
    }

    /** The record to put in a URL part: the other company's, or for a child under a parent, my parent and their child. */
    private function urlFor(string $uri, array $theirs, array $mine, bool $mixParent): ?string
    {
        preg_match_all('/\{(\w+)\}/', $uri, $m);
        $params = array_values(array_diff($m[1], self::NOT_RECORDS));

        if ($params === []) {
            return null;
        }

        $first = $params[0];

        return preg_replace_callback('/\{(\w+)\}/', function (array $match) use ($theirs, $mine, $mixParent, $first, $params, $uri) {
            $name = $match[1];

            if (in_array($name, self::NOT_RECORDS, true)) {
                return match ($name) { 'type' => 'factura', 'role' => 'cashier', 'kind' => 'sales' };
            }

            // A sale's settlements and returns belong to a proforma.
            $key = $name === 'sale' && preg_match('/settlements|returns/', $uri) ? 'proforma' : $name;
            $this->assertArrayHasKey($key, $theirs, "No test record for {{$name}} in {$uri}: add one to records().");

            $useMine = $mixParent && count($params) > 1 && $name === $first;

            return (string) ($useMine ? $mine[$key] : $theirs[$key]);
        }, $uri);
    }

    public function test_no_endpoint_lets_one_company_reach_another_companys_records(): void
    {
        $theirs = $this->records($this->theirOwner);
        $mine = $this->records($this->me);
        $before = $this->snapshot();
        $checked = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/app/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                foreach ([false, true] as $mixParent) {
                    $url = $this->urlFor($route->uri(), $theirs, $mine, $mixParent);
                    if ($url === null || isset($checked["{$method} {$url}"])) {
                        continue;
                    }

                    $status = $this->actingAs($this->me, 'sanctum')->json($method, '/'.$url, [])->status();
                    $this->assertContains($status, [403, 404], "{$method} /{$url} answered {$status} to another company.");
                    $checked["{$method} {$url}"] = true;
                }
            }
        }

        $this->assertGreaterThanOrEqual(50, count($checked));
        $this->assertSame($before, $this->snapshot(), 'Another company changed this company\'s data.');
    }

    public function test_ids_in_the_request_body_cannot_point_at_another_companys_records(): void
    {
        $theirs = $this->records($this->theirOwner);
        $mine = $this->records($this->me);
        $myProduct = Product::withoutGlobalScopes()->find($mine['product']);
        $as = fn () => $this->actingAs($this->me, 'sanctum');

        $sale = fn (array $extra, array $lineExtra = []) => $as()->postJson('/api/app/sales', array_merge([
            'type' => 'factura',
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => 'Counter sale',
            'lines' => [array_merge([
                'product_id' => $myProduct->id,
                'article' => $myProduct->article,
                'quantity' => 1,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ], $lineExtra)],
        ], $extra));

        // Their customer, their recargo rate, their payment method, their product on a line of mine.
        $sale(['customer_id' => $theirs['customer']])->assertStatus(422);
        $sale(['recargo_rate_id' => $theirs['recargoRate']])->assertStatus(422);
        $sale(['payment_status' => 'paid', 'payment_method_id' => $theirs['companyPaymentMethod']])->assertStatus(422);
        $this->assertNotSame(201, $sale([], ['product_id' => $theirs['product']])->status());

        // Purchases and the ledger: their supplier or product.
        $as()->postJson('/api/app/purchases', [
            'supplier_id' => $theirs['supplier'],
            'purchased_on' => now()->toDateString(),
            'lines' => [['product_id' => $myProduct->id, 'quantity' => 1, 'unit_cost' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        $as()->postJson('/api/app/purchases', [
            'supplier_id' => $mine['supplier'],
            'purchased_on' => now()->toDateString(),
            'lines' => [['product_id' => $theirs['product'], 'quantity' => 1, 'unit_cost' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.product_id');
        $as()->postJson('/api/app/ledger', [
            'supplier_id' => $theirs['supplier'], 'bill_date' => now()->toDateString(), 'description' => 'X', 'amount_cents' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        $as()->postJson("/api/app/ledger/{$mine['bill']}/payments", [
            'amount_cents' => 100, 'paid_on' => now()->toDateString(), 'payment_method_id' => $theirs['companyPaymentMethod'],
        ])->assertStatus(422);

        // A product of mine filed under their category or supplier.
        $as()->patchJson("/api/app/products/{$mine['product']}", ['category_id' => $theirs['category']])->assertStatus(422);
        $as()->patchJson("/api/app/products/{$mine['product']}", ['supplier_id' => $theirs['supplier']])->assertStatus(422);

        // A payment on my proforma that names a line of their proforma.
        $theirLine = DB::table('sales_document_lines')->where('sales_document_id', $theirs['proforma'])->value('id');
        $as()->postJson("/api/app/sales/{$mine['proforma']}/settle", [
            'payment_method_id' => $mine['companyPaymentMethod'],
            'lines' => [['line_id' => $theirLine, 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertStatus(422);
        $as()->postJson("/api/app/sales/{$mine['proforma']}/returns", ['lines' => [['line_id' => $theirLine, 'quantity' => 1]]])->assertStatus(422);

        // Bulk actions skip what is not mine, and touch nothing of theirs.
        $before = $this->snapshot();
        $as()->postJson('/api/app/products/bulk-delete', ['ids' => [$theirs['product']]])->assertOk()->assertJsonPath('data.deleted', 0);
        $as()->postJson('/api/app/product-images/bulk-delete', ['uuids' => [$theirs['productImage']]])->assertOk()->assertJsonPath('data.done', []);
        $as()->postJson('/api/app/products/labels/printed', ['items' => [['product_id' => $theirs['product']]]])->assertOk()->assertJsonPath('data.updated', 0);
        $as()->postJson('/api/app/sales/bulk-delete', ['ids' => [$theirs['sale']]])->assertOk()->assertJsonPath('data.done', []);
        $as()->postJson('/api/app/sales/bulk-restore', ['ids' => [$theirs['sale']]])->assertOk()->assertJsonPath('data.done', []);
        $this->assertSame($before, $this->snapshot());
    }
}

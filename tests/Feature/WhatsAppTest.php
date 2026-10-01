<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fatura.whatsapp_service.secret' => 'test-secret-1234567890',
            'fatura.whatsapp_service.auto_start' => false,
        ]);

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    public function test_the_session_name_is_chosen_by_the_server_not_the_client(): void
    {
        Http::fake(['*/instance/init' => Http::response(['status' => 'qrcode', 'qrcode' => 'data:x'])]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/whatsapp/init', ['instance_name' => 'someone_elses_session'])
            ->assertOk()
            ->assertJsonPath('data.instance_name', 'company_'.$this->company->id);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/instance/init') && $request['instanceName'] === 'company_'.$this->company->id);
        $this->assertSame('company_'.$this->company->id, $this->company->fresh()->whatsapp_instance_name);
    }

    public function test_every_service_call_carries_the_shared_secret(): void
    {
        Http::fake(['*/instance/init' => Http::response(['status' => 'qrcode'])]);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/whatsapp/init')->assertOk();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/instance/init') && $request->header('X-Service-Secret') === ['test-secret-1234567890']);
    }

    public function test_a_legacy_or_foreign_instance_name_cannot_send(): void
    {
        Http::fake();
        $this->company->forceFill([
            'whatsapp_instance_name' => 'someone_elses_session',
            'whatsapp_status' => 'connected',
        ])->save();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/whatsapp/test', ['phone' => '+923001112233'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_send_document_rejects_a_non_pdf_attachment(): void
    {
        Http::fake();
        $this->company->forceFill([
            'whatsapp_instance_name' => 'company_'.$this->company->id,
            'whatsapp_status' => 'connected',
        ])->save();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/whatsapp/send-document', [
                'sale_id' => 1,
                'number' => '+923001112233',
                'fileBase64' => base64_encode('<html>not a pdf</html>'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The attachment is not a valid PDF.');

        Http::assertNothingSent();
    }

    private function connect(): void
    {
        $this->company->forceFill([
            'whatsapp_instance_name' => 'company_'.$this->company->id,
            'whatsapp_status' => 'connected',
            'whatsapp_connected_phone' => '923001112233',
            'whatsapp_connected_name' => 'Rana Shop',
        ])->save();
    }

    public function test_status_is_disconnected_with_a_running_service_when_nothing_is_linked(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ok'])]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/whatsapp/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'disconnected')
            ->assertJsonPath('data.service_alive', true)
            ->assertJsonPath('data.qrcode', null);
    }

    public function test_status_says_so_when_the_service_is_not_running(): void
    {
        Http::fake(['*/health' => Http::response('', 500)]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/whatsapp/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'service_offline')
            ->assertJsonPath('data.service_alive', false);
    }

    public function test_status_follows_the_live_session_and_hands_over_the_qr_code(): void
    {
        $this->company->forceFill(['whatsapp_instance_name' => 'company_'.$this->company->id, 'whatsapp_status' => 'connecting'])->save();
        Http::fake(['*/instance/status/*' => Http::sequence()
            ->push(['status' => 'qrcode', 'qrcode' => 'data:image/png;base64,AAA'])
            ->push(['status' => 'connected', 'phone' => '34600111222', 'name' => 'Ana'])]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/whatsapp/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'qrcode')
            ->assertJsonPath('data.qrcode', 'data:image/png;base64,AAA');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/whatsapp/status')
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.connected_phone', '34600111222')
            ->assertJsonPath('data.qrcode', null);

        $this->assertSame('connected', $this->company->fresh()->whatsapp_status);
    }

    public function test_a_service_that_refuses_our_secret_is_reported_as_unavailable_not_as_a_bare_unauthorized(): void
    {
        Http::fake(['*/instance/init' => Http::response(['error' => 'Unauthorized.'], 401)]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/whatsapp/init')
            ->assertStatus(503)
            ->assertJsonPath('code', 'SERVICE_AUTH')
            ->assertJsonPath('message', 'WhatsApp is not set up correctly on the server. Please contact support.');
    }

    public function test_starting_while_the_service_is_down_gives_a_friendly_503(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/whatsapp/init')
            ->assertStatus(503)
            ->assertJsonPath('code', 'SERVICE_OFFLINE');
    }

    public function test_fresh_drops_the_old_session_before_asking_for_a_new_code(): void
    {
        $this->connect();
        Http::fake([
            '*/instance/logout/*' => Http::response(['success' => true]),
            '*/instance/init' => Http::response(['status' => 'qrcode', 'qrcode' => 'data:new']),
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/whatsapp/init', ['fresh' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'qrcode')
            ->assertJsonPath('data.qrcode', 'data:new')
            ->assertJsonPath('data.connected_phone', null);

        Http::assertSentCount(2);
    }

    public function test_a_test_message_with_no_number_goes_to_the_linked_whatsapp(): void
    {
        $this->connect();
        Http::fake(['*/message/send-text' => Http::response(['success' => true, 'messageId' => 'abc'])]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/whatsapp/test')
            ->assertOk()
            ->assertJsonPath('data.recipient', '923001112233');

        Http::assertSent(fn (Request $request) => $request['number'] === '923001112233' && $request['instanceName'] === 'company_'.$this->company->id);
    }

    public function test_disconnecting_frees_the_session_and_clears_the_number(): void
    {
        $this->connect();
        Http::fake(['*/instance/logout/*' => Http::response(['success' => true])]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/whatsapp/logout')
            ->assertOk()
            ->assertJsonPath('data.status', 'disconnected');

        $fresh = $this->company->fresh();
        $this->assertNull($fresh->whatsapp_instance_name);
        $this->assertNull($fresh->whatsapp_connected_phone);
    }

    public function test_the_admin_sees_one_companys_whatsapp_and_can_disconnect_it(): void
    {
        $this->connect();
        $admin = User::factory()->superAdmin()->create();

        Http::fake([
            '*/instance/status/*' => Http::response(['status' => 'connected', 'phone' => '923001112233', 'name' => 'Rana Shop']),
            '*/instance/logout/*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/companies/{$this->company->id}/whatsapp")
            ->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.instance_name', 'company_'.$this->company->id);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/companies/{$this->company->id}/whatsapp/disconnect")
            ->assertOk()
            ->assertJsonPath('data.status', 'disconnected');

        $this->assertNull($this->company->fresh()->whatsapp_instance_name);

        // A company owner is not the platform admin.
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/admin/companies/{$this->company->id}/whatsapp")
            ->assertForbidden();
    }
}

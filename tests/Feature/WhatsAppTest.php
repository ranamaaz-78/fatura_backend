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
}

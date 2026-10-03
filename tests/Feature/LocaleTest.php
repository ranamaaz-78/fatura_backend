<?php

namespace Tests\Feature;

use App\Mail\AccountReadyMail;
use App\Mail\PasswordResetMail;
use App\Models\Application;
use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Plan;
use App\Models\PrintTemplate;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    /** A request nobody is signed in for: the answer is the same sentence in whatever language was asked. */
    private function unauthenticatedMessage(array $headers = [], string $query = ''): string
    {
        return (string) $this->withHeaders($headers)
            ->getJson('/api/app/dashboard'.$query)
            ->assertStatus(401)
            ->json('message');
    }

    public function test_a_visitor_gets_english_unless_they_ask_for_spanish(): void
    {
        $english = 'Unauthenticated.';
        $spanish = 'No has iniciado sesión.';

        $this->assertSame($english, $this->unauthenticatedMessage());
        $this->assertSame($spanish, $this->unauthenticatedMessage(['Accept-Language' => 'es']));
        $this->assertSame($spanish, $this->unauthenticatedMessage(['Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8']));
        $this->assertSame($spanish, $this->unauthenticatedMessage([], '?lang=es'));
        // A language we do not speak falls back to English, and Spanish does not leak into the next request.
        $this->assertSame($english, $this->unauthenticatedMessage(['Accept-Language' => 'fr-FR']));
        $this->assertSame($english, $this->unauthenticatedMessage([], '?lang=fr'));
    }

    public function test_the_query_beats_the_header(): void
    {
        $this->assertSame('No has iniciado sesión.', $this->unauthenticatedMessage(['Accept-Language' => 'en'], '?lang=es'));
    }

    public function test_a_signed_in_owner_follows_the_company_language_not_the_browser(): void
    {
        $this->company->forceFill(['locale' => 'es'])->save();

        $this->withHeaders(['Accept-Language' => 'en'])
            ->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company/locale', ['locale' => 'es'])
            ->assertOk()
            ->assertJsonPath('message', 'Idioma guardado.');
    }

    public function test_me_reports_the_language_the_person_sees(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/me')->assertJsonPath('data.locale', 'en');

        $this->company->forceFill(['locale' => 'es'])->save();

        $this->actingAs($this->owner->fresh(), 'sanctum')
            ->getJson('/api/app/me')
            ->assertJsonPath('data.locale', 'es')
            ->assertJsonPath('data.company.locale', 'es');
    }

    public function test_the_owner_changes_the_company_language_and_untouched_starter_text_follows(): void
    {
        $this->assertContains('Cash', CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('name')->all());
        $this->assertSame('Thank you for your business.', PrintTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->where('type', 'factura')->value('footer_notes'));

        // The company wrote its own footer on the quotation and added its own payment method.
        PrintTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->where('type', 'quotation')->update(['footer_notes' => 'Our own terms.']);
        CompanyPaymentMethod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Bizum', 'is_active' => true, 'sort_order' => 9]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company/locale', ['locale' => 'es'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'es');

        $names = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('name')->all();
        $this->assertContains('Efectivo', $names);
        $this->assertContains('Tarjeta', $names);
        $this->assertContains('Transferencia bancaria', $names);
        $this->assertContains('Bizum', $names);
        $this->assertNotContains('Cash', $names);

        $templates = PrintTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('footer_notes', 'type');
        $this->assertSame('Gracias por confiar en nosotros.', $templates['factura']);
        $this->assertSame('Este albarán no es una factura.', $templates['albaran']);
        $this->assertSame('Our own terms.', $templates['quotation']);

        // And back again.
        $this->actingAs($this->owner->fresh(), 'sanctum')
            ->patchJson('/api/app/company/locale', ['locale' => 'en'])
            ->assertOk();

        $this->assertContains('Cash', CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('name')->all());
        $this->assertSame('Thank you for your business.', PrintTemplate::withoutGlobalScopes()->where('company_id', $this->company->id)->where('type', 'factura')->value('footer_notes'));
    }

    public function test_only_a_supported_language_is_accepted_and_only_the_owner_may_change_it(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/company/locale', ['locale' => 'fr'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locale');

        $staff = User::factory()->businessAdmin()->create(['company_id' => $this->company->id, 'role' => 'staff']);

        $this->actingAs($staff, 'sanctum')
            ->patchJson('/api/app/company/locale', ['locale' => 'es'])
            ->assertForbidden();

        $this->assertSame('en', $this->company->fresh()->locale);
    }

    public function test_a_company_still_in_setup_can_choose_its_language(): void
    {
        $company = Company::factory()->incomplete()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $owner = User::factory()->businessAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($owner, 'sanctum')->getJson('/api/app/dashboard')->assertStatus(403);

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/app/company/locale', ['locale' => 'es'])
            ->assertOk();

        $this->assertSame('es', $company->fresh()->locale);
    }

    public function test_the_platform_admin_has_a_language_of_their_own(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/me/locale', ['locale' => 'es'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'es');

        $this->actingAs($admin->fresh(), 'sanctum')
            ->getJson('/api/auth/me')
            ->assertJsonPath('data.locale', 'es');

        $this->actingAs($admin->fresh(), 'sanctum')
            ->patchJson('/api/admin/me/locale', ['locale' => 'es'])
            ->assertJsonPath('message', 'Idioma guardado.');
    }

    public function test_converting_an_application_in_spanish_makes_a_spanish_company_and_mail(): void
    {
        Mail::fake();
        $plan = Plan::factory()->create();
        $application = Application::factory()->pending()->create();

        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')
            ->postJson("/api/admin/applications/{$application->id}/convert", [
                'company_name' => 'Taller Rivas',
                'company_email' => 'info@rivas.test',
                'owner_name' => 'Marta Rivas',
                'owner_email' => 'marta@rivas.test',
                'plan_id' => $plan->id,
                'locale' => 'es',
            ])
            ->assertCreated();

        $company = Company::where('email', 'info@rivas.test')->firstOrFail();
        $this->assertSame('es', $company->locale);
        $this->assertContains('Efectivo', CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $company->id)->pluck('name')->all());

        Mail::assertSent(AccountReadyMail::class, fn (AccountReadyMail $mail) => $mail->hasTo('marta@rivas.test') && $mail->locale === 'es');
    }

    public function test_an_application_remembers_the_language_it_was_made_in(): void
    {
        $this->assertSame('es', app()->setLocale('es') ?? 'es');
        $application = Application::factory()->create(['locale' => 'es']);

        $this->assertSame('es', $application->fresh()->locale);
    }

    public function test_the_account_ready_email_reads_in_spanish(): void
    {
        $company = Company::factory()->create(['locale' => 'es']);
        $subscription = Subscription::factory()->create(['company_id' => $company->id]);
        $owner = User::factory()->businessAdmin()->create(['company_id' => $company->id, 'name' => 'Marta']);

        $html = (new AccountReadyMail($owner, $company, $subscription, 'https://app.test/set-password?token=x'))->locale('es')->render();

        $this->assertStringContainsString('Crea tu contraseña', $html);
        $this->assertStringContainsString('Tu plan', $html);
        $this->assertStringNotContainsString('Set your password', $html);
    }

    public function test_the_reset_email_uses_the_users_own_language(): void
    {
        Mail::fake();
        $this->company->forceFill(['locale' => 'es'])->save();

        $this->postJson('/api/auth/forgot-password', ['email' => $this->owner->email])->assertOk();

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->locale === 'es');
    }

    public function test_plans_speak_spanish_when_a_spanish_text_was_written(): void
    {
        Plan::factory()->create([
            'name' => 'Starter',
            'name_es' => 'Inicial',
            'description' => 'Everything you need.',
            'description_es' => 'Todo lo que necesitas.',
            'features' => ['Unlimited invoices'],
            'features_es' => ['Facturas ilimitadas'],
            'is_active' => true,
        ]);
        Plan::factory()->create(['name' => 'Growth', 'is_active' => true, 'sort_order' => 2]);

        $english = collect($this->getJson('/api/public/plans')->assertOk()->json('data'))->firstWhere('name_es', 'Inicial');
        $this->assertSame('Starter', $english['name']);

        $rows = $this->withHeaders(['Accept-Language' => 'es'])->getJson('/api/public/plans')->assertOk()->json('data');
        $names = collect($rows)->pluck('name')->all();
        $this->assertContains('Inicial', $names);
        // No Spanish written: the English text is shown.
        $this->assertContains('Growth', $names);
        $starter = collect($rows)->firstWhere('name', 'Inicial');
        $this->assertSame('Todo lo que necesitas.', $starter['description']);
        $this->assertSame(['Facturas ilimitadas'], $starter['features']);
    }

    public function test_the_admin_can_save_a_plan_with_spanish_text(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/plans', [
                'name' => 'Pro', 'name_es' => 'Profesional', 'price' => 49, 'interval' => 'month',
                'description_es' => 'Para negocios en crecimiento.', 'features_es' => ['Usuarios ilimitados'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name_es', 'Profesional')
            ->assertJsonPath('data.features_es.0', 'Usuarios ilimitados');
    }

    public function test_a_subscription_keeps_the_spanish_plan_wording_it_started_with(): void
    {
        $plan = Plan::factory()->create(['name' => 'Starter', 'name_es' => 'Inicial', 'features' => ['A'], 'features_es' => ['B']]);
        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')
            ->postJson("/api/admin/companies/{$this->company->id}/subscriptions", ['plan_id' => $plan->id, 'periods' => 1])
            ->assertCreated()
            ->assertJsonPath('data.plan_name', 'Starter');

        $plan->update(['name_es' => 'Cambiado']);

        $this->withHeaders(['Accept-Language' => 'es'])
            ->actingAs(User::factory()->superAdmin()->create(), 'sanctum')
            ->getJson("/api/admin/companies/{$this->company->id}")
            ->assertJsonPath('data.active_subscription.plan_name', 'Inicial');
    }

    public function test_every_english_string_the_backend_translates_has_a_spanish_version(): void
    {
        $es = json_decode((string) file_get_contents(base_path('lang/es.json')), true);
        $missing = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        foreach ($views as $file) {
            $files[] = $file->getPathname();
        }

        $string = "(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")";

        foreach ($files as $path) {
            if (! str_ends_with($path, '.php') || str_ends_with($path, 'welcome.blade.php')) {
                continue;
            }

            preg_match_all('/(?<![\w>:$])(?:__|trans_choice)\(\s*'.$string.'/s', (string) file_get_contents($path), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $key = isset($match[2]) && $match[2] !== ''
                    ? str_replace(['\\"', '\\n', '\\$', '\\\\'], ['"', "\n", '$', '\\'], $match[2])
                    : str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]);

                if ($key !== '' && ! array_key_exists($key, $es)) {
                    $missing[$key] = basename($path);
                }
            }
        }

        $this->assertSame([], $missing, 'Add these to lang/es.json: '.json_encode($missing, JSON_UNESCAPED_UNICODE));
    }
}

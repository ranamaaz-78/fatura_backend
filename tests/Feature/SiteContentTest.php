<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SiteFaq;
use App\Models\SitePage;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteContentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    public function test_the_website_starts_with_the_questions_and_legal_pages_it_already_had_in_both_languages(): void
    {
        $this->assertGreaterThanOrEqual(8, SiteFaq::count());
        $this->assertSame(['privacy', 'terms'], SitePage::orderBy('slug')->pluck('slug')->all());

        foreach (SitePage::all() as $page) {
            $this->assertNotSame('', trim($page->body_en), $page->slug);
            $this->assertNotSame('', trim((string) $page->body_es), $page->slug);
            $this->assertStringContainsString('## ', $page->body_en);
        }

        $faq = SiteFaq::orderBy('sort_order')->first();
        $this->assertNotEmpty($faq->question_es);
        $this->assertNotSame($faq->question_en, $faq->question_es);
    }

    public function test_the_public_website_gets_the_content_in_the_visitors_language(): void
    {
        SiteFaq::query()->delete();
        SiteFaq::create(['question_en' => 'How do I pay?', 'answer_en' => 'Per period.', 'question_es' => '¿Cómo pago?', 'answer_es' => 'Por periodo.', 'sort_order' => 1]);
        SiteFaq::create(['question_en' => 'Only English', 'answer_en' => 'No Spanish yet.', 'sort_order' => 2]);
        SiteFaq::create(['question_en' => 'Hidden', 'answer_en' => 'Not shown.', 'sort_order' => 3, 'is_published' => false]);

        $en = $this->withHeaders(['Accept-Language' => 'en'])->getJson('/api/public/site-content')->assertOk();
        $this->assertSame('en', $en->json('data.locale'));
        $this->assertSame(['How do I pay?', 'Only English'], collect($en->json('data.faqs'))->pluck('question')->all());

        $es = $this->withHeaders(['Accept-Language' => 'es'])->getJson('/api/public/site-content')->assertOk();
        $this->assertSame('es', $es->json('data.locale'));
        // Spanish where there is Spanish, English where there is not yet; unpublished never.
        $this->assertSame(['¿Cómo pago?', 'Only English'], collect($es->json('data.faqs'))->pluck('question')->all());
        $this->assertSame('Por periodo.', $es->json('data.faqs.0.answer'));

        // The page's own language: ?lang beats the header.
        $this->withHeaders(['Accept-Language' => 'en'])->getJson('/api/public/site-content?lang=es')->assertJsonPath('data.locale', 'es');

        $this->assertNotEmpty($es->json('data.pages.privacy.title'));
        $this->assertNotEmpty($es->json('data.pages.terms.body'));
    }

    public function test_the_admin_manages_the_questions_in_both_languages_and_their_order(): void
    {
        SiteFaq::query()->delete();
        $admin = $this->admin();

        $first = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/site-content/faqs', [
            'question_en' => 'First?', 'answer_en' => 'One.', 'question_es' => '¿Primera?', 'answer_es' => 'Uno.',
        ])->assertCreated()->json('data');

        $second = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/site-content/faqs', [
            'question_en' => 'Second?', 'answer_en' => 'Two.', 'question_es' => '', 'answer_es' => '',
        ])->assertCreated()->json('data');

        $this->assertSame(1, $first['sort_order']);
        $this->assertSame(2, $second['sort_order']);
        // A blank Spanish box is stored as "no Spanish yet".
        $this->assertNull($second['question_es']);

        // English is required.
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/site-content/faqs', ['question_en' => '', 'answer_en' => 'x'])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/site-content/faqs/'.$second['id'], [
            'question_en' => 'Second?', 'answer_en' => 'Two, updated.', 'question_es' => '¿Segunda?', 'answer_es' => 'Dos.', 'is_published' => false,
        ])->assertOk()->assertJsonPath('data.is_published', false)->assertJsonPath('data.question_es', '¿Segunda?');

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/site-content/faqs/reorder', ['ids' => [$second['id'], $first['id']]])
            ->assertOk()
            ->assertJsonPath('data.faqs.0.id', $second['id']);

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/admin/site-content/faqs/'.$first['id'])->assertOk();
        $this->assertSame(1, SiteFaq::count());

        // The admin sees unpublished questions too.
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/site-content')->assertOk()->assertJsonCount(1, 'data.faqs');
    }

    public function test_the_admin_saves_a_legal_page_in_both_languages_and_the_website_shows_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/site-content/pages/privacy', [
            'title_en' => 'Privacy policy',
            'title_es' => 'Política de privacidad',
            'body_en' => "Intro.\n\n## What we collect\nYour name.",
            'body_es' => "Introducción.\n\n## Qué recogemos\nTu nombre.",
        ])->assertOk();

        $this->withHeaders(['Accept-Language' => 'es'])->getJson('/api/public/site-content')
            ->assertJsonPath('data.pages.privacy.title', 'Política de privacidad')
            ->assertJsonPath('data.pages.privacy.body', "Introducción.\n\n## Qué recogemos\nTu nombre.");
        $this->withHeaders(['Accept-Language' => 'en'])->getJson('/api/public/site-content')
            ->assertJsonPath('data.pages.privacy.body', "Intro.\n\n## What we collect\nYour name.");

        // Spanish left empty: the English is shown.
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/site-content/pages/terms', ['title_en' => 'Terms', 'title_es' => '', 'body_en' => 'Only English.', 'body_es' => ''])->assertOk();
        $this->withHeaders(['Accept-Language' => 'es'])->getJson('/api/public/site-content')->assertJsonPath('data.pages.terms.body', 'Only English.');

        // Only the two pages that exist.
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/site-content/pages/cookies', ['title_en' => 'x', 'body_en' => 'x'])->assertNotFound();
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/site-content/pages/terms', ['title_en' => '', 'body_en' => ''])->assertStatus(422);
    }

    public function test_only_the_platform_admin_can_manage_the_content_and_anyone_can_read_it(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $owner = User::factory()->businessAdmin()->create(['company_id' => $company->id]);

        $this->getJson('/api/admin/site-content')->assertUnauthorized();
        $this->actingAs($owner, 'sanctum')->getJson('/api/admin/site-content')->assertForbidden();
        $this->actingAs($owner, 'sanctum')->postJson('/api/admin/site-content/faqs', ['question_en' => 'x', 'answer_en' => 'x'])->assertForbidden();
        $this->actingAs($owner, 'sanctum')->putJson('/api/admin/site-content/pages/terms', ['title_en' => 'x', 'body_en' => 'x'])->assertForbidden();

        // The website needs no login.
        $this->getJson('/api/public/site-content')->assertOk();
    }

    public function test_a_question_can_be_hidden_and_shown_again_without_resending_its_text(): void
    {
        $admin = $this->admin();
        $faq = SiteFaq::create(['question_en' => 'Q?', 'answer_en' => 'A.', 'question_es' => '¿P?', 'answer_es' => 'R.', 'sort_order' => 1]);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/admin/site-content/faqs/{$faq->id}", ['is_published' => false])
            ->assertOk()->assertJsonPath('data.is_published', false)->assertJsonPath('data.question_es', '¿P?');
        $this->assertNotContains($faq->id, collect($this->getJson('/api/public/site-content')->json('data.faqs'))->pluck('id')->all());

        $this->actingAs($admin, 'sanctum')->patchJson("/api/admin/site-content/faqs/{$faq->id}", ['is_published' => true])->assertOk();
        $this->assertContains($faq->id, collect($this->getJson('/api/public/site-content')->json('data.faqs'))->pluck('id')->all());

        // A text that is sent still cannot be emptied.
        $this->actingAs($admin, 'sanctum')->patchJson("/api/admin/site-content/faqs/{$faq->id}", ['question_en' => ''])->assertStatus(422);
    }
}

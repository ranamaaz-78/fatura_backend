<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteFaq;
use App\Models\SitePage;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What the public website says in its FAQ and its legal pages, in both languages. One screen of the admin panel
 * manages all of it; the website reads it back in whichever language the visitor is using.
 */
class SiteContentController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->success($this->payload());
    }

    public function storeFaq(Request $request): JsonResponse
    {
        $data = $this->validatedFaq($request);
        $data['sort_order'] = ((int) SiteFaq::max('sort_order')) + 1;

        $faq = SiteFaq::create($data);

        return $this->success($this->faq($faq), __('Question added.'), 201);
    }

    public function updateFaq(Request $request, SiteFaq $faq): JsonResponse
    {
        // The show/hide switch sends only is_published, so on an update every field is optional.
        $faq->update($this->validatedFaq($request, partial: true));

        return $this->success($this->faq($faq->fresh()), __('Question saved.'));
    }

    public function destroyFaq(SiteFaq $faq): JsonResponse
    {
        $faq->delete();

        return $this->success([], __('Question deleted.'));
    }

    /** The questions in the order the website should show them: the ids, first to last. */
    public function reorderFaqs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists('site_faqs', 'id')],
        ]);

        DB::transaction(function () use ($data) {
            foreach (array_values(array_unique($data['ids'])) as $position => $id) {
                SiteFaq::whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return $this->success($this->payload());
    }

    public function updatePage(Request $request, string $slug): JsonResponse
    {
        abort_unless(in_array($slug, SitePage::SLUGS, true), 404);

        $data = $request->validate([
            'title_en' => ['required', 'string', 'max:160'],
            'title_es' => ['nullable', 'string', 'max:160'],
            'body_en' => ['required', 'string', 'max:60000'],
            'body_es' => ['nullable', 'string', 'max:60000'],
        ]);

        $page = SitePage::updateOrCreate(['slug' => $slug], $data);

        return $this->success($this->page($page), __('Page saved.'));
    }

    /** @return array<string, mixed> */
    private function validatedFaq(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        $data = $request->validate([
            'question_en' => [...$required, 'string', 'max:500'],
            'answer_en' => [...$required, 'string', 'max:5000'],
            'question_es' => ['nullable', 'string', 'max:500'],
            'answer_es' => ['nullable', 'string', 'max:5000'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        // A blank box means "no Spanish yet", so the English shows instead.
        foreach (['question_es', 'answer_es'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = filled($data[$key]) ? $data[$key] : null;
            } elseif (! $partial) {
                $data[$key] = null;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $pages = SitePage::all()->keyBy('slug');

        return [
            'faqs' => SiteFaq::orderBy('sort_order')->orderBy('id')->get()->map(fn (SiteFaq $faq) => $this->faq($faq))->values(),
            'pages' => collect(SitePage::SLUGS)->mapWithKeys(fn (string $slug) => [
                $slug => isset($pages[$slug]) ? $this->page($pages[$slug]) : null,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function faq(SiteFaq $faq): array
    {
        return [
            'id' => $faq->id,
            'question_en' => $faq->question_en,
            'answer_en' => $faq->answer_en,
            'question_es' => $faq->question_es,
            'answer_es' => $faq->answer_es,
            'sort_order' => $faq->sort_order,
            'is_published' => $faq->is_published,
        ];
    }

    /** @return array<string, mixed> */
    private function page(SitePage $page): array
    {
        return [
            'slug' => $page->slug,
            'title_en' => $page->title_en,
            'title_es' => $page->title_es,
            'body_en' => $page->body_en,
            'body_es' => $page->body_es,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }
}

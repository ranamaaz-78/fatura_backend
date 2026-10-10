<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\SiteFaq;
use App\Models\SitePage;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The FAQ and legal pages of the public website, in the language the visitor is using (the request's language,
 * set from ?lang or the browser's header). Whatever has no translation yet comes back in English.
 */
class SiteContentController extends Controller
{
    use ApiResponse;

    public function show(): JsonResponse
    {
        $locale = app()->getLocale();
        $pages = SitePage::all()->keyBy('slug');

        return $this->success([
            'locale' => $locale,
            'faqs' => SiteFaq::where('is_published', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (SiteFaq $faq) => [
                    'id' => $faq->id,
                    'question' => $faq->question($locale),
                    'answer' => $faq->answer($locale),
                ])
                ->values(),
            'pages' => collect(SitePage::SLUGS)
                ->filter(fn (string $slug) => isset($pages[$slug]))
                ->mapWithKeys(fn (string $slug) => [$slug => [
                    'title' => $pages[$slug]->title($locale),
                    'body' => $pages[$slug]->body($locale),
                    'updated_at' => $pages[$slug]->updated_at?->toIso8601String(),
                ]]),
        ]);
    }
}

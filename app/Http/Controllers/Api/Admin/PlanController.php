<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PlanInterval;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $plans = Plan::withCount('subscriptions')->orderBy('sort_order')->orderBy('id')->get();

        return $this->success(PlanResource::collection($plans));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);

        $plan = DB::transaction(function () use ($data) {
            $this->claimFeatured($data);

            return Plan::create($data);
        });

        return $this->success(new PlanResource($plan), __('Plan created.'), 201);
    }

    public function show(Plan $plan): JsonResponse
    {
        return $this->success(new PlanResource($plan));
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $data = $this->validated($request, $plan);

        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $plan->id);
        }

        DB::transaction(function () use ($plan, $data) {
            $this->claimFeatured($data, $plan->id);
            $plan->update($data);
        });

        return $this->success(new PlanResource($plan->fresh()), __('Plan updated.'));
    }

    public function toggle(Plan $plan): JsonResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return $this->success(
            new PlanResource($plan->fresh()),
            $plan->is_active ? __('Plan is now public.') : __('Plan is now hidden.'),
        );
    }

    public function destroy(Plan $plan): JsonResponse
    {
        if ($plan->subscriptions()->withoutGlobalScopes()->exists()) {
            return $this->error(__('This plan is in use. Hide it instead of deleting it.'), 422);
        }

        $plan->delete();

        return $this->success([], __('Plan deleted.'));
    }

    /** Only one plan can be featured: turning it on for this plan turns it off everywhere else. */
    private function claimFeatured(array $data, ?int $exceptId = null): void
    {
        if (! empty($data['is_featured'])) {
            Plan::query()
                ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
                ->where('is_featured', true)
                ->update(['is_featured' => false]);
        }
    }

    private function validated(Request $request, ?Plan $plan = null): array
    {
        $required = $plan === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'name_es' => ['nullable', 'string', 'max:255'],
            'description_es' => ['nullable', 'string', 'max:2000'],
            'features_es' => ['nullable', 'array', 'max:30'],
            'features_es.*' => ['string', 'max:255'],
            'price' => [$required, 'numeric', 'min:0', 'max:99999999'],
            'original_price' => [
                'nullable', 'numeric', 'min:0', 'max:99999999',
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $plan) {
                    $price = $request->input('price', $plan?->price);

                    if ($value !== null && is_numeric($price) && (float) $value <= (float) $price) {
                        $fail(__('The original price must be higher than the price.'));
                    }
                },
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'interval' => [$required, Rule::in(PlanInterval::values())],
            'features' => ['nullable', 'array', 'max:30'],
            'features.*' => ['string', 'max:255'],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'max_invoices' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'is_featured' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
    }

    private function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'plan';
        $slug = $base;
        $i = 2;

        while (Plan::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}

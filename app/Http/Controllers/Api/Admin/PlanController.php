<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PlanInterval;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $plan = Plan::create($data);

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

        $plan->update($data);

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

    private function validated(Request $request, ?Plan $plan = null): array
    {
        $required = $plan === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => [$required, 'numeric', 'min:0', 'max:99999999'],
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

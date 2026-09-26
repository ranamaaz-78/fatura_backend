<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentMethodController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $methods = PaymentMethod::orderBy('sort_order')->orderBy('id')->get();

        return $this->success(PaymentMethodResource::collection($methods));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = Str::slug($data['slug'] ?? $data['name']);

        $method = PaymentMethod::create($data);

        return $this->success(new PaymentMethodResource($method), __('Payment method created.'), 201);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $data = $this->validated($request, $paymentMethod);

        if (isset($data['slug'])) {
            $data['slug'] = Str::slug($data['slug']);
        }

        $paymentMethod->update($data);

        return $this->success(new PaymentMethodResource($paymentMethod->fresh()), __('Payment method updated.'));
    }

    public function toggle(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->update(['is_active' => ! $paymentMethod->is_active]);

        return $this->success(new PaymentMethodResource($paymentMethod->fresh()), __('Saved.'));
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->delete();

        return $this->success([], __('Payment method deleted.'));
    }

    private function validated(Request $request, ?PaymentMethod $method = null): array
    {
        $required = $method === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('payment_methods', 'slug')->ignore($method?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
    }
}

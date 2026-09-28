<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyPaymentMethodResource;
use App\Models\CompanyPaymentMethod;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyPaymentMethodController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $methods = CompanyPaymentMethod::query()
            ->withCount(['documents', 'settlements'])
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->success(CompanyPaymentMethodResource::collection($methods));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $companyId = (int) $request->user()->company_id;

        $method = CompanyPaymentMethod::create([
            ...$data,
            'company_id' => $companyId,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? ((int) CompanyPaymentMethod::query()->max('sort_order') + 1),
        ]);

        return $this->success(
            new CompanyPaymentMethodResource($method->loadCount(['documents', 'settlements'])),
            __('Payment method created.'),
            201,
        );
    }

    public function update(Request $request, CompanyPaymentMethod $companyPaymentMethod): JsonResponse
    {
        $companyPaymentMethod->update($this->validated($request, $companyPaymentMethod));

        return $this->success(
            new CompanyPaymentMethodResource($companyPaymentMethod->fresh()->loadCount(['documents', 'settlements'])),
            __('Payment method updated.'),
        );
    }

    public function updateActive(Request $request, CompanyPaymentMethod $companyPaymentMethod): JsonResponse
    {
        $active = $request->validate([
            'is_active' => ['required', 'boolean'],
        ])['is_active'];

        $companyPaymentMethod->update(['is_active' => $active]);

        return $this->success(
            new CompanyPaymentMethodResource($companyPaymentMethod->fresh()->loadCount(['documents', 'settlements'])),
            __('Saved.'),
        );
    }

    public function destroy(CompanyPaymentMethod $companyPaymentMethod): JsonResponse
    {
        if ($companyPaymentMethod->documents()->exists() || $companyPaymentMethod->settlements()->exists()) {
            return $this->error(__('This method is used on a document. Turn it off instead.'), 422);
        }

        $companyPaymentMethod->delete();

        return $this->success([], __('Payment method deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?CompanyPaymentMethod $method = null): array
    {
        $required = $method === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [
                $required,
                'string',
                'max:80',
                Rule::unique('company_payment_methods', 'name')
                    ->where('company_id', $request->user()->company_id)
                    ->ignore($method?->id),
            ],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
    }
}

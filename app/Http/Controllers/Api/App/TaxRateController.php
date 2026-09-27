<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaxRateResource;
use App\Models\TaxRate;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxRateController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $rates = TaxRate::query()->orderByDesc('rate')->orderBy('name')->get();

        return $this->success(TaxRateResource::collection($rates));
    }

    public function store(Request $request): JsonResponse
    {
        $rate = TaxRate::create($this->validated($request));

        return $this->success(new TaxRateResource($rate), __('IVA rate added.'), 201);
    }

    public function update(Request $request, TaxRate $taxRate): JsonResponse
    {
        $taxRate->update($this->validated($request, $taxRate));

        return $this->success(new TaxRateResource($taxRate->fresh()), __('IVA rate updated.'));
    }

    public function destroy(TaxRate $taxRate): JsonResponse
    {
        if (TaxRate::query()->count() <= 1) {
            return $this->error(__('Keep at least one IVA rate.'), 422);
        }

        $taxRate->delete();

        return $this->success([], __('IVA rate removed.'));
    }

    /** @return array{name: string, rate: float} */
    private function validated(Request $request, ?TaxRate $taxRate = null): array
    {
        $companyId = $request->user()->company_id;

        if ($request->exists('rate')) {
            $request->merge(['rate' => round((float) $request->input('rate'), 2)]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'rate' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                Rule::unique('tax_rates', 'rate')->where('company_id', $companyId)->ignore($taxRate?->id),
            ],
        ]);
    }
}

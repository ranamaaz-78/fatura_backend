<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaxRateResource;
use App\Models\RecargoRate;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecargoRateController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $rates = RecargoRate::query()->orderByDesc('rate')->orderBy('name')->get();

        return $this->success(TaxRateResource::collection($rates));
    }

    public function store(Request $request): JsonResponse
    {
        $rate = RecargoRate::create($this->validated($request));

        return $this->success(new TaxRateResource($rate), __('Recargo rate added.'), 201);
    }

    public function update(Request $request, RecargoRate $recargoRate): JsonResponse
    {
        $recargoRate->update($this->validated($request, $recargoRate));

        return $this->success(new TaxRateResource($recargoRate->fresh()), __('Recargo rate updated.'));
    }

    /** Unlike IVA, nothing needs a recargo rate, so the list may be emptied. */
    public function destroy(RecargoRate $recargoRate): JsonResponse
    {
        $recargoRate->delete();

        return $this->success([], __('Recargo rate removed.'));
    }

    /** @return array{name: string, rate: float} */
    private function validated(Request $request, ?RecargoRate $recargoRate = null): array
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
                Rule::unique('recargo_rates', 'rate')->where('company_id', $companyId)->ignore($recargoRate?->id),
            ],
        ]);
    }
}

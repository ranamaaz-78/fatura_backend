<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanySettingsController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        abort_unless($company, 404);

        return $this->success(new CompanyResource($company));
    }

    public function update(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        abort_unless($company, 404);

        $company->update($this->validated($request));

        return $this->success(new CompanyResource($company->fresh()), __('Company details saved.'));
    }

    /**
     * @return array{name: string, email: string, phone: ?string, whatsapp: ?string, address: ?string, city: ?string, country: ?string, currency: string}
     */
    private function validated(Request $request): array
    {
        if ($request->exists('currency')) {
            $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Company::CURRENCIES)],
        ]);

        foreach (['phone', 'whatsapp', 'address', 'city', 'country'] as $field) {
            $data[$field] = ($data[$field] ?? '') !== '' ? $data[$field] : null;
        }

        return $data;
    }
}

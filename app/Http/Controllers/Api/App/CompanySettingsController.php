<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

        $company->update($this->validated($request, $company));

        return $this->success(new CompanyResource($company->fresh()), __('Company details saved.'));
    }

    /**
     * Every detail is compulsory: they print on every document, and the workspace stays closed until they are in.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Company $company): array
    {
        if ($request->exists('currency')) {
            $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email', 'max:180'],
            // One field for NIF, NIE or CIF.
            'tax_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9\s.\-\/]{3,30}$/'],
            'phone' => ['required', 'string', 'max:40'],
            'whatsapp' => ['required', 'string', 'max:40'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:80'],
            'postal_code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9][A-Za-z0-9\s\-]{1,14}$/'],
            'country' => ['required', 'string', 'max:80'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Company::CURRENCIES)],
        ], [
            'tax_id.regex' => __('Enter a valid NIF, NIE or CIF.'),
            'postal_code.regex' => __('Enter a valid postal code.'),
        ]);

        // The logo is uploaded on its own; the details cannot be saved without one.
        $validator->after(function ($validator) use ($company) {
            if (blank($company->logo_path)) {
                $validator->errors()->add('logo', __('Upload your company logo.'));
            }
        });

        $data = $validator->validate();

        return $data;
    }
}

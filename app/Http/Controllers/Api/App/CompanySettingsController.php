<?php

namespace App\Http\Controllers\Api\App;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyLocale;
use App\Support\Locales;
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

    /** The company's language: its panel, documents, emails and WhatsApp messages. Only the owner chooses it. */
    public function updateLocale(Request $request, CompanyLocale $locales): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::BusinessAdmin, 403);

        $company = $request->user()->company;
        abort_unless($company, 404);

        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locales::SUPPORTED)],
        ]);

        return $this->success(new CompanyResource($locales->change($company, $data['locale'])), __('Language saved.'));
    }

    /** The language printed on documents, independent of the panel's. Null follows the panel. Only the owner chooses it. */
    public function updateDocumentLocale(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::BusinessAdmin, 403);

        $company = $request->user()->company;
        abort_unless($company, 404);

        $data = $request->validate([
            'document_locale' => ['nullable', 'string', Rule::in(Locales::SUPPORTED)],
        ]);

        $company->update(['document_locale' => $data['document_locale'] ?? null]);

        return $this->success(new CompanyResource($company->fresh()), __('Language saved.'));
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

        return $validator->validate();
    }
}

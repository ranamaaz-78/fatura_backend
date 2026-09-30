<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:2000'],
            'plan_id' => ['nullable', Rule::exists('plans', 'id')->where('is_active', true)],
            'plan_slug' => ['nullable', 'string', 'max:255'],
            // Honeypot: real browsers leave this empty.
            'website' => ['nullable', 'string', 'max:255'],
            // Emailed to the applicant first; without it nothing is saved.
            'otp' => ['required', 'digits:6'],
        ];
    }
}

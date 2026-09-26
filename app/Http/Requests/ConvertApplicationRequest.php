<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['required', 'email:rfc', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:32'],
            'company_whatsapp' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'size:3'],

            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'owner_phone' => ['nullable', 'string', 'max:32'],
            'owner_whatsapp' => ['nullable', 'string', 'max:32'],

            'plan_id' => ['required', Rule::exists('plans', 'id')],
            'periods' => ['nullable', 'integer', 'min:1', 'max:36'],
            'starts_at' => ['nullable', 'date'],
            'subscription_notes' => ['nullable', 'string', 'max:2000'],

            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
            'payment_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'owner_email.unique' => __('A user with this email already exists.'),
        ];
    }
}

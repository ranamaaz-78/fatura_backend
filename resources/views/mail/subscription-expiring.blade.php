@component('mail::message')
# {{ __('Renewal reminder') }}

{{ __('Hi :name, the :plan subscription for **:company** ends on :date — that is :days day(s) from now.', [
    'name' => $owner->name,
    'plan' => $subscription->plan_name,
    'company' => $company->name,
    'date' => $subscription->ends_at->toFormattedDateString(),
    'days' => $daysLeft,
]) }}

{{ __('To keep your account active, contact us to renew:') }}

- {{ __('Email') }}: {{ $supportEmail }}
- {{ __('WhatsApp') }}: {{ $supportWhatsapp }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
@endcomponent

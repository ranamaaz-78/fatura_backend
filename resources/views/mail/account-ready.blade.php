@component('mail::message')
# {{ __('Welcome to :app, :name', ['app' => config('app.name'), 'name' => $owner->name]) }}

{{ __('Your account for **:company** is ready. Choose a password to sign in.', ['company' => $company->name]) }}

@component('mail::button', ['url' => $url])
{{ __('Set your password') }}
@endcomponent

{{ __('This link expires in 48 hours.') }}

@component('mail::panel')
**{{ $subscription->plan_name }}** — {{ $subscription->plan_currency }} {{ number_format((float) $subscription->plan_price, 2) }} / {{ $subscription->plan_interval->value }}
{{ __('Active until :date', ['date' => $subscription->ends_at->toFormattedDateString()]) }}
@endcomponent

{{ __('Need help? Write to :email.', ['email' => $supportEmail]) }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
@endcomponent

@extends('mail.layout')

@section('title', __('Your account is ready'))
@section('preview', __('Congratulations! Your :app account for :company is ready. Set your password to get started.', ['app' => config('app.name'), 'company' => $company->name]))
@section('eyebrow', __('Application approved'))
@section('heading')
    {{ __('Congratulations!') }}<br><span style="color:#4edea3;">{{ __('Your account is ready.') }}</span>
@endsection
@section('subheading', __('Welcome to :app, :name', ['app' => config('app.name'), 'name' => $owner->name]))

@section('content')
    <p style="margin:0 0 24px 0;">
        {{ __('Your account for :company is ready. Click the button below to set your password and sign in.', ['company' => $company->name]) }}
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center" bgcolor="#004ac6" style="background-color:#004ac6;border-radius:12px;">
                <a href="{{ $url }}" target="_blank" style="display:inline-block;padding:16px 32px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:16px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">
                    {{ __('Set your password') }} &nbsp;&rarr;
                </a>
            </td>
        </tr>
    </table>
    <p style="margin:12px 0 0 0;font-size:13px;color:#6b7280;">{{ __('This link expires in 48 hours.') }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eff4ff" style="margin-top:28px;background-color:#eff4ff;border:1px solid #dbe1ff;border-radius:14px;">
        <tr>
            <td style="padding:18px 20px;">
                <div style="font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:#004ac6;">{{ __('Your plan') }}</div>
                <div style="margin-top:6px;font-size:18px;font-weight:800;letter-spacing:-.01em;color:#0b1c30;">
                    {{ $subscription->plan_name }}
                    <span style="font-size:14px;font-weight:500;color:#434655;">&nbsp;{{ $subscription->plan_currency }} {{ \App\Support\Fmt::money((float) $subscription->plan_price) }} / {{ __($subscription->plan_interval->value) }}</span>
                </div>
                <div style="margin-top:4px;font-size:13.5px;color:#434655;">
                    <span style="color:#007d55;">&#10003;</span> {{ __('Active until :date', ['date' => \App\Support\Fmt::date($subscription->ends_at)]) }}
                </div>
            </td>
        </tr>
    </table>

    <p style="margin:24px 0 0 0;font-size:12.5px;line-height:20px;color:#6b7280;">
        {{ __('If the button does not work, copy and paste this link into your browser:') }}<br>
        <a href="{{ $url }}" style="color:#004ac6;word-break:break-all;">{{ $url }}</a>
    </p>
@endsection

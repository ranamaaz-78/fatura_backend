@extends('mail.layout')

@section('title', $created ? __('Your access is ready') : __('Your password was changed'))
@section('preview', __('Your login for :company is ready.', ['company' => $company?->name]))
@section('eyebrow', $created ? __('Team access') : __('Password changed'))
@section('heading')
    @if ($created)
        {{ __('Welcome to the team,') }}<br><span style="color:#4edea3;">{{ $member->name }}</span>
    @else
        {{ __('Your password was changed.') }}
    @endif
@endsection
@section('subheading', __(':company on :app', ['company' => $company?->name, 'app' => config('app.name')]))

@section('content')
    <p style="margin:0 0 20px 0;">
        {{ $created
            ? __('The owner of :company gave you access. Sign in with the details below.', ['company' => $company?->name])
            : __('The owner of :company set a new password for you. Sign in with the details below.', ['company' => $company?->name]) }}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eff4ff" style="background-color:#eff4ff;border:1px solid #dbe1ff;border-radius:14px;">
        <tr>
            <td style="padding:18px 20px;">
                <div style="font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:#004ac6;">{{ __('Email') }}</div>
                <div style="margin-top:4px;font-size:16px;font-weight:700;color:#0b1c30;word-break:break-all;">{{ $member->email }}</div>
                <div style="margin-top:14px;font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:#004ac6;">{{ __('Password') }}</div>
                <div style="margin-top:4px;font-size:16px;font-weight:700;color:#0b1c30;font-family:'Courier New',monospace;word-break:break-all;">{{ $password }}</div>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">
        <tr>
            <td align="center" bgcolor="#004ac6" style="background-color:#004ac6;border-radius:12px;">
                <a href="{{ $loginUrl }}" target="_blank" style="display:inline-block;padding:16px 32px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:16px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">
                    {{ __('Sign in') }} &nbsp;&rarr;
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0 0;font-size:13px;line-height:20px;color:#6b7280;">
        {{ __('For your security, change this password after you sign in (Settings, then Password).') }}
    </p>
@endsection

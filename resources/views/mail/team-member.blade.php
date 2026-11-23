@extends('mail.layout')

@section('title', $created ? __('Your access is ready') : __('Choose a new password'))
@section('preview', $created
    ? __('Your login for :company is ready.', ['company' => $company?->name])
    : __('Use this link to choose a new password for :company.', ['company' => $company?->name]))
@section('eyebrow', $created ? __('Team access') : __('New password'))
@section('heading')
    @if ($created)
        {{ __('Welcome to the team,') }}<br><span style="color:#4edea3;">{{ $member->name }}</span>
    @else
        {{ __('Choose a new password.') }}
    @endif
@endsection
@section('subheading', __(':company on :app', ['company' => $company?->name, 'app' => config('app.name')]))

@section('content')
    <p style="margin:0 0 20px 0;">
        {{ $created
            ? __('The owner of :company gave you access. Click the button below to choose your password and sign in.', ['company' => $company?->name])
            : __('The owner of :company asked for your password to be renewed. Your old password no longer works. Click the button below to choose a new one.', ['company' => $company?->name]) }}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eff4ff" style="background-color:#eff4ff;border:1px solid #dbe1ff;border-radius:14px;">
        <tr>
            <td style="padding:18px 20px;">
                <div style="font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:#004ac6;">{{ __('Email') }}</div>
                <div style="margin-top:4px;font-size:16px;font-weight:700;color:#0b1c30;word-break:break-all;">{{ $member->email }}</div>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">
        <tr>
            <td align="center" bgcolor="#004ac6" style="background-color:#004ac6;border-radius:12px;">
                <a href="{{ $setPasswordUrl }}" target="_blank" style="display:inline-block;padding:16px 32px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:16px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">
                    {{ __('Set your password') }} &nbsp;&rarr;
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0 0;font-size:13px;line-height:20px;color:#6b7280;">
        {{ __('The link works once and is valid for :hours hours. If it has expired, ask the owner of :company to send a new one.', ['hours' => $validHours, 'company' => $company?->name]) }}
    </p>
@endsection

@extends('mail.layout')

@section('title', __('Reset your password'))
@section('preview', __('Use this link to choose a new password. It is valid for :minutes minutes.', ['minutes' => $minutes]))
@section('eyebrow', __('Password reset'))
@section('heading')
    {{ __('Forgot your password?') }}<br><span style="color:#4edea3;">{{ __('Let us get you back in.') }}</span>
@endsection
@section('subheading', __('Hi :name, we received a request to reset the password for :email.', ['name' => $user->name, 'email' => $user->email]))

@section('content')
    <p style="margin:0 0 24px 0;">
        {{ __('Click the button below to choose a new password for your :app account.', ['app' => config('app.name')]) }}
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center" bgcolor="#004ac6" style="background-color:#004ac6;border-radius:12px;">
                <a href="{{ $url }}" target="_blank" style="display:inline-block;padding:16px 32px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:16px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">
                    {{ __('Reset password') }} &nbsp;&rarr;
                </a>
            </td>
        </tr>
    </table>
    <p style="margin:12px 0 0 0;font-size:13px;color:#6b7280;">{{ __('This link expires in :minutes minutes and can be used once.', ['minutes' => $minutes]) }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:28px;">
        <tr>
            <td style="border-left:3px solid #c3c6d7;padding:2px 0 2px 14px;font-size:13.5px;line-height:21px;color:#6b7280;">
                <strong style="color:#434655;">{{ __('Didn\'t ask for this?') }}</strong>
                {{ __('You can ignore this email. Your password stays the same until you open the link and choose a new one.') }}
            </td>
        </tr>
    </table>

    <p style="margin:24px 0 0 0;font-size:12.5px;line-height:20px;color:#6b7280;">
        {{ __('If the button does not work, copy and paste this link into your browser:') }}<br>
        <a href="{{ $url }}" style="color:#004ac6;word-break:break-all;">{{ $url }}</a>
    </p>
@endsection

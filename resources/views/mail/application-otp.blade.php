@extends('mail.layout')

@section('title', __('Verify your email'))
@section('preview', __('Your verification code is :code. It is valid for :minutes minutes.', ['code' => $code, 'minutes' => $minutes]))
@section('eyebrow', __('Email verification'))
@section('heading')
    {{ __('Confirm your email') }}<br><span style="color:#4edea3;">{{ __('to send your application.') }}</span>
@endsection

@section('content')
    <p style="margin:0 0 6px 0;font-size:16px;font-weight:700;color:#0b1c30;">{{ __('Hi :name,', ['name' => $name]) }}</p>
    <p style="margin:0 0 24px 0;">
        {{ __('Thanks for applying to :app. Enter this code on the application page to confirm your email and send your application:', ['app' => config('app.name')]) }}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eff4ff" style="background-color:#eff4ff;border:1px solid #dbe1ff;border-radius:16px;">
        <tr>
            <td align="center" style="padding:24px 12px 20px 12px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        @foreach (str_split($code) as $digit)
                            <td align="center" width="46" height="58" bgcolor="#ffffff" style="width:46px;height:58px;background-color:#ffffff;border:1px solid #c3c6d7;border-radius:12px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:30px;font-weight:700;color:#004ac6;">{{ $digit }}</td>
                            @if (! $loop->last)
                                <td width="7" style="width:7px;font-size:0;line-height:0;">&nbsp;</td>
                            @endif
                        @endforeach
                    </tr>
                </table>
                <div style="margin-top:14px;font-size:13px;color:#434655;">
                    {{ __('Valid for :minutes minutes · single use', ['minutes' => $minutes]) }}
                </div>
            </td>
        </tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">
        <tr>
            <td style="border-left:3px solid #c3c6d7;padding:2px 0 2px 14px;font-size:13.5px;line-height:21px;color:#6b7280;">
                <strong style="color:#434655;">{{ __('Didn\'t request this?') }}</strong>
                {{ __('If you did not start this application, you can ignore this email. Nothing will be sent.') }}
            </td>
        </tr>
    </table>
@endsection

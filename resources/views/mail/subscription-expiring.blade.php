@php
    $waDigits = preg_replace('/\D+/', '', (string) $supportWhatsapp);
@endphp
@extends('mail.layout')

@section('title', __('Renewal reminder'))
@section('preview', __('Your :plan subscription ends on :date. Renew to keep your account active.', ['plan' => $subscription->plan_name, 'date' => \App\Support\Fmt::date($subscription->ends_at)]))
@section('dot', '#f59e0b')
@section('eyebrow', __('Renewal reminder'))
@section('heading')
    {{ __('Your subscription ends in') }}<br><span style="color:#4edea3;">{{ trans_choice(':count day|:count days', $daysLeft) }}.</span>
@endsection

@section('content')
    <p style="margin:0 0 6px 0;font-size:16px;font-weight:700;color:#0b1c30;">{{ __('Hi :name,', ['name' => $owner->name]) }}</p>
    <p style="margin:0 0 24px 0;">
        {{ trans_choice('The :plan subscription for :company ends on :date — that is :count day from now.|The :plan subscription for :company ends on :date — that is :count days from now.', $daysLeft, [
            'plan' => $subscription->plan_name,
            'company' => $company->name,
            'date' => \App\Support\Fmt::date($subscription->ends_at),
        ]) }}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eff4ff" style="background-color:#eff4ff;border:1px solid #dbe1ff;border-radius:14px;">
        <tr>
            <td style="padding:18px 20px;">
                <div style="font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:#004ac6;">{{ __('Your plan') }}</div>
                <div style="margin-top:6px;font-size:18px;font-weight:800;letter-spacing:-.01em;color:#0b1c30;">{{ $subscription->plan_name }}</div>
                <div style="margin-top:4px;font-size:13.5px;color:#434655;">{{ __('Ends on :date', ['date' => \App\Support\Fmt::date($subscription->ends_at)]) }}</div>
            </td>
        </tr>
    </table>

    <p style="margin:28px 0 14px 0;">{{ __('To keep your account active, contact us to renew:') }}</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center" bgcolor="#004ac6" style="background-color:#004ac6;border-radius:12px;">
                <a href="mailto:{{ $supportEmail }}" style="display:inline-block;padding:15px 28px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">{{ __('Email us') }}</a>
            </td>
            @if ($waDigits !== '')
                <td width="12" style="width:12px;font-size:0;">&nbsp;</td>
                <td align="center" style="border:1.5px solid #004ac6;border-radius:12px;">
                    <a href="https://wa.me/{{ $waDigits }}" style="display:inline-block;padding:13.5px 26px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#004ac6;text-decoration:none;border-radius:12px;">{{ __('WhatsApp') }}</a>
                </td>
            @endif
        </tr>
    </table>
    <p style="margin:14px 0 0 0;font-size:13px;color:#6b7280;">
        {{ $supportEmail }}@if ($waDigits !== '') &nbsp;·&nbsp; {{ $supportWhatsapp }}@endif
    </p>
@endsection

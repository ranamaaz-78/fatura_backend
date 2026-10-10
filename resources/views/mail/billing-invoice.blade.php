@php
    $locale = $invoice->locale;
    $money = fn ($value) => \App\Support\Fmt::currency((float) $value, $invoice->currency, $locale);
    $day = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->locale($locale)->isoFormat('D MMM YYYY') : '';

    $plan = $invoice->data['plan'];
    $to = $invoice->data['billed_to'];
    $until = $invoice->data['coverage']['to'];
    $renewal = $invoice->kind === \App\Models\BillingInvoice::KIND_RENEWAL;
    $ownerName = $invoice->company?->owner?->name ?: $to['name'];

    $units = match ($plan['interval']) {
        'year' => trans_choice('{1} :count year|[2,*] :count years', $invoice->periods),
        'quarter' => trans_choice('{1} :count quarter|[2,*] :count quarters', $invoice->periods),
        default => trans_choice('{1} :count month|[2,*] :count months', $invoice->periods),
    };
    $cycle = match ($plan['interval']) {
        'year' => __('yearly'),
        'quarter' => __('quarterly'),
        default => __('monthly'),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ __('Your invoice :number · :app', ['number' => $invoice->number, 'app' => $issuer['name']]) }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f8f9ff;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f8f9ff;">{{ __('The invoice for your payment is attached as a PDF.') }}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f8f9ff" style="background-color:#f8f9ff;">
<tr>
<td align="center" style="padding:28px 16px 36px 16px;">

<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-radius:18px;overflow:hidden;border:1px solid #dbe1ff;background-color:#ffffff;">

    {{-- Navy hero --}}
    <tr>
        <td bgcolor="#0b1c30" style="background-color:#0b1c30;background-image:radial-gradient(380px 220px at 90% 0%,rgba(37,99,235,.45),rgba(11,28,48,0) 70%);padding:26px 32px 28px 32px;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
            {{-- Text mark, like the other emails: an embedded image shows up as a separate attachment in many mail apps. --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td align="center" width="38" height="38" bgcolor="#004ac6" style="width:38px;height:38px;background-color:#004ac6;border-radius:10px;font-size:15px;font-weight:bold;letter-spacing:.5px;color:#ffffff;">YK</td>
                    <td style="padding-left:11px;font-size:19px;font-weight:bold;letter-spacing:-.02em;color:#ffffff;">{{ $issuer['name'] }}</td>
                </tr>
            </table>
            <h1 style="margin:22px 0 6px 0;font-size:24px;line-height:1.2;letter-spacing:-.02em;color:#ffffff;">
                {{ $renewal ? __('Thank you for renewing') : __('Thank you for your purchase') }}
            </h1>
            <p style="margin:0;font-size:14px;line-height:1.5;color:#cbd5e1;">
                {{ $invoice->isPaid() ? __('Your subscription is active. The invoice is attached as a PDF.') : __('Your subscription is active. The invoice is attached as a PDF, pending payment.') }}
            </p>
        </td>
    </tr>

    <tr>
        <td style="padding:28px 32px 6px 32px;font-family:'Segoe UI',Helvetica,Arial,sans-serif;color:#0b1c30;">

            <p style="margin:0 0 20px 0;font-size:15px;line-height:1.65;color:#334155;">
                {{ __('Hi :name,', ['name' => $ownerName]) }}
                {!! __('we have activated the account for :company with the plan you purchased. Here is a summary of your purchase.', ['company' => '<b style="color:#0b1c30;">'.e($to['name']).'</b>']) !!}
            </p>

            {{-- Summary --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e5eeff;border-radius:14px;overflow:hidden;font-size:14px;">
                <tr>
                    <td style="padding:12px 16px;color:#64748b;border-bottom:1px solid #e5eeff;">{{ __('Invoice no.') }}</td>
                    <td align="right" style="padding:12px 16px;font-weight:bold;border-bottom:1px solid #e5eeff;">{{ $invoice->number }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;color:#64748b;border-bottom:1px solid #e5eeff;">{{ __('Plan') }}</td>
                    <td align="right" style="padding:12px 16px;font-weight:bold;border-bottom:1px solid #e5eeff;">{{ $plan['name'] }} · {{ $cycle }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;color:#64748b;border-bottom:1px solid #e5eeff;">{{ __('Period purchased') }}</td>
                    <td align="right" style="padding:12px 16px;font-weight:bold;border-bottom:1px solid #e5eeff;">{{ $units }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;color:#64748b;border-bottom:1px solid #e5eeff;">{{ __('Service active until') }}</td>
                    <td align="right" style="padding:12px 16px;font-weight:bold;border-bottom:1px solid #e5eeff;">{{ $day($until) }}</td>
                </tr>
                <tr>
                    <td bgcolor="#eff4ff" style="padding:14px 16px;background-color:#eff4ff;color:#64748b;">{{ $invoice->isPaid() ? __('Total paid') : __('Total to pay') }}</td>
                    <td align="right" bgcolor="#eff4ff" style="padding:14px 16px;background-color:#eff4ff;font-weight:bold;font-size:20px;letter-spacing:-.02em;color:#004ac6;">{{ $money($invoice->total) }}</td>
                </tr>
            </table>

            {{-- Attachment note --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;border:1px dashed #b9c7e6;border-radius:12px;">
                <tr>
                    <td width="62" style="padding:12px 0 12px 14px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                            <td align="center" valign="bottom" width="38" height="44" bgcolor="#e11d48" style="width:38px;height:44px;background-color:#e11d48;border-radius:6px;color:#ffffff;font-size:11px;font-weight:bold;padding-bottom:6px;">PDF</td>
                        </tr></table>
                    </td>
                    <td style="padding:12px 14px 12px 8px;">
                        <div style="font-size:14px;font-weight:bold;">{{ $invoice->number }}.pdf</div>
                        <div style="font-size:12px;color:#64748b;margin-top:2px;">{{ __('Your invoice, attached to this email (A4)') }}</div>
                    </td>
                </tr>
            </table>

            {{-- Button --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:26px;">
                <tr><td align="center">
                    <a href="{{ $loginUrl }}" style="display:inline-block;background-color:#004ac6;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:14px 28px;border-radius:12px;">{{ __('Go to my account') }}</a>
                </td></tr>
                <tr><td align="center" style="padding-top:10px;font-size:12.5px;color:#64748b;">{{ __('Use the email you registered with.') }}</td></tr>
            </table>

            {{-- Help --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">
                <tr><td bgcolor="#f8f9ff" style="background-color:#f8f9ff;border-radius:14px;padding:14px 16px;font-size:13px;line-height:1.6;color:#475569;">
                    <b style="color:#0b1c30;">{{ __('Need help or a correction on the invoice?') }}</b><br>
                    {{ __('Reply to this email, write to :email or message us on WhatsApp at :phone.', ['email' => $support['email'], 'phone' => $support['whatsapp']]) }}
                </td></tr>
            </table>
        </td>
    </tr>

    <tr>
        <td align="center" style="padding:22px 32px 26px 32px;font-family:'Segoe UI',Helvetica,Arial,sans-serif;font-size:12px;line-height:1.7;color:#64748b;">
            <b style="color:#334155;">{{ $issuer['name'] }}</b><br>{{ __('Keep the attached invoice for your accounting.') }}
        </td>
    </tr>
</table>

</td>
</tr>
</table>
</body>
</html>

@php
    $locale = $invoice->locale;
    $money = fn ($value) => \App\Support\Fmt::currency((float) $value, $invoice->currency, $locale);
    $day = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->locale($locale)->isoFormat('D MMM YYYY') : '';

    $to = $invoice->data['billed_to'];
    $plan = $invoice->data['plan'];
    $coverage = $invoice->data['coverage'];
    $payment = $invoice->data['payment'];
    $interval = $plan['interval'];

    $words = match ($interval) {
        'year' => ['qty' => __('Years'), 'unit' => __('Price / year'), 'cycle' => __('yearly')],
        'quarter' => ['qty' => __('Quarters'), 'unit' => __('Price / quarter'), 'cycle' => __('quarterly')],
        default => ['qty' => __('Months'), 'unit' => __('Price / month'), 'cycle' => __('monthly')],
    };
    $periodNote = match ($interval) {
        'year' => __('(1 period = 1 year)'),
        'quarter' => __('(1 period = 1 quarter)'),
        default => __('(1 period = 1 month)'),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
<style>
    @page { size: A4; margin: 0; }
    body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #0b1c30; font-size: 9.5pt; }
    table { border-collapse: collapse; }
    .muted { color: #64748b; }
    .label { font-size: 7pt; letter-spacing: 1px; text-transform: uppercase; color: #64748b; font-weight: bold; }
    .txt { font-size: 8.8pt; line-height: 1.6; color: #475569; }
    .name { font-size: 11pt; font-weight: bold; margin-bottom: 3px; }
    .th { font-size: 7pt; letter-spacing: 1px; text-transform: uppercase; color: #64748b; padding: 8px 0; border-bottom: 2px solid #0b1c30; text-align: left; }
    .td { padding: 14px 0; border-bottom: 1px solid #e5eeff; vertical-align: top; font-size: 9.5pt; }
    .r { text-align: right; }
    .box { border: 1px solid #e5eeff; padding: 12px 14px; vertical-align: top; }
</style>
</head>
<body>

{{-- Header --}}
<table width="100%" bgcolor="#0b1c30" style="background-color:#0b1c30;">
    <tr>
        <td style="padding:38px 46px 30px 46px;" valign="top">
            <img src="{{ $logo }}" alt="{{ $issuer['name'] }}" style="height:38px;">
        </td>
        <td style="padding:38px 46px 30px 46px; text-align:right; color:#ffffff;" valign="top">
            <div style="font-size:8pt; letter-spacing:2px; text-transform:uppercase; color:#cbd5e1; font-weight:bold;">{{ __('Invoice') }}</div>
            <div style="font-size:18pt; font-weight:bold; margin-top:3px;">{{ $invoice->number }}</div>
        </td>
    </tr>
</table>

<div style="padding:28px 46px 0 46px;">

    {{-- Parties --}}
    <table width="100%">
        <tr>
            <td width="50%" valign="top" style="padding-right:20px;">
                <div class="label">{{ __('Issued by') }}</div>
                <div class="name" style="margin-top:5px;">{{ $issuer['name'] }}</div>
                <div class="txt">
                    @if (filled($issuer['tax_id'])) NIF/CIF: {{ $issuer['tax_id'] }}<br>@endif
                    @if (filled($issuer['address'])) {{ $issuer['address'] }}<br>@endif
                    {{ $support['email'] }}<br>
                    {{ $support['whatsapp'] }}
                </div>
            </td>
            <td width="50%" valign="top">
                <div class="label">{{ __('Billed to') }}</div>
                <div class="name" style="margin-top:5px;">{{ $to['name'] }}</div>
                <div class="txt">
                    @if (filled($to['tax_id'])) NIF/CIF: {{ $to['tax_id'] }}<br>@endif
                    @if (filled($to['address'])) {{ $to['address'] }}<br>@endif
                    {{ $to['email'] }}
                </div>
            </td>
        </tr>
    </table>

    {{-- Meta --}}
    <table width="100%" style="margin-top:22px; border:1px solid #e5eeff;">
        <tr>
            <td width="30%" style="padding:10px 14px; border-right:1px solid #e5eeff;">
                <div class="label">{{ __('Issue date') }}</div>
                <div style="font-size:10pt; font-weight:bold; margin-top:3px;">{{ $day($invoice->issued_at) }}</div>
            </td>
            <td width="43%" style="padding:10px 14px; border-right:1px solid #e5eeff;">
                <div class="label">{{ __('Service period') }}</div>
                <div style="font-size:10pt; font-weight:bold; margin-top:3px;">{{ $day($coverage['from']) }} – {{ $day($coverage['to']) }}</div>
            </td>
            <td width="27%" style="padding:10px 14px;">
                <div class="label">{{ __('Status') }}</div>
                <div style="margin-top:3px;">
                    @if ($invoice->isPaid())
                        <span style="background:#e6f9f1; color:#007d55; font-weight:bold; font-size:9pt; padding:2px 9px;">{{ __('Paid') }}</span>
                    @else
                        <span style="background:#fff4e0; color:#b45309; font-weight:bold; font-size:9pt; padding:2px 9px;">{{ __('Pending') }}</span>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    {{-- Line item --}}
    <table width="100%" style="margin-top:24px;">
        <tr>
            <td class="th" width="46%">{{ __('Description') }}</td>
            <td class="th r" width="14%">{{ $words['qty'] }}</td>
            <td class="th r" width="20%">{{ $words['unit'] }}</td>
            <td class="th r" width="20%">{{ __('Amount') }}</td>
        </tr>
        <tr>
            <td class="td">
                <div style="font-weight:bold;">{{ __('Subscription — :plan plan', ['plan' => $plan['name']]) }}</div>
                <div class="muted" style="font-size:8.3pt; margin-top:3px; line-height:1.5;">{{ __('Access to the invoicing and stock management platform') }}</div>
            </td>
            <td class="td r">{{ $invoice->periods }}</td>
            <td class="td r">{{ $money($invoice->unit_price) }}</td>
            <td class="td r">{{ $money($invoice->total) }}</td>
        </tr>
    </table>

    {{-- Total --}}
    <table width="100%" style="margin-top:18px;">
        <tr>
            <td width="55%"></td>
            <td width="45%" bgcolor="#eff4ff" style="background-color:#eff4ff; padding:12px 16px;">
                <table width="100%">
                    <tr>
                        <td style="font-weight:bold; font-size:10pt;">{{ __('Total') }}</td>
                        <td class="r" style="font-weight:bold; font-size:17pt; color:#004ac6;">{{ $money($invoice->total) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Payment and subscription --}}
    <table width="100%" style="margin-top:26px;">
        <tr>
            <td width="49%" class="box">
                <div class="label">{{ __('Payment') }}</div>
                <div class="txt" style="margin-top:5px;">
                    @if ($payment)
                        @if (filled($payment['method']))<b style="color:#0b1c30;">{{ __('Method') }}</b>: {{ $payment['method'] }}<br>@endif
                        @if (filled($payment['reference']))<b style="color:#0b1c30;">{{ __('Reference') }}</b>: {{ $payment['reference'] }}<br>@endif
                        <b style="color:#0b1c30;">{{ __('Paid on') }}</b>: {{ $day($payment['paid_at']) }}
                    @else
                        {{ __('This invoice is pending payment. Write to us to arrange it.') }}
                    @endif
                </div>
            </td>
            <td width="2%"></td>
            <td width="49%" class="box">
                <div class="label">{{ __('Subscription') }}</div>
                <div class="txt" style="margin-top:5px;">
                    <b style="color:#0b1c30;">{{ __('Plan') }}</b>: {{ $plan['name'] }} · {{ $words['cycle'] }}<br>
                    <b style="color:#0b1c30;">{{ __('Periods') }}</b>: {{ $invoice->periods }} {{ $periodNote }}<br>
                    <b style="color:#0b1c30;">{{ __('Next renewal') }}</b>: {{ $day($coverage['to']) }}
                </div>
            </td>
        </tr>
    </table>
</div>

{{-- Footer --}}
<div style="position:absolute; left:0; right:0; bottom:0; border-top:1px solid #e5eeff; padding:14px 46px 22px 46px;">
    <table width="100%">
        <tr>
            <td class="muted" style="font-size:8pt; line-height:1.6;">
                <b style="color:#334155;">{{ $issuer['name'] }}</b><br>{{ __('Thank you for trusting us.') }}
            </td>
            <td class="muted r" style="font-size:8pt; line-height:1.6;">
                {{ __('Questions about this invoice?') }}<br><b style="color:#334155;">{{ $support['email'] }} · {{ $support['whatsapp'] }}</b>
            </td>
        </tr>
    </table>
</div>

</body>
</html>

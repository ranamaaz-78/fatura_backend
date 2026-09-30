<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>@yield('title')</title>
</head>
{{-- Brand tokens mirror the web app: navy #0b1c30, primary #004ac6, mint #4edea3, page #f8f9ff --}}
<body style="margin:0;padding:0;background-color:#f8f9ff;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f8f9ff;">@yield('preview')</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f8f9ff" style="background-color:#f8f9ff;">
<tr>
<td align="center" style="padding:32px 16px 40px 16px;">

    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">

        {{-- Brand row --}}
        <tr>
            <td style="padding:0 4px 18px 4px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td align="center" width="36" height="36" bgcolor="#004ac6" style="width:36px;height:36px;background-color:#004ac6;border-radius:10px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;font-weight:800;letter-spacing:.5px;color:#ffffff;">YK</td>
                        <td style="padding-left:10px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:19px;font-weight:700;letter-spacing:-.02em;color:#0b1c30;">{{ config('app.name') }}</td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="border-radius:20px;overflow:hidden;border:1px solid #dbe1ff;background-color:#ffffff;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">

                    {{-- Navy hero, same as the website --}}
                    <tr>
                        <td bgcolor="#0b1c30" style="background-color:#0b1c30;background-image:radial-gradient(420px 220px at 90% 0%,rgba(37,99,235,.45),rgba(11,28,48,0) 70%);padding:36px 36px 34px 36px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border:1px solid #2b3d55;background-color:#14273f;border-radius:999px;padding:6px 14px;font-size:12.5px;font-weight:600;color:#dbe1ff;">
                                        <span style="color:{{ trim($__env->yieldContent('dot', '#4edea3')) }};font-size:14px;">&#9679;</span>&nbsp; @yield('eyebrow')
                                    </td>
                                </tr>
                            </table>
                            <div style="margin-top:20px;font-size:32px;line-height:38px;font-weight:800;letter-spacing:-.03em;color:#ffffff;">
                                @yield('heading')
                            </div>
                            @hasSection('subheading')
                                <div style="margin-top:12px;font-size:15px;line-height:23px;color:#cbd5e1;">
                                    @yield('subheading')
                                </div>
                            @endif
                        </td>
                    </tr>

                    {{-- Content --}}
                    <tr>
                        <td style="padding:32px 36px 36px 36px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:24px;color:#434655;">
                            @yield('content')
                        </td>
                    </tr>

                </table>
            </td>
        </tr>

        {{-- Footer --}}
        <tr>
            <td align="center" style="padding:24px 16px 0 16px;font-family:'Geist','Segoe UI',Helvetica,Arial,sans-serif;font-size:12.5px;line-height:20px;color:#6b7280;">
                {{ __('Need help? Write to :email.', ['email' => $supportEmail]) }}<br>
                <span style="color:#9aa3b2;">&copy; {{ date('Y') }} {{ config('app.name') }} &nbsp;·&nbsp; {{ __('Invoicing, stock and barcodes in one app') }}</span>
            </td>
        </tr>

    </table>

</td>
</tr>
</table>
</body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('mail.reset.subject') }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background-color:#ffffff;border-radius:8px;padding:32px;">
                <tr>
                    <td>
                        <h1 style="margin:0 0 8px;font-size:20px;line-height:28px;">{{ __('mail.reset.greeting', ['name' => $user->name]) }}</h1>
                        <p style="margin:0 0 24px;font-size:14px;line-height:22px;">{{ __('mail.reset.intro') }}</p>

                        <p style="margin:0 0 24px;">
                            <a href="{{ $resetUrl }}"
                               style="display:inline-block;background-color:#18181b;color:#ffffff;text-decoration:none;font-size:14px;line-height:22px;padding:10px 18px;border-radius:6px;">
                                {{ __('mail.reset.action') }}
                            </a>
                        </p>

                        <p style="margin:0 0 16px;font-size:12px;line-height:18px;color:#71717a;">{{ __('mail.reset.expiry') }}</p>
                        <p style="margin:0 0 24px;font-size:12px;line-height:18px;color:#71717a;">{{ __('mail.reset.ignore') }}</p>

                        <p style="margin:0 0 4px;font-size:12px;line-height:18px;color:#71717a;">
                            <a href="{{ $resetUrl }}" style="color:#71717a;word-break:break-all;">{{ $resetUrl }}</a>
                        </p>
                        <p style="margin:12px 0 0;font-size:12px;line-height:18px;color:#71717a;">
                            {{ __('mail.reset.footer', ['email' => $user->email]) }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

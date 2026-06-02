<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#25263A;font-family:Inter,Arial,sans-serif;color:#E4E4E7;">
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center" style="padding:40px 20px;">
            <table width="600" cellpadding="0" cellspacing="0" style="background:#2C2E48;border-radius:16px;overflow:hidden;max-width:600px;">
                <tr>
                    <td align="center" style="padding:40px 30px;background:linear-gradient(180deg,#E433E1 0%,#420089 100%);">
                        <img src="https://payyigi.com/payigi-logo-bg.png" alt="PayYigi" width="120" />
                        <h1 style="margin:20px 0 0;color:#fff;font-size:30px;font-weight:700;">
                            {{ $title }}
                        </h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:40px 30px;">
                        <p style="font-size:16px;line-height:1.8;margin:0;color:#E4E4E7;">
                            {!! nl2br(e($message)) !!}
                        </p>
                        @if(!empty($button_url))
                        <div style="margin-top:32px;text-align:center;">
                            <a href="{{ $button_url }}" style="display:inline-block;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:600;color:#fff;background:linear-gradient(180deg,#E433E1 0%,#420089 100%);">
                                {{ $button_text }}
                            </a>
                        </div>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:30px;text-align:center;background:#1F2033;">
                        <p style="margin:0 0 10px;font-size:14px;color:#A0A0B0;">
                            Fast. Secure. Reliable Crypto-to-Naira Exchange.
                        </p>
                        <p style="margin:0;font-size:13px;color:#7D7D91;">
                            {{ date('Y') }} PayYigi. All rights reserved.
                        </p>
                        <div style="margin-top:15px;">
                            <a href="https://payyigi.com" style="color:#E433E1;text-decoration:none;margin:0 8px;">Website</a>
                            <a href="https://payyigi.com/blog" style="color:#E433E1;text-decoration:none;margin:0 8px;">Blog</a>
                            <a href="https://payyigi.com/contact" style="color:#E433E1;text-decoration:none;margin:0 8px;">Support</a>
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
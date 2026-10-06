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
                    <td align="center" style="padding:32px 30px;background:linear-gradient(180deg,#E433E1 0%,#420089 100%);">
                        <img src="https://payyigi.com/payigi-logo-bg.png" alt="PayYigi" width="100" />
                        <h1 style="margin:16px 0 4px;color:#fff;font-size:24px;font-weight:700;">{{ $title }}</h1>
                        <p style="margin:0;color:#F3D9F5;font-size:14px;">Ticket {{ $ref }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:30px;">
                        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#A0A0B0;margin-bottom:20px;">
                            <tr><td style="padding:2px 0;">Customer</td><td align="right" style="color:#E4E4E7;">{{ $customer }}</td></tr>
                            <tr><td style="padding:2px 0;">Email</td><td align="right" style="color:#E4E4E7;">{{ $customerEmail }}</td></tr>
                            <tr><td style="padding:2px 0;">Time</td><td align="right" style="color:#E4E4E7;">{{ $time }}</td></tr>
                        </table>

                        <div style="background:#1F2033;border-left:4px solid {{ $roleLabel === 'Customer' ? '#E433E1' : '#7C5CFF' }};border-radius:8px;padding:16px 18px;">
                            <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#A0A0B0;">{{ $roleLabel }}</p>
                            <p style="margin:0;font-size:15px;line-height:1.7;color:#E4E4E7;">{!! nl2br(e($body)) !!}</p>
                        </div>

                        @if(!empty($snapshot))
                        <p style="margin:28px 0 8px;font-size:13px;font-weight:700;color:#A0A0B0;">Account snapshot</p>
                        <pre style="margin:0;background:#1F2033;border-radius:8px;padding:14px 16px;font-size:12px;line-height:1.6;color:#E4E4E7;white-space:pre-wrap;word-break:break-word;">{{ $snapshot }}</pre>
                        @endif

                        @if(!empty($transcript))
                        <p style="margin:28px 0 8px;font-size:13px;font-weight:700;color:#A0A0B0;">Chat transcript</p>
                        @foreach($transcript as $line)
                            <div style="margin-bottom:8px;background:#1F2033;border-radius:8px;padding:10px 14px;">
                                <span style="font-size:11px;color:#A0A0B0;">{{ $line['role'] === 'user' ? 'Customer' : 'PayYigi AI' }} · {{ $line['time'] }}</span>
                                <p style="margin:4px 0 0;font-size:13px;line-height:1.6;">{!! nl2br(e($line['body'])) !!}</p>
                            </div>
                        @endforeach
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;text-align:center;background:#1F2033;">
                        <p style="margin:0;font-size:13px;color:#7D7D91;">{{ date('Y') }} PayYigi. Internal support record.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

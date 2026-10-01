<!DOCTYPE html>
<html lang="sw">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:24px;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#1b1f2a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #dde3ee;border-radius:8px;">
    <tr>
        <td style="padding:20px 24px;border-bottom:1px solid #dde3ee;">
            <div style="font-size:12px;letter-spacing:.06em;color:#5b6475;text-transform:uppercase;">{{ $schoolName }}</div>
            <div style="font-size:18px;font-weight:bold;color:#0b3a82;margin-top:4px;">Michango ya Ustawi wa Jamii</div>
        </td>
    </tr>
    <tr>
        <td style="padding:24px;font-size:15px;line-height:1.6;">
            <p style="margin:0 0 16px;">{{ $body }}</p>
            <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;">
                <tr><td style="padding:4px 16px 4px 0;color:#5b6475;">Kiasi</td><td style="font-weight:bold;">{{ \App\Support\Months::money($contribution->amount) }}</td></tr>
                <tr><td style="padding:4px 16px 4px 0;color:#5b6475;">Mwezi</td><td>{{ \App\Support\Months::LONG[$contribution->month] }} {{ $contribution->year }}</td></tr>
                <tr><td style="padding:4px 16px 4px 0;color:#5b6475;">Kumbukumbu</td><td>{{ $contribution->reference }}</td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

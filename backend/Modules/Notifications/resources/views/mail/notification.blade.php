@php($appName = config('app.name'))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $heading }}</title>
</head>
<body style="font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; color: #1c1917; line-height: 1.55;">
    <p style="font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; color: #0f766e;">{{ $appName }}</p>
    <h1 style="font-size: 18px; margin: 8px 0 16px;">{{ $heading }}</h1>

    @if ($greetingName)
        <p>Hello {{ $greetingName }},</p>
    @endif

    <p>{{ $bodyText }}</p>

    @if (count($lines) > 0)
        <table cellpadding="0" cellspacing="0" style="margin-top: 16px; font-size: 14px;">
            @foreach ($lines as $label => $value)
                <tr>
                    <td style="padding: 2px 16px 2px 0; color: #78716c;">{{ $label }}</td>
                    <td style="padding: 2px 0;">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p style="margin-top: 24px; font-size: 12px; color: #78716c;">
        This is an automated message from {{ $appName }}.
    </p>
</body>
</html>

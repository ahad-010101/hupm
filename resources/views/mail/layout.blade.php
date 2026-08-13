{{--
    Shared email layout.

    Deliberately plain: inline styles, no external stylesheet, no webfont, no
    images. Email clients strip most CSS, Outlook renders with Word, and many
    tenants read on a phone with images off. Anything clever here degrades into
    a mess somewhere.

    These messages are also legal records — a late-fee notice or a Management
    Review letter may end up in a Georgia dispossessory conversation — so the
    plain-text fallback has to carry the same meaning as the HTML.

    Variables: $company (shared by the ViewServiceProvider composer).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $company['name'])</title>
</head>
<body style="margin:0; padding:0; background-color:#f6f7f8; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:1.5; color:#1f2937;">
    <div style="max-width:600px; margin:0 auto; padding:24px 16px;">

        <div style="padding:16px 0; border-bottom:2px solid #0d7d72;">
            <span style="font-size:18px; font-weight:600; color:#0f6459;">{{ $company['name'] }}</span>
        </div>

        <div style="background:#ffffff; padding:24px; border:1px solid #e5e7eb; border-top:none;">
            @yield('content')
        </div>

        <div style="padding:16px 0; font-size:14px; color:#6b7280;">
            @if ($company['emergency_phone'])
                <p style="margin:0 0 8px;">
                    <strong style="color:#991b1b;">Emergency maintenance:</strong>
                    {{ $company['emergency_phone'] }} — for fire, gas or flooding call 911 first.
                </p>
            @endif
            @if ($company['phone'])
                <p style="margin:0 0 8px;">Office: {{ $company['phone'] }}</p>
            @endif
            @if ($company['address'])
                <p style="margin:0 0 8px;">{{ $company['address'] }}</p>
            @endif
            <p style="margin:8px 0 0; color:#9ca3af;">
                This message was sent to you because you are a resident of a {{ $company['name'] }} property.
            </p>
        </div>

    </div>
</body>
</html>

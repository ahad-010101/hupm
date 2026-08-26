{{-- An operational alert to an administrator.  [TDD §10, WP-31]

     Written to be read on a phone, at the weekend, by somebody who was not
     thinking about the system a minute ago. What has happened, then what to do,
     then the figures. The detail table carries counts and timestamps only —
     never a resident's name and never anything resembling bank detail (I-5). --}}
@extends('mail.layout')
@section('title', $alert->subject())

@section('content')
    <p style="margin:0 0 12px; font-size:18px; font-weight:600; color:#991b1b;">
        {{ $alert->subject() }}
    </p>

    <p style="margin:0 0 16px;">{{ $alert->summary() }}</p>

    <p style="margin:0 0 8px; font-weight:600;">What to do</p>
    <p style="margin:0 0 16px;">{{ $alert->action() }}</p>

    @if ($detail)
        <p style="margin:0 0 8px; font-weight:600;">Detail</p>
        <table role="presentation" style="width:100%; border-collapse:collapse; margin:0 0 16px;">
            @foreach ($detail as $label => $value)
                <tr>
                    <td style="padding:6px 12px 6px 0; color:#6b7280; vertical-align:top; white-space:nowrap;">
                        {{ $label }}
                    </td>
                    <td style="padding:6px 0;"><strong>{{ $value }}</strong></td>
                </tr>
            @endforeach
        </table>
    @endif

    <p style="margin:0; font-size:14px; color:#6b7280;">
        You will not be sent this particular alert again for
        {{ $alert->cooldownHours() }} hours, whether or not it is resolved. The admin console
        shows the same conditions continuously.
    </p>
@endsection

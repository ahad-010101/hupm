{{-- A message from the public contact form (FR-PUB-01).

     The visitor's own words are escaped like everything else — this arrives
     from a stranger, and the office reads it in a mail client that will happily
     render whatever it is given. --}}
@extends('mail.layout')
@section('title', 'Website enquiry')

@section('content')
    <p style="margin:0 0 12px;">
        Somebody used the contact form on the public site.
    </p>

    <table role="presentation" style="width:100%; border-collapse:collapse; margin:0 0 16px;">
        <tr>
            <td style="padding:6px 12px 6px 0; color:#6b7280; vertical-align:top;">From</td>
            <td style="padding:6px 0;"><strong>{{ $senderName }}</strong></td>
        </tr>
        <tr>
            <td style="padding:6px 12px 6px 0; color:#6b7280; vertical-align:top;">Email</td>
            <td style="padding:6px 0;">
                <a href="mailto:{{ $senderEmail }}" style="color:#0d7d72;">{{ $senderEmail }}</a>
            </td>
        </tr>
        @if ($senderPhone)
            <tr>
                <td style="padding:6px 12px 6px 0; color:#6b7280; vertical-align:top;">Telephone</td>
                <td style="padding:6px 0;">{{ $senderPhone }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding:6px 12px 6px 0; color:#6b7280; vertical-align:top;">Subject</td>
            <td style="padding:6px 0;">{{ $subjectLine }}</td>
        </tr>
        <tr>
            <td style="padding:6px 12px 6px 0; color:#6b7280; vertical-align:top;">Received</td>
            <td style="padding:6px 0;">{{ $submittedAt }}</td>
        </tr>
    </table>

    <div style="border-left:3px solid #e5e7eb; padding:0 0 0 16px; white-space:pre-wrap;">{{ $body }}</div>

    <p style="margin:16px 0 0; font-size:14px; color:#6b7280;">
        Replying to this email answers the sender directly.
    </p>
@endsection

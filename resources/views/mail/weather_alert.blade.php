{{-- Deduplicated by (property, nws_alert_id) before this is queued, so a
     tenant is never alerted twice for one storm (AC-NTF-07). --}}
@extends('mail.layout')
@section('title', 'Weather alert for your area')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
        The National Weather Service has issued a <strong>{{ $eventType }}</strong> for your area.
    </p>
    @if (! empty($headline))
        <p style="margin:0 0 12px;">{{ $headline }}</p>
    @endif
    @if (! empty($expiresAt))
        <p style="margin:0 0 12px;">In effect until {{ $expiresAt }}.</p>
    @endif
    <p style="margin:0 0 12px;">
        Please take sensible precautions. If the weather damages your home, report it as a
        maintenance request — or call the emergency number below if it cannot wait.
    </p>
@endsection

{{-- Only tenant-visible transitions reach here. Vendor cost and invoices are
     never shown to a tenant (NG-6). --}}
@extends('mail.layout')
@section('title', 'Update on your maintenance request')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
        Your maintenance request <strong>{{ $ticketNumber }}</strong> ({{ $category }}) is now
        <strong>{{ $status }}</strong>.
    </p>
    @if (! empty($note))
        <p style="margin:0 0 12px;">{{ $note }}</p>
    @endif
    @if (! empty($scheduledFor))
        <p style="margin:0 0 12px;">Scheduled for: <strong>{{ $scheduledFor }}</strong>.</p>
    @endif
    <p style="margin:0 0 12px;">
        <a href="{{ $url }}" style="display:inline-block; background:#0d7d72; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; font-weight:600;">View the request</a>
    </p>
@endsection

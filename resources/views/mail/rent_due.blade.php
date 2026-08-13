{{-- INVARIANT I-4: the amount here is the tenant portion only. The Housing
     Authority portion is never named or implied in tenant-facing text. --}}
@extends('mail.layout')
@section('title', 'Rent due')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
        Your rent of <strong>{{ $amount }}</strong> is due on <strong>{{ $dueDate }}</strong>.
    </p>
    @if (! empty($balance))
        <p style="margin:0 0 12px;">Your current account balance is <strong>{{ $balance }}</strong>.</p>
    @endif
    <p style="margin:0 0 12px;">
        <a href="{{ $url }}" style="display:inline-block; background:#0d7d72; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; font-weight:600;">Pay online</a>
    </p>
    <p style="margin:0 0 12px;">
        Payments by bank transfer take 2 to 5 business days to clear, so please allow time
        if you are paying close to the due date.
    </p>
@endsection

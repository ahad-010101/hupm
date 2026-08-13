@extends('mail.layout')
@section('title', 'We received your payment')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
        Thank you — we have received your payment of <strong>{{ $amount }}</strong> on {{ $date }}.
    </p>
    @if (! empty($processing))
        {{-- Never "pending" or "failed" to a tenant. ACH takes 2–5 business days,
             and a tenant who believes it failed will pay twice (UI §8). --}}
        <p style="margin:0 0 12px;">
            It is now processing with your bank and usually completes within 2 to 5 business days.
            Your balance will update once it clears. You do not need to do anything else.
        </p>
    @endif
    @if (! empty($balance))
        <p style="margin:0 0 12px;">Balance once this payment clears: <strong>{{ $balance }}</strong>.</p>
    @endif
    <p style="margin:0 0 12px;">
        <a href="{{ $url }}" style="display:inline-block; background:#0d7d72; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; font-weight:600;">View your account</a>
    </p>
@endsection

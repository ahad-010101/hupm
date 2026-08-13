{{-- Factual, never accusatory. A return is a bank event; the tenant needs to
     know what happened and what to do next (UI §8). The word "failed" does not
     appear. --}}
@extends('mail.layout')
@section('title', 'Your payment was returned')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
        Your payment of <strong>{{ $amount }}</strong> from {{ $date }} was returned unpaid by your
        bank, so it has been removed from your account balance.
    </p>
    @if (! empty($reason))
        <p style="margin:0 0 12px;">Reason given by the bank: {{ $reason }}.</p>
    @endif
    @if (! empty($fee))
        <p style="margin:0 0 12px;">
            A returned payment fee of <strong>{{ $fee }}</strong> has been added to your account,
            as set out in your lease.
        </p>
    @endif
    <p style="margin:0 0 12px;">Your balance is now <strong>{{ $balance }}</strong>.</p>
    <p style="margin:0 0 12px;">
        <a href="{{ $url }}" style="display:inline-block; background:#0d7d72; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; font-weight:600;">Make a payment</a>
    </p>
    <p style="margin:0 0 12px;">
        If you think this is a mistake, please contact the office and we will look into it with you.
    </p>
@endsection

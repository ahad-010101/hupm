{{-- Neutral and actionable, never accusatory (UI §8). This message may be read
     back in a Georgia dispossessory proceeding, so it states the facts, says
     what to do, and does nothing else. --}}
@extends('mail.layout')
@section('title', 'Please contact us about your account')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
        Your account has an outstanding balance of <strong>{{ $balance }}</strong>.
    </p>
    <p style="margin:0 0 12px;">Please contact management to arrange payment.</p>
    <p style="margin:0 0 12px;">
        While an account is under review, online payment is not available — but we can take
        payment directly and set up an arrangement. Getting in touch is the quickest way to
        resolve this.
    </p>
    @if (! empty($phone))
        <p style="margin:0 0 12px;">Call us on {{ $phone }}.</p>
    @endif
@endsection

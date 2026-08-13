@extends('mail.layout')
@section('title', 'A late fee has been added')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
        A late fee of <strong>{{ $fee }}</strong> was added to your account on {{ $date }},
        because rent was not received by {{ $graceEnd }}.
    </p>
    <p style="margin:0 0 12px;">Your balance is now <strong>{{ $balance }}</strong>.</p>
    <p style="margin:0 0 12px;">
        <a href="{{ $url }}" style="display:inline-block; background:#0d7d72; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; font-weight:600;">Pay online</a>
    </p>
    <p style="margin:0 0 12px;">
        If paying in full is difficult at the moment, please contact the office — we would
        rather arrange something with you.
    </p>
@endsection

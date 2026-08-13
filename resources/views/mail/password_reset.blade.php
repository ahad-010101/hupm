@extends('mail.layout')
@section('title', 'Reset your password')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">We received a request to reset the password on your resident account.</p>
    <p style="margin:0 0 12px;">
        <a href="{{ $url }}" style="display:inline-block; background:#0d7d72; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; font-weight:600;">Reset your password</a>
    </p>
    <p style="margin:0 0 12px;">This link expires on {{ $expiresOn }}.</p>
    <p style="margin:0 0 12px;">
        If you did not ask for this, no action is needed and your password has not changed.
        If you keep receiving these, please contact the office.
    </p>
@endsection

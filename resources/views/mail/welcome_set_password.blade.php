{{-- Sent by WP-04. The link is single-use and expires (AC-AUTH-05). --}}
@extends('mail.layout')
@section('title', 'Set up your resident account')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
        An account has been created for you so you can view your balance, pay rent, submit
        maintenance requests and read your documents online.
    </p>
    <p style="margin:0 0 12px;">
        <a href="{{ $url }}" style="display:inline-block; background:#0d7d72; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; font-weight:600;">Set your password</a>
    </p>
    <p style="margin:0 0 12px;">
        This link expires on {{ $expiresOn }}. If it has expired, you can request a new one
        from the login page.
    </p>
    <p style="margin:0 0 12px;">
        If you were not expecting this, you can ignore this message and no account will be activated.
    </p>
@endsection

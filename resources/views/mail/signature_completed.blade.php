@extends('mail.layout')
@section('title', 'Your signed document')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
        Thank you. <strong>{{ $documentTitle }}</strong> was signed on {{ $signedAt }}.
    </p>
    <p style="margin:0 0 12px;">
        <a href="{{ $url }}" style="display:inline-block; background:#0d7d72; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; font-weight:600;">Download your copy</a>
    </p>
    <p style="margin:0 0 12px;">
        A copy is also kept in your documents, where you can download it at any time.
    </p>
@endsection

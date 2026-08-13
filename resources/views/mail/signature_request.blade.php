@extends('mail.layout')
@section('title', 'A document needs your signature')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
        <strong>{{ $documentTitle }}</strong> is ready for you to read and sign.
    </p>
    <p style="margin:0 0 12px;">
        <a href="{{ $url }}" style="display:inline-block; background:#0d7d72; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; font-weight:600;">Review and sign</a>
    </p>
    @if (! empty($expiresOn))
        <p style="margin:0 0 12px;">Please complete this by {{ $expiresOn }}.</p>
    @endif
    <p style="margin:0 0 12px;">
        You will be able to read the whole document before signing, and you will receive a
        signed copy afterwards.
    </p>
@endsection

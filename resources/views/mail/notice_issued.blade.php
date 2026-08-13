{{-- $body is admin-authored HTML. It is sanitised at the point of composition
     in WP-20 before it is stored, never here — sanitising at render time means
     the stored record and the sent email can differ, and a notice is a legal
     record (FR-NTF-02). --}}
@extends('mail.layout')
@section('title', 'A notice from your property manager')

@section('content')
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>

    <div style="margin:0 0 12px;">{!! $body !!}</div>

    <p style="margin:0 0 12px;">
        <a href="{{ $url }}" style="display:inline-block; background:#0d7d72; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:6px; font-weight:600;">View in your account</a>
    </p>
    <p style="margin:0 0 12px;">A copy of this notice is kept in your documents.</p>
@endsection

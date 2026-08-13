{{--
    FS §18.3. Normally unreachable: the exception handler redirects 419 to login
    with an explanatory message. This is the fallback for when a redirect is not
    possible.
--}}
@extends('public.layout')

@section('title', 'Session expired')

@section('content')
    <h1 class="text-2xl font-semibold">Your session expired</h1>
    <p class="mt-2 text-gray-700">
        For security, sessions end after a period of inactivity. Nothing you submitted
        was lost — please sign in again and retry.
    </p>
    <p class="mt-4">
        <a href="{{ route('login') }}" class="underline">Sign in</a>
    </p>
@endsection

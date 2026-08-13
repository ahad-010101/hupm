{{--
    FS §18.3. Wrong role for a route that legitimately exists — an owner
    reaching a documents route, for example (AC-DOC-04). Ownership violations
    are 404, not this page.
--}}
@extends('public.layout')

@section('title', 'No access')

@section('content')
    <h1 class="text-2xl font-semibold">You do not have access to this page.</h1>
    <p class="mt-2 text-gray-700">
        Your account does not have permission to view this. If you believe this is a
        mistake, please contact management.
    </p>
    <p class="mt-4">
        <a href="{{ route('public.home') }}" class="underline">Return to the home page</a>
    </p>
@endsection

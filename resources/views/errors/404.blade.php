{{--
    FS §18.3. Also served for ownership violations (BR-20, invariant I-9): a 403
    would confirm the record exists, telling one tenant that another is a
    customer here. The wording must therefore not hint that anything was found.
--}}
@extends('public.layout')

@section('title', 'Not found')

@section('content')
    <h1 class="text-2xl font-semibold">Not found</h1>
    <p class="mt-2 text-gray-700">
        We could not find that page. It may have moved, or the link may be out of date.
    </p>
    <p class="mt-4">
        <a href="{{ route('public.home') }}" class="underline">Return to the home page</a>
        or <a href="{{ route('login') }}" class="underline">sign in to your account</a>.
    </p>
@endsection

{{--
    FS §18.3: generic page with a reference ID, no stack trace. The reference is
    logged alongside the exception so an admin can find the cause from what the
    tenant read out over the phone.
--}}
@extends('public.layout')

@section('title', 'Something went wrong')

@section('content')
    <h1 class="text-2xl font-semibold">Something went wrong</h1>
    <p class="mt-2 text-gray-700">
        We hit an unexpected problem and could not complete that request. Nothing was
        charged or changed. Please try again in a few minutes.
    </p>

    @isset($reference)
        <p class="mt-4 text-gray-700">
            If it keeps happening, contact management and quote this reference:
            <span class="font-mono font-semibold">{{ $reference }}</span>
        </p>
    @endisset

    <p class="mt-4">
        <a href="{{ route('public.home') }}" class="underline">Return to the home page</a>
    </p>
@endsection

{{-- Placeholder. WP-18 builds the real Home page against UI §1 and FR-PUB-01. --}}
@extends('public.layout')

@section('title', config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ config('app.name') }}</h1>
    <p class="mt-2 text-gray-700">
        Public site placeholder — rendered by Blade with no Inertia middleware (D-05).
        WP-18 replaces this with the Home page defined in UI §1.
    </p>
@endsection

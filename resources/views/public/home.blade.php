{{-- Placeholder. WP-18 builds the real Home page against UI §1. --}}
@extends('public.layout')

@section('title', $company['name'])

@section('content')
    <h1 class="text-3xl font-semibold">{{ $company['name'] }}</h1>
    <p class="mt-3 max-w-prose text-lg text-gray-700">
        Property management for residents across metropolitan Atlanta.
    </p>

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <a href="{{ route('login') }}"
           class="rounded-lg border border-gray-200 p-5 hover:border-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600">
            <p class="text-lg font-semibold">Resident login</p>
            <p class="mt-1 text-gray-700">Pay rent, submit a repair request, and view your documents.</p>
        </a>

        <a href="{{ route('public.emergency') }}"
           class="rounded-lg border border-overdue-border bg-overdue-bg p-5 hover:border-overdue-fg focus-visible:outline focus-visible:outline-2 focus-visible:outline-overdue-fg">
            <p class="text-lg font-semibold text-overdue-fg">Emergency maintenance</p>
            <p class="mt-1 text-gray-700">What to do about a leak, no heat, or a lockout — right now.</p>
        </a>
    </div>
@endsection

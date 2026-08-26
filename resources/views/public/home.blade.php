{{-- Home.  [FR-PUB-01, UI §1]

     Almost everyone arriving here is an existing resident who wants to pay rent
     or report a repair, so those are the two things above the fold. Marketing
     copy for prospective renters comes after, not before. --}}
@extends('public.layout')

@section('title', $company['name'])
@section('meta_description', 'Property management for residents across metropolitan Atlanta. Pay rent, report a repair, and reach the office.')

@section('content')
    <h1 class="text-3xl font-semibold sm:text-4xl">{{ $company['name'] }}</h1>
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

    <h2 class="mt-12 text-2xl font-semibold">What you can do in your account</h2>

    <div class="mt-4 grid gap-4 sm:grid-cols-3">
        @foreach ([
            ['Pay rent online', 'Pay by bank transfer at any hour. Payments take two to five business days to clear, and your balance updates when they do.'],
            ['Report a repair', 'Send photographs, say when you are home, and follow the job through to the day it is closed.'],
            ['Keep your paperwork', 'Your lease, notices and signed agreements, in one place, downloadable whenever you need them.'],
        ] as [$title, $blurb])
            <div class="rounded-lg border border-gray-200 p-5">
                <p class="text-lg font-semibold">{{ $title }}</p>
                <p class="mt-1 text-gray-700">{{ $blurb }}</p>
            </div>
        @endforeach
    </div>

    <h2 class="mt-12 text-2xl font-semibold">Looking for a home?</h2>
    <p class="mt-2 max-w-prose text-gray-700">
        Availability changes constantly and we do not keep a live vacancy list online. See what is
        currently advertised, or write to us and we will tell you what is coming up.
    </p>

    <div class="mt-4 flex flex-wrap gap-3">
        <a href="{{ route('public.properties') }}"
           class="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            Available properties
        </a>
        <a href="{{ route('public.contact') }}"
           class="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 py-2 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            Contact us
        </a>
    </div>
@endsection

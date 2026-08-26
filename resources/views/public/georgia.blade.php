{{-- Georgia Rental Information & DCA resources.  [FR-PUB-01, UI §1]

     Deliberately not legal advice, and deliberately not our interpretation of
     the law. This page points at the state's own material and at independent
     help, because a landlord explaining a tenant's rights in the landlord's own
     words is worth very little to the tenant.

     Every outward link is rel="noopener noreferrer". --}}
@extends('public.layout')

@section('title', 'Georgia Rental Information — '.$company['name'])
@section('meta_description', 'Official Georgia tenant-landlord resources, the DCA handbook, and where to get independent advice.')

@section('content')
    <h1 class="text-3xl font-semibold">Georgia rental information</h1>

    <p class="mt-3 max-w-prose text-lg text-gray-700">
        Where to read the rules for yourself, and where to get help that is not from us.
    </p>

    <div class="mt-6 rounded-lg border border-gray-300 bg-gray-50 p-5">
        <p class="max-w-prose text-gray-800">
            Nothing on this page is legal advice, and none of it is our summary of the law. These
            are the state's own materials. If your situation is serious, speak to one of the
            independent services below rather than to us.
        </p>
    </div>

    <h2 class="mt-10 text-2xl font-semibold">Official Georgia resources</h2>

    <ul class="mt-4 grid gap-4 sm:grid-cols-2">
        @foreach ([
            ['Georgia Department of Community Affairs', 'https://www.dca.ga.gov/', 'The state housing agency. Administers Housing Choice Vouchers and publishes the tenant-landlord handbook.'],
            ['Georgia Landlord-Tenant Handbook', 'https://www.dca.ga.gov/safe-affordable-housing/rental-housing-development/georgia-landlord-tenant-handbook', 'The state\'s own guide to deposits, repairs, notice periods and eviction.'],
            ['Housing Choice Voucher programme', 'https://www.dca.ga.gov/safe-affordable-housing/rental-assistance/housing-choice-voucher-program-formerly-known-section-8', 'How the voucher works, what it covers, and what changes your share of the rent.'],
            ['HUD — Georgia', 'https://www.hud.gov/states/georgia/renting', 'Federal housing information, including fair-housing complaints.'],
        ] as [$title, $url, $blurb])
            <li class="rounded-lg border border-gray-200 p-5">
                <p class="text-lg font-semibold">
                    <a href="{{ $url }}" rel="noopener noreferrer" target="_blank"
                       class="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        {{ $title }}
                    </a>
                </p>
                <p class="mt-1 text-gray-700">{{ $blurb }}</p>
            </li>
        @endforeach
    </ul>

    <h2 class="mt-10 text-2xl font-semibold">Independent help</h2>

    <ul class="mt-4 grid gap-4 sm:grid-cols-2">
        @foreach ([
            ['Georgia Legal Services Program', 'https://www.glsp.org/', 'Free civil legal help outside metropolitan Atlanta, for those who qualify.'],
            ['Atlanta Legal Aid Society', 'https://atlantalegalaid.org/', 'Free civil legal help in Fulton, DeKalb, Clayton, Cobb and Gwinnett.'],
            ['Georgia Fair Housing', 'https://www.hud.gov/program_offices/fair_housing_equal_opp', 'If you believe you have been discriminated against.'],
            ['Georgia 211', 'https://www.211.org/', 'Rent, utility and food assistance, by telephone or online.'],
        ] as [$title, $url, $blurb])
            <li class="rounded-lg border border-gray-200 p-5">
                <p class="text-lg font-semibold">
                    <a href="{{ $url }}" rel="noopener noreferrer" target="_blank"
                       class="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        {{ $title }}
                    </a>
                </p>
                <p class="mt-1 text-gray-700">{{ $blurb }}</p>
            </li>
        @endforeach
    </ul>

    <h2 class="mt-10 text-2xl font-semibold">If you are struggling to pay</h2>
    <p class="mt-2 max-w-prose text-gray-700">
        Telephone us before the due date. We would far rather agree an arrangement in writing than
        chase arrears, and an arrangement made in advance is on much better terms than one made
        afterwards. Georgia 211 above can also point you at rent assistance you may be entitled to.
    </p>

    <p class="mt-6">
        <a href="{{ route('public.contact') }}"
           class="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 py-2 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            Contact the office
        </a>
    </p>
@endsection

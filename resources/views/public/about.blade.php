{{-- About the Management Company.  [FR-PUB-01, UI §1]

     Written for two readers at once: a prospective resident deciding whether we
     are worth dealing with, and an existing one working out who to talk to.
     Neither is served by adjectives, so there are none. --}}
@extends('public.layout')

@section('title', 'About — '.$company['name'])
@section('meta_description', 'Who we are, the homes we manage, and how to reach the office.')

@section('content')
    <h1 class="text-3xl font-semibold">About {{ $company['name'] }}</h1>

    <p class="mt-4 max-w-prose text-lg text-gray-700">
        We manage residential property across metropolitan Atlanta, day to day and in person:
        collecting rent, keeping homes in repair, and answering the telephone when something
        goes wrong.
    </p>

    <h2 class="mt-10 text-2xl font-semibold">How we work</h2>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        @foreach ([
            ['Repairs are tracked, not remembered', 'Every request gets a number and a written trail, from the day it is reported to the day you agree it is finished. You close it, not us.'],
            ['Rent is on the record', 'Every charge, payment and adjustment appears on your account with a date. Nothing is held in somebody\'s notebook.'],
            ['Housing Choice Vouchers are routine here', 'Most of the homes we manage are let to voucher holders. Your share is the only figure you ever have to think about.'],
            ['You can reach a person', 'The telephone is answered during office hours, and there is a number for emergencies at every other hour.'],
        ] as [$title, $blurb])
            <div class="rounded-lg border border-gray-200 p-5">
                <p class="text-lg font-semibold">{{ $title }}</p>
                <p class="mt-1 text-gray-700">{{ $blurb }}</p>
            </div>
        @endforeach
    </div>

    <h2 class="mt-10 text-2xl font-semibold">Where to find us</h2>

    <dl class="mt-4 max-w-prose space-y-3">
        @if ($company['address'])
            <div>
                <dt class="font-semibold">Office</dt>
                <dd class="text-gray-700">{{ $company['address'] }}</dd>
            </div>
        @endif
        @if ($company['phone'])
            <div>
                <dt class="font-semibold">Telephone</dt>
                <dd>
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $company['phone']) }}" class="underline">
                        {{ $company['phone'] }}
                    </a>
                </dd>
            </div>
        @endif
        @if ($company['office_hours'])
            <div>
                <dt class="font-semibold">Office hours</dt>
                <dd class="text-gray-700">{{ $company['office_hours'] }}</dd>
            </div>
        @endif
    </dl>

    <p class="mt-6">
        <a href="{{ route('public.contact') }}"
           class="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            Contact us
        </a>
    </p>
@endsection

{{-- Available Properties.  [FR-PUB-01, BR-22, UI §1]

     Static copy the office maintains, never a query over vacant units — a
     public list of empty homes is a public statement about who is not at home.
     See PropertiesController: it reads a setting and nothing else. --}}
@extends('public.layout')

@section('title', 'Available Properties — '.$company['name'])
@section('meta_description', 'Homes currently advertised, and how to ask about what is coming up.')

@section('content')
    <h1 class="text-3xl font-semibold">Available properties</h1>

    @if ($listings)
        <p class="mt-3 max-w-prose text-gray-700">
            Currently advertised. Availability changes quickly, so please confirm with the office
            before making arrangements.
        </p>

        <ul class="mt-6 grid gap-3 sm:grid-cols-2">
            @foreach ($listings as $listing)
                <li class="rounded-lg border border-gray-200 p-4 text-gray-800">{{ $listing }}</li>
            @endforeach
        </ul>
    @else
        <p class="mt-3 max-w-prose text-lg text-gray-700">
            We have nothing advertised at the moment.
        </p>
        <p class="mt-3 max-w-prose text-gray-700">
            Homes here turn over steadily and are often taken before they are advertised at all.
            Write to us with what you are looking for and we will tell you what is coming up.
        </p>
    @endif

    <div class="mt-8 rounded-lg border border-gray-200 bg-gray-50 p-5">
        <p class="text-lg font-semibold">Housing Choice Vouchers</p>
        <p class="mt-1 max-w-prose text-gray-700">
            We let to voucher holders as a matter of course. Tell us which housing authority
            issued yours when you write, and we will tell you what we have that qualifies.
        </p>
    </div>

    <p class="mt-6">
        <a href="{{ route('public.contact') }}"
           class="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            Ask about availability
        </a>
    </p>
@endsection

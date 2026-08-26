{{-- Privacy Policy / Terms of Use.  [UI §2.1 footer]

     Both routes render this. The wording is the client's to supply and their
     lawyer's to approve — inventing it here would be worse than admitting it is
     not written, because a made-up privacy policy is a promise nobody has
     checked we keep.

     The page still has to be useful to someone who followed the footer link, so
     it says what is true today and where to ask. --}}
@extends('public.layout')

@section('title', $heading.' — '.$company['name'])

@section('content')
    <h1 class="text-3xl font-semibold">{{ $heading }}</h1>

    <p class="mt-4 max-w-prose text-lg text-gray-700">
        This document is being prepared and is not published yet.
    </p>

    <p class="mt-3 max-w-prose text-gray-700">
        In the meantime, if you have a question about how we hold your information or about the
        terms you are dealing with us under, write to the office and we will answer it directly.
    </p>

    <p class="mt-6">
        <a href="{{ route('public.contact') }}"
           class="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            Contact the office
        </a>
    </p>
@endsection

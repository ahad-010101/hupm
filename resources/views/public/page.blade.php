{{--
    A managed public page.  [WP-36, D-27]

    Renders an ordered list of typed sections. Every partial is defensive: a
    payload written before a field existed must not take the page down, so each
    reads `?? ''` and each section is skipped entirely if we have no partial for
    its type.

    Server-rendered, no JavaScript (D-05).
--}}
@extends('public.layout')

@section('title', ($page['title'] ?: $company['name']).' — '.$company['name'])
@section('meta_description', $page['meta_description'])

@section('content')
    {{-- Already filtered to catalogue types by PageController: the include
         path below comes from a database column, so the closed set is what
         stops it being a template-inclusion bug. --}}
    @forelse ($page['sections'] as $section)
        @include('public.sections.'.$section['type'], ['s' => $section['payload']])
    @empty
        {{-- The state of a fresh install, so it has to be decent rather than
             merely non-fatal. It says what is true and offers the two things
             anyone arriving here actually wants. --}}
        <section class="py-16">
            <h1 class="text-3xl font-semibold">{{ $page['title'] ?: $company['name'] }}</h1>
            <p class="mt-3 max-w-prose text-lg text-gray-700">
                There is nothing on this page yet.
            </p>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('login') }}"
                   class="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-5 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                    Resident login
                </a>
                <a href="{{ route('public.contact') }}"
                   class="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-5 py-2 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                    Contact the office
                </a>
            </div>
        </section>
    @endforelse
@endsection

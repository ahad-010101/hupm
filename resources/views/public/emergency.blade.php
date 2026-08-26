{{--
    Emergency Maintenance Instructions.  [FR-PUB-01, BR-23, UI §1, D-05]

    This is the page the entire public site is Blade for. Someone opens it
    standing in water, on one bar of signal, possibly in the dark. Every choice
    below follows from that:

      - The 911 line comes before anything else, and before any of our own
        numbers. We are not the right call for a gas leak.
      - Numbers are `tel:` links and large enough to hit with a wet thumb.
      - The "what to do now" steps come before the explanation of what counts as
        an emergency, because somebody who is already in one does not need the
        definition.
      - No JavaScript, no webfont, no image. Nothing here may depend on a
        request that might not complete.

    Do not add a JS dependency to this page or its layout.
--}}
@extends('public.layout')

@section('title', 'Emergency Maintenance — '.$company['name'])
@section('meta_description', 'What to do about a leak, no heat, no power or a lockout, and who to call.')

@section('content')
    <h1 class="text-3xl font-semibold">Emergency maintenance</h1>

    {{-- BR-23. First, largest, and above everything of ours. --}}
    <div class="mt-6 rounded-lg border-2 border-overdue-border bg-overdue-bg p-5">
        <p class="text-lg font-semibold text-overdue-fg">
            If there is a fire, a gas smell, or anyone is in danger
        </p>
        <p class="mt-2 text-lg">
            <a href="tel:911"
               class="inline-flex min-h-touch items-center text-3xl font-bold text-overdue-fg underline
                      focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg">
                Call 911
            </a>
        </p>
        <p class="mt-2 text-gray-800">
            Leave the building first and call from outside. Tell us afterwards — not before.
        </p>
    </div>

    <div class="mt-6 rounded-lg border border-gray-300 bg-gray-50 p-5">
        <p class="text-lg font-semibold">For a maintenance emergency at your home</p>

        @if ($company['emergency_phone'])
            <p class="mt-2">
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $company['emergency_phone']) }}"
                   class="inline-flex min-h-touch items-center text-3xl font-bold underline
                          focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                    {{ $company['emergency_phone'] }}
                </a>
            </p>
            <p class="mt-2 text-gray-800">
                Answered day and night. Telephone — do not use the repair form for an emergency,
                because nobody may read it until morning.
            </p>
        @else
            {{-- [GATE] company.emergency_phone is unset. WP-35 blocks go-live
                 while it is empty, and this page says so rather than leaving a
                 blank space where the number belongs. --}}
            <p class="mt-2 text-lg font-semibold text-overdue-fg">
                The out-of-hours number has not been published yet.
            </p>
            @if ($company['phone'])
                <p class="mt-2 text-gray-800">
                    During office hours, telephone
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $company['phone']) }}" class="font-semibold underline">
                        {{ $company['phone'] }}</a>.
                </p>
            @endif
        @endif
    </div>

    <h2 class="mt-10 text-2xl font-semibold">What to do first</h2>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        @foreach ([
            ['Water pouring in', 'Turn off the stop tap if you can reach it safely. Move what you can off the floor. Do not touch a wet socket or switch.'],
            ['No heat in freezing weather', 'Close interior doors to keep one room warm. Do not use a gas cooker or a barbecue to heat a room — people die of this every winter.'],
            ['No power', 'Check whether your neighbours have power. If they do, look at your breaker panel. If nobody does, it is the utility, not the building.'],
            ['A smell of gas', 'Do not touch any switch, and do not use your phone indoors. Leave, then call 911 from outside.'],
            ['Locked out', 'Telephone the number above. Do not force a window — a broken window at night is a second emergency.'],
            ['Sewage backing up', 'Stop running water anywhere in the home, including the washing machine, and telephone straight away.'],
        ] as [$situation, $advice])
            <div class="rounded-lg border border-gray-200 p-4">
                <p class="font-semibold">{{ $situation }}</p>
                <p class="mt-1 text-gray-700">{{ $advice }}</p>
            </div>
        @endforeach
    </div>

    <h2 class="mt-10 text-2xl font-semibold">What counts as an emergency</h2>

    <p class="mt-2 max-w-prose text-gray-700">
        Anything that puts someone in danger, makes the home unfit to live in tonight, or will
        cause serious damage if it waits until morning. If you are not sure, telephone — we would
        far rather answer a call that turns out to be nothing.
    </p>

    <p class="mt-4 max-w-prose text-gray-700">
        A dripping tap, an appliance that has stopped, or a repair that is inconvenient but safe
        is not an emergency. Those are dealt with faster through the repair form in your account,
        because they arrive with photographs and your access details already attached.
    </p>

    <div class="mt-6 flex flex-wrap gap-3">
        <a href="{{ route('login') }}"
           class="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            Report a non-urgent repair
        </a>
        <a href="{{ route('public.resources') }}"
           class="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 py-2 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            Resident resources
        </a>
    </div>
@endsection

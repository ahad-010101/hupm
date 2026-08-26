{{-- Available properties.  [BR-22]

     Admin-typed lines, never a query over vacant units. A public list of empty
     homes is a public statement about who is not at home, which is exactly what
     BR-22 forbids — so nothing here reaches a tenant table. --}}
<section class="py-12 sm:py-16">
    @if ($s['heading'] ?? '')
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">{{ $s['heading'] }}</h2>
    @endif

    @php
        $entries = array_values(array_filter(array_map('trim', explode("\n", (string) ($s['entries'] ?? '')))));
    @endphp

    @if ($entries)
        @if ($s['intro'] ?? '')
            <p class="mt-3 max-w-prose text-lg text-gray-700">{{ $s['intro'] }}</p>
        @endif

        <ul class="mt-8 grid gap-4 sm:grid-cols-2">
            @foreach ($entries as $entry)
                <li class="rounded-xl border border-gray-200 bg-white p-5 text-gray-800">{{ $entry }}</li>
            @endforeach
        </ul>
    @else
        <p class="mt-3 max-w-prose text-lg text-gray-700">
            {{ $s['empty_text'] ?? 'We have nothing advertised at the moment.' }}
        </p>
    @endif
</section>

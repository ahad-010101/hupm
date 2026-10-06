{{-- Call to action. One button, and it can only point somewhere on this site —
     the catalogue takes a route name, not a URL. --}}
<section class="hupm-reveal my-12 rounded-2xl bg-brand-700 px-6 py-12 text-center sm:my-16 sm:px-12">
    <h2 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">{{ $s['heading'] ?? '' }}</h2>

    @if ($s['body'] ?? '')
        <p class="mx-auto mt-3 max-w-2xl text-lg leading-relaxed text-brand-50">{{ $s['body'] }}</p>
    @endif

    {{-- [WP-54] Same resolution as the hero: a login button shown to somebody
         already signed in becomes the way back to their account. --}}
    @if (($s['primary_label'] ?? '') && Route::has($s['primary_route'] ?? ''))
        @php $primary = App\Support\PublicAction::resolve($s['primary_route'], $s['primary_label']); @endphp
        <a href="{{ $primary['href'] }}"
           class="hupm-nudge mt-7 inline-flex min-h-touch items-center justify-center rounded-md bg-white px-6 py-3 text-base font-semibold text-brand-700 hover:bg-brand-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
            {{ $primary['label'] }}
        </a>
    @endif
</section>

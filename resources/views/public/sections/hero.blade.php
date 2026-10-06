{{-- Hero. The opening block: one heading, one sentence, two ways forward. --}}
<section class="border-b border-gray-200 py-14 sm:py-20">
    <div class="max-w-3xl">
        @if ($s['eyebrow'] ?? '')
            <p class="hupm-enter text-sm font-semibold uppercase tracking-widest text-brand-700">{{ $s['eyebrow'] }}</p>
        @endif

        <h1 class="hupm-enter hupm-enter-1 mt-3 text-4xl font-semibold leading-tight tracking-tight text-gray-900 sm:text-5xl">
            {{ $s['heading'] ?? '' }}
        </h1>

        @if ($s['body'] ?? '')
            <p class="hupm-enter hupm-enter-2 mt-5 text-lg leading-relaxed text-gray-700 sm:text-xl">{{ $s['body'] }}</p>
        @endif

        @if (($s['primary_label'] ?? '') || ($s['secondary_label'] ?? ''))
            <div class="hupm-enter hupm-enter-3 mt-8 flex flex-col gap-3 sm:flex-row">
                {{-- [WP-54] A "Resident login" button offered to somebody
                     already logged in. Resolved through PublicAction so the
                     hero and the CTA band cannot drift apart. --}}
                @if (($s['primary_label'] ?? '') && Route::has($s['primary_route'] ?? ''))
                    @php $primary = App\Support\PublicAction::resolve($s['primary_route'], $s['primary_label']); @endphp
                    <a href="{{ $primary['href'] }}"
                       class="hupm-nudge inline-flex min-h-touch items-center justify-center rounded-md bg-brand-600 px-6 py-3 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        {{ $primary['label'] }}
                    </a>
                @endif

                @if (($s['secondary_label'] ?? '') && Route::has($s['secondary_route'] ?? ''))
                    @php $secondary = App\Support\PublicAction::resolve($s['secondary_route'], $s['secondary_label']); @endphp
                    <a href="{{ $secondary['href'] }}"
                       class="hupm-nudge inline-flex min-h-touch items-center justify-center rounded-md border border-gray-300 px-6 py-3 text-base font-medium text-gray-900 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        {{ $secondary['label'] }}
                    </a>
                @endif
            </div>
        @endif
    </div>
</section>

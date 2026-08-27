{{-- Text. The only block that renders stored HTML — and it is safe to, because
     SectionCatalogue sanitised it on the way in through HtmlSanitiser, whose
     allowlist carries no img, no class and no style. Sanitise-on-write is the
     same rule NoticeService follows: what is stored is already safe, so a
     renderer added later cannot forget. --}}
<section class="hupm-reveal border-t border-gray-200 py-12 sm:py-16">
    @if ($s['heading'] ?? '')
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">{{ $s['heading'] }}</h2>
    @endif

    <div class="prose-public mt-4 max-w-prose space-y-4 text-lg leading-relaxed text-gray-700">
        {!! $s['body'] ?? '' !!}
    </div>
</section>

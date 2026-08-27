{{-- Emergency notice. The number is never typed into content — it comes from
     `company.emergency_phone`, so it is right in one place and changing it does
     not mean hunting through pages. --}}
<section class="hupm-reveal my-10">
    <div class="rounded-xl border-2 border-overdue-border bg-overdue-bg p-6">
        <p class="text-lg font-semibold text-overdue-fg">
            {{ $s['heading'] ?? 'Is this an emergency?' }}
        </p>

        <p class="mt-2 max-w-prose leading-relaxed text-gray-800">
            {{ $s['body'] ?? '' }}
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3">
            @if ($company['emergency_phone'])
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $company['emergency_phone']) }}"
                   class="inline-flex min-h-touch items-center text-2xl font-bold text-overdue-fg underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg">
                    {{ $company['emergency_phone'] }}
                </a>
            @endif

            <a href="{{ route('public.emergency') }}"
               class="inline-flex min-h-touch items-center font-semibold text-gray-900 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg">
                What to do right now
            </a>
        </div>
    </div>
</section>

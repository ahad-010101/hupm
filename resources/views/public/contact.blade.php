{{--
    Contact Us.  [FR-PUB-01, AC-PUB-02, API-PUB-05/06, UI §1, D-05]

    A plain server-side form. No JavaScript, no client-side validation, no
    hosted captcha — which is a constraint rather than a preference, and the
    reason the bot protection is two server-side traps instead.

    The telephone comes before the form. Somebody standing in a hallway with a
    problem should not have to fill anything in.
--}}
@extends('public.layout')

@section('title', 'Contact Us — '.$company['name'])
@section('meta_description', 'Telephone, write to, or email the office.')

@section('content')
    <h1 class="text-3xl font-semibold">Contact us</h1>

    @if (session('status'))
        {{-- Neutral rather than green. The green token is `credit`, which means
             money in a resident's favour — borrowing it for "your email
             arrived" would be exactly the drift the colour system exists to
             prevent. The words and role="status" carry the meaning, which is
             what UI §9 asks for anyway. --}}
        <div role="status" class="mt-6 rounded-lg border-2 border-brand-600 bg-brand-50 p-4">
            <p class="font-semibold text-brand-700">Message sent</p>
            <p class="mt-1 text-gray-800">{{ session('status') }}</p>
        </div>
    @endif

    <div class="mt-6 grid gap-8 lg:grid-cols-2">

        {{-- Reaching a person, first. --}}
        <div>
            <h2 class="text-2xl font-semibold">Speak to the office</h2>

            <dl class="mt-4 space-y-4">
                @if ($company['phone'])
                    <div>
                        <dt class="font-semibold">Telephone</dt>
                        <dd class="mt-1">
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $company['phone']) }}"
                               class="inline-flex min-h-touch items-center text-2xl font-bold underline
                                      focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                                {{ $company['phone'] }}
                            </a>
                        </dd>
                    </div>
                @endif

                @if ($company['office_hours'])
                    <div>
                        <dt class="font-semibold">Office hours</dt>
                        <dd class="mt-1 text-gray-700">{{ $company['office_hours'] }}</dd>
                    </div>
                @endif

                @if ($company['address'])
                    <div>
                        <dt class="font-semibold">Write to us</dt>
                        <dd class="mt-1 text-gray-700">{{ $company['address'] }}</dd>
                    </div>
                @endif
            </dl>

            <div class="mt-6 rounded-lg border border-overdue-border bg-overdue-bg p-4">
                <p class="font-semibold text-overdue-fg">Is this an emergency?</p>
                <p class="mt-1 text-gray-800">
                    A leak, no heat, no power or a gas smell needs a telephone call, not this form.
                </p>
                <p class="mt-2">
                    <a href="{{ route('public.emergency') }}"
                       class="font-semibold underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg">
                        Emergency maintenance instructions
                    </a>
                </p>
            </div>

            <div class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p class="font-semibold">Already a resident?</p>
                <p class="mt-1 text-gray-700">
                    Repairs, payments and documents are all handled faster from your account than
                    through this form.
                </p>
                <p class="mt-2">
                    <a href="{{ route('login') }}" class="font-semibold underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        Sign in
                    </a>
                </p>
            </div>
        </div>

        {{-- The form. --}}
        <div>
            <h2 class="text-2xl font-semibold">Send a message</h2>

            @unless ($canSend)
                {{-- [GATE] company.email is unset, so there is nowhere to
                     deliver. A form that accepts a message and drops it is
                     worse than no form: the sender believes they have been in
                     touch. --}}
                <div class="mt-4 rounded-lg border border-gray-300 bg-gray-50 p-4">
                    <p class="font-semibold">The message form is not available yet</p>
                    <p class="mt-1 text-gray-700">
                        Please telephone the office
                        @if ($company['phone'])
                            on
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $company['phone']) }}" class="font-semibold underline">
                                {{ $company['phone'] }}</a>
                        @endif
                        and we will help.
                    </p>
                </div>
            @else
                @if ($errors->any())
                    <div role="alert" class="mt-4 rounded-lg border border-overdue-border bg-overdue-bg p-4">
                        <p class="font-semibold text-overdue-fg">Your message has not been sent</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-800">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-gray-800">Nothing you typed has been lost.</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('public.contact.send') }}" class="mt-4">
                    @csrf

                    {{-- The honeypot. Hidden from sight and from assistive
                         technology, and skipped by the keyboard, so no person
                         reaches it — while a bot filling every field does.
                         `hidden` alone would be skipped by some bots too, which
                         is why it is off-screen instead. --}}
                    <div aria-hidden="true" class="absolute left-[-9999px] top-auto h-px w-px overflow-hidden">
                        <label for="website">Website</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
                    </div>

                    {{-- When the form was served. A submission that arrives
                         faster than the page can be read did not come from a
                         reader. --}}
                    <input type="hidden" name="started_at" value="{{ old('started_at', $startedAt) }}">

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="name" class="mb-1 block text-base font-medium">
                                Your name <span aria-hidden="true" class="text-overdue-fg">*</span>
                                <span class="sr-only">(required)</span>
                            </label>
                            <input type="text" id="name" name="name" required maxlength="120"
                                   autocomplete="name" value="{{ old('name') }}"
                                   @if ($errors->has('name')) aria-invalid="true" @endif
                                   class="block min-h-touch w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600">
                        </div>

                        <div>
                            <label for="email" class="mb-1 block text-base font-medium">
                                Email <span aria-hidden="true" class="text-overdue-fg">*</span>
                                <span class="sr-only">(required)</span>
                            </label>
                            <input type="email" id="email" name="email" required maxlength="190"
                                   autocomplete="email" value="{{ old('email') }}"
                                   @if ($errors->has('email')) aria-invalid="true" @endif
                                   class="block min-h-touch w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600">
                        </div>

                        <div>
                            <label for="phone" class="mb-1 block text-base font-medium">Telephone</label>
                            <p id="phone-hint" class="mb-1 text-sm text-gray-600">Optional.</p>
                            <input type="tel" id="phone" name="phone" maxlength="40"
                                   autocomplete="tel" value="{{ old('phone') }}" aria-describedby="phone-hint"
                                   class="block min-h-touch w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600">
                        </div>

                        <div>
                            <label for="subject" class="mb-1 block text-base font-medium">
                                Subject <span aria-hidden="true" class="text-overdue-fg">*</span>
                                <span class="sr-only">(required)</span>
                            </label>
                            <input type="text" id="subject" name="subject" required maxlength="150"
                                   value="{{ old('subject') }}"
                                   @if ($errors->has('subject')) aria-invalid="true" @endif
                                   class="block min-h-touch w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600">
                        </div>
                    </div>

                    <div class="mt-4">
                        <label for="message" class="mb-1 block text-base font-medium">
                            Message <span aria-hidden="true" class="text-overdue-fg">*</span>
                            <span class="sr-only">(required)</span>
                        </label>
                        <p id="message-hint" class="mb-1 text-sm text-gray-600">
                            If you are asking about a home, tell us how many bedrooms you need and
                            whether you hold a voucher.
                        </p>
                        <textarea id="message" name="message" rows="6" required minlength="10" maxlength="4000"
                                  aria-describedby="message-hint"
                                  @if ($errors->has('message')) aria-invalid="true" @endif
                                  class="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600">{{ old('message') }}</textarea>
                    </div>

                    <p class="mt-3 text-sm text-gray-600">
                        Please do not send bank account or card details. We will never ask for them
                        by email.
                    </p>

                    <button type="submit"
                            class="mt-4 inline-flex min-h-touch items-center rounded-md bg-brand-600 px-5 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        Send message
                    </button>
                </form>
            @endunless
        </div>
    </div>
@endsection

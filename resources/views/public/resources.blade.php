{{-- Resident Resources.  [FR-PUB-01, UI §1]

     A signpost page. Everything a resident might be looking for that is not
     behind the login, in the order they are likely to want it. --}}
@extends('public.layout')

@section('title', 'Resident Resources — '.$company['name'])
@section('meta_description', 'Paying rent, reporting repairs, your rights as a Georgia tenant, and who to contact.')

@section('content')
    <h1 class="text-3xl font-semibold">Resident resources</h1>
    <p class="mt-3 max-w-prose text-lg text-gray-700">
        The things residents ask us about most often.
    </p>

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <a href="{{ route('public.emergency') }}"
           class="rounded-lg border border-overdue-border bg-overdue-bg p-5 hover:border-overdue-fg focus-visible:outline focus-visible:outline-2 focus-visible:outline-overdue-fg">
            <p class="text-lg font-semibold text-overdue-fg">Emergency maintenance</p>
            <p class="mt-1 text-gray-700">A leak, no heat, no power, a lockout — what to do and who to call.</p>
        </a>

        <a href="{{ route('public.georgia') }}"
           class="rounded-lg border border-gray-200 p-5 hover:border-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600">
            <p class="text-lg font-semibold">Georgia rental information</p>
            <p class="mt-1 text-gray-700">Your rights as a tenant, and where to get independent help.</p>
        </a>
    </div>

    <h2 class="mt-10 text-2xl font-semibold">Paying rent</h2>
    <div class="mt-4 max-w-prose space-y-3 text-gray-700">
        <p>
            Rent is paid online from your account, by bank transfer. You can pay at any hour,
            including weekends.
        </p>
        <p>
            <strong class="text-gray-900">Bank payments take two to five business days to clear.</strong>
            Until they do, your balance still shows the full amount with the payment listed as
            processing beneath it. That is normal, and there is nothing further to do. If you are
            paying close to the due date, allow for it.
        </p>
        <p>
            If you cannot pay in full, telephone the office before the due date rather than after.
            An arrangement agreed in advance is a very different conversation from one agreed
            afterwards.
        </p>
    </div>

    <h2 class="mt-10 text-2xl font-semibold">Repairs that are not urgent</h2>
    <div class="mt-4 max-w-prose space-y-3 text-gray-700">
        <p>
            Report these from your account rather than by telephone. The form asks for photographs,
            when the problem started, when you are home, and whether we may let ourselves in —
            which is most of what a contractor needs to arrive prepared.
        </p>
        <p>
            You will get a number, and you will see the job move through triage, scheduling and
            completion. <strong class="text-gray-900">You close it, not us</strong>: when we think
            it is finished we ask you to confirm, and it stays open until you do.
        </p>
    </div>

    <h2 class="mt-10 text-2xl font-semibold">If your circumstances change</h2>
    <div class="mt-4 max-w-prose space-y-3 text-gray-700">
        <p>
            Tell us and tell your housing authority. A change in income or household usually changes
            your share of the rent, and the sooner it is recorded the smaller the correction.
        </p>
        <p>
            Keep your telephone number and email up to date in your account. Notices, receipts and
            repair updates all go to whatever is there.
        </p>
    </div>

    <div class="mt-8 flex flex-wrap gap-3">
        <a href="{{ route('login') }}"
           class="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            Go to my account
        </a>
        <a href="{{ route('public.contact') }}"
           class="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 py-2 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            Contact the office
        </a>
    </div>
@endsection

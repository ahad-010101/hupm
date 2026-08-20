{{--
    Payment arrangement agreement.  [BR-19, FR-ARR-02, AC-ARR-04]

    Every one of the nine required elements is present and individually
    labelled, because AC-ARR-04 is asserted by parsing this document's text
    rather than by looking at it. The headings below are what the test greps
    for; renaming one silently is how an element goes missing.

    Plain HTML and inline styles: dompdf supports a narrow slice of CSS, and
    this document is signed and may be read back in a Georgia dispossessory.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment arrangement</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; line-height: 1.5; }
        h1 { font-size: 17px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 14px 0 4px; border-bottom: 1px solid #9ca3af; padding-bottom: 2px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        td, th { padding: 5px 4px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        .right { text-align: right; }
        .sign { margin-top: 10px; border: 1px solid #9ca3af; padding: 10px; }
        .line { border-bottom: 1px solid #4b5563; height: 22px; margin-top: 14px; }
    </style>
</head>
<body>
    <h1>Payment arrangement</h1>
    <div class="muted">
        Between {{ $company['name'] }} ("the landlord") and {{ $tenant->fullName() }} ("the resident")
        for {{ $address }}.
    </div>
    <div class="muted">Agreed {{ $agreedOn->format('j F Y') }}.</div>

    {{-- 1 --}}
    <h2>1. Total amount owed</h2>
    <p>
        The resident owes <strong>{{ $arrangement->total_owed->format() }}</strong> as at
        {{ $agreedOn->format('j F Y') }}.
    </p>

    {{-- 2 --}}
    <h2>2. Amount accepted today</h2>
    <p>
        The landlord accepts <strong>{{ $arrangement->amount_accepted->format() }}</strong> today as a
        part payment. Accepting it does not waive the balance below, and does not waive the
        landlord's rights in respect of it.
    </p>

    {{-- 3 --}}
    <h2>3. Remaining balance</h2>
    <p>
        After that payment <strong>{{ $arrangement->remaining_balance->format() }}</strong> remains
        owing and is payable as set out below.
    </p>

    {{-- 4 --}}
    <h2>4. Payment dates</h2>
    @if (! empty($schedule))
        <table>
            <thead><tr><th>Due</th><th class="right">Amount</th></tr></thead>
            <tbody>
                @foreach ($schedule as $instalment)
                    <tr>
                        <td>{{ $instalment['due_on_label'] }}</td>
                        <td class="right">{{ $instalment['amount_label'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>The remaining balance is payable in full immediately.</p>
    @endif
    <p class="muted">
        Rent falling due after today remains payable on its normal date and is not covered by this
        arrangement.
    </p>

    {{-- 5 --}}
    <h2>5. Late fees</h2>
    <p>
        @if ($arrangement->late_fees_continue)
            Late fees under the lease <strong>continue to apply</strong> to any amount that is not
            paid on the dates above. This arrangement does not suspend them.
        @else
            The landlord agrees <strong>not to charge further late fees</strong> on the remaining
            balance for as long as the payments above are made on time. If a payment is missed,
            late fees under the lease resume.
        @endif
    </p>

    {{-- 6 --}}
    <h2>6. If a payment is missed</h2>
    <p>
        Missing any payment above, or paying less than the amount stated, places this arrangement in
        default. On default the whole remaining balance becomes immediately due, and the landlord
        may act on it without further notice under this agreement.
    </p>

    {{-- 7 --}}
    <h2>7. Consequences of breach</h2>
    <p>{{ $arrangement->default_terms }}</p>
    <p>
        Nothing in this arrangement waives any right the landlord has under the lease or under
        Georgia law, and nothing in it prevents the resident from paying the balance sooner.
    </p>

    {{-- 8 and 9 --}}
    <h2>8. Signatures</h2>

    <div class="sign">
        <strong>Resident</strong>
        <div class="line"></div>
        <div class="muted">{{ $tenant->fullName() }} — signature and date</div>
    </div>

    <div class="sign">
        <strong>Landlord</strong>
        <div class="line"></div>
        <div class="muted">{{ $company['name'] }} — signature and date</div>
    </div>

    <p class="muted" style="margin-top:14px;">
        Prepared {{ $agreedOn->format('j F Y') }} and approved by {{ $approver }}. A signed copy is
        kept in the resident's document vault and a copy is provided to the resident.
    </p>
</body>
</html>

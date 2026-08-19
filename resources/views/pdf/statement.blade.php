{{--
    Tenant account statement.  [FR-POR-02, API-POR-03]

    INVARIANT I-4: the rows arrive already filtered to `payer = tenant` by the
    controller's query. There is no housing-authority figure to omit here
    because none was ever selected.

    Deliberately plain HTML and inline styles: dompdf supports a narrow slice of
    CSS, and a statement that renders wrong is a document someone brings to a
    hearing.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Account statement</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th { text-align: left; border-bottom: 1px solid #9ca3af; padding: 6px 4px; font-size: 10px; text-transform: uppercase; }
        td { padding: 6px 4px; border-bottom: 1px solid #e5e7eb; }
        .right { text-align: right; }
        .totals { margin-top: 18px; padding-top: 10px; border-top: 2px solid #1f2937; }
        .totals td { border: 0; padding: 3px 4px; }
    </style>
</head>
<body>
    <h1>{{ $company['name'] }}</h1>
    @if ($company['address'])
        <div class="muted">{{ $company['address'] }}</div>
    @endif
    @if ($company['phone'])
        <div class="muted">{{ $company['phone'] }}</div>
    @endif

    <div style="margin-top:18px;">
        <strong>Account statement</strong> for {{ $tenant->fullName() }}<br>
        <span class="muted">
            @if ($from || $to)
                {{ $from?->format('j F Y') ?? 'the beginning' }} to {{ $to?->format('j F Y') ?? 'today' }} ·
            @endif
            Produced {{ $generatedOn->format('j F Y') }}
        </span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th class="right">Charge</th>
                <th class="right">Payment</th>
                <th class="right">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td>{{ $entry['date'] }}</td>
                    <td>
                        {{ $entry['description'] }}
                        {{-- Stated by the server, and never the word "failed" (UI §8). --}}
                        @if (! empty($entry['state']))
                            <span class="muted">— {{ $entry['state'] }}</span>
                        @endif
                    </td>
                    <td class="right">{{ $entry['charge'] ? '$'.$entry['charge'] : '' }}</td>
                    <td class="right">{{ $entry['payment'] ? '$'.$entry['payment'] : '' }}</td>
                    <td class="right">{{ $entry['counts'] ? '$'.$entry['running'] : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No transactions in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td><strong>Balance</strong></td>
            <td class="right">
                {{-- A credit reads as a credit, never as a minus figure (UI §7). --}}
                <strong>
                    @if ($balance->isNegative())
                        Credit {{ $balance->absolute()->format() }}
                    @else
                        {{ $balance->format() }}
                    @endif
                </strong>
            </td>
        </tr>
        @if ($pending->isPositive())
            <tr>
                <td class="muted">Processing with your bank</td>
                <td class="right muted">{{ $pending->format() }}</td>
            </tr>
            <tr>
                <td colspan="2" class="muted">
                    Bank payments take 2 to 5 business days to clear. The balance above does not
                    include anything still processing.
                </td>
            </tr>
        @endif
    </table>
</body>
</html>

{{--
    A report as a PDF.  [API-ADM-35]

    Landscape, because these tables are wide and a rent roll broken across two
    portrait pages is unreadable. Deliberately plain: this is a document that
    gets printed, filed and occasionally shown to somebody's accountant.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        @page { margin: 14mm 12mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5pt;
            color: #16191c;
            margin: 0;
        }

        .masthead { border-bottom: 1.5pt solid #16191c; padding-bottom: 5pt; margin-bottom: 10pt; }
        .company { font-size: 8pt; letter-spacing: 1pt; text-transform: uppercase; color: #5a6472; }
        h1 { font-size: 15pt; margin: 3pt 0 1pt; }
        .subtitle { font-size: 9.5pt; color: #5a6472; }
        .taken { font-size: 7.5pt; color: #5a6472; margin-top: 2pt; }

        table { width: 100%; border-collapse: collapse; }

        th {
            text-align: left;
            font-size: 7pt;
            letter-spacing: .5pt;
            text-transform: uppercase;
            color: #5a6472;
            border-bottom: .8pt solid #9aa3ad;
            padding: 3pt 4pt;
        }

        td { padding: 3pt 4pt; border-bottom: .4pt solid #dfe2e6; }
        td.money, th.money { text-align: right; }

        tr.totals td {
            border-top: .8pt solid #16191c;
            border-bottom: none;
            font-weight: bold;
            padding-top: 4pt;
        }

        .notes { margin-top: 10pt; font-size: 7.5pt; color: #5a6472; }
        .notes p { margin: 0 0 3pt; }

        .empty { padding: 14pt 0; color: #5a6472; font-size: 9pt; }
    </style>
</head>
<body>

<div class="masthead">
    <div class="company">{{ $company }}</div>
    <h1>{{ $report->title }}</h1>
    <div class="subtitle">{{ $report->subtitle }}</div>
    <div class="taken">Taken {{ $takenAt }}</div>
</div>

@if (count($report->rows) === 0)
    <p class="empty">Nothing to report for this period.</p>
@else
    <table>
        <thead>
            <tr>
                @foreach ($report->columns as $column)
                    <th class="{{ ($column['money'] ?? false) ? 'money' : '' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report->rows as $row)
                <tr>
                    @foreach ($report->columns as $column)
                        <td class="{{ ($column['money'] ?? false) ? 'money' : '' }}">
                            {{ ($column['money'] ?? false) ? '$'.($row[$column['key']] ?? '') : ($row[$column['key']] ?? '') }}
                        </td>
                    @endforeach
                </tr>
            @endforeach

            @if ($report->totals !== [])
                <tr class="totals">
                    @foreach ($report->columns as $column)
                        <td class="{{ ($column['money'] ?? false) ? 'money' : '' }}">
                            {{ ($column['money'] ?? false) && ($report->totals[$column['key']] ?? '') !== ''
                                ? '$'.$report->totals[$column['key']]
                                : ($report->totals[$column['key']] ?? '') }}
                        </td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>
@endif

@if ($report->notes !== [])
    <div class="notes">
        @foreach ($report->notes as $note)
            <p>{{ $note }}</p>
        @endforeach
    </div>
@endif

</body>
</html>

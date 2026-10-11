{{--
    The console's printable report.

    Deliberately self-contained: no layout, no asset pipeline, no Tailwind build.
    It is fetched into a new tab and handed to the browser's own "Save as PDF",
    so it has to render correctly with nothing but itself. The print rules hide
    the toolbar and stop a row's contents from splitting across a page break,
    which is the difference between a table a reader can use and one they cannot.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $range->from->format('j M Y') }} to {{ $range->to->format('j M Y') }}</title>
    <style>
        :root {
            --ink: #0F172A;
            --muted: #64748B;
            --line: #E2E8F0;
            --accent: #0F766E;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 32px;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: var(--ink);
            background: #fff;
        }

        header { margin-bottom: 20px; border-bottom: 2px solid var(--ink); padding-bottom: 12px; }

        h1 { margin: 0 0 4px; font-size: 18px; letter-spacing: -0.01em; }

        .meta { color: var(--muted); font-size: 11px; }

        .meta strong { color: var(--ink); font-weight: 600; }

        table { width: 100%; border-collapse: collapse; }

        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }

        th {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            border-bottom: 1px solid var(--ink);
        }

        tbody tr:nth-child(even) { background: #F8FAFC; }

        td.numeric { text-align: right; font-variant-numeric: tabular-nums; }

        .empty { padding: 32px; text-align: center; color: var(--muted); }

        .toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 16px;
        }

        .toolbar button {
            font: inherit;
            padding: 6px 14px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--ink);
            cursor: pointer;
        }

        .toolbar button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }

        footer { margin-top: 24px; color: var(--muted); font-size: 10px; }

        @page { margin: 14mm; }

        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.close()">Close</button>
        <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <header>
        <h1>{{ $title }}</h1>
        <p class="meta">
            <strong>{{ $range->from->format('j M Y') }}</strong>
            to
            <strong>{{ $range->to->format('j M Y') }}</strong>
            · {{ $rows->count() }} {{ Str::plural('row', $rows->count()) }}
            · generated {{ $generatedAt->format('j M Y H:i') }}
            @if ($generatedBy)
                by {{ $generatedBy }}
            @endif
        </p>
    </header>

    @if ($rows->isEmpty())
        <p class="empty">No rows matched these filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th @class(['numeric' => in_array($column['key'], ['agreed_total', 'rating_avg'], true)])>
                            {{ $column['label'] }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            @php($value = $row[$column['key']] ?? null)
                            <td @class(['numeric' => in_array($column['key'], ['agreed_total', 'rating_avg'], true)])>
                                {{ $value === null || $value === '' ? '—' : $value }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <footer>
        HomeFix Connect · {{ $title }} report · this document was generated from live platform data.
    </footer>
</body>
</html>

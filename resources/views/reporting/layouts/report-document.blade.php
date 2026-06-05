{{--
    Shared report-document furniture (frozen §4.2). Every report PDF renders
    through THIS layout; the report only supplies its sections/columns/totals via
    the dataset. Structural skeleton only — per-report visual polish is Phase 1.

    Reuses the app's teal/hairline tokens (teal #0d9488, hairline #e7ebf1,
    ink #0f172a). Page header/footer repeat on every page via the CSS paged-media
    box and a running table header; rows never split (see section-table partial).

    Expected variables:
      $meta       ReportMeta
      $dataset    ReportDataset
      $columnsFor callable(string $sectionKey): ColumnDefinition[]
      $formatter  ValueFormatter
      $grandTotals  array<string, array{label:string, type:ColumnType, value:mixed}>  (optional)
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $meta->title }}</title>
    <style>
        @page {
            size: A4;
            margin: 22mm 14mm 20mm 14mm;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            font-family: 'DejaVu Sans', 'Noto Sans', Arial, sans-serif;
            color: #0f172a;
            font-size: 10px;
            line-height: 1.4;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .report-doc-header {
            border-bottom: 2px solid #0d9488;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .rh-shop-name {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 2px;
        }
        .rh-shop-meta {
            color: #475569;
            font-size: 9px;
            margin: 0 0 1px;
        }
        .rh-gstin { font-weight: 600; color: #0f172a; }
        .rh-report-bar {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 8px;
        }
        .rh-report-name {
            font-size: 13px;
            font-weight: 700;
            color: #0d9488;
            margin: 0;
        }
        .rh-period { font-size: 10px; color: #0f172a; font-weight: 600; }
        .rh-profile {
            font-size: 9px;
            color: #475569;
            text-align: right;
        }
        .rh-filters {
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid #e7ebf1;
            font-size: 9px;
            color: #475569;
        }
        .rh-filters .rf-key { color: #0f172a; font-weight: 600; }

        .report-section { margin: 0 0 14px; }
        .report-section-title {
            font-size: 11px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px;
            padding-bottom: 2px;
            border-bottom: 1px solid #e7ebf1;
        }

        table.report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }
        table.report-table thead { display: table-header-group; }
        table.report-table tfoot { display: table-row-group; }
        table.report-table th {
            background: #f0fdfa;
            color: #0f172a;
            font-weight: 700;
            font-size: 9px;
            text-align: left;
            padding: 4px 6px;
            border-bottom: 1.5px solid #0d9488;
            white-space: nowrap;
        }
        table.report-table td {
            padding: 3px 6px;
            border-bottom: 1px solid #e7ebf1;
            font-size: 9px;
            vertical-align: top;
        }
        table.report-table tr { page-break-inside: avoid; break-inside: avoid; }
        table.report-table th.num,
        table.report-table td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-feature-settings: "tnum";
            white-space: nowrap;
        }
        .report-subtotal td {
            font-weight: 700;
            border-top: 1.5px solid #0d9488;
            border-bottom: none;
            background: #f8fafc;
        }
        .report-grand-total {
            margin-top: 10px;
            border-top: 2px solid #0d9488;
        }
        .report-grand-total table { width: 100%; border-collapse: collapse; }
        .report-grand-total td {
            padding: 5px 6px;
            font-weight: 700;
            font-size: 10px;
        }
        .report-grand-total td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .report-empty-row td {
            text-align: center;
            color: #94a3b8;
            font-style: italic;
            padding: 10px;
        }

        /* Footer / provenance repeats on every page via the bottom page-margin box. */
        .report-footer-grid {
            position: running(reportFooter);
            display: flex;
            justify-content: space-between;
            font-size: 7.5px;
            color: #64748b;
            border-top: 1px solid #e7ebf1;
            padding-top: 4px;
        }
        @page {
            @bottom-center { content: element(reportFooter); }
        }
        .rf-col { display: flex; flex-direction: column; }
        .rf-center { text-align: center; }
        .rf-right { text-align: right; }
        .rf-label { color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; font-size: 6.5px; }
        .rf-value { color: #0f172a; font-weight: 600; }
        .rf-system { font-weight: 600; color: #475569; }
        .rf-tag { color: #94a3b8; }
        .rf-page-num::before { content: counter(page); }
        .rf-page-count::before { content: counter(pages); }

        .report-watermark {
            position: fixed;
            top: 45%;
            left: 0;
            right: 0;
            text-align: center;
            transform: rotate(-30deg);
            transform-origin: center;
            font-size: 90px;
            font-weight: 800;
            letter-spacing: .1em;
            color: rgba(13, 148, 136, 0.08);
            z-index: 0;
            pointer-events: none;
            text-transform: uppercase;
        }
        .report-body { position: relative; z-index: 1; }
    </style>
</head>
<body>
    @if($meta->hasWatermark())
        @include('reporting.partials.watermark', ['meta' => $meta])
    @endif

    {{-- Footer element — pulled into the page bottom margin on every page. --}}
    @include('reporting.partials.provenance', ['meta' => $meta])

    <header class="report-doc-header">
        <p class="rh-shop-name">{{ $meta->shopLegalName }}</p>
        @if($meta->shopAddress)
            <p class="rh-shop-meta">{{ $meta->shopAddress }}</p>
        @endif
        @if($meta->shopGstin)
            <p class="rh-shop-meta">
                <span class="rh-gstin">GSTIN: {{ $meta->shopGstin }}</span>
                @if($meta->shopStateCode)
                    &nbsp;·&nbsp; State code: {{ $meta->shopStateCode }}
                @endif
            </p>
        @endif

        <div class="rh-report-bar">
            <div>
                <p class="rh-report-name">{{ $meta->title }}</p>
                @if($meta->periodLabel)
                    <span class="rh-period">{{ $meta->periodLabel }}</span>
                @endif
            </div>
            <div class="rh-profile">
                <div>Profile: {{ $meta->profileLabel }}</div>
                <div>Format: {{ ucfirst($meta->format) }}</div>
            </div>
        </div>

        @if(!empty($meta->filtersApplied))
            <div class="rh-filters">
                <span class="rf-applied-label">Filters applied: </span>
                @foreach($meta->filtersApplied as $key => $value)
                    <span class="rf-pair"><span class="rf-key">{{ $key }}:</span> {{ $value }}</span>@if(!$loop->last) &nbsp;·&nbsp; @endif
                @endforeach
            </div>
        @endif
    </header>

    <main class="report-body">
        @foreach($dataset->sections as $section)
            @include('reporting.partials.section-table', [
                'section'   => $section,
                'columns'   => $columnsFor($section->key),
                'formatter' => $formatter,
            ])
        @endforeach

        @if(!empty($grandTotals))
            <div class="report-grand-total">
                <table>
                    <tr>
                        <td class="text">Grand total</td>
                        @foreach($grandTotals as $cell)
                            <td class="num">{{ $formatter->format($cell['value'], $cell['type']) }}</td>
                        @endforeach
                    </tr>
                </table>
            </div>
        @endif
    </main>
</body>
</html>

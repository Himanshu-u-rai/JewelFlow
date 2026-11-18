<x-app-layout>
<style>
    .ye-wrap { max-width: 960px; margin: 0 auto; padding: 24px 16px; }
    .ye-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-top:16px; overflow-x:auto; }
    .ye-card h2 { font-size:15px; font-weight:700; margin:0 0 10px; }
    .ye-table { width:100%; border-collapse:collapse; font-size:13px; }
    .ye-table th, .ye-table td { text-align:left; padding:6px 8px; border-bottom:1px solid #f1f5f9; white-space:nowrap; }
    .ye-table td.num, .ye-table th.num { text-align:right; }
    .ye-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
    .ye-tabs a { padding:6px 12px; border-radius:999px; border:1px solid #e5e7eb; font-size:13px; text-decoration:none; color:#374151; }
    .ye-tabs a.is-on { background:#111827; color:#fff; border-color:#111827; }
    .ye-error { color:#b91c1c; font-size:13px; margin-top:8px; }
    .ye-muted { color:#6b7280; font-size:13px; }
</style>
@php
    $fmt = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $wt = fn ($v) => $v === null ? '—' : number_format((float) $v, 3);
@endphp
<div class="ye-wrap">
    <h1 class="text-2xl font-bold text-gray-900">{{ __('Year-end snapshot') }}</h1>
    <p class="ye-muted" style="margin-top:4px;">{{ __('What the shop held on :date, valued at the lower of cost and the closing rate.', ['date' => \Carbon\CarbonImmutable::parse($year->endDate())->format('d M Y')]) }}</p>

    <nav class="ye-tabs" aria-label="{{ __('Financial year') }}">
        @foreach($years as $y)
            <a href="{{ route('books.year-end', ['fy' => $y->label()]) }}" class="{{ $y->equals($year) ? 'is-on' : '' }}">{{ $y->label() }}</a>
        @endforeach
    </nav>

    @if(session('success'))<p class="ye-muted" role="status" style="margin-top:10px;">{{ session('success') }}</p>@endif
    @error('snapshot')<p class="ye-error" role="alert">{{ $message }}</p>@enderror

    {{-- Before you close: what is still open in this year. Warnings only; the owner decides. --}}
    @php $openChecks = collect($checklist)->filter(fn ($c) => $c['count'] > 0); @endphp
    <section class="ye-card">
        <h2>{{ __('Before you close') }} {{ $year->label() }}</h2>
        @if($openChecks->isEmpty())
            <p class="ye-muted">{{ __('Nothing is left open in this year.') }}</p>
        @else
            <ul style="margin:0; padding-left:18px; font-size:14px; line-height:1.7;">
                @foreach($openChecks as $c)
                    <li>
                        @if($c['key'] === 'unknown_cost_items' && auth()->user()?->can('inventory.edit') && auth()->user()?->can('reports.money'))
                            <a href="{{ route('inventory.missing-costs') }}" style="color:#1d4ed8; text-decoration:underline; font-weight:600;">{{ $c['label'] }} &rarr; {{ __('Enter the costs') }}</a>
                        @else
                            {{ $c['label'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="ye-muted" style="margin-top:8px;">{{ __('You can still take a closing snapshot; these will show as changes if they are finished later.') }}</p>
        @endif
    </section>

    <section class="ye-card">
        <h2>{{ __('Financial year') }} {{ $year->label() }}</h2>
        @if($historyWarning)
            <div role="alert" style="background:#fffbeb; border:1px solid #f59e0b; border-radius:8px; padding:10px 12px; margin-bottom:12px; font-size:13px; color:#92400e;">
                {{ $historyWarning }}
            </div>
        @endif
        @if(! $snapshot)
            <p>{{ __('No snapshot yet') }}</p>
        @else
            <p class="ye-muted">
                {{ __('Version') }} {{ $snapshot->version }} ({{ $snapshot->kind === 'automatic' ? __('automatic') : __('closing') }}),
                {{ __('taken') }} {{ \Carbon\CarbonImmutable::parse($snapshot->taken_at)->format('d M Y H:i') }}
            </p>
            <p style="margin-top:8px;">
                <strong>{{ $totals['item_count'] ?? 0 }}</strong> {{ __('items in stock') }},
                {{ __('valuation') }} <strong>{{ $fmt($totals['total_valuation'] ?? null) }}</strong>,
                {{ __('total cost') }} {{ $fmt($totals['total_acquisition_cost'] ?? null) }}
            </p>
            @if(($totals['unknown_cost_count'] ?? 0) > 0)
                <p class="ye-muted">{{ $totals['unknown_cost_count'] }} {{ trans_choice('{1} item|[2,*] items', $totals['unknown_cost_count']) }} {{ __('with unknown cost are not valued.') }}</p>
            @endif

            <table class="ye-table" style="margin-top:12px;">
                <thead><tr><th>{{ __('Metal') }}</th><th class="num">{{ __('Purity') }}</th><th class="num">{{ __('Items') }}</th><th class="num">{{ __('Gross wt') }}</th><th class="num">{{ __('Net metal wt') }}</th><th class="num">{{ __('Cost') }}</th><th class="num">{{ __('Valuation') }}</th></tr></thead>
                <tbody>
                @foreach($totals['by_metal'] ?? [] as $g)
                    <tr>
                        <td>{{ $g['metal_type'] }}</td><td class="num">{{ (float) $g['purity'] }}</td><td class="num">{{ $g['item_count'] }}</td>
                        <td class="num">{{ $wt($g['gross_weight']) }}</td><td class="num">{{ $wt($g['net_metal_weight']) }}</td>
                        <td class="num">{{ $fmt($g['total_acquisition_cost']) }}</td><td class="num">{{ $fmt($g['valuation']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        <form method="POST" action="{{ route('books.year-end.take', ['fy' => $year->label()]) }}" data-turbo-frame="_top" style="margin-top:14px;">
            @csrf
            <button type="submit" class="btn-primary">{{ $snapshot ? __('Take a new closing snapshot') : __('Take a closing snapshot') }}</button>
        </form>
    </section>

    @if($snapshot && ! empty($totals['balances']))
        <section class="ye-card">
            <h2>{{ __('Balances at year end') }}</h2>
            @php
                // One readable line per area: the amount (₹) or weight (g) that carries into next year.
                $b = $totals['balances'];
                $rupee = fn ($v) => '₹' . number_format((float) $v, 2);
                $lines = array_filter([
                    [__('Customer dues (receivables)'), $rupee($b['receivables']['total'] ?? 0), __('includes EMI balances')],
                    [__('Cash and bank'), $rupee($b['cash_and_bank']['total'] ?? 0), null],
                    [__('Customer store credit (owed by the shop)'), $rupee($b['store_credit']['total'] ?? 0), null],
                    [__('Customer old gold held (fine)'), number_format((float) ($b['customer_gold']['fine_gold_total'] ?? 0), 3) . ' g', null],
                    [__('Scheme money collected, not yet redeemed'), $rupee($b['scheme_liability']['total'] ?? 0), null],
                    [__('Owed to karigars'), $rupee($b['karigar']['total_payable'] ?? 0), null],
                    [__('Stock purchases from vendors'), $rupee($b['supplier_payables']['purchases_total'] ?? 0),
                        ($b['supplier_payables']['payments_tracked'] ?? true) ? null : __('payments to vendors are not recorded in the app')],
                    [__('Loyalty points outstanding'), number_format((float) ($b['loyalty_points']['total_points'] ?? 0)) . ' pts (' . $rupee($b['loyalty_points']['total_value'] ?? 0) . ')', null],
                    isset($b['dhiran']) && $b['dhiran'] !== null
                        ? [__('Gold loans: principal outstanding'), $rupee($b['dhiran']['total_principal'] ?? 0), __('interest booked: :i', ['i' => $rupee($b['dhiran']['total_interest'] ?? 0)])]
                        : null,
                ]);
            @endphp
            <table class="ye-table"><tbody>
            @foreach($lines as [$label, $value, $note])
                <tr>
                    <td>{{ $label }}@if($note)<br><span class="ye-muted" style="font-size:12px;">{{ $note }}</span>@endif</td>
                    <td class="num">{{ $value }}</td>
                </tr>
            @endforeach
            </tbody></table>
        </section>
    @endif

    @if($changes)
        <section class="ye-card">
            <h2>{{ __('Changed since this snapshot') }}</h2>
            @if(! $changes['has_changes'])
                <p class="ye-muted">{{ __('Nothing has changed in this year since the snapshot.') }}</p>
            @else
                @if($changes['added'])
                    <p><strong>{{ __('Added to year-end stock') }}</strong></p>
                    <table class="ye-table"><tbody>
                    @foreach($changes['added'] as $r)<tr><td>{{ $r['barcode'] ?? $r['item_id'] }}</td><td>{{ $r['design'] }}</td><td>{{ $r['metal_type'] }}</td><td class="num">{{ $wt($r['net_metal_weight']) }}</td></tr>@endforeach
                    </tbody></table>
                @endif
                @if($changes['removed'])
                    <p style="margin-top:10px;"><strong>{{ __('Removed from year-end stock') }}</strong></p>
                    <table class="ye-table"><tbody>
                    @foreach($changes['removed'] as $r)<tr><td>{{ $r['barcode'] ?? $r['item_id'] }}</td><td>{{ $r['design'] }}</td><td>{{ $r['metal_type'] }}</td><td class="num">{{ $wt($r['net_metal_weight']) }}</td></tr>@endforeach
                    </tbody></table>
                @endif
                @if($changes['late_movements'])
                    <p style="margin-top:10px;"><strong>{{ __('Stock movements recorded later but dated in this year') }}</strong></p>
                    <table class="ye-table"><tbody>
                    @foreach($changes['late_movements'] as $m)<tr><td>#{{ $m['item_id'] }}</td><td>{{ $m['movement_type'] }}</td><td>{{ $m['to_status'] }}</td><td>{{ substr((string) $m['occurred_at'], 0, 10) }}</td><td class="ye-muted">{{ __('recorded') }} {{ substr((string) $m['created_at'], 0, 16) }}</td></tr>@endforeach
                    </tbody></table>
                @endif
                @if($changes['backdated_events'])
                    <p style="margin-top:10px;"><strong>{{ __('Back-dated entries') }}</strong></p>
                    <table class="ye-table"><tbody>
                    @foreach($changes['backdated_events'] as $e)<tr><td>{{ substr((string) $e['created_at'], 0, 16) }}</td><td>{{ $e['description'] ?? $e['action'] }}</td></tr>@endforeach
                    </tbody></table>
                @endif
            @endif
        </section>
    @endif
</div>
</x-app-layout>

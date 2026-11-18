<x-app-layout>
<style>
    .mc-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
    .mc-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-top:16px; }
    .mc-muted { color:#6b7280; font-size:13px; }
    .mc-error { color:#b91c1c; font-size:12px; margin-top:4px; }
    .mc-table { width:100%; border-collapse:collapse; font-size:13px; }
    .mc-table th { text-align:left; padding:8px; border-bottom:1px solid #e5e7eb; color:#4b5563; font-weight:600; white-space:nowrap; }
    .mc-table td { padding:8px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    .mc-table .num { text-align:right; }
    .mc-input { width:140px; padding:6px 8px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; }
    .mc-input.is-bad { border-color:#b91c1c; }
    .mc-btn { padding:7px 14px; border-radius:8px; border:1px solid #111827; background:#111827; color:#fff; font-size:13px; font-weight:600; cursor:pointer; }
    .mc-btn.is-light { background:#fff; color:#111827; }
    .mc-hint { color:#6b7280; font-size:12px; }
    .mc-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:space-between; }
    @media (max-width: 640px) {
        .mc-table thead { display:none; }
        .mc-table, .mc-table tbody, .mc-table tr, .mc-table td { display:block; width:100%; }
        .mc-table tr { border:1px solid #e5e7eb; border-radius:10px; padding:6px 10px; margin-bottom:10px; }
        .mc-table td { display:flex; justify-content:space-between; gap:12px; border:0; padding:5px 0; text-align:right; }
        .mc-table td::before { content: attr(data-label); color:#6b7280; font-weight:600; text-align:left; flex:0 0 40%; }
        .mc-table td[data-label=""]::before { content:none; }
        .mc-input { width:100%; }
        .mc-field { flex:1; }
    }
</style>
@php
    $fmt = fn ($v) => number_format((float) $v, 2);
    $wt = fn ($v) => number_format((float) $v, 3);
@endphp
<div class="mc-wrap">
    <h1 class="text-2xl font-bold text-gray-900">{{ __('Items without a purchase cost') }}</h1>
    <p class="mc-muted" style="margin-top:4px;">
        {{ __('Pieces in stock whose purchase cost was never recorded are left out of the year-end stock value. Enter what each piece really cost you. A cost can be entered only once and cannot be changed afterwards, so check it before saving.') }}
    </p>

    @if(session('success'))<p class="mc-muted" role="status" style="margin-top:10px; color:#047857;">{{ session('success') }}</p>@endif
    @if($errors->any())<p class="mc-error" role="alert" style="font-size:13px;">{{ __('Some costs were not saved. See the messages beside them.') }}</p>@endif

    <div class="mc-card">
        <div class="mc-bar">
            <form method="GET" action="{{ route('inventory.missing-costs') }}" role="search" style="display:flex; gap:8px;">
                <input type="search" name="search" value="{{ $search }}" class="mc-input" style="width:220px;" placeholder="{{ __('Search barcode or design') }}" aria-label="{{ __('Search barcode or design') }}">
                <button type="submit" class="mc-btn is-light">{{ __('Search') }}</button>
                @if($search !== '')<a href="{{ route('inventory.missing-costs') }}" class="mc-btn is-light" style="text-decoration:none;">{{ __('Clear') }}</a>@endif
            </form>
            <span class="mc-muted">{{ trans_choice(':count piece without a cost|:count pieces without a cost', $total, ['count' => $total]) }}</span>
        </div>
    </div>

    @if($items->isEmpty())
        <div class="mc-card"><p class="mc-muted">{{ $search !== '' ? __('No matching pieces.') : __('Every piece in stock has a purchase cost.') }}</p></div>
    @else
    <form method="POST" action="{{ route('inventory.missing-costs.store') }}" data-turbo-frame="_top" class="mc-card" autocomplete="off">
        @csrf
        <input type="hidden" name="search" value="{{ $search }}">
        <input type="hidden" name="page" value="{{ $items->currentPage() }}">
        {{-- First submit button in the form: it is the one Enter in a cost box presses. --}}
        <div class="mc-bar" style="margin-bottom:10px;">
            <button type="submit" class="mc-btn">{{ __('Save all entered costs') }}</button>
            <label class="mc-muted" style="display:flex; gap:6px; align-items:center;">{{ __('Note (optional, kept with every cost saved now)') }}
                <input type="text" name="note" maxlength="500" class="mc-input" style="width:220px;" value="{{ old('note') }}">
            </label>
        </div>

        <table class="mc-table">
            <thead>
            <tr>
                <th>{{ __('Barcode') }}</th><th>{{ __('Design') }}</th><th>{{ __('Category') }}</th><th>{{ __('Metal') }}</th>
                <th class="num">{{ __('Purity') }}</th><th class="num">{{ __('Gross weight') }}</th><th class="num">{{ __('Net weight') }}</th>
                <th>{{ __('Created') }}</th><th>{{ __('Suggestion') }}</th><th>{{ __('Purchase cost') }} (₹)</th><th></th>
            </tr>
            </thead>
            <tbody>
            @foreach($items as $item)
                @php
                    $rate = $purchaseRates[$item->id] ?? null;
                    $bad = $errors->has('costs.' . $item->id);
                @endphp
                <tr data-missing-cost-row>
                    <td data-label="Barcode"><strong>{{ $item->barcode }}</strong></td>
                    <td data-label="Design">{{ $item->design ?: '—' }}</td>
                    <td data-label="Category">{{ $item->category ?: '—' }}</td>
                    <td data-label="Metal">{{ ucfirst((string) $item->metal_type) }}</td>
                    <td data-label="Purity" class="num">{{ (float) $item->purity }}</td>
                    <td data-label="Gross weight" class="num">{{ $wt($item->gross_weight) }} g</td>
                    <td data-label="Net weight" class="num">{{ $wt($item->net_metal_weight) }} g</td>
                    <td data-label="Created">{{ optional($item->created_at)->format('d M Y') }}</td>
                    <td data-label="Suggestion">
                        <span class="mc-hint">
                            @if($rate !== null && (float) $rate > 0)
                                {{ __('Suggestion') }}: {{ __('bought at ₹:rate per gram', ['rate' => $fmt($rate)]) }}
                            @elseif($item->cost_price !== null)
                                {{ __('Suggestion') }}: {{ __('current cost price ₹:amount (today’s value, not the original cost)', ['amount' => $fmt($item->cost_price)]) }}
                            @else
                                —
                            @endif
                        </span>
                    </td>
                    <td data-label="Purchase cost">
                        <div class="mc-field">
                            <input type="text" inputmode="decimal" name="costs[{{ $item->id }}]" value="{{ old('costs.' . $item->id) }}"
                                   class="mc-input {{ $bad ? 'is-bad' : '' }}" aria-label="{{ __('Purchase cost of :barcode', ['barcode' => $item->barcode]) }}" placeholder="0.00">
                            @error('costs.' . $item->id)<div class="mc-error" role="alert">{{ $message }}</div>@enderror
                        </div>
                    </td>
                    <td data-label="">
                        <button type="submit" name="only" value="{{ $item->id }}" class="mc-btn is-light">{{ __('Save') }}</button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="mc-bar" style="margin-top:12px;">
            <button type="submit" class="mc-btn">{{ __('Save all entered costs') }}</button>
        </div>
    </form>
    <div style="margin-top:12px;">{{ $items->links() }}</div>
    @endif
</div>
</x-app-layout>

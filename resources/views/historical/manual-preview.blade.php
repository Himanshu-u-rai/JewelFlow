@php
    use App\Models\Historical\HistoricalSalesDocument;
    use App\Support\Historical\HistoricalMessages;

    $taxModes = [
        '' => '— choose —',
        HistoricalSalesDocument::TAX_MODE_EXCLUSIVE     => 'Tax added on top (exclusive)',
        HistoricalSalesDocument::TAX_MODE_INCLUSIVE     => 'Tax already inside total (inclusive)',
        HistoricalSalesDocument::TAX_MODE_UNKNOWN       => 'Unknown',
        HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE => 'Not applicable',
    ];
    $money = ['taxable_amount'=>'Taxable','tax_total'=>'Tax total','cgst'=>'CGST','sgst'=>'SGST','igst'=>'IGST','cess'=>'Cess','discount'=>'Discount','rounding'=>'Rounding','metal_value'=>'Metal value','stone_value'=>'Stone value','paid_amount'=>'Paid','outstanding_amount'=>'Outstanding'];

    $customer = $attributes['customer_snapshot'] ?? [];
    $errors   = $messages->ofSeverity(HistoricalMessages::ERROR);
    $warnings = $messages->ofSeverity(HistoricalMessages::WARNING);
    $infos    = $messages->ofSeverity(HistoricalMessages::INFO);
@endphp
<x-app-layout>
    <x-page-header title="Preview historical bill" subtitle="Nothing has been saved yet — this is a computed preview. Review it, then Confirm Save.">
        <x-slot:actions>
            <a href="{{ route('historical.index') }}" class="btn btn-sm min-h-[44px]">← Historical sales</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-manual-preview-page">
        <x-app-alerts />

        {{-- Prominent read-only preview header: badge, disclaimer, and the exact
             original number up front — this is what the operator is confirming. --}}
        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden mb-4">
            <div class="p-4 sm:p-6">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold uppercase tracking-wide bg-teal-700 text-white">{{ HistoricalSalesDocument::BADGE }}</span>
                    <span class="text-xs font-semibold normal-case tracking-normal text-slate-500">Read-only preview</span>
                </div>
                <p class="text-xl font-semibold text-slate-900 mt-2">
                    {{ $attributes['original_document_number'] ?? 'Number unavailable' }}
                    @if($attributes['document_series'] ?? null) <span class="text-sm font-normal text-slate-500">(series {{ $attributes['document_series'] }})</span> @endif
                </p>
            </div>
            <div class="border-t border-amber-200 bg-amber-50 px-4 py-3 sm:px-6">
                <p class="text-sm text-amber-800">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>
            </div>
        </section>

        {{-- Warnings and blockers are visually separated by severity, and blockers
             are shown first — Confirm Save is not disabled client-side (it always
             re-validates server-side), but a blocking finding here means it will
             be rejected, so the operator should see that before scrolling to it. --}}
        @if($errors !== [])
            <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 mb-3" role="alert">
                <p class="font-semibold text-rose-700 text-sm mb-1">Blocking — this bill cannot be saved until these are fixed:</p>
                @foreach($errors as $m)<p class="text-rose-700 text-sm">{{ $m['text'] }}</p>@endforeach
            </div>
        @endif

        @if($warnings !== [])
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 mb-3">
                <p class="font-semibold text-amber-800 text-sm mb-1">Warnings:</p>
                @foreach($warnings as $m)<p class="text-amber-800 text-sm">{{ $m['text'] }}</p>@endforeach
            </div>
        @endif

        @if($infos !== [])
            <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 mb-3">
                @foreach($infos as $m)<p class="text-blue-800 text-sm">{{ $m['text'] }}</p>@endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4" data-historical-preview-layout>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 lg:col-span-2">
                <h2 class="text-base font-semibold text-slate-800 mb-3">Document</h2>
                <dl class="text-sm grid grid-cols-2 gap-x-3 gap-y-4">
                    <dt class="text-slate-500">Date</dt><dd class="text-slate-800">{{ $attributes['document_date'] ?? '—' }}</dd>
                    <dt class="text-slate-500">Financial year</dt><dd class="text-slate-800">{{ $attributes['financial_year'] ?? '—' }}</dd>
                    <dt class="text-slate-500">Source</dt><dd class="text-slate-800">{{ $attributes['source_system'] ?? '—' }}</dd>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <h2 class="text-base font-semibold text-slate-800 mb-3">Customer snapshot <span class="text-slate-400 font-normal text-xs">(never linked)</span></h2>
                <dl class="text-sm grid grid-cols-2 gap-x-3 gap-y-4">
                    <dt class="text-slate-500">Name</dt><dd class="text-slate-800">{{ $customer['name'] ?? '—' }}</dd>
                    <dt class="text-slate-500">Mobile</dt><dd class="text-slate-800">{{ $customer['mobile'] ?? '—' }}</dd>
                    <dt class="text-slate-500">GSTIN</dt><dd class="text-slate-800">{{ $customer['gstin'] ?? '—' }}</dd>
                    <dt class="text-slate-500">Place of supply</dt><dd class="text-slate-800">{{ $customer['place_of_supply'] ?? '—' }}</dd>
                    <dt class="text-slate-500">Address</dt><dd class="text-slate-800">{{ $customer['address'] ?? '—' }}</dd>
                </dl>

                @if(($suggestions['mobile']['status'] ?? 'none') !== 'none' || ($suggestions['gstin']['status'] ?? 'none') !== 'none' || ($suggestions['name']['status'] ?? 'none') !== 'none')
                    <div class="mt-3 pt-3 border-t border-slate-100 text-sm text-slate-500">
                        <p class="mb-1">Possible existing customer matches — informational only, link them after saving from the document's review screen:</p>
                        @foreach(['mobile' => 'Mobile match', 'gstin' => 'GSTIN match', 'name' => 'Possible name match'] as $key => $label)
                            @php $match = $suggestions[$key] ?? ['status' => 'none', 'customers' => collect()]; @endphp
                            @if($match['status'] === 'ambiguous')
                                <p>{{ $label }}: ambiguous — {{ $match['customers']->count() }} customers share this value, not linked automatically</p>
                            @elseif($match['status'] === 'match')
                                <p>{{ $label }}: {{ $match['customers']->pluck('name')->join(', ') }}</p>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 lg:col-span-2">
                <h2 class="text-base font-semibold text-slate-800 mb-3">Tax and making / labour charge</h2>
                <dl class="text-sm grid grid-cols-2 gap-x-3 gap-y-4">
                    <dt class="text-slate-500">Tax</dt><dd class="text-slate-800">{{ $attributes['tax_mode'] ?? '—' }} ({{ $attributes['tax_completeness'] ?? '—' }})</dd>
                    <dt class="text-slate-500">Making / labour</dt>
                    <dd class="text-slate-800">
                        {{ $attributes['making_label_original'] ?? '—' }}: {{ $attributes['making_value_original'] ?? '—' }}
                        <span class="text-slate-500">({{ $attributes['making_category'] ?? 'uncategorized' }} / {{ $attributes['making_basis'] ?? 'unknown basis' }})</span>
                        — {{ number_format((float) ($attributes['making_amount'] ?? 0), 2) }}
                    </dd>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <h2 class="text-base font-semibold text-slate-800 mb-3">Amounts <span class="text-slate-400 font-normal text-xs">(computed — nothing here is trusted as input)</span></h2>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        <tr><td class="py-1.5 text-slate-500">Taxable</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) ($attributes['taxable_amount'] ?? 0), 2) }}</td></tr>
                        <tr><td class="py-1.5 text-slate-500">Discount</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) ($attributes['discount_snapshot'] ?? 0), 2) }}</td></tr>
                        <tr><td class="py-1.5 text-slate-500">Rounding</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) ($attributes['rounding_snapshot'] ?? 0), 2) }}</td></tr>
                        <tr class="font-semibold"><td class="py-1.5 text-slate-800">Grand total</td><td class="py-1.5 text-right tabular-nums text-slate-800">{{ number_format((float) ($attributes['grand_total'] ?? 0), 2) }}</td></tr>
                        <tr><td class="py-1.5 text-slate-500">Paid</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) ($attributes['paid_amount_snapshot'] ?? 0), 2) }}</td></tr>
                        <tr><td class="py-1.5 text-slate-500">Outstanding</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) ($attributes['outstanding_amount_snapshot'] ?? 0), 2) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden mt-4">
            <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                <h2 class="text-lg font-semibold text-slate-900">Item lines <span class="text-sm font-normal text-slate-500">({{ count($lines) }})</span></h2>
                <p class="mt-1 text-sm text-slate-500">Read-only normalized values from the bill you entered.</p>
            </div>
            @if($lines !== [])
                <div class="hidden md:block" data-historical-preview-register="lines-desktop">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold normal-case tracking-normal text-slate-600">
                                <th class="px-4 py-3 sm:px-6">Item</th><th class="px-4 py-3 text-right sm:px-6">Qty</th><th class="px-4 py-3 text-right sm:px-6">Net wt</th><th class="px-4 py-3 text-right sm:px-6">Gross wt</th><th class="px-4 py-3 text-right sm:px-6">Stone wt</th><th class="px-4 py-3 text-right sm:px-6">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($lines as $line)
                                <tr class="transition-colors hover:bg-slate-50">
                                    <td class="px-4 py-4 text-sm font-semibold text-slate-900 sm:px-6">{{ $line['item_snapshot']['name'] ?? $line['source_description'] ?? '—' }}</td>
                                    <td class="px-4 py-4 text-right tabular-nums sm:px-6">{{ $line['quantity'] ?? '—' }}</td>
                                    <td class="px-4 py-4 text-right tabular-nums sm:px-6">{{ $line['net_weight'] ?? '—' }}</td>
                                    <td class="px-4 py-4 text-right tabular-nums sm:px-6">{{ $line['gross_weight'] ?? '—' }}</td>
                                    <td class="px-4 py-4 text-right tabular-nums sm:px-6">{{ $line['stone_weight'] ?? '—' }}</td>
                                    <td class="px-4 py-4 text-right font-semibold tabular-nums text-slate-900 sm:px-6">{{ number_format((float) ($line['line_total'] ?? 0), 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                </div>
                <div class="grid gap-3 bg-slate-50 p-3 md:hidden" data-historical-preview-register="lines-mobile">
                    @foreach($lines as $line)
                        <article class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-slate-900">{{ $line['item_snapshot']['name'] ?? $line['source_description'] ?? '—' }}</h3>
                                    <p class="mt-1 text-xs text-slate-500">Qty {{ $line['quantity'] ?? '—' }} · Net {{ $line['net_weight'] ?? '—' }} · Gross {{ $line['gross_weight'] ?? '—' }}</p>
                                </div>
                                <span class="shrink-0 text-sm font-semibold text-slate-900 tabular-nums">{{ number_format((float) ($line['line_total'] ?? 0), 2) }}</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="p-4 sm:p-6"><p class="text-sm text-slate-500">Header only — no item lines.</p></div>
            @endif
        </section>

        {{-- The same fields, prefilled from what was submitted (flashed as old input).
             Edit them and Preview again, or Confirm Save to post these exact values —
             Save re-validates and re-normalizes from scratch; nothing computed above
             is trusted as input. --}}
        {{-- Same Turbo opt-out as manual.blade.php: "Edit / Recalculate preview" re-posts
             to the 200-rendering preview endpoint. "Confirm Save" (formaction override)
             redirects and would work under Turbo either way — data-turbo="false" on the
             whole form is harmless for it and keeps both buttons' behavior consistent. --}}
        {{-- Same x-init contract as manual.blade.php. request->flash() in
             HistoricalManualEntryController::preview() puts the just-submitted
             lines into old() before this view renders, so Edit/Recalculate
             reloads exactly what was typed instead of starting empty. --}}
        <form method="POST" action="{{ route('historical.manual.preview') }}" data-turbo="false"
              x-data="{ lines: [] }"
              x-init="lines = historicalPadLines(historicalSeedLines(@js(old('lines', []))))"
              class="grid gap-4 mt-4" data-historical-form="manual-preview">
            @csrf

            @include('historical._manual-form-fields', compact('taxModes', 'money', 'makingCategories', 'makingBases'))

            <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <p class="text-xs text-slate-500">Recalculate to review edits, or confirm once the historical record is correct.</p>
                <div class="flex gap-3 flex-wrap">
                    <button class="btn min-h-[44px]" type="submit">Edit / Recalculate preview</button>
                    <button class="btn btn-primary min-h-[44px]" type="submit" formaction="{{ route('historical.manual.store') }}">Confirm Save</button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>

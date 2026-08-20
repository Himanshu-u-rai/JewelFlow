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
    $documentDateDisplay = isset($attributes['document_date'])
        ? \Illuminate\Support\Carbon::parse($attributes['document_date'])->format('d M Y')
        : '—';
@endphp
<x-app-layout>
    <x-page-header title="Preview historical bill" subtitle="Review the normalized record before confirming the save.">
        <x-slot:actions>
            <a href="{{ route('historical.index') }}"
               class="inline-flex min-h-[44px] items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm transition-colors hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
               data-historical-preview-back>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600" aria-hidden="true">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path d="M12.5 5 7.5 10l5 5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <span>Historical sales</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-manual-preview-page">
        <x-app-alerts />

        <div class="grid gap-4" data-historical-preview-review>
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" data-historical-preview-summary>
                <div class="flex flex-col gap-5 p-4 sm:p-6 lg:flex-row lg:items-end lg:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center rounded bg-teal-700 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-white">{{ HistoricalSalesDocument::BADGE }}</span>
                            <span class="text-xs font-semibold text-slate-500">Read-only review</span>
                        </div>
                        <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Original document</p>
                        <p class="mt-1 break-words text-2xl font-semibold leading-tight text-slate-900">
                            {{ $attributes['original_document_number'] ?? 'Number unavailable' }}
                            @if($attributes['document_series'] ?? null)
                                <span class="text-sm font-normal text-slate-500">Series {{ $attributes['document_series'] }}</span>
                            @endif
                        </p>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                            <div class="rounded-xl bg-slate-50 px-3 py-3">
                                <dt class="text-xs font-medium text-slate-500">Document date</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ $documentDateDisplay }}</dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 px-3 py-3">
                                <dt class="text-xs font-medium text-slate-500">Financial year</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ $attributes['financial_year'] ?? '—' }}</dd>
                            </div>
                            <div class="col-span-2 rounded-xl bg-slate-50 px-3 py-3 sm:col-span-1">
                                <dt class="text-xs font-medium text-slate-500">Source</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ $attributes['source_system'] ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 lg:w-64 lg:shrink-0" data-historical-preview-grand-total>
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Grand total</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ number_format((float) ($attributes['grand_total'] ?? 0), 2) }}</p>
                        <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-amber-200 pt-3 text-xs">
                            <div>
                                <dt class="text-amber-700">Paid</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['paid_amount_snapshot'] ?? 0), 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-amber-700">Outstanding</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['outstanding_amount_snapshot'] ?? 0), 2) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
                <div class="border-t border-amber-200 bg-amber-50 px-4 py-3 sm:px-6">
                    <p class="text-sm text-amber-800">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>
                </div>
            </section>

            {{-- Findings remain informational until the server re-validates on save. --}}
            <div class="grid gap-3" data-historical-preview-messages>
                @if($errors !== [])
                    <section class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3" role="alert">
                        <h2 class="text-sm font-semibold text-rose-800">Blocking issues</h2>
                        <p class="mt-1 text-xs text-rose-700">This bill cannot be saved until these are fixed.</p>
                        <div class="mt-2 grid gap-1">
                            @foreach($errors as $m)<p class="text-sm text-rose-700">{{ $m['text'] }}</p>@endforeach
                        </div>
                    </section>
                @endif

                @if($warnings !== [])
                    <section class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                        <h2 class="text-sm font-semibold text-amber-800">Review warnings</h2>
                        <div class="mt-2 grid gap-1">
                            @foreach($warnings as $m)<p class="text-sm text-amber-800">{{ $m['text'] }}</p>@endforeach
                        </div>
                    </section>
                @endif

                @if($infos !== [])
                    <section class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3">
                        <h2 class="text-sm font-semibold text-blue-800">Record notes</h2>
                        <div class="mt-2 grid gap-1">
                            @foreach($infos as $m)<p class="text-sm text-blue-800">{{ $m['text'] }}</p>@endforeach
                        </div>
                    </section>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2" data-historical-preview-layout>
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" data-historical-preview-card="customer">
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                        <h2 class="text-base font-semibold text-slate-900">Customer snapshot</h2>
                        <p class="mt-1 text-xs text-slate-500">Stored as entered and never linked automatically.</p>
                    </div>
                    <dl class="grid grid-cols-1 gap-4 p-4 text-sm sm:grid-cols-2 sm:p-6">
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Name</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $customer['name'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Mobile</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $customer['mobile'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">GSTIN</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $customer['gstin'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Place of supply</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $customer['place_of_supply'] ?? '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium text-slate-500">Address</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $customer['address'] ?? '—' }}</dd>
                        </div>
                    </dl>

                    @if(($suggestions['mobile']['status'] ?? 'none') !== 'none' || ($suggestions['gstin']['status'] ?? 'none') !== 'none' || ($suggestions['name']['status'] ?? 'none') !== 'none')
                        <div class="border-t border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-600 sm:px-6">
                            <p class="font-medium text-slate-700">Possible existing customer matches</p>
                            <p class="mt-1 text-xs">Informational only. Link a customer after saving from the document review screen.</p>
                            <div class="mt-2 grid gap-1 text-xs">
                                @foreach(['mobile' => 'Mobile match', 'gstin' => 'GSTIN match', 'name' => 'Possible name match'] as $key => $label)
                                    @php $match = $suggestions[$key] ?? ['status' => 'none', 'customers' => collect()]; @endphp
                                    @if($match['status'] === 'ambiguous')
                                        <p>{{ $label }}: ambiguous — {{ $match['customers']->count() }} customers share this value</p>
                                    @elseif($match['status'] === 'match')
                                        <p>{{ $label }}: {{ $match['customers']->pluck('name')->join(', ') }}</p>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" data-historical-preview-card="tax-making">
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                        <h2 class="text-base font-semibold text-slate-900">Tax and making / labour</h2>
                        <p class="mt-1 text-xs text-slate-500">Normalized display values from the submitted record.</p>
                    </div>
                    <dl class="grid grid-cols-2 gap-4 p-4 text-sm sm:p-6">
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Tax mode</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['tax_mode'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Completeness</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['tax_completeness'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Charge label</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['making_label_original'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Charge value</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['making_value_original'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Category</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['making_category'] ?? 'Uncategorized' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Basis</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['making_basis'] ?? 'Unknown' }}</dd>
                        </div>
                        <div class="col-span-2 border-t border-slate-200 pt-4">
                            <dt class="text-xs font-medium text-slate-500">Computed making / labour amount</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ number_format((float) ($attributes['making_amount'] ?? 0), 2) }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white lg:col-span-2" data-historical-preview-card="amounts">
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                        <h2 class="text-base font-semibold text-slate-900">Financial summary</h2>
                        <p class="mt-1 text-xs text-slate-500">Computed review values; nothing here is trusted as input.</p>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 p-4 text-sm sm:grid-cols-3 sm:p-6 md:hidden">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-xs font-medium text-slate-500">Taxable</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['taxable_amount'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-xs font-medium text-slate-500">Discount</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['discount_snapshot'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-xs font-medium text-slate-500">Rounding</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['rounding_snapshot'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                            <dt class="text-xs font-medium text-amber-700">Grand total</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-900">{{ number_format((float) ($attributes['grand_total'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-xs font-medium text-slate-500">Paid</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['paid_amount_snapshot'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-xs font-medium text-slate-500">Outstanding</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['outstanding_amount_snapshot'] ?? 0), 2) }}</dd>
                        </div>
                    </dl>
                    <div class="hidden overflow-x-auto p-4 sm:p-6 md:block">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-slate-200">
                                <tr>
                                    <td class="py-3 text-slate-500">Taxable</td><td class="py-3 pr-8 text-right font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['taxable_amount'] ?? 0), 2) }}</td>
                                    <td class="py-3 text-slate-500">Discount</td><td class="py-3 pr-8 text-right font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['discount_snapshot'] ?? 0), 2) }}</td>
                                    <td class="py-3 text-slate-500">Rounding</td><td class="py-3 text-right font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['rounding_snapshot'] ?? 0), 2) }}</td>
                                </tr>
                                <tr>
                                    <td class="py-3 text-slate-500">Grand total</td><td class="py-3 pr-8 text-right font-semibold tabular-nums text-slate-900">{{ number_format((float) ($attributes['grand_total'] ?? 0), 2) }}</td>
                                    <td class="py-3 text-slate-500">Paid</td><td class="py-3 pr-8 text-right font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['paid_amount_snapshot'] ?? 0), 2) }}</td>
                                    <td class="py-3 text-slate-500">Outstanding</td><td class="py-3 text-right font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['outstanding_amount_snapshot'] ?? 0), 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" data-historical-preview-items>
                <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                    <h2 class="text-lg font-semibold text-slate-900">Item lines <span class="text-sm font-normal text-slate-500">({{ count($lines) }})</span></h2>
                    <p class="mt-1 text-sm text-slate-500">Read-only normalized values from the bill you entered.</p>
                </div>
                @if($lines !== [])
                    <div class="hidden md:block" data-historical-preview-register="lines-desktop">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-full text-sm">
                                <thead class="bg-slate-50">
                                    <tr class="text-left text-xs font-semibold normal-case tracking-normal text-slate-600">
                                        <th class="border-b border-slate-200 px-4 py-3 sm:px-6">Item</th><th class="border-b border-slate-200 px-4 py-3 text-right sm:px-6">Qty</th><th class="border-b border-slate-200 px-4 py-3 text-right sm:px-6">Net wt</th><th class="border-b border-slate-200 px-4 py-3 text-right sm:px-6">Gross wt</th><th class="border-b border-slate-200 px-4 py-3 text-right sm:px-6">Stone wt</th><th class="border-b border-slate-200 px-4 py-3 text-right sm:px-6">Total</th>
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
                                    <h3 class="min-w-0 text-sm font-semibold text-slate-900">{{ $line['item_snapshot']['name'] ?? $line['source_description'] ?? '—' }}</h3>
                                    <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">{{ number_format((float) ($line['line_total'] ?? 0), 2) }}</span>
                                </div>
                                <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                                    <div><dt class="text-slate-500">Quantity</dt><dd class="mt-1 font-medium text-slate-800">{{ $line['quantity'] ?? '—' }}</dd></div>
                                    <div><dt class="text-slate-500">Net weight</dt><dd class="mt-1 font-medium text-slate-800">{{ $line['net_weight'] ?? '—' }}</dd></div>
                                    <div><dt class="text-slate-500">Gross weight</dt><dd class="mt-1 font-medium text-slate-800">{{ $line['gross_weight'] ?? '—' }}</dd></div>
                                    <div><dt class="text-slate-500">Stone weight</dt><dd class="mt-1 font-medium text-slate-800">{{ $line['stone_weight'] ?? '—' }}</dd></div>
                                </dl>
                            </article>
                        @endforeach
                    </div>
                @else
                    <div class="p-4 sm:p-6"><p class="text-sm text-slate-500">Header only — no item lines.</p></div>
                @endif
            </section>

            <section class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 sm:px-6" data-historical-preview-editor-heading>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Editable copy</p>
                <h2 id="historical-preview-editor-title" class="mt-1 text-lg font-semibold text-slate-900">Edit submitted details</h2>
                <p class="mt-1 text-sm text-slate-600">Adjust any field below and recalculate the preview, or confirm once the historical record is correct.</p>
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
              class="grid gap-4" data-historical-form="manual-preview" aria-labelledby="historical-preview-editor-title">
            @csrf

            @include('historical._manual-form-fields', compact('taxModes', 'money', 'makingCategories', 'makingBases'))

            {{-- Warning acknowledgement for a direct publish. The digest pins this tick
                 to the exact warning set shown above: edit the bill so its warnings
                 change and the server recomputes a different digest and refuses the
                 stale acknowledgement instead of carrying it over. --}}
            @if (! empty($warningDigest))
                <label class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:px-6">
                    <input type="checkbox" name="acknowledge_warnings" value="1" class="mt-0.5 h-4 w-4 rounded border-amber-300">
                    <span>I have read the {{ $messages->countOf(\App\Support\Historical\HistoricalMessages::WARNING) }}
                        warning(s) above and want to record this bill as it stands.</span>
                </label>
                <input type="hidden" name="acknowledged_warning_digest" value="{{ $warningDigest }}">
            @endif

            <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <p class="text-xs text-slate-500">Recalculate to review edits, or save once the historical record is correct.</p>
                <div class="flex gap-3 flex-wrap">
                    <button class="btn min-h-[44px]" type="submit">Edit / Recalculate preview</button>
                    <button class="btn btn-primary min-h-[44px]" type="submit"
                            name="intent" value="{{ \App\Http\Requests\Historical\StoreManualHistoricalRequest::INTENT_DRAFT }}"
                            formaction="{{ route('historical.manual.store') }}">Save draft</button>
                    @can('historical.publish')
                        <button class="btn btn-primary min-h-[44px]" type="submit"
                                name="intent" value="{{ \App\Http\Requests\Historical\StoreManualHistoricalRequest::INTENT_PUBLISH }}"
                                formaction="{{ route('historical.manual.store') }}">Save &amp; publish</button>
                    @endcan
                </div>
            </div>
        </form>
        </div>
    </div>
</x-app-layout>

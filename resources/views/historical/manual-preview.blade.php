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
    // cgst/sgst/igst join the auto-calculated set so the one bill-level GST% + split
    // entry drives them the same way every other calculated field works — see
    // resources/views/historical/manual.blade.php for the matching change.
    $calculatedDocumentFields = ['taxable_amount', 'tax_total', 'cgst', 'sgst', 'igst', 'discount', 'metal_value', 'stone_value', 'grand_total', 'paid_amount', 'outstanding_amount'];
    $taxSplitTypes = [
        '' => '— not set —',
        HistoricalSalesDocument::TAX_SPLIT_CGST_SGST => 'CGST + SGST (intrastate)',
        HistoricalSalesDocument::TAX_SPLIT_IGST      => 'IGST (interstate)',
    ];
    $documentTotals = collect($calculatedDocumentFields)->mapWithKeys(fn ($field) => [$field => old($field, '')])->all();
    $documentModes = collect($calculatedDocumentFields)->mapWithKeys(fn ($field) => [$field => old($field . '_mode', 'auto')])->all();

    $customer = $attributes['customer_snapshot'] ?? [];
    // NOT $errors: that name is reserved by Laravel's ShareErrorsFromSession.
    // These are preview findings, a different thing from validation errors.
    $blockingFindings = $messages->ofSeverity(HistoricalMessages::ERROR);
    $warnings = $messages->ofSeverity(HistoricalMessages::WARNING);
    $infos    = $messages->ofSeverity(HistoricalMessages::INFO);
    $calculationState = $attributes['calculation_state'] ?? [];
    $documentDateDisplay = isset($attributes['document_date'])
        ? \Illuminate\Support\Carbon::parse($attributes['document_date'])->format('d M Y')
        : '—';
@endphp
<x-app-layout>
    <x-page-header title="Preview historical bill" subtitle="Review the normalized record before confirming the save.">
        <x-slot:actions>
            <a href="{{ route('historical.index') }}"
               class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition-colors hover:border-amber-400 hover:bg-amber-50 hover:text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
               data-historical-back>
                <svg class="h-4 w-4 shrink-0 text-amber-700" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path d="M12.5 5 7.5 10l5 5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span>Back to historical sales</span>
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
                        <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                            <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-3">
                                <dt class="text-xs font-medium text-slate-500">Document date</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ $documentDateDisplay }}</dd>
                            </div>
                            <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-3">
                                <dt class="text-xs font-medium text-slate-500">Financial year</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ $attributes['financial_year'] ?? '—' }}</dd>
                            </div>
                            <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-3">
                                <dt class="text-xs font-medium text-slate-500">Source</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ $attributes['source_system'] ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 lg:w-64 lg:shrink-0" data-historical-preview-grand-total>
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Grand total</p>
                            @if(isset($calculationState['grand_total']))
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($calculationState['grand_total']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-200 text-amber-900' : 'bg-emerald-100 text-emerald-800' }}" data-historical-calculation-mode="grand_total">{{ ($calculationState['grand_total']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>
                            @endif
                        </div>
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
                @if($blockingFindings !== [])
                    <section class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3" role="alert" data-historical-preview-finding="blocking">
                        <h2 class="text-sm font-semibold text-rose-800">Blocking issues</h2>
                        <p class="mt-1 text-xs text-rose-700">This bill cannot be saved until these are fixed.</p>
                        <div class="mt-2 grid gap-1">
                            @foreach($blockingFindings as $m)<p class="text-sm text-rose-700">{{ $m['text'] }}</p>@endforeach
                        </div>
                    </section>
                @endif

                @if($warnings !== [])
                    <section class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3" data-historical-preview-finding="warnings">
                        <h2 class="text-sm font-semibold text-amber-800">Review warnings</h2>
                        <div class="mt-2 grid gap-1">
                            @foreach($warnings as $m)<p class="text-sm text-amber-800">{{ $m['text'] }}</p>@endforeach
                        </div>
                        @if (! empty($warningDigest))
                            <label class="mt-3 flex min-h-[44px] items-start gap-3 border-t border-amber-200 pt-3 text-sm text-amber-900">
                                <input type="checkbox" name="acknowledge_warnings" value="1" form="historical-manual-preview-form" class="mt-0.5 h-4 w-4 rounded border-amber-300">
                                <span>I have read the {{ count($warnings) }} warning(s) above and want to record this bill as it stands.</span>
                            </label>
                            <input type="hidden" name="acknowledged_warning_digest" value="{{ $warningDigest }}" form="historical-manual-preview-form">
                        @endif
                    </section>
                @endif

                @if($infos !== [])
                    <section class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3" data-historical-preview-finding="informational">
                        <h2 class="text-sm font-semibold text-blue-800">Record notes</h2>
                        <div class="mt-2 grid gap-1">
                            @foreach($infos as $m)<p class="text-sm text-blue-800">{{ $m['text'] }}</p>@endforeach
                        </div>
                    </section>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2" data-historical-preview-layout>
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white lg:col-span-2" data-historical-preview-card="amounts">
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                        <h2 class="text-base font-semibold text-slate-900">Financial summary</h2>
                        <p class="mt-1 text-xs text-slate-500">Computed review values; nothing here is trusted as input.</p>
                    </div>
                    <dl class="grid grid-cols-1 gap-3 p-4 text-sm sm:grid-cols-2 sm:p-6 lg:grid-cols-3">
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="taxable">
                            <div class="flex items-center justify-between gap-2"><dt class="text-xs font-medium text-slate-500">Taxable</dt>@if(isset($calculationState['taxable_amount']))<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($calculationState['taxable_amount']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}" data-historical-calculation-mode="taxable_amount">{{ ($calculationState['taxable_amount']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</div>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['taxable_amount'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="tax-total">
                            <div class="flex items-center justify-between gap-2"><dt class="text-xs font-medium text-slate-500">Tax total</dt>@if(isset($calculationState['tax_total']))<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($calculationState['tax_total']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}" data-historical-calculation-mode="tax_total">{{ ($calculationState['tax_total']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</div>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) data_get($calculationState, 'tax_total.value', 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="cgst">
                            <div class="flex items-center justify-between gap-2"><dt class="text-xs font-medium text-slate-500">CGST</dt>@if(isset($calculationState['cgst']))<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($calculationState['cgst']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}" data-historical-calculation-mode="cgst">{{ ($calculationState['cgst']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</div>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) data_get($calculationState, 'cgst.value', 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="sgst">
                            <div class="flex items-center justify-between gap-2"><dt class="text-xs font-medium text-slate-500">SGST</dt>@if(isset($calculationState['sgst']))<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($calculationState['sgst']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}" data-historical-calculation-mode="sgst">{{ ($calculationState['sgst']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</div>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) data_get($calculationState, 'sgst.value', 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="igst">
                            <div class="flex items-center justify-between gap-2"><dt class="text-xs font-medium text-slate-500">IGST</dt>@if(isset($calculationState['igst']))<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($calculationState['igst']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}" data-historical-calculation-mode="igst">{{ ($calculationState['igst']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</div>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) data_get($calculationState, 'igst.value', 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="discount">
                            <div class="flex items-center justify-between gap-2"><dt class="text-xs font-medium text-slate-500">Discount</dt>@if(isset($calculationState['discount']))<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($calculationState['discount']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}" data-historical-calculation-mode="discount">{{ ($calculationState['discount']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</div>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['discount_snapshot'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="metal-value">
                            <div class="flex items-center justify-between gap-2"><dt class="text-xs font-medium text-slate-500">Metal value</dt>@if(isset($calculationState['metal_value']))<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($calculationState['metal_value']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}" data-historical-calculation-mode="metal_value">{{ ($calculationState['metal_value']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</div>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['metal_value'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="stone-value">
                            <div class="flex items-center justify-between gap-2"><dt class="text-xs font-medium text-slate-500">Stone value</dt>@if(isset($calculationState['stone_value']))<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($calculationState['stone_value']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}" data-historical-calculation-mode="stone_value">{{ ($calculationState['stone_value']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</div>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['stone_value'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="rounding">
                            <dt class="text-xs font-medium text-slate-500">Rounding</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['rounding_snapshot'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3" data-historical-preview-field="grand-total">
                            <div class="flex items-center justify-between gap-2"><dt class="text-xs font-medium text-amber-700">Grand total</dt>@if(isset($calculationState['grand_total']))<span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($calculationState['grand_total']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-200 text-amber-900' : 'bg-emerald-100 text-emerald-800' }}" data-historical-calculation-mode="grand_total">{{ ($calculationState['grand_total']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</div>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-900">{{ number_format((float) ($attributes['grand_total'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="paid">
                            <dt class="text-xs font-medium text-slate-500">Paid</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['paid_amount_snapshot'] ?? 0), 2) }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="outstanding">
                            <dt class="text-xs font-medium text-slate-500">Outstanding</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ number_format((float) ($attributes['outstanding_amount_snapshot'] ?? 0), 2) }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" data-historical-preview-card="customer">
                    <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                        <h2 class="text-base font-semibold text-slate-900">Customer snapshot</h2>
                        <p class="mt-1 text-xs text-slate-500">Stored as entered and never linked automatically.</p>
                    </div>
                    <dl class="grid grid-cols-1 gap-3 p-4 text-sm sm:grid-cols-2 sm:p-6">
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="customer-name">
                            <dt class="text-xs font-medium text-slate-500">Name</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $customer['name'] ?? '—' }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="customer-mobile">
                            <dt class="text-xs font-medium text-slate-500">Mobile</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $customer['mobile'] ?? '—' }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="customer-gstin">
                            <dt class="text-xs font-medium text-slate-500">GSTIN</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $customer['gstin'] ?? '—' }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="place-of-supply">
                            <dt class="text-xs font-medium text-slate-500">Place of supply</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $customer['place_of_supply'] ?? '—' }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3 sm:col-span-2" data-historical-preview-field="customer-address">
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
                    <dl class="grid grid-cols-1 gap-3 p-4 text-sm sm:grid-cols-2 sm:p-6">
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="tax-mode">
                            <dt class="text-xs font-medium text-slate-500">Tax mode</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['tax_mode'] ?? '—' }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="tax-completeness">
                            <dt class="text-xs font-medium text-slate-500">Completeness</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['tax_completeness'] ?? '—' }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="making-label">
                            <dt class="text-xs font-medium text-slate-500">Charge label</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['making_label_original'] ?? '—' }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="making-value">
                            <dt class="text-xs font-medium text-slate-500">Charge value</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['making_value_original'] ?? '—' }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="making-category">
                            <dt class="text-xs font-medium text-slate-500">Category</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['making_category'] ?? 'Uncategorized' }}</dd>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-preview-field="making-basis">
                            <dt class="text-xs font-medium text-slate-500">Basis</dt>
                            <dd class="mt-1 font-medium text-slate-800">{{ $attributes['making_basis'] ?? 'Unknown' }}</dd>
                        </div>
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 sm:col-span-2" data-historical-preview-field="making-amount">
                            <dt class="text-xs font-medium text-slate-500">Computed making / labour amount</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900">{{ number_format((float) ($attributes['making_amount'] ?? 0), 2) }}</dd>
                        </div>
                    </dl>
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
                                        <th class="w-16 border-b border-slate-200 px-4 py-3 text-center">No.</th><th class="border-b border-slate-200 px-4 py-3 sm:px-6">Item</th><th class="border-b border-slate-200 px-4 py-3 text-right sm:px-6">Qty</th><th class="border-b border-slate-200 px-4 py-3 text-right sm:px-6">Net wt</th><th class="border-b border-slate-200 px-4 py-3 text-right sm:px-6">Gross wt</th><th class="border-b border-slate-200 px-4 py-3 text-right sm:px-6">Stone wt</th><th class="border-b border-slate-200 px-4 py-3 text-right sm:px-6">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($lines as $line)
                                        <tr class="transition-colors hover:bg-slate-50">
                                            <td class="w-16 px-4 py-4 text-center text-sm font-medium tabular-nums text-slate-500" data-historical-row-number="line">{{ $loop->iteration }}</td>
                                            <td class="px-4 py-4 text-sm font-semibold text-slate-900 sm:px-6">{{ $line['item_snapshot']['name'] ?? $line['source_description'] ?? '—' }}</td>
                                            <td class="px-4 py-4 text-right tabular-nums sm:px-6">{{ $line['quantity'] ?? '—' }}</td>
                                            <td class="px-4 py-4 text-right tabular-nums sm:px-6">{{ $line['net_weight'] ?? '—' }}</td>
                                            <td class="px-4 py-4 text-right tabular-nums sm:px-6">{{ $line['gross_weight'] ?? '—' }}</td>
                                            <td class="px-4 py-4 text-right tabular-nums sm:px-6">{{ $line['stone_weight'] ?? '—' }}</td>
                                            <td class="px-4 py-4 text-right font-semibold tabular-nums text-slate-900 sm:px-6">{{ number_format((float) ($line['line_total'] ?? 0), 2) }}@if(isset($line['calculation_state']['line_total']))<span class="ml-2 inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($line['calculation_state']['line_total']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}" data-historical-line-calculation-mode="{{ $loop->iteration }}">{{ ($line['calculation_state']['line_total']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</td>
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
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span class="inline-flex w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold tabular-nums text-slate-500" data-historical-row-number="line">{{ $loop->iteration }}</span>
                                        <h3 class="min-w-0 text-sm font-semibold text-slate-900">{{ $line['item_snapshot']['name'] ?? $line['source_description'] ?? '—' }}</h3>
                                    </div>
                                    <span class="shrink-0 text-right text-sm font-semibold tabular-nums text-slate-900">{{ number_format((float) ($line['line_total'] ?? 0), 2) }}@if(isset($line['calculation_state']['line_total']))<span class="mt-1 block rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ ($line['calculation_state']['line_total']['mode'] ?? 'auto') === 'manual' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}">{{ ($line['calculation_state']['line_total']['mode'] ?? 'auto') === 'manual' ? 'Manual' : 'Auto' }}</span>@endif</span>
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
        <form method="POST" action="{{ route('historical.manual.preview') }}" data-turbo="false" id="historical-manual-preview-form"
              x-data="historicalManualForm({
                  lines: @js(old('lines', [])),
                  payments: @js(old('payments', [])),
                  enabledMetals: @js($enabledMetals),
                  purityProfiles: @js($purityProfiles),
                  minimumRows: 1,
                  documentTotals: @js($documentTotals),
                  documentModes: @js($documentModes),
              })"
              @submit="submitOnce($event)"
              class="grid gap-4" data-historical-form="manual-preview" aria-labelledby="historical-preview-editor-title">
            @csrf

            @include('historical._manual-form-fields', compact('taxModes', 'taxSplitTypes', 'money', 'makingCategories', 'makingBases', 'shopPaymentMethods'))

            {{-- Both Save buttons are hidden while this bill has a blocking finding.
                 The server already refuses (storeManual returns a null document,
                 publishManual throws HistoricalManualPublishRejected), so offering
                 the buttons only bought the operator a round trip that ends back
                 on this page. The condition is $messages->hasBlocking() — the exact
                 call the service makes — rather than a re-derived one, so the screen
                 and the refusal cannot drift apart. Edit / Recalculate always stays:
                 it is the only way out of a blocked bill. --}}
            <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6" data-historical-preview-actions>
                <p class="text-xs text-slate-500">
                    @if($messages->hasBlocking())
                        Fix the blocking issues above, then recalculate the preview to enable saving.
                    @else
                        Save draft is reversible. Save &amp; publish makes the historical record permanent.
                    @endif
                </p>
                <div class="flex gap-3 flex-wrap">
                    <button class="btn min-h-[44px]" type="submit" :disabled="submitting">Edit / Recalculate preview</button>
                    @unless($messages->hasBlocking())
                        <button class="btn btn-primary min-h-[44px]" type="submit" :disabled="submitting"
                                name="intent" value="{{ \App\Http\Requests\Historical\StoreManualHistoricalRequest::INTENT_DRAFT }}"
                                formaction="{{ route('historical.manual.store') }}" data-historical-preview-action="draft">Save draft</button>
                        {{-- Batch 3 fast-entry: same store() endpoint, same validation, only
                             the intent value differs — see StoreManualHistoricalRequest::
                             wantsFreshFormAfterSuccess(). --}}
                        <button class="btn min-h-[44px]" type="submit" :disabled="submitting"
                                name="intent" value="{{ \App\Http\Requests\Historical\StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW }}"
                                formaction="{{ route('historical.manual.store') }}" data-historical-preview-action="draft-and-new">Save draft &amp; new</button>
                        @can('historical.publish')
                            <button class="btn min-h-[44px]" type="submit" :disabled="submitting"
                                    name="intent" value="{{ \App\Http\Requests\Historical\StoreManualHistoricalRequest::INTENT_PUBLISH }}"
                                    formaction="{{ route('historical.manual.store') }}" data-historical-preview-action="publish">Save &amp; publish</button>
                            <button class="btn min-h-[44px]" type="submit" :disabled="submitting"
                                    name="intent" value="{{ \App\Http\Requests\Historical\StoreManualHistoricalRequest::INTENT_PUBLISH_AND_NEW }}"
                                    formaction="{{ route('historical.manual.store') }}" data-historical-preview-action="publish-and-new">Publish &amp; new</button>
                        @endcan
                    @endunless
                </div>
            </div>
        </form>
        </div>
    </div>
</x-app-layout>

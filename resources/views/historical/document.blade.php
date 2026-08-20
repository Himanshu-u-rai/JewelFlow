@php
    use App\Models\Historical\HistoricalSalesDocument;
    use App\Services\Historical\HistoricalOpeningBalanceEvaluator;

    $statusColors = [
        HistoricalSalesDocument::STATUS_DRAFT      => 'bg-amber-100 text-amber-800',
        HistoricalSalesDocument::STATUS_PUBLISHED  => 'bg-emerald-100 text-emerald-800',
        HistoricalSalesDocument::STATUS_SUPERSEDED => 'bg-slate-100 text-slate-700',
        HistoricalSalesDocument::STATUS_VOID       => 'bg-rose-100 text-rose-800',
    ];
@endphp
<x-app-layout>
    <x-page-header title="{{ $document->displayNumber() }}" subtitle="{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}">
        <x-slot:actions>
            <a href="{{ route('historical.index') }}" class="btn btn-sm min-h-[44px]">← Historical sales</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner historical-document-page grid gap-4">
        <x-app-alerts />

        {{-- The immutable UI contract: badge + status, kept apart from the number
             itself (the number is the page title, the primary identity). --}}
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold uppercase tracking-wide bg-teal-700 text-white">
                {{ HistoricalSalesDocument::BADGE }}
            </span>
            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$document->status] ?? 'bg-slate-100 text-slate-700' }}">
                {{ ucfirst($document->status) }}
            </span>
        </div>

        {{-- Lifecycle: revises/supersededBy are already eager-loaded by the
             controller but were never rendered before this. Read-only, links only. --}}
        @if($document->revises || $document->supersededBy || $document->status === HistoricalSalesDocument::STATUS_VOID)
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-6">
                <h2 class="text-base font-semibold text-slate-800 mb-2">Lifecycle</h2>
                <ul class="text-sm text-slate-700 grid gap-1">
                    @if($document->revises)
                        <li>Revises
                            <a href="{{ route('historical.documents.show', $document->revises) }}" class="text-teal-700 hover:text-teal-800 font-medium">{{ $document->revises->displayNumber() }}</a>
                        </li>
                    @endif
                    @if($document->supersededBy)
                        <li>Superseded by
                            <a href="{{ route('historical.documents.show', $document->supersededBy) }}" class="text-teal-700 hover:text-teal-800 font-medium">{{ $document->supersededBy->displayNumber() }}</a>
                        </li>
                    @endif
                    @if($document->status === HistoricalSalesDocument::STATUS_VOID)
                        <li>
                            Voided{{ $document->voider?->name ? ' by '.$document->voider->name : '' }}{{ $document->voided_at ? ' on '.$document->voided_at->format('d M Y, H:i') : '' }}
                            @if($document->void_reason)<br><span class="text-slate-500">Reason: {{ $document->void_reason }}</span>@endif
                        </li>
                    @endif
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <h2 class="text-base font-semibold text-slate-800 mb-3">Document</h2>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-4 text-sm">
                    <dt class="text-slate-500">{{ HistoricalSalesDocument::NUMBER_LABEL }}</dt><dd class="text-slate-800 font-medium">{{ $document->displayNumber() }}</dd>
                    <dt class="text-slate-500">Series</dt><dd class="text-slate-800">{{ $document->document_series ?? '—' }}</dd>
                    <dt class="text-slate-500">Date</dt><dd class="text-slate-800">{{ $document->document_date?->toDateString() ?? '—' }}</dd>
                    <dt class="text-slate-500">Financial year</dt><dd class="text-slate-800">{{ $document->financial_year ?? '—' }}</dd>
                    <dt class="text-slate-500">Source system</dt><dd class="text-slate-800">{{ $document->source_system ?? '—' }}</dd>
                    <dt class="text-slate-500">Status</dt><dd class="text-slate-800">{{ ucfirst($document->status) }}</dd>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <h2 class="text-base font-semibold text-slate-800 mb-3">Customer snapshot</h2>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-4 text-sm mb-4">
                    <dt class="text-slate-500">Name</dt><dd class="text-slate-800">{{ data_get($document->customer_snapshot, 'name', '—') }}</dd>
                    <dt class="text-slate-500">Mobile</dt><dd class="text-slate-800">{{ data_get($document->customer_snapshot, 'mobile', '—') }}</dd>
                    <dt class="text-slate-500">GSTIN</dt><dd class="text-slate-800">{{ data_get($document->customer_snapshot, 'gstin', '—') }}</dd>
                    <dt class="text-slate-500">Place of supply</dt><dd class="text-slate-800">{{ data_get($document->customer_snapshot, 'place_of_supply', '—') }}</dd>
                </dl>

                <h3 class="text-sm font-semibold text-slate-700 mb-1">Linked customer <span class="font-normal text-slate-500">(the snapshot above never changes)</span></h3>
                @if($document->customer)
                    <p class="text-sm text-slate-700 flex items-center gap-2 flex-wrap">
                        {{ $document->customer->name }}
                        @if($document->customer->isArchived())
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">archived — link kept, no longer eligible for new links</span>
                        @endif
                    </p>
                @else
                    <p class="text-sm text-slate-500">Not linked — snapshot only.</p>
                @endif

                @if($document->status === HistoricalSalesDocument::STATUS_DRAFT)
                    @can('historical.import')
                        @php
                            $bases = ['mobile' => 'Mobile match', 'gstin' => 'GSTIN match', 'name' => 'Possible name match'];
                            $candidates = collect();
                            foreach ($bases as $key => $basisLabel) {
                                $match = $suggestions[$key] ?? ['status' => 'none', 'customers' => collect()];
                                if ($match['status'] !== 'match') {
                                    continue;
                                }
                                foreach ($match['customers'] as $candidate) {
                                    $entry = $candidates->get($candidate->id, ['candidate' => $candidate, 'bases' => []]);
                                    $entry['bases'][] = $basisLabel;
                                    $candidates->put($candidate->id, $entry);
                                }
                            }
                        @endphp

                        @if($suggestions)
                            @foreach(['gstin' => 'GSTIN'] as $key => $label)
                                @php $match = $suggestions[$key]; @endphp
                                @if($match['status'] === 'ambiguous')
                                    <p class="text-sm text-amber-700 font-medium mt-3">⚠ {{ $label }} match is ambiguous — {{ $match['customers']->count() }} customers share this GSTIN. Not linked automatically; review manually.</p>
                                @endif
                            @endforeach
                        @endif

                        @if($candidates->isNotEmpty())
                            <form method="POST" action="{{ route('historical.documents.link-customer', $document) }}" class="mt-3">
                                @csrf
                                <p class="text-sm text-slate-500 mb-2">Possible existing customers — nothing selected by default:</p>
                                <div class="grid gap-2 mb-2">
                                    @foreach($candidates as $entry)
                                        @php $candidate = $entry['candidate']; @endphp
                                        <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-200 hover:bg-slate-50">
                                            <input type="radio" name="customer_id" value="{{ $candidate->id }}" required>
                                            <span class="text-sm text-slate-700">{{ $candidate->name }} — {{ Str::mask($candidate->mobile ?? '—', '*', 2, -2) }}
                                                <span class="text-slate-500">({{ implode(', ', $entry['bases']) }})</span></span>
                                        </label>
                                    @endforeach
                                </div>
                                <button class="btn btn-sm min-h-[44px]" type="submit">Link selected customer</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('historical.documents.link-customer', $document) }}" class="flex items-end gap-2 mt-3">
                            @csrf
                            <div>
                                <label for="doc_customer_id" class="text-xs">Customer ID</label>
                                <input type="number" id="doc_customer_id" name="customer_id" placeholder="Customer ID" class="w-32">
                            </div>
                            <button class="btn btn-sm min-h-[44px]" type="submit">Link by ID</button>
                        </form>

                        <form method="POST" action="{{ route('historical.documents.link-customer', $document) }}" class="mt-2">
                            @csrf
                            <button class="btn btn-sm min-h-[44px]" type="submit">{{ $document->customer_id ? 'Unlink — keep snapshot only' : 'Keep historical snapshot only' }}</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>

        {{-- Opening-balance overlap: metadata-only, never touches the customer's
             actual opening balance/ledger/receivables. Severity is recomputed live
             (never cached), so this reflects the customer's opening-balance rows
             right now, not at the time the document was linked or last resolved. --}}
        @php
            $obSeverity = $openingBalanceSeverity ?? HistoricalOpeningBalanceEvaluator::NONE;
            $obResolved = $document->opening_balance_resolution !== null;
            $obIsDraft = $document->status === HistoricalSalesDocument::STATUS_DRAFT;
            // Alarm styling and the "publishing is blocked" claim are only true
            // while the document is still draft and the block is actionable.
            // A HIGH severity on a terminal (published/void/superseded) document
            // is immutable and can never be resolved — the copy must say so.
            $obHighUnresolved = $obSeverity === HistoricalOpeningBalanceEvaluator::HIGH && ! $obResolved && $obIsDraft;
        @endphp
        @if($obSeverity !== HistoricalOpeningBalanceEvaluator::NONE || $obResolved)
            <div class="rounded-2xl border p-4 sm:p-6 {{ $obHighUnresolved ? 'border-rose-300 bg-rose-50' : 'border-slate-200 bg-slate-50' }}">
                <h2 class="text-base font-semibold text-slate-800 mb-2">Opening-balance overlap</h2>

                @if($obResolved)
                    <p class="text-sm text-slate-700">
                        <strong>Resolved:</strong>
                        {{ $document->opening_balance_resolution === HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED ? 'Already included in opening balance' : 'Separate from opening balance' }}
                        <br>
                        <span class="text-slate-500">by {{ $document->openingBalanceResolver->name ?? 'unknown' }} on {{ $document->opening_balance_resolved_at?->format('d M Y, H:i') }}</span>
                    </p>
                @elseif($obSeverity === HistoricalOpeningBalanceEvaluator::HIGH && ! $obIsDraft)
                    <p class="text-sm text-slate-600 font-medium">⚠ HIGH — the linked customer already has an opening-balance entry on or before this bill's date, with an outstanding amount. This document is {{ $document->status }} and can no longer be changed. This overlap is shown for reference only and creates no ledger or receivable.</p>
                @elseif($obSeverity === HistoricalOpeningBalanceEvaluator::HIGH)
                    <p class="text-sm text-rose-700 font-medium">⚠ HIGH — the linked customer already has an opening-balance entry on or before this bill's date, with an outstanding amount. This bill's receivable may already be counted there. Publishing is blocked until this is resolved.</p>

                    {{-- Resolution clears a publish gate, so it needs historical.publish,
                         not historical.import — an import-only operator sees the block
                         notice above but no dangling instruction and no form. --}}
                    @can('historical.publish')
                        <p class="text-sm text-slate-600 mt-1">JewelFlow will not change the customer's opening balance automatically — pick the option that reflects reality:</p>
                        <form method="POST" action="{{ route('historical.documents.resolve-opening-balance', $document) }}"
                              onsubmit="return confirm('Confirm this opening-balance resolution?');" class="mt-3 grid gap-2">
                            @csrf
                            <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-200 bg-white">
                                <input type="radio" name="resolution" value="{{ HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED }}" required>
                                <span class="text-sm text-slate-700">Already included in opening balance</span>
                            </label>
                            <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-200 bg-white">
                                <input type="radio" name="resolution" value="{{ HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE }}" required>
                                <span class="text-sm text-slate-700">Separate from opening balance</span>
                            </label>
                            <div><button class="btn btn-sm min-h-[44px]" type="submit">Confirm resolution</button></div>
                        </form>
                    @endcan
                @elseif($obSeverity === HistoricalOpeningBalanceEvaluator::MEDIUM)
                    <p class="text-sm text-slate-600">ℹ MEDIUM — on or before the linked customer's opening-balance date, but no outstanding amount is recorded on this bill. Informational only, does not block publishing.</p>
                @endif
            </div>
        @endif

        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="text-base font-semibold text-slate-800 mb-3">Amounts <span class="font-normal text-slate-500 text-sm">(display snapshot — creates no ledger or receivable)</span></h2>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-100">
                    <tr><td class="py-1.5 text-slate-600">Taxable</td><td class="py-1.5 text-right tabular-nums">{{ $document->taxable_amount === null ? 'unavailable' : number_format((float) $document->taxable_amount, 2) }}</td></tr>
                    <tr><td class="py-1.5 text-slate-600">Tax completeness</td><td class="py-1.5 text-right">{{ $document->tax_completeness }}</td></tr>
                    <tr><td class="py-1.5 text-slate-600">Metal value</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) $document->metal_value, 2) }}</td></tr>
                    <tr><td class="py-1.5 text-slate-600">Stone value</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) $document->stone_value, 2) }}</td></tr>
                    <tr><td class="py-1.5 text-slate-600">Making / labour charge ({{ $document->making_category ?? 'uncategorised' }}, {{ $document->making_basis ?? 'unknown' }})</td>
                        <td class="py-1.5 text-right tabular-nums">{{ $document->making_amount === null ? ($document->making_value_original ?? '—').' (amount unknown)' : number_format((float) $document->making_amount, 2) }}</td></tr>
                    <tr><td class="py-1.5 text-slate-600">Discount</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) $document->discount_snapshot, 2) }}</td></tr>
                    <tr><td class="py-1.5 text-slate-600">Rounding</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) $document->rounding_snapshot, 2) }}</td></tr>
                    <tr class="font-semibold"><td class="py-1.5 text-slate-800">Grand total</td><td class="py-1.5 text-right tabular-nums">{{ number_format((float) $document->grand_total, 2) }}</td></tr>
                    <tr><td class="py-1.5 text-slate-600">Paid</td><td class="py-1.5 text-right tabular-nums">{{ $document->paid_amount_snapshot === null ? 'unknown' : number_format((float) $document->paid_amount_snapshot, 2) }}</td></tr>
                    <tr><td class="py-1.5 text-slate-600">Outstanding</td><td class="py-1.5 text-right tabular-nums">{{ $document->outstanding_amount_snapshot === null ? 'unknown' : number_format((float) $document->outstanding_amount_snapshot, 2) }}</td></tr>
                </tbody>
            </table>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
            <h2 class="text-base font-semibold text-slate-800 mb-3">Line items</h2>
            @if($document->lines->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full min-w-full text-sm">
                        <thead><tr class="text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                            <th class="py-2">Item</th><th class="py-2">SKU</th><th class="py-2">HSN</th><th class="py-2 text-right">Qty</th><th class="py-2 text-right">Net wt</th><th class="py-2 text-right">Line total</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach($document->lines as $line)
                            <tr>
                                <td class="py-2 text-slate-800">{{ data_get($line->item_snapshot, 'name', '—') }}</td>
                                <td class="py-2 text-slate-600">{{ $line->source_sku ?? '—' }}</td>
                                <td class="py-2 text-slate-600">{{ $line->hsn_snapshot ?? '—' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $line->quantity ?? '—' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $line->net_weight ?? '—' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ number_format((float) $line->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-slate-500">Header-only document — no itemised lines were recorded.</p>
            @endif
        </div>

        @can('historical.publish')
            @if($document->status === HistoricalSalesDocument::STATUS_PUBLISHED)
                <div class="rounded-2xl border border-rose-300 bg-rose-50 p-4 sm:p-6">
                    <h2 class="text-base font-semibold text-rose-800 mb-1">Danger zone</h2>
                    <p class="text-sm text-rose-700 mb-4">These actions change the lifecycle of a published record. Neither deletes it — the record and its number stay as evidence.</p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <form method="POST" action="{{ route('historical.documents.void', $document) }}"
                              onsubmit="return confirm('Void this document? Its number and fingerprint are released.');" class="grid gap-2">
                            @csrf
                            <label for="void_reason" class="text-sm font-medium text-slate-700">Void</label>
                            <input type="text" id="void_reason" name="reason" placeholder="Reason for voiding (required)" required class="w-full">
                            <p class="text-xs text-slate-500">Marks this document void and frees its original number for reuse. Cannot be undone from here.</p>
                            <div><button class="btn btn-danger btn-sm min-h-[44px]" type="submit">Void document</button></div>
                        </form>

                        <form method="POST" action="{{ route('historical.documents.supersede', $document) }}"
                              onsubmit="return confirm('Supersede this document with the replacement? The original is kept as evidence and marked superseded.');" class="grid gap-2">
                            @csrf
                            <label for="supersede_replacement_id" class="text-sm font-medium text-slate-700">Supersede</label>
                            <input type="number" id="supersede_replacement_id" name="replacement_id" placeholder="Replacement document ID" required class="w-full">
                            <input type="text" id="supersede_reason" name="reason" placeholder="Reason (optional, recorded)" maxlength="500" class="w-full">
                            <p class="text-xs text-slate-500">Marks this document superseded and points it to the replacement, which becomes published in its place. Cannot be undone from here.</p>
                            <div><button class="btn btn-danger btn-sm min-h-[44px]" type="submit">Supersede document</button></div>
                        </form>
                    </div>
                </div>
            @endif
        @endcan
    </div>
</x-app-layout>

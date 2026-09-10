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

    <div class="content-inner historical-document-page grid gap-4">
        <x-app-alerts />

        {{-- The immutable UI contract: badge + status, kept apart from the number
             itself (the number is the page title, the primary identity). --}}
        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold uppercase tracking-wide bg-teal-700 text-white">
                    {{ HistoricalSalesDocument::BADGE }}
                </span>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$document->status] ?? 'bg-slate-100 text-slate-700' }}">
                    {{ ucfirst($document->status) }}
                </span>
            </div>
            <div class="border-t border-amber-200 bg-amber-50 px-4 py-3 sm:px-6">
                <p class="text-sm text-amber-800">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>
            </div>
        </section>

        {{-- Lifecycle: revises/supersededBy are already eager-loaded by the
             controller but were never rendered before this. Read-only, links only. --}}
        @if($document->revises || $document->supersededBy || $lifecycle['is_void'])
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
                    @if($lifecycle['is_void'])
                        <li>
                            Voided{{ $document->voider?->name ? ' by '.$document->voider->name : '' }}{{ $document->voided_at ? ' on '.$document->voided_at->format('d M Y, H:i') : '' }}
                            @if($document->void_reason)<br><span class="text-slate-500">Reason: {{ $document->void_reason }}</span>@endif
                        </li>
                    @endif
                </ul>
            </div>
        @endif

        @if($lifecycle['is_manual'])
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" data-historical-manual-lifecycle>
                <div class="flex flex-col items-start gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:justify-between sm:px-6">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Manual bill review</p>
                        <h2 class="mt-1 text-lg font-semibold text-slate-900">Review findings and lifecycle</h2>
                        <p class="mt-1 text-sm text-slate-600">This document is reviewed and managed individually. File-import batch controls do not apply.</p>
                    </div>
                    <span class="inline-flex shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                        {{ ucfirst((string) ($lifecycle['batch_status'] ?? 'unknown')) }}
                    </span>
                </div>

                <div class="grid gap-4 p-4 sm:p-6">
                    @if($lifecycle['is_published'])
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-historical-manual-terminal="published">
                            Published record — findings remain visible for reference. No manual review action is available.
                        </div>
                    @elseif($lifecycle['is_void'])
                        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" data-historical-manual-terminal="void">
                            Void record — findings remain visible for reference. No manual review action is available.
                        </div>
                    @elseif($lifecycle['is_superseded'])
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" data-historical-manual-terminal="superseded">
                            Superseded record — findings remain visible for reference. No manual review action is available.
                        </div>
                    @endif

                    @if($lifecycle['blocking'] !== [])
                        <section class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3" data-historical-findings="blocking" role="alert">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-rose-800">Blocking issues</h3>
                                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold tabular-nums text-rose-800">{{ $lifecycle['blocking_count'] }}</span>
                            </div>
                            <div class="mt-2 grid gap-1">
                                @foreach($lifecycle['blocking'] as $finding)
                                    <p class="text-sm text-rose-700">{{ $finding['text'] }}</p>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if($lifecycle['warnings'] !== [])
                        <section class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3" data-historical-findings="warnings">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-amber-800">Review warnings</h3>
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold tabular-nums text-amber-800">{{ $lifecycle['warning_count'] }}</span>
                            </div>
                            <div class="mt-2 grid gap-1">
                                @foreach($lifecycle['warnings'] as $finding)
                                    <p class="text-sm text-amber-800">{{ $finding['text'] }}</p>
                                @endforeach
                            </div>

                            @if($lifecycle['warnings_acknowledged'])
                                <p class="mt-3 border-t border-amber-200 pt-3 text-sm font-medium text-amber-900" data-historical-acknowledgement="complete">
                                    Warnings acknowledged{{ $lifecycle['acknowledged_at'] ? ' on '.$lifecycle['acknowledged_at']->format('d M Y, H:i') : '' }}.
                                </p>
                            @else
                                <p class="mt-3 border-t border-amber-200 pt-3 text-sm text-amber-900" data-historical-acknowledgement="pending">Warnings still need acknowledgement.</p>
                                @if($lifecycle['is_draft'])
                                    @can('historical.import')
                                        <form method="POST" action="{{ route('historical.documents.acknowledge', $document) }}" class="mt-3" data-historical-manual-action="acknowledge">
                                            @csrf
                                            <button class="btn min-h-[44px]" type="submit">Acknowledge warnings</button>
                                        </form>
                                    @endcan
                                @endif
                            @endif
                        </section>
                    @endif

                    @if($lifecycle['informational'] !== [])
                        <section class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3" data-historical-findings="informational">
                            <h3 class="text-sm font-semibold text-blue-800">Record notes</h3>
                            <div class="mt-2 grid gap-1">
                                @foreach($lifecycle['informational'] as $finding)
                                    <p class="text-sm text-blue-800">{{ $finding['text'] }}</p>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if($lifecycle['is_draft'])
                        <section class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4" data-historical-manual-next-actions>
                            <h3 class="text-sm font-semibold text-slate-900">Next action</h3>
                            @if($lifecycle['can_publish'])
                                <p class="mt-1 text-sm text-slate-600">Review complete. Publishing makes this historical record permanent.</p>
                                @can('historical.publish')
                                    <form method="POST" action="{{ route('historical.documents.publish', $document) }}" class="mt-3" data-historical-manual-action="publish">
                                        @csrf
                                        <button class="btn btn-primary min-h-[44px]" type="submit">Publish historical bill</button>
                                    </form>
                                @endcan
                            @elseif($lifecycle['publish_blocker'] !== null)
                                <p class="mt-1 text-sm text-slate-700" data-historical-publish-blocker>{{ $lifecycle['publish_blocker'] }}</p>
                            @endif
                        </section>
                    @endif
                </div>
            </section>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4" data-historical-document-layout>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 lg:col-span-2" data-historical-document-card="identity">
                <h2 class="text-base font-semibold text-slate-800 mb-3">Document</h2>
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="number">
                        <dt class="text-xs font-medium text-slate-500">{{ HistoricalSalesDocument::NUMBER_LABEL }}</dt>
                        <dd class="mt-1 font-medium text-slate-800">{{ $document->displayNumber() }}</dd>
                    </div>
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="series">
                        <dt class="text-xs font-medium text-slate-500">Series</dt>
                        <dd class="mt-1 text-slate-800">{{ $document->document_series ?? '—' }}</dd>
                    </div>
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="date">
                        <dt class="text-xs font-medium text-slate-500">Date</dt>
                        <dd class="mt-1 text-slate-800">{{ $document->document_date?->toDateString() ?? '—' }}</dd>
                    </div>
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="financial-year">
                        <dt class="text-xs font-medium text-slate-500">Financial year</dt>
                        <dd class="mt-1 text-slate-800">{{ $document->financial_year ?? '—' }}</dd>
                    </div>
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="source-system">
                        <dt class="text-xs font-medium text-slate-500">Source system</dt>
                        <dd class="mt-1 text-slate-800">{{ $document->source_system ?? '—' }}</dd>
                    </div>
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="status">
                        <dt class="text-xs font-medium text-slate-500">Status</dt>
                        <dd class="mt-1 text-slate-800">{{ ucfirst($document->status) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6" data-historical-document-card="customer">
                <h2 class="text-base font-semibold text-slate-800 mb-3">Customer snapshot</h2>
                <dl class="mb-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="customer-name">
                        <dt class="text-xs font-medium text-slate-500">Name</dt>
                        <dd class="mt-1 text-slate-800">{{ data_get($document->customer_snapshot, 'name', '—') }}</dd>
                    </div>
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="customer-mobile">
                        <dt class="text-xs font-medium text-slate-500">Mobile</dt>
                        <dd class="mt-1 text-slate-800">{{ data_get($document->customer_snapshot, 'mobile', '—') }}</dd>
                    </div>
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="customer-gstin">
                        <dt class="text-xs font-medium text-slate-500">GSTIN</dt>
                        <dd class="mt-1 text-slate-800">{{ data_get($document->customer_snapshot, 'gstin', '—') }}</dd>
                    </div>
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="place-of-supply">
                        <dt class="text-xs font-medium text-slate-500">Place of supply</dt>
                        <dd class="mt-1 text-slate-800">{{ data_get($document->customer_snapshot, 'place_of_supply', '—') }}</dd>
                    </div>
                </dl>

                <h3 class="text-sm font-semibold text-slate-700 mb-1">Linked customer <span class="font-normal text-slate-500">(the snapshot above never changes)</span></h3>
                @if($lifecycle['customer_linked'])
                    <p class="text-sm text-slate-700 flex items-center gap-2 flex-wrap">
                        {{ $document->customer?->name ?? 'Linked customer unavailable' }}
                        @if($document->customer?->isArchived())
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">archived — link kept, no longer eligible for new links</span>
                        @endif
                    </p>
                @else
                    <p class="text-sm text-slate-500">Not linked — snapshot only.</p>
                @endif

            </div>
        </div>

        @if($lifecycle['is_draft'])
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

                <section class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50" data-historical-customer-link-panel>
                    <div class="flex items-start gap-3 border-b border-slate-200 bg-white px-4 py-4 sm:px-6">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-700" aria-hidden="true">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path d="M7.75 12.25l4.5-4.5M6.25 14.75l-1 1a2.12 2.12 0 0 1-3-3l3-3a2.12 2.12 0 0 1 3 0M13.75 5.25l1-1a2.12 2.12 0 1 1 3 3l-3 3a2.12 2.12 0 0 1-3 0" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-slate-900">Customer link</h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Optional account reference. The historical snapshot never changes.</p>
                        </div>
                    </div>

                    <div class="grid gap-4 p-4 sm:p-6 lg:grid-cols-2" data-historical-customer-link-actions>
                        @if($suggestions)
                            @foreach(['gstin' => 'GSTIN'] as $key => $label)
                                @php $match = $suggestions[$key]; @endphp
                                @if($match['status'] === 'ambiguous')
                                    <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 lg:col-span-2">{{ $label }} match is ambiguous — {{ $match['customers']->count() }} customers share this GSTIN. Review manually.</p>
                                @endif
                            @endforeach
                        @endif

                        @if($candidates->isNotEmpty())
                            <form method="POST" action="{{ route('historical.documents.link-customer', $document) }}" class="rounded-xl border border-slate-200 bg-white p-4 lg:col-span-2" data-historical-customer-link-action="suggested">
                                @csrf
                                <p class="text-sm font-semibold text-slate-800">Possible existing customers</p>
                                <p class="mt-1 text-xs text-slate-500">Choose one only after confirming the match.</p>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    @foreach($candidates as $entry)
                                        @php $candidate = $entry['candidate']; @endphp
                                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-3 hover:bg-slate-50">
                                            <input type="radio" name="customer_id" value="{{ $candidate->id }}" required>
                                            <span class="text-sm text-slate-700">{{ $candidate->name }} — {{ Str::mask($candidate->mobile ?? '—', '*', 2, -2) }}
                                                <span class="text-slate-500">({{ implode(', ', $entry['bases']) }})</span></span>
                                        </label>
                                    @endforeach
                                </div>
                                <button class="btn btn-primary mt-3 min-h-[44px]" type="submit">Link selected customer</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('historical.documents.link-customer', $document) }}" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4" data-historical-customer-link-action="by-id">
                            @csrf
                            <div class="min-w-0">
                                <label for="doc_customer_id" class="block text-sm font-semibold text-slate-800">Customer ID</label>
                                <p class="mt-1 text-xs text-slate-500">Use this when you know the existing JewelFlow customer ID.</p>
                                <input type="number" id="doc_customer_id" name="customer_id" placeholder="Enter customer ID" class="mt-3 min-h-[44px] w-full rounded-lg border-slate-300 bg-white">
                            </div>
                            <button class="btn btn-primary min-h-[44px] w-full" type="submit">Link customer by ID</button>
                        </form>

                        <form method="POST" action="{{ route('historical.documents.link-customer', $document) }}" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4" data-historical-customer-link-action="snapshot-only">
                            @csrf
                            <div>
                                <p class="text-sm font-semibold text-slate-800">Snapshot only</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">Do not connect this record to a customer account. Its saved customer details remain unchanged.</p>
                            </div>
                            <button class="btn min-h-[44px] w-full" type="submit">{{ $document->customer_id ? 'Unlink — keep snapshot only' : 'Keep historical snapshot only' }}</button>
                        </form>
                    </div>
                </section>
            @endcan
        @endif

        {{-- Opening-balance overlap: metadata-only, never touches the customer's
             actual opening balance/ledger/receivables. Severity is recomputed live
             (never cached), so this reflects the customer's opening-balance rows
             right now, not at the time the document was linked or last resolved. --}}
        @php
            $obSeverity = $openingBalanceSeverity ?? HistoricalOpeningBalanceEvaluator::NONE;
            $obResolved = $lifecycle['opening_balance_resolution'] !== null;
            $obIsDraft = $lifecycle['is_draft'];
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
                        {{ $lifecycle['opening_balance_resolution'] === HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED ? 'Already included in opening balance' : 'Separate from opening balance' }}
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

        <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6" data-historical-document-card="amounts">
            <h2 class="text-base font-semibold text-slate-800 mb-3">Amounts <span class="font-normal text-slate-500 text-sm">(display snapshot — creates no ledger or receivable)</span></h2>
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="taxable">
                    <dt class="text-xs font-medium text-slate-500">Taxable</dt>
                    <dd class="mt-1 font-medium tabular-nums text-slate-800">{{ $document->taxable_amount === null ? 'unavailable' : number_format((float) $document->taxable_amount, 2) }}</dd>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="tax-completeness">
                    <dt class="text-xs font-medium text-slate-500">Tax completeness</dt>
                    <dd class="mt-1 font-medium text-slate-800">{{ $document->tax_completeness }}</dd>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="metal-value">
                    <dt class="text-xs font-medium text-slate-500">Metal value</dt>
                    <dd class="mt-1 font-medium tabular-nums text-slate-800">{{ number_format((float) $document->metal_value, 2) }}</dd>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="stone-value">
                    <dt class="text-xs font-medium text-slate-500">Stone value</dt>
                    <dd class="mt-1 font-medium tabular-nums text-slate-800">{{ number_format((float) $document->stone_value, 2) }}</dd>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3 sm:col-span-2" data-historical-document-field="making-charge">
                    <dt class="text-xs font-medium text-slate-500">Making / labour charge ({{ $document->making_category ?? 'uncategorised' }}, {{ $document->making_basis ?? 'unknown' }})</dt>
                    <dd class="mt-1 font-medium tabular-nums text-slate-800">{{ $document->making_amount === null ? ($document->making_value_original ?? '—').' (amount unknown)' : number_format((float) $document->making_amount, 2) }}</dd>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="discount">
                    <dt class="text-xs font-medium text-slate-500">Discount</dt>
                    <dd class="mt-1 font-medium tabular-nums text-slate-800">{{ number_format((float) $document->discount_snapshot, 2) }}</dd>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="rounding">
                    <dt class="text-xs font-medium text-slate-500">Rounding</dt>
                    <dd class="mt-1 font-medium tabular-nums text-slate-800">{{ number_format((float) $document->rounding_snapshot, 2) }}</dd>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-3" data-historical-document-field="grand-total">
                    <dt class="text-xs font-medium text-amber-700">Grand total</dt>
                    <dd class="mt-1 font-semibold tabular-nums text-slate-900">{{ number_format((float) $document->grand_total, 2) }}</dd>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="paid">
                    <dt class="text-xs font-medium text-slate-500">Paid</dt>
                    <dd class="mt-1 font-medium tabular-nums text-slate-800">{{ $document->paid_amount_snapshot === null ? 'unknown' : number_format((float) $document->paid_amount_snapshot, 2) }}</dd>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3" data-historical-document-field="outstanding">
                    <dt class="text-xs font-medium text-slate-500">Outstanding</dt>
                    <dd class="mt-1 font-medium tabular-nums text-slate-800">{{ $document->outstanding_amount_snapshot === null ? 'unknown' : number_format((float) $document->outstanding_amount_snapshot, 2) }}</dd>
                </div>
            </dl>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                <h2 class="text-base font-semibold text-slate-900">Line items <span class="text-sm font-normal text-slate-500">({{ $document->lines->count() }})</span></h2>
                <p class="mt-1 text-sm text-slate-500">Read-only item details captured with this historical document.</p>
            </div>
            @if($document->lines->isNotEmpty())
                <div class="hidden md:block" data-historical-document-register="lines-desktop">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-full text-sm">
                        <thead class="bg-slate-50 border-b border-slate-200"><tr class="text-left text-xs font-semibold normal-case tracking-normal text-slate-600">
                            <th class="w-16 px-4 py-3 text-center">No.</th><th class="px-4 py-3 sm:px-6">Item</th><th class="px-4 py-3 sm:px-6">SKU</th><th class="px-4 py-3 sm:px-6">HSN</th><th class="px-4 py-3 text-right sm:px-6">Qty</th><th class="px-4 py-3 text-right sm:px-6">Net wt</th><th class="px-4 py-3 text-right sm:px-6">Line total</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach($document->lines as $line)
                            <tr class="transition-colors hover:bg-slate-50">
                                <td class="w-16 px-4 py-4 text-center text-sm font-medium tabular-nums text-slate-500" data-historical-row-number="line">{{ $loop->iteration }}</td>
                                <td class="px-4 py-4 text-sm font-semibold text-slate-900 sm:px-6">{{ data_get($line->item_snapshot, 'name', '—') }}</td>
                                <td class="px-4 py-4 text-slate-600 sm:px-6">{{ $line->source_sku ?? '—' }}</td>
                                <td class="px-4 py-4 text-slate-600 sm:px-6">{{ $line->hsn_snapshot ?? '—' }}</td>
                                <td class="px-4 py-4 text-right tabular-nums sm:px-6">{{ $line->quantity ?? '—' }}</td>
                                <td class="px-4 py-4 text-right tabular-nums sm:px-6">{{ $line->net_weight ?? '—' }}</td>
                                <td class="px-4 py-4 text-right font-semibold tabular-nums text-slate-900 sm:px-6">{{ number_format((float) $line->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                </div>
                <div class="grid gap-3 bg-slate-50 p-3 md:hidden" data-historical-document-register="lines-mobile">
                    @foreach($document->lines as $line)
                        <article class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-2">
                                    <span class="inline-flex w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold tabular-nums text-slate-500" data-historical-row-number="line">{{ $loop->iteration }}</span>
                                    <div class="min-w-0">
                                    <h3 class="text-sm font-semibold text-slate-900">{{ data_get($line->item_snapshot, 'name', '—') }}</h3>
                                    <p class="mt-1 text-xs text-slate-500">SKU {{ $line->source_sku ?? '—' }} · HSN {{ $line->hsn_snapshot ?? '—' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">Qty {{ $line->quantity ?? '—' }} · Net wt {{ $line->net_weight ?? '—' }}</p>
                                    </div>
                                </div>
                                <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">{{ number_format((float) $line->line_total, 2) }}</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="p-4 sm:p-6"><p class="text-sm text-slate-500">Header-only document — no itemised lines were recorded.</p></div>
            @endif
        </section>

        {{-- Evidence attachments (Batch 5). Upload/removal require historical.import
             and are blocked by the service on void/superseded documents (terminal —
             the docblock on HistoricalSalesDocumentAttachmentService::assertMutable()
             explains why); existing evidence stays viewable in every status. --}}
        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden" data-historical-attachments>
            <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
                <h2 class="text-base font-semibold text-slate-900">Evidence attachments <span class="text-sm font-normal text-slate-500">({{ $document->attachments->where('is_active', true)->count() }})</span></h2>
                <p class="mt-1 text-sm text-slate-500">Scanned bills or proof supporting this record. Stored privately — never a public link.</p>
            </div>

            <div class="p-4 sm:p-6">
                @if($lifecycle['is_void'] || $lifecycle['is_superseded'])
                    <p class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600" data-historical-attachments-readonly>
                        This document is {{ $document->status }} — evidence is read-only. Existing attachments remain viewable below; none can be added or removed.
                    </p>
                @else
                    @can('historical.import')
                        <form method="POST" action="{{ route('historical.documents.attachments.store', $document) }}" enctype="multipart/form-data" class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-4" data-historical-attachment-upload>
                            @csrf
                            <label for="attachment_file" class="text-sm font-medium text-slate-700">Upload evidence</label>
                            <input type="file" id="attachment_file" name="file" accept=".jpg,.jpeg,.png,.pdf" required class="min-h-[44px] w-full text-sm">
                            <p class="text-xs text-slate-500">JPEG, PNG or PDF, up to 10 MB.</p>
                            <div><button class="btn btn-sm min-h-[44px]" type="submit">Upload</button></div>
                        </form>
                    @endcan
                @endif

                @if($document->attachments->isEmpty())
                    <p class="text-sm text-slate-500">No evidence attached yet.</p>
                @else
                    <ul class="grid gap-2" data-historical-attachment-list>
                        @foreach($document->attachments as $attachment)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 {{ $attachment->is_active ? '' : 'opacity-60' }}" data-historical-attachment-row data-historical-attachment-active="{{ $attachment->is_active ? '1' : '0' }}">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800">{{ $attachment->original_filename }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Uploaded by {{ $attachment->uploadedBy?->name ?? 'unknown' }} on {{ $attachment->created_at?->format('d M Y, H:i') }}
                                    </p>
                                    @if(! $attachment->is_active)
                                        <p class="mt-0.5 text-xs text-rose-600">
                                            Removed by {{ $attachment->removedBy?->name ?? 'unknown' }} on {{ $attachment->removed_at?->format('d M Y, H:i') }} — {{ $attachment->removed_reason }}
                                        </p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    {{-- is_active ONLY, deliberately not the document lifecycle: the
                                         stream route 404s a removed attachment, so a View link on one is
                                         dead. Void/superseded documents keep their ACTIVE evidence
                                         viewable — see the readonly notice above. --}}
                                    @if($attachment->is_active)
                                        @can('historical.view')
                                            <a href="{{ route('historical.attachments.show', $attachment) }}" target="_blank" rel="noopener" class="text-sm font-medium text-teal-700 hover:text-teal-800">View</a>
                                        @endcan
                                    @endif
                                    @if($attachment->is_active && ! $lifecycle['is_void'] && ! $lifecycle['is_superseded'])
                                        @can('historical.import')
                                            <details class="relative" data-historical-attachment-remove>
                                                <summary class="cursor-pointer text-sm font-medium text-rose-600 hover:text-rose-700 list-none">Remove</summary>
                                                <form method="POST" action="{{ route('historical.attachments.destroy', $attachment) }}"
                                                      onsubmit="return confirm('Remove this attachment? It stops being viewable here. The file and the audit history are both kept.');"
                                                      class="mt-2 flex flex-wrap items-center gap-2">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="text" name="reason" placeholder="Reason (required)" required maxlength="500" class="min-h-[36px] rounded-lg border-slate-300 text-sm">
                                                    <button class="btn btn-danger btn-sm min-h-[36px]" type="submit">Confirm removal</button>
                                                </form>
                                            </details>
                                        @endcan
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        @can('historical.publish')
            @if($lifecycle['is_published'])
                <div class="rounded-2xl border border-rose-200 bg-white p-4 sm:p-6">
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

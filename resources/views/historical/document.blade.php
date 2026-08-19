@php
    use App\Models\Historical\HistoricalSalesDocument;
    use App\Services\Historical\HistoricalOpeningBalanceEvaluator;
@endphp
<x-app-layout>
    <div class="page" style="padding:1rem;max-width:900px;margin:0 auto;">
        <x-app-alerts />

        {{-- The immutable UI contract: badge + disclaimer, wording owned by the model. --}}
        <div style="display:flex;align-items:center;gap:.5rem;">
            <span class="badge" style="background:#0f766e;color:#fff;">{{ HistoricalSalesDocument::BADGE }}</span>
            <h1 style="margin:0;">{{ $document->displayNumber() }}</h1>
        </div>
        <p style="color:#b45309;font-weight:600;margin:.25rem 0 1rem;">{{ HistoricalSalesDocument::RECORD_DISCLAIMER }}</p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <h3>Document</h3>
                <dl>
                    <dt>{{ HistoricalSalesDocument::NUMBER_LABEL }}</dt><dd>{{ $document->displayNumber() }}</dd>
                    <dt>Series</dt><dd>{{ $document->document_series ?? '—' }}</dd>
                    <dt>Date</dt><dd>{{ $document->document_date?->toDateString() ?? '—' }}</dd>
                    <dt>Financial year</dt><dd>{{ $document->financial_year ?? '—' }}</dd>
                    <dt>Source system</dt><dd>{{ $document->source_system ?? '—' }}</dd>
                    <dt>Status</dt><dd>{{ ucfirst($document->status) }}</dd>
                </dl>
            </div>
            <div>
                <h3>Customer snapshot</h3>
                <dl>
                    <dt>Name</dt><dd>{{ data_get($document->customer_snapshot, 'name', '—') }}</dd>
                    <dt>Mobile</dt><dd>{{ data_get($document->customer_snapshot, 'mobile', '—') }}</dd>
                    <dt>GSTIN</dt><dd>{{ data_get($document->customer_snapshot, 'gstin', '—') }}</dd>
                    <dt>Place of supply</dt><dd>{{ data_get($document->customer_snapshot, 'place_of_supply', '—') }}</dd>
                </dl>

                <h4>Linked customer <small style="color:#64748b;">(the snapshot above never changes)</small></h4>
                @if($document->customer)
                    <p>
                        {{ $document->customer->name }}
                        @if($document->customer->isArchived())
                            <span style="color:#b45309;">(archived — link kept, no longer eligible for new links)</span>
                        @endif
                    </p>
                @else
                    <p style="color:#64748b;">Not linked — snapshot only.</p>
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
                                    <p style="color:#b45309;font-weight:600;">⚠ {{ $label }} match is ambiguous — {{ $match['customers']->count() }} customers share this GSTIN. Not linked automatically; review manually.</p>
                                @endif
                            @endforeach
                        @endif

                        @if($candidates->isNotEmpty())
                            <form method="POST" action="{{ route('historical.documents.link-customer', $document) }}">
                                @csrf
                                <p style="color:#64748b;margin-bottom:.25rem;">Possible existing customers — nothing selected by default:</p>
                                @foreach($candidates as $entry)
                                    @php $candidate = $entry['candidate']; @endphp
                                    <label style="display:flex;align-items:center;gap:.5rem;padding:.5rem;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:.4rem;min-height:44px;">
                                        <input type="radio" name="customer_id" value="{{ $candidate->id }}" required>
                                        <span>{{ $candidate->name }} — {{ Str::mask($candidate->mobile ?? '—', '*', 2, -2) }}
                                            <small style="color:#64748b;">({{ implode(', ', $entry['bases']) }})</small></span>
                                    </label>
                                @endforeach
                                <button class="btn" type="submit">Link selected customer</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('historical.documents.link-customer', $document) }}" style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem;">
                            @csrf
                            <input type="number" name="customer_id" placeholder="Customer ID" style="width:8rem;">
                            <button class="btn" type="submit">Link by ID</button>
                        </form>

                        <form method="POST" action="{{ route('historical.documents.link-customer', $document) }}" style="margin-top:.5rem;">
                            @csrf
                            <button class="btn" type="submit">{{ $document->customer_id ? 'Unlink — keep snapshot only' : 'Keep historical snapshot only' }}</button>
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
        @endphp
        @if($obSeverity !== HistoricalOpeningBalanceEvaluator::NONE || $obResolved)
            <div style="margin:1rem 0;padding:1rem;border-radius:8px;border:1px solid {{ $obSeverity === HistoricalOpeningBalanceEvaluator::HIGH && ! $obResolved ? '#fca5a5' : '#e2e8f0' }};background:{{ $obSeverity === HistoricalOpeningBalanceEvaluator::HIGH && ! $obResolved ? '#fef2f2' : '#f8fafc' }};">
                <h3 style="margin-top:0;">Opening-balance overlap</h3>

                @if($obResolved)
                    <p>
                        <strong>Resolved:</strong>
                        {{ $document->opening_balance_resolution === HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED ? 'Already included in opening balance' : 'Separate from opening balance' }}
                        <br>
                        <small style="color:#64748b;">by {{ $document->openingBalanceResolver->name ?? 'unknown' }} on {{ $document->opening_balance_resolved_at?->format('d M Y, H:i') }}</small>
                    </p>
                @elseif($obSeverity === HistoricalOpeningBalanceEvaluator::HIGH)
                    <p style="color:#b91c1c;font-weight:600;">⚠ HIGH — the linked customer already has an opening-balance entry on or before this bill's date, with an outstanding amount. This bill's receivable may already be counted there. Publishing is blocked until this is resolved.</p>
                    <p style="color:#64748b;">JewelFlow will not change the customer's opening balance automatically — pick the option that reflects reality:</p>

                    @if($document->status === HistoricalSalesDocument::STATUS_DRAFT)
                        @can('historical.import')
                            <form method="POST" action="{{ route('historical.documents.resolve-opening-balance', $document) }}"
                                  onsubmit="return confirm('Confirm this opening-balance resolution?');">
                                @csrf
                                <label style="display:flex;align-items:center;gap:.5rem;padding:.5rem;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:.4rem;min-height:44px;">
                                    <input type="radio" name="resolution" value="{{ HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED }}" required>
                                    <span>Already included in opening balance</span>
                                </label>
                                <label style="display:flex;align-items:center;gap:.5rem;padding:.5rem;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:.4rem;min-height:44px;">
                                    <input type="radio" name="resolution" value="{{ HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE }}" required>
                                    <span>Separate from opening balance</span>
                                </label>
                                <button class="btn" type="submit">Confirm resolution</button>
                            </form>
                        @endcan
                    @endif
                @elseif($obSeverity === HistoricalOpeningBalanceEvaluator::MEDIUM)
                    <p style="color:#334155;">ℹ MEDIUM — on or before the linked customer's opening-balance date, but no outstanding amount is recorded on this bill. Informational only, does not block publishing.</p>
                @endif
            </div>
        @endif

        <h3>Amounts <small style="color:#64748b;">(display snapshot — creates no ledger or receivable)</small></h3>
        <table class="data-table" style="width:100%;border-collapse:collapse;">
            <tbody>
                <tr><td>Taxable</td><td style="text-align:right;">{{ $document->taxable_amount === null ? 'unavailable' : number_format((float) $document->taxable_amount, 2) }}</td></tr>
                <tr><td>Tax completeness</td><td style="text-align:right;">{{ $document->tax_completeness }}</td></tr>
                <tr><td>Metal value</td><td style="text-align:right;">{{ number_format((float) $document->metal_value, 2) }}</td></tr>
                <tr><td>Stone value</td><td style="text-align:right;">{{ number_format((float) $document->stone_value, 2) }}</td></tr>
                <tr><td>Making / labour ({{ $document->making_category ?? 'uncategorised' }}, {{ $document->making_basis ?? 'unknown' }})</td>
                    <td style="text-align:right;">{{ $document->making_amount === null ? ($document->making_value_original ?? '—').' (amount unknown)' : number_format((float) $document->making_amount, 2) }}</td></tr>
                <tr><td>Discount</td><td style="text-align:right;">{{ number_format((float) $document->discount_snapshot, 2) }}</td></tr>
                <tr><td>Rounding</td><td style="text-align:right;">{{ number_format((float) $document->rounding_snapshot, 2) }}</td></tr>
                <tr style="font-weight:700;"><td>Grand total</td><td style="text-align:right;">{{ number_format((float) $document->grand_total, 2) }}</td></tr>
                <tr><td>Paid</td><td style="text-align:right;">{{ $document->paid_amount_snapshot === null ? 'unknown' : number_format((float) $document->paid_amount_snapshot, 2) }}</td></tr>
                <tr><td>Outstanding</td><td style="text-align:right;">{{ $document->outstanding_amount_snapshot === null ? 'unknown' : number_format((float) $document->outstanding_amount_snapshot, 2) }}</td></tr>
            </tbody>
        </table>

        @if($document->lines->isNotEmpty())
            <h3>Line items</h3>
            <table class="data-table" style="width:100%;border-collapse:collapse;">
                <thead><tr><th style="text-align:left;">Item</th><th>SKU</th><th>HSN</th><th>Qty</th><th>Net wt</th><th>Line total</th></tr></thead>
                <tbody>
                @foreach($document->lines as $line)
                    <tr>
                        <td style="text-align:left;">{{ data_get($line->item_snapshot, 'name', '—') }}</td>
                        <td>{{ $line->source_sku ?? '—' }}</td>
                        <td>{{ $line->hsn_snapshot ?? '—' }}</td>
                        <td style="text-align:right;">{{ $line->quantity ?? '—' }}</td>
                        <td style="text-align:right;">{{ $line->net_weight ?? '—' }}</td>
                        <td style="text-align:right;">{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            <p style="color:#64748b;">Header-only document — no itemised lines were recorded.</p>
        @endif

        @can('historical.publish')
            @if($document->status === HistoricalSalesDocument::STATUS_PUBLISHED)
                <form method="POST" action="{{ route('historical.documents.void', $document) }}"
                      onsubmit="return confirm('Void this document? Its number and fingerprint are released.');"
                      style="margin-top:1rem;display:flex;gap:.5rem;align-items:center;">
                    @csrf
                    <input type="text" name="reason" placeholder="Reason for voiding (required)" required style="flex:1;">
                    <button class="btn" type="submit">Void</button>
                </form>
            @endif
        @endcan
    </div>
</x-app-layout>

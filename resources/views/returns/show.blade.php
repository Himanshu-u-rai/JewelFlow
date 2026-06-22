<x-app-layout>
    @php
        $cn = $returnOrder->creditNote;
        $invoice = $returnOrder->invoice;
        $customer = $invoice?->customer;
        $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : null;
        $statusLabel = match ($returnOrder->status) {
            \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL => 'Needs Approval',
            \App\Models\ReturnOrder::STATUS_SETTLED => 'Settled',
            \App\Models\ReturnOrder::STATUS_DRAFT => 'Draft',
            \App\Models\ReturnOrder::STATUS_SUBMITTED => 'Submitted',
            \App\Models\ReturnOrder::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', (string) $returnOrder->status)),
        };
        $statusTone = match ($returnOrder->status) {
            \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL => 'warning',
            \App\Models\ReturnOrder::STATUS_SETTLED => 'success',
            \App\Models\ReturnOrder::STATUS_CANCELLED => 'danger',
            default => 'neutral',
        };
        $title = $cn ? 'Credit Note ' . $cn->credit_note_number : 'Return #' . $returnOrder->id;
        $subtitle = 'Return of ' . ($invoice?->invoice_number ?? '-');
    @endphp

    <x-page-header
        class="returns-show-header"
        :title="$title"
        :subtitle="$subtitle">
        <x-slot:actions>
            <a href="{{ route('returns.index') }}" class="returns-show-back-btn" aria-label="Back to returns">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <line x1="19" y1="12" x2="5" y2="12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                    <polyline points="12 19 5 12 12 5" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                </svg>
                <span>Back to Returns</span>
            </a>
            @if($invoice)
                <a href="{{ route('invoices.show', $invoice) }}" class="returns-show-header-action returns-show-header-action--invoice">
                    View Original Invoice
                </a>
            @endif
            @if($returnOrder->status === 'settled' && !$returnOrder->exchangeOrder)
                <a href="{{ route('exchanges.create', $returnOrder) }}" class="returns-show-header-action returns-show-header-action--exchange">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m4 6H8m0 0l4 4m-4-4l4-4"/>
                    </svg>
                    Link as Exchange
                </a>
            @elseif($returnOrder->exchangeOrder)
                <a href="{{ route('exchanges.show', $returnOrder->exchangeOrder) }}" class="returns-show-header-action returns-show-header-action--exchange">
                    Part of exchange #{{ $returnOrder->exchangeOrder->id }}
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner returns-show-page">
        @if($invoice || $returnOrder->exchangeOrder || ($returnOrder->status === 'settled' && !$returnOrder->exchangeOrder))
            <section class="returns-show-mobile-actions" aria-label="Related actions">
                @if($invoice)
                    <a href="{{ route('invoices.show', $invoice) }}" class="returns-show-mobile-action">
                        View Original Invoice
                    </a>
                @endif
                @if($returnOrder->status === 'settled' && !$returnOrder->exchangeOrder)
                    <a href="{{ route('exchanges.create', $returnOrder) }}" class="returns-show-mobile-action returns-show-mobile-action--primary">
                        Link as Exchange
                    </a>
                @elseif($returnOrder->exchangeOrder)
                    <a href="{{ route('exchanges.show', $returnOrder->exchangeOrder) }}" class="returns-show-mobile-action returns-show-mobile-action--primary">
                        Exchange #{{ $returnOrder->exchangeOrder->id }}
                    </a>
                @endif
            </section>
        @endif

        <section class="returns-show-summary" aria-label="Return summary">
            <article class="returns-show-stat">
                <span>Status</span>
                <strong>
                    <span class="returns-show-status returns-show-status--{{ $statusTone }}">{{ $statusLabel }}</span>
                </strong>
                <small>Reason: {{ $returnOrder->reason ?? '-' }}</small>
            </article>
            <article class="returns-show-stat returns-show-stat--refund">
                <span>Refund total</span>
                <strong>₹{{ number_format((float) ($cn?->total ?? 0), 2) }}</strong>
                <small>{{ $returnOrder->lineItems->count() }} {{ $returnOrder->lineItems->count() === 1 ? 'line' : 'lines' }} returned</small>
            </article>
            <article class="returns-show-stat">
                <span>Customer</span>
                <strong>{{ $customerName ?: '-' }}</strong>
                <small>{{ $customer?->mobile ?: 'No mobile number' }}</small>
            </article>
        </section>

        @if($cn)
            <section class="returns-show-breakdown" aria-label="Credit note breakdown">
                <div class="returns-show-section-head">
                    <div>
                        <h2>Credit Note Breakdown</h2>
                        <p>{{ $cn->credit_note_number }} issued against {{ $invoice?->invoice_number ?? 'the original invoice' }}.</p>
                    </div>
                </div>

                <dl class="returns-show-breakdown-grid">
                    <div>
                        <dt>Subtotal</dt>
                        <dd>₹{{ number_format((float) $cn->subtotal, 2) }}</dd>
                    </div>
                    <div>
                        <dt>GST ({{ $cn->gst_rate }}%)</dt>
                        <dd>₹{{ number_format((float) $cn->gst, 2) }}</dd>
                    </div>
                    <div>
                        <dt>Wastage</dt>
                        <dd>₹{{ number_format((float) $cn->wastage_charge, 2) }}</dd>
                    </div>
                    <div>
                        <dt>Discount</dt>
                        <dd>-₹{{ number_format((float) $cn->discount, 2) }}</dd>
                    </div>
                    <div>
                        <dt>Round Off</dt>
                        <dd>₹{{ number_format((float) $cn->round_off, 4) }}</dd>
                    </div>
                    <div class="returns-show-breakdown-total">
                        <dt>Total</dt>
                        <dd>₹{{ number_format((float) $cn->total, 2) }}</dd>
                    </div>
                </dl>

                <p class="returns-show-footnote">
                    Issued by {{ $cn->issuedBy?->name ?? 'system' }} on {{ optional($cn->issued_at)->format('d M Y, h:i A') ?? '-' }}.
                </p>
            </section>
        @endif

        @if(($pendingMelts->isNotEmpty() || $pendingReworks->isNotEmpty()) && auth()->user()?->can('returns.approve'))
        <section aria-label="Next steps" class="returns-show-next-steps">
            <div class="returns-show-section-head">
                <div>
                    <h2>Action Needed</h2>
                    <p>{{ $pendingMelts->count() + $pendingReworks->count() }} item(s) need a follow-up action to complete this return.</p>
                </div>
            </div>

            @foreach($pendingMelts as $disposition)
            <details class="returns-next-step-item">
                <summary class="returns-next-step-summary">
                    <span class="returns-next-step-badge returns-next-step-badge--melt">Melt</span>
                    <span class="returns-next-step-label">
                        Record gold recovery —
                        <strong>{{ $disposition->item?->barcode ?? 'item #'.$disposition->item_id }}</strong>
                        @if($disposition->item)
                            ({{ $disposition->item->design ?? '' }}
                            {{ number_format((float)$disposition->item->net_metal_weight, 3) }}g,
                            {{ number_format((float)$disposition->item->purity, 0) }}k)
                        @endif
                    </span>
                </summary>
                <form method="POST"
                      action="{{ route('returns.disposition.recover-inline', [$returnOrder, $disposition]) }}"
                      class="returns-next-step-form">
                    @csrf
                    <div class="returns-next-step-fields">
                        <div>
                            <label class="returns-field-label">Actual gross weight (g)</label>
                            <input type="number" name="actual_gross_weight"
                                   step="0.001" min="0.001" max="10000"
                                   value="{{ old('actual_gross_weight', $disposition->item?->net_metal_weight) }}"
                                   class="returns-field-input @error('actual_gross_weight') is-invalid @enderror"
                                   required>
                            @error('actual_gross_weight')
                                <p class="returns-field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="returns-field-label">Actual purity (karats)</label>
                            <input type="number" name="actual_purity"
                                   step="0.1" min="1" max="24"
                                   value="{{ old('actual_purity', $disposition->item?->purity) }}"
                                   class="returns-field-input @error('actual_purity') is-invalid @enderror"
                                   required>
                            @error('actual_purity')
                                <p class="returns-field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="returns-next-step-field--wide">
                            <label class="returns-field-label">Add recovered gold to lot</label>
                            @if($goldLots->isEmpty())
                                <p class="returns-field-warning">No gold lots available. Add gold to vault first, then use the <a href="{{ route('returns.items.recover', $disposition) }}">recovery page</a>.</p>
                                <input type="hidden" name="target_lot_id" value="">
                            @else
                                <select name="target_lot_id"
                                        class="returns-field-input @error('target_lot_id') is-invalid @enderror"
                                        required>
                                    @foreach($goldLots as $lot)
                                        <option value="{{ $lot->id }}" {{ old('target_lot_id') == $lot->id ? 'selected' : '' }}>
                                            Lot #{{ $lot->lot_number }} — {{ number_format((float)$lot->purity, 0) }}K — {{ number_format((float)$lot->fine_weight_remaining, 3) }}g remaining
                                        </option>
                                    @endforeach
                                </select>
                                @error('target_lot_id')
                                    <p class="returns-field-error">{{ $message }}</p>
                                @enderror
                            @endif
                        </div>
                        <div class="returns-next-step-field--wide">
                            <label class="returns-field-label">Notes (optional)</label>
                            <input type="text" name="notes" maxlength="500"
                                   value="{{ old('notes') }}"
                                   class="returns-field-input">
                        </div>
                    </div>
                    <div class="returns-next-step-actions">
                        @if($goldLots->isNotEmpty())
                        <button type="submit" class="btn btn-primary btn-sm">
                            Record Melt Recovery
                        </button>
                        @endif
                        <a href="{{ route('returns.items.recover', $disposition) }}"
                           class="btn btn-secondary btn-sm">
                            Open full recovery form →
                        </a>
                    </div>
                </form>
            </details>
            @endforeach

            @foreach($pendingReworks as $disposition)
            <div class="returns-next-step-item returns-next-step-item--rework">
                <span class="returns-next-step-badge returns-next-step-badge--rework">Rework</span>
                <div class="returns-next-step-label">
                    <strong>{{ $disposition->item?->barcode ?? 'item #'.$disposition->item_id }}</strong>
                    @if($disposition->item)
                        <span class="text-slate-500">
                            — {{ $disposition->item->design ?? '' }}
                            {{ number_format((float)$disposition->item->net_metal_weight, 3) }}g,
                            {{ number_format((float)$disposition->item->purity, 0) }}k
                        </span>
                    @endif
                    <span class="returns-next-step-hint">
                        This item is marked for rework. Link a karigar job order to track the work.
                    </span>
                </div>
                <span class="btn btn-secondary btn-sm" style="opacity:.6;cursor:not-allowed"
                      title="In-app rework job creation is not available yet — track the karigar rework manually.">
                    Rework (track manually)
                </span>
            </div>
            @endforeach
        </section>
        @endif

        <section class="returns-show-lines" aria-label="Returned lines">
            <div class="returns-show-section-head">
                <div>
                    <h2>Returned Lines</h2>
                    <p>Returned item, refund decision, and stock handling in one compact record.</p>
                </div>
                <span class="returns-show-section-count">{{ $returnOrder->lineItems->count() }} {{ Str::plural('item', $returnOrder->lineItems->count()) }}</span>
            </div>

            <div class="returns-show-lines-list">
                @foreach($returnOrder->lineItems as $line)
                    @php
                        $latest = $line->dispositions->sortByDesc('id')->first();
                        $condition = ucfirst(str_replace('_', ' ', (string) $line->condition));
                        $disposition = $latest ? ucfirst(str_replace('_', ' ', (string) $latest->disposition)) : 'Pending';
                    @endphp
                    <article class="returns-show-line-card">
                        <div class="returns-show-line-top">
                            <div class="returns-show-line-identity">
                                <span class="returns-show-item-code">{{ $line->item?->barcode ?? '-' }}</span>
                                <h3>{{ $line->item?->design ?? $line->item?->category ?? 'No item detail' }}</h3>
                                <div class="returns-show-line-tags">
                                    <span class="returns-show-line-tag">Condition: {{ $condition }}</span>
                                    <span class="returns-show-line-tag {{ $latest ? 'returns-show-line-tag--outcome' : 'returns-show-line-tag--pending' }}">
                                        {{ $disposition }}
                                    </span>
                                </div>
                            </div>

                            <div class="returns-show-line-refund">
                                <span>Refund value</span>
                                <strong class="returns-show-refund">₹{{ number_format((float) $line->refund_total, 2) }}</strong>
                            </div>
                        </div>

                        <div class="returns-show-line-body">
                            <div class="returns-show-line-outcome">
                                <span>Stock handling</span>
                                <div class="returns-show-disposition-flow">
                                    @include('returns.partials.disposition-history', ['dispositions' => $line->dispositions->sortBy('dispositioned_at')])
                                </div>
                            </div>

                            <div class="returns-show-line-policy">
                                <span>Refund policy</span>
                                <div class="returns-show-deduction-action">
                                    @include('returns.partials.policy-breakdown', [
                                        'breakdown' => $line->policy_breakdown ?: null,
                                        'lineId'    => $line->id,
                                    ])
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="returns-show-approval" aria-label="Approval and policy context">
            <div class="returns-show-section-head">
                <div>
                    <h2>Approval & Policy Context</h2>
                    <p>Settlement rules and policy checks used for this return.</p>
                </div>
            </div>
            <dl class="returns-show-policy-list">
                <div>
                    <dt>Settlement method</dt>
                    <dd>
                        {{ $returnOrder->refund_settlement === 'store_credit' ? 'Store credit' : 'Cash refund' }}
                    </dd>
                </div>

                @if($approvalReason ?? null)
                <div>
                    <dt>Approval required because</dt>
                    <dd>
                        <span class="returns-show-pill returns-show-pill--warning">
                            {{ $approvalReason }}
                        </span>
                    </dd>
                </div>
                @else
                <div>
                    <dt>Approval</dt>
                    <dd><span class="returns-show-pill returns-show-pill--success">No approval required</span></dd>
                </div>
                @endif

                @if($returnOrder->approvedBy ?? null)
                <div>
                    <dt>Approved by</dt>
                    <dd>{{ $returnOrder->approvedBy->name }} · {{ \Carbon\Carbon::parse($returnOrder->approved_at)->format('d M Y, H:i') }}</dd>
                </div>
                @endif

                @if($returnOrder->status === 'cancelled' && str_starts_with($returnOrder->cancellation_reason ?? '', 'Rejected by approver'))
                <div>
                    <dt>Rejection reason</dt>
                    <dd class="returns-show-rejection">{{ $returnOrder->cancellation_reason }}</dd>
                </div>
                @endif

                @if($windowContext ?? null)
                <div>
                    <dt>Return window</dt>
                    <dd>
                        Invoice was {{ $windowContext['days_since'] }} day(s) old. Policy window: {{ $windowContext['window_days'] }} days.
                        @if($windowContext['within'])
                            <span class="returns-show-pill returns-show-pill--success">Within window</span>
                        @else
                            <span class="returns-show-pill returns-show-pill--warning">Override approved</span>
                        @endif
                    </dd>
                </div>
                @endif
            </dl>
        </section>

        <section class="returns-show-audit" aria-label="Return audit">
            <p>Created on {{ optional($returnOrder->created_at)->format('d M Y, h:i A') ?? '-' }} · {{ $returnOrder->createdBy?->name ?? 'system' }}.</p>
            @if($returnOrder->settled_at)
                <p>Settled on {{ $returnOrder->settled_at->format('d M Y, h:i A') }} · {{ $returnOrder->settledBy?->name ?? 'system' }}.</p>
            @endif
        </section>
    </div>

    @if(isset($eventFeed))
        <x-entity-timeline :feed="$eventFeed" title="Return Activity" />
    @endif
</x-app-layout>

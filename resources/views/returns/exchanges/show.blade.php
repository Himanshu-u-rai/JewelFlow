<x-app-layout>
    @php
        $ro = $exchange->returnOrder;
        $cn = $ro?->creditNote;
        $newInv = $exchange->newInvoice;
        $customerName = $exchange->customer
            ? trim(($exchange->customer->first_name ?? '') . ' ' . ($exchange->customer->last_name ?? ''))
            : ($newInv?->customer ? trim(($newInv->customer->first_name ?? '') . ' ' . ($newInv->customer->last_name ?? '')) : 'Walk-in customer');
        $customerName = trim($customerName) !== '' ? $customerName : 'Walk-in customer';
        $netAmount = (float) $exchange->net_amount;
        $netAbs = number_format(abs($netAmount), 2);
        if ($netAmount > 0.005) {
            $netLabel = 'Customer paid';
            $netValue = '₹' . $netAbs;
            $netTone = 'is-positive';
        } elseif ($netAmount < -0.005) {
            $netLabel = 'Shop refunded';
            $netValue = '₹' . $netAbs;
            $netTone = 'is-negative';
        } else {
            $netLabel = 'Even swap';
            $netValue = '₹0.00';
            $netTone = 'is-even';
        }

        $basisLabels = [
            'sale_day_rate' => 'Sale-day rate',
            'today_rate' => "Today's rate",
            'manual_override' => 'Manual override',
        ];
        $basisLabel = $basisLabels[$exchange->valuation_basis_source] ?? str_replace('_', ' ', (string) $exchange->valuation_basis_source);
    @endphp

    <x-page-header class="exchange-show-header jf-header-auto-mobile">
        <div class="min-w-0 exchange-show-title-block">
            <h1 class="page-title">Exchange #{{ $exchange->id }}</h1>
            <p class="page-subtitle">{{ $cn?->credit_note_number ?? 'No credit note' }} / {{ $newInv?->invoice_number ?? 'No new invoice' }}</p>
        </div>

        <div class="page-actions exchange-show-header-actions">
            <a href="{{ route('exchanges.receipt', $exchange) }}"
               target="_blank"
               class="exchange-show-action exchange-show-action--primary">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6v-8z"/>
                </svg>
                <span>Print receipt</span>
            </a>
            <a href="{{ route('returns.index') }}"
               class="exchange-show-action exchange-show-action--back">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                <span>Back to returns</span>
            </a>
        </div>
    </x-page-header>

    <div class="content-inner exchange-show-page">
        <section class="exchange-show-summary" aria-label="Exchange totals">
            <article class="exchange-show-stat exchange-show-stat--credit">
                <span>Return credit</span>
                <strong>₹{{ number_format((float) ($cn?->total ?? 0), 2) }}</strong>
                <small>{{ $cn?->credit_note_number ?? 'Credit note missing' }}</small>
            </article>
            <article class="exchange-show-stat">
                <span>New sale</span>
                <strong>₹{{ number_format((float) ($newInv?->total ?? 0), 2) }}</strong>
                <small>{{ $newInv?->invoice_number ?? 'Invoice missing' }}</small>
            </article>
            <article class="exchange-show-stat exchange-show-stat--net {{ $netTone }}">
                <span>Net settlement</span>
                <strong>{{ $netLabel }} {{ $netValue }}</strong>
                <small>Basis: {{ $basisLabel }}</small>
            </article>
        </section>

        <div class="exchange-show-layout">
            <main class="exchange-show-main" aria-label="Exchange review">
                <section class="exchange-show-card exchange-show-money" aria-labelledby="exchange-money-title">
                    <div class="exchange-show-card-head">
                        <div>
                            <p class="exchange-show-eyebrow">Settlement</p>
                            <h2 id="exchange-money-title">How the money moved</h2>
                        </div>
                    </div>
                    <div class="exchange-show-money-grid">
                        <div class="exchange-show-money-row">
                            <span>Refund out</span>
                            @if($refundCashOut)
                                <strong class="is-negative">−₹{{ number_format((float) $refundCashOut->amount, 2) }}</strong>
                                <small>Cash, {{ optional($refundCashOut->created_at)->format('d M Y, h:i A') }}</small>
                            @else
                                <strong>₹0.00</strong>
                                <small>No cash entry found for the credit note</small>
                            @endif
                        </div>
                        <div class="exchange-show-money-row">
                            <span>New sale payments</span>
                            @if($newSalePaymentMethods->isEmpty())
                                <strong>₹0.00</strong>
                                <small>No payment rows on the new invoice yet</small>
                            @else
                                <strong class="is-positive">+₹{{ number_format((float) ($newInv?->total ?? 0), 2) }}</strong>
                                <small>Via {{ $newSalePaymentMethods->map(fn($m) => str_replace('_', ' ', $m))->implode(', ') }}</small>
                            @endif
                        </div>
                    </div>
                </section>

                @if($ro)
                    <section class="exchange-show-card" aria-labelledby="exchange-returned-title">
                        <div class="exchange-show-card-head">
                            <div>
                                <p class="exchange-show-eyebrow">Return half</p>
                                <h2 id="exchange-returned-title">Items returned</h2>
                                <small>Credit note {{ $cn?->credit_note_number ?? '—' }} / return order #{{ $ro->id }}</small>
                            </div>
                            <a href="{{ route('returns.show', $ro) }}" class="exchange-show-link">View return</a>
                        </div>

                        <div class="exchange-show-lines">
                            @foreach($ro->lineItems as $rl)
                                <article class="exchange-show-line">
                                    <div class="exchange-show-line__item">
                                        <span>{{ $rl->item?->barcode ?? '—' }}</span>
                                        <strong>{{ $rl->item?->design ?? $rl->item?->category ?? 'Returned item' }}</strong>
                                    </div>
                                    <div class="exchange-show-line__meta">
                                        <span>Condition</span>
                                        <strong>{{ str_replace('_', ' ', $rl->condition) }}</strong>
                                    </div>
                                    <div class="exchange-show-line__amount is-positive">
                                        <span>Refund</span>
                                        <strong>₹{{ number_format((float) $rl->refund_total, 2) }}</strong>
                                        @include('returns.partials.policy-breakdown', [
                                            'breakdown' => $rl->policy_breakdown ?: null,
                                            'lineId'    => 'exc-' . $rl->id,
                                        ])
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if($newInv)
                    <section class="exchange-show-card" aria-labelledby="exchange-bought-title">
                        <div class="exchange-show-card-head">
                            <div>
                                <p class="exchange-show-eyebrow">New sale half</p>
                                <h2 id="exchange-bought-title">Items bought</h2>
                                <small>{{ $newInv->invoice_number }}{{ $newInv->finalized_at ? ' / finalized ' . optional($newInv->finalized_at)->format('d M Y, h:i A') : '' }}</small>
                            </div>
                            <a href="{{ route('invoices.show', $newInv) }}" class="exchange-show-link">View invoice</a>
                        </div>

                        <div class="exchange-show-lines">
                            @foreach($newInv->items as $il)
                                <article class="exchange-show-line exchange-show-line--sale">
                                    <div class="exchange-show-line__item">
                                        <span>{{ $il->item?->barcode ?? '—' }}</span>
                                        <strong>{{ $il->item?->design ?? $il->item?->category ?? 'Invoice item' }}</strong>
                                    </div>
                                    <div class="exchange-show-line__meta">
                                        <span>Source</span>
                                        <strong>{{ $newInv->invoice_number }}</strong>
                                    </div>
                                    <div class="exchange-show-line__amount">
                                        <span>Line total</span>
                                        <strong>₹{{ number_format((float) $il->line_total, 2) }}</strong>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif
            </main>

            <aside class="exchange-show-rail" aria-label="Exchange details">
                <section class="exchange-show-card">
                    <div class="exchange-show-card-head exchange-show-card-head--compact">
                        <div>
                            <p class="exchange-show-eyebrow">Details</p>
                            <h2>Exchange facts</h2>
                        </div>
                    </div>
                    <dl class="exchange-show-facts">
                        <div>
                            <dt>Customer</dt>
                            <dd>{{ $customerName }}</dd>
                        </div>
                        <div>
                            <dt>Credit note</dt>
                            <dd>{{ $cn?->credit_note_number ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt>New invoice</dt>
                            <dd>{{ $newInv?->invoice_number ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt>Linked by</dt>
                            <dd>{{ $exchange->createdBy?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt>Linked on</dt>
                            <dd>{{ optional($exchange->created_at)->format('d M Y, h:i A') ?? '—' }}</dd>
                        </div>
                        @if($exchange->settled_at)
                            <div>
                                <dt>Settled</dt>
                                <dd>
                                    {{ $exchange->settled_at->format('d M Y, h:i A') }}
                                    @if($exchange->settledBy)
                                        <small>by {{ $exchange->settledBy->name }}</small>
                                    @endif
                                </dd>
                            </div>
                        @endif
                    </dl>
                </section>

                <section class="exchange-show-card">
                    <div class="exchange-show-card-head exchange-show-card-head--compact">
                        <div>
                            <p class="exchange-show-eyebrow">Valuation</p>
                            <h2>Rate basis</h2>
                        </div>
                    </div>
                    <dl class="exchange-show-facts">
                        <div>
                            <dt>Gold rate basis</dt>
                            <dd>{{ $basisLabel }}</dd>
                        </div>
                        @if($exchange->valuation_rate_override)
                            <div>
                                <dt>Override rate</dt>
                                <dd>₹{{ number_format($exchange->valuation_rate_override, 2) }}/g</dd>
                            </div>
                        @endif
                        @if($exchange->approvedBy ?? null)
                            <div>
                                <dt>Rate authorized by</dt>
                                <dd>{{ $exchange->approvedBy->name }} / {{ \Carbon\Carbon::parse($exchange->approved_at)->format('d M Y, H:i') }}</dd>
                            </div>
                        @endif
                        @if(auth()->user()->shop?->preferences?->exchange_rate_basis_locked ?? false)
                            <div>
                                <dt>Policy note</dt>
                                <dd>Shop policy locks rate basis to default.</dd>
                            </div>
                        @endif
                    </dl>
                </section>

                @if($exchange->reason)
                    <section class="exchange-show-card exchange-show-reason">
                        <div class="exchange-show-card-head exchange-show-card-head--compact">
                            <div>
                                <p class="exchange-show-eyebrow">Audit</p>
                                <h2>Reason</h2>
                            </div>
                        </div>
                        <p>{{ $exchange->reason }}</p>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-app-layout>

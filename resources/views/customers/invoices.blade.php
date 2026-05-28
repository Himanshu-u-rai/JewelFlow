<x-app-layout>
    <x-page-header class="customers-show-header" title="Customer Invoices" subtitle="Customer billing history">
        <x-slot:actions>
            <a href="{{ route('customers.show', $customer) }}" class="btn btn-secondary btn-sm customers-show-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                <span class="customers-show-back-label-full">Back to Profile</span>
                <span class="customers-show-back-label-short">Back</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner customers-invoices-page">
        <section class="customers-show-profile-identity" aria-label="Customer identity">
            <div>
                <p class="customers-show-profile-eyebrow">Customer</p>
                <h1>{{ $customer->first_name }} {{ $customer->last_name }}</h1>
                <div class="customers-show-profile-meta">
                    @if($customer->mobile)
                        <span>{{ $customer->mobile }}</span>
                    @endif
                    @if($customer->email)
                        <span>{{ $customer->email }}</span>
                    @endif
                    <span>{{ number_format($stats->total_count) }} {{ Str::plural('invoice', $stats->total_count) }}</span>
                </div>
            </div>
            @can('sales.pos')
                <a href="{{ url('/pos/customer/' . $customer->id) }}" class="customers-invoices-primary-action">
                    Sell Item
                </a>
            @endcan
        </section>

        <section class="customers-invoices-stats" aria-label="Invoice summary">
            <div class="customers-invoices-stat">
                <span>Total invoices</span>
                <strong>{{ number_format($stats->total_count) }}</strong>
                <small>{{ number_format($stats->finalized_count) }} finalized</small>
            </div>
            <div class="customers-invoices-stat">
                <span>Total billed</span>
                <strong>₹{{ number_format($stats->total_billed, 2) }}</strong>
                <small>All customer invoices</small>
            </div>
            <div class="customers-invoices-stat">
                <span>Total paid</span>
                <strong>₹{{ number_format($stats->total_paid, 2) }}</strong>
                <small>Recorded payments</small>
            </div>
            <div class="customers-invoices-stat">
                <span>Due</span>
                <strong>₹{{ number_format($stats->total_due, 2) }}</strong>
                <small>Outstanding balance</small>
            </div>
        </section>

        <section class="customers-invoices-filter">
            <form method="GET" action="{{ route('customers.invoices', $customer) }}" class="customers-invoices-filter-form">
                <div class="customers-invoices-filter-search">
                    <label for="search">Invoice number</label>
                    <input id="search" type="text" name="search" value="{{ request('search') }}" placeholder="Search invoice number">
                </div>
                <div>
                    <label for="from_date">From date</label>
                    <input id="from_date" type="date" name="from_date" value="{{ request('from_date') }}">
                </div>
                <div>
                    <label for="to_date">To date</label>
                    <input id="to_date" type="date" name="to_date" value="{{ request('to_date') }}">
                </div>
                <div class="customers-invoices-filter-actions">
                    @if(request()->hasAny(['search', 'from_date', 'to_date']))
                        <a href="{{ route('customers.invoices', $customer) }}">Clear</a>
                    @endif
                    <button type="submit">Filter</button>
                </div>
            </form>
        </section>

        <section class="customers-invoices-card">
            <div class="customers-invoices-card-head">
                <div>
                    <h2>Invoices for {{ $customer->first_name }} {{ $customer->last_name }}</h2>
                    <p>Only invoices linked to this customer are shown here.</p>
                </div>
            </div>

            <div class="customers-invoices-table-wrap">
                <table class="customers-invoices-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-right">GST</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Due</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($invoices as $invoice)
                            @php
                                $paid = (float) $invoice->payments->sum('amount');
                                $due = max(0, (float) $invoice->total - $paid);
                            @endphp
                            <tr>
                                <td>
                                    <span class="customers-invoices-number">{{ $invoice->invoice_number }}</span>
                                    @if(str_starts_with($invoice->invoice_number, 'REP-'))
                                        <span class="customers-invoices-chip customers-invoices-chip--repair">Repair</span>
                                    @endif
                                </td>
                                <td>{{ $invoice->created_at->format('d M Y, h:i A') }}</td>
                                <td class="text-right">₹{{ number_format($invoice->subtotal, 2) }}</td>
                                <td class="text-right">₹{{ number_format($invoice->gst, 2) }}</td>
                                <td class="text-right customers-invoices-strong">₹{{ number_format($invoice->total, 2) }}</td>
                                <td class="text-right">₹{{ number_format($paid, 2) }}</td>
                                <td class="text-right {{ $due > 0 ? 'customers-invoices-due' : '' }}">₹{{ number_format($due, 2) }}</td>
                                <td class="text-center">
                                    <span class="customers-invoices-chip customers-invoices-chip--{{ $invoice->status }}">
                                        {{ $invoice->status === \App\Models\Invoice::STATUS_FINALIZED ? 'Finalized' : ucfirst($invoice->status) }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="customers-invoices-actions">
                                        <a href="{{ route('invoices.show', $invoice) }}">View</a>
                                        <a href="{{ route('invoices.print', $invoice) }}" target="_blank">Print</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="customers-invoices-empty">
                                    No invoices found for this customer.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="customers-invoices-mobile-list">
                @forelse($invoices as $invoice)
                    @php
                        $paid = (float) $invoice->payments->sum('amount');
                        $due = max(0, (float) $invoice->total - $paid);
                    @endphp
                    <article class="customers-invoices-mobile-card">
                        <div class="customers-invoices-mobile-head">
                            <div>
                                <strong>{{ $invoice->invoice_number }}</strong>
                                <span>{{ $invoice->created_at->format('d M Y, h:i A') }}</span>
                            </div>
                            <span class="customers-invoices-chip customers-invoices-chip--{{ $invoice->status }}">
                                {{ $invoice->status === \App\Models\Invoice::STATUS_FINALIZED ? 'Finalized' : ucfirst($invoice->status) }}
                            </span>
                        </div>
                        <div class="customers-invoices-mobile-grid">
                            <div><span>Total</span><strong>₹{{ number_format($invoice->total, 2) }}</strong></div>
                            <div><span>Paid</span><strong>₹{{ number_format($paid, 2) }}</strong></div>
                            <div><span>Due</span><strong class="{{ $due > 0 ? 'customers-invoices-due' : '' }}">₹{{ number_format($due, 2) }}</strong></div>
                            <div><span>GST</span><strong>₹{{ number_format($invoice->gst, 2) }}</strong></div>
                        </div>
                        <div class="customers-invoices-mobile-actions">
                            <a href="{{ route('invoices.show', $invoice) }}">View</a>
                            <a href="{{ route('invoices.print', $invoice) }}" target="_blank">Print</a>
                        </div>
                    </article>
                @empty
                    <div class="customers-invoices-empty">No invoices found for this customer.</div>
                @endforelse
            </div>

            @if($invoices->hasPages())
                <div class="customers-invoices-pagination">
                    {{ $invoices->links() }}
                </div>
            @endif
        </section>
    </div>
</x-app-layout>

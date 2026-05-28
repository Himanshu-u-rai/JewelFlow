@php
    $statusLabel = fn ($status) => match ($status) {
        'pending_approval' => 'Pending Approval',
        'settled'          => 'Settled',
        'draft'            => 'Draft',
        'submitted'        => 'Submitted',
        'cancelled'        => 'Cancelled',
        default            => ucfirst(str_replace('_', ' ', (string) $status)),
    };
    $statusTone = fn ($status) => match ($status) {
        'pending_approval' => 'warning',
        'settled'          => 'success',
        'cancelled'        => 'danger',
        default            => 'neutral',
    };
@endphp

<x-app-layout>
    <x-page-header
        class="returns-inbox-header"
        title="Exchanges"
        subtitle="Track items exchanged by customers">
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="returns-inbox-back-btn" aria-label="Back to invoices">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <line x1="19" y1="12" x2="5" y2="12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                    <polyline points="12 19 5 12 12 5" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                </svg>
                <span>Back to Invoices</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner returns-inbox-page">

        <div class="flex flex-wrap gap-3 mb-4">
            <div class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium bg-gray-100 text-gray-600">
                Total exchanges: {{ number_format($totalCount) }}
            </div>
            <div class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium
                {{ $settledCount > 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600' }}">
                Settled: {{ number_format($settledCount) }}
            </div>
            <div class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium
                {{ (float) $todayNetFlow > 0 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                Today's net flow:
                @if((float) $todayNetFlow == 0)
                    ₹0
                @elseif((float) $todayNetFlow > 0)
                    ↑ ₹{{ number_format((float) $todayNetFlow, 0) }}
                @else
                    ↓ ₹{{ number_format(abs((float) $todayNetFlow), 0) }}
                @endif
            </div>
        </div>

        <form method="GET" action="{{ route('exchanges.index') }}" class="mb-4 flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Status</label>
                <select name="status" class="rounded border-gray-300 text-sm shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">All</option>
                    <option value="settled" {{ request('status') === 'settled' ? 'selected' : '' }}>Settled</option>
                    <option value="pending_approval" {{ request('status') === 'pending_approval' ? 'selected' : '' }}>Pending Approval</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">From</label>
                <input type="date" name="from" value="{{ request('from') }}" class="rounded border-gray-300 text-sm shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">To</label>
                <input type="date" name="to" value="{{ request('to') }}" class="rounded border-gray-300 text-sm shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Customer</label>
                <input type="text" name="customer" value="{{ request('customer') }}" placeholder="Name or mobile" class="rounded border-gray-300 text-sm shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-3 py-2 text-sm bg-indigo-600 text-white rounded hover:bg-indigo-700">Apply</button>
                <a href="{{ route('exchanges.index') }}" class="px-3 py-2 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200">Clear</a>
            </div>
        </form>

        @if($exchanges->isEmpty())
            <section class="returns-inbox-empty" aria-label="No exchanges">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <polyline points="17 1 21 5 17 9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M3 11V9a4 4 0 0 1 4-4h14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <polyline points="7 23 3 19 7 15" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M21 13v2a4 4 0 0 1-4 4H3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <h2>No exchanges yet</h2>
                <p>When a customer exchange is processed, it will appear here. Start an exchange from the original invoice.</p>
            </section>
        @else
            <section class="returns-inbox-register" aria-label="Exchanges register">
                <div class="returns-inbox-register-head">
                    <div>
                        <h2>Exchange Orders</h2>
                        <p>{{ number_format($exchanges->total()) }} {{ $exchanges->total() === 1 ? 'exchange' : 'exchanges' }} recorded</p>
                    </div>
                </div>

                <div class="returns-inbox-table-wrap">
                    <table class="returns-inbox-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Original Invoice</th>
                                <th>New Invoice</th>
                                <th>Credit Note</th>
                                <th class="text-right">Net Settlement</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($exchanges as $exchange)
                                @php
                                    $customer = $exchange->customer;
                                    $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : null;
                                    $originalInvoice = $exchange->returnOrder?->invoice;
                                    $creditNote = $exchange->returnOrder?->creditNote;
                                    $newInvoice = $exchange->newInvoice;
                                    $netAmount = (float) ($exchange->net_amount ?? 0);
                                    $tone = $statusTone($exchange->status);
                                @endphp
                                <tr>
                                    <td>{{ $exchange->id }}</td>
                                    <td>
                                        @if($customerName)
                                            <span class="returns-inbox-customer">{{ $customerName }}</span>
                                            <small>{{ $customer->mobile ?: 'No mobile' }}</small>
                                        @else
                                            <span class="returns-inbox-muted">Walk-in customer</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($originalInvoice)
                                            <a class="returns-inbox-invoice-link" href="{{ route('invoices.show', $originalInvoice) }}">{{ $originalInvoice->invoice_number }}</a>
                                            <small>₹{{ number_format((float) $originalInvoice->total, 2) }}</small>
                                        @else
                                            <span class="returns-inbox-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($newInvoice)
                                            <a class="returns-inbox-invoice-link" href="{{ route('invoices.show', $newInvoice) }}">{{ $newInvoice->invoice_number }}</a>
                                            <small>₹{{ number_format((float) $newInvoice->total, 2) }}</small>
                                        @else
                                            <span class="returns-inbox-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($creditNote)
                                            <span class="returns-inbox-number">{{ $creditNote->credit_note_number }}</span>
                                            <small>₹{{ number_format((float) $creditNote->total, 2) }}</small>
                                        @else
                                            <span class="returns-inbox-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if(abs($netAmount) < 0.005)
                                            <span class="text-gray-500 font-medium">= ₹0</span>
                                        @elseif($netAmount < 0)
                                            <span class="text-emerald-700 font-medium">↓ ₹{{ number_format(abs($netAmount), 2) }}</span>
                                        @else
                                            <span class="text-amber-700 font-medium">↑ ₹{{ number_format($netAmount, 2) }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="returns-inbox-status returns-inbox-status--{{ $tone }}">{{ $statusLabel($exchange->status) }}</span>
                                    </td>
                                    <td>
                                        <span>{{ $exchange->created_at->format('d M Y') }}</span>
                                        <small>{{ $exchange->createdBy?->name ?? '—' }}</small>
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('exchanges.show', $exchange) }}" class="returns-inbox-view-action">View Details</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="returns-inbox-mobile-list">
                    @foreach($exchanges as $exchange)
                        @php
                            $customer = $exchange->customer;
                            $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : 'Walk-in customer';
                            $originalInvoice = $exchange->returnOrder?->invoice;
                            $newInvoice = $exchange->newInvoice;
                            $netAmount = (float) ($exchange->net_amount ?? 0);
                            $tone = $statusTone($exchange->status);
                        @endphp
                        <article class="returns-inbox-mobile-card">
                            <div class="returns-inbox-mobile-head">
                                <div>
                                    <h3>Exchange #{{ $exchange->id }}</h3>
                                    <p>{{ $exchange->created_at->format('d M Y, h:i A') }}</p>
                                </div>
                                <span class="returns-inbox-status returns-inbox-status--{{ $tone }}">{{ $statusLabel($exchange->status) }}</span>
                            </div>

                            <dl class="returns-inbox-mobile-grid">
                                <div>
                                    <dt>Net</dt>
                                    <dd>
                                        @if(abs($netAmount) < 0.005)
                                            <span class="text-gray-500">= ₹0</span>
                                        @elseif($netAmount < 0)
                                            <span class="text-emerald-700">↓ ₹{{ number_format(abs($netAmount), 2) }}</span>
                                        @else
                                            <span class="text-amber-700">↑ ₹{{ number_format($netAmount, 2) }}</span>
                                        @endif
                                    </dd>
                                </div>
                                <div>
                                    <dt>Original Inv.</dt>
                                    <dd>
                                        @if($originalInvoice)
                                            <a href="{{ route('invoices.show', $originalInvoice) }}">{{ $originalInvoice->invoice_number }}</a>
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>
                                <div>
                                    <dt>New Invoice</dt>
                                    <dd>
                                        @if($newInvoice)
                                            <a href="{{ route('invoices.show', $newInvoice) }}">{{ $newInvoice->invoice_number }}</a>
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>
                                <div>
                                    <dt>By</dt>
                                    <dd>{{ $exchange->createdBy?->name ?? '—' }}</dd>
                                </div>
                            </dl>

                            <div class="returns-inbox-mobile-customer">
                                <span>{{ $customerName }}</span>
                                @if($customer?->mobile)
                                    <small>{{ $customer->mobile }}</small>
                                @endif
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <a href="{{ route('exchanges.show', $exchange) }}" class="returns-inbox-view-action">View Details</a>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if($exchanges->hasPages())
                    <div class="returns-inbox-pagination">
                        {{ $exchanges->links() }}
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-app-layout>

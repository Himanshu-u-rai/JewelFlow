@php
    $visibleReturns = $returnOrders->getCollection();
    $visibleRefundTotal = $visibleReturns->sum(fn ($order) => (float) ($order->creditNote?->total ?? 0));
    $visibleSettled = $visibleReturns->where('status', \App\Models\ReturnOrder::STATUS_SETTLED)->count();
    $visiblePending = $visibleReturns->where('status', \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL)->count();
    $statusLabel = fn ($status) => match ($status) {
        \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL => 'Pending Approval',
        \App\Models\ReturnOrder::STATUS_SETTLED => 'Settled',
        \App\Models\ReturnOrder::STATUS_DRAFT => 'Draft',
        \App\Models\ReturnOrder::STATUS_SUBMITTED => 'Submitted',
        \App\Models\ReturnOrder::STATUS_CANCELLED => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', (string) $status)),
    };
    $statusTone = fn ($status) => match ($status) {
        \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL => 'warning',
        \App\Models\ReturnOrder::STATUS_SETTLED => 'success',
        \App\Models\ReturnOrder::STATUS_CANCELLED => 'danger',
        default => 'neutral',
    };
@endphp

<x-app-layout>
    <x-page-header
        class="returns-inbox-header"
        title="Returns"
        subtitle="Credit notes issued against customer returns">
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
        <div class="returns-inbox-policy">
            <x-return-policy-banner />
        </div>

        <section class="returns-inbox-summary" aria-label="Returns summary">
            <article class="returns-inbox-stat">
                <span>Total returns</span>
                <strong>{{ number_format($returnOrders->total()) }}</strong>
                <small>all processed return orders</small>
            </article>
            <article class="returns-inbox-stat">
                <span>Visible refunds</span>
                <strong>₹{{ number_format($visibleRefundTotal, 2) }}</strong>
                <small>refund value on this page</small>
            </article>
            <article class="returns-inbox-stat">
                <span>Settled here</span>
                <strong>{{ number_format($visibleSettled) }}</strong>
                <small>{{ $visiblePending ? number_format($visiblePending) . ' pending approval' : 'no pending approvals here' }}</small>
            </article>
        </section>

        <section class="returns-inbox-note" aria-label="Returns workflow note">
            <p>
                Processed returns appear here with their refund receipt, original invoice, number of items returned, and what was done with the returned items. To start a new return, open the original invoice and use <strong>Cancel via Reversal</strong>.
            </p>
        </section>

        <div class="flex flex-wrap gap-3 mb-4">
            <div class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium
                {{ $pendingApprovalCount > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600' }}">
                Pending approvals: {{ $pendingApprovalCount }}
                @if($pendingApprovalCount > 0)
                    <a href="{{ route('returns.control-center') }}" class="ml-2 underline text-amber-700 hover:text-amber-900">Review</a>
                @endif
            </div>
            <div class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium
                {{ $pendingRestockCount > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600' }}">
                Awaiting inspection: {{ $pendingRestockCount }}
            </div>
            <div class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium bg-gray-100 text-gray-600">
                Today's refunds: ₹{{ number_format($todayRefunds, 2) }}
            </div>
        </div>

        <form method="GET" action="{{ route('returns.index') }}" class="mb-4 flex flex-wrap gap-3 items-end">
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
                <a href="{{ route('returns.index') }}" class="px-3 py-2 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200">Clear</a>
            </div>
        </form>

        @if($returnOrders->isEmpty())
            <section class="returns-inbox-empty" aria-label="No returns">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18M5 6h14M5 18h14"/>
                </svg>
                <h2>No returns yet</h2>
                <p>When a customer return is processed, the credit note appears here.</p>
            </section>
        @else
            <section class="returns-inbox-register" aria-label="Returns register">
                <div class="returns-inbox-register-head">
                    <div>
                        <h2>Return Orders</h2>
                        <p>{{ number_format($returnOrders->total()) }} {{ $returnOrders->total() === 1 ? 'return' : 'returns' }} recorded</p>
                    </div>
                </div>

                <div class="returns-inbox-table-wrap">
                    <table class="returns-inbox-table">
                        <thead>
                            <tr>
                                <th>Credit Note</th>
                                <th>Original Invoice</th>
                                <th>Customer</th>
                                <th class="text-right">Refund Total</th>
                                <th class="text-center">Lines</th>
                                <th>Status</th>
                                <th>Settled At / By</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($returnOrders as $ro)
                                @php
                                    $cn = $ro->creditNote;
                                    $customer = $ro->customer;
                                    $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : null;
                                    $tone = $statusTone($ro->status);
                                @endphp
                                <tr>
                                    <td>
                                        @if($cn)
                                            <span class="returns-inbox-number">{{ $cn->credit_note_number }}</span>
                                            <small>Issued {{ optional($cn->issued_at)->format('d M Y, h:i A') }}</small>
                                        @else
                                            <span class="returns-inbox-muted">No credit note yet</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($ro->invoice)
                                            <a class="returns-inbox-invoice-link" href="{{ route('invoices.show', $ro->invoice) }}">{{ $ro->invoice->invoice_number }}</a>
                                            <small>₹{{ number_format((float) $ro->invoice->total, 2) }}</small>
                                        @else
                                            <span class="returns-inbox-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($customerName)
                                            <span class="returns-inbox-customer">{{ $customerName }}</span>
                                            <small>{{ $customer->mobile ?: 'No mobile number' }}</small>
                                        @else
                                            <span class="returns-inbox-muted">Walk-in customer</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if($cn)
                                            <span class="returns-inbox-refund">₹{{ number_format((float) $cn->total, 2) }}</span>
                                        @else
                                            <span class="returns-inbox-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $ro->lineItems->count() }}</td>
                                    <td>
                                        <span class="returns-inbox-status returns-inbox-status--{{ $tone }}">{{ $statusLabel($ro->status) }}</span>
                                    </td>
                                    <td>
                                        <span>{{ optional($ro->settled_at)->format('d M Y, h:i A') ?? '-' }}</span>
                                        <small>{{ $ro->settledBy?->name ?? $ro->createdBy?->name ?? 'No user recorded' }}</small>
                                    </td>
                                    <td class="text-right">
                                        <div class="inline-flex items-center gap-2 justify-end flex-wrap">
                                            <a href="{{ route('returns.show', $ro) }}" class="returns-inbox-view-action">View</a>
                                            @if($ro->status === \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL)
                                                @can('returns.approve')
                                                    <form method="POST" action="{{ route('returns.approve', $ro) }}" class="inline"
                                                          onsubmit="return confirm('Approve this return and issue the credit note?')">
                                                        @csrf
                                                        <button type="submit"
                                                                class="inline-flex items-center rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700 transition">
                                                            Approve
                                                        </button>
                                                    </form>
                                                    <a href="{{ route('returns.show', $ro) }}"
                                                       class="inline-flex items-center rounded-lg bg-rose-100 border border-rose-300 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-200 transition">
                                                        Reject
                                                    </a>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="returns-inbox-mobile-list">
                    @foreach($returnOrders as $ro)
                        @php
                            $cn = $ro->creditNote;
                            $customer = $ro->customer;
                            $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : 'Walk-in customer';
                            $tone = $statusTone($ro->status);
                        @endphp
                        <article class="returns-inbox-mobile-card">
                            <div class="returns-inbox-mobile-head">
                                <div>
                                    <h3>{{ $cn?->credit_note_number ?? 'Credit note pending' }}</h3>
                                    <p>{{ $cn?->issued_at ? 'Issued ' . $cn->issued_at->format('d M Y, h:i A') : 'Not issued yet' }}</p>
                                </div>
                                <span class="returns-inbox-status returns-inbox-status--{{ $tone }}">{{ $statusLabel($ro->status) }}</span>
                            </div>

                            <dl class="returns-inbox-mobile-grid">
                                <div>
                                    <dt>Refund</dt>
                                    <dd>{{ $cn ? '₹' . number_format((float) $cn->total, 2) : '-' }}</dd>
                                </div>
                                <div>
                                    <dt>Lines</dt>
                                    <dd>{{ $ro->lineItems->count() }}</dd>
                                </div>
                                <div>
                                    <dt>Invoice</dt>
                                    <dd>
                                        @if($ro->invoice)
                                            <a href="{{ route('invoices.show', $ro->invoice) }}">{{ $ro->invoice->invoice_number }}</a>
                                        @else
                                            -
                                        @endif
                                    </dd>
                                </div>
                                <div>
                                    <dt>Settled</dt>
                                    <dd>{{ optional($ro->settled_at)->format('d M Y, h:i A') ?? '-' }}</dd>
                                </div>
                            </dl>

                            <div class="returns-inbox-mobile-customer">
                                <span>{{ $customerName }}</span>
                                @if($customer?->mobile)
                                    <small>{{ $customer->mobile }}</small>
                                @endif
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <a href="{{ route('returns.show', $ro) }}" class="returns-inbox-view-action">View Return</a>
                                @if($ro->status === \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL)
                                    @can('returns.approve')
                                        <form method="POST" action="{{ route('returns.approve', $ro) }}" class="inline"
                                              onsubmit="return confirm('Approve this return and issue the credit note?')">
                                            @csrf
                                            <button type="submit"
                                                    class="inline-flex items-center rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700 transition">
                                                Approve
                                            </button>
                                        </form>
                                        <a href="{{ route('returns.show', $ro) }}"
                                           class="inline-flex items-center rounded-lg bg-rose-100 border border-rose-300 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-200 transition">
                                            Reject
                                        </a>
                                    @endcan
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                @if($returnOrders->hasPages())
                    <div class="returns-inbox-pagination">
                        {{ $returnOrders->links() }}
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-app-layout>

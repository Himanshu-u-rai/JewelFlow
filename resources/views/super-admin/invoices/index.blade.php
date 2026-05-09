<x-super-admin.layout>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-white">Platform Invoices</h3>
            <p class="text-sm text-slate-400">All subscription billing invoices generated across all shops.</p>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <div class="admin-panel px-4 py-2 text-slate-300">
                <span class="text-slate-500">{{ request()->hasAny(['q','plan_id','billing_cycle','status','date_from','date_to','shop']) ? 'Filtered:' : 'Total:' }}</span>
                <span class="font-semibold text-white ml-1">{{ number_format($totals['count']) }} invoices</span>
            </div>
            <div class="admin-panel px-4 py-2 text-slate-300">
                <span class="text-slate-500">Revenue:</span>
                <span class="font-semibold text-emerald-400 ml-1">₹{{ number_format($totals['revenue'], 2) }}</span>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="admin-panel p-4 mb-4">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div class="min-w-[200px] flex-1">
                <label class="block text-sm mb-1 text-slate-300">Search</label>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Invoice # or Shop name"
                       class="admin-control">
            </div>
            <div>
                <label class="block text-sm mb-1 text-slate-300">Plan</label>
                <select name="plan_id" class="admin-control admin-select">
                    <option value="">All Plans</option>
                    @foreach($plans as $plan)
                        <option value="{{ $plan->id }}" @selected(request('plan_id') == $plan->id)>{{ $plan->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm mb-1 text-slate-300">Cycle</label>
                <select name="billing_cycle" class="admin-control admin-select">
                    <option value="">All</option>
                    <option value="monthly" @selected(request('billing_cycle') === 'monthly')>Monthly</option>
                    <option value="yearly" @selected(request('billing_cycle') === 'yearly')>Yearly</option>
                </select>
            </div>
            <div>
                <label class="block text-sm mb-1 text-slate-300">Status</label>
                <select name="status" class="admin-control admin-select">
                    <option value="">All</option>
                    <option value="issued" @selected(request('status') === 'issued')>Issued</option>
                    <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
                </select>
            </div>
            <div>
                <label class="block text-sm mb-1 text-slate-300">From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="admin-control">
            </div>
            <div>
                <label class="block text-sm mb-1 text-slate-300">To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="admin-control">
            </div>
            <div class="flex gap-2">
                <button class="admin-btn admin-btn-primary">Apply</button>
                @if(request()->hasAny(['q','plan_id','billing_cycle','status','date_from','date_to','shop']))
                    <a href="{{ route('admin.invoices.index') }}" class="admin-btn admin-btn-secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Invoice #</th>
                        <th class="px-4 py-2 text-left">Shop</th>
                        <th class="px-4 py-2 text-left">Plan</th>
                        <th class="px-4 py-2 text-left">Cycle</th>
                        <th class="px-4 py-2 text-right">Before Tax</th>
                        <th class="px-4 py-2 text-right">GST</th>
                        <th class="px-4 py-2 text-right">Total</th>
                        <th class="px-4 py-2 text-left">Method</th>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoices as $inv)
                        <tr class="border-t border-slate-800 text-slate-200 hover:bg-slate-800/30 transition-colors">
                            <td class="px-4 py-3 font-mono text-xs text-slate-300">{{ $inv->invoice_number }}</td>
                            <td class="px-4 py-3">
                                @if($inv->shop)
                                    <a href="{{ route('admin.shops.show', $inv->shop) }}" class="text-sky-400 hover:underline">
                                        {{ $inv->shop->name }}
                                    </a>
                                @else
                                    <span class="text-slate-500">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-300">{{ $inv->plan?->name ?? '—' }}</td>
                            <td class="px-4 py-3 capitalize text-slate-400 text-xs">{{ $inv->billing_cycle }}</td>
                            <td class="px-4 py-3 text-right text-slate-300">₹{{ number_format($inv->amount_before_tax, 2) }}</td>
                            <td class="px-4 py-3 text-right text-slate-400 text-xs">
                                {{ number_format($inv->gst_rate, 0) }}% · ₹{{ number_format($inv->gst_amount, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-white">₹{{ number_format($inv->total_amount, 2) }}</td>
                            <td class="px-4 py-3 capitalize text-slate-400 text-xs">{{ $inv->payment_method }}</td>
                            <td class="px-4 py-3 text-xs text-slate-400">{{ $inv->issued_at->format('d M Y') }}</td>
                            <td class="px-4 py-3">
                                @if($inv->status === 'issued')
                                    <span class="admin-badge admin-badge-emerald">Issued</span>
                                @else
                                    <span class="admin-badge admin-badge-rose">Cancelled</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-10 text-center text-slate-500">No invoices found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($invoices->hasPages())
            <div class="px-4 py-3 border-t border-slate-800">{{ $invoices->links() }}</div>
        @endif
    </div>
</x-super-admin.layout>

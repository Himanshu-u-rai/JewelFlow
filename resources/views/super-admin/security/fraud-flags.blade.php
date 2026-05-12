<x-super-admin.layout>
    {{-- Filter bar --}}
    <form method="GET" action="{{ route('admin.fraud-flags.index') }}" class="flex flex-wrap gap-3 mb-5">
        <div>
            <label for="flag_type" class="block text-xs text-slate-400 mb-1">Flag Type</label>
            <select id="flag_type" name="flag_type" class="admin-select text-sm">
                <option value="">All Types</option>
                @foreach($flagTypes as $value => $label)
                    <option value="{{ $value }}" {{ request('flag_type') === $value ? 'selected' : '' }}>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="reviewed" class="block text-xs text-slate-400 mb-1">Status</label>
            <select id="reviewed" name="reviewed" class="admin-select text-sm">
                <option value="0" {{ request('reviewed', '0') === '0' ? 'selected' : '' }}>Unreviewed</option>
                <option value="1" {{ request('reviewed') === '1' ? 'selected' : '' }}>Reviewed</option>
                <option value="2" {{ request('reviewed') === '2' ? 'selected' : '' }}>All</option>
            </select>
        </div>

        <div class="flex items-end">
            <button type="submit" class="admin-btn admin-btn-primary text-sm">Filter</button>
        </div>

        @if(request('flag_type') || request('reviewed', '0') !== '0')
            <div class="flex items-end">
                <a href="{{ route('admin.fraud-flags.index') }}" class="admin-btn text-sm">Reset</a>
            </div>
        @endif
    </form>

    <div class="admin-panel">
        <div class="admin-panel-header">
            <div>
                <h3 class="text-sm font-semibold text-white">Fraud Flags</h3>
                <p class="text-xs text-slate-400">
                    {{ $flags->total() }} flag(s) — showing {{ $flags->count() }} on this page
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-left">
                        <th class="px-4 py-3 text-xs font-medium text-slate-400">Shop</th>
                        <th class="px-4 py-3 text-xs font-medium text-slate-400">Flag Type</th>
                        <th class="px-4 py-3 text-xs font-medium text-slate-400">Flag Data</th>
                        <th class="px-4 py-3 text-xs font-medium text-slate-400">Created At</th>
                        <th class="px-4 py-3 text-xs font-medium text-slate-400">Status</th>
                        <th class="px-4 py-3 text-xs font-medium text-slate-400">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @forelse($flags as $flag)
                        <tr class="hover:bg-slate-800/30">
                            <td class="px-4 py-3 text-white">
                                @if($flag->shop)
                                    <a href="{{ route('admin.shops.show', $flag->shop_id) }}" class="hover:text-sky-300 transition-colors">
                                        {{ $flag->shop->name }}
                                    </a>
                                    <div class="text-xs text-slate-400">#{{ $flag->shop_id }}</div>
                                @else
                                    <span class="text-slate-400">Shop #{{ $flag->shop_id }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $badgeClass = match($flag->flag_type) {
                                        'invoice_spike'       => 'bg-rose-900/60 text-rose-300 border border-rose-700',
                                        'bulk_customers'      => 'bg-amber-900/60 text-amber-300 border border-amber-700',
                                        'cross_tenant_pan'    => 'bg-purple-900/60 text-purple-300 border border-purple-700',
                                        'inactive_subscriber' => 'bg-slate-700 text-slate-300 border border-slate-600',
                                        default               => 'bg-slate-700 text-slate-300 border border-slate-600',
                                    };
                                    $badgeLabel = $flagTypes[$flag->flag_type] ?? $flag->flag_type;
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $badgeClass }}">
                                    {{ $badgeLabel }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-300 text-xs max-w-xs">
                                @if($flag->flag_type === 'invoice_spike')
                                    Today: {{ $flag->flag_data['today_count'] ?? '—' }} invoices
                                    (avg: {{ $flag->flag_data['daily_avg'] ?? '—' }}/day)
                                @elseif($flag->flag_type === 'bulk_customers')
                                    {{ $flag->flag_data['customer_count_today'] ?? '—' }} new customers today
                                @elseif($flag->flag_type === 'cross_tenant_pan')
                                    PAN: {{ Str::mask($flag->flag_data['pan'] ?? '', '*', 3, -2) }}
                                    across {{ $flag->flag_data['shop_count'] ?? '—' }} shops
                                @else
                                    <span class="text-slate-500">{{ json_encode($flag->flag_data) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-400 text-xs whitespace-nowrap">
                                {{ $flag->created_at->format('d M Y H:i') }}
                            </td>
                            <td class="px-4 py-3">
                                @if($flag->reviewed)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-emerald-900/50 text-emerald-300 border border-emerald-700">
                                        Reviewed
                                    </span>
                                    @if($flag->reviewed_at)
                                        <div class="text-xs text-slate-500 mt-1">{{ $flag->reviewed_at->format('d M Y') }}</div>
                                    @endif
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-rose-900/50 text-rose-300 border border-rose-700">
                                        Pending
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if(!$flag->reviewed)
                                    <form method="POST" action="{{ route('admin.fraud-flags.review', $flag) }}" class="space-y-1">
                                        @csrf
                                        <textarea
                                            name="review_notes"
                                            rows="2"
                                            placeholder="Review notes (min 5 chars)..."
                                            class="admin-input text-xs w-40 resize-none"
                                            required
                                            minlength="5"
                                        ></textarea>
                                        <button type="submit" class="admin-btn admin-btn-primary text-xs w-full">
                                            Mark Reviewed
                                        </button>
                                    </form>
                                @else
                                    @if($flag->review_notes)
                                        <div class="text-xs text-slate-400 max-w-xs truncate" title="{{ $flag->review_notes }}">
                                            {{ Str::limit($flag->review_notes, 60) }}
                                        </div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-slate-400 text-sm">
                                No fraud flags found matching the current filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($flags->hasPages())
            <div class="px-4 py-3 border-t border-slate-800">
                {{ $flags->links() }}
            </div>
        @endif
    </div>
</x-super-admin.layout>

<x-app-layout>
    <x-page-header title="Vault Ledger" subtitle="All bullion movements across this shop">
        <x-slot:actions>
            <a href="{{ route('vault.index') }}" class="btn btn-secondary btn-sm">← Vault</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <form method="GET" class="bg-white border border-gray-200 rounded-xl p-4 mb-4 flex flex-wrap items-end gap-3">
            <div class="flex flex-col">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">Type</label>
                <select name="type" class="rounded-md border-gray-300 text-sm" style="height:34px;">
                    <option value="">All</option>
                    @foreach($types as $t)
                        <option value="{{ $t }}" {{ $type === $t ? 'selected' : '' }}>{{ str_replace('_', ' ', $t) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">From</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-md border-gray-300 text-sm" style="height:34px;">
            </div>
            <div class="flex flex-col">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">To</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-md border-gray-300 text-sm" style="height:34px;">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="height:34px;">Filter</button>
        </form>

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-2 text-left font-semibold">When</th>
                            <th class="px-4 py-2 text-left font-semibold">Type</th>
                            <th class="px-4 py-2 text-left font-semibold">From Lot</th>
                            <th class="px-4 py-2 text-left font-semibold">To Lot</th>
                            <th class="px-4 py-2 text-right font-semibold">Fine Wt</th>
                            <th class="px-4 py-2 text-left font-semibold">Reference</th>
                            <th class="px-4 py-2 text-right font-semibold">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($movements as $mv)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $mv->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-4 py-2"><span class="text-[11px] uppercase font-semibold text-gray-700">{{ str_replace('_', ' ', $mv->type) }}</span></td>
                                <td class="px-4 py-2 text-gray-600 font-mono text-xs">{{ $mv->from_lot_id ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-600 font-mono text-xs">{{ $mv->to_lot_id ?? '—' }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($mv->fine_weight, 3) }}g</td>
                                <td class="px-4 py-2 text-gray-500 text-xs">{{ $mv->reference_type }}#{{ $mv->reference_id }}</td>
                                <td class="px-4 py-2 text-right text-gray-500 text-xs">{{ $mv->user_id }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-10 text-center text-gray-400 text-sm">No movements match your filter.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4">{{ $movements->links() }}</div>
        </div>
    </div>
</x-app-layout>

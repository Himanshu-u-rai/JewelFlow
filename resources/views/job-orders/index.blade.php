<x-app-layout>
    <x-page-header title="Job Orders" subtitle="Bullion issued to karigars">
        <x-slot:actions>
            <a href="{{ route('job-orders.create') }}" class="btn btn-success btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Issue Bullion
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <form method="GET" class="bg-white border border-gray-200 rounded-xl p-4 mb-4 flex flex-wrap items-end gap-3">
            <div class="flex flex-col">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">Status</label>
                <select name="status" class="rounded-md border-gray-300 text-sm" style="height:34px;">
                    <option value="">All</option>
                    @foreach(['issued','partial_return','completed','cancelled'] as $s)
                        <option value="{{ $s }}" {{ $filterStatus === $s ? 'selected' : '' }}>{{ str_replace('_', ' ', $s) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">Karigar</label>
                <select name="karigar_id" class="rounded-md border-gray-300 text-sm" style="height:34px;">
                    <option value="">All</option>
                    @foreach($karigars as $k)
                        <option value="{{ $k->id }}" {{ (string) $filterKarigar === (string) $k->id ? 'selected' : '' }}>{{ $k->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">From</label>
                <input type="date" name="from" value="{{ $filterFrom }}" class="rounded-md border-gray-300 text-sm" style="height:34px;">
            </div>
            <div class="flex flex-col">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">To</label>
                <input type="date" name="to" value="{{ $filterTo }}" class="rounded-md border-gray-300 text-sm" style="height:34px;">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="height:34px;">Filter</button>
        </form>

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            @if($orders->isEmpty())
                <div class="py-16 text-center text-gray-400">
                    <p class="text-sm mb-3">No job orders match your filter.</p>
                    <a href="{{ route('job-orders.create') }}" class="text-teal-700 underline text-sm">Issue your first job order</a>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <th class="px-4 py-3 text-left font-semibold">Job #</th>
                                <th class="px-4 py-3 text-left font-semibold">Karigar</th>
                                <th class="px-4 py-3 text-left font-semibold">Issued</th>
                                <th class="px-4 py-3 text-right font-semibold">Gross / Fine</th>
                                <th class="px-4 py-3 text-right font-semibold">Returned (fine)</th>
                                <th class="px-4 py-3 text-right font-semibold">Wastage</th>
                                <th class="px-4 py-3 text-center font-semibold">Status</th>
                                <th class="px-4 py-3 text-left font-semibold">Flags</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($orders as $jo)
                                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('job-orders.show', $jo) }}'"  >
                                    <td class="px-4 py-3">
                                        <a href="{{ route('job-orders.show', $jo) }}" class="text-teal-700 font-mono hover:underline">{{ $jo->job_order_number }}</a>
                                        <div class="text-[10px] text-gray-400">DC: {{ $jo->challan_number }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ $jo->karigar?->name }}</td>
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $jo->issue_date->format('d M Y') }}</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ number_format($jo->issued_gross_weight, 3) }} / {{ number_format($jo->issued_fine_weight, 3) }}g</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ number_format($jo->returned_fine_weight, 3) }}g</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ number_format($jo->actual_wastage_fine, 3) }}g</td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $jo->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : ($jo->status === 'cancelled' ? 'bg-gray-200 text-gray-600' : ($jo->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800')) }}">{{ str_replace('_', ' ', $jo->status) }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @foreach($jo->discrepancy_flags ?? [] as $flag)
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800 mr-1">{{ str_replace('_', ' ', $flag) }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-4">{{ $orders->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>

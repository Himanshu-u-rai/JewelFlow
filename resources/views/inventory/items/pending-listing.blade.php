<x-app-layout>
    <x-page-header title="Pending Pricing Review" subtitle="Items returned from karigar awaiting pricing and stock release">
        <x-slot:actions>
            <a href="{{ route('inventory.items.index') }}" class="btn btn-secondary btn-sm">Back to Stock</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        @if($items->isEmpty())
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm py-16 text-center text-gray-400 text-sm">
                No items awaiting pricing review.
            </div>
        @else
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3 text-left font-semibold">Barcode</th>
                            <th class="px-4 py-3 text-left font-semibold">Description</th>
                            <th class="px-4 py-3 text-left font-semibold">Karigar</th>
                            <th class="px-4 py-3 text-left font-semibold">Job Order</th>
                            <th class="px-4 py-3 text-right font-semibold">Net Wt</th>
                            <th class="px-4 py-3 text-right font-semibold">Purity</th>
                            <th class="px-4 py-3 text-center font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($items as $item)
                            <tr class="hover:bg-amber-50">
                                <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $item->barcode ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-800">{{ $item->design ?? $item->category ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $item->karigar?->name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if($item->jobOrder)
                                        <a href="{{ route('job-orders.show', $item->jobOrder) }}" class="text-teal-700 hover:underline font-mono text-xs">{{ $item->jobOrder->job_order_number }}</a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs">{{ number_format($item->net_metal_weight, 3) }}g</td>
                                <td class="px-4 py-3 text-right text-xs">{{ $item->purity_label ?? '—' }}</td>
                                <td class="px-4 py-3 text-center">
                                    @can('inventory.edit')
                                        <a href="{{ route('inventory.items.edit', $item) }}" class="text-xs font-semibold text-amber-700 hover:text-amber-900">Price & Release</a>
                                    @else
                                        <span class="text-xs text-gray-400">Awaiting pricing</span>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $items->links() }}
            </div>
        @endif
    </div>
</x-app-layout>

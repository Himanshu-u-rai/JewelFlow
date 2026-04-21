<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Gold Inventory</h1>
            <p class="text-sm text-gray-500 mt-1">Manage your gold stock (Metal Lots)</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('inventory.gold.create') }}" class="btn btn-dark btn-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Add Gold
            </a>
        </div>
    </x-page-header>

    <div class="content-inner">
        @if(session('success'))
            <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                {{ session('success') }}
            </div>
        @endif

        <!-- Summary Card -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Total Fine Gold in Stock</p>
                    <p class="text-3xl font-bold mt-2 text-gray-900">{{ number_format($totalFineGold, 3) }} g</p>
                </div>
                <div class="rounded-full p-4 bg-amber-50">
                    <svg class="w-10 h-10 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Lots Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Gold Lots</h2>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Lot #</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Source</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Purity</th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Total Fine</th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Remaining</th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Cost/g</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        @forelse($lots as $lot)
                            @php
                                $usedPercent = $lot->fine_weight_total > 0 
                                    ? (($lot->fine_weight_total - $lot->fine_weight_remaining) / $lot->fine_weight_total) * 100 
                                    : 0;
                                $sourceColors = [
                                    'purchase' => 'bg-blue-100 text-blue-800',
                                    'buyback' => 'bg-green-100 text-green-800',
                                    'opening' => 'bg-purple-100 text-purple-800',
                                    'customer_advance' => 'bg-yellow-100 text-yellow-800',
                                ];
                                $sourceColor = $sourceColors[$lot->source] ?? 'bg-gray-100 text-gray-800';
                            @endphp
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4">
                                    <span class="font-mono font-semibold text-gray-900">#{{ $lot->lot_number ?? '—' }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $sourceColor }}">
                                        {{ ucfirst(str_replace('_', ' ', $lot->source)) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 font-medium">{{ $lot->purity }}K</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-700">{{ number_format($lot->fine_weight_total, 3) }} g</td>
                                <td class="px-6 py-4 text-right">
                                    <div class="text-sm font-semibold {{ $lot->fine_weight_remaining > 0 ? 'text-green-600' : 'text-gray-400' }}">
                                        {{ number_format($lot->fine_weight_remaining, 3) }} g
                                    </div>
                                    <div class="w-24 bg-gray-200 rounded-full h-1.5 mt-1">
                                        <div class="bg-amber-500 h-1.5 rounded-full" style="width: {{ 100 - $usedPercent }}%"></div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-gray-700">
                                    @if($lot->cost_per_fine_gram)
                                        ₹{{ number_format($lot->cost_per_fine_gram, 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    {{ $lot->created_at->format('d M Y') }}
                                </td>
                                <td class="px-6 py-4">
                                    <a href="{{ route('inventory.gold.show', $lot) }}" 
                                       class="text-amber-600 hover:text-amber-800 text-sm font-medium">
                                        View Details
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center">
                                    <div class="text-gray-400 mb-4">
                                        <svg class="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                        </svg>
                                    </div>
                                    <p class="text-gray-500 font-medium">No gold lots found</p>
                                    <p class="text-gray-400 text-sm mt-1">Add your first gold lot to get started</p>
                                    <a href="{{ route('inventory.gold.create') }}" 
                                       class="inline-flex items-center mt-4 px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors text-sm font-medium">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                        </svg>
                                        Add Gold
                                    </a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>

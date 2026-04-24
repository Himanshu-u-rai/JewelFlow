<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Gold Lot #{{ $lot->lot_number ?? '—' }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ ucfirst(str_replace('_', ' ', $lot->source)) }} - {{ $lot->created_at->format('d M Y') }}</p>
        </div>
        <div class="page-actions flex gap-2">
            <a href="{{ route('inventory.gold.edit', $lot) }}" class="btn btn-dark btn-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
                Edit
            </a>
            <a href="{{ route('inventory.gold.index') }}" class="btn btn-secondary btn-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Inventory
            </a>
        </div>
    </x-page-header>

    <div class="content-inner">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Lot Details -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Lot Details</h3>
                @php
                    $fineTotal = (float) $lot->fine_weight_total;
                    $purity = (float) $lot->purity;
                    $costPerFine = (float) ($lot->cost_per_fine_gram ?? 0);
                    $grossEquivalent = $purity > 0 ? ($fineTotal * 24) / $purity : 0;
                    $costPerGross = $purity > 0 ? ($costPerFine * $purity) / 24 : 0;
                    $totalPurchaseCost = $fineTotal * $costPerFine;
                @endphp
                <dl class="space-y-4">
                    <div>
                        <dt class="text-sm text-gray-500">Source</dt>
                        <dd class="text-lg font-semibold text-gray-900">{{ ucfirst(str_replace('_', ' ', $lot->source)) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Purity</dt>
                        <dd class="text-lg font-semibold text-gray-900">{{ $lot->purity }}K</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Gross Weight (Equivalent)</dt>
                        <dd class="text-lg font-semibold text-gray-900">{{ number_format($grossEquivalent, 3) }} g</dd>
                        <dd class="text-xs text-gray-500 mt-1">
                            Fine {{ number_format($fineTotal, 3) }} g × 24 ÷ {{ number_format($purity, 2) }}K
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Cost per Gross Gram</dt>
                        <dd class="text-lg font-semibold text-gray-900">
                            @if($costPerFine > 0)
                                ₹{{ number_format($costPerGross, 2) }}
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Cost per Fine Gram</dt>
                        <dd class="text-lg font-semibold text-gray-900">
                            @if($costPerFine > 0)
                                ₹{{ number_format($costPerFine, 2) }}
                            @else
                                —
                            @endif
                        </dd>
                        <dd class="text-xs text-gray-500 mt-1">
                            Cost per fine gram = Cost per gross gram × 24 ÷ {{ number_format($purity, 2) }}K
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Total Purchase Cost</dt>
                        <dd class="text-lg font-semibold text-gray-900">
                            @if($costPerFine > 0)
                                ₹{{ number_format($totalPurchaseCost, 2) }}
                            @else
                                —
                            @endif
                        </dd>
                        <dd class="text-xs text-gray-500 mt-1">
                            {{ number_format($fineTotal, 3) }} g (fine) × ₹{{ number_format($costPerFine, 2) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Created</dt>
                        <dd class="text-lg font-semibold text-gray-900">{{ $lot->created_at->format('d M Y, H:i') }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Gold Stats -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Gold Balance</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-gray-500 text-sm">Total Fine Gold</p>
                        <p class="text-3xl font-bold text-gray-900">{{ number_format($lot->fine_weight_total, 3) }} g</p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Remaining</p>
                        <p class="text-3xl font-bold text-gray-900">{{ number_format($lot->fine_weight_remaining, 3) }} g</p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Used</p>
                        <p class="text-xl font-semibold text-gray-900">{{ number_format($lot->fine_weight_total - $lot->fine_weight_remaining, 3) }} g</p>
                    </div>
                    @php
                        $usedPercent = $lot->fine_weight_total > 0 
                            ? (($lot->fine_weight_total - $lot->fine_weight_remaining) / $lot->fine_weight_total) * 100 
                            : 0;
                    @endphp
                    <div class="mt-4">
                        <div class="flex justify-between text-sm text-gray-500 mb-1">
                            <span>Usage</span>
                            <span>{{ number_format($usedPercent, 1) }}%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-yellow-500 h-2 rounded-full transition-all" style="width: {{ $usedPercent }}%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">Quick Actions</h3>
                <div class="space-y-3">
                    @if($lot->fine_weight_remaining > 0)
                        <a href="{{ route('inventory.items.create') }}" class="btn btn-dark w-full">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                            Create Item from this Lot
                        </a>
                    @else
                        <div class="w-full px-4 py-3 bg-gray-100 text-gray-500 rounded-lg text-center font-medium">
                            No gold remaining in this lot
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Movements History -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Movement History</h2>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Type</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Fine Gold</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Reference</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase">User</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($movements as $m)
                            @php
                                $isInflow = $m->to_lot_id == $lot->id;
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-sm text-gray-700">{{ $m->created_at->format('d M Y H:i') }}</td>
                                <td class="px-6 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                        {{ ucfirst($m->type) }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-right text-sm font-semibold {{ $isInflow ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $isInflow ? '+' : '-' }}{{ number_format($m->fine_weight, 3) }} g
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-700">{{ ucfirst($m->reference_type) }}</td>
                                <td class="px-6 py-3 text-sm text-gray-500">
                                    {{ $m->user->mobile_number ?? 'System' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">No movements recorded</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>

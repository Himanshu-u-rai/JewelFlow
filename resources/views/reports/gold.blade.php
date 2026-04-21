<x-app-layout>
    <x-page-header>
        <h1 class="page-title">Gold Management</h1>
        <div class="page-actions">
            <span class="header-badge">Inventory Report</span>
        </div>
    </x-page-header>

    <div class="content-inner">
        <div class="bg-white rounded-lg shadow-sm">
            <div class="p-6">
                <h2 class="text-lg font-semibold mb-4">Gold Balance by Purity</h2>
                
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-gray-50 border-b">
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purity (K)</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Fine Gold (g)</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($balances as $b)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $b->purity }}K</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ number_format($b->total_fine, 6) }} g</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="px-6 py-4 text-center text-sm text-gray-500">No gold inventory found</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

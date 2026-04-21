<x-super-admin.layout>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="admin-kpi admin-tone-slate">
            <div class="text-xs text-slate-400">Date</div>
            <div class="mt-1 text-lg font-semibold text-white">{{ $snapshot['date'] }}</div>
            <p class="mt-1 text-xs text-slate-500">Tenant activity snapshot</p>
        </div>
        <div class="admin-kpi admin-tone-emerald">
            <div class="text-xs text-slate-400">Top Active Shops</div>
            <div class="mt-1 text-lg font-semibold text-white">{{ $snapshot['top_active']->count() }}</div>
            <p class="mt-1 text-xs text-slate-500">Highest activity score today</p>
        </div>
        <div class="admin-kpi admin-tone-rose">
            <div class="text-xs text-slate-400">Activity Spikes</div>
            <div class="mt-1 text-lg font-semibold text-white">{{ $snapshot['spikes']->count() }}</div>
            <p class="mt-1 text-xs text-slate-500">Invoice spikes vs 7-day avg</p>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h3 class="text-sm font-semibold text-white">Top Active Shops (Today)</h3>
                <p class="text-xs text-slate-400">Invoices, POS payments, items, imports, metal lots.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm admin-table">
                    <thead class="bg-slate-800/80 text-slate-300">
                        <tr>
                            <th class="px-4 py-2 text-left">Shop</th>
                            <th class="px-4 py-2 text-center">Invoices</th>
                            <th class="px-4 py-2 text-center">POS</th>
                            <th class="px-4 py-2 text-center">Items</th>
                            <th class="px-4 py-2 text-center">Imports</th>
                            <th class="px-4 py-2 text-center">Lots</th>
                            <th class="px-4 py-2 text-center">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($snapshot['top_active'] as $row)
                            <tr class="border-t border-slate-800 text-slate-200">
                                <td class="px-4 py-2">
                                    <div class="font-medium">{{ $row['shop']->name }}</div>
                                    <div class="text-xs text-slate-400">{{ ucfirst($row['shop']->shop_type ?? 'retailer') }}</div>
                                </td>
                                <td class="px-4 py-2 text-center">{{ $row['invoices'] }}</td>
                                <td class="px-4 py-2 text-center">{{ $row['pos_transactions'] }}</td>
                                <td class="px-4 py-2 text-center">{{ $row['items'] }}</td>
                                <td class="px-4 py-2 text-center">{{ $row['imports'] }}</td>
                                <td class="px-4 py-2 text-center">{{ $row['metal_lots'] }}</td>
                                <td class="px-4 py-2 text-center text-amber-200 font-semibold">{{ $row['activity_score'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-4 text-center text-slate-400">No activity today.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-6">
            <div class="admin-panel">
                <div class="admin-panel-header">
                    <h3 class="text-sm font-semibold text-white">High Activity Alerts</h3>
                    <p class="text-xs text-slate-400">Shops with unusually high volume.</p>
                </div>
                <div class="divide-y divide-slate-800">
                    @forelse($snapshot['alerts'] as $row)
                        <div class="px-4 py-3 flex items-center justify-between">
                            <div>
                                <div class="text-sm font-medium text-white">{{ $row['shop']->name }}</div>
                                <div class="text-xs text-slate-400">Invoices: {{ $row['invoices'] }} • POS: {{ $row['pos_transactions'] }} • Items: {{ $row['items'] }}</div>
                            </div>
                            <span class="text-xs px-2 py-1 rounded-full bg-amber-500/20 text-amber-200">Score {{ $row['activity_score'] }}</span>
                        </div>
                    @empty
                        <div class="px-4 py-4 text-sm text-slate-400">No high activity alerts.</div>
                    @endforelse
                </div>
            </div>

            <div class="admin-panel">
                <div class="admin-panel-header">
                    <h3 class="text-sm font-semibold text-white">Activity Spikes</h3>
                    <p class="text-xs text-slate-400">Today vs 7-day invoice average.</p>
                </div>
                <div class="divide-y divide-slate-800">
                    @forelse($snapshot['spikes'] as $row)
                        <div class="px-4 py-3 flex items-center justify-between">
                            <div>
                                <div class="text-sm font-medium text-white">{{ $row['shop']->name }}</div>
                                <div class="text-xs text-slate-400">Today: {{ $row['invoices'] }} • 7-day avg: {{ $row['window_avg'] }}</div>
                            </div>
                            <span class="text-xs px-2 py-1 rounded-full bg-rose-500/20 text-rose-200">Spike</span>
                        </div>
                    @empty
                        <div class="px-4 py-4 text-sm text-slate-400">No spikes detected.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel-header">
            <h3 class="text-sm font-semibold text-white">All Tenant Activity</h3>
            <p class="text-xs text-slate-400">Operational overview by shop.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Shop</th>
                        <th class="px-4 py-2 text-center">Active Users</th>
                        <th class="px-4 py-2 text-center">Invoices</th>
                        <th class="px-4 py-2 text-center">POS</th>
                        <th class="px-4 py-2 text-center">Items</th>
                        <th class="px-4 py-2 text-center">Imports</th>
                        <th class="px-4 py-2 text-center">Lots</th>
                        <th class="px-4 py-2 text-center">Score</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($snapshot['rows'] as $row)
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ $row['shop']->name }}</div>
                                <div class="text-xs text-slate-400">{{ ucfirst($row['shop']->shop_type ?? 'retailer') }}</div>
                            </td>
                            <td class="px-4 py-2 text-center">{{ $row['active_users'] }}</td>
                            <td class="px-4 py-2 text-center">{{ $row['invoices'] }}</td>
                            <td class="px-4 py-2 text-center">{{ $row['pos_transactions'] }}</td>
                            <td class="px-4 py-2 text-center">{{ $row['items'] }}</td>
                            <td class="px-4 py-2 text-center">{{ $row['imports'] }}</td>
                            <td class="px-4 py-2 text-center">{{ $row['metal_lots'] }}</td>
                            <td class="px-4 py-2 text-center text-amber-200 font-semibold">{{ $row['activity_score'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-super-admin.layout>
